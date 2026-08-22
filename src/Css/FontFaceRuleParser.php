<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class FontFaceRuleParser
{
    public function __construct(
        private readonly DeclarationParser $declarationParser = new DeclarationParser(),
        private readonly MediaQueryEvaluator $mediaQueryEvaluator = new MediaQueryEvaluator(),
    ) {
    }

    /** @return list<array{family:string,src:string,weight:int,style:string}> */
    public function parse(
        string $css,
        string $mediaType = 'print',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): array {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $faces = [];
        $this->processBlocks($css, $faces, $mediaType, $viewportWidth, $viewportHeight);
        return $faces;
    }

    /** @param list<array{family:string,src:string,weight:int,style:string}> $faces */
    private function processBlocks(
        string $css,
        array &$faces,
        string $mediaType,
        ?float $viewportWidth,
        ?float $viewportHeight,
    ): void {
        $length = strlen($css);
        $cursor = 0;

        while ($cursor < $length) {
            while ($cursor < $length && ctype_space($css[$cursor])) $cursor++;
            if ($cursor >= $length) break;

            $open = strpos($css, '{', $cursor);
            if ($open === false) break;
            $prelude = trim(substr($css, $cursor, $open - $cursor));
            $close = $this->findMatchingBrace($css, $open);
            if ($close === null) break;

            $body = substr($css, $open + 1, $close - $open - 1);
            $cursor = $close + 1;

            if (preg_match('/^@media\s+(.+)$/is', $prelude, $mediaMatch) === 1) {
                if ($this->mediaQueryEvaluator->matches($mediaMatch[1], $mediaType, $viewportWidth, $viewportHeight)) {
                    $this->processBlocks($body, $faces, $mediaType, $viewportWidth, $viewportHeight);
                }
                continue;
            }

            if (strcasecmp($prelude, '@font-face') !== 0) {
                continue;
            }

            $declarations = $this->declarationParser->parse($body);
            $family = $this->fontFamily($declarations['font-family'] ?? null);
            $src = $this->pickUrl($declarations['src'] ?? null);
            if ($family === null || $src === null) continue;

            $faces[] = [
                'family' => $family,
                'src' => $src,
                'weight' => $this->fontWeight($declarations['font-weight'] ?? '400'),
                'style' => $this->fontStyle($declarations['font-style'] ?? 'normal'),
            ];
        }
    }

    private function findMatchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($css);
        for ($i = $open; $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($quote !== null) {
                if ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $quote = $ch; continue; }
            if ($ch === '{') $depth++;
            elseif ($ch === '}' && --$depth === 0) return $i;
        }
        return null;
    }

    private function fontFamily(?string $value): ?string
    {
        if ($value === null) return null;
        $value = trim($value);
        if ($value === '') return null;
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        return trim($value) !== '' ? trim($value) : null;
    }

    private function pickUrl(?string $src): ?string
    {
        if ($src === null || trim($src) === '') return null;
        if (!preg_match_all('/url\(\s*([\'\"]?)([^\'\")]+)\1\s*\)(?:\s*format\(\s*[\'\"]?([^\'\")]+)[\'\"]?\s*\))?/i', $src, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $fallback = null;
        foreach ($matches as $match) {
            $url = trim($match[2] ?? '');
            if ($url === '') continue;
            $fallback ??= $url;
            $format = strtolower(trim($match[3] ?? ''));
            if (in_array($format, ['truetype', 'opentype', 'ttf', 'otf'], true)) return $url;
            if ($format === '' && preg_match('/\.(?:ttf|otf)(?:[?#].*)?$/i', $url) === 1) return $url;
            if ($format === '' && str_starts_with(strtolower($url), 'data:font/')) return $url;
        }
        return $fallback;
    }

    private function fontWeight(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === 'bold') return 700;
        if ($value === 'normal') return 400;
        return is_numeric($value) ? max(100, min(900, (int) $value)) : 400;
    }

    private function fontStyle(string $value): string
    {
        $value = strtolower(trim($value));
        return str_contains($value, 'italic') || str_contains($value, 'oblique') ? 'italic' : 'normal';
    }
}
