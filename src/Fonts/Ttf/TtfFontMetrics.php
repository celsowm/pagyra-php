<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Ttf;

final readonly class TtfFontMetrics
{
    /**
     * @param array<int,int> $advanceWidths
     * @param array<int,int> $cmap
     * @param array<int,array<int,int>> $kerning
     * @param array{xMin:int,yMin:int,xMax:int,yMax:int} $bbox
     * @param list<array{coverage:array<int,bool>,class1:array<int,int>,class2:array<int,int>,values:array<int,array<int,int>>}> $classKerning
     */
    public function __construct(
        public int $unitsPerEm,
        public int $ascent,
        public int $descent,
        public int $lineGap,
        public array $advanceWidths,
        public array $cmap,
        public array $kerning = [],
        public array $bbox = ['xMin' => 0, 'yMin' => 0, 'xMax' => 0, 'yMax' => 0],
        public array $classKerning = [],
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
        $value = $this->kerning[$leftGlyph][$rightGlyph] ?? 0;
        foreach ($this->classKerning as $rule) {
            if (!isset($rule['coverage'][$leftGlyph])) continue;
            $leftClass = $rule['class1'][$leftGlyph] ?? 0;
            $rightClass = $rule['class2'][$rightGlyph] ?? 0;
            $value += $rule['values'][$leftClass][$rightClass] ?? 0;
        }
        return $value;
    }
}
