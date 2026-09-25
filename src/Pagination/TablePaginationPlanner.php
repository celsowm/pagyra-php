<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LayoutNode;

/**
 * Packs table rows into page-local slices while reserving repeatable table header/footer groups.
 *
 * This intentionally activates only for the simple row model the layout engine can fragment
 * safely: direct laid-out rows with no rowspan > 1. A spanning cell owns geometry across several
 * row boxes, so repeating/packing those rows independently would duplicate or cut that cell.
 */
final class TablePaginationPlanner
{
    private const EPSILON = 0.01;

    public function plan(LayoutNode $table, float $offsetY, PageFlow $flow): ?TablePaginationPlan
    {
        $display = strtolower(trim($table->source->style->get('display', 'block') ?? 'block'));
        if ($display !== 'table' || $table->children === []) {
            return null;
        }

        $headers = [];
        $bodies = [];
        $footers = [];
        foreach ($table->children as $row) {
            if ($this->hasRowSpan($row)) {
                return null;
            }
            $section = strtolower(trim($row->source->style->get('x-table-section', 'body') ?? 'body'));
            match ($section) {
                'header' => $headers[] = $row,
                'footer' => $footers[] = $row,
                default => $bodies[] = $row,
            };
        }

        if (($headers === [] && $footers === []) || $bodies === []) {
            return null;
        }

        $headerHeight = $this->rowsHeight($headers);
        $footerHeight = $this->rowsHeight($footers);
        $minimumPage = $flow->minimumUsableHeight();

        // A row group that consumes a whole fragmentainer is not repeatable in browsers either:
        // keep its first/last natural occurrence and let normal pagination handle that edge case.
        $repeatHeader = $headers !== [] && $headerHeight < $minimumPage - self::EPSILON;
        $repeatFooter = $footers !== [] && $footerHeight < $minimumPage - self::EPSILON;
        if ($repeatHeader && $repeatFooter && $headerHeight + $footerHeight >= $minimumPage - self::EPSILON) {
            $repeatFooter = false;
        }

        $border = $table->box->borderBox();
        $content = $table->box->content;
        $topInset = max(0.0, $content->y - $border->y);
        $bottomInset = max(0.0, $border->bottom() - $content->bottom());

        $originalBorderStart = $border->y + $offsetY;
        $firstPage = $flow->pageIndexAt(max(0.0, $originalBorderStart));
        $firstPageStart = $flow->contentStartForPage($firstPage);
        $firstBorderY = $originalBorderStart - $firstPageStart;
        $firstContentY = $content->y + $offsetY - $firstPageStart;

        // Do not strand only a repeated header at the bottom of the page. When even the first
        // body row cannot fit beside it, move the table fragment to the next page as a unit.
        $firstBodyHeight = $this->rowHeight($bodies[0]);
        $firstFooterReserve = $repeatFooter ? $footerHeight : 0.0;
        $firstNeed = max(0.0, $firstContentY)
            + ($headers !== [] ? $headerHeight : 0.0)
            + $firstBodyHeight
            + $firstFooterReserve
            + $bottomInset;
        if ($firstNeed > $flow->usableHeightForPage($firstPage) + self::EPSILON && $firstBorderY > self::EPSILON) {
            $firstPage++;
            $firstPageStart = $flow->contentStartForPage($firstPage);
            $firstBorderY = 0.0;
            $firstContentY = $topInset;
        }

        $slices = [];
        $bodyIndex = 0;
        $pageIndex = $firstPage;
        $firstSlice = true;
        $lastBorderEnd = $originalBorderStart;

        while ($bodyIndex < count($bodies)) {
            $usable = $flow->usableHeightForPage($pageIndex);
            $pageStart = $flow->contentStartForPage($pageIndex);
            $tablePageY = $firstSlice ? $firstBorderY : 0.0;
            $cursor = $firstSlice ? $firstContentY : 0.0;

            $pageHeaders = ($firstSlice || $repeatHeader) ? $headers : [];
            $pageHeaderHeight = $this->rowsHeight($pageHeaders);

            $remainingBodyHeight = 0.0;
            for ($i = $bodyIndex; $i < count($bodies); $i++) {
                $remainingBodyHeight += $this->rowHeight($bodies[$i]);
            }

            $footerOnFinal = $footers !== [];
            $wouldFinish = $cursor + $pageHeaderHeight + $remainingBodyHeight
                + ($footerOnFinal ? $footerHeight : 0.0)
                + $bottomInset
                <= $usable + self::EPSILON;

            $reserveFooter = $wouldFinish
                ? ($footerOnFinal ? $footerHeight : 0.0)
                : ($repeatFooter ? $footerHeight : 0.0);
            $limit = max($cursor + $pageHeaderHeight, $usable - $reserveFooter - ($wouldFinish ? $bottomInset : 0.0));

            $rows = [];
            foreach ($pageHeaders as $row) {
                $rows[] = ['node' => $row, 'pageY' => $cursor];
                $cursor += $this->rowHeight($row);
            }

            $bodyStartIndex = $bodyIndex;
            while ($bodyIndex < count($bodies)) {
                $row = $bodies[$bodyIndex];
                $height = $this->rowHeight($row);
                if ($bodyIndex > $bodyStartIndex && $cursor + $height > $limit + self::EPSILON) {
                    break;
                }
                if ($bodyIndex === $bodyStartIndex && $cursor + $height > $limit + self::EPSILON && !$wouldFinish) {
                    // An oversized row must make progress even if it consumes the whole fragment.
                    $rows[] = ['node' => $row, 'pageY' => $cursor];
                    $cursor += $height;
                    $bodyIndex++;
                    break;
                }
                if ($cursor + $height > $limit + self::EPSILON) {
                    break;
                }
                $rows[] = ['node' => $row, 'pageY' => $cursor];
                $cursor += $height;
                $bodyIndex++;
            }

            $isFinal = $bodyIndex >= count($bodies);
            $pageFooters = [];
            if ($isFinal) {
                $pageFooters = $footers;
            } elseif ($repeatFooter) {
                $pageFooters = $footers;
                $cursor = max($cursor, $usable - $footerHeight);
            }

            foreach ($pageFooters as $row) {
                $rows[] = ['node' => $row, 'pageY' => $cursor];
                $cursor += $this->rowHeight($row);
            }

            $sliceEnd = $isFinal ? min($usable, $cursor + $bottomInset) : $usable;
            $sliceHeight = max(0.0, $sliceEnd - $tablePageY);
            $slices[$pageIndex] = [
                'pageIndex' => $pageIndex,
                'pageY' => $tablePageY,
                'height' => $sliceHeight,
                'rows' => $rows,
            ];
            $lastBorderEnd = $pageStart + $tablePageY + $sliceHeight;

            if ($isFinal) {
                break;
            }

            $pageIndex++;
            $firstSlice = false;
        }

        if ($slices === []) {
            return null;
        }

        $lastPage = (int) array_key_last($slices);
        $firstSliceData = $slices[$firstPage];
        $continuousStart = $flow->contentStartForPage($firstPage) + $firstSliceData['pageY'];
        $flowEnd = $lastBorderEnd + $table->box->margin->bottom;

        return new TablePaginationPlan(
            firstPage: $firstPage,
            lastPage: $lastPage,
            continuousStartY: $continuousStart,
            continuousEndY: $lastBorderEnd,
            flowEndY: $flowEnd,
            slices: $slices,
        );
    }

    private function rowHeight(LayoutNode $row): float
    {
        return max(0.0, $row->box->borderBox()->height);
    }

    /** @param list<LayoutNode> $rows */
    private function rowsHeight(array $rows): float
    {
        $height = 0.0;
        foreach ($rows as $row) {
            $height += $this->rowHeight($row);
        }
        return $height;
    }

    private function hasRowSpan(LayoutNode $row): bool
    {
        foreach ($row->children as $cell) {
            $raw = trim($cell->source->node->attribute('rowspan') ?? '1');
            if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 1) {
                return true;
            }
        }
        return false;
    }
}
