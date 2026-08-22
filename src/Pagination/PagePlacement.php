<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

final readonly class PagePlacement implements \JsonSerializable
{
    /** @param list<PageFragment> $fragments */
    public function __construct(
        public LayoutNode $node,
        public int $pageIndex,
        public int $endPageIndex,
        public float $offsetY,
        public float $startY,
        public float $endY,
        public array $fragments = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'pageIndex' => $this->pageIndex,
            'endPageIndex' => $this->endPageIndex,
            'offsetY' => $this->offsetY,
            'startY' => $this->startY,
            'endY' => $this->endY,
            'fragments' => $this->fragments,
            'node' => $this->node,
        ];
    }
}
