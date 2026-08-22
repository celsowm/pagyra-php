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

    public function resolve(?string $fontFamily, int $weight = 400, string $style = 'normal'): ?TtfFontMetrics
    {
        foreach ($this->families($fontFamily) as $family) {
            $variants = $this->fonts[$this->familyKey($family)] ?? null;
            if ($variants === null) continue;
            $exact = $variants[$this->variantKey($weight, $style)] ?? null;
            if ($exact !== null) return $exact;
            $normal = $variants[$this->variantKey(400, 'normal')] ?? null;
            if ($normal !== null) return $normal;
            $first = reset($variants);
            if ($first instanceof TtfFontMetrics) return $first;
        }
        return null;
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
        return max(100, min(900, $weight)) . ':' . strtolower(trim($style));
    }
}
