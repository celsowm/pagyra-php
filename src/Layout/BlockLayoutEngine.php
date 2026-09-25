<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\FontSizeKeywords;
use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Dom\Node;
use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Geometry\Edges;
use Pagyra\Geometry\Rect;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyleComputer;
use Pagyra\Style\StyledNode;

final class BlockLayoutEngine
{
    /**
     * Tag name given to the boxes this engine generates itself (CSS anonymous block and table
     * boxes). It cannot collide with a parsed element, so a layout tree can be told apart from
     * the markup that produced it.
     */
    public const ANONYMOUS_TAG = '#anonymous';

    private FloatExclusionContext $floatContext;

    private const ROOT_FONT_SIZE = 16.0;

    private readonly LengthParser $lengthParser;
    private readonly InlineTextFormatter $inlineTextFormatter;
    private readonly IntrinsicSizeResolver $intrinsicSizeResolver;
    /** @var array<int,bool> memoiza containsBlockLevelChild() por nó */
    private array $containsBlockCache = [];

    public function __construct(
        private readonly float $viewportWidth,
        private readonly float $viewportHeight,
        ?TextMetrics $textMetrics = null,
    ) {
        $this->lengthParser = new LengthParser($viewportWidth, $viewportHeight);
        $this->floatContext = new FloatExclusionContext();
        $metrics = $textMetrics ?? new HeuristicTextMetrics();
        $this->inlineTextFormatter = new InlineTextFormatter($metrics);
        $this->intrinsicSizeResolver = new IntrinsicSizeResolver($this->inlineTextFormatter, $viewportWidth, $viewportHeight);
        $this->inlineTextFormatter->setIntrinsicSizeHandler(
            fn(StyledNode $node, float $referenceWidth, float $fontSize): IntrinsicInlineSize
                => $this->intrinsicSizeResolver->measure($node, $referenceWidth, $fontSize),
        );
        $this->inlineTextFormatter->setBlockContentLayoutHandler(
            fn(StyledNode $node, float $contentWidth, float $fontSize): AtomicContentLayout
                => $this->layoutAtomicContent($node, $contentWidth, $fontSize),
        );
    }

    public function layout(StyledNode $root): LayoutNode
    {
        $viewport = new Rect(0.0, 0.0, $this->viewportWidth, $this->viewportHeight);
        return $this->applyOutOfFlowOffsets(
            $this->applyRelativeOffsets($this->layoutDocument($root), $this->viewportWidth, $this->viewportHeight),
            $viewport,
        );
    }

    /**
     * `position: relative` (CSS 2.1 §9.4.3): once the flow is laid out, a relatively positioned
     * box is moved by `left`/`top` (or minus `right`/`bottom`) without disturbing anything around
     * it. Running this after layout is what keeps the siblings where the unshifted box left them.
     * A block moves with its whole subtree; the text of a relatively positioned inline element
     * moves by itself. The offsets were ignored, so such content stayed at its flow position.
     */
    private function applyRelativeOffsets(LayoutNode $node, float $containingWidth, float $containingHeight): LayoutNode
    {
        $children = [];
        $changed = false;
        foreach ($node->children as $child) {
            $moved = $this->applyRelativeOffsets($child, $node->box->content->width, $node->box->content->height);
            $changed = $changed || $moved !== $child;
            $children[] = $moved;
        }
        $lines = [];
        foreach ($node->lineBoxes as $line) {
            $runs = [];
            $lineChanged = false;
            foreach ($line->runs as $run) {
                // Only the text of an inline element moves on its own; a run styled by a block
                // container is that block's own text and moves with the block below.
                $inline = strtolower(trim($run->style->get('display', 'inline') ?? 'inline')) === 'inline';
                [$dx, $dy] = $inline ? $this->relativeOffset($run->style, $node->box->content->width, $node->box->content->height, $run->fontSize) : [0.0, 0.0];
                if ($dx != 0.0 || $dy != 0.0) {
                    $run = new TextRun($run->x + $dx, $run->y + $dy, $run->width, $run->height, $run->baseline + $dy, $run->text, $run->fontSize, $run->style, $run->justificationWordSpacing, $run->inlineBackground, $run->inlineBorderColor, $run->inlineBorderWidth, $run->inlinePaddingLeft, $run->inlinePaddingRight, $run->inlineBorderStart, $run->inlineBorderEnd);
                    $lineChanged = true;
                }
                $runs[] = $run;
            }
            $lines[] = $lineChanged ? new LineBox($line->x, $line->y, $line->width, $line->height, $line->baseline, $line->text, $runs, $line->atomicBoxes) : $line;
            $changed = $changed || $lineChanged;
        }
        if ($changed) {
            $node = new LayoutNode($node->source, $node->box, $children, $node->fontSize, $lines);
        }
        [$dx, $dy] = $this->relativeOffset($node->source->style, $containingWidth, $containingHeight, $node->fontSize);

        return $this->translateNode($node, $dy, $dx);
    }

    /** @return array{0:float,1:float} */
    private function relativeOffset(ComputedStyle $style, float $containingWidth, float $containingHeight, float $fontSize): array
    {
        if (strtolower(trim($style->get('position') ?? 'static')) !== 'relative') {
            return [0.0, 0.0];
        }
        $resolve = function (string $side, float $reference) use ($style, $fontSize): ?float {
            $value = $style->get($side);
            return $value === null || $this->isAuto($value) ? null : $this->resolveLength($value, $reference, $fontSize, $reference, $this->viewportHeight, 'zero');
        };
        $left = $resolve('left', $containingWidth);
        $right = $resolve('right', $containingWidth);
        $top = $resolve('top', $containingHeight);
        $bottom = $resolve('bottom', $containingHeight);

        return [$left ?? ($right !== null ? -$right : 0.0), $top ?? ($bottom !== null ? -$bottom : 0.0)];
    }

    /**
     * `position: absolute`/`fixed` (CSS 2.1 §10.1, §9.6): once the flow (and `position: relative`)
     * has settled, each absolutely or fixed positioned box is moved to sit against its containing
     * block — the padding-relative content box of its nearest positioned ancestor (`relative`,
     * `absolute` or `fixed`), or the page for `fixed` and for `absolute` with no positioned
     * ancestor — placed by `left`/`top`/`right`/`bottom`. Like pagyra-js, the box is not removed
     * from the flow it was laid out in, so it still opens a gap in its siblings; and when both
     * insets on an axis are `auto` it is pinned to the containing block's own top-left corner
     * rather than kept at its flow position, which is the same simplification pagyra-js makes.
     * The property used to be ignored entirely, so a stamp or watermark positioned this way ended
     * up wherever its flow position happened to put it.
     */
    private function applyOutOfFlowOffsets(LayoutNode $node, Rect $ancestorBox): LayoutNode
    {
        $position = strtolower(trim($node->source->style->get('position') ?? 'static'));
        $isPositioned = in_array($position, ['relative', 'absolute', 'fixed'], true);
        if ($position === 'absolute' || $position === 'fixed') {
            $containingBlock = $position === 'fixed'
                ? new Rect(0.0, 0.0, $this->viewportWidth, $this->viewportHeight)
                : $ancestorBox;
            [$dx, $dy] = $this->outOfFlowOffset($node, $containingBlock);
            $node = $this->translateNode($node, $dy, $dx);
        }
        $childAncestorBox = $isPositioned ? $node->box->content : $ancestorBox;

        $children = [];
        $changed = false;
        foreach ($node->children as $child) {
            $moved = $this->applyOutOfFlowOffsets($child, $childAncestorBox);
            $changed = $changed || $moved !== $child;
            $children[] = $moved;
        }
        if ($changed) {
            $node = new LayoutNode($node->source, $node->box, $children, $node->fontSize, $node->lineBoxes);
        }

        return $node;
    }

    /** @return array{0:float,1:float} the delta to move the node's content-box origin by */
    private function outOfFlowOffset(LayoutNode $node, Rect $containingBlock): array
    {
        $style = $node->source->style;
        $fontSize = $node->fontSize;
        $resolve = function (string $side, float $reference) use ($style, $fontSize): ?float {
            $value = $style->get($side);
            return $value === null || $this->isAuto($value) ? null : $this->resolveLength($value, $reference, $fontSize, $reference, $this->viewportHeight, 'zero');
        };
        $left = $resolve('left', $containingBlock->width);
        $right = $resolve('right', $containingBlock->width);
        $top = $resolve('top', $containingBlock->height);
        $bottom = $resolve('bottom', $containingBlock->height);

        $marginBox = $node->box->marginBox();
        $marginX = $left !== null
            ? $containingBlock->x + $left
            : ($right !== null ? $containingBlock->x + $containingBlock->width - $marginBox->width - $right : $containingBlock->x);
        $marginY = $top !== null
            ? $containingBlock->y + $top
            : ($bottom !== null ? $containingBlock->y + $containingBlock->height - $marginBox->height - $bottom : $containingBlock->y);

        $box = $node->box;
        $contentX = $marginX + $box->margin->left + $box->border->left + $box->padding->left;
        $contentY = $marginY + $box->margin->top + $box->border->top + $box->padding->top;

        return [$contentX - $box->content->x, $contentY - $box->content->y];
    }

    /**
     * The root runs its children through the same flow segmentation the block path uses, so
     * inline content sitting directly at the top level lands in an anonymous block instead of
     * being skipped. It used to be skipped: the loop below only accepted block-level children,
     * so `<p>a</p><foobar>texto</foobar><p>b</p>` — any element the UA sheet does not know, and
     * these documents arrive full of them — lost "texto" without a word of warning.
     */
    private function layoutDocument(StyledNode $root): LayoutNode
    {
        $this->floatContext = new FloatExclusionContext();
        // The root carries the body's computed style (StyleComputer::computeTree), and the body is
        // a block box like any other: its margin (8px from the UA sheet), border and padding
        // inset the content, `width`/`max-width` narrow it and auto side margins centre it. The
        // reference lays the root out from that same body style (pagyra-js
        // `src/html-to-pdf/layout-build.ts`). The root node keeps the full viewport as its own
        // box, and only its children move, so the tree the pagination walks is unchanged.
        $fontSize = $this->resolveFontSize($root, self::ROOT_FONT_SIZE);
        [$marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw] = $this->edgeRawValues($root, 'margin');
        $margin = $this->resolveRawEdges($marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw, $this->viewportWidth, $this->viewportHeight, $fontSize);
        $padding = $this->resolveEdges($root, 'padding', $this->viewportWidth, $this->viewportHeight, $fontSize);
        $border = $this->resolveBorderEdges($root, $this->viewportWidth, $this->viewportHeight, $fontSize);
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $widthValue = $root->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = max(0.0, $this->viewportWidth - $margin->horizontal() - $horizontalNonContent);
        } else {
            $resolvedWidth = $this->resolveLength($widthValue, $this->viewportWidth, $fontSize, $this->viewportWidth, $this->viewportHeight, 'zero');
            $contentWidth = ($root->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedWidth - $horizontalNonContent) : max(0.0, $resolvedWidth);
        }
        $autoWidthBeforeConstraints = $contentWidth;
        $contentWidth = $this->applyHorizontalConstraints($root, $contentWidth, $horizontalNonContent, $this->viewportWidth, $this->viewportHeight, $fontSize);
        if ((!$this->isAuto($widthValue) || $contentWidth !== $autoWidthBeforeConstraints) && !$this->isOutOfFlow($root)) {
            $usedMargins = BlockMath::resolveAutoMargins($this->viewportWidth, $contentWidth + $horizontalNonContent, $margin->left, $margin->right, $this->isAuto($marginLeftRaw ?? '0'), $this->isAuto($marginRightRaw ?? '0'));
            $margin = new Edges($margin->top, $usedMargins['right'], $margin->bottom, $usedMargins['left']);
        }
        $contentX = $margin->left + $border->left + $padding->left;
        $contentRight = $contentX + $contentWidth;

        $segments = $this->flowSegments($root);
        $cursorY = $margin->top + $border->top + $padding->top;
        $collapsesTop = $border->top <= 0.0 && $padding->top <= 0.0;
        $first = $segments[0] ?? null;
        if ($collapsesTop && $first !== null && $first[0] === 'block' && $this->floatSide($first[1]) === null) {
            // CSS 2.1 8.3.1: the body's top margin collapses with its first child's (the 8px
            // and a paragraph's 16px give 16, not 24). The child adds its own used top margin
            // when it is laid out, so it starts where the collapsed margin minus that one ends.
            $leading = $this->leadingChildTopMargin($segments, $contentWidth, $this->viewportHeight, $fontSize);
            $cursorY = max($margin->top, $leading) - $leading;
        }

        $children = [];
        $previousBorderBottom = null;
        $previousBottomMargin = 0.0;
        $float = new FloatRun($contentX, $contentRight);

        foreach ($segments as $segment) {
            if ($segment[0] === 'inline') {
                if ($float->active) {
                    $this->excludeFloat($float);
                }
                $run = $this->inlineTextFormatter->layout(
                    new StyledNode($root->node, $root->style, $segment[1]),
                    $contentX,
                    $cursorY,
                    $contentWidth,
                    $fontSize,
                    $this->floatContext->exclusions(),
                );
                // Always an anonymous block here, never lines on the root itself: the pagination
                // walk starts at the root's children, so anything left on the root node was laid
                // out and then never painted. `<p>a</p>solto<p>b</p>` lost "solto" that way, and
                // an <img> or a bare text node directly under <body> went the same way — the
                // layout tree had it, the display list and the PDF did not.
                $children[] = $this->anonymousBlockOfLines($root, $run->lines, $contentX, $cursorY, $contentWidth, $run->height, $fontSize);
                $cursorY += $run->height;
                $previousBorderBottom = null;
                $previousBottomMargin = 0.0;
                continue;
            }

            $child = $segment[1];
            $childFontSize = $this->resolveFontSize($child, $fontSize);

            $side = $this->floatSide($child);
            if ($side !== null) {
                $runY = $float->active ? $float->startY : ($previousBorderBottom ?? $cursorY);
                [$layout, $float] = $this->layoutFloatChild($child, $side, $float, $runY, $this->viewportHeight, $childFontSize);
                $children[] = $layout;
                continue;
            }

            $clear = $this->clearSide($child);
            if ($clear !== null) {
                if ($float->active) $this->excludeFloat($float);
                $clearance = $this->floatContext->clearanceBottom($clear);
                if ($clearance > $cursorY) {
                    $cursorY = $clearance;
                    $previousBorderBottom = null;
                    $previousBottomMargin = 0.0;
                }
            }

            if ($float->active && $this->wrapsAroundFloats($child)) {
                $this->excludeFloat($float);
            } elseif ($float->active) {
                $cursorY = max($cursorY, $float->bottom);
                $previousBorderBottom = null;
                $previousBottomMargin = 0.0;
                $float = $float->reset($contentX, $contentRight);
            }

            $childTopMargin = $this->resolveMarginSide($child, 'top', $contentWidth, $this->viewportHeight, $childFontSize);
            $flowY = $previousBorderBottom === null ? $cursorY : $previousBorderBottom + BlockMath::collapseMarginSet([$previousBottomMargin, $childTopMargin]) - $childTopMargin;
            $layout = $this->layoutBlockLevelChild($child, $contentX, $flowY, $contentWidth, $this->viewportHeight, $fontSize);
            $children[] = $layout;
            $previousBorderBottom = $layout->box->borderBox()->bottom();
            $previousBottomMargin = $layout->box->margin->bottom;
            $cursorY = $previousBorderBottom + $previousBottomMargin;
        }
        if ($float->active) $cursorY = max($cursorY, $float->bottom);
        $collapsesBottom = $border->bottom <= 0.0 && $padding->bottom <= 0.0 && $previousBorderBottom !== null;
        $cursorY += $padding->bottom + $border->bottom
            + ($collapsesBottom ? max(0.0, $margin->bottom - $previousBottomMargin) : $margin->bottom);

        // No lineBoxes of its own: everything inline became an anonymous block child above.
        return new LayoutNode($root, new LayoutBox(new Rect(0.0, 0.0, $this->viewportWidth, max(0.0, $cursorY))), $children, $fontSize);
    }

    /**
     * @param bool $heightIsMinimum treats a specified `height` as a lower bound instead of a
     *        fixed value, which is how CSS defines it for table cells: "the height of a cell box
     *        is the minimum height required by the content". Without it, a `<td height="14pt">`
     *        holding a paragraph reports a 19px box while its content runs on for hundreds of
     *        pixels; the table then reports a height that does not cover its own content, and
     *        pagination drops everything past the first page because no fragment claims it.
     */
    /**
     * Lays out the inside of an atomic inline box through the real block engine. The wrapper's
     * own margin/padding/border/size belong to AtomicInlineBox, so a synthetic flow-root carries
     * only the children and inherited/text formatting state. This is the formatting-context
     * boundary that replaces InlineTextFormatter's former "skip block child" special case.
     */
    private function layoutAtomicContent(StyledNode $source, float $contentWidth, float $fontSize): AtomicContentLayout
    {
        $properties = $source->style->properties;
        $properties['display'] = 'flow-root';
        $properties['width'] = max(0.0, $contentWidth) . 'px';
        $properties['height'] = 'auto';
        $properties['font-size'] = $fontSize . 'px';
        $properties['box-sizing'] = 'content-box';
        $properties['margin'] = '0';
        $properties['padding'] = '0';
        $properties['border-width'] = '0';
        $properties['border-style'] = 'none';
        $properties['position'] = 'static';
        $properties['float'] = 'none';
        $properties['clear'] = 'none';

        foreach (array_keys($properties) as $property) {
            if (
                preg_match('/^(?:margin|padding)-(?:top|right|bottom|left)$/', $property) === 1
                || preg_match('/^border-(?:top|right|bottom|left)-(?:width|style|color)$/', $property) === 1
                || in_array($property, ['min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio', 'top', 'right', 'bottom', 'left', 'inset'], true)
            ) {
                unset($properties[$property]);
            }
        }

        $synthetic = new StyledNode(
            Node::element(self::ANONYMOUS_TAG, [], []),
            new ComputedStyle($properties),
            $source->children,
        );
        $layout = $this->layoutBlock(
            $synthetic,
            0.0,
            0.0,
            max(0.0, $contentWidth),
            $this->viewportHeight,
            $fontSize,
        );

        $baseline = $this->lastInFlowLineBaseline($layout);
        if ($baseline !== null) {
            $baseline -= $layout->box->content->y;
        }

        return new AtomicContentLayout(
            height: $layout->box->content->height,
            lines: $layout->lineBoxes,
            blocks: $layout->children,
            baseline: $baseline,
        );
    }

    /**
     * Baseline of the last genuine line box in normal flow. Replaced block boxes expose a
     * synthetic LineBox only so the paint pipeline can carry their AtomicInlineBox; that is not
     * an inline-block baseline. Floats and positioned descendants are outside normal flow too.
     */
    private function lastInFlowLineBaseline(LayoutNode $node): ?float
    {
        if ($node->source->node->isImage() || $node->source->node->isSvg()) {
            return null;
        }

        $position = strtolower(trim($node->source->style->get('position') ?? 'static'));
        $float = strtolower(trim($node->source->style->get('float') ?? 'none'));
        if (in_array($position, ['absolute', 'fixed'], true) || in_array($float, ['left', 'right'], true)) {
            return null;
        }

        $baseline = null;
        foreach ($node->lineBoxes as $line) {
            $baseline = $line->baseline;
        }
        foreach ($node->children as $child) {
            $candidate = $this->lastInFlowLineBaseline($child);
            if ($candidate !== null) {
                $baseline = $candidate;
            }
        }

        return $baseline;
    }

    private function layoutBlock(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize, bool $heightIsMinimum = false): LayoutNode
    {
        // Every descendant gets its own mutable float-context frame. Ordinary blocks inherit a
        // copy of the surrounding exclusions so their lines can wrap around outside floats;
        // boxes that establish a BFC start empty. Either way, floats created inside this block
        // stop affecting siblings once the block returns.
        $outerFloatContext = $this->floatContext;
        $this->floatContext = $this->wrapsAroundFloats($styled)
            ? $outerFloatContext->copy()
            : new FloatExclusionContext();
        try {
            return $this->layoutBlockContent($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize, $heightIsMinimum);
        } finally {
            $this->floatContext = $outerFloatContext;
        }
    }

    private function layoutBlockContent(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize, bool $heightIsMinimum): LayoutNode
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

        if (!$this->isAuto($widthValue) && !$this->isOutOfFlow($styled)) {
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
        // CSS 2.1 9.2.1.1: a block container holding both inline and block-level content wraps
        // each run of inline content in an anonymous block box. Without that the lines were all
        // appended to this node's own lineBoxes while the blocks went to its children, and the
        // paint walk emits every line of a node before any of its children — so the inline text
        // that belongs *after* a block came out of the PDF before it. The geometry was right, the
        // order of the drawing operations was not, which is what anyone copying text out of the
        // decision gets. A block whose content is all inline keeps its lines on itself, as before.
        $insideMarker = $this->insideListMarker($styled);
        $insideMarkerConsumed = false;

        // CSS Lists puts an inside marker in the list item's principal block box even when the
        // authored content begins with a block. In that shape there is no existing inline run to
        // prepend to, so create the anonymous inline segment CSS would generate before the first
        // block child. This replaces the old paint-only outside fallback for <li><p>...</p></li>.
        if ($insideMarker !== null) {
            $hasInlineSegment = false;
            foreach ($segments as $segment) {
                if ($segment[0] === 'inline') {
                    $hasInlineSegment = true;
                    break;
                }
            }
            if (!$hasInlineSegment) {
                array_unshift($segments, ['inline', [$this->listMarkerStyledNode($styled, $insideMarker)]]);
                $insideMarker = null;
                $insideMarkerConsumed = true;
            }
        }

        $wrapsInlineInAnonymousBlocks = $this->hasMixedFlow($segments);

        foreach ($segments as $segment) {
            if ($segment[0] === 'inline') {
                if ($float->active) {
                    $this->excludeFloat($float);
                }
                $inlineChildren = $segment[1];
                if ($insideMarker !== null) {
                    array_unshift($inlineChildren, $this->listMarkerStyledNode($styled, $insideMarker));
                    $insideMarker = null;
                    $insideMarkerConsumed = true;
                }
                $run = $this->inlineTextFormatter->layout(
                    new StyledNode($styled->node, $styled->style, $inlineChildren),
                    $contentX,
                    $cursorY,
                    $contentWidth,
                    $fontSize,
                    $this->floatContext->exclusions(),
                );
                if ($wrapsInlineInAnonymousBlocks) {
                    $children[] = $this->anonymousBlockOfLines($styled, $run->lines, $contentX, $cursorY, $contentWidth, $run->height, $fontSize);
                } else {
                    array_push($lineBoxes, ...$run->lines);
                }
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

            $clear = $this->clearSide($child);
            if ($clear !== null) {
                if ($float->active) $this->excludeFloat($float);
                $clearance = $this->floatContext->clearanceBottom($clear);
                if ($clearance > $cursorY) {
                    $cursorY = $clearance;
                    $previousBorderBottom = null;
                    $previousBottomMargin = 0.0;
                    $firstInFlowChild = false;
                }
            }

            if ($float->active && $this->wrapsAroundFloats($child)) {
                $this->excludeFloat($float);
                $firstInFlowChild = false;
            } elseif ($float->active) {
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
            // `aspect-ratio` (CSS Sizing 4 §7) gives an auto-height block the height its width
            // calls for; with the default `min-height: auto` the content can still make it
            // taller. It was unknown, so `<div style="width:100px; aspect-ratio: 2/1">` had no
            // height at all and its background never showed.
            $ratio = $this->aspectRatio($styled->style->get('aspect-ratio'));
            if ($ratio !== null) {
                $boxSizing = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box';
                $ratioHeight = $boxSizing
                    ? max(0.0, ($contentWidth + $horizontalNonContent) / $ratio - $padding->vertical() - $border->vertical())
                    : $contentWidth / $ratio;
                $contentHeight = max($contentHeight, $ratioHeight);
            }
        } else {
            $resolvedHeight = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentHeight = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedHeight - $verticalNonContent) : max(0.0, $resolvedHeight);
            if ($heightIsMinimum) $contentHeight = max($contentHeight, $autoContentHeight);
        }
        $contentHeight = $this->applyVerticalConstraints($styled, $contentHeight, $verticalNonContent, $containingWidth, $containingHeight, $fontSize);

        $returnedStyled = $insideMarkerConsumed ? $this->withoutListMarker($styled) : $styled;

        return new LayoutNode($returnedStyled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin), $children, $fontSize, $inlineLayout->lines);
    }

    private function layoutBlockLevelChild(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        if ($styled->node->isImage() || $styled->node->isSvg()) {
            return $this->layoutBlockReplaced($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize);
        }

        return match ($this->display($styled)) {
            'table' => $this->layoutTable($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize),
            'flex' => $this->layoutFlex($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize),
            'grid' => $this->layoutGrid($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize),
            default => $this->layoutBlock($styled, $containingX, $flowY, $containingWidth, $containingHeight, $parentFontSize),
        };
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
        if (!$this->isOutOfFlow($styled)) {
            $usedMargins = BlockMath::resolveAutoMargins(
                $containingWidth,
                $contentWidth + $horizontalNonContent,
                $margin->left,
                $margin->right,
                $this->isAuto($marginLeftRaw ?? '0'),
                $this->isAuto($marginRightRaw ?? '0'),
            );
            $margin = new Edges($margin->top, $usedMargins['right'], $margin->bottom, $usedMargins['left']);
        }

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;

        return $this->buildReplacedLayoutNode($styled, $contentX, $contentY, $contentWidth, $contentHeight, $padding, $border, $margin, $fontSize);
    }

    /**
     * The box a replaced element (an image or inline SVG) lays out to: a single line holding one
     * atomic box the size of its resolved content box, with no children of its own since a
     * replaced element's "content" is the image, not markup this engine lays out. Shared by
     * layoutBlockReplaced() and the float path (layoutFloatChild()), which both resolve their own
     * margin/padding/border and content size first — a float's come from its own shrink-to-fit
     * and float-edge placement rather than the ordinary block box model — and only need this last
     * step of turning the resolved geometry into a LayoutNode.
     */
    private function buildReplacedLayoutNode(StyledNode $styled, float $contentX, float $contentY, float $contentWidth, float $contentHeight, Edges $padding, Edges $border, Edges $margin, float $fontSize): LayoutNode
    {
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
     * no longer visually compresses the columns after it. `<col>`/`<colgroup>` width hints
     * participate in column sizing; repeated header/footer fragmentation semantics remain open.
     *
     * Column widths use the same min/max-content shape as pagyra-js's
     * TableLayoutStrategy::calculateColumnWidths(): intrinsic bounds are collected recursively
     * across each cell subtree, colspans split their requirement across the covered tracks,
     * preferred widths receive slack when they fit, and an overflowing preferred set is blended
     * down toward min-content before the emergency proportional shrink. A declared cell width
     * remains a preferred column constraint, preserving the HTML width-hint behavior used by the
     * motivating corpus.
     *
     * Row height for a rowspanning cell is reconciled the same incremental way: rows are laid
     * out top to bottom, and once a spanning cell's own row range has closed, whatever height
     * it still needs beyond what the non-spanning cells already gave those rows is added onto
     * the last row it covers.
     *
     * Every cell's box is then stretched down to the full height of the row (or row span) it
     * occupies, matching pagyra-js's unconditional `cell.box.borderBoxHeight = spanHeight`
     * (TableLayoutStrategy) so a short cell's borders/background still reach the row's bottom
     * edge next to a taller sibling; its content stays anchored at the top, since this port has
     * no vertical-align support for table cells yet.
     */
    private function layoutTable(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
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
            // A table narrower than its container is placed by its auto side margins, as any
            // block is (`margin: 0 auto`, and `<table align="center">` through its hint); they
            // resolved to zero here, so such a table always sat on the left.
            if (!$this->isOutOfFlow($styled)) {
                $usedMargins = BlockMath::resolveAutoMargins($containingWidth, $contentWidth + $horizontalNonContent, $margin->left, $margin->right, $this->isAuto($marginLeftRaw ?? '0'), $this->isAuto($marginRightRaw ?? '0'));
                $margin = new Edges($margin->top, $usedMargins['right'], $margin->bottom, $usedMargins['left']);
            }
        }
        // Captions sit in the table wrapper box, outside the table's border box and as wide as it
        // (CSS 2.1 17.4): the top ones above the grid, the `caption-side: bottom` ones below. They
        // were skipped outright, so a <caption> and all of its text never reached the page. With
        // captions present the table is laid out without its margins and handed back inside an
        // anonymous wrapper block that carries them, which is the box CSS describes; margin
        // collapsing around the table then works on the wrapper exactly as it did on the table.
        [$topCaptions, $bottomCaptions] = $this->tableCaptions($styled);
        $captionLayouts = [];
        $wrapperMargin = null;
        if ($topCaptions !== [] || $bottomCaptions !== []) {
            $wrapperMargin = $margin;
            $captionX = $containingX + $margin->left;
            $captionWidth = $contentWidth + $horizontalNonContent;
            $wrapperY = $flowY + $margin->top;
            foreach ($topCaptions as $caption) {
                $layout = $this->layoutBlock($caption, $captionX, $flowY + $margin->top, $captionWidth, $containingHeight, $fontSize);
                $captionLayouts[] = $layout;
                $flowY = $layout->box->marginBox()->bottom() - $margin->top;
            }
            $zero = new Edges(0.0, 0.0, 0.0, 0.0);
            $flowY += $margin->top;
            $containingX += $margin->left;
            $margin = $zero;
        }

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;

        $rows = $this->collectTableRows($styled);
        [$placements, $columnCount] = $this->buildTableGrid($rows);
        if ($columnCount === 0) {
            $table = new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, 0.0), $padding, $border, $margin), [], $fontSize);
            return $this->wrapWithCaptions($styled, $table, $wrapperMargin, $captionLayouts, $bottomCaptions, $containingHeight, $fontSize);
        }

        if ($this->isBorderCollapse($styled)) {
            $placements = $this->collapseCellBorders($placements, $contentWidth, $containingHeight, $fontSize);
        }

        $minColumnWidths = array_fill(0, $columnCount, 0.0);
        $maxColumnWidths = array_fill(0, $columnCount, 0.0);

        // CSS table-column/table-column-group widths are track constraints, not boxes that get
        // laid out on their own. StyleComputer already maps legacy <col width="..."> attributes
        // into computed width values; consume those tracks before cell intrinsic measurements.
        foreach ($this->tableColumnWidthHints($styled, $columnCount, $contentWidth, $containingHeight, $fontSize) as $column => $hint) {
            if ($hint === null) continue;
            $minColumnWidths[$column] = max($minColumnWidths[$column], $hint);
            $maxColumnWidths[$column] = max($maxColumnWidths[$column], $hint);
        }

        foreach ($placements as $p) {
            $cellFontSize = $this->resolveFontSize($p['cell'], $fontSize);
            // A cell that declares its own width states the column's preferred width; only a cell
            // that leaves it auto is sized from its subtree. This preserves the width-attribute
            // proportions used by old HTML while allowing auto columns to follow the reference's
            // min/max-content algorithm instead of a single shrink-to-fit probe.
            $declared = $this->declaredWidth($p['cell'], $contentWidth, $containingHeight, $cellFontSize);
            if ($declared !== null) {
                $cellMin = $declared;
                $cellMax = $declared;
            } else {
                $intrinsic = $this->intrinsicSizeResolver->measure($p['cell'], $contentWidth, $cellFontSize);
                $cellPadding = $this->resolveEdges($p['cell'], 'padding', $contentWidth, $containingHeight, $cellFontSize);
                $cellBorder = $this->resolveBorderEdges($p['cell'], $contentWidth, $containingHeight, $cellFontSize);
                $horizontalExtras = $cellPadding->horizontal() + $cellBorder->horizontal();
                $cellMin = $intrinsic->minContent + $horizontalExtras;
                $cellMax = max($cellMin, $intrinsic->maxContent + $horizontalExtras);
            }

            $minShare = $cellMin / $p['colSpan'];
            $maxShare = $cellMax / $p['colSpan'];
            for ($k = 0; $k < $p['colSpan']; $k++) {
                $column = $p['col'] + $k;
                $minColumnWidths[$column] = max($minColumnWidths[$column], $minShare);
                $maxColumnWidths[$column] = max($maxColumnWidths[$column], $maxShare);
            }
        }

        $totalMin = array_sum($minColumnWidths);
        $totalMax = array_sum($maxColumnWidths);
        if ($totalMax <= 0.0) {
            $columnWidths = array_fill(0, $columnCount, $contentWidth / $columnCount);
        } elseif ($totalMax <= $contentWidth) {
            $slack = $contentWidth - $totalMax;
            $columnWidths = array_map(
                static fn (float $w): float => $w + $slack * ($w / $totalMax),
                $maxColumnWidths,
            );
        } elseif ($totalMin <= $contentWidth) {
            $growthRoom = $totalMax - $totalMin;
            if ($growthRoom <= 0.0) {
                $columnWidths = $minColumnWidths;
            } else {
                $fraction = ($contentWidth - $totalMin) / $growthRoom;
                $columnWidths = array_map(
                    static fn (float $min, int $i): float => $min + ($maxColumnWidths[$i] - $min) * $fraction,
                    $minColumnWidths,
                    array_keys($minColumnWidths),
                );
            }
        } elseif ($totalMin > 0.0) {
            $scale = $contentWidth / $totalMin;
            $columnWidths = array_map(static fn (float $w): float => $w * $scale, $minColumnWidths);
        } else {
            $columnWidths = array_fill(0, $columnCount, $contentWidth / $columnCount);
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
                $cellLayout = $this->layoutBlock($this->withUsedWidth($p['cell'], $spanWidth), $columnX[$p['col']], $rowY[$r], $spanWidth, $containingHeight, $fontSize, heightIsMinimum: true);
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
                $spanHeight = $rowY[$r + $p['rowSpan']] - $rowY[$r];
                $box = $cellLayout->box;
                $extra = max(0.0, $spanHeight - $box->borderBox()->height);
                // CSS 2.1 17.5.3: a cell shorter than its row places its content by
                // `vertical-align` — `middle` is the UA default for td/th, and `valign` maps
                // here too. The content always sat at the top. Its lines and child blocks are
                // moved down by the offset; the cell's own box is untouched, and the stretch
                // below still makes it fill the row.
                // The free space is measured against what the content actually takes, not the
                // cell's box: a declared `height` makes the box taller than its content, and
                // that difference is exactly what `valign="bottom"` is about.
                $free = $spanHeight - $box->borderBox()->height + max(0.0, $box->content->height - $this->usedContentHeight($cellLayout));
                $shift = $free * match (strtolower(trim($p['cell']->style->get('vertical-align') ?? 'top'))) {
                    'middle' => 0.5,
                    'bottom' => 1.0,
                    default => 0.0,
                };
                if ($shift > 0.0) {
                    $cellLayout = new LayoutNode(
                        $cellLayout->source,
                        $box,
                        array_map(fn(LayoutNode $child): LayoutNode => $this->translateNode($child, $shift), $cellLayout->children),
                        $cellLayout->fontSize,
                        $this->inlineTextFormatter->translateLines($cellLayout->lineBoxes, 0.0, $shift),
                    );
                }
                if ($extra > 0.0) {
                    $stretched = new LayoutBox(new Rect($box->content->x, $box->content->y, $box->content->width, $box->content->height + $extra), $box->padding, $box->border, $box->margin);
                    $cellLayout = new LayoutNode($cellLayout->source, $stretched, $cellLayout->children, $cellLayout->fontSize, $cellLayout->lineBoxes);
                }
                $cellLayouts[] = $cellLayout;
            }
            $rowLayouts[] = new LayoutNode($tr, new LayoutBox(new Rect($contentX, $rowY[$r], $contentWidth, $rowHeights[$r])), $cellLayouts, $this->resolveFontSize($tr, $fontSize));
        }

        $table = new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $rowY[$rowCount] - $contentY), $padding, $border, $margin), $rowLayouts, $fontSize);

        return $this->wrapWithCaptions($styled, $table, $wrapperMargin, $captionLayouts, $bottomCaptions, $containingHeight, $fontSize);
    }

    /** The laid-out subtree moved down by $dy (and right by $dx). */
    private function translateNode(LayoutNode $node, float $dy, float $dx = 0.0): LayoutNode
    {
        if ($dx == 0.0 && $dy == 0.0) {
            return $node;
        }
        $box = $node->box;
        $content = $box->content;

        return new LayoutNode(
            $node->source,
            new LayoutBox(new Rect($content->x + $dx, $content->y + $dy, $content->width, $content->height), $box->padding, $box->border, $box->margin),
            array_map(fn(LayoutNode $child): LayoutNode => $this->translateNode($child, $dy, $dx), $node->children),
            $node->fontSize,
            $this->inlineTextFormatter->translateLines($node->lineBoxes, $dx, $dy),
        );
    }

    /** `aspect-ratio` as width / height, or null for `auto`, `none` or anything invalid. */
    private function aspectRatio(?string $value): ?float
    {
        if ($value === null || preg_match('/(\d*\.?\d+)\s*(?:\/\s*(\d*\.?\d+))?\s*$/', trim($value), $m) !== 1) {
            return null;
        }
        $width = (float) $m[1];
        $height = isset($m[2]) && $m[2] !== '' ? (float) $m[2] : 1.0;

        return $width > 0.0 && $height > 0.0 ? $width / $height : null;
    }

    /** How far below the content edge a laid-out box's lines and children actually reach. */
    private function usedContentHeight(LayoutNode $node): float
    {
        $top = $node->box->content->y;
        $bottom = $top;
        foreach ($node->lineBoxes as $line) {
            $bottom = max($bottom, $line->y + $line->height);
        }
        foreach ($node->children as $child) {
            $bottom = max($bottom, $child->box->marginBox()->bottom());
        }

        return $bottom - $top;
    }

    /**
     * Flexbox layout (CSS Flexible Box Layout 1), following the reference's FlexLayoutStrategy
     * (pagyra-js `src/layout/strategies/flex.ts` and `flex/*.ts`): items are measured at their
     * basis — `flex-basis`, else their size, else their preferred width in a row — broken into
     * lines when `flex-wrap` allows, grown by `flex-grow`, spaced by `justify-content` and `gap`,
     * and placed on the cross axis by `align-items`/`align-self`, with `align-content` spacing the
     * lines. Two things the reference leaves out are done here as browsers do them: `flex-shrink`
     * shrinks items that overflow a line, and `stretch` (the default) makes an item with an auto
     * cross size fill its line. Auto margins on the main axis take the free space first, `order`
     * reorders the items, and the `-reverse` directions mirror them.
     *
     * `display: flex` used to fall back to a plain block, so a row of items came out as a stack.
     */
    private function layoutFlex(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        [$marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw] = $this->edgeRawValues($styled, 'margin');
        $margin = $this->resolveRawEdges($marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw, $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $verticalNonContent = $padding->vertical() + $border->vertical();
        $borderBox = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box';

        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = max(0.0, $containingWidth - $margin->horizontal() - $horizontalNonContent);
        } else {
            $resolved = $this->resolveLength($widthValue, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentWidth = max(0.0, $borderBox ? $resolved - $horizontalNonContent : $resolved);
        }
        $contentWidth = $this->applyHorizontalConstraints($styled, $contentWidth, $horizontalNonContent, $containingWidth, $containingHeight, $fontSize);
        if (!$this->isAuto($widthValue) && !$this->isOutOfFlow($styled)) {
            $used = BlockMath::resolveAutoMargins($containingWidth, $contentWidth + $horizontalNonContent, $margin->left, $margin->right, $this->isAuto($marginLeftRaw ?? '0'), $this->isAuto($marginRightRaw ?? '0'));
            $margin = new Edges($margin->top, $used['right'], $margin->bottom, $used['left']);
        }
        $heightValue = $styled->style->get('height', 'auto') ?? 'auto';
        $definiteHeight = null;
        if (!$this->isAuto($heightValue) && !str_ends_with(trim($heightValue), '%')) {
            $resolved = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $definiteHeight = max(0.0, $borderBox ? $resolved - $verticalNonContent : $resolved);
        }

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;

        [$direction, $wrap] = $this->flexFlow($styled);
        $isRow = str_starts_with($direction, 'row');
        [$rowGap, $columnGap] = $this->flexGaps($styled, $contentWidth, $fontSize);
        $mainGap = $isRow ? $columnGap : $rowGap;
        $crossGap = $isRow ? $rowGap : $columnGap;
        $containerMain = $isRow ? $contentWidth : $definiteHeight;
        $alignItems = strtolower(trim($styled->style->get('align-items') ?? 'stretch'));

        // Items: every in-flow child element, blockified, plus runs of text wrapped in anonymous
        // items; `order` sorts them, stably.
        $children = [];
        $pendingText = [];
        $flushText = function () use (&$pendingText, &$children, $styled): void {
            $hasText = false;
            foreach ($pendingText as $text) {
                if (trim($text->node->text ?? '') !== '') $hasText = true;
            }
            if ($hasText) {
                $children[] = $this->anonymousFlexItem($styled, $pendingText);
            }
            $pendingText = [];
        };
        foreach ($styled->children as $child) {
            if ($child->node->type === 'text') {
                $pendingText[] = $child;
                continue;
            }
            if ($this->display($child) === 'none') continue;
            $flushText();
            $children[] = $this->blockifiedFlexItem($child);
        }
        $flushText();
        $orderIndex = array_keys($children);
        usort($orderIndex, fn(int $a, int $b): int => [(int) ($children[$a]->style->get('order') ?? 0), $a] <=> [(int) ($children[$b]->style->get('order') ?? 0), $b]);
        $children = array_map(static fn(int $i): StyledNode => $children[$i], $orderIndex);

        // Measure each item at its hypothetical main size.
        $items = [];
        foreach ($children as $child) {
            $childFont = $this->resolveFontSize($child, $fontSize);
            [$mt, $mr, $mb, $ml] = $this->edgeRawValues($child, 'margin');
            $childMargin = $this->resolveRawEdges($mt, $mr, $mb, $ml, $contentWidth, $containingHeight, $childFont);
            [$grow, $shrink, $basisRaw] = $this->flexFactors($child);
            $mainMarginStart = $isRow ? $childMargin->left : $childMargin->top;
            $mainMarginEnd = $isRow ? $childMargin->right : $childMargin->bottom;
            $autoStart = $this->isAuto(($isRow ? $ml : $mt) ?? '0');
            $autoEnd = $this->isAuto(($isRow ? $mr : $mb) ?? '0');
            $childPadding = $this->resolveEdges($child, 'padding', $contentWidth, $containingHeight, $childFont);
            $childBorder = $this->resolveBorderEdges($child, $contentWidth, $containingHeight, $childFont);
            $childBorderBox = ($child->style->get('box-sizing') ?? 'content-box') === 'border-box';
            $extrasMain = $isRow ? $childPadding->horizontal() + $childBorder->horizontal() : $childPadding->vertical() + $childBorder->vertical();

            $basis = null;
            if ($basisRaw !== null && !in_array(strtolower($basisRaw), ['auto', 'content'], true)) {
                $basis = $this->resolveLength($basisRaw, $isRow ? $contentWidth : ($definiteHeight ?? 0.0), $childFont, $contentWidth, $containingHeight, 'zero');
                $basis = max(0.0, $childBorderBox ? $basis : $basis + $extrasMain);
            }

            if ($isRow) {
                $width = $basis ?? $this->intrinsicPreferredWidth($child, max(0.0, $contentWidth - $childMargin->horizontal()), $childFont);
                $layout = $this->layoutFlexItem($child, $contentX, $contentY, $width, $childMargin, null, $containingHeight, $fontSize);
                $mainSize = $width;
                $crossSize = $layout->box->borderBox()->height;
            } else {
                $stretchCross = $this->flexAlignment($child, $alignItems) === 'stretch' && $this->isAuto($child->style->get('width', 'auto') ?? 'auto');
                $width = $stretchCross
                    ? max(0.0, $contentWidth - $childMargin->horizontal())
                    : $this->intrinsicPreferredWidth($child, max(0.0, $contentWidth - $childMargin->horizontal()), $childFont);
                $layout = $this->layoutFlexItem($child, $contentX, $contentY, $width, $childMargin, $basis, $containingHeight, $fontSize);
                $mainSize = $layout->box->borderBox()->height;
                $crossSize = $layout->box->borderBox()->width;
            }
            $items[] = [
                'styled' => $child, 'layout' => $layout, 'margin' => $childMargin,
                'grow' => $grow, 'shrink' => $shrink, 'main' => $mainSize, 'cross' => $crossSize,
                'mainMargins' => $mainMarginStart + $mainMarginEnd,
                'crossMargins' => $isRow ? $childMargin->vertical() : $childMargin->horizontal(),
                'autoStart' => $autoStart, 'autoEnd' => $autoEnd, 'width' => $width, 'basis' => $basis,
            ];
        }

        // Break into lines.
        $lines = [];
        $current = [];
        $used = 0.0;
        foreach ($items as $index => $item) {
            $contribution = $item['main'] + $item['mainMargins'];
            $addition = $current === [] ? $contribution : $mainGap + $contribution;
            if ($wrap !== 'nowrap' && $current !== [] && $containerMain !== null && $used + $addition > $containerMain + 0.01) {
                $lines[] = $current;
                $current = [];
                $used = 0.0;
                $addition = $contribution;
            }
            $current[] = $index;
            $used += $addition;
        }
        if ($current !== []) $lines[] = $current;

        // Resolve flexible lengths per line and re-lay out the items whose main size changed.
        foreach ($lines as $line) {
            if ($containerMain === null) break;
            $sum = 0.0;
            foreach ($line as $i) $sum += $items[$i]['main'] + $items[$i]['mainMargins'];
            $free = $containerMain - $sum - $mainGap * (count($line) - 1);
            $hasAutoMargin = false;
            foreach ($line as $i) $hasAutoMargin = $hasAutoMargin || $items[$i]['autoStart'] || $items[$i]['autoEnd'];
            if ($free > 0.0 && !$hasAutoMargin) {
                $totalGrow = array_sum(array_map(static fn(int $i): float => $items[$i]['grow'], $line));
                if ($totalGrow > 0.0) {
                    foreach ($line as $i) {
                        if ($items[$i]['grow'] > 0.0) {
                            $items[$i]['main'] += $free * $items[$i]['grow'] / $totalGrow;
                            $items[$i]['resize'] = true;
                        }
                    }
                }
            } elseif ($free < 0.0) {
                $totalShrink = array_sum(array_map(static fn(int $i): float => $items[$i]['shrink'] * $items[$i]['main'], $line));
                if ($totalShrink > 0.0) {
                    foreach ($line as $i) {
                        $share = $items[$i]['shrink'] * $items[$i]['main'] / $totalShrink;
                        if ($share > 0.0) {
                            $items[$i]['main'] = max(0.0, $items[$i]['main'] + $free * $share);
                            $items[$i]['resize'] = true;
                        }
                    }
                }
            }
            foreach ($line as $i) {
                if (!($items[$i]['resize'] ?? false)) continue;
                $item = $items[$i];
                if ($isRow) {
                    $items[$i]['width'] = $item['main'];
                    $items[$i]['layout'] = $this->layoutFlexItem($item['styled'], $contentX, $contentY, $item['main'], $item['margin'], null, $containingHeight, $fontSize);
                    $items[$i]['cross'] = $items[$i]['layout']->box->borderBox()->height;
                } else {
                    $items[$i]['layout'] = $this->layoutFlexItem($item['styled'], $contentX, $contentY, $item['width'], $item['margin'], $item['main'], $containingHeight, $fontSize, true);
                    $items[$i]['main'] = $items[$i]['layout']->box->borderBox()->height;
                }
            }
        }

        // Cross sizes of the lines and of the container.
        $lineCross = [];
        foreach ($lines as $l => $line) {
            $lineCross[$l] = 0.0;
            foreach ($line as $i) $lineCross[$l] = max($lineCross[$l], $items[$i]['cross'] + $items[$i]['crossMargins']);
        }
        $naturalCross = array_sum($lineCross) + $crossGap * max(0, count($lines) - 1);
        if ($isRow) {
            $containerCross = $definiteHeight ?? $naturalCross;
            if (count($lines) === 1 && $definiteHeight !== null) $lineCross[0] = max($lineCross[0], $definiteHeight);
        } else {
            $containerCross = $contentWidth;
            if (count($lines) === 1) $lineCross[0] = $contentWidth;
        }
        [$lineOffset, $lineSpacing] = $this->flexContentDistribution(
            strtolower(trim($styled->style->get('align-content') ?? 'normal')),
            max(0.0, $containerCross - $naturalCross),
            count($lines),
            $lineCross,
        );
        if ($wrap === 'wrap-reverse') {
            $lines = array_reverse($lines, true);
        }

        // Place the items.
        $justify = strtolower(trim($styled->style->get('justify-content') ?? 'flex-start'));
        $reverse = str_ends_with($direction, '-reverse');
        $mainExtent = $containerMain ?? 0.0;
        $placed = [];
        $crossCursor = $lineOffset;
        $maxMainEnd = 0.0;
        foreach ($lines as $l => $line) {
            $sum = 0.0;
            foreach ($line as $i) $sum += $items[$i]['main'] + $items[$i]['mainMargins'];
            $sum += $mainGap * (count($line) - 1);
            $free = $containerMain === null ? 0.0 : $containerMain - $sum;
            $autoCount = 0;
            foreach ($line as $i) $autoCount += ($items[$i]['autoStart'] ? 1 : 0) + ($items[$i]['autoEnd'] ? 1 : 0);
            $perAuto = $autoCount > 0 && $free > 0.0 ? $free / $autoCount : 0.0;
            [$offset, $between] = $autoCount > 0 && $free > 0.0 ? [0.0, 0.0] : $this->flexJustify($justify, $free, count($line));
            $cursor = $offset;
            foreach ($line as $position => $i) {
                $item = $items[$i];
                $start = ($isRow ? ($item['margin']->left) : ($item['margin']->top)) + ($item['autoStart'] ? $perAuto : 0.0);
                $end = ($isRow ? ($item['margin']->right) : ($item['margin']->bottom)) + ($item['autoEnd'] ? $perAuto : 0.0);
                $mainPos = $cursor + $start;
                $cursor += $start + $item['main'] + $end + ($position < count($line) - 1 ? $mainGap + $between : 0.0);
                $maxMainEnd = max($maxMainEnd, $cursor);

                $alignment = $this->flexAlignment($item['styled'], $alignItems);
                $crossMarginStart = $isRow ? $item['margin']->top : $item['margin']->left;
                $crossFree = $lineCross[$l] - $item['cross'] - $item['crossMargins'];
                $layout = $item['layout'];
                if ($alignment === 'stretch' && $crossFree > 0.0 && $this->isAuto($item['styled']->style->get($isRow ? 'height' : 'width', 'auto') ?? 'auto')) {
                    $layout = $isRow ? $this->stretchedHeight($layout, $crossFree) : $layout;
                    $crossFree = 0.0;
                }
                $crossPos = $crossCursor + $crossMarginStart + match ($alignment) {
                    'center' => max(0.0, $crossFree) / 2,
                    'flex-end', 'end', 'self-end' => max(0.0, $crossFree),
                    default => 0.0,
                };
                $itemBox = $layout->box->borderBox();
                if ($isRow) {
                    $x = $reverse ? $contentX + $mainExtent - $mainPos - $itemBox->width : $contentX + $mainPos;
                    $placed[] = $this->translateNode($layout, $contentY + $crossPos - $itemBox->y, $x - $itemBox->x);
                } else {
                    $y = $reverse && $containerMain !== null ? $contentY + $mainExtent - $mainPos - $itemBox->height : $contentY + $mainPos;
                    $placed[] = $this->translateNode($layout, $y - $itemBox->y, $contentX + $crossPos - $itemBox->x);
                }
            }
            $crossCursor += $lineCross[$l] + $crossGap + $lineSpacing;
        }

        $contentHeight = $isRow ? $containerCross : ($definiteHeight ?? $maxMainEnd);
        $contentHeight = $this->applyVerticalConstraints($styled, $contentHeight, $verticalNonContent, $containingWidth, $containingHeight, $fontSize);

        return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin), $placed, $fontSize);
    }

    /** @return array{0:string,1:string} direction and wrap, from the longhands or `flex-flow` */
    private function flexFlow(StyledNode $styled): array
    {
        $direction = strtolower(trim($styled->style->get('flex-direction') ?? ''));
        $wrap = strtolower(trim($styled->style->get('flex-wrap') ?? ''));
        foreach (preg_split('/\s+/', strtolower(trim($styled->style->get('flex-flow') ?? ''))) ?: [] as $token) {
            if ($direction === '' && in_array($token, ['row', 'row-reverse', 'column', 'column-reverse'], true)) $direction = $token;
            if ($wrap === '' && in_array($token, ['nowrap', 'wrap', 'wrap-reverse'], true)) $wrap = $token;
        }

        return [
            in_array($direction, ['row', 'row-reverse', 'column', 'column-reverse'], true) ? $direction : 'row',
            in_array($wrap, ['wrap', 'wrap-reverse'], true) ? $wrap : 'nowrap',
        ];
    }

    /** @return array{0:float,1:float} row-gap and column-gap, from the longhands or `gap` */
    private function flexGaps(StyledNode $styled, float $width, float $fontSize): array
    {
        $parts = preg_split('/\s+/', trim($styled->style->get('gap') ?? '')) ?: [];
        $row = $styled->style->get('row-gap') ?? (($parts[0] ?? '') !== '' ? $parts[0] : null) ?? $styled->style->get('grid-row-gap');
        $column = $styled->style->get('column-gap') ?? $parts[1] ?? (($parts[0] ?? '') !== '' ? $parts[0] : null) ?? $styled->style->get('grid-column-gap');
        $resolve = fn(?string $v): float => $v === null || strtolower(trim($v)) === 'normal' ? 0.0 : max(0.0, $this->resolveLength($v, $width, $fontSize, $width, $this->viewportHeight, 'zero'));

        return [$resolve($row), $resolve($column)];
    }

    /** @return array{0:float,1:float,2:?string} grow, shrink and basis, from the longhands or `flex` */
    private function flexFactors(StyledNode $styled): array
    {
        $grow = 0.0;
        $shrink = 1.0;
        $basis = null;
        $shorthand = strtolower(trim($styled->style->get('flex') ?? ''));
        if ($shorthand === 'none') {
            [$grow, $shrink, $basis] = [0.0, 0.0, 'auto'];
        } elseif ($shorthand === 'auto') {
            [$grow, $shrink, $basis] = [1.0, 1.0, 'auto'];
        } elseif ($shorthand !== '' && $shorthand !== 'initial') {
            $numbers = [];
            foreach (preg_split('/\s+/', $shorthand) ?: [] as $token) {
                if (is_numeric($token) && count($numbers) < 2) {
                    $numbers[] = (float) $token;
                } else {
                    $basis = $token;
                }
            }
            if ($numbers !== []) {
                $grow = $numbers[0];
                $shrink = $numbers[1] ?? 1.0;
                $basis ??= '0%';
            }
        }
        if (($value = $styled->style->get('flex-grow')) !== null && is_numeric(trim($value))) $grow = (float) $value;
        if (($value = $styled->style->get('flex-shrink')) !== null && is_numeric(trim($value))) $shrink = (float) $value;
        if (($value = $styled->style->get('flex-basis')) !== null) $basis = trim($value);
        if ($basis === null || strtolower($basis) === 'auto') {
            $basis = null;
        }

        return [max(0.0, $grow), max(0.0, $shrink), $basis];
    }

    private function flexAlignment(StyledNode $item, string $alignItems): string
    {
        $self = strtolower(trim($item->style->get('align-self') ?? 'auto'));
        $value = $self === 'auto' || $self === '' ? $alignItems : $self;

        return in_array($value, ['normal', 'stretch'], true) ? 'stretch' : $value;
    }

    /** @return array{0:float,1:float} initial offset and extra space between items */
    private function flexJustify(string $justify, float $free, int $count): array
    {
        $free = max(0.0, $free);

        return match ($justify) {
            'center' => [$free / 2, 0.0],
            'flex-end', 'end', 'right' => [$free, 0.0],
            'space-between' => $count > 1 ? [0.0, $free / ($count - 1)] : [0.0, 0.0],
            'space-around' => [$free / $count / 2, $free / $count],
            'space-evenly' => [$free / ($count + 1), $free / ($count + 1)],
            default => [0.0, 0.0],
        };
    }

    /**
     * `align-content` for the lines of a multi-line container: the initial offset and the extra
     * space between lines; `stretch` (the default) grows the lines themselves.
     *
     * @param list<float> $lineCross
     * @return array{0:float,1:float}
     */
    private function flexContentDistribution(string $alignContent, float $free, int $lines, array &$lineCross): array
    {
        if ($lines <= 1 || $free <= 0.0) return [0.0, 0.0];

        return match ($alignContent) {
            'flex-start', 'start' => [0.0, 0.0],
            'center' => [$free / 2, 0.0],
            'flex-end', 'end' => [$free, 0.0],
            'space-between' => [0.0, $free / ($lines - 1)],
            'space-around' => [$free / $lines / 2, $free / $lines],
            'space-evenly' => [$free / ($lines + 1), $free / ($lines + 1)],
            default => (function () use (&$lineCross, $free, $lines): array {
                foreach ($lineCross as $index => $size) $lineCross[$index] = $size + $free / $lines;
                return [0.0, 0.0];
            })(),
        };
    }

    /**
     * Lays out one flex item with the given border-box width, and optionally a border-box main
     * height (column containers), at the container's content origin; the caller moves it.
     */
    private function layoutFlexItem(StyledNode $item, float $x, float $y, float $borderBoxWidth, Edges $margin, ?float $borderBoxHeight, float $containingHeight, float $fontSize, bool $heightIsFixed = false): LayoutNode
    {
        $properties = $item->style->properties;
        $properties['width'] = max(0.0, $borderBoxWidth) . 'px';
        $properties['box-sizing'] = 'border-box';
        if ($borderBoxHeight !== null) {
            $properties['height'] = max(0.0, $borderBoxHeight) . 'px';
        }
        foreach (['margin-left', 'margin-right'] as $side) {
            if ($this->isAuto($properties[$side] ?? '0')) $properties[$side] = '0';
        }
        $sized = new StyledNode($item->node, new ComputedStyle($properties), $item->children);

        return $this->layoutBlockLevelChild($sized, $x, $y, $borderBoxWidth + $margin->horizontal(), $containingHeight, $fontSize);
    }

    /** A laid-out item whose border box is made taller by $extra, content staying at the top. */
    private function stretchedHeight(LayoutNode $node, float $extra): LayoutNode
    {
        $box = $node->box;

        return new LayoutNode(
            $node->source,
            new LayoutBox(new Rect($box->content->x, $box->content->y, $box->content->width, $box->content->height + $extra), $box->padding, $box->border, $box->margin),
            $node->children,
            $node->fontSize,
            $node->lineBoxes,
        );
    }

    /** A flex item's display, blockified (CSS Display 3 §2.7): inline-level becomes block-level. */
    private function blockifiedFlexItem(StyledNode $child): StyledNode
    {
        $display = $this->display($child);
        $blockified = match ($display) {
            'inline', 'inline-block', 'contents' => 'block',
            'inline-table' => 'table',
            'inline-flex' => 'flex',
            'inline-grid' => 'grid',
            default => $display,
        };
        $properties = $child->style->properties;
        if ($blockified !== $display) $properties['display'] = $blockified;
        // Floats do not apply to flex items.
        unset($properties['float']);

        return new StyledNode($child->node, new ComputedStyle($properties), $child->children);
    }

    /** @param list<StyledNode> $texts */
    private function anonymousFlexItem(StyledNode $container, array $texts): StyledNode
    {
        $properties = ['display' => 'block'];
        foreach (StyleComputer::INHERITED as $property) {
            $value = $container->style->get($property);
            if ($value !== null) $properties[$property] = $value;
        }

        return new StyledNode(Node::element(self::ANONYMOUS_TAG, [], []), new ComputedStyle($properties), $texts);
    }

    /**
     * The border-box width an item takes when nothing constrains it but the available space:
     * its declared width, else its max-content width (every line unbroken, every block child at
     * its own preferred width), capped by what is available.
     */
    private function intrinsicPreferredWidth(StyledNode $item, float $available, float $fontSize): float
    {
        $padding = $this->resolveEdges($item, 'padding', $available, $this->viewportHeight, $fontSize);
        $border = $this->resolveBorderEdges($item, $available, $this->viewportHeight, $fontSize);
        $extras = $padding->horizontal() + $border->horizontal();
        $width = $item->style->get('width', 'auto') ?? 'auto';

        // Preserve the established definite-width path: flex/grid still receive a border-box
        // preferred width and can subsequently flex/shrink it according to their own algorithms.
        if (!$this->isAuto($width) && !str_ends_with(trim($width), '%')) {
            $resolved = $this->resolveLength($width, $available, $fontSize, $available, $this->viewportHeight, 'zero');
            return ($item->style->get('box-sizing') ?? 'content-box') === 'border-box'
                ? $resolved
                : $resolved + $extras;
        }

        // Content-driven sizing is centralized here. The resolver sees nested block descendants,
        // declared descendant widths, atomic/replaced content and the same inline tokenization
        // used by real layout, so flex and grid no longer maintain their own recursive probes.
        $intrinsic = $this->intrinsicSizeResolver->borderBox($item, $available, $fontSize);
        $result = min($available, $intrinsic->maxContent);

        // Keep the pre-existing min-width behavior: an explicit minimum may force overflow beyond
        // the available slot, while ordinary min-content remains shrinkable by flex/grid.
        $min = $item->style->get('min-width');
        if ($min !== null && !$this->isAuto($min)) {
            $result = max(
                $result,
                $this->resolveLength($min, $available, $fontSize, $available, $this->viewportHeight, 'zero'),
            );
        }

        return max(0.0, $result);
    }

    /**
     * CSS Grid layout (CSS Grid 1), beyond the reference's row-by-row auto placement (pagyra-js
     * `src/layout/strategies/grid.ts`): column tracks from `grid-template-columns` in px, %,
     * `fr`, `auto`, `minmax()` and `repeat()` (with `auto-fill`/`auto-fit`), items placed by
     * `grid-column`/`grid-row`/`grid-area` line numbers and spans or else auto-placed in row
     * order, rows from `grid-template-rows` and `grid-auto-rows` or sized to their content, `gap`,
     * and `align-items`/`justify-items` with their `-self` overrides (`stretch` by default).
     * `auto` columns take their items' preferred widths, and `fr` columns share what is left.
     * `grid-template-areas` places a `grid-area: <name>` item at the name's cells (gridTemplateAreas()/
     * gridLine()); named lines from either property are not implemented.
     *
     * `display: grid` used to fall back to a plain block, stacking the cells.
     */
    private function layoutGrid(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        [$marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw] = $this->edgeRawValues($styled, 'margin');
        $margin = $this->resolveRawEdges($marginTopRaw, $marginRightRaw, $marginBottomRaw, $marginLeftRaw, $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $verticalNonContent = $padding->vertical() + $border->vertical();
        $borderBox = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box';
        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($widthValue)) {
            $contentWidth = max(0.0, $containingWidth - $margin->horizontal() - $horizontalNonContent);
        } else {
            $resolved = $this->resolveLength($widthValue, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentWidth = max(0.0, $borderBox ? $resolved - $horizontalNonContent : $resolved);
        }
        $contentWidth = $this->applyHorizontalConstraints($styled, $contentWidth, $horizontalNonContent, $containingWidth, $containingHeight, $fontSize);
        if (!$this->isAuto($widthValue) && !$this->isOutOfFlow($styled)) {
            $used = BlockMath::resolveAutoMargins($containingWidth, $contentWidth + $horizontalNonContent, $margin->left, $margin->right, $this->isAuto($marginLeftRaw ?? '0'), $this->isAuto($marginRightRaw ?? '0'));
            $margin = new Edges($margin->top, $used['right'], $margin->bottom, $used['left']);
        }
        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;
        [$rowGap, $columnGap] = $this->flexGaps($styled, $contentWidth, $fontSize);

        // Items: in-flow children, blockified; text runs become anonymous items.
        $children = [];
        $pendingText = [];
        $flush = function () use (&$pendingText, &$children, $styled): void {
            foreach ($pendingText as $text) {
                if (trim($text->node->text ?? '') !== '') {
                    $children[] = $this->anonymousFlexItem($styled, $pendingText);
                    break;
                }
            }
            $pendingText = [];
        };
        foreach ($styled->children as $child) {
            if ($child->node->type === 'text') { $pendingText[] = $child; continue; }
            if ($this->display($child) === 'none') continue;
            $flush();
            $children[] = $this->blockifiedFlexItem($child);
        }
        $flush();

        $columns = $this->gridTracks($styled->style->get('grid-template-columns') ?? 'none', $contentWidth, $columnGap, $fontSize);
        if ($columns === []) $columns = [['kind' => 'auto', 'size' => 0.0, 'min' => 0.0]];
        $areas = $this->gridTemplateAreas($styled->style->get('grid-template-areas'));

        // Placement: explicit positions first, then auto placement in row order, sparse.
        $placements = [];
        $occupied = [];
        $columnCount = count($columns);
        $pending = [];
        foreach ($children as $index => $child) {
            [$colStart, $colSpan] = $this->gridLine($child, 'column', $areas);
            [$rowStart, $rowSpan] = $this->gridLine($child, 'row', $areas);
            if ($colStart !== null) $columnCount = max($columnCount, $colStart + $colSpan);
            $placements[$index] = ['col' => $colStart, 'colSpan' => $colSpan, 'row' => $rowStart, 'rowSpan' => $rowSpan];
            if ($colStart === null || $rowStart === null) $pending[] = $index;
        }
        while (count($columns) < $columnCount) $columns[] = ['kind' => 'auto', 'size' => 0.0, 'min' => 0.0];
        $mark = function (int $row, int $col, int $rowSpan, int $colSpan) use (&$occupied): void {
            for ($r = $row; $r < $row + $rowSpan; $r++) for ($c = $col; $c < $col + $colSpan; $c++) $occupied[$r][$c] = true;
        };
        $free = function (int $row, int $col, int $rowSpan, int $colSpan) use (&$occupied, $columnCount): bool {
            if ($col + $colSpan > $columnCount) return false;
            for ($r = $row; $r < $row + $rowSpan; $r++) for ($c = $col; $c < $col + $colSpan; $c++) if (isset($occupied[$r][$c])) return false;
            return true;
        };
        foreach ($placements as $index => $p) {
            if ($p['col'] !== null && $p['row'] !== null) $mark($p['row'], $p['col'], $p['rowSpan'], $p['colSpan']);
        }
        $cursorRow = 0;
        $cursorCol = 0;
        foreach ($pending as $index) {
            $p = $placements[$index];
            $colSpan = min($p['colSpan'], $columnCount);
            if ($p['row'] !== null) {
                $col = 0;
                while (!$free($p['row'], $col, $p['rowSpan'], $colSpan)) $col++;
                $placements[$index]['col'] = $col;
                $placements[$index]['colSpan'] = $colSpan;
                $mark($p['row'], $col, $p['rowSpan'], $colSpan);
                continue;
            }
            $row = $cursorRow;
            $col = $p['col'] ?? $cursorCol;
            if ($p['col'] !== null && $col < $cursorCol) $row++;
            while (true) {
                if ($free($row, $col, $p['rowSpan'], $colSpan)) break;
                if ($p['col'] !== null) { $row++; continue; }
                $col++;
                if ($col + $colSpan > $columnCount) { $col = 0; $row++; }
            }
            $placements[$index] = ['col' => $col, 'colSpan' => $colSpan, 'row' => $row, 'rowSpan' => $p['rowSpan']];
            $mark($row, $col, $p['rowSpan'], $colSpan);
            $cursorRow = $row;
            $cursorCol = $col + $colSpan;
        }

        // Column sizes: fixed, then auto from the items' preferred widths, then fr.
        $widths = [];
        $available = $contentWidth - $columnGap * ($columnCount - 1);
        foreach ($columns as $c => $track) {
            $widths[$c] = $track['kind'] === 'fixed' ? $track['size'] : $track['min'];
        }
        foreach ($columns as $c => $track) {
            if ($track['kind'] !== 'auto') continue;
            foreach ($placements as $index => $p) {
                if ($p['col'] === $c && $p['colSpan'] === 1) {
                    $widths[$c] = max($widths[$c], $this->intrinsicPreferredWidth($children[$index], $available, $this->resolveFontSize($children[$index], $fontSize)));
                }
            }
        }
        $fr = array_sum(array_map(static fn(array $t): float => $t['kind'] === 'fr' ? $t['size'] : 0.0, $columns));
        $remaining = $available - array_sum($widths);
        if ($fr > 0.0) {
            // CSS Grid 1 §12.7.1 "find the size of an fr": share the leftover space by flex
            // factor, and freeze at its minimum any track whose share would fall below it.
            $flexible = array_keys(array_filter($columns, static fn(array $t): bool => $t['kind'] === 'fr'));
            $leftover = $available - array_sum(array_map(static fn(int $c): float => $widths[$c], array_diff(array_keys($widths), $flexible)));
            $frozen = [];
            do {
                $active = array_diff($flexible, $frozen);
                $factor = array_sum(array_map(static fn(int $c): float => $columns[$c]['size'], $active));
                $space = $leftover - array_sum(array_map(static fn(int $c): float => $columns[$c]['min'], $frozen));
                $unit = $factor > 0.0 ? max(0.0, $space) / $factor : 0.0;
                $changed = false;
                foreach ($active as $c) {
                    if ($columns[$c]['size'] * $unit < $columns[$c]['min']) { $frozen[] = $c; $changed = true; }
                }
            } while ($changed);
            foreach ($flexible as $c) {
                $widths[$c] = in_array($c, $frozen, true) ? $columns[$c]['min'] : $columns[$c]['size'] * $unit;
            }
        } elseif ($remaining > 0.0) {
            $autos = array_keys(array_filter($columns, static fn(array $t): bool => $t['kind'] === 'auto'));
            foreach ($autos as $c) $widths[$c] += $remaining / count($autos);
        }
        $columnX = [];
        $x = $contentX;
        foreach ($widths as $c => $w) { $columnX[$c] = $x; $x += $w + $columnGap; }

        // Lay out the items and size the rows.
        $rowTracks = $this->gridTracks($styled->style->get('grid-template-rows') ?? 'none', 0.0, $rowGap, $fontSize);
        $autoRow = $this->gridTracks($styled->style->get('grid-auto-rows') ?? 'auto', 0.0, $rowGap, $fontSize)[0] ?? ['kind' => 'auto', 'size' => 0.0, 'min' => 0.0];
        $rowCount = 0;
        foreach ($placements as $p) $rowCount = max($rowCount, $p['row'] + $p['rowSpan']);
        $heights = [];
        for ($r = 0; $r < $rowCount; $r++) {
            $track = $rowTracks[$r] ?? $autoRow;
            $heights[$r] = $track['kind'] === 'fixed' ? $track['size'] : $track['min'];
        }
        $layouts = [];
        $itemMargins = [];
        foreach ($placements as $index => $p) {
            $child = $children[$index];
            $childFont = $this->resolveFontSize($child, $fontSize);
            [$mt, $mr, $mb, $ml] = $this->edgeRawValues($child, 'margin');
            $itemMargins[$index] = $this->resolveRawEdges($mt, $mr, $mb, $ml, $contentWidth, $containingHeight, $childFont);
            $areaWidth = array_sum(array_slice($widths, $p['col'], $p['colSpan'])) + $columnGap * ($p['colSpan'] - 1);
            $justify = $this->gridAlignment($child, $styled, 'justify');
            $width = $justify === 'stretch' && $this->isAuto($child->style->get('width', 'auto') ?? 'auto')
                ? max(0.0, $areaWidth - $itemMargins[$index]->horizontal())
                : $this->intrinsicPreferredWidth($child, max(0.0, $areaWidth - $itemMargins[$index]->horizontal()), $childFont);
            $layouts[$index] = $this->layoutFlexItem($child, $contentX, $contentY, $width, $itemMargins[$index], null, $containingHeight, $fontSize);
            if ($p['rowSpan'] === 1 && ($rowTracks[$p['row']] ?? $autoRow)['kind'] !== 'fixed') {
                $heights[$p['row']] = max($heights[$p['row']], $layouts[$index]->box->borderBox()->height + $itemMargins[$index]->vertical());
            }
        }
        foreach ($placements as $index => $p) {
            if ($p['rowSpan'] === 1) continue;
            $need = $layouts[$index]->box->borderBox()->height + $itemMargins[$index]->vertical();
            $have = array_sum(array_slice($heights, $p['row'], $p['rowSpan'])) + $rowGap * ($p['rowSpan'] - 1);
            if ($need > $have) $heights[$p['row'] + $p['rowSpan'] - 1] += $need - $have;
        }
        $rowY = [];
        $y = $contentY;
        for ($r = 0; $r < $rowCount; $r++) { $rowY[$r] = $y; $y += $heights[$r] + ($r < $rowCount - 1 ? $rowGap : 0.0); }

        // Place the items in their areas.
        $placed = [];
        foreach ($placements as $index => $p) {
            $layout = $layouts[$index];
            $m = $itemMargins[$index];
            $areaWidth = array_sum(array_slice($widths, $p['col'], $p['colSpan'])) + $columnGap * ($p['colSpan'] - 1);
            $areaHeight = array_sum(array_slice($heights, $p['row'], $p['rowSpan'])) + $rowGap * ($p['rowSpan'] - 1);
            $box = $layout->box->borderBox();
            $freeY = $areaHeight - $box->height - $m->vertical();
            $align = $this->gridAlignment($children[$index], $styled, 'align');
            if ($align === 'stretch' && $freeY > 0.0 && $this->isAuto($children[$index]->style->get('height', 'auto') ?? 'auto')) {
                $layout = $this->stretchedHeight($layout, $freeY);
                $freeY = 0.0;
            }
            $freeX = $areaWidth - $box->width - $m->horizontal();
            $justify = $this->gridAlignment($children[$index], $styled, 'justify');
            $dx = $columnX[$p['col']] + $m->left + match ($justify) { 'center' => max(0.0, $freeX) / 2, 'end', 'flex-end', 'right' => max(0.0, $freeX), default => 0.0 };
            $dy = $rowY[$p['row']] + $m->top + match ($align) { 'center' => max(0.0, $freeY) / 2, 'end', 'flex-end' => max(0.0, $freeY), default => 0.0 };
            $placed[] = $this->translateNode($layout, $dy - $box->y, $dx - $box->x);
        }
        usort($placed, static fn(LayoutNode $a, LayoutNode $b): int => [$a->box->borderBox()->y, $a->box->borderBox()->x] <=> [$b->box->borderBox()->y, $b->box->borderBox()->x]);

        $contentHeight = $rowCount === 0 ? 0.0 : $y - $contentY;
        $heightValue = $styled->style->get('height', 'auto') ?? 'auto';
        if (!$this->isAuto($heightValue) && !str_ends_with(trim($heightValue), '%')) {
            $resolved = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentHeight = max(0.0, $borderBox ? $resolved - $verticalNonContent : $resolved);
        }
        $contentHeight = $this->applyVerticalConstraints($styled, $contentHeight, $verticalNonContent, $containingWidth, $containingHeight, $fontSize);

        return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin), $placed, $fontSize);
    }

    /**
     * A track list as `fixed` (px), `fr` (flex factor) and `auto` tracks, each with the minimum it
     * keeps. `minmax(min, max)` is a fixed track of `max`, an `fr` track with a minimum, or an
     * auto track with a minimum; `min-content`/`max-content`/`fit-content()` are auto.
     *
     * @return list<array{kind:string,size:float,min:float}>
     */
    private function gridTracks(string $value, float $reference, float $gap, float $fontSize): array
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['none', 'initial', 'inherit'], true)) return [];
        $tracks = [];
        foreach ($this->gridTokens($value) as $token) {
            $lower = strtolower($token);
            if (preg_match('/^repeat\(\s*([^,]+),(.*)\)$/s', $token, $m) === 1) {
                $inner = $this->gridTracks(trim($m[2]), $reference, $gap, $fontSize);
                $count = strtolower(trim($m[1]));
                if ($count === 'auto-fill' || $count === 'auto-fit') {
                    $size = array_sum(array_map(static fn(array $t): float => $t['kind'] === 'fixed' ? $t['size'] : $t['min'], $inner));
                    $n = $size > 0.0 && $reference > 0.0 ? max(1, (int) floor(($reference + $gap) / ($size + $gap * count($inner)))) : 1;
                } else {
                    $n = max(1, (int) $count);
                }
                for ($i = 0; $i < $n; $i++) array_push($tracks, ...$inner);
                continue;
            }
            $tracks[] = $this->gridTrack($lower, $reference, $fontSize);
        }

        return $tracks;
    }

    /** @return array{kind:string,size:float,min:float} */
    private function gridTrack(string $token, float $reference, float $fontSize): array
    {
        if (preg_match('/^minmax\((.*),(.*)\)$/s', $token, $m) === 1) {
            $min = $this->gridTrack(trim($m[1]), $reference, $fontSize);
            $max = $this->gridTrack(trim($m[2]), $reference, $fontSize);
            $floor = $min['kind'] === 'fixed' ? $min['size'] : 0.0;
            return match ($max['kind']) {
                'fr' => ['kind' => 'fr', 'size' => $max['size'], 'min' => $floor],
                'fixed' => ['kind' => 'fixed', 'size' => max($floor, $max['size']), 'min' => $floor],
                default => ['kind' => 'auto', 'size' => 0.0, 'min' => $floor],
            };
        }
        if (preg_match('/^(\d*\.?\d+)fr$/', $token, $m) === 1) return ['kind' => 'fr', 'size' => (float) $m[1], 'min' => 0.0];
        if (in_array($token, ['auto', 'min-content', 'max-content'], true) || str_starts_with($token, 'fit-content')) {
            return ['kind' => 'auto', 'size' => 0.0, 'min' => 0.0];
        }
        $size = max(0.0, $this->resolveLength($token, $reference, $fontSize, $reference, $this->viewportHeight, 'zero'));

        return ['kind' => 'fixed', 'size' => $size, 'min' => $size];
    }

    /** @return list<string> a track list split at top-level whitespace, line names dropped */
    private function gridTokens(string $value): array
    {
        $tokens = [];
        $buffer = '';
        $depth = 0;
        foreach (str_split($value) as $ch) {
            if ($ch === '(' ) $depth++;
            if ($ch === ')') $depth--;
            if ($ch === '[' && $depth === 0) { $depth += 100; continue; }
            if ($ch === ']' && $depth >= 100) { $depth -= 100; continue; }
            if ($depth >= 100) continue;
            if ($depth === 0 && ctype_space($ch)) {
                if ($buffer !== '') $tokens[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if ($buffer !== '') $tokens[] = $buffer;

        return $tokens;
    }

    /**
     * `grid-template-areas` (CSS Grid 1 §8.3): each quoted string is one row, tokenized on
     * whitespace into one name per column; `.` is an empty cell. A name spanning several rows
     * and/or columns is one rectangular run of matching cells — the only shape real stylesheets
     * give it, so this takes the bounding box of every cell that carries the name rather than
     * verifying every cell in between actually repeats it. Named lines (`header-start` etc.),
     * which this areas map would also imply, are not produced or matched anywhere.
     *
     * @return array<string,array{row:int,rowSpan:int,col:int,colSpan:int}>
     */
    private function gridTemplateAreas(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $matched = preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $value, $matches);
        if ($matched === false || $matched === 0) {
            return [];
        }
        $bounds = [];
        foreach ($matches[0] as $index => $whole) {
            $content = $matches[1][$index] !== '' ? $matches[1][$index] : $matches[2][$index];
            $tokens = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $col => $name) {
                if ($name === '.') continue;
                if (!isset($bounds[$name])) {
                    $bounds[$name] = ['minRow' => $index, 'maxRow' => $index, 'minCol' => $col, 'maxCol' => $col];
                    continue;
                }
                $bounds[$name]['minRow'] = min($bounds[$name]['minRow'], $index);
                $bounds[$name]['maxRow'] = max($bounds[$name]['maxRow'], $index);
                $bounds[$name]['minCol'] = min($bounds[$name]['minCol'], $col);
                $bounds[$name]['maxCol'] = max($bounds[$name]['maxCol'], $col);
            }
        }
        $areas = [];
        foreach ($bounds as $name => $b) {
            $areas[$name] = ['row' => $b['minRow'], 'rowSpan' => $b['maxRow'] - $b['minRow'] + 1, 'col' => $b['minCol'], 'colSpan' => $b['maxCol'] - $b['minCol'] + 1];
        }

        return $areas;
    }

    /**
     * An item's 0-based start line and span on one axis, from `grid-<axis>`, its `-start`/`-end`
     * longhands or `grid-area`; null start means auto-placed.
     *
     * @return array{0:?int,1:int}
     */
    /** @param array<string,array{row:int,rowSpan:int,col:int,colSpan:int}> $areas */
    private function gridLine(StyledNode $item, string $axis, array $areas = []): array
    {
        $start = $item->style->get('grid-' . $axis . '-start');
        $end = $item->style->get('grid-' . $axis . '-end');
        $shorthand = $item->style->get('grid-' . $axis);
        if ($shorthand !== null) {
            $parts = array_map('trim', explode('/', $shorthand, 2));
            $start ??= $parts[0];
            $end ??= $parts[1] ?? null;
        }
        $area = $item->style->get('grid-area');
        if ($area !== null) {
            $name = trim($area);
            // A bare identifier names a `grid-template-areas` cell (or run of cells) rather than
            // a numbered line: `grid-area: header` used to fall through to the line parser below,
            // which treats anything that is not `span N` or a bare integer as `auto` — so a named
            // area placed the item exactly where an unplaced one would have gone instead of where
            // its name says, silently discarding the association with `grid-template-areas`.
            if (!str_contains($name, '/') && isset($areas[$name])) {
                return $axis === 'row' ? [$areas[$name]['row'], $areas[$name]['rowSpan']] : [$areas[$name]['col'], $areas[$name]['colSpan']];
            }
            $parts = array_map('trim', explode('/', $area));
            $start ??= $parts[$axis === 'row' ? 0 : 1] ?? null;
            $end ??= $parts[$axis === 'row' ? 2 : 3] ?? null;
        }
        $parse = static function (?string $value): array {
            $value = strtolower(trim($value ?? 'auto'));
            if (preg_match('/^span\s+(\d+)$/', $value, $m) === 1) return ['span', max(1, (int) $m[1])];
            if (preg_match('/^-?\d+$/', $value) === 1 && (int) $value > 0) return ['line', (int) $value];
            return ['auto', 1];
        };
        [$startKind, $startValue] = $parse($start);
        [$endKind, $endValue] = $parse($end);
        if ($startKind === 'line') {
            $span = match ($endKind) {
                'line' => max(1, $endValue - $startValue),
                'span' => $endValue,
                default => 1,
            };
            return [$startValue - 1, $span];
        }
        if ($startKind === 'span') return [$endKind === 'line' ? max(0, $endValue - 1 - $startValue) : null, $startValue];
        if ($endKind === 'line') return [max(0, $endValue - 2), 1];

        return [null, $endKind === 'span' ? $endValue : 1];
    }

    private function gridAlignment(StyledNode $item, StyledNode $container, string $axis): string
    {
        $self = strtolower(trim($item->style->get($axis . '-self') ?? 'auto'));
        $value = $self === 'auto' || $self === '' ? strtolower(trim($container->style->get($axis . '-items') ?? 'stretch')) : $self;

        return in_array($value, ['normal', 'stretch', 'legacy'], true) ? 'stretch' : $value;
    }

    /** Registers a float run in the current block-formatting-context frame. */
    private function excludeFloat(FloatRun $float): void
    {
        $this->floatContext->register($float);
    }

    /**
     * Whether a block lets the lines inside it flow around outside floats: an ordinary block in
     * normal flow does; a box that establishes a formatting context of its own (table, flex,
     * grid, inline-block, overflow other than visible) does not. `clear` changes vertical
     * placement but does not itself create a new BFC, so opposite-side floats may still shorten
     * the cleared box's line boxes.
     */
    private function wrapsAroundFloats(StyledNode $node): bool
    {
        if ($node->node->isImage() || $node->node->isSvg()) return false;
        if (!in_array($this->display($node), ['block', 'list-item'], true)) return false;
        if ($this->floatSide($node) !== null) return false;
        $overflow = strtolower(trim($node->style->get('overflow') ?? 'visible'));

        return in_array($overflow, ['', 'visible', 'clip'], true);
    }

    /**
     * Width hints contributed by table-column/table-column-group boxes in source order.
     *
     * A <col span=N> applies its width to N consecutive columns. A <colgroup> containing explicit
     * <col> children takes its coverage from those children; an empty group uses its own span.
     * Group width acts as a fallback for child cols that do not declare one themselves.
     *
     * @return list<?float>
     */
    private function tableColumnWidthHints(
        StyledNode $table,
        int $columnCount,
        float $widthReference,
        float $heightReference,
        float $fontSize,
    ): array {
        $hints = array_fill(0, $columnCount, null);
        $cursor = 0;

        $apply = function (StyledNode $column, int $span, ?float $fallbackWidth = null) use (&$hints, &$cursor, $columnCount, $widthReference, $heightReference, $fontSize): void {
            if ($cursor >= $columnCount) return;
            $columnFont = $this->resolveFontSize($column, $fontSize);
            $width = $this->declaredWidth($column, $widthReference, $heightReference, $columnFont) ?? $fallbackWidth;
            $span = max(1, min($span, $columnCount - $cursor));
            for ($i = 0; $i < $span; $i++, $cursor++) {
                if ($width !== null) {
                    $hints[$cursor] = max($hints[$cursor] ?? 0.0, $width);
                }
            }
        };

        foreach ($table->children as $child) {
            $display = $this->display($child);
            if ($display === 'table-column') {
                $span = $this->positiveSpanAttribute($child, 'span');
                $apply($child, $span);
                continue;
            }
            if ($display !== 'table-column-group') continue;

            $groupFont = $this->resolveFontSize($child, $fontSize);
            $groupWidth = $this->declaredWidth($child, $widthReference, $heightReference, $groupFont);
            $columns = array_values(array_filter(
                $child->children,
                fn(StyledNode $col): bool => $this->display($col) === 'table-column',
            ));
            if ($columns === []) {
                $span = $this->positiveSpanAttribute($child, 'span');
                $apply($child, $span, $groupWidth);
                continue;
            }
            foreach ($columns as $column) {
                $span = $this->positiveSpanAttribute($column, 'span');
                $apply($column, $span, $groupWidth);
            }
        }

        return $hints;
    }

    private function positiveSpanAttribute(StyledNode $node, string $name): int
    {
        $raw = trim($node->node->attribute($name) ?? '');
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) return 1;
        return max(1, (int) $raw);
    }

    /**
     * The table's captions, split by `caption-side` (`top` unless it says `bottom`).
     *
     * @return array{0:list<StyledNode>,1:list<StyledNode>}
     */
    private function tableCaptions(StyledNode $table): array
    {
        $top = [];
        $bottom = [];
        foreach ($table->children as $child) {
            if ($child->node->type !== 'element' || $this->display($child) !== 'table-caption') {
                continue;
            }
            if (strtolower(trim($child->style->get('caption-side') ?? 'top')) === 'bottom') {
                $bottom[] = $child;
            } else {
                $top[] = $child;
            }
        }

        return [$top, $bottom];
    }

    /**
     * The table wrapper box around a table that has captions: an anonymous block carrying the
     * table's margins, holding the top captions, the table and the bottom captions laid out
     * below it. A table without captions is returned as is.
     *
     * @param list<LayoutNode> $topCaptionLayouts
     * @param list<StyledNode> $bottomCaptions
     */
    private function wrapWithCaptions(StyledNode $styled, LayoutNode $table, ?Edges $margin, array $topCaptionLayouts, array $bottomCaptions, float $containingHeight, float $fontSize): LayoutNode
    {
        if ($margin === null) {
            return $table;
        }
        $border = $table->box->borderBox();
        $x = $border->x;
        $width = $border->width;
        $top = $topCaptionLayouts === [] ? $border->y : $topCaptionLayouts[0]->box->marginBox()->y;
        $children = $topCaptionLayouts;
        $children[] = $table;
        $cursor = $border->bottom();
        foreach ($bottomCaptions as $caption) {
            $layout = $this->layoutBlock($caption, $x, $cursor, $width, $containingHeight, $fontSize);
            $children[] = $layout;
            $cursor = $layout->box->marginBox()->bottom();
        }

        $properties = ['display' => 'block'];
        foreach (StyleComputer::INHERITED as $property) {
            $value = $styled->style->get($property);
            if ($value !== null) $properties[$property] = $value;
        }
        $wrapper = new StyledNode(Node::element(self::ANONYMOUS_TAG, [], []), new ComputedStyle($properties));
        $zero = new Edges(0.0, 0.0, 0.0, 0.0);

        return new LayoutNode($wrapper, new LayoutBox(new Rect($x, $top, $width, $cursor - $top), $zero, $zero, $margin), $children, $fontSize);
    }

    /**
     * Whether this block container mixes inline and block-level content, the condition CSS 2.1
     * 9.2.1.1 puts on generating anonymous block boxes.
     *
     * @param list<array{0:'inline'|'block',1:list<StyledNode>|StyledNode}> $segments
     */
    private function hasMixedFlow(array $segments): bool
    {
        $hasInline = false;
        $hasBlock = false;
        foreach ($segments as $segment) {
            if ($segment[0] === 'inline') $hasInline = true;
            else $hasBlock = true;
            if ($hasInline && $hasBlock) return true;
        }

        return false;
    }

    /**
     * An anonymous block box holding one run of inline lines. It carries only the inheritable
     * properties of the block that generated it, so it never repeats that block's border,
     * padding or background around content it is merely rehoming, and its own box has no edges
     * of its own — the lines were already positioned by the inline formatter.
     *
     * @param list<LineBox> $lines
     */
    private function anonymousBlockOfLines(StyledNode $source, array $lines, float $x, float $y, float $width, float $height, float $fontSize): LayoutNode
    {
        $properties = ['display' => 'block'];
        foreach (StyleComputer::INHERITED as $property) {
            $value = $source->style->get($property);
            if ($value !== null) $properties[$property] = $value;
        }
        $styled = new StyledNode(Node::element(self::ANONYMOUS_TAG, [], []), new ComputedStyle($properties));
        $zero = new Edges(0.0, 0.0, 0.0, 0.0);

        return new LayoutNode($styled, new LayoutBox(new Rect($x, $y, $width, $height), $zero, $zero, $zero), [], $fontSize, $lines);
    }

    /**
     * The rows of a table, read by computed `display` and no longer by tag name, with anonymous
     * table boxes generated around anything that is not a row (CSS 2.1 17.2.1).
     *
     * Both halves of this were losing content. Matching `<tr>`/`<tbody>` by tag name meant a row
     * written as `<div style="display:table-row">` was skipped, and the reference reads its rows
     * off `child.style.display` (pagyra-js `src/layout/strategies/table.ts`), so that half was a
     * plain divergence. The other half is that any child which is neither a row nor a row group
     * was dropped outright: with no rows left the caller sees `columnCount === 0` and returns an
     * empty box, so the whole subtree vanished from the PDF without an error.
     *
     * That is not a corner case in these documents. The CKEditor stylesheet the eproc and the JFRJ
     * embed carries `.table { display: table }` and the markup is `<figure class="table"><table>`,
     * so the real `<table>` becomes a non-row child of an outer table box — and three corpus
     * documents lost a whole table that way, one of them the table of levels of scientific
     * evidence that a decision is reasoned on. `<table><p>x</p></table>` and a bare text child had
     * the same fate.
     *
     * Anonymous generation is where this goes past the reference, which drops those children too
     * (AGENTS.md item 5): a run of consecutive non-row children is wrapped in one anonymous row
     * holding one anonymous cell, which is what the spec asks for and what a browser shows.
     * Whitespace-only text between rows generates nothing, so ordinary indented markup does not
     * grow an empty row.
     *
     * @return list<StyledNode>
     */
    private function collectTableRows(StyledNode $table, string $section = 'body'): array
    {
        $rows = [];
        $pending = [];

        $flushPending = function () use (&$pending, &$rows, $table, $section): void {
            if ($pending === []) return;
            $cell = $this->anonymousTableBox($table, 'table-cell', $pending);
            $rows[] = $this->withTableSection($this->anonymousTableBox($table, 'table-row', [$cell]), $section);
            $pending = [];
        };

        foreach ($table->children as $child) {
            $display = $this->display($child);
            if ($display === 'none') continue;

            if ($display === 'table-row') {
                $flushPending();
                $rows[] = $this->withTableSection($child, $section);
                continue;
            }
            if (in_array($display, ['table-row-group', 'table-header-group', 'table-footer-group'], true)) {
                $flushPending();
                $groupSection = match ($display) {
                    'table-header-group' => 'header',
                    'table-footer-group' => 'footer',
                    default => 'body',
                };
                array_push($rows, ...$this->collectTableRows($child, $groupSection));
                continue;
            }
            // Column boxes and captions are not rows and generate no anonymous ones either; the
            // port has no layout for them yet, but swallowing them silently is right, unlike
            // swallowing content.
            if (in_array($display, ['table-column', 'table-column-group', 'table-caption'], true)) continue;
            if ($child->node->type === 'text' && trim($child->node->text ?? '') === '') continue;

            $pending[] = $child;
        }
        $flushPending();

        return $rows;
    }

    private function withTableSection(StyledNode $row, string $section): StyledNode
    {
        $properties = $row->style->properties;
        $properties['x-table-section'] = $section;

        return new StyledNode($row->node, new ComputedStyle($properties), $row->children);
    }

    /**
     * The cells of a row, by computed `display`, wrapping any run of non-cell children in one
     * anonymous cell for the same reason rows are wrapped above.
     *
     * @return list<StyledNode>
     */
    private function collectTableCells(StyledNode $row): array
    {
        $cells = [];
        $pending = [];

        $flushPending = function () use (&$pending, &$cells, $row): void {
            if ($pending === []) return;
            $cells[] = $this->anonymousTableBox($row, 'table-cell', $pending);
            $pending = [];
        };

        foreach ($row->children as $child) {
            $display = $this->display($child);
            if ($display === 'none') continue;

            if ($display === 'table-cell') {
                $flushPending();
                $cells[] = $child;
                continue;
            }
            if ($child->node->type === 'text' && trim($child->node->text ?? '') === '') continue;

            $pending[] = $child;
        }
        $flushPending();

        return $cells;
    }

    /**
     * An anonymous table box: it carries only the inheritable properties of the element that
     * generated it plus the `display` it was generated as, so it never picks up that element's
     * border, padding or background — which would double the table's own frame around content
     * that is merely being rehomed.
     *
     * @param list<StyledNode> $children
     */
    private function anonymousTableBox(StyledNode $source, string $display, array $children): StyledNode
    {
        $properties = ['display' => $display];
        foreach (StyleComputer::INHERITED as $property) {
            $value = $source->style->get($property);
            if ($value !== null) $properties[$property] = $value;
        }

        return new StyledNode(Node::element(self::ANONYMOUS_TAG, [], []), new ComputedStyle($properties), $children);
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

    /**
     * Pins a cell to the width its column ended up with. A `width` on a table cell states the
     * column's preferred width, not the cell's final one: once the columns have been sized (and
     * the leftover space handed out), the cell fills its column. Without this the cell kept
     * drawing at its own declared width inside a wider column, leaving an unpainted gap between
     * the columns — visible the moment `<td width="378">` started being honored at all.
     * `box-sizing: border-box` comes along so the border box is exactly the column, padding and
     * border included.
     */
    private function withUsedWidth(StyledNode $cell, float $width): StyledNode
    {
        return $this->withUsedBorderBoxWidth($cell, $width);
    }

    /** Pins an already-resolved box to a used border-box width so percentages are not resolved twice. */
    private function withUsedBorderBoxWidth(StyledNode $node, float $width): StyledNode
    {
        $properties = $node->style->properties;
        $properties['width'] = $width . 'px';
        $properties['box-sizing'] = 'border-box';

        return new StyledNode($node->node, new ComputedStyle($properties), $node->children);
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


    private function clearSide(StyledNode $node): ?string
    {
        $value = strtolower(trim($node->style->get('clear', 'none') ?? 'none'));
        return in_array($value, ['left', 'right', 'both'], true) ? $value : null;
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
     * Auto widths use recursive min/max intrinsic sizing, so block descendants participate.
     * Registered float bands shorten following line boxes, while clear:left/right/both consult
     * side-specific bottoms in the current Block Formatting Context. The remaining limitation is
     * full CSS float-placement retry when another float cannot fit the current horizontal slot.
     *
     * @return array{0:LayoutNode,1:FloatRun}
     */
    private function layoutFloatChild(StyledNode $styled, string $side, FloatRun $float, float $runY, float $containingHeight, float $parentFontSize): array
    {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        // Width resolution belongs to the containing block, not to whatever narrow slot the
        // preceding floats happened to leave on the current row. Placement decides afterward
        // whether that resolved margin box still fits beside them.
        $containingAvailable = $float->containingWidth();
        $slotAvailable = $float->availableWidth();
        $margin = $this->resolveEdges($styled, 'margin', $containingAvailable, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingAvailable, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingAvailable, $containingHeight, $fontSize);
        $horizontalNonContent = $margin->horizontal() + $padding->horizontal() + $border->horizontal();

        $isReplaced = $styled->node->isImage() || $styled->node->isSvg();
        if ($isReplaced) {
            // An <img align="left"> has no markup content for the ordinary block box model to
            // measure, so width:auto and height:auto (below) resolved to the full available
            // width and zero height instead of the image's own intrinsic size.
            [$contentWidth, $contentHeight] = $this->inlineTextFormatter->replacedContentSize($styled, $containingAvailable, $fontSize);
        } else {
            $widthValue = $styled->style->get('width', 'auto') ?? 'auto';
            if ($this->isAuto($widthValue)) {
                $contentWidth = $this->shrinkToFitWidth($styled, max(0.0, $containingAvailable - $horizontalNonContent), $fontSize);
            } else {
                $resolvedWidth = $this->resolveLength($widthValue, $containingAvailable, $fontSize, $containingAvailable, $containingHeight, 'zero');
                $contentWidth = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedWidth - $horizontalNonContent) : max(0.0, $resolvedWidth);
            }
            $contentWidth = $this->applyHorizontalConstraints($styled, $contentWidth, $horizontalNonContent, $containingAvailable, $containingHeight, $fontSize);
        }
        $marginBoxWidth = $contentWidth + $horizontalNonContent;

        // CSS 2.1 float placement retries lower when the current row has insufficient horizontal
        // room. Register the finished row before resetting its left/right cursors, so following
        // line boxes and clear still see it through the BFC context.
        if ($float->active && $marginBoxWidth > $slotAvailable + 0.01) {
            $this->excludeFloat($float);
            $runY = max($runY, $float->bottom);
            $float = $float->nextRow();
        }

        $containingX = $side === 'left' ? $float->leftX : $float->rightX - $marginBoxWidth;
        if ($isReplaced) {
            $contentX = $containingX + $margin->left + $border->left + $padding->left;
            $contentY = $runY + $margin->top + $border->top + $padding->top;
            $layout = $this->buildReplacedLayoutNode($styled, $contentX, $contentY, $contentWidth, $contentHeight, $padding, $border, $margin, $fontSize);
        } else {
            $usedBorderBoxWidth = $contentWidth + $padding->horizontal() + $border->horizontal();
            $layout = $this->layoutBlock(
                $this->withUsedBorderBoxWidth($styled, $usedBorderBoxWidth),
                $containingX,
                $runY,
                $marginBoxWidth,
                $containingHeight,
                $parentFontSize,
            );
        }
        $bottom = $layout->box->borderBox()->bottom();
        $nextFloat = $side === 'left' ? $float->withLeft($float->leftX + $marginBoxWidth, $bottom, $runY) : $float->withRight($float->rightX - $marginBoxWidth, $bottom, $runY);

        return [$layout, $nextFloat];
    }

    /**
     * Shrink-to-fit width for an auto-width float. Uses recursive min/max intrinsic sizes so
     * block descendants and replaced content participate instead of forcing the float to fill all
     * available space.
     */
    /**
     * The element's own `width`, resolved against the containing block, or null when it is `auto`
     * (or absent). Percentages resolve against $widthReference, so `width: 50%` on a cell is half
     * the table's content width.
     */
    private function declaredWidth(StyledNode $styled, float $widthReference, float $heightReference, float $fontSize): ?float
    {
        $value = $styled->style->get('width', 'auto') ?? 'auto';
        if ($this->isAuto($value)) {
            return null;
        }

        return max(0.0, $this->resolveLength($value, $widthReference, $fontSize, $widthReference, $heightReference, 'zero'));
    }

    private function shrinkToFitWidth(StyledNode $styled, float $available, float $fontSize): float
    {
        // CSS 2.1 shrink-to-fit: min(max(preferred-min-width, available), preferred-width).
        // The recursive resolver sees block descendants and replaced content too, so floats no
        // longer fall back to the entire containing width merely because their direct children
        // are block-level.
        $intrinsic = $this->intrinsicSizeResolver->measure($styled, $available, $fontSize);

        return min(max($intrinsic->minContent, $available), $intrinsic->maxContent);
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
        $display = $this->display($node);
        if (in_array($display, ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell', 'flex', 'grid'], true)) {
            return true;
        }

        // An element the UA sheet does not know resolves to `inline`, and an inline box holding
        // block-level content is something this engine has nowhere to put: the inline formatter
        // only lays out text and atomic boxes, so every block inside it — and all of its text —
        // was silently dropped. `<article><secao-custom><p>…</p></secao-custom></article>`
        // rendered as an empty page. Real documents reach us with wrappers like that: the corpus
        // already carries `<mce:style>` from TinyMCE, and HTML pasted out of Word brings `<o:p>`.
        //
        // CSS answers this by splitting the inline box around the block (block-in-inline). This
        // port has no such splitting, so it does the next best thing and treats the inline box as
        // a block, which keeps the content and its own layout. `inline-block` is deliberately
        // left out: it is already laid out as an atomic box, and promoting it would change how
        // documents that use it today are rendered.
        return $display === 'inline' && $this->containsBlockLevelChild($node);
    }

    /**
     * Whether any child is block-level, memoized because isBlockLevel() consults this for every
     * inline element on every pass and these documents nest spans deeply.
     */
    private function containsBlockLevelChild(StyledNode $node): bool
    {
        $key = spl_object_id($node);
        if (isset($this->containsBlockCache[$key])) {
            return $this->containsBlockCache[$key];
        }
        // Set before recursing so a cyclic structure cannot loop forever.
        $this->containsBlockCache[$key] = false;

        foreach ($node->children as $child) {
            if ($child->node->type === 'element' && $this->isBlockLevel($child)) {
                return $this->containsBlockCache[$key] = true;
            }
        }

        return false;
    }

    private function resolveFontSize(StyledNode $node, float $parentFontSize): float
    {
        $value = $node->style->get('font-size');
        if ($value === null) return $parentFontSize;
        $keyword = FontSizeKeywords::resolve($value, $parentFontSize);
        if ($keyword !== null) return $keyword;
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

    /**
     * `position: absolute`/`fixed` boxes are not laid out by the normal-flow width/margin
     * equation (CSS 2.1 §10.3.7 has its own, involving `left`/`right`, that this engine does not
     * solve): the auto-margin fill/over-constraint step below is for a box that must span its
     * containing block's width, which an out-of-flow box never has to. Without this guard, a
     * `<div style="position:absolute;width:50px">` had its unset (0) right margin silently
     * stretched to fill the rest of the page, so `outOfFlowOffset`'s `right`/`auto` math worked
     * off a margin box as wide as the page instead of the declared 50px.
     */
    private function isOutOfFlow(StyledNode $styled): bool
    {
        return in_array(strtolower(trim($styled->style->get('position') ?? 'static')), ['absolute', 'fixed'], true);
    }

    /**
     * `list-style-position: inside` (CSS Lists 3 §7.1): the marker is the first inline box of the
     * list item's own content, in the flow with the text that follows it, rather than sitting in
     * the item's padding to the left of it (`outside`, the default, which DisplayListBuilder
     * paints without reserving any layout space for it). It was painted the same way regardless
     * of `list-style-position`, so an `inside` marker overlapped the first word instead of
     * pushing it aside.
     *
     * Only the marker string itself is returned; layoutBlockContent() below turns it into a real
     * leading text run and, once it has, strips `x-list-marker` from the returned node's style so
     * DisplayListBuilder's own paint-only marker has nothing left to draw.
     */
    private function insideListMarker(StyledNode $styled): ?string
    {
        $marker = $styled->style->get('x-list-marker');
        if ($marker === null || $marker === '') {
            return null;
        }

        return strtolower(trim($styled->style->get('list-style-position', 'outside') ?? 'outside')) === 'inside' ? $marker : null;
    }

    /**
     * The marker plus a thin trailing gap, as a synthetic text child sharing the list item's own
     * style (color, font, weight...) the way the marker inherits from it in every browser. A
     * plain space is what CSS itself uses for the gap between an `inside` marker and the text
     * that follows (no browser reserves the exact width DisplayListBuilder's `outside` gap does).
     */
    private function listMarkerStyledNode(StyledNode $styled, string $marker): StyledNode
    {
        return new StyledNode(Node::text($marker . ' '), $styled->style, []);
    }

    private function withoutListMarker(StyledNode $styled): StyledNode
    {
        $properties = $styled->style->properties;
        unset($properties['x-list-marker']);

        return new StyledNode($styled->node, new ComputedStyle($properties), $styled->children);
    }

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
