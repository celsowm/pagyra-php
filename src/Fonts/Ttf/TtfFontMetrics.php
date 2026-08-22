<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Ttf;

final readonly class TtfFontMetrics
{
    /** @param array<int,int> $advanceWidths @param array<int,int> $cmap @param array<int,array<int,int>> $kerning */
    public function __construct(
        public int $unitsPerEm,
        public int $ascent,
        public int $descent,
        public int $lineGap,
        public array $advanceWidths,
        public array $cmap,
        public array $kerning = [],
    ) {
        if ($unitsPerEm <= 0) {
            throw new \InvalidArgumentException('unitsPerEm must be greater than zero');
        }
    }

    public function glyphId(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }

    public function advanceWidth(int $glyphId): int
    {
        return $this->advanceWidths[$glyphId] ?? ($this->advanceWidths[0] ?? 0);
    }

    public function kerning(int $leftGlyph, int $rightGlyph): int
    {
        return $this->kerning[$leftGlyph][$rightGlyph] ?? 0;
    }
}
