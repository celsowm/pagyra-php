<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\FontSizeKeywords;
use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Dom\Node;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyleComputer;
use Pagyra\Style\StyledNode;
use Pagyra\Units\Units;

/**
 * Recursively resolves min/max-content inline-size bounds for a styled subtree.
 *
 * measure() returns the content contribution of the node itself. Descendants are folded in as
 * their margin-box contributions so an explicitly sized/padded nested block cannot disappear from
 * its parent's intrinsic width. borderBox() exposes the same central measurement as a used
 * border-box bound for flex/grid/shrink-to-fit callers.
 */
final class IntrinsicSizeResolver
{
    private const ROOT_FONT_SIZE = 16.0;

    private readonly LengthParser $lengthParser;

    public function __construct(
        private readonly InlineTextFormatter $inlineFormatter,
        float $viewportWidth = 794.0,
        float $viewportHeight = 1123.0,
    ) {
        $this->lengthParser = new LengthParser($viewportWidth, $viewportHeight);
    }

    public function measure(StyledNode $node, float $referenceWidth, float $parentFontSize): IntrinsicInlineSize
    {
        if ($this->display($node) === 'none') {
            return new IntrinsicInlineSize(0.0, 0.0);
        }

        $fontSize = $this->resolveFontSize($node->style, $parentFontSize);

        // A flex row or a grid places its items side by side, so its intrinsic width is the sum of
        // the items (and the gaps between them), not the widest item as for a block. Treating
        // them as blocks made a `display:flex` brand with a logo and a name, or a two-column
        // `grid` of labels and values, report the width of one child: the container was then
        // laid out too narrow and wrapped its text or spilled past its edge.
        $display = $this->display($node);
        $laidOut = match ($display) {
            'flex', 'inline-flex' => $this->measureFlexRow($node, $referenceWidth, $fontSize),
            'grid', 'inline-grid' => $this->measureGrid($node, $referenceWidth, $fontSize),
            default => null,
        };
        if ($laidOut !== null) {
            return $laidOut;
        }

        $own = $this->inlineFormatter->intrinsicInlineSize($node, $referenceWidth, $fontSize);
        $minContent = $own->minContent;
        $maxContent = $own->maxContent;

        foreach ($node->children as $child) {
            if ($child->node->type === 'text' || $this->display($child) === 'none') {
                continue;
            }

            $childSize = $this->outerContribution($child, $referenceWidth, $fontSize);
            $minContent = max($minContent, $childSize->minContent);
            $maxContent = max($maxContent, $childSize->maxContent);
        }

        return new IntrinsicInlineSize($minContent, max($minContent, $maxContent));
    }

    /**
     * Intrinsic min/max bounds of this node's border box, excluding its margins.
     */
    public function borderBox(StyledNode $node, float $referenceWidth, float $parentFontSize): IntrinsicInlineSize
    {
        if ($this->display($node) === 'none') {
            return new IntrinsicInlineSize(0.0, 0.0);
        }

        $fontSize = $this->resolveFontSize($node->style, $parentFontSize);

        // Replaced elements are already measured by InlineTextFormatter as their full atomic outer
        // box (margin + border + padding + content). Strip only the direct margins here.
        if ($node->node->isImage() || $node->node->isSvg()) {
            $outer = $this->inlineFormatter->intrinsicInlineSize($node, $referenceWidth, $fontSize);
            $margin = $this->horizontalEdges($node->style, 'margin', $referenceWidth, $fontSize, true);

            return new IntrinsicInlineSize(
                max(0.0, $outer->minContent - $margin),
                max(0.0, $outer->maxContent - $margin),
            );
        }

        $content = $this->measure($node, $referenceWidth, $fontSize);
        $nonContent = $this->horizontalEdges($node->style, 'padding', $referenceWidth, $fontSize)
            + $this->horizontalBorder($node->style, $referenceWidth, $fontSize);

        $min = $content->minContent + $nonContent;
        $max = $content->maxContent + $nonContent;

        $specified = $this->specifiedBorderBoxWidth($node->style, 'width', $referenceWidth, $fontSize, $nonContent);
        if ($specified !== null) {
            $min = $max = $specified;
        }

        $minimum = $this->specifiedBorderBoxWidth($node->style, 'min-width', $referenceWidth, $fontSize, $nonContent);
        if ($minimum !== null) {
            $min = max($min, $minimum);
            $max = max($max, $minimum);
        }

        $maximum = $this->specifiedBorderBoxWidth($node->style, 'max-width', $referenceWidth, $fontSize, $nonContent);
        if ($maximum !== null) {
            $max = min($max, $maximum);
            $min = min($min, $max);
        }

        return new IntrinsicInlineSize(max(0.0, $min), max(0.0, $max));
    }

    /**
     * Min/max-content width of a row flex container (CSS Flexbox 1 §9.9.1): the items' outer
     * contributions side by side plus the column gaps. A single-line container cannot get
     * narrower than all its items' min-content widths together; a multi-line one only than the
     * widest item. A column container stacks its items like a block, so it returns null and the
     * block measurement applies.
     */
    private function measureFlexRow(StyledNode $node, float $referenceWidth, float $fontSize): ?IntrinsicInlineSize
    {
        $direction = strtolower(trim($node->style->get('flex-direction') ?? ''));
        $wrap = strtolower(trim($node->style->get('flex-wrap') ?? ''));
        foreach (preg_split('/\s+/', strtolower(trim($node->style->get('flex-flow') ?? ''))) ?: [] as $token) {
            if ($direction === '' && in_array($token, ['row', 'row-reverse', 'column', 'column-reverse'], true)) $direction = $token;
            if ($wrap === '' && in_array($token, ['nowrap', 'wrap', 'wrap-reverse'], true)) $wrap = $token;
        }
        if (str_starts_with($direction, 'column')) {
            return null;
        }

        $items = $this->itemContributions($node, $referenceWidth, $fontSize);
        if ($items === []) {
            return null;
        }

        $gaps = $this->columnGap($node, $referenceWidth, $fontSize) * (count($items) - 1);
        $max = $gaps;
        $min = $gaps;
        $widestMin = 0.0;
        foreach ($items as $item) {
            $max += $item->maxContent;
            $min += $item->minContent;
            $widestMin = max($widestMin, $item->minContent);
        }
        if ($wrap === 'wrap' || $wrap === 'wrap-reverse') {
            $min = $widestMin;
        }

        return new IntrinsicInlineSize($min, max($min, $max));
    }

    /**
     * Min/max-content width of a grid (CSS Grid 1 §12.3, for auto-placed items in single cells):
     * each column takes its widest item, fixed tracks their length, and the columns and gaps add
     * up. Grids this does not model (named areas, explicit placement, column auto-flow,
     * `auto-fill`/`auto-fit`) return null and keep the block measurement.
     */
    private function measureGrid(StyledNode $node, float $referenceWidth, float $fontSize): ?IntrinsicInlineSize
    {
        if (str_contains(strtolower($node->style->get('grid-auto-flow') ?? ''), 'column')) return null;
        $areas = strtolower(trim($node->style->get('grid-template-areas') ?? 'none'));
        if ($areas !== '' && $areas !== 'none') return null;

        $tracks = $this->gridTracks($node->style->get('grid-template-columns') ?? 'none', $referenceWidth, $fontSize);
        if ($tracks === null || count($tracks) < 2) return null;

        foreach ($node->children as $child) {
            if ($child->node->type === 'text') continue;
            foreach (['grid-area', 'grid-column', 'grid-column-start', 'grid-column-end', 'grid-row', 'grid-row-start', 'grid-row-end'] as $property) {
                $value = strtolower(trim($child->style->get($property) ?? 'auto'));
                if ($value !== '' && $value !== 'auto' && $value !== 'auto / auto') return null;
            }
        }

        $items = $this->itemContributions($node, $referenceWidth, $fontSize);
        $count = count($tracks);
        $minimums = array_map(static fn(?float $fixed): float => $fixed ?? 0.0, $tracks);
        $maximums = $minimums;
        foreach ($items as $index => $item) {
            $column = $index % $count;
            if ($tracks[$column] !== null) continue;
            $minimums[$column] = max($minimums[$column], $item->minContent);
            $maximums[$column] = max($maximums[$column], $item->maxContent);
        }

        $gaps = $this->columnGap($node, $referenceWidth, $fontSize) * ($count - 1);
        $min = array_sum($minimums) + $gaps;

        return new IntrinsicInlineSize($min, max($min, array_sum($maximums) + $gaps));
    }

    /**
     * The outer contributions of a flex or grid container's items, in document order: every
     * in-flow child element, and each run of non-blank text as one anonymous item.
     *
     * @return list<IntrinsicInlineSize>
     */
    private function itemContributions(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $items = [];
        $text = [];
        $flush = function () use (&$text, &$items, $node, $referenceWidth, $fontSize): void {
            $blank = true;
            foreach ($text as $run) {
                if (trim($run->node->text ?? '') !== '') $blank = false;
            }
            if (!$blank) {
                $properties = ['display' => 'block'];
                foreach (StyleComputer::INHERITED as $property) {
                    $value = $node->style->get($property);
                    if ($value !== null) $properties[$property] = $value;
                }
                $anonymous = new StyledNode(Node::element(BlockLayoutEngine::ANONYMOUS_TAG, [], []), new ComputedStyle($properties), $text);
                $items[] = $this->inlineFormatter->intrinsicInlineSize($anonymous, $referenceWidth, $fontSize);
            }
            $text = [];
        };

        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                $text[] = $child;
                continue;
            }
            if ($this->display($child) === 'none') continue;
            $position = strtolower(trim($child->style->get('position') ?? 'static'));
            if ($position === 'absolute' || $position === 'fixed') continue;
            $flush();
            $items[] = $this->outerContribution($child, $referenceWidth, $fontSize);
        }
        $flush();

        return $items;
    }

    private function columnGap(StyledNode $node, float $referenceWidth, float $fontSize): float
    {
        $parts = preg_split('/\s+/', trim($node->style->get('gap') ?? '')) ?: [];
        $raw = $node->style->get('column-gap')
            ?? (($parts[1] ?? '') !== '' ? $parts[1] : null)
            ?? (($parts[0] ?? '') !== '' ? $parts[0] : null)
            ?? $node->style->get('grid-column-gap');
        // A percentage gap resolves against the width being measured, so it counts as zero here.
        if ($raw === null || in_array(strtolower(trim($raw)), ['normal', ''], true) || str_contains($raw, '%')) {
            return 0.0;
        }

        return max(0.0, $this->resolveLength($raw, $referenceWidth, $fontSize) ?? 0.0);
    }

    /**
     * The explicit column tracks: a length for a fixed track, null for one sized by its content
     * (`auto`, `fr`, `min-content`, `minmax()` with a flexible or content maximum, a percentage).
     * Null for the whole list when it cannot be counted without the container's width.
     *
     * @return list<?float>|null
     */
    private function gridTracks(string $value, float $referenceWidth, float $fontSize): ?array
    {
        $value = trim((string) preg_replace('/\[[^\]]*\]/', ' ', $value));
        if ($value === '' || strtolower($value) === 'none') return null;

        $tracks = [];
        foreach ($this->topLevelTokens($value) as $token) {
            $lower = strtolower($token);
            if (preg_match('/^repeat\(\s*(\d+)\s*,(.*)\)$/s', $lower, $m) === 1) {
                $inner = $this->gridTracks($m[2], $referenceWidth, $fontSize);
                if ($inner === null) return null;
                for ($i = 0; $i < (int) $m[1]; $i++) array_push($tracks, ...$inner);
                continue;
            }
            if (str_starts_with($lower, 'repeat(')) return null;
            if (preg_match('/^minmax\((.*),([^,]*)\)$/s', $lower, $m) === 1) {
                $lower = trim($m[2]);
            }
            $tracks[] = str_ends_with($lower, 'fr') || str_ends_with($lower, '%') || preg_match('/^[a-z-]+(\(.*\))?$/', $lower) === 1
                ? null
                : $this->resolveLength($lower, $referenceWidth, $fontSize);
        }

        return $tracks;
    }

    /** @return list<string> */
    private function topLevelTokens(string $value): array
    {
        $tokens = [];
        $buffer = '';
        $depth = 0;
        foreach (str_split($value) as $char) {
            if ($char === '(') $depth++;
            if ($char === ')') $depth = max(0, $depth - 1);
            if ($depth === 0 && ctype_space($char)) {
                if ($buffer !== '') $tokens[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($buffer !== '') $tokens[] = $buffer;

        return $tokens;
    }

    /**
     * A child's contribution to an ancestor's intrinsic content width includes its margins.
     */
    private function outerContribution(StyledNode $node, float $referenceWidth, float $parentFontSize): IntrinsicInlineSize
    {
        if ($node->node->isImage() || $node->node->isSvg()) {
            $fontSize = $this->resolveFontSize($node->style, $parentFontSize);
            return $this->inlineFormatter->intrinsicInlineSize($node, $referenceWidth, $fontSize);
        }

        $box = $this->borderBox($node, $referenceWidth, $parentFontSize);
        $fontSize = $this->resolveFontSize($node->style, $parentFontSize);
        $margin = $this->horizontalEdges($node->style, 'margin', $referenceWidth, $fontSize, true);

        return new IntrinsicInlineSize(
            max(0.0, $box->minContent + $margin),
            max(0.0, $box->maxContent + $margin),
        );
    }

    private function specifiedBorderBoxWidth(
        ComputedStyle $style,
        string $property,
        float $referenceWidth,
        float $fontSize,
        float $nonContent,
    ): ?float {
        $raw = strtolower(trim($style->get($property) ?? ''));
        if ($raw === '' || in_array($raw, ['auto', 'none', 'min-content', 'max-content', 'fit-content'], true)) {
            return null;
        }

        $value = $this->resolveLength($raw, $referenceWidth, $fontSize);
        if ($value === null) return null;

        return strtolower($style->get('box-sizing', 'content-box') ?? 'content-box') === 'border-box'
            ? max($nonContent, $value)
            : max(0.0, $value + $nonContent);
    }

    private function horizontalEdges(
        ComputedStyle $style,
        string $property,
        float $referenceWidth,
        float $fontSize,
        bool $allowNegative = false,
    ): float {
        $parts = $this->expandFour($style->get($property, '0') ?? '0');
        $left = $style->get($property . '-left') ?? $parts[3];
        $right = $style->get($property . '-right') ?? $parts[1];

        $l = $this->resolveLength($left, $referenceWidth, $fontSize) ?? 0.0;
        $r = $this->resolveLength($right, $referenceWidth, $fontSize) ?? 0.0;
        if (!$allowNegative) {
            $l = max(0.0, $l);
            $r = max(0.0, $r);
        }

        return $l + $r;
    }

    private function horizontalBorder(ComputedStyle $style, float $referenceWidth, float $fontSize): float
    {
        $parts = $this->expandFour($style->get('border-width', '0') ?? '0');
        $left = $style->get('border-left-width') ?? $parts[3];
        $right = $style->get('border-right-width') ?? $parts[1];

        return max(0.0, $this->borderLength($left, $referenceWidth, $fontSize))
            + max(0.0, $this->borderLength($right, $referenceWidth, $fontSize));
    }

    private function borderLength(string $raw, float $referenceWidth, float $fontSize): float
    {
        return match (strtolower(trim($raw))) {
            'thin' => 1.0,
            'medium' => 3.0,
            'thick' => 5.0,
            default => $this->resolveLength($raw, $referenceWidth, $fontSize) ?? 0.0,
        };
    }

    private function resolveLength(string $raw, float $referenceWidth, float $fontSize): ?float
    {
        $parsed = $this->lengthParser->parseLengthOrPercent(trim($raw));
        if ($parsed === null) return null;

        return LengthResolver::resolve(
            $parsed,
            $referenceWidth,
            $fontSize,
            self::ROOT_FONT_SIZE,
            $referenceWidth,
            $referenceWidth,
            'zero',
        );
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function expandFour(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: ['0'];

        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    private function display(StyledNode $node): string
    {
        return strtolower(trim($node->style->get('display', 'inline') ?? 'inline'));
    }

    private function resolveFontSize(ComputedStyle $style, float $parentFontSize): float
    {
        $raw = strtolower(trim($style->get('font-size') ?? ''));
        if ($raw === '') return $parentFontSize;

        $keyword = FontSizeKeywords::resolve($raw, $parentFontSize);
        if ($keyword !== null) return $keyword;
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $raw, $m) === 1) return max(0.0, (float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $raw, $m) === 1) return max(0.0, Units::ptToPx((float) $m[1]));
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * $parentFontSize);
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * self::ROOT_FONT_SIZE);
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $raw, $m) === 1) return max(0.0, ((float) $m[1] / 100.0) * $parentFontSize);

        return is_numeric($raw) ? max(0.0, (float) $raw) : $parentFontSize;
    }
}
