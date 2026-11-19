<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Ttf\TtfFontMetrics;
use Pagyra\Fonts\Ttf\TtfParser;
use Pagyra\Fonts\Woff\WoffDecoder;

final class FontRegistry
{
    /** @var array<string,array<string,RegisteredFont>> */
    private array $fonts = [];

    /**
     * Last-resort faces for code points the Base14 fallback cannot draw, kept apart from
     * $fonts so that naming one of these families in CSS does not move ordinary text off
     * Base14. Files are only parsed the first time such a code point shows up.
     *
     * @var array<int,array{family:string,path:string,face:?RegisteredFont}>
     */
    private array $lastResort = [];

    /**
     * Registers a TrueType file as a last-resort face: it is consulted only after the CSS font
     * stack, and only for code points outside WinAnsi, which the Base14 fallback would
     * otherwise write as a literal `?`.
     */
    public function registerLastResortFile(string $family, string $path): void
    {
        $this->lastResort[] = ['family' => $family, 'path' => $path, 'face' => null];
    }

    public function register(string $family, TtfFontMetrics $metrics, int $weight = 400, string $style = 'normal', string $binary = ''): void
    {
        $normalizedWeight = $this->normalizedWeight($weight);
        $normalizedStyle = $this->normalizedStyle($style);
        $this->fonts[$this->familyKey($family)][$this->variantKey($normalizedWeight, $normalizedStyle)] = new RegisteredFont(
            family: $family,
            weight: $normalizedWeight,
            style: $normalizedStyle,
            metrics: $metrics,
            binary: $binary,
        );
        $this->candidates = [];
    }

    public function registerFile(string $family, string $path, int $weight = 400, string $style = 'normal'): void
    {
        $binary = @file_get_contents($path);
        if ($binary === false) {
            throw new \RuntimeException("Unable to read font: {$path}");
        }
        $this->registerData($family, $binary, $weight, $style);
    }

    public function registerData(string $family, string $binary, int $weight = 400, string $style = 'normal'): void
    {
        [$sfnt, $metrics] = self::parsed($binary);
        $this->register($family, $metrics, $weight, $style, $sfnt);
    }

    /** Limites do cache entre renderizacoes: quantidade de fontes e bytes guardados. */
    private const CACHE_MAX_ENTRIES = 32;
    private const CACHE_MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Fonte ja lida por hash dos bytes do arquivo: o sfnt (WOFF ja descompactado) e as metricas.
     * Ler as tabelas de um TTF em PHP custa cerca de 15 ms por arquivo (a Inter regular e bold e
     * a DejaVu de ultimo recurso somavam 45 ms em toda renderizacao, um terco de uma fatura de
     * uma pagina), e documentos gerados em serie repetem as mesmas fontes. TtfFontMetrics e
     * readonly, entao o mesmo objeto serve a varias renderizacoes.
     *
     * @var array<string,array{0:string,1:TtfFontMetrics}>
     */
    private static array $cache = [];
    private static int $cacheBytes = 0;

    public static function clearCache(): void
    {
        self::$cache = [];
        self::$cacheBytes = 0;
    }

    /** @return array{0:string,1:TtfFontMetrics} */
    private static function parsed(string $binary): array
    {
        $key = hash('xxh128', $binary);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $woff = new WoffDecoder();
        $sfnt = $woff->isWoff($binary) ? $woff->decode($binary) : $binary;
        $result = [$sfnt, (new TtfParser())->parse($sfnt)];

        $size = strlen($sfnt);
        if ($size <= self::CACHE_MAX_BYTES) {
            while (self::$cache !== [] && (count(self::$cache) >= self::CACHE_MAX_ENTRIES || self::$cacheBytes + $size > self::CACHE_MAX_BYTES)) {
                $oldest = array_key_first(self::$cache);
                self::$cacheBytes -= strlen(self::$cache[$oldest][0]);
                unset(self::$cache[$oldest]);
            }
            self::$cache[$key] = $result;
            self::$cacheBytes += $size;
        }

        return $result;
    }

    public function resolve(?string $fontFamily, int $weight = 400, string $style = 'normal'): ?TtfFontMetrics
    {
        return $this->resolveFace($fontFamily, $weight, $style)?->metrics;
    }

    public function resolveFace(?string $fontFamily, int $weight = 400, string $style = 'normal'): ?RegisteredFont
    {
        $requestedWeight = $this->normalizedWeight($weight);
        $requestedStyle = $this->normalizedStyle($style);

        foreach ($this->families($fontFamily) as $family) {
            $variants = $this->fonts[$this->familyKey($family)] ?? null;
            if ($variants === null) continue;

            $exact = $variants[$this->variantKey($requestedWeight, $requestedStyle)] ?? null;
            if ($exact !== null) return $exact;

            $best = $this->nearestVariant($variants, $requestedWeight, $requestedStyle, true);
            if ($best !== null) return $best;

            $best = $this->nearestVariant($variants, $requestedWeight, $requestedStyle, false);
            if ($best !== null) return $best;
        }
        return null;
    }

    /**
     * Resolve the first family in the CSS stack whose matched face actually contains this
     * codepoint. Glyph 0 is .notdef and therefore does not count as coverage.
     */
    public function resolveFaceForCodePoint(
        ?string $fontFamily,
        int $codePoint,
        int $weight = 400,
        string $style = 'normal',
    ): ?RegisteredFont {
        foreach ($this->candidates[$fontFamily . "\0" . $weight . "\0" . $style] ??= $this->candidateFaces($fontFamily, $weight, $style) as $face) {
            if ($face->metrics->glyphId($codePoint) !== 0) {
                return $face;
            }
        }

        return $this->resolveLastResortFace($codePoint);
    }

    /**
     * Faces que a pilha de familias oferece para um peso e estilo, na ordem do CSS. O layout e o
     * PdfSerializer resolvem a face de cada caractere, e cada chamada reprocessava a string de
     * `font-family` e procurava a variante mais proxima: guardada por pilha, peso e estilo, a
     * resolucao de um caractere so consulta o cmap.
     *
     * @var array<string,list<RegisteredFont>>
     */
    private array $candidates = [];

    /** @return list<RegisteredFont> */
    private function candidateFaces(?string $fontFamily, int $weight, string $style): array
    {
        $requestedWeight = $this->normalizedWeight($weight);
        $requestedStyle = $this->normalizedStyle($style);
        $faces = [];
        foreach ($this->families($fontFamily) as $family) {
            $variants = $this->fonts[$this->familyKey($family)] ?? null;
            if ($variants === null) continue;

            $face = $variants[$this->variantKey($requestedWeight, $requestedStyle)] ?? null;
            $face ??= $this->nearestVariant($variants, $requestedWeight, $requestedStyle, true);
            $face ??= $this->nearestVariant($variants, $requestedWeight, $requestedStyle, false);
            if ($face !== null) $faces[] = $face;
        }

        return $faces;
    }

    /**
     * A code point the CSS stack does not cover falls back to Base14, which can only write
     * WinAnsi. Anything outside it (Greek letters, math signs, bullets, Cyrillic, but also the
     * U+2003 em space or the U+202F narrow no-break space) used to come out as `?`; a
     * last-resort face draws it instead.
     */
    private function resolveLastResortFace(int $codePoint): ?RegisteredFont
    {
        if ($this->lastResort === [] || WinAnsiEncoding::byteFor($codePoint) !== null) return null;

        foreach ($this->lastResort as $i => $entry) {
            $face = $entry['face'];
            if ($face === null) {
                $binary = @file_get_contents($entry['path']);
                if ($binary === false) {
                    unset($this->lastResort[$i]);
                    continue;
                }
                try {
                    [$sfnt, $metrics] = self::parsed($binary);
                    $face = new RegisteredFont(
                        family: $entry['family'],
                        weight: 400,
                        style: 'normal',
                        metrics: $metrics,
                        binary: $sfnt,
                    );
                } catch (\InvalidArgumentException|\RuntimeException) {
                    unset($this->lastResort[$i]);
                    continue;
                }
                $this->lastResort[$i]['face'] = $face;
            }
            if ($face->metrics->glyphId($codePoint) !== 0) return $face;
        }

        return null;
    }

    /** @param array<string,RegisteredFont> $variants */
    private function nearestVariant(array $variants, int $requestedWeight, string $requestedStyle, bool $requireStyle): ?RegisteredFont
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($variants as $key => $font) {
            [$weight, $style] = $this->parseVariantKey($key);
            if ($requireStyle && $style !== $requestedStyle) continue;

            $diff = abs($weight - $requestedWeight);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $font;
            }
        }

        return $best;
    }

    /** @return array{0:int,1:string} */
    private function parseVariantKey(string $key): array
    {
        [$weight, $style] = array_pad(explode(':', $key, 2), 2, 'normal');
        return [$this->normalizedWeight((int) $weight), $this->normalizedStyle($style)];
    }

    /** @return list<string> */
    private function families(?string $value): array
    {
        return FontFamilyList::names($value);
    }

    private function familyKey(string $family): string
    {
        return strtolower(trim($family));
    }

    private function variantKey(int $weight, string $style): string
    {
        return $this->normalizedWeight($weight) . ':' . $this->normalizedStyle($style);
    }

    private function normalizedWeight(int $weight): int
    {
        return max(100, min(900, $weight));
    }

    private function normalizedStyle(string $style): string
    {
        $style = strtolower(trim($style));
        return in_array($style, ['italic', 'oblique'], true) ? 'italic' : 'normal';
    }
}
