<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PaginationResult implements \JsonSerializable
{
    /** @param list<PagePlacement> $placements */
    public function __construct(
        public PageFlow $flow,
        public array $placements,
        public int $pageCount,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'flow' => $this->flow,
            'placements' => $this->placements,
            'pageCount' => $this->pageCount,
        ];
    }
}
