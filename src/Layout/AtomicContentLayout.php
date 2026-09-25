<?php

declare(strict_types=1);

namespace Pagyra\Layout;

/**
 * Inner formatting-context result carried by an atomic inline box.
 *
 * Lines are the direct inline content of the inner formatting context. Blocks are real nested
 * layout nodes (including anonymous blocks generated for mixed inline/block flow). Baseline is
 * measured from the atomic box's content-box top and is null when the inner context has no
 * in-flow line box.
 */
final readonly class AtomicContentLayout implements \JsonSerializable
{
    /**
     * @param list<LineBox> $lines
     * @param list<LayoutNode> $blocks
     */
    public function __construct(
        public float $height,
        public array $lines = [],
        public array $blocks = [],
        public ?float $baseline = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'height' => $this->height,
            'lines' => $this->lines,
            'blocks' => $this->blocks,
            'baseline' => $this->baseline,
        ];
    }
}
