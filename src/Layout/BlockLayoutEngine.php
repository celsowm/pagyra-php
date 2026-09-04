<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Geometry\Edges;
use Pagyra\Geometry\Rect;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;

final class BlockLayoutEngine
{
    private const ROOT_FONT_SIZE = 16.0;

    private readonly LengthParser $lengthParser;
    private readonly InlineTextFormatter $inlineTextFormatter;

    public function __construct(
        private readonly float $viewportWidth,
        private readonly float $viewportHeight,
        ?TextMetrics $textMetrics = null,
    ) {
        $this->lengthParser = new LengthParser($viewportWidth, $viewportHeight);
        $this->inlineTextFormatter = new InlineTextFormatter($textMetrics ?? new HeuristicTextMetrics());
    }

    public function layout(StyledNode $root): LayoutNode
    {
        return $this->layoutDocument($root);
    }

    private function layoutDocument(StyledNode $root): LayoutNode
    {
        $children = [];
        $cursorY = 0.0;
        $previousBorderBottom = null;
        $previousBottomMargin = 0.0;
        $float = new FloatRun(0.0, $this->viewportWidth);

        foreach ($root->children as $child) {
            if ($this->display($child) === 'none' || !$this->isBlockLevel($child)) continue;
            $childFontSize = $this->resolveFontSize($child, self::ROOT_FONT_SIZE);

            $side = $this->floatSide($child);
            if ($side !== null) {
                $runY = $float->active ? $float->startY : ($previousBorderBottom ?? $cursorY);
                [$layout, $float] = $this->layoutFloatChild($child, $side, $float, $runY, $this->viewportHeight, $childFontSize);
                $children[] = $layout;
                continue;
            }
            if ($float->active) {
                $cursorY = max($cursorY, $float->bottom);
                $previousBorderBottom = null;
                $previousBottomMargin = 0.0;
                $float = $float->reset(0.0, $this->viewportWidth);
            }

            $childTopMargin = $this->resolveMarginSide($child, 'top', $this->viewportWidth, $this->viewportHeight, $childFontSize);
            $flowY = $previousBorderBottom === null ? $cursorY : $previousBorderBottom + BlockMath::collapseMarginSet([$previousBottomMargin, $childTopMargin]) - $childTopMargin;
            $layout = $this->layoutBlockLevelChild($child, 0.0, $flowY, $this->viewportWidth, $this->viewportHeight, self::ROOT_FONT_SIZE);
            $children[] = $layout;
            $previousBorderBottom = $layout->box->borderBox()->bottom();
            $previousBottomMargin = $layout->box->margin->bottom;
            $cursorY = $previousBorderBottom + $previousBottomMargin;
        }
        if ($float->active) $cursorY = max($cursorY, $float->bottom);

        return new LayoutNode($root, new LayoutBox(new Rect(0.0, 0.0, $this->viewportWidth, max(0.0, $cursorY))), $children, self::ROOT_FONT_SIZE);
    }

    /**
     * @param bool $heightIsMinimum treats a specified `height` as a lower bound instead of a
     *        fixed value, which is how CSS defines it for table cells: "the height of a cell box
     *        is the minimum height required by the content". Without it, a `<td height="14pt">`
     *        holding a paragraph reports a 19px box while its content runs on for hundreds of
     *        pixels; the table then reports a height that does not cover its own content, and
     *        pagination drops everything past the first page because no fragment claims it.
     */
    private function layoutBlock(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize, bool $heightIsMinimum = false): LayoutNode
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        [$marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw] = $this->edgeRawValues($styled, 'margin');
        $margin = $this->resolveRawEdges($marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw, $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);

        $available = max(0.0, $containingWidth - $margin->horizontal());
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = max(0.0, $available - $horizontalNonContent);
        } else {
            $resolvedWidth = $this->resolveLength($widthValue, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentWidth = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedWidth - $horizontalNonContent) : max(0.0, $resolvedWidth);
        }
        $contentWidth = $this->applyHorizontalConstraints($styled, $contentWidth, $horizontalNonContent, $containingWidth, $containingHeight, $fontSize);

        if (!$this->isAuto($widthValue)) {
            $usedMargins = BlockMath::resolveAutoMargins($containingWidth, $contentWidth + $horizontalNonContent, $margin->left, $margin->right, $this->isAuto($marginLeftRaw ?? '0'), $this->isAuto($marginRightRaw ?? '0'));
            $margin = new Edges($margin->top, $usedMargins['right'], $margin->bottom, $usedMargins['left']);
        }

        // CSS 2.1 8.3.1: a block's top margin collapses with the top margin of its first in-flow
        // block child when no top border or padding separates them, and the same happens at the
        // bottom for the last child of an auto-height box. Without this the eproc pattern
        // `<ol><li class=x><p class=x>` stacked three 5mm margins at each end of every list item
        // instead of one, adding a page of blank space to a 12-item ementa.
        $segments = $this->flowSegments($styled);
        $collapsesTop = $border->top <= 0.0 && $padding->top <= 0.0;
        $firstChildTopMargin = $collapsesTop ? $this->leadingChildTopMargin($segments, $containingWidth, $containingHeight, $fontSize) : 0.0;
        $marginTopUsed = max($margin->top, $firstChildTopMargin);
        $margin = new Edges($marginTopUsed, $margin->right, $margin->bottom, $margin->left);

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;
        $cursorY = $contentY;
        $children = [];
        $firstInFlowChild = true;
        $lastChildBorderBottom = null;
        $lastChildBottomMargin = 0.0;
        $previousBorderBottom = null;
        $previousBottomMargin = 0.0;
        $float = new FloatRun($contentX, $contentX + $contentWidth);

        $lineBoxes = [];

        foreach ($segments as $segment) {
            if ($segment[0] === 'inline') {
                if ($float->active) {
                    $cursorY = max($cursorY, $float->bottom);
                    $float = $float->reset($contentX, $contentX + $contentWidth);
                }
                $run = $this->inlineTextFormatter->layout(
                    new StyledNode($styled->node, $styled->style, $segment[1]),
                    $contentX,
                    $cursorY,
                    $contentWidth,
                    $fontSize,
                );
                array_push($lineBoxes, ...$run->lines);
                $cursorY += $run->height;
                // An inline run between two blocks separates their margins, so nothing collapses
                // across it — and it also ends the run of leading children whose top margin
                // could still have collapsed into this block's own.
                $previousBorderBottom = null;
                $previousBottomMargin = 0.0;
                $firstInFlowChild = false;
                $lastChildBorderBottom = null;
                $lastChildBottomMargin = 0.0;
                continue;
            }

            $child = $segment[1];
            $childFontSize = $this->resolveFontSize($child, $fontSize);

            $side = $this->floatSide($child);
            if ($side !== null) {
                $runY = $float->active ? $float->startY : ($previousBorderBottom ?? $cursorY);
                [$childLayout, $float] = $this->layoutFloatChild($child, $side, $float, $runY, $containingHeight, $childFontSize);
                $children[] = $childLayout;
                // A float does not collapse margins, but it does mean the next in-flow child is
                // no longer the one whose top margin may merge into this block's own.
                $firstInFlowChild = false;
                continue;
            }
            if ($float->active) {
                $cursorY = max($cursorY, $float->bottom);
                $previousBorderBottom = null;
                $previousBottomMargin = 0.0;
                $firstInFlowChild = false;
                $float = $float->reset($contentX, $contentX + $contentWidth);
            }

            $childTopMargin = $this->collapsedTopMargin($child, $contentWidth, $containingHeight, $childFontSize);
            if ($previousBorderBottom !== null) {
                $childFlowY = $previousBorderBottom + BlockMath::collapseMarginSet([$previousBottomMargin, $childTopMargin]) - $childTopMargin;
            } elseif ($firstInFlowChild && $collapsesTop) {
                // Its top margin already went into this block's own, so start the child's border
                // box exactly at the content edge rather than pushing it down a second time.
                $childFlowY = $contentY - $childTopMargin;
            } else {
                $childFlowY = $cursorY;
            }
            $childLayout = $this->layoutBlockLevelChild($child, $contentX, $childFlowY, $contentWidth, $containingHeight, $fontSize);
            $children[] = $childLayout;
            $firstInFlowChild = false;
            $previousBorderBottom = $childLayout->box->borderBox()->bottom();
            $previousBottomMargin = $childLayout->box->margin->bottom;
            $lastChildBorderBottom = $previousBorderBottom;
            $lastChildBottomMargin = $previousBottomMargin;
            $cursorY = $previousBorderBottom + $previousBottomMargin;
        }
        if ($float->active) $cursorY = max($cursorY, $float->bottom);

        $heightValue = $styled->style->get('height', 'auto') ?? 'auto';
        // The mirror of the top rule: the last in-flow child's bottom margin escapes an
        // auto-height box with no bottom border or padding instead of growing it, and becomes
        // this block's own bottom margin for the sibling below.
        if (
            $lastChildBorderBottom !== null
            && !$float->active
            && $border->bottom <= 0.0
            && $padding->bottom <= 0.0
            && $this->isAuto($heightValue)
        ) {
            $cursorY = $lastChildBorderBottom;
            $margin = new Edges($margin->top, $margin->right, max($margin->bottom, $lastChildBottomMargin), $margin->left);
        }

        $inlineLayout = new InlineTextLayout($lineBoxes, max(0.0, $cursorY - $contentY));
        $autoContentHeight = max(0.0, $cursorY - $contentY);
        $verticalNonContent = $padding->vertical() + $border->vertical();
        if ($this->isAuto($heightValue)) {
            $contentHeight = $autoContentHeight;
        } else {
            $resolvedHeight = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentHeight = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedHeight - $verticalNonContent) : max(0.0, $resolvedHeight);
            if ($heightIsMinimum) $contentHeight = max($contentHeight, $autoContentHeight);
        }
        $contentHeight = $this->applyVerticalConstraints($styled, $contentHeight, $verticalNonContent, $containingWidth, $containingHeight, $fontSize);

        return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin), $children, $fontSize, $inlineLayout->lines);
    }

    private function layoutBlockLevelChild(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        if ($styled->node->isImage() || $styled->node->isSvg()) {
            return $this->layoutBlockReplaced($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize);
        }

        return $this->display($styled) === 'table'
            ? $this->layoutTable($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize)
            : $this->layoutBlock($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize);
    }

    /**
     * A replaced element that is block-level (`<img style="display:block">` and friends) still
     * has replaced-element sizing and still has to paint its image; only its outer box takes
     * part in block flow. Running it through layoutBlock() instead gives it the ordinary block
     * treatment, where `width:auto` stretches to the container and `height:auto` measures the
     * (nonexistent) child content as zero, so the image ends up as a full-width zero-height
     * strip that no paint step ever draws.
     *
     * The content box therefore comes from the same replaced-sizing resolver the inline path
     * uses, and the image itself is emitted as a single line holding one atomic box covering
     * that content box. That reuses the existing atomic-image paint and pagination path
     * verbatim: the box carries zero margin/padding/border because this LayoutNode's own box
     * already accounts for them.
     */
    private function layoutBlockReplaced(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        [$marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw] = $this->edgeRawValues($styled, 'margin');
        $margin = $this->resolveRawEdges($marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw, $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);

        [$contentWidth, $contentHeight] = $this->inlineTextFormatter->replacedContentSize($styled, $containingWidth, $fontSize);

        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $usedMargins = BlockMath::resolveAutoMargins(
            $containingWidth,
            $contentWidth + $horizontalNonContent,
            $margin->left,
            $margin->right,
            $this->isAuto($marginLeftRaw ?? '0'),
            $this->isAuto($marginRightRaw ?? '0'),
        );
        $margin = new Edges($margin->top, $usedMargins['right'], $margin->bottom, $usedMargins['left']);

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;

        $box = new AtomicInlineBox(
            source: $styled,
            x: $contentX,
            y: $contentY,
            width: $contentWidth,
            height: $contentHeight,
            style: $styled->style,
            contentWidth: $contentWidth,
            contentHeight: $contentHeight,
        );
        $line = new LineBox($contentX, $contentY, $contentWidth, $contentHeight, $contentY + $contentHeight, '', [], [$box]);

        return new LayoutNode(
            $styled,
            new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin),
            [],
            $fontSize,
            [$line],
        );
    }

    /**
     * Lays out a `<table>` as a real grid of columns and rows, honoring `colspan`, `rowspan`,
     * and `border-collapse: collapse`. `<thead>`/`<tbody>`/`<tfoot>` wrappers are read through
     * transparently (collectTableRows()); buildTableGrid() places every cell at its true
     * (row, col) origin and reserves the extra columns/rows a span covers, so a spanning cell
     * no longer visually compresses the columns after it. Per-column `<col>` width hints and
     * caption/footer semantics remain unimplemented.
     *
     * Column widths follow the same overall shape as pagyra-js's real (min/max-content based)
     * table algorithm for its common "preferred widths fit" case: measure each column's
     * natural width and distribute any leftover space proportionally. What's ported is
     * deliberately simpler, because pagyra-js's min-content measurement depends on a
     * recursive intrinsic-sizing pass (TableLayoutStrategy::calculateColumnWidths, walking
     * intrinsicInlineSize/minIntrinsicInlineSize across every descendant) that this PHP port
     * does not have yet for arbitrary content. Each column's "natural width" here is instead
     * the widest single-line shrink-to-fit measurement (shrinkToFitWidth(), the same helper
     * float layout uses) of any cell touching that column; a colspanning cell's measured width
     * is split evenly across the columns it covers, mirroring pagyra-js's own simplification
     * for that case. If the total exceeds the table's content width, columns are scaled down
     * proportionally rather than the JS reference's min/max blend.
     *
     * Row height for a rowspanning cell is reconciled the same incremental way: rows are laid
     * out top to bottom, and once a spanning cell's own row range has closed, whatever height
     * it still needs beyond what the non-spanning cells already gave those rows is added onto
     * the last row it covers. The spanning cell's own box is then stretched down to that full
     * span so its border/background covers it; its content stays anchored at the top, since
     * this port has no vertical-align support for table cells yet.
     */
    private function layoutTable(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        $margin = $this->resolveEdges($styled, 'margin', $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);
        $available = max(0.0, $containingWidth - $margin->horizontal());
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = max(0.0, $available - $horizontalNonContent);
        } else {
            $resolvedWidth = $this->resolveLength($widthValue, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentWidth = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedWidth - $horizontalNonContent) : max(0.0, $resolvedWidth);
        }
        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;

        $rows = $this->collectTableRows($styled);
        [$placements, $columnCount] = $this->buildTableGrid($rows);
        if ($columnCount === 0) {
            return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, 0.0), $padding, $border, $margin), [], $fontSize);
        }

        if ($this->isBorderCollapse($styled)) {
            $placements = $this->collapseCellBorders($placements, $contentWidth, $containingHeight, $fontSize);
        }

        $naturalColumnWidths = array_fill(0, $columnCount, 0.0);
        foreach ($placements as $p) {
            $cellFontSize = $this->resolveFontSize($p['cell'], $fontSize);
            $share = $this->shrinkToFitWidth($p['cell'], $contentWidth, $cellFontSize) / $p['colSpan'];
            for ($k = 0; $k < $p['colSpan']; $k++) {
                $naturalColumnWidths[$p['col'] + $k] = max($naturalColumnWidths[$p['col'] + $k], $share);
            }
        }
        $totalNatural = array_sum($naturalColumnWidths);
        if ($totalNatural <= 0.0) {
            $columnWidths = array_fill(0, $columnCount, $contentWidth / $columnCount);
        } elseif ($totalNatural <= $contentWidth) {
            $slack = $contentWidth - $totalNatural;
            $columnWidths = array_map(static fn (float $w): float => $w + $slack * ($w / $totalNatural), $naturalColumnWidths);
        } else {
            $scale = $contentWidth / $totalNatural;
            $columnWidths = array_map(static fn (float $w): float => $w * $scale, $naturalColumnWidths);
        }
        $columnX = [];
        $x = $contentX;
        foreach ($columnWidths as $w) {
            $columnX[] = $x;
            $x += $w;
        }

        $rowCount = count($rows);
        $placementsByRow = array_fill(0, $rowCount, []);
        foreach ($placements as $p) $placementsByRow[$p['row']][] = $p;

        $rowHeights = array_fill(0, $rowCount, 0.0);
        $rowY = array_fill(0, $rowCount + 1, $contentY);
        $cellLayoutsByRow = array_fill(0, $rowCount, []);
        $spanningByEndRow = array_fill(0, $rowCount, []);

        for ($r = 0; $r < $rowCount; $r++) {
            $singleRowHeight = 0.0;
            foreach ($placementsByRow[$r] as $p) {
                $spanWidth = array_sum(array_slice($columnWidths, $p['col'], $p['colSpan']));
                $cellLayout = $this->layoutBlock($p['cell'], $columnX[$p['col']], $rowY[$r], $spanWidth, $containingHeight, $fontSize, heightIsMinimum: true);
                $cellLayoutsByRow[$r][] = ['layout' => $cellLayout, 'placement' => $p];
                $height = $cellLayout->box->borderBox()->height;
                if ($p['rowSpan'] === 1) {
                    $singleRowHeight = max($singleRowHeight, $height);
                } else {
                    $spanningByEndRow[$r + $p['rowSpan'] - 1][] = ['startRow' => $r, 'height' => $height];
                }
            }
            $rowHeights[$r] = $singleRowHeight;
            foreach ($spanningByEndRow[$r] as $spanning) {
                $spannedSoFar = array_sum(array_slice($rowHeights, $spanning['startRow'], $r - $spanning['startRow'] + 1));
                if ($spanning['height'] > $spannedSoFar) {
                    $rowHeights[$r] += $spanning['height'] - $spannedSoFar;
                }
            }
            $rowY[$r + 1] = $rowY[$r] + $rowHeights[$r];
        }

        $rowLayouts = [];
        foreach ($rows as $r => $tr) {
            $cellLayouts = [];
            foreach ($cellLayoutsByRow[$r] as ['layout' => $cellLayout, 'placement' => $p]) {
                if ($p['rowSpan'] > 1) {
                    $spanHeight = $rowY[$r + $p['rowSpan']] - $rowY[$r];
                    $box = $cellLayout->box;
                    $extra = max(0.0, $spanHeight - $box->borderBox()->height);
                    if ($extra > 0.0) {
                        $stretched = new LayoutBox(new Rect($box->content->x, $box->content->y, $box->content->width, $box->content->height + $extra), $box->padding, $box->border, $box->margin);
                        $cellLayout = new LayoutNode($cellLayout->source, $stretched, $cellLayout->children, $cellLayout->fontSize, $cellLayout->lineBoxes);
                    }
                }
                $cellLayouts[] = $cellLayout;
            }
            $rowLayouts[] = new LayoutNode($tr, new LayoutBox(new Rect($contentX, $rowY[$r], $contentWidth, $rowHeights[$r])), $cellLayouts, $this->resolveFontSize($tr, $fontSize));
        }

        return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $rowY[$rowCount] - $contentY), $padding, $border, $margin), $rowLayouts, $fontSize);
    }

    /** @return list<StyledNode> descendant `<tr>` elements, looking through `<thead>`/`<tbody>`/`<tfoot>` wrappers. */
    private function collectTableRows(StyledNode $table): array
    {
        $rows = [];
        foreach ($table->children as $child) {
            if ($child->node->type !== 'element') continue;
            $tag = strtolower($child->node->tagName ?? '');
            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['tbody', 'thead', 'tfoot'], true)) {
                array_push($rows, ...$this->collectTableRows($child));
            }
        }
        return $rows;
    }

    /** @return list<StyledNode> direct `<td>`/`<th>` children of a `<tr>`. */
    private function collectTableCells(StyledNode $row): array
    {
        $cells = [];
        foreach ($row->children as $child) {
            if ($child->node->type !== 'element') continue;
            if (in_array(strtolower($child->node->tagName ?? ''), ['td', 'th'], true)) $cells[] = $child;
        }
        return $cells;
    }

    /**
     * Places every `<td>`/`<th>` at its real (row, col) origin instead of assuming one cell per
     * column/row: `rowspan` reserves the same column across the following rows via
     * $occupiedUntilRow, and `colspan` claims the following column indices in the same row.
     * An invalid or missing span (non-numeric, zero, negative) falls back to 1, matching this
     * port's general stance of ignoring what it cannot parse rather than failing the render.
     *
     * @param list<StyledNode> $rows
     * @return array{0: list<array{row:int,col:int,colSpan:int,rowSpan:int,cell:StyledNode}>, 1: int}
     */
    private function buildTableGrid(array $rows): array
    {
        $placements = [];
        $occupiedUntilRow = [];
        $columnCount = 0;
        foreach ($rows as $r => $tr) {
            $c = 0;
            foreach ($this->collectTableCells($tr) as $cell) {
                while (($occupiedUntilRow[$c] ?? -1) >= $r) $c++;
                $colSpan = max(1, (int) ($cell->node->attribute('colspan') ?? '1'));
                $rowSpan = max(1, (int) ($cell->node->attribute('rowspan') ?? '1'));
                $placements[] = ['row' => $r, 'col' => $c, 'colSpan' => $colSpan, 'rowSpan' => $rowSpan, 'cell' => $cell];
                for ($k = 0; $k < $colSpan; $k++) {
                    $occupiedUntilRow[$c + $k] = max($occupiedUntilRow[$c + $k] ?? -1, $r + $rowSpan - 1);
                }
                $c += $colSpan;
            }
            $columnCount = max($columnCount, $c);
        }
        return [$placements, $columnCount];
    }

    private function isBorderCollapse(StyledNode $table): bool
    {
        return strtolower(trim($table->style->get('border-collapse', 'separate') ?? 'separate')) === 'collapse';
    }

    /**
     * `border-collapse: collapse` folds each interior shared edge into a single border instead
     * of letting both cells paint their own: for every pair of grid-adjacent cells, the thicker
     * declared side wins (a tie keeps the earlier cell's side) and the other side is zeroed out
     * on a border-adjusted copy of that cell's StyledNode. Only cell-to-cell adjacency is
     * resolved this way; merging the table's own border into its edge cells is not implemented.
     *
     * @param list<array{row:int,col:int,colSpan:int,rowSpan:int,cell:StyledNode}> $placements
     * @return list<array{row:int,col:int,colSpan:int,rowSpan:int,cell:StyledNode}>
     */
    private function collapseCellBorders(array $placements, float $widthReference, float $heightReference, float $fontSize): array
    {
        $ownerAt = [];
        foreach ($placements as $i => $p) {
            for ($r = $p['row']; $r < $p['row'] + $p['rowSpan']; $r++) {
                for ($c = $p['col']; $c < $p['col'] + $p['colSpan']; $c++) {
                    $ownerAt[$r][$c] = $i;
                }
            }
        }

        $zeroSides = array_fill(0, count($placements), []);
        foreach ($placements as $i => $p) {
            $right = $ownerAt[$p['row']][$p['col'] + $p['colSpan']] ?? null;
            if ($right !== null && $right !== $i) {
                [$loserIndex, $loserSide] = $this->collapsedLoser($placements, $i, 'right', $right, 'left', $widthReference, $heightReference, $fontSize);
                $zeroSides[$loserIndex][] = $loserSide;
            }
            $bottom = $ownerAt[$p['row'] + $p['rowSpan']][$p['col']] ?? null;
            if ($bottom !== null && $bottom !== $i) {
                [$loserIndex, $loserSide] = $this->collapsedLoser($placements, $i, 'bottom', $bottom, 'top', $widthReference, $heightReference, $fontSize);
                $zeroSides[$loserIndex][] = $loserSide;
            }
        }

        foreach ($placements as $i => $p) {
            if ($zeroSides[$i] !== []) $placements[$i]['cell'] = $this->withBorderSidesRemoved($p['cell'], $zeroSides[$i]);
        }
        return $placements;
    }

    /** @return array{0: int, 1: string} the [placement index, side] to zero out. */
    private function collapsedLoser(array $placements, int $a, string $sideA, int $b, string $sideB, float $widthReference, float $heightReference, float $fontSize): array
    {
        $widthA = $this->resolveBorderEdges($placements[$a]['cell'], $widthReference, $heightReference, $fontSize)->{$sideA};
        $widthB = $this->resolveBorderEdges($placements[$b]['cell'], $widthReference, $heightReference, $fontSize)->{$sideB};
        return $widthA >= $widthB ? [$b, $sideB] : [$a, $sideA];
    }

    /** @param list<string> $sides */
    private function withBorderSidesRemoved(StyledNode $cell, array $sides): StyledNode
    {
        $properties = $cell->style->properties;
        foreach ($sides as $side) {
            $properties['border-' . $side . '-style'] = 'none';
            $properties['border-' . $side . '-width'] = '0';
        }
        return new StyledNode($cell->node, new ComputedStyle($properties), $cell->children);
    }

    /**
     * `float: left` / `float: right` (not `none`/absent), or null for the normal-flow case.
     */
    private function floatSide(StyledNode $node): ?string
    {
        $value = strtolower(trim($node->style->get('float', 'none') ?? 'none'));
        return $value === 'left' || $value === 'right' ? $value : null;
    }

    /**
     * Lays out a `float: left|right` block child alongside its run instead of stacking it
     * vertically: left floats grow inward from the run's left edge, right floats grow inward
     * from the right edge, both sharing the run's starting Y. Reuses layoutBlock() unmodified
     * by pre-resolving the float's own width and threading it in as $containingWidth, so
     * layoutBlock()'s existing "auto width fills the available width" behavior reproduces
     * exactly that width as a side effect.
     *
     * Unlike normal children, a floated child does not participate in margin collapsing and
     * does not advance the flow cursor on its own; the caller folds the run's tallest bottom
     * back into the flow once a non-floated sibling (or the end of children) clears the run.
     *
     * This intentionally only covers the shape every real-world float in the motivating
     * corpus takes: a handful of block siblings floated side by side with only inline
     * (text/span) content, no explicit width, and no float wrapping inline text around them.
     * Floats with block children, explicit widths that do not fit the run, or that need
     * following inline content to reflow around them are unsupported and keep behaving as
     * before (i.e. this method is simply not reached for anything wrapping inline text
     * around a float, since that reflow is not implemented).
     *
     * @return array{0:LayoutNode,1:FloatRun}
     */
    private function layoutFloatChild(StyledNode $styled, string $side, FloatRun $float, float $runY, float $containingHeight, float $parentFontSize): array
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        $available = max(0.0, $float->rightX - $float->leftX);
        $margin = $this->resolveEdges($styled, 'margin', $available, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $available, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $available, $containingHeight, $fontSize);
        $horizontalNonContent = $margin->horizontal() + $padding->horizontal() + $border->horizontal();

        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = $this->shrinkToFitWidth($styled, max(0.0, $available - $horizontalNonContent), $fontSize);
        } else {
            $resolvedWidth = $this->resolveLength($widthValue, $available, $fontSize, $available, $containingHeight, 'zero');
            $contentWidth = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedWidth - $horizontalNonContent) : max(0.0, $resolvedWidth);
        }
        $contentWidth = $this->applyHorizontalConstraints($styled, $contentWidth, $horizontalNonContent, $available, $containingHeight, $fontSize);
        $marginBoxWidth = $contentWidth + $horizontalNonContent;

        $containingX = $side === 'left' ? $float->leftX : $float->rightX - $marginBoxWidth;
        $layout = $this->layoutBlock($styled, $containingX, $runY, $marginBoxWidth, $containingHeight, $parentFontSize);
        $bottom = $layout->box->borderBox()->bottom();
        $nextFloat = $side === 'left' ? $float->withLeft($float->leftX + $marginBoxWidth, $bottom, $runY) : $float->withRight($float->rightX - $marginBoxWidth, $bottom, $runY);

        return [$layout, $nextFloat];
    }

    /**
     * Shrink-to-fit width for a float with `width:auto`: the widest measured line of its own
     * inline content, capped at the available space. Block children inside a float are not
     * measured this way (they always fill $available, same as normal-flow auto width) since
     * no float in the motivating corpus has block children.
     */
    private function shrinkToFitWidth(StyledNode $styled, float $available, float $fontSize): float
    {
        if (!$this->hasInlineContent($styled)) return $available;
        $probe = $this->inlineTextFormatter->layout($styled, 0.0, 0.0, $available, $fontSize);
        $natural = 0.0;
        foreach ($probe->lines as $line) $natural = max($natural, $line->width);
        return min($natural, $available);
    }

    /**
     * Splits a block's children into flow order: each block-level child on its own, and each run
     * of consecutive inline-level children grouped into one anonymous inline segment, the way CSS
     * wraps them in anonymous block boxes.
     *
     * Before this, every block child was laid out in flow while ALL inline content was laid out
     * once starting at the content-box top, so any block that mixed the two painted its inline
     * content on top of its blocks instead of between them.
     *
     * Whitespace-only text is carried along inside a run (it separates inline items) but never
     * starts one on its own, so the blank text nodes that formatted HTML puts between block tags
     * do not produce empty lines.
     *
     * @return list<array{0:'inline'|'block',1:list<StyledNode>|StyledNode}>
     */
    private function flowSegments(StyledNode $node): array
    {
        $segments = [];
        $pending = [];
        $pendingHasContent = false;

        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                if ($pending === [] && trim($child->node->text ?? '') === '') continue;
                $pending[] = $child;
                $pendingHasContent = $pendingHasContent || trim($child->node->text ?? '') !== '';
                continue;
            }
            if ($this->display($child) === 'none') continue;

            if ($this->isBlockLevel($child)) {
                if ($pendingHasContent) $segments[] = ['inline', $pending];
                $pending = [];
                $pendingHasContent = false;
                $segments[] = ['block', $child];
                continue;
            }

            $pending[] = $child;
            $pendingHasContent = true;
        }
        if ($pendingHasContent) $segments[] = ['inline', $pending];

        return $segments;
    }

    /**
     * Top margin the first in-flow child contributes to its parent's own, or 0 when the block
     * opens with inline content (text between the edge and the first child stops the collapse).
     *
     * @param list<array{0:'inline'|'block',1:list<StyledNode>|StyledNode}> $segments
     */
    private function leadingChildTopMargin(array $segments, float $containingWidth, float $containingHeight, float $fontSize, int $depth = 0): float
    {
        $first = $segments[0] ?? null;
        if ($first === null || $first[0] !== 'block') return 0.0;

        $child = $first[1];
        if ($this->floatSide($child) !== null) return 0.0;

        return $this->collapsedTopMargin($child, $containingWidth, $containingHeight, $this->resolveFontSize($child, $fontSize), $depth + 1);
    }

    /**
     * A block's used top margin: its own, raised by the top margins of the leading descendants
     * that collapse into it (CSS 2.1 8.3.1). Walking the chain here — rather than reading it back
     * off the finished child box — is what lets the caller place that child without adding the
     * margin a second time. Percentage margins resolve against the parent's width instead of the
     * descendant's own containing block, which the absolute units in real documents never notice.
     */
    private function collapsedTopMargin(StyledNode $node, float $containingWidth, float $containingHeight, float $fontSize, int $depth = 0): float
    {
        $own = $this->resolveMarginSide($node, 'top', $containingWidth, $containingHeight, $fontSize);
        if ($depth >= 32) return $own;
        if ($node->node->isImage() || $node->node->isSvg()) return $own;
        if (!in_array($this->display($node), ['block', 'list-item'], true)) return $own;

        $padding = $this->resolveEdges($node, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($node, $containingWidth, $containingHeight, $fontSize);
        if ($padding->top > 0.0 || $border->top > 0.0) return $own;

        return max($own, $this->leadingChildTopMargin(
            $this->flowSegments($node),
            $containingWidth,
            $containingHeight,
            $fontSize,
            $depth,
        ));
    }

    private function hasInlineContent(StyledNode $node): bool
    {
        foreach ($node->children as $child) {
            if ($child->node->type === 'text' && trim($child->node->text ?? '') !== '') return true;
            if ($child->node->type === 'element' && !$this->isBlockLevel($child) && $this->display($child) !== 'none') return true;
        }
        return false;
    }

    private function display(StyledNode $node): string
    {
        if ($node->node->type === 'text') return 'inline';
        return strtolower($node->style->get('display', 'inline') ?? 'inline');
    }

    /**
     * `flex` and `grid` are treated as plain block for layout purposes: neither flexbox nor
     * grid is implemented yet (see README/PLAN.md), and without this fallback such an element
     * is excluded here (isBlockLevel() === false) while also matching hasInlineContent()'s
     * "non-block element" check, so it gets funneled into the inline formatter as if it were
     * inline content instead. That formatter has no notion of a `display:flex` box either, so
     * the element and everything inside it silently disappears from the rendered output
     * rather than falling back to *something* visible. Falling back to block at least
     * preserves the content and its children's own layout (e.g. their own `float`), even
     * though the flex/grid distribution itself is not honored.
     */
    private function isBlockLevel(StyledNode $node): bool
    {
        return in_array($this->display($node), ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell', 'flex', 'grid'], true);
    }

    private function resolveFontSize(StyledNode $node, float $parentFontSize): float
    {
        $value = $node->style->get('font-size');
        if ($value === null) return $parentFontSize;
        return max(0.0, $this->resolveLength($value, $parentFontSize, $parentFontSize, $this->viewportWidth, $this->viewportHeight, 'zero'));
    }

    private function resolveMarginSide(StyledNode $node, string $side, float $widthReference, float $heightReference, float $fontSize): float
    {
        [$top, $right, $bottom, $left] = $this->edgeRawValues($node, 'margin');
        $value = match ($side) { 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, default => '0' };
        return $this->resolveLength($value ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero');
    }

    private function resolveEdges(StyledNode $node, string $prefix, float $widthReference, float $heightReference, float $fontSize): Edges
    {
        [$top, $right, $bottom, $left] = $this->edgeRawValues($node, $prefix);
        return $this->resolveRawEdges($top, $right, $bottom, $left, $widthReference, $heightReference, $fontSize);
    }

    private function edgeRawValues(StyledNode $node, string $prefix): array
    {
        $shorthand = $node->style->get($prefix);
        $parts = $shorthand !== null ? preg_split('/\s+/', trim($shorthand)) ?: [] : [];
        [$top, $right, $bottom, $left] = $this->expandFour($parts);
        return [$node->style->get($prefix . '-top', $top), $node->style->get($prefix . '-right', $right), $node->style->get($prefix . '-bottom', $bottom), $node->style->get($prefix . '-left', $left)];
    }

    private function resolveRawEdges(?string $top, ?string $right, ?string $bottom, ?string $left, float $widthReference, float $heightReference, float $fontSize): Edges
    {
        return new Edges($this->resolveLength($top ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($right ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($bottom ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($left ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'));
    }

    private function resolveBorderEdges(StyledNode $node, float $widthReference, float $heightReference, float $fontSize): Edges
    {
        $shorthand = $node->style->get('border-width');
        $parts = $shorthand !== null ? preg_split('/\s+/', trim($shorthand)) ?: [] : [];
        [$top, $right, $bottom, $left] = $this->expandFour($parts);
        $raw = [
            'top' => $node->style->get('border-top-width', $top) ?? '0',
            'right' => $node->style->get('border-right-width', $right) ?? '0',
            'bottom' => $node->style->get('border-bottom-width', $bottom) ?? '0',
            'left' => $node->style->get('border-left-width', $left) ?? '0',
        ];
        $resolved = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (in_array($this->borderStyleForSide($node, $side), ['none', 'hidden'], true)) {
                $resolved[$side] = 0.0;
                continue;
            }
            $resolved[$side] = $this->resolveLength($raw[$side], $widthReference, $fontSize, $widthReference, $heightReference, 'zero');
        }
        return new Edges($resolved['top'], $resolved['right'], $resolved['bottom'], $resolved['left']);
    }

    private function borderStyleForSide(StyledNode $node, string $side): string
    {
        $specific = $node->style->get('border-' . $side . '-style');
        if ($specific !== null && trim($specific) !== '') return strtolower(trim($specific));
        $shorthand = trim($node->style->get('border-style', 'none') ?? 'none');
        $parts = preg_split('/\s+/', $shorthand) ?: ['none'];
        $expanded = $this->expandFour($parts);
        $index = array_search($side, ['top', 'right', 'bottom', 'left'], true);
        return strtolower($expanded[$index === false ? 0 : $index] ?? 'none');
    }

    private function expandFour(array $parts): array
    {
        return match (count($parts)) { 1 => [$parts[0], $parts[0], $parts[0], $parts[0]], 2 => [$parts[0], $parts[1], $parts[0], $parts[1]], 3 => [$parts[0], $parts[1], $parts[2], $parts[1]], default => [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null, $parts[3] ?? null] };
    }

    private function isAuto(string $value): bool { return strtolower(trim($value)) === 'auto'; }

    private function resolveLength(string $value, float $reference, float $fontSize, float $containerWidth, float $containerHeight, string $auto): float
    {
        return LengthResolver::resolve($this->lengthParser->parseLengthOrAuto($value), $reference, $fontSize, self::ROOT_FONT_SIZE, $containerWidth, $containerHeight, $auto);
    }

    private function applyHorizontalConstraints(StyledNode $node, float $contentWidth, float $nonContent, float $containingWidth, float $containingHeight, float $fontSize): float
    {
        foreach ([['min-width', true], ['max-width', false]] as [$property, $isMin]) {
            $value = $node->style->get($property);
            if ($value === null || strtolower(trim($value)) === 'none') continue;
            $resolved = $this->resolveLength($value, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            if (($node->style->get('box-sizing') ?? 'content-box') === 'border-box') $resolved = max(0.0, $resolved - $nonContent);
            $contentWidth = $isMin ? max($contentWidth, $resolved) : min($contentWidth, $resolved);
        }
        return max(0.0, $contentWidth);
    }

    private function applyVerticalConstraints(StyledNode $node, float $contentHeight, float $nonContent, float $containingWidth, float $containingHeight, float $fontSize): float
    {
        foreach ([['min-height', true], ['max-height', false]] as [$property, $isMin]) {
            $value = $node->style->get($property);
            if ($value === null || strtolower(trim($value)) === 'none') continue;
            $resolved = $this->resolveLength($value, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            if (($node->style->get('box-sizing') ?? 'content-box') === 'border-box') $resolved = max(0.0, $resolved - $nonContent);
            $contentHeight = $isMin ? max($contentHeight, $resolved) : min($contentHeight, $resolved);
        }
        return max(0.0, $contentHeight);
    }
}
