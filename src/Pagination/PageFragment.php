<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PageFragment implements \JsonSerializable
{
    /** @param list<LineFragment> $lines @param list<BlockFragment> $blocks */
    public function __construct(
        public int $pageIndex,
        public float $pageY,
        public float $height,
        public float $continuousStartY,
        public float $continuousEndY,
        public array $lines = [],
        public array $blocks = [],
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
            'lines' => $this->lines,
            'blocks' => $this->blocks,
        ];
    }
}
