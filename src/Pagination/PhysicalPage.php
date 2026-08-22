<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PhysicalPage implements \JsonSerializable
{
    /** @param list<PhysicalPageEntry> $entries */
    public function __construct(
        public int $pageIndex,
        public array $entries = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'pageIndex' => $this->pageIndex,
            'entries' => $this->entries,
        ];
    }
}
