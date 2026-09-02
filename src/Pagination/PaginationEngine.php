<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;
use Pagyra\Layout\LineBox;

final class PaginationEngine
{
    private const EPSILON = 0.01;

    /**
     * Absolute top/bottom of each node's whole subtree, offsets included, keyed by object id.
     * A descendant can carry a larger offset than the block it lives in (a widow/orphan or
     * break-inside shift applies to the descendant, not to its ancestor's box), so a subtree can
     * reach pages its own box never touches.
     *
     * @var array<int,array{0:float,1:float}>
     */
    private array $subtreeExtents = [];

    public function paginate(LayoutNode $root, float|PageFlow $contentHeightOrFlow): PaginationResult
    {
        $flow = $contentHeightOrFlow instanceof PageFlow
            ? $contentHeightOrFlow
            : new PageFlow($contentHeightOrFlow);
        $nodeOffsets = (new RecursivePaginationOffsets())->resolve($root, $flow);
        $this->subtreeExtents = [];
        $this->measureSubtree($root, $nodeOffsets);
        $placements = [];
        $maxEnd = 0.0;

        foreach ($root->children as $node) {
            $offset = $this->offsetFor($node, $nodeOffsets);
            $start = $node->box->marginBox()->y + $offset;
            $end = $this->absoluteSubtreeBottom($node, $nodeOffsets);
            $pageIndex = $flow->pageIndexAt($start);
            $endPageIndex = $flow->pageIndexAt(max($start, $end - self::EPSILON));
            $fragments = $this->fragmentsForNode($node, $start, $end, $offset, $flow, $nodeOffsets);

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
        }

        $pageCount = max(1, $flow->pageIndexAt(max(0.0, $maxEnd - self::EPSILON)) + 1);
        return new PaginationResult(
            flow: $flow,
            placements: $placements,
            pageCount: $pageCount,
            pages: $this->buildPhysicalPages($placements, $pageCount),
        );
    }

    /** @param list<PagePlacement> $placements @return list<PhysicalPage> */
    private function buildPhysicalPages(array $placements, int $pageCount): array
    {
        $entriesByPage = array_fill(0, $pageCount, []);
        foreach ($placements as $placement) {
            foreach ($placement->fragments as $fragment) {
                if ($fragment->pageIndex < 0 || $fragment->pageIndex >= $pageCount) continue;
                $entriesByPage[$fragment->pageIndex][] = new PhysicalPageEntry($placement, $fragment);
            }
        }

        $pages = [];
        for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
            $pages[] = new PhysicalPage($pageIndex, $entriesByPage[$pageIndex]);
        }
        return $pages;
    }

    /** @param array<int,float> $nodeOffsets @return list<PageFragment> */
    private function fragmentsForNode(
        LayoutNode $node,
        float $start,
        float $end,
        float $offset,
        PageFlow $flow,
        array $nodeOffsets,
    ): array {
        $linesByPage = [];
        foreach ($node->lineBoxes as $lineIndex => $line) {
            $lineFragment = $this->lineFragmentForPage($lineIndex, $line->y, $line->baseline, $line, $offset, $flow);
            $linesByPage[$lineFragment->pageIndex][] = $lineFragment;
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
                blocks: $this->blockFragmentsForPage($node, $pageIndex, $flow, $nodeOffsets),
            )];
        }

        $firstPage = $flow->pageIndexAt($start);
        $lastPage = $flow->pageIndexAt(max($start, $end - self::EPSILON));
        $fragments = [];

        for ($pageIndex = $firstPage; $pageIndex <= $lastPage; $pageIndex++) {
            $pageStart = $flow->contentStartForPage($pageIndex);
            $pageEnd = $pageStart + $flow->usableHeightForPage($pageIndex);
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
                blocks: $this->blockFragmentsForPage($node, $pageIndex, $flow, $nodeOffsets),
            );
        }

        return $fragments;
    }

    private function lineFragmentForPage(int $lineIndex, float $lineY, float $baseline, LineBox $line, float $offset, PageFlow $flow): LineFragment
    {
        $continuousY = $lineY + $offset;
        $continuousBaseline = $baseline + $offset;
        $pageIndex = $flow->pageIndexAt(max(0.0, $continuousBaseline - self::EPSILON));
        $pageStart = $flow->contentStartForPage($pageIndex);

        return new LineFragment(
            line: $line,
            lineIndex: $lineIndex,
            pageIndex: $pageIndex,
            pageY: $continuousY - $pageStart,
            pageBaseline: $continuousBaseline - $pageStart,
            continuousY: $continuousY,
            continuousBaseline: $continuousBaseline,
        );
    }

    /** @param array<int,float> $nodeOffsets @return list<BlockFragment> */
    private function blockFragmentsForPage(LayoutNode $node, int $pageIndex, PageFlow $flow, array $nodeOffsets): array
    {
        $fragments = [];
        foreach ($node->children as $child) {
            $fragment = $this->blockFragmentForPage($child, $pageIndex, $flow, $nodeOffsets);
            if ($fragment !== null) $fragments[] = $fragment;
        }
        return $fragments;
    }

    /** @param array<int,float> $nodeOffsets */
    private function blockFragmentForPage(LayoutNode $node, int $pageIndex, PageFlow $flow, array $nodeOffsets): ?BlockFragment
    {
        $offset = $this->offsetFor($node, $nodeOffsets);
        $border = $node->box->borderBox();
        $start = $border->y + $offset;
        $end = $border->bottom() + $offset;
        $pageStart = $flow->contentStartForPage($pageIndex);
        $pageEnd = $pageStart + $flow->usableHeightForPage($pageIndex);

        // The whole subtree decides whether this page is worth visiting, not this node's own box.
        // Otherwise a block whose box ends on page N, but whose last children were pushed onto
        // page N+1 by a widow/orphan or break-inside shift, returns null for page N+1 and takes
        // those children down with it: nothing ever claims them and their text never reaches the
        // PDF. This node's own fragment still spans only its own box, so it paints exactly the
        // same area as before and simply collapses to zero height on the pages it does not cover.
        [$subtreeStart, $subtreeEnd] = $this->subtreeExtent($node, $nodeOffsets);
        if ($subtreeEnd <= $pageStart + self::EPSILON || $subtreeStart >= $pageEnd - self::EPSILON) {
            return null;
        }

        $fragmentStart = max($start, $pageStart);
        $fragmentEnd = max($fragmentStart, min($end, $pageEnd));
        $children = [];
        foreach ($node->children as $child) {
            $childFragment = $this->blockFragmentForPage($child, $pageIndex, $flow, $nodeOffsets);
            if ($childFragment !== null) $children[] = $childFragment;
        }

        $lines = [];
        foreach ($node->lineBoxes as $lineIndex => $line) {
            $lineFragment = $this->lineFragmentForPage($lineIndex, $line->y, $line->baseline, $line, $offset, $flow);
            if ($lineFragment->pageIndex === $pageIndex) $lines[] = $lineFragment;
        }

        return new BlockFragment(
            node: $node,
            pageIndex: $pageIndex,
            pageY: $fragmentStart - $pageStart,
            height: max(0.0, $fragmentEnd - $fragmentStart),
            continuousStartY: $fragmentStart,
            continuousEndY: $fragmentEnd,
            lines: $lines,
            children: $children,
        );
    }

    /** @param array<int,float> $nodeOffsets */
    private function offsetFor(LayoutNode $node, array $nodeOffsets): float
    {
        return $nodeOffsets[spl_object_id($node)] ?? 0.0;
    }

    /**
     * @param array<int,float> $nodeOffsets
     * @return array{0:float,1:float} absolute top and bottom of the node's subtree
     */
    private function subtreeExtent(LayoutNode $node, array $nodeOffsets): array
    {
        return $this->subtreeExtents[spl_object_id($node)] ?? $this->measureSubtree($node, $nodeOffsets);
    }

    /**
     * @param array<int,float> $nodeOffsets
     * @return array{0:float,1:float}
     */
    private function measureSubtree(LayoutNode $node, array $nodeOffsets): array
    {
        $offset = $this->offsetFor($node, $nodeOffsets);
        $box = $node->box->marginBox();
        $top = $box->y + $offset;
        $bottom = $box->bottom() + $offset;
        foreach ($node->lineBoxes as $line) {
            $top = min($top, $line->y + $offset);
            $bottom = max($bottom, $line->y + $line->height + $offset);
        }
        foreach ($node->children as $child) {
            [$childTop, $childBottom] = $this->measureSubtree($child, $nodeOffsets);
            $top = min($top, $childTop);
            $bottom = max($bottom, $childBottom);
        }

        return $this->subtreeExtents[spl_object_id($node)] = [$top, $bottom];
    }

    /** @param array<int,float> $nodeOffsets */
    private function absoluteSubtreeBottom(LayoutNode $node, array $nodeOffsets): float
    {
        $bottom = $node->box->marginBox()->bottom() + $this->offsetFor($node, $nodeOffsets);
        foreach ($node->children as $child) {
            $bottom = max($bottom, $this->absoluteSubtreeBottom($child, $nodeOffsets));
        }
        return $bottom;
    }
}
