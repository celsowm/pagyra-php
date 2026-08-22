<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

final readonly class BlockFragment implements \JsonSerializable
{
    /** @param list<BlockFragment> $children */
    public function __construct(
        public LayoutNode $node,
        public int $pageIndex,
        public float $pageY,
        public float $height,
        public float $continuousStartY,
        public float $continuousEndY,
        public array $children = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'pageIndex' => $this->pageIndex,
            'pageY' => $this->pageY,
            'height' => $this->height,
            'continuousStartY' => $this->continuousStartY,
            'continuousEndY' => $this->continuousEndY,
            'children' => $this->children,
            'node' => $this->node,
        ];
    }
}
