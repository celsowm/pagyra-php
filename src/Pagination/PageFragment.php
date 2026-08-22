<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PageFragment implements \JsonSerializable
{
    public function __construct(
        public int $pageIndex,
        public float $pageY,
        public float $height,
        public float $continuousStartY,
        public float $continuousEndY,
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
        ];
    }
}
