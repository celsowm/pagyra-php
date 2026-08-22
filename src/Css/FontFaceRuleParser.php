<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class FontFaceRuleParser
{
    public function __construct(private readonly DeclarationParser $declarationParser = new DeclarationParser())
    {
    }

    /** @return list<array{family:string,src:string,weight:int,style:string}> */
    public function parse(string $css): array
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        if (preg_match_all('/@font-face\s*\{([^{}]*)\}/is', $css, $matches) !== 1 && ($matches[1] ?? []) === []) {
            return [];
        }

        $faces = [];
        foreach ($matches[1] ?? [] as $body) {
            $declarations = $this->declarationParser->parse((string) $body);
            $family = $this->fontFamily($declarations['font-family'] ?? null);
            $src = $this->pickUrl($declarations['src'] ?? null);
            if ($family === null || $src === null) {
                continue;
            }

            $faces[] = [
                'family' => $family,
                'src' => $src,
                'weight' => $this->fontWeight($declarations['font-weight'] ?? '400'),
                'style' => $this->fontStyle($declarations['font-style'] ?? 'normal'),
            ];
        }

        return $faces;
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
            if (in_array($format, ['truetype', 'opentype', 'ttf', 'otf'], true)) {
                return $url;
            }
            if ($format === '' && preg_match('/\.(?:ttf|otf)(?:[?#].*)?$/i', $url) === 1) {
                return $url;
            }
            if ($format === '' && str_starts_with(strtolower($url), 'data:font/')) {
                return $url;
            }
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
