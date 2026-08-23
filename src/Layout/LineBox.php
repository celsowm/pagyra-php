<?php

declare(strict_types=1);

namespace Pagyra\Layout;

final readonly class LineBox implements \JsonSerializable
{
    /**
     * @param list<TextRun> $runs
     * @param list<AtomicInlineBox> $atomicBoxes
     * @param list<TextRun|AtomicInlineBox> $items
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $baseline,
        public string $text,
        public array $runs = [],
        public array $atomicBoxes = [],
        public array $items = [],
    ) {
    }

    /** @return list<TextRun|AtomicInlineBox> */
    public function orderedItems(): array
    {
        if ($this->items !== []) return $this->items;

        $items = [...$this->runs, ...$this->atomicBoxes];
        usort($items, static function (TextRun|AtomicInlineBox $left, TextRun|AtomicInlineBox $right): int {
            $byX = $left->x <=> $right->x;
            if ($byX !== 0) return $byX;
            return $left instanceof TextRun && $right instanceof AtomicInlineBox ? -1 : 1;
        });
        return $items;
    }

    public function jsonSerialize(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'baseline' => $this->baseline,
            'text' => $this->text,
            'runs' => $this->runs,
            'atomicBoxes' => $this->atomicBoxes,
            'items' => $this->orderedItems(),
        ];
    }
}
