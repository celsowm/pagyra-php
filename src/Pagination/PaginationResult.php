<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PaginationResult implements \JsonSerializable
{
    /**
     * @param list<PagePlacement> $placements
     * @param list<PhysicalPage> $pages
     * @param int $lastForcedBreakPage highest page index a forced break sent content to, or -1:
     *        up to it every page was asked for by the author, even if nothing visible lands there
     */
    public function __construct(
        public PageFlow $flow,
        public array $placements,
        public int $pageCount,
        public array $pages = [],
        public int $lastForcedBreakPage = -1,
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
