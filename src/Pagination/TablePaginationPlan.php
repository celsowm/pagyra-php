<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

/**
 * Page-local packing for one fragmented table.
 *
 * @phpstan-type RowPlacement array{node:LayoutNode,pageY:float}
 * @phpstan-type TableSlice array{pageIndex:int,pageY:float,height:float,rows:list<RowPlacement>}
 */
final readonly class TablePaginationPlan
{
    /**
     * @param array<int,array{pageIndex:int,pageY:float,height:float,rows:list<array{node:LayoutNode,pageY:float}>}> $slices
     */
    public function __construct(
        public int $firstPage,
        public int $lastPage,
        public float $continuousStartY,
        public float $continuousEndY,
        public float $flowEndY,
        public array $slices,
    ) {
    }

    /** @return array{pageIndex:int,pageY:float,height:float,rows:list<array{node:LayoutNode,pageY:float}>}|null */
    public function sliceForPage(int $pageIndex): ?array
    {
        return $this->slices[$pageIndex] ?? null;
    }
}
