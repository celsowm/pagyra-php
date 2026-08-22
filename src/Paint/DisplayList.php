<?php

declare(strict_types=1);

namespace Pagyra\Paint;

final readonly class DisplayList implements \JsonSerializable
{
    /** @param list<PageDisplayList> $pages */
    public function __construct(public array $pages = [])
    {
    }

    public function jsonSerialize(): array
    {
        return ['pages' => $this->pages];
    }
}
