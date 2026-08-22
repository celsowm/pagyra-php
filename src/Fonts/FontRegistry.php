<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Ttf\TtfFontMetrics;
use Pagyra\Fonts\Ttf\TtfParser;

final class FontRegistry
{
    /** @var array<string,array<string,TtfFontMetrics>> */
    private array $fonts = [];

    public function register(string $family, TtfFontMetrics $metrics, int $weight = 400, string $style = 'normal'): void
    {
        $this->fonts[$this->familyKey($family)][$this->variantKey($weight, $style)] = $metrics;
    }

    public function registerFile(string $family, string $path, int $weight = 400, string $style = 'normal'): void
    {
        $this->register($family, (new TtfParser())->parseFile($path), $weight, $style);
    }

    public function registerData(string $family, string $binary, int $weight = 400, string $style = 'normal'): void
    {
        $this->register($family, (new TtfParser())->parse($binary), $weight, $style);
    }

    public function resolve(?string $fontFamily, int $weight = 400, string $style = 'normal'): ?TtfFontMetrics
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
     * @param array<string,TtfFontMetrics> $variants
     */
    private function nearestVariant(array $variants, int $requestedWeight, string $requestedStyle, bool $requireStyle): ?TtfFontMetrics
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($variants as $key => $metrics) {
            [$weight, $style] = $this->parseVariantKey($key);
            if ($requireStyle && $style !== $requestedStyle) continue;

            $diff = abs($weight - $requestedWeight);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $metrics;
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
