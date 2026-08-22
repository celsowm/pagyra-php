<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PaginationResult implements \JsonSerializable
{
    /** @param list<PagePlacement> $placements @param list<PhysicalPage> $pages */
    public function __construct(
        public PageFlow $flow,
        public array $placements,
        public int $pageCount,
        public array $pages = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'flow' => $this->flow,
            'placements' => $this->placements,
            'pageCount' => $this->pageCount,
            'pages' => $this->pages,
        ];
    }
}
