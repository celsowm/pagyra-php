<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

final class PaginationEngine
{
    private const EPSILON = 0.01;

    public function paginate(LayoutNode $root, float $contentHeight): PaginationResult
    {
        $flow = new PageFlow($contentHeight);
        $placements = [];
        $offset = 0.0;
        $maxEnd = 0.0;

        foreach ($root->children as $node) {
            $start = $node->box->marginBox()->y + $offset;
            $before = $this->breakValue($node, 'before');
            $beforeOffset = $this->forcedBreakOffset($before, $start, $flow);
            if ($beforeOffset > self::EPSILON) {
                $offset += $beforeOffset;
                $start += $beforeOffset;
            }

            $originalEnd = max($node->box->marginBox()->bottom(), $this->subtreeBottom($node));
            $end = $originalEnd + $offset;
            $pageIndex = $flow->pageIndexAt($start);
            $endPageIndex = $flow->pageIndexAt(max($start, $end - self::EPSILON));
            $fragments = $this->fragmentsForRange($start, $end, $flow);

            $placements[] = new PagePlacement(
                node: $node,
                pageIndex: $pageIndex,
                endPageIndex: $endPageIndex,
                offsetY: $offset,
                startY: $start,
                endY: $end,
                fragments: $fragments,
            );
            $maxEnd = max($maxEnd, $end);

            $after = $this->breakValue($node, 'after');
            $afterOffset = $this->forcedBreakOffset($after, $end, $flow);
            if ($afterOffset > self::EPSILON) {
                $offset += $afterOffset;
            }
        }

        $pageCount = max(1, $flow->pageIndexAt(max(0.0, $maxEnd - self::EPSILON)) + 1);
        return new PaginationResult($flow, $placements, $pageCount);
    }

    /** @return list<PageFragment> */
    private function fragmentsForRange(float $start, float $end, PageFlow $flow): array
    {
        if ($end <= $start + self::EPSILON) {
            return [new PageFragment(
                pageIndex: $flow->pageIndexAt($start),
                pageY: $start - $flow->contentStartForPage($flow->pageIndexAt($start)),
                height: 0.0,
                continuousStartY: $start,
                continuousEndY: $start,
            )];
        }

        $firstPage = $flow->pageIndexAt($start);
        $lastPage = $flow->pageIndexAt(max($start, $end - self::EPSILON));
        $fragments = [];

        for ($pageIndex = $firstPage; $pageIndex <= $lastPage; $pageIndex++) {
            $pageStart = $flow->contentStartForPage($pageIndex);
            $pageEnd = $pageStart + $flow->contentHeight;
            $fragmentStart = max($start, $pageStart);
            $fragmentEnd = min($end, $pageEnd);
            if ($fragmentEnd < $fragmentStart) continue;

            $fragments[] = new PageFragment(
                pageIndex: $pageIndex,
                pageY: $fragmentStart - $pageStart,
                height: max(0.0, $fragmentEnd - $fragmentStart),
                continuousStartY: $fragmentStart,
                continuousEndY: $fragmentEnd,
            );
        }

        return $fragments;
    }

    private function breakValue(LayoutNode $node, string $side): ?string
    {
        $modern = $node->source->style->get('break-' . $side);
        $legacy = $node->source->style->get('page-break-' . $side);
        $value = strtolower(trim($modern ?? $legacy ?? ''));
        if ($value === 'always') return 'page';
        return in_array($value, ['page', 'left', 'right'], true) ? $value : null;
    }

    private function forcedBreakOffset(?string $value, float $coordinate, PageFlow $flow): float
    {
        if ($value === null) return 0.0;

        $currentPage = $flow->pageIndexAt($coordinate);
        $currentStart = $flow->contentStartForPage($currentPage);
        $target = abs($coordinate - $currentStart) <= self::EPSILON ? $currentPage : $currentPage + 1;

        if ($value === 'left' && $target % 2 === 0) {
            $target++;
        } elseif ($value === 'right' && $target % 2 !== 0) {
            $target++;
        }

        return max(0.0, $flow->contentStartForPage($target) - $coordinate);
    }

    private function subtreeBottom(LayoutNode $node): float
    {
        $bottom = $node->box->marginBox()->bottom();
        foreach ($node->children as $child) {
            $bottom = max($bottom, $this->subtreeBottom($child));
        }
        return $bottom;
    }
}
