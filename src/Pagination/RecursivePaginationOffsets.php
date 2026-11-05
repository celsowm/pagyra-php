<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

final class RecursivePaginationOffsets
{
    private const EPSILON = 0.01;

    /** @var array<int,float> */
    private array $forcedOffsets = [];
    private float $forcedGlobalOffset = 0.0;
    private int $lastForcedBreakPage = -1;

    /** @var array<int,float> extra shift of each moved line box, keyed by spl_object_id(LineBox) */
    private array $lineShifts = [];

    /** @var array<int,float> space moved lines added inside each node's box, keyed by spl_object_id(LayoutNode) */
    private array $growths = [];

    /** Running total of the space added by moved lines so far, to size each node's growth. */
    private float $lineShiftTotal = 0.0;

    private readonly TablePaginationPlanner $tablePlanner;

    public function __construct()
    {
        $this->tablePlanner = new TablePaginationPlanner();
    }

    /** @return array<int,float> keyed by spl_object_id(LayoutNode) */
    public function resolve(LayoutNode $root, PageFlow $flow): array
    {
        $this->forcedOffsets = [];
        $this->forcedGlobalOffset = 0.0;
        $this->lastForcedBreakPage = -1;
        $this->lineShifts = [];
        $this->growths = [];
        $this->lineShiftTotal = 0.0;

        foreach ($root->children as $child) {
            $this->visitForced($child, $flow);
        }

        $finalOffsets = [];
        $avoidGlobalOffset = 0.0;
        foreach ($root->children as $child) {
            $this->visitBreakInside($child, $flow, $avoidGlobalOffset, $finalOffsets);
        }

        return $finalOffsets;
    }

    /** Highest page index a `break-before`/`break-after` sent content to in the last resolve(), or -1. */
    public function lastForcedBreakPage(): int
    {
        return $this->lastForcedBreakPage;
    }

    /**
     * How far each line box that straddled a page break was moved down in the last resolve(),
     * keyed by spl_object_id(LineBox). A line not listed stays where layout put it, plus its
     * node's offset.
     *
     * @return array<int,float>
     */
    public function lineShifts(): array
    {
        return $this->lineShifts;
    }

    /**
     * How much taller each node's box became in the last resolve() because lines inside it were
     * moved down, keyed by spl_object_id(LayoutNode).
     *
     * @return array<int,float>
     */
    public function growths(): array
    {
        return $this->growths;
    }

    /**
     * @param bool $placedWhole true below a table or an out-of-flow box, where moving a single line
     *                          would shift unrelated content (the cells beside it)
     * @param bool $movableRow  true for a row of a table this class paginates itself, short enough
     *                          to move whole to the next page when an image inside it would straddle
     *                          the break
     */
    private function visitForced(LayoutNode $node, PageFlow $flow, bool $placedWhole = false, bool $movableRow = false): void
    {
        $participates = $this->participatesInPageFlow($node);
        $shiftTotalBefore = $this->lineShiftTotal;
        $display = strtolower(trim($node->source->style->get('display', 'block') ?? 'block'));
        $childrenPlacedWhole = $placedWhole
            || in_array($display, ['table', 'inline-table'], true)
            || ($display !== 'none' && !str_starts_with($display, 'inline') && !$participates);
        // TablePaginationPlanner places the rows of a table with a repeatable header or footer
        // whole; any other table is paginated here, one row at a time.
        $rowsHandledHere = $display === 'table' && !$placedWhole && !$this->tablePlanner->managesRowsOf($node);

        if ($participates) {
            $start = $node->box->marginBox()->y + $this->forcedGlobalOffset;
            $before = $this->forcedBreakOffset($this->breakValue($node, 'before'), $start, $flow);
            if ($before > self::EPSILON) {
                $this->forcedGlobalOffset += $before;
            }
        }

        $this->forcedOffsets[spl_object_id($node)] = $this->forcedGlobalOffset;

        foreach ($node->children as $child) {
            if ($rowsHandledHere && $this->isTableRow($child)) {
                // A row that fits a page moves whole, which keeps its cells side by side. A taller
                // one (a whole document laid out in a single cell) cannot, so the lines inside its
                // cells move on their own, as in any block. A rowspan cell belongs to the row it
                // starts in and counts in that row's height.
                $short = $this->subtreeBottom($child) - $child->box->borderBox()->y <= $flow->minimumUsableHeight() + self::EPSILON;
                $this->visitForced($child, $flow, $short, $short);
                continue;
            }
            $this->visitForced($child, $flow, $childrenPlacedWhole);
        }

        if (!$participates) return;

        $nodeOffset = $this->forcedOffsets[spl_object_id($node)] ?? 0.0;
        $start = $node->box->marginBox()->y + $nodeOffset;
        $widowOrphan = $this->widowOrphanOffset($node, $start, $nodeOffset, $flow);
        if ($widowOrphan > self::EPSILON) {
            $this->addOffsetToVisitedSubtree($node, $widowOrphan);
            $this->forcedGlobalOffset += $widowOrphan;
        }

        if (!$placedWhole) {
            $shift = $this->straddlingLineShift($node, $this->forcedOffsets[spl_object_id($node)] ?? 0.0, $flow);
            if ($shift > self::EPSILON) {
                $this->forcedGlobalOffset += $shift;
                $this->lineShiftTotal += $shift;
            }
        }
        $growth = $this->lineShiftTotal - $shiftTotalBefore;
        if ($growth > self::EPSILON) {
            $this->growths[spl_object_id($node)] = $growth;
        }

        // After the growth: the row itself moves, it does not get taller; the table around it does.
        if ($movableRow) {
            $shift = $this->straddlingRowShift($node, $flow);
            if ($shift > self::EPSILON) {
                $this->addOffsetToVisitedSubtree($node, $shift);
                $this->forcedGlobalOffset += $shift;
                $this->lineShiftTotal += $shift;
            }
        }

        $end = $this->absoluteForcedSubtreeBottom($node);
        $after = $this->forcedBreakOffset($this->breakValue($node, 'after'), $end, $flow);
        if ($after > self::EPSILON) {
            $this->forcedGlobalOffset += $after;
        }
    }

    /** @param array<int,float> $finalOffsets */
    private function visitBreakInside(LayoutNode $node, PageFlow $flow, float &$avoidGlobalOffset, array &$finalOffsets): void
    {
        $baseOffset = $this->forcedOffsets[spl_object_id($node)] ?? 0.0;
        $offset = $baseOffset + $avoidGlobalOffset;

        if ($this->participatesInPageFlow($node)) {
            $pushDown = $this->breakInsideAvoidOffset($node, $offset, $flow);
            if ($pushDown > self::EPSILON) {
                $avoidGlobalOffset += $pushDown;
                $offset += $pushDown;
            }
        }

        $finalOffsets[spl_object_id($node)] = $offset;
        foreach ($node->children as $child) {
            $this->visitBreakInside($child, $flow, $avoidGlobalOffset, $finalOffsets);
        }
    }

    private function participatesInPageFlow(LayoutNode $node): bool
    {
        $style = $node->source->style;
        $display = strtolower(trim($style->get('display', 'block') ?? 'block'));
        if ($display === 'none' || str_starts_with($display, 'inline')) return false;

        $position = strtolower(trim($style->get('position', 'static') ?? 'static'));
        if (in_array($position, ['absolute', 'fixed'], true)) return false;

        $float = strtolower(trim($style->get('float', 'none') ?? 'none'));
        return $float === 'none';
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

        $height = max($node->box->marginBox()->bottom(), $this->subtreeBottom($node)) - $node->box->marginBox()->y;
        if ($height > $flow->maximumUsableHeight() + self::EPSILON) return 0.0;

        $currentPage = $flow->pageIndexAt($start);
        return max(0.0, $flow->contentStartForPage($currentPage + 1) - $start);
    }

    /**
     * Moves each of the node's lines that holds an image (or another atomic inline box) and would
     * straddle a page break to the top of the next page, and returns the space added. Each later
     * line carries the shifts of the lines before it. Such a line goes to the page its baseline is
     * on, so left in place it is painted across the break and loses its top or its bottom off the
     * sheet. A text-only line crossing the break loses nothing (at most part of a line runs into
     * the page margin) and keeps its place, which is also what keeps page counts in step with
     * wkhtmltopdf. A line taller than a page (a scanned page as an inline image) straddles a break
     * wherever it goes and loses its bottom anyway; it moves to the top of the next page only when
     * less than half a page is left for it, which is when staying would hide most of it.
     */
    private function straddlingLineShift(LayoutNode $node, float $offset, PageFlow $flow): float
    {
        $shift = 0.0;
        foreach ($node->lineBoxes as $line) {
            if ($line->atomicBoxes === []) {
                if ($shift > self::EPSILON) $this->lineShifts[spl_object_id($line)] = $shift;
                continue;
            }
            $top = $line->y + $offset + $shift;
            $topPage = $flow->pageIndexAt($top + self::EPSILON);
            $bottomPage = $flow->pageIndexAt(max($top, $top + $line->height - self::EPSILON));
            $room = $flow->contentStartForPage($topPage + 1) - $top;
            $fits = $line->height <= $flow->usableHeightForPage($topPage + 1) + self::EPSILON;
            if ($bottomPage > $topPage && ($fits || $room < $flow->usableHeightForPage($topPage) / 2)) {
                $shift += $room;
            }
            if ($shift > self::EPSILON) {
                $this->lineShifts[spl_object_id($line)] = $shift;
            }
        }
        return $shift;
    }

    /**
     * How far a table row that fits a page has to move down to start the next page, when a line
     * inside it holding an image (or another atomic inline box) would straddle the break; 0 when
     * none would. The row moves whole, since moving the line alone would leave the cells beside it
     * behind.
     */
    private function straddlingRowShift(LayoutNode $row, PageFlow $flow): float
    {
        if (!$this->hasStraddlingAtomicLine($row, $flow)) return 0.0;

        $top = $row->box->borderBox()->y + ($this->forcedOffsets[spl_object_id($row)] ?? 0.0);
        $topPage = $flow->pageIndexAt($top + self::EPSILON);
        return max(0.0, $flow->contentStartForPage($topPage + 1) - $top);
    }

    private function hasStraddlingAtomicLine(LayoutNode $node, PageFlow $flow): bool
    {
        $offset = $this->forcedOffsets[spl_object_id($node)] ?? 0.0;
        foreach ($node->lineBoxes as $line) {
            if ($line->atomicBoxes === []) continue;
            $top = $line->y + $offset;
            if ($flow->pageIndexAt(max($top, $top + $line->height - self::EPSILON)) > $flow->pageIndexAt($top + self::EPSILON)) {
                return true;
            }
        }
        foreach ($node->children as $child) {
            if ($this->hasStraddlingAtomicLine($child, $flow)) return true;
        }
        return false;
    }

    private function isTableRow(LayoutNode $node): bool
    {
        return strtolower(trim($node->source->style->get('display', '') ?? '')) === 'table-row';
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
        $bottom = $border->bottom() + $offset + ($this->growths[spl_object_id($node)] ?? 0.0);
        $startPage = $flow->pageIndexAt($top);
        $endPage = $flow->pageIndexAt(max($top, $bottom - self::EPSILON));
        if ($startPage === $endPage) return 0.0;

        return max(0.0, $flow->contentStartForPage($startPage + 1) - $top);
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
        $this->lastForcedBreakPage = max($this->lastForcedBreakPage, $target);
        return max(0.0, $flow->contentStartForPage($target) - $coordinate);
    }

    private function positiveIntegerStyle(LayoutNode $node, string $property, int $fallback): int
    {
        $raw = trim($node->source->style->get($property, (string) $fallback) ?? (string) $fallback);
        if (!preg_match('/^\d+$/', $raw)) return $fallback;
        return max(1, (int) $raw);
    }

    private function subtreeBottom(LayoutNode $node): float
    {
        $bottom = $node->box->marginBox()->bottom();
        foreach ($node->children as $child) $bottom = max($bottom, $this->subtreeBottom($child));
        return $bottom;
    }

    private function addOffsetToVisitedSubtree(LayoutNode $node, float $delta): void
    {
        $id = spl_object_id($node);
        $this->forcedOffsets[$id] = ($this->forcedOffsets[$id] ?? 0.0) + $delta;
        foreach ($node->children as $child) {
            $this->addOffsetToVisitedSubtree($child, $delta);
        }
    }

    private function absoluteForcedSubtreeBottom(LayoutNode $node): float
    {
        $offset = $this->forcedOffsets[spl_object_id($node)] ?? 0.0;
        $bottom = $node->box->marginBox()->bottom() + $offset + ($this->growths[spl_object_id($node)] ?? 0.0);
        foreach ($node->children as $child) {
            $bottom = max($bottom, $this->absoluteForcedSubtreeBottom($child));
        }
        return $bottom;
    }
}
