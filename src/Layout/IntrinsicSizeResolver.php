<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\FontSizeKeywords;
use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Style\ComputedStyle;
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
