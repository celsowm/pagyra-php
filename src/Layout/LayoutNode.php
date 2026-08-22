<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\StyledNode;

final readonly class LayoutNode implements \JsonSerializable
{
    /**
     * @param list<LayoutNode> $children
     * @param list<LineBox> $lineBoxes
     */
    public function __construct(
        public StyledNode $source,
        public LayoutBox $box,
        public array $children = [],
        public float $fontSize = 16.0,
        public array $lineBoxes = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'node' => $this->source->node,
            'style' => $this->source->style,
            'box' => $this->box,
            'fontSize' => $this->fontSize,
            'lineBoxes' => $this->lineBoxes,
            'children' => $this->children,
        ];
    }
}
