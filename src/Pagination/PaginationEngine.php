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

            $lineConstraintOffset = $this->widowOrphanOffset($node, $start, $offset, $flow);
            if ($lineConstraintOffset > self::EPSILON) {
                $offset += $lineConstraintOffset;
                $start += $lineConstraintOffset;
            }

            $insideOffset = $this->breakInsideAvoidOffset($node, $offset, $flow);
            if ($insideOffset > self::EPSILON) {
                $offset += $insideOffset;
                $start += $insideOffset;
            }

            $originalEnd = max($node->box->marginBox()->bottom(), $this->subtreeBottom($node));
            $end = $originalEnd + $offset;
            $pageIndex = $flow->pageIndexAt($start);
            $endPageIndex = $flow->pageIndexAt(max($start, $end - self::EPSILON));
            $fragments = $this->fragmentsForNode($node, $start, $end, $offset, $flow);

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
    private function fragmentsForNode(LayoutNode $node, float $start, float $end, float $offset, PageFlow $flow): array
    {
        $linesByPage = [];
        foreach ($node->lineBoxes as $lineIndex => $line) {
            $continuousY = $line->y + $offset;
            $continuousBaseline = $line->baseline + $offset;
            $pageIndex = $flow->pageIndexAt(max(0.0, $continuousBaseline - self::EPSILON));
            $pageStart = $flow->contentStartForPage($pageIndex);
            $linesByPage[$pageIndex][] = new LineFragment(
                line: $line,
                lineIndex: $lineIndex,
                pageIndex: $pageIndex,
                pageY: $continuousY - $pageStart,
                pageBaseline: $continuousBaseline - $pageStart,
                continuousY: $continuousY,
                continuousBaseline: $continuousBaseline,
            );
        }

        if ($end <= $start + self::EPSILON) {
            $pageIndex = $flow->pageIndexAt($start);
            return [new PageFragment(
                pageIndex: $pageIndex,
                pageY: $start - $flow->contentStartForPage($pageIndex),
                height: 0.0,
                continuousStartY: $start,
                continuousEndY: $start,
                lines: $linesByPage[$pageIndex] ?? [],
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
                lines: $linesByPage[$pageIndex] ?? [],
            );
        }

        return $fragments;
    }

    private function widowOrphanOffset(LayoutNode $node, float $start, float $offset, PageFlow $flow): float
    {
        if (count($node->lineBoxes) < 2) return 0.0;

        $pageCounts = [];
        foreach ($node->lineBoxes as $line) {
            $baseline = $line->baseline + $offset;
            $pageIndex = $flow->pageIndexAt(max(0.0, $baseline - self::EPSILON));
            $pageCounts[$pageIndex] = ($pageCounts[$pageIndex] ?? 0) + 1;
        }
        if (count($pageCounts) < 2) return 0.0;

        ksort($pageCounts);
        $firstPage = (int) array_key_first($pageCounts);
        $lastPage = (int) array_key_last($pageCounts);
        $orphans = $this->positiveIntegerStyle($node, 'orphans', 2);
        $widows = $this->positiveIntegerStyle($node, 'widows', 2);
        if (($pageCounts[$firstPage] ?? 0) >= $orphans && ($pageCounts[$lastPage] ?? 0) >= $widows) return 0.0;

        $originalStart = $node->box->marginBox()->y;
        $originalEnd = max($node->box->marginBox()->bottom(), $this->subtreeBottom($node));
        if (($originalEnd - $originalStart) > $flow->contentHeight + self::EPSILON) return 0.0;

        $currentPage = $flow->pageIndexAt($start);
        return max(0.0, $flow->contentStartForPage($currentPage + 1) - $start);
    }

    private function breakInsideAvoidOffset(LayoutNode $node, float $offset, PageFlow $flow): float
    {
        $value = strtolower(trim(
            $node->source->style->get('break-inside')
            ?? $node->source->style->get('page-break-inside')
            ?? '',
        ));
        if (!in_array($value, ['avoid', 'avoid-page'], true)) return 0.0;

        $border = $node->box->borderBox();
        $top = $border->y + $offset;
        $bottom = $border->bottom() + $offset;
        $startPage = $flow->pageIndexAt($top);
        $endPage = $flow->pageIndexAt(max($top, $bottom - self::EPSILON));
        if ($startPage === $endPage) return 0.0;

        return max(0.0, $flow->contentStartForPage($startPage + 1) - $top);
    }

    private function positiveIntegerStyle(LayoutNode $node, string $property, int $fallback): int
    {
        $raw = trim($node->source->style->get($property, (string) $fallback) ?? (string) $fallback);
        if (!preg_match('/^[+-]?\d+$/', $raw)) return $fallback;
        return max(1, (int) $raw);
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
        if ($value === 'left' && $target % 2 === 0) $target++;
        elseif ($value === 'right' && $target % 2 !== 0) $target++;
        return max(0.0, $flow->contentStartForPage($target) - $coordinate);
    }

    private function subtreeBottom(LayoutNode $node): float
    {
        $bottom = $node->box->marginBox()->bottom();
        foreach ($node->children as $child) $bottom = max($bottom, $this->subtreeBottom($child));
        return $bottom;
    }
}
