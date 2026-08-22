<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final readonly class PageFlow implements \JsonSerializable
{
    public function __construct(public float $contentHeight)
    {
        if ($contentHeight <= 0.0) {
            throw new \InvalidArgumentException('contentHeight must be greater than zero');
        }
    }

    public function pageIndexAt(float $contentY): int
    {
        return max(0, (int) floor(max(0.0, $contentY) / $this->contentHeight));
    }

    public function contentStartForPage(int $pageIndex): float
    {
        return max(0, $pageIndex) * $this->contentHeight;
    }

    public function jsonSerialize(): array
    {
        return ['contentHeight' => $this->contentHeight];
    }
}
