<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Resolve;

use Celsowm\PagyraPhp\Font\Cache\SystemFontCache;

final class SystemFontRepository
{
    private SystemFontLocator $locator;
    private SystemFontCache $cache;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $fonts = null;
    /** @var array<string, array{family:string, variants:array<string, array<string, mixed>>}>|null */
    private ?array $families = null;

    public function __construct(SystemFontLocator $locator, ?SystemFontCache $cache = null)
    {
        $this->locator = $locator;
        $this->cache = $cache ?? new SystemFontCache();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFonts(): array
    {
        if ($this->fonts !== null) {
            return $this->fonts;
        }
        $signature = $this->locator->computeSignature();
        $cached = $this->cache->load();
        if ($cached !== null && $cached['signature'] === $signature) {
            $this->fonts = $cached['fonts'];
            return $this->fonts;
        }
        $fonts = $this->locator->locate();
        $this->cache->store([
            'signature' => $signature,
            'fonts' => $fonts,
        ]);
        $this->fonts = $fonts;
        return $this->fonts;
    }

    /**
     * @return array<string, array{family:string, variants:array<string, array<string, mixed>>}>
     */
    public function getFamilies(): array
    {
        if ($this->families !== null) {
            return $this->families;
        }
        $map = [];
        foreach ($this->getFonts() as $font) {
            if (!isset($font['family'], $font['path'])) {
                continue;
            }
            $familyName = (string)$font['family'];
            $familyKey = $this->normalizeName($familyName);
            if ($familyKey === '') {
                continue;
            }
            $variantKey = $this->variantKey((int)($font['weight'] ?? 400), !empty($font['italic']));
            if (!isset($map[$familyKey])) {
                $map[$familyKey] = [
                    'family' => $familyName,
                    'variants' => [],
                ];
            }
            $variants = $map[$familyKey]['variants'];
            $entry = [
                'path' => (string)$font['path'],
                'weight' => (int)($font['weight'] ?? 400),
                'italic' => (bool)($font['italic'] ?? false),
                'subfamily' => $font['subfamily'] ?? null,
                'fullName' => $font['fullName'] ?? null,
            ];
            if (!isset($variants[$variantKey]) || $this->scoreVariant($entry, $variantKey) > $this->scoreVariant($variants[$variantKey], $variantKey)) {
                $variants[$variantKey] = $entry;
                $map[$familyKey]['variants'] = $variants;
            }
        }
        $this->families = $map;
        return $this->families;
    }

    private function normalizeName(string $name): string
    {
        $trim = trim(strtolower($name));
        $trim = preg_replace('/\s+/', ' ', $trim);
        return is_string($trim) ? $trim : '';
    }

    private function variantKey(int $weight, bool $italic): string
    {
        $bold = $weight >= 600;
        if ($bold && $italic) {
            return 'BI';
        }
        if ($bold) {
            return 'B';
        }
        if ($italic) {
            return 'I';
        }
        return 'R';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function scoreVariant(array $entry, string $variantKey): int
    {
        $weight = (int)($entry['weight'] ?? 400);
        $italic = (bool)($entry['italic'] ?? false);
        switch ($variantKey) {
            case 'BI':
                return -abs(700 - $weight) + ($italic ? 10 : 0);
            case 'B':
                return -abs(700 - $weight);
            case 'I':
                return ($italic ? 10 : 0) - abs(400 - $weight);
            default:
                return -abs(400 - $weight);
        }
    }
}
