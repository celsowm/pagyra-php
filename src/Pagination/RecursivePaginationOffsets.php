<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

final class RecursivePaginationOffsets
{
    private const EPSILON = 0.01;

    /** @var array<int,float> */
    private array $offsets = [];
    private float $globalOffset = 0.0;

    /** @return array<int,float> keyed by spl_object_id(LayoutNode) */
    public function resolve(LayoutNode $root, PageFlow $flow): array
    {
        $this->offsets = [];
        $this->globalOffset = 0.0;

        foreach ($root->children as $child) {
            $this->visit($child, $flow);
        }

        return $this->offsets;
    }

    private function visit(LayoutNode $node, PageFlow $flow): void
    {
        $participates = $this->participatesInPageFlow($node);

        if ($participates) {
            $start = $node->box->marginBox()->y + $this->globalOffset;
            $before = $this->forcedBreakOffset($this->breakValue($node, 'before'), $start, $flow);
            if ($before > self::EPSILON) {
                $this->globalOffset += $before;
                $start += $before;
            }

            $inside = $this->breakInsideAvoidOffset($node, $this->globalOffset, $flow);
            if ($inside > self::EPSILON) {
                $this->globalOffset += $inside;
                $start += $inside;
            }

            $widowOrphan = $this->widowOrphanOffset($node, $start, $this->globalOffset, $flow);
            if ($widowOrphan > self::EPSILON) {
                $this->globalOffset += $widowOrphan;
            }
        }

        $this->offsets[spl_object_id($node)] = $this->globalOffset;

        foreach ($node->children as $child) {
            $this->visit($child, $flow);
        }

        if ($participates) {
            $offset = $this->offsets[spl_object_id($node)] ?? $this->globalOffset;
            $end = max($node->box->marginBox()->bottom(), $this->subtreeBottomWithOffsets($node)) + $offset;
            $after = $this->forcedBreakOffset($this->breakValue($node, 'after'), $end, $flow);
            if ($after > self::EPSILON) {
                $this->globalOffset += $after;
            }
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
        if ($height > $flow->contentHeight + self::EPSILON) return 0.0;

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
        if (($border->height) > $flow->contentHeight + self::EPSILON) return 0.0;

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

    private function subtreeBottomWithOffsets(LayoutNode $node): float
    {
        $bottom = $node->box->marginBox()->bottom();
        foreach ($node->children as $child) {
            $childOffset = $this->offsets[spl_object_id($child)] ?? $this->globalOffset;
            $bottom = max($bottom, $this->subtreeBottom($child) + $childOffset);
        }
        return $bottom;
    }
}
