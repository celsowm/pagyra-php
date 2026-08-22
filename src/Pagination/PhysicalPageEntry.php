<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PhysicalPageEntry implements \JsonSerializable
{
    public function __construct(
        public PagePlacement $placement,
        public PageFragment $fragment,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'placement' => $this->placement,
            'fragment' => $this->fragment,
        ];
    }
}
