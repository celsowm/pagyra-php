<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Image\ReplacedElementSizingResolver;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;
use Pagyra\Units\Units;

final class InlineTextFormatter
{
    private const ROOT_FONT_SIZE = 16.0;
    private const EPSILON = 0.01;

    private readonly ReplacedElementSizingResolver $replacedElementSizing;

    public function __construct(private readonly TextMetrics $metrics = new HeuristicTextMetrics())
    {
        $this->replacedElementSizing = new ReplacedElementSizingResolver();
    }

    public function layout(StyledNode $block, float $x, float $y, float $availableWidth, float $fontSize): InlineTextLayout
    {
        $tokens = $this->collectTokens($block, $fontSize, $availableWidth);
        if ($tokens === []) {
            return new InlineTextLayout([], 0.0);
        }

        $whiteSpace = strtolower($block->style->get('white-space', 'normal') ?? 'normal');
        $overflowWrap = strtolower($block->style->get('overflow-wrap', 'normal') ?? 'normal');
        $wordBreak = strtolower($block->style->get('word-break', 'normal') ?? 'normal');
        $allowSoftWrap = !in_array($whiteSpace, ['nowrap', 'pre'], true);

        $lines = [];
        $current = [];
        $currentWidth = 0.0;

        foreach ($tokens as $token) {
            if ($token['kind'] === 'newline') {
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
                continue;
            }

            if ($token['kind'] === 'space' && $this->collapsesSpaces($whiteSpace)) {
                if ($current === [] || ($current[array_key_last($current)]['kind'] ?? null) === 'space') {
                    continue;
                }
                $token['text'] = ' ';
                $token['width'] = $this->metrics->measure(' ', $token['style'], $token['fontSize'])->inlineSize;
            }

            if ($allowSoftWrap && $availableWidth > 0.0 && $current !== [] && $currentWidth + $token['width'] > $availableWidth) {
                if ($token['kind'] === 'space' && $this->collapsesSpaces($whiteSpace)) {
                    $lines[] = $current;
                    $current = [];
                    $currentWidth = 0.0;
                    continue;
                }
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
            }

            if ($allowSoftWrap && $availableWidth > 0.0 && $token['kind'] === 'word' && $token['width'] > $availableWidth && $this->canBreakInsideWord($overflowWrap, $wordBreak)) {
                foreach ($this->splitWordToken($token, $availableWidth) as $index => $chunk) {
                    if ($index > 0) {
                        $lines[] = $current;
                        $current = [];
                        $currentWidth = 0.0;
                    }
                    $current[] = $chunk;
                    $currentWidth += $chunk['width'];
                }
                continue;
            }

            $current[] = $token;
            $currentWidth += $token['width'];
        }

        if ($current !== [] || $lines === []) {
            $lines[] = $current;
        }

        $lineBoxes = [];
        $cursorY = $y;
        foreach ($lines as $lineIndex => $lineTokens) {
            $lineWidth = array_sum(array_column($lineTokens, 'width'));
            // A line that carries no text and only collapsed (zero-height) atomic boxes gets no
            // font strut: `<div style="display:inline-block;height:0"><img style="display:block"></div>`
            // alone on its line contributes nothing to the flow in browsers, so the following
            // block must not be pushed down by a phantom line. Any real text, or an atomic box
            // with height, brings the strut back.
            $collapsedBoxLineOnly = $lineTokens !== [];
            foreach ($lineTokens as $token) {
                if ($token['kind'] !== 'box' || $token['lineHeight'] > self::EPSILON) {
                    $collapsedBoxLineOnly = false;
                    break;
                }
            }
            $nominalHeight = $collapsedBoxLineOnly ? 0.0 : $this->metrics->lineHeight($block->style, $fontSize);
            foreach ($lineTokens as $token) {
                $nominalHeight = max($nominalHeight, $token['lineHeight']);
            }

            $isLastLine = $lineIndex === count($lines) - 1;
            $alignment = strtolower($block->style->get('text-align', 'left') ?? 'left');
            // `text-align` aligns inline content inside a line box; it never moves a block-level
            // box, which stays at the content edge (only auto margins would move it). A line
            // holding just such a box therefore ignores the alignment entirely.
            $isBlockLevelBoxLine = count($lineTokens) === 1 && ($lineTokens[0]['blockLevel'] ?? false);
            $justify = !$isBlockLevelBoxLine && $alignment === 'justify' && !$isLastLine && $availableWidth > $lineWidth;
            $spaceCount = $justify ? $this->countSpaceTokens($lineTokens) : 0;
            $extraPerSpace = $spaceCount > 0 ? ($availableWidth - $lineWidth) / $spaceCount : 0.0;
            $offset = ($justify || $isBlockLevelBoxLine) ? 0.0 : $this->alignmentOffset($alignment, $lineWidth, $availableWidth);

            $lineBaseline = $this->ownBaseline($fontSize, $nominalHeight);
            $placements = [];
            $minTop = 0.0;
            $maxBottom = 0.0;

            foreach ($lineTokens as $token) {
                if ($token['kind'] === 'box') {
                    $top = $this->boxTopOffset($token, $nominalHeight, $fontSize);
                    $placements[] = ['token' => $token, 'top' => $top, 'baseline' => null];
                    $minTop = min($minTop, $top);
                    $maxBottom = max($maxBottom, $top + $token['lineHeight']);
                    continue;
                }

                $itemBaseline = $this->textBaseline($token, $lineBaseline, $nominalHeight);
                $top = $itemBaseline - $this->ownBaseline($token['fontSize'], $token['lineHeight']);
                $placements[] = ['token' => $token, 'top' => $top, 'baseline' => $itemBaseline];
                $minTop = min($minTop, $top);
                $maxBottom = max($maxBottom, $top + $token['lineHeight']);
            }

            // The nominal height is a floor on the finished line, not a term measured in the
            // unshifted frame: adding it there and then subtracting $minTop counted the shift
            // twice, so a line mixing font sizes grew by however far its tallest run reached
            // above the strut baseline (a 10px paragraph holding a 30px span came out 41px tall
            // where the reference, and browsers, give 36).
            $lineHeight = max($nominalHeight, $maxBottom - $minTop);
            $baseline = $cursorY + ($lineBaseline - $minTop);
            $runX = $x + $offset;
            $runs = [];
            $boxes = [];
            $usedWidth = 0.0;

            foreach ($placements as $placement) {
                $token = $placement['token'];
                $width = $token['width'] + (($justify && $token['kind'] === 'space') ? $extraPerSpace : 0.0);
                $itemY = $cursorY + ($placement['top'] - $minTop);

                if ($token['kind'] === 'box') {
                    $contentX = $runX + $token['margin']['left'] + $token['border']['left'] + $token['padding']['left'];
                    $contentY = $itemY + $token['margin']['top'] + $token['border']['top'] + $token['padding']['top'];
                    $contentLines = $this->translateLines($token['contentLines'], $contentX, $contentY);
                    $boxes[] = new AtomicInlineBox(
                        source: $token['source'],
                        x: $runX,
                        y: $itemY,
                        width: $width,
                        height: $token['lineHeight'],
                        style: $token['style'],
                        contentWidth: $token['contentWidth'],
                        contentHeight: $token['contentHeight'],
                        margin: $token['margin'],
                        padding: $token['padding'],
                        border: $token['border'],
                        contentLines: $contentLines,
                    );
                } else {
                    $runBaseline = $cursorY + (($placement['baseline'] ?? $lineBaseline) - $minTop);
                    $this->appendRun($runs, new TextRun(
                        $runX,
                        $itemY,
                        $width,
                        $token['lineHeight'],
                        $runBaseline,
                        $token['text'],
                        $token['fontSize'],
                        $token['style'],
                    ));
                }

                $runX += $width;
                $usedWidth += $width;
            }

            $text = implode('', array_map(static fn(TextRun $run): string => $run->text, $runs));
            $lineBoxes[] = new LineBox($x + $offset, $cursorY, $usedWidth, $lineHeight, $baseline, $text, $runs, $boxes);
            $cursorY += $lineHeight;
        }

        return new InlineTextLayout($lineBoxes, $cursorY - $y);
    }

    private function collectTokens(StyledNode $node, float $nodeFontSize, float $referenceWidth): array
    {
        $tokens = [];
        $children = $node->children;
        foreach ($children as $index => $child) {
            if ($child->node->type === 'text') {
                $text = $this->applyTextTransform($child->node->text ?? '', $node->style);
                array_push($tokens, ...$this->tokenizeText($text, $node->style, $nodeFontSize));
                continue;
            }

            $display = strtolower($child->style->get('display', 'inline') ?? 'inline');
            if ($display === 'none') {
                continue;
            }
            $blockLevel = in_array($display, ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true);
            if ($blockLevel && !($child->node->isImage() || $child->node->isSvg())) {
                // Block-level, non-replaced children belong to a block formatting context this
                // formatter does not run (see the mixed inline/block limitation in README.md).
                continue;
            }

            $fontSize = $this->resolveFontSize($child->style, $nodeFontSize);
            if ($blockLevel) {
                // A block-level replaced element reached through an inline formatting context,
                // which is what happens when it sits inside an inline-block: that box's inner
                // content is laid out by this formatter, not by the block engine, so skipping it
                // here dropped the image entirely. It becomes an atomic box on a line of its own,
                // the closest this formatter gets to the block box it should generate.
                //
                // The surrounding breaks are only emitted when there is something to break away
                // from: a leading break with nothing before it, or a trailing break with nothing
                // after it, would add an empty line and push the image off the box's top.
                if ($tokens !== [] && ($tokens[array_key_last($tokens)]['kind'] ?? null) !== 'newline') {
                    $tokens[] = $this->textToken('newline', '', $child->style, $fontSize);
                }
                $tokens[] = $this->atomicBoxToken($child, $fontSize, $referenceWidth, true);
                if ($this->hasFollowingInlineContent($children, $index)) {
                    $tokens[] = $this->textToken('newline', '', $child->style, $fontSize);
                }
                continue;
            }
            if ($child->node->isElement('br')) {
                // A forced break, independent of `white-space`. pagyra-js's brHandler builds a
                // text node holding "\n" with the parent's inline style, but its tokenizer only
                // turns "\n" into a break under pre/pre-wrap/pre-line, so under the default
                // `white-space: normal` that "\n" collapses into a plain space and the break is
                // lost. Emitting the newline token directly keeps the reference's intent (its
                // helper is literally named createBreakNode) without depending on a white-space
                // mode the element does not set. A `<br>` hidden with `display: none` is already
                // dropped by the check above, matching browsers.
                $tokens[] = $this->textToken('newline', '', $child->style, $fontSize);
                continue;
            }
            if ($child->node->isImage() || $child->node->isSvg() || in_array($display, ['inline-block', 'inline-flex', 'inline-grid', 'inline-table'], true)) {
                $tokens[] = $this->atomicBoxToken($child, $fontSize, $referenceWidth);
                continue;
            }

            array_push($tokens, ...$this->collectTokens($child, $fontSize, $referenceWidth));
        }
        return $tokens;
    }

    /** @param list<StyledNode> $children */
    private function hasFollowingInlineContent(array $children, int $index): bool
    {
        for ($i = $index + 1, $count = count($children); $i < $count; $i++) {
            $child = $children[$i];
            if ($child->node->type === 'text') {
                if (trim($child->node->text ?? '') !== '') return true;
                continue;
            }
            if (strtolower($child->style->get('display', 'inline') ?? 'inline') === 'none') continue;
            return true;
        }
        return false;
    }

    private function atomicBoxToken(StyledNode $node, float $fontSize, float $referenceWidth, bool $blockLevel = false): array
    {
        $metrics = $this->atomicBoxMetrics($node, $referenceWidth, $fontSize);
        return [
            'kind' => 'box',
            'blockLevel' => $blockLevel,
            'text' => '',
            'style' => $node->style,
            'fontSize' => $fontSize,
            'width' => $metrics['outerWidth'],
            'lineHeight' => $metrics['outerHeight'],
            'source' => $node,
            'contentWidth' => $metrics['contentWidth'],
            'contentHeight' => $metrics['contentHeight'],
            'margin' => $metrics['margin'],
            'padding' => $metrics['padding'],
            'border' => $metrics['border'],
            'contentLines' => $metrics['contentLines'],
        ];
    }

    private function tokenizeText(string $text, ComputedStyle $style, float $fontSize): array
    {
        if ($text === '') {
            return [];
        }

        $whiteSpace = strtolower($style->get('white-space', 'normal') ?? 'normal');
        $parts = preg_split('/(\r\n|\r|\n|[\t\f ]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if (preg_match('/^(\r\n|\r|\n)$/u', $part) === 1) {
                $preserve = in_array($whiteSpace, ['pre', 'pre-wrap', 'pre-line'], true);
                $tokens[] = $this->textToken($preserve ? 'newline' : 'space', $preserve ? '' : ' ', $style, $fontSize);
            } elseif (preg_match('/^[\t\f ]+$/u', $part) === 1) {
                $tokens[] = $this->textToken('space', in_array($whiteSpace, ['pre', 'pre-wrap'], true) ? $part : ' ', $style, $fontSize);
            } else {
                $tokens[] = $this->textToken('word', $part, $style, $fontSize);
            }
        }

        return $tokens;
    }

    private function textToken(string $kind, string $text, ComputedStyle $style, float $fontSize): array
    {
        return [
            'kind' => $kind,
            'text' => $text,
            'style' => $style,
            'fontSize' => $fontSize,
            'width' => $kind === 'newline' ? 0.0 : $this->metrics->measure($text, $style, $fontSize)->inlineSize,
            'lineHeight' => $this->metrics->lineHeight($style, $fontSize),
            'source' => null,
        ];
    }

    private function textBaseline(array $token, float $lineBaseline, float $lineHeight): float
    {
        $value = strtolower(trim($token['style']->get('vertical-align', 'baseline') ?? 'baseline'));
        $fontSize = $token['fontSize'];
        $ownHeight = $token['lineHeight'];

        return match ($value) {
            'sub' => $lineBaseline + $fontSize * 0.2,
            'super' => $lineBaseline - $fontSize * 0.4,
            'top', 'text-top' => $this->ownBaseline($fontSize, $ownHeight),
            'bottom', 'text-bottom' => max($lineHeight - $ownHeight, 0.0) + $this->ownBaseline($fontSize, $ownHeight),
            'middle' => $lineBaseline + $fontSize * 0.25 + ($this->ownBaseline($fontSize, $ownHeight) - $ownHeight / 2.0),
            default => $lineBaseline - $this->numericVerticalShift($value, $fontSize, $ownHeight),
        };
    }

    private function boxTopOffset(array $token, float $lineHeight, float $parentFontSize): float
    {
        $value = strtolower(trim($token['style']->get('vertical-align', 'baseline') ?? 'baseline'));
        $height = $token['lineHeight'];

        return match ($value) {
            'bottom', 'text-bottom' => max($lineHeight - $height, 0.0),
            'middle' => ($lineHeight - $height) / 2.0,
            'sub' => $parentFontSize * 0.2,
            'super' => -$parentFontSize * 0.4,
            'top', 'text-top', 'baseline' => 0.0,
            default => -$this->numericVerticalShift($value, $parentFontSize, $height),
        };
    }

    private function ownBaseline(float $fontSize, float $lineHeight): float
    {
        return (($lineHeight - $fontSize) / 2.0) + ($fontSize * 0.75);
    }

    private function numericVerticalShift(string $value, float $fontSize, float $lineHeight): float
    {
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) return (float) $m[1];
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $value, $m) === 1) return Units::ptToPx((float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $value, $m) === 1) return (float) $m[1] * $fontSize;
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $value, $m) === 1) return (float) $m[1] * self::ROOT_FONT_SIZE;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) return ((float) $m[1] / 100.0) * $lineHeight;
        return 0.0;
    }

    /**
     * Content size of a replaced element (image/SVG), for callers outside the inline formatter.
     * A `display:block` image is not an atomic inline box, but it is sized by exactly the same
     * replaced-element rules, so BlockLayoutEngine resolves its box through here instead of
     * falling back to the ordinary "auto width fills the container, auto height is zero" block
     * behavior, which collapses a block image to a zero-height strip.
     *
     * @return array{0:float,1:float} content width and height
     */
    public function replacedContentSize(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        return $this->imageContentSize(
            $node,
            $referenceWidth,
            $fontSize,
            $this->edgeMetrics($node, 'margin', $referenceWidth, $fontSize),
            $this->edgeMetrics($node, 'padding', $referenceWidth, $fontSize),
            $this->borderMetrics($node, $referenceWidth, $fontSize),
        );
    }

    private function atomicBoxMetrics(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $margin = $this->edgeMetrics($node, 'margin', $referenceWidth, $fontSize);
        $padding = $this->edgeMetrics($node, 'padding', $referenceWidth, $fontSize);
        $border = $this->borderMetrics($node, $referenceWidth, $fontSize);

        if ($node->node->isImage() || $node->node->isSvg()) {
            [$contentWidth, $contentHeight] = $this->imageContentSize($node, $referenceWidth, $fontSize, $margin, $padding, $border);
            $contentLines = [];
        } else {
            [$contentWidth, $contentHeight, $contentLines] = $this->inlineBlockContentSize($node, $referenceWidth, $fontSize);
        }

        $horizontalExtras = $margin['left'] + $margin['right'] + $padding['left'] + $padding['right'] + $border['left'] + $border['right'];
        $verticalExtras = $margin['top'] + $margin['bottom'] + $padding['top'] + $padding['bottom'] + $border['top'] + $border['bottom'];

        return [
            'contentWidth' => $contentWidth,
            'contentHeight' => $contentHeight,
            'outerWidth' => $contentWidth + $horizontalExtras,
            'outerHeight' => $contentHeight + $verticalExtras,
            'margin' => $margin,
            'padding' => $padding,
            'border' => $border,
            'contentLines' => $contentLines,
        ];
    }

    private function imageContentSize(StyledNode $node, float $referenceWidth, float $fontSize, array $margin, array $padding, array $border): array
    {
        $intrinsicWidth = $this->numericAttribute($node, 'width');
        $intrinsicHeight = $this->numericAttribute($node, 'height');

        $rawWidth = trim($node->style->get('width', 'auto') ?? 'auto');
        $rawHeight = trim($node->style->get('height', 'auto') ?? 'auto');
        $specifiedWidth = strtolower($rawWidth) === 'auto'
            ? null
            : $this->resolveSimpleLength($rawWidth, $referenceWidth, $fontSize, $intrinsicWidth);
        $specifiedHeight = strtolower($rawHeight) === 'auto'
            ? null
            : $this->resolveSimpleLength($rawHeight, $referenceWidth, $fontSize, $intrinsicHeight);

        $horizontalExtras = $padding['left'] + $padding['right'] + $border['left'] + $border['right'];
        $verticalExtras = $padding['top'] + $padding['bottom'] + $border['top'] + $border['bottom'];
        $horizontalOuterExtras = $margin['left'] + $margin['right'] + $horizontalExtras;
        $availableContentWidth = max(0.0, $referenceWidth - $horizontalOuterExtras);
        $boxSizing = strtolower($node->style->get('box-sizing', 'content-box') ?? 'content-box');

        $minWidth = $this->optionalImageConstraint($node, 'min-width', $referenceWidth, $fontSize, $intrinsicWidth);
        $maxWidth = $this->optionalImageConstraint($node, 'max-width', $referenceWidth, $fontSize, $intrinsicWidth);
        $minHeight = $this->optionalImageConstraint($node, 'min-height', $referenceWidth, $fontSize, $intrinsicHeight);
        $maxHeight = $this->optionalImageConstraint($node, 'max-height', $referenceWidth, $fontSize, $intrinsicHeight);

        $size = $this->replacedElementSizing->resolve(
            intrinsicWidth: $intrinsicWidth,
            intrinsicHeight: $intrinsicHeight,
            specifiedWidth: $specifiedWidth,
            specifiedHeight: $specifiedHeight,
            boxSizing: $boxSizing,
            horizontalExtras: $horizontalExtras,
            verticalExtras: $verticalExtras,
            minWidth: $minWidth,
            maxWidth: $maxWidth,
            minHeight: $minHeight,
            maxHeight: $maxHeight,
            availableContentWidth: $availableContentWidth,
        );

        $width = $size->width;
        $height = $size->height;

        if ($width <= 0.0 && $height <= 0.0) {
            $width = $height = $this->metrics->lineHeight($node->style, $fontSize);
        }

        return [max(0.0, $width), max(0.0, $height)];
    }

    private function optionalImageConstraint(StyledNode $node, string $property, float $referenceWidth, float $fontSize, float $fallback): ?float
    {
        $raw = $node->style->get($property);
        if ($raw === null || in_array(strtolower(trim($raw)), ['auto', 'none'], true)) {
            return null;
        }

        return $this->resolveSimpleLength($raw, $referenceWidth, $fontSize, $fallback);
    }

    private function inlineBlockContentSize(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $rawWidth = trim($node->style->get('width', 'auto') ?? 'auto');
        $rawHeight = trim($node->style->get('height', 'auto') ?? 'auto');
        $hasWidth = strtolower($rawWidth) !== 'auto';
        $hasHeight = strtolower($rawHeight) !== 'auto';

        if ($hasWidth) {
            $contentWidth = $this->resolveSimpleLength($rawWidth, $referenceWidth, $fontSize, $referenceWidth);
        } else {
            $contentWidth = min($referenceWidth > 0.0 ? $referenceWidth : INF, $this->maxContentWidth($node, $fontSize, $referenceWidth));
            if (!is_finite($contentWidth) || $contentWidth <= 0.0) {
                $contentWidth = $this->metrics->lineHeight($node->style, $fontSize);
            }
        }

        $inner = $this->layout($node, 0.0, 0.0, max(0.0, $contentWidth), $fontSize);
        $contentHeight = $hasHeight
            ? $this->resolveSimpleLength($rawHeight, $referenceWidth, $fontSize, $inner->height)
            : max($inner->height, $this->metrics->lineHeight($node->style, $fontSize));

        $contentWidth = $this->clampDimension($node, 'width', $contentWidth, $referenceWidth, $fontSize);
        $contentHeight = $this->clampDimension($node, 'height', $contentHeight, $referenceWidth, $fontSize);

        if (!$hasWidth) {
            $inner = $this->layout($node, 0.0, 0.0, max(0.0, $contentWidth), $fontSize);
            if (!$hasHeight) $contentHeight = max($inner->height, $this->metrics->lineHeight($node->style, $fontSize));
        }

        return [$contentWidth, $contentHeight, $inner->lines];
    }

    private function maxContentWidth(StyledNode $node, float $fontSize, float $referenceWidth): float
    {
        $tokens = $this->collectTokens($node, $fontSize, $referenceWidth);
        $line = 0.0;
        $max = 0.0;
        foreach ($tokens as $token) {
            if ($token['kind'] === 'newline') {
                $max = max($max, $line);
                $line = 0.0;
                continue;
            }
            $line += $token['width'];
        }
        return max($max, $line);
    }

    private function clampDimension(StyledNode $node, string $axis, float $value, float $referenceWidth, float $fontSize): float
    {
        $min = $node->style->get('min-' . $axis);
        $max = $node->style->get('max-' . $axis);
        if ($min !== null && strtolower(trim($min)) !== 'auto') {
            $value = max($value, $this->resolveSimpleLength($min, $referenceWidth, $fontSize, $value));
        }
        if ($max !== null && strtolower(trim($max)) !== 'auto') {
            $limit = $this->resolveSimpleLength($max, $referenceWidth, $fontSize, $value);
            if ($limit > 0.0) $value = min($value, $limit);
        }
        return max(0.0, $value);
    }

    private function edgeMetrics(StyledNode $node, string $property, float $referenceWidth, float $fontSize): array
    {
        // Negative margins are valid CSS and common in real headers (an <img> pulled left
        // with `margin-left:-10px` to hang past its box); negative padding is not, so only
        // `margin` keeps its sign here.
        $allowNegative = $property === 'margin';
        $parts = $this->expandFour($node->style->get($property, '0') ?? '0');
        foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $index) {
            $specific = $node->style->get($property . '-' . $side);
            if ($specific !== null) $parts[$index] = $specific;
        }
        return [
            'top' => $this->resolveSimpleLength($parts[0], $referenceWidth, $fontSize, 0.0, $allowNegative),
            'right' => $this->resolveSimpleLength($parts[1], $referenceWidth, $fontSize, 0.0, $allowNegative),
            'bottom' => $this->resolveSimpleLength($parts[2], $referenceWidth, $fontSize, 0.0, $allowNegative),
            'left' => $this->resolveSimpleLength($parts[3], $referenceWidth, $fontSize, 0.0, $allowNegative),
        ];
    }

    private function borderMetrics(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $parts = $this->expandFour($node->style->get('border-width', '0') ?? '0');
        foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $index) {
            $specific = $node->style->get('border-' . $side . '-width');
            if ($specific !== null) $parts[$index] = $specific;
        }
        $result = [];
        foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $index) {
            if (in_array($this->borderStyleForSide($node, $side), ['none', 'hidden'], true)) {
                $result[$side] = 0.0;
            } else {
                $result[$side] = $this->resolveSimpleLength($parts[$index], $referenceWidth, $fontSize, 0.0);
            }
        }
        return $result;
    }

    private function borderStyleForSide(StyledNode $node, string $side): string
    {
        $specific = $node->style->get('border-' . $side . '-style');
        if ($specific !== null && trim($specific) !== '') return strtolower(trim($specific));
        $parts = $this->expandFour($node->style->get('border-style', 'none') ?? 'none');
        $index = array_search($side, ['top', 'right', 'bottom', 'left'], true);
        return strtolower($parts[$index === false ? 0 : $index] ?? 'none');
    }

    private function expandFour(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: ['0'];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    private function numericAttribute(StyledNode $node, string $name): float
    {
        $value = $node->node->attribute($name);
        return $value !== null && is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    private function resolveSimpleLength(string $raw, float $referenceWidth, float $fontSize, float $fallback, bool $allowNegative = false): float
    {
        $floor = static fn (float $value): float => $allowNegative ? $value : max(0.0, $value);
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === 'auto') return $fallback;
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $raw, $m) === 1) return $floor((float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $raw, $m) === 1) return $floor(Units::ptToPx((float) $m[1]));
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $raw, $m) === 1) return $floor((float) $m[1] * $fontSize);
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $raw, $m) === 1) return $floor((float) $m[1] * self::ROOT_FONT_SIZE);
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $raw, $m) === 1) return $floor(((float) $m[1] / 100.0) * $referenceWidth);
        return is_numeric($raw) ? $floor((float) $raw) : $fallback;
    }

    private function splitWordToken(array $token, float $availableWidth): array
    {
        $chars = preg_split('//u', $token['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [$token['text']];
        $chunks = [];
        $buffer = '';
        foreach ($chars as $char) {
            $candidate = $buffer . $char;
            $candidateWidth = $this->metrics->measure($candidate, $token['style'], $token['fontSize'])->inlineSize;
            if ($buffer !== '' && $candidateWidth > $availableWidth) {
                $chunks[] = $this->retoken($token, $buffer);
                $buffer = $char;
            } else {
                $buffer = $candidate;
            }
        }
        if ($buffer !== '') $chunks[] = $this->retoken($token, $buffer);
        return $chunks === [] ? [$token] : $chunks;
    }

    private function retoken(array $token, string $text): array
    {
        $token['text'] = $text;
        $token['width'] = $this->metrics->measure($text, $token['style'], $token['fontSize'])->inlineSize;
        return $token;
    }

    private function translateLines(array $lines, float $dx, float $dy): array
    {
        $translated = [];
        foreach ($lines as $line) {
            $runs = [];
            foreach ($line->runs as $run) {
                $runs[] = new TextRun($run->x + $dx, $run->y + $dy, $run->width, $run->height, $run->baseline + $dy, $run->text, $run->fontSize, $run->style);
            }
            $boxes = [];
            foreach ($line->atomicBoxes as $box) {
                $boxes[] = new AtomicInlineBox($box->source, $box->x + $dx, $box->y + $dy, $box->width, $box->height, $box->style, $box->contentWidth, $box->contentHeight, $box->margin, $box->padding, $box->border, $this->translateLines($box->contentLines, $dx, $dy));
            }
            $translated[] = new LineBox($line->x + $dx, $line->y + $dy, $line->width, $line->height, $line->baseline + $dy, $line->text, $runs, $boxes);
        }
        return $translated;
    }

    private function collapsesSpaces(string $whiteSpace): bool
    {
        return !in_array($whiteSpace, ['pre', 'pre-wrap'], true);
    }

    private function canBreakInsideWord(string $overflowWrap, string $wordBreak): bool
    {
        return $wordBreak === 'break-all' || in_array($overflowWrap, ['anywhere', 'break-word'], true);
    }

    private function countSpaceTokens(array $tokens): int
    {
        return count(array_filter($tokens, static fn(array $token): bool => $token['kind'] === 'space'));
    }

    private function alignmentOffset(string $alignment, float $lineWidth, float $availableWidth): float
    {
        $slack = max(0.0, $availableWidth - $lineWidth);
        return match ($alignment) {
            'center' => $slack / 2.0,
            'right', 'end' => $slack,
            default => 0.0,
        };
    }

    private function appendRun(array &$runs, TextRun $run): void
    {
        $key = array_key_last($runs);
        $last = $key !== null ? $runs[$key] : null;
        if ($last instanceof TextRun
            && $last->style === $run->style
            && abs($last->fontSize - $run->fontSize) < 1e-9
            && abs(($last->x + $last->width) - $run->x) < 1e-9
            && abs($last->baseline - $run->baseline) < 1e-9) {
            $runs[$key] = new TextRun($last->x, min($last->y, $run->y), $last->width + $run->width, max($last->height, $run->height), $run->baseline, $last->text . $run->text, $run->fontSize, $run->style);
            return;
        }
        $runs[] = $run;
    }

    private function resolveFontSize(ComputedStyle $style, float $parentFontSize): float
    {
        $raw = strtolower(trim($style->get('font-size') ?? ''));
        if ($raw === '') return $parentFontSize;
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $raw, $m) === 1) return max(0.0, (float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $raw, $m) === 1) return max(0.0, Units::ptToPx((float) $m[1]));
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * $parentFontSize);
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * self::ROOT_FONT_SIZE);
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $raw, $m) === 1) return max(0.0, ((float) $m[1] / 100.0) * $parentFontSize);
        return is_numeric($raw) ? max(0.0, (float) $raw) : $parentFontSize;
    }

    private function applyTextTransform(string $text, ComputedStyle $style): string
    {
        return match (strtolower($style->get('text-transform', 'none') ?? 'none')) {
            'uppercase' => $this->upper($text),
            'lowercase' => $this->lower($text),
            'capitalize' => preg_replace_callback('/(^|\s)(\p{L})/u', fn(array $m): string => $m[1] . $this->upper($m[2]), $text) ?? $text,
            default => $text,
        };
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}