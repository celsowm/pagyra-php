<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Ttf\TtfFontMetrics;
use Pagyra\Fonts\Ttf\TtfParser;

final class FontRegistry
{
    /** @var array<string,array<string,RegisteredFont>> */
    private array $fonts = [];

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
        $this->register($family, (new TtfParser())->parse($binary), $weight, $style, $binary);
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
        if ($value === null || trim($value) === '') return [];
        $parts = array_map(static function (string $family): string {
            $family = trim($family);
            if (strlen($family) >= 2 && (($family[0] === '"' && $family[-1] === '"') || ($family[0] === "'" && $family[-1] === "'"))) {
                $family = substr($family, 1, -1);
            }
            return $family;
        }, explode(',', $value));
        return array_values(array_filter($parts, static fn (string $v): bool => $v !== ''));
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
