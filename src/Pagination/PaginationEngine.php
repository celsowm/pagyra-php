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

    /** @var array<int,TablePaginationPlan> keyed by spl_object_id(LayoutNode) */
    private array $tablePlans = [];

    public function paginate(LayoutNode $root, float|PageFlow $contentHeightOrFlow): PaginationResult
    {
        $flow = $contentHeightOrFlow instanceof PageFlow
            ? $contentHeightOrFlow
            : new PageFlow($contentHeightOrFlow);
        $baseOffsets = (new RecursivePaginationOffsets())->resolve($root, $flow);
        $this->tablePlans = [];
        $nodeOffsets = $this->applyTablePaginationOffsets($root, $flow, $baseOffsets);
        $this->subtreeExtents = [];
        $this->measureSubtree($root, $nodeOffsets);
        $placements = [];
        $maxEnd = 0.0;

        foreach ($root->children as $node) {
            $offset = $this->offsetFor($node, $nodeOffsets);
            $start = $node->box->marginBox()->y + $offset;
            $end = $this->absoluteSubtreeBottom($node, $nodeOffsets);
            $fragments = $this->fragmentsForNode($node, $start, $end, $offset, $flow, $nodeOffsets);
            $plan = $this->tablePlans[spl_object_id($node)] ?? null;
            $pageIndex = $plan?->firstPage ?? ($fragments[0]->pageIndex ?? $flow->pageIndexAt($start));
            $endPageIndex = $plan?->lastPage ?? (
                $fragments !== []
                    ? $fragments[array_key_last($fragments)]->pageIndex
                    : $flow->pageIndexAt(max($start, $end - self::EPSILON))
            );
            $placementStart = $plan?->continuousStartY ?? $start;
            $placementEnd = $plan?->flowEndY ?? $end;

            $placements[] = new PagePlacement(
                node: $node,
                pageIndex: $pageIndex,
                endPageIndex: $endPageIndex,
                offsetY: $offset,
                startY: $placementStart,
                endY: $placementEnd,
                fragments: $fragments,
            );
            $maxEnd = max($maxEnd, $placementEnd);
        }

        $pageCount = max(1, $flow->pageIndexAt(max(0.0, $maxEnd - self::EPSILON)) + 1);
        return new PaginationResult(
            flow: $flow,
            placements: $placements,
            pageCount: $pageCount,
            pages: $this->buildPhysicalPages($placements, $flow, $pageCount),
        );
    }

    /** @param list<PagePlacement> $placements @return list<PhysicalPage> */
    private function buildPhysicalPages(array $placements, PageFlow $flow, int $pageCount): array
    {
        $entriesByPage = array_fill(0, $pageCount, []);
        foreach ($placements as $placement) {
            foreach ($placement->fragments as $fragment) {
                if ($fragment->pageIndex < 0 || $fragment->pageIndex >= $pageCount) continue;
                if (!$this->fragmentOccupiesPage($placement, $fragment, $flow)) continue;
                $entriesByPage[$fragment->pageIndex][] = new PhysicalPageEntry($placement, $fragment);
            }
        }

        $pages = [];
        for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
            $pages[] = new PhysicalPage($pageIndex, $entriesByPage[$pageIndex]);
        }
        return $pages;
    }

    /**
     * Whether a page actually holds part of this placement, and so deserves an entry.
     *
     * A placement spans every page between its own top and the bottom of its whole subtree, and
     * the subtree can reach past the element itself when a descendant carries a forced-break or
     * widow/orphan shift. `break-before: right` is the case that matters: it jumps over a page to
     * land on the next right-hand one, and that skipped page must come out blank — yet the
     * placement still produces a fragment for it, because the page sits between the two.
     *
     * Two things can earn a page an entry: the element's own box covering it (a block spanning
     * pages paints its background and borders there, and a leaf `<div>` carrying only a border
     * has no blocks or lines to show for it), or the fragment actually carrying something. A page
     * that has neither is one nothing reaches.
     */
    private function fragmentOccupiesPage(PagePlacement $placement, PageFragment $fragment, PageFlow $flow): bool
    {
        if ($fragment->blocks !== [] || $fragment->lines !== []) return true;

        $box = $placement->node->box->marginBox();
        $ownStart = $box->y + $placement->offsetY;
        $ownEnd = $box->bottom() + $placement->offsetY;
        $pageStart = $flow->contentStartForPage($fragment->pageIndex);
        $pageEnd = $pageStart + $flow->usableHeightForPage($fragment->pageIndex);

        return $ownEnd > $pageStart + self::EPSILON && $ownStart < $pageEnd - self::EPSILON;
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
        $tablePlan = $this->tablePlans[spl_object_id($node)] ?? null;
        if ($tablePlan instanceof TablePaginationPlan) {
            return $this->tablePageFragments($tablePlan, $flow);
        }

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
        $tablePlan = $this->tablePlans[spl_object_id($node)] ?? null;
        if ($tablePlan instanceof TablePaginationPlan) {
            return $this->tableBlockFragmentForPage($node, $tablePlan, $pageIndex, $flow);
        }

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
        if ($subtreeEnd - $subtreeStart <= self::EPSILON) {
            // A degenerate (zero-height) subtree is a single point, not a range, and the range
            // check below treats a point that lands exactly on a page boundary as touching
            // neither page. anonymousBlockOfLines() in BlockLayoutEngine produces exactly this: a
            // block that is legitimately zero-height in flow (the `height: 0 !important` the
            // eproc/JFRJ letterhead puts on its logo wrapper, to pull the image out of flow via a
            // negative margin) sitting right at a page's own content start. Ask the flow which
            // page the point itself belongs to instead of range-comparing it against this page.
            if ($flow->pageIndexAt($subtreeStart) !== $pageIndex) {
                return null;
            }
        } elseif ($subtreeEnd <= $pageStart + self::EPSILON || $subtreeStart >= $pageEnd - self::EPSILON) {
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

    /**
     * Repeated table header/footer groups consume real fragmentainer space. This post-pass adds
     * that expansion to every later flow node, the same way forced page breaks add a global
     * offset, so content following a multi-page table cannot overlap its repeated rows.
     *
     * @param array<int,float> $baseOffsets
     * @return array<int,float>
     */
    private function applyTablePaginationOffsets(LayoutNode $root, PageFlow $flow, array $baseOffsets): array
    {
        $offsets = $baseOffsets;
        $globalExpansion = 0.0;
        $planner = new TablePaginationPlanner();

        foreach ($root->children as $child) {
            $this->visitTablePaginationOffsets($child, $flow, $baseOffsets, $offsets, $globalExpansion, $planner);
        }

        return $offsets;
    }

    /**
     * @param array<int,float> $baseOffsets
     * @param array<int,float> $offsets
     */
    private function visitTablePaginationOffsets(
        LayoutNode $node,
        PageFlow $flow,
        array $baseOffsets,
        array &$offsets,
        float &$globalExpansion,
        TablePaginationPlanner $planner,
    ): void {
        $id = spl_object_id($node);
        $effectiveOffset = ($baseOffsets[$id] ?? 0.0) + $globalExpansion;
        $offsets[$id] = $effectiveOffset;

        $display = strtolower(trim($node->source->style->get('display', 'block') ?? 'block'));
        if ($display === 'table') {
            $plan = $planner->plan($node, $effectiveOffset, $flow);
            if ($plan instanceof TablePaginationPlan) {
                $this->tablePlans[$id] = $plan;
                foreach ($node->children as $child) {
                    $this->assignExpandedOffsets($child, $baseOffsets, $offsets, $globalExpansion);
                }

                $naturalEnd = $node->box->marginBox()->bottom() + $effectiveOffset;
                $globalExpansion += max(0.0, $plan->flowEndY - $naturalEnd);
                return;
            }
        }

        foreach ($node->children as $child) {
            $this->visitTablePaginationOffsets($child, $flow, $baseOffsets, $offsets, $globalExpansion, $planner);
        }
    }

    /**
     * Descendants of a planner-managed table are painted through its page-local row fragments;
     * they still receive the expansion accumulated before the table, but the table's own repeated
     * rows must not recursively add that same expansion again.
     *
     * @param array<int,float> $baseOffsets
     * @param array<int,float> $offsets
     */
    private function assignExpandedOffsets(LayoutNode $node, array $baseOffsets, array &$offsets, float $globalExpansion): void
    {
        $id = spl_object_id($node);
        $offsets[$id] = ($baseOffsets[$id] ?? 0.0) + $globalExpansion;
        foreach ($node->children as $child) {
            $this->assignExpandedOffsets($child, $baseOffsets, $offsets, $globalExpansion);
        }
    }

    /** @return list<PageFragment> */
    private function tablePageFragments(TablePaginationPlan $plan, PageFlow $flow): array
    {
        $fragments = [];
        foreach ($plan->slices as $pageIndex => $slice) {
            $blocks = [];
            foreach ($slice['rows'] as $rowPlacement) {
                $blocks[] = $this->placedWholeBlockFragment(
                    $rowPlacement['node'],
                    $pageIndex,
                    $rowPlacement['pageY'],
                    $flow,
                );
            }

            $pageStart = $flow->contentStartForPage($pageIndex);
            $fragments[] = new PageFragment(
                pageIndex: $pageIndex,
                pageY: $slice['pageY'],
                height: $slice['height'],
                continuousStartY: $pageStart + $slice['pageY'],
                continuousEndY: $pageStart + $slice['pageY'] + $slice['height'],
                lines: [],
                blocks: $blocks,
            );
        }

        return $fragments;
    }

    private function tableBlockFragmentForPage(
        LayoutNode $table,
        TablePaginationPlan $plan,
        int $pageIndex,
        PageFlow $flow,
    ): ?BlockFragment {
        $slice = $plan->sliceForPage($pageIndex);
        if ($slice === null) return null;

        $children = [];
        foreach ($slice['rows'] as $rowPlacement) {
            $children[] = $this->placedWholeBlockFragment(
                $rowPlacement['node'],
                $pageIndex,
                $rowPlacement['pageY'],
                $flow,
            );
        }

        $pageStart = $flow->contentStartForPage($pageIndex);
        return new BlockFragment(
            node: $table,
            pageIndex: $pageIndex,
            pageY: $slice['pageY'],
            height: $slice['height'],
            continuousStartY: $pageStart + $slice['pageY'],
            continuousEndY: $pageStart + $slice['pageY'] + $slice['height'],
            lines: [],
            children: $children,
        );
    }

    /**
     * Reuses one laid-out row/cell subtree as an atomic page-local fragment. The LayoutNode stays
     * immutable; only fragment coordinates are translated, which is what lets the same thead row
     * paint on several pages without cloning or mutating the layout tree.
     */
    private function placedWholeBlockFragment(
        LayoutNode $node,
        int $pageIndex,
        float $pageY,
        PageFlow $flow,
    ): BlockFragment {
        $border = $node->box->borderBox();
        $deltaY = $pageY - $border->y;
        $pageStart = $flow->contentStartForPage($pageIndex);

        $lines = [];
        foreach ($node->lineBoxes as $lineIndex => $line) {
            $linePageY = $line->y + $deltaY;
            $linePageBaseline = $line->baseline + $deltaY;
            $lines[] = new LineFragment(
                line: $line,
                lineIndex: $lineIndex,
                pageIndex: $pageIndex,
                pageY: $linePageY,
                pageBaseline: $linePageBaseline,
                continuousY: $pageStart + $linePageY,
                continuousBaseline: $pageStart + $linePageBaseline,
            );
        }

        $children = [];
        foreach ($node->children as $child) {
            $childBorder = $child->box->borderBox();
            $children[] = $this->placedWholeBlockFragment(
                $child,
                $pageIndex,
                $childBorder->y + $deltaY,
                $flow,
            );
        }

        return new BlockFragment(
            node: $node,
            pageIndex: $pageIndex,
            pageY: $pageY,
            height: $border->height,
            continuousStartY: $pageStart + $pageY,
            continuousEndY: $pageStart + $pageY + $border->height,
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
        $tablePlan = $this->tablePlans[spl_object_id($node)] ?? null;
        if ($tablePlan instanceof TablePaginationPlan) {
            return $this->subtreeExtents[spl_object_id($node)] = [
                $tablePlan->continuousStartY,
                $tablePlan->flowEndY,
            ];
        }

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
        $tablePlan = $this->tablePlans[spl_object_id($node)] ?? null;
        if ($tablePlan instanceof TablePaginationPlan) {
            return $tablePlan->flowEndY;
        }

        $bottom = $node->box->marginBox()->bottom() + $this->offsetFor($node, $nodeOffsets);
        foreach ($node->children as $child) {
            $bottom = max($bottom, $this->absoluteSubtreeBottom($child, $nodeOffsets));
        }
        return $bottom;
    }
}
