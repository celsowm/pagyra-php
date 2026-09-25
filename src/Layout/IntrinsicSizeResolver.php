<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\FontSizeKeywords;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;
use Pagyra\Units\Units;

/**
 * Recursively resolves content-driven inline-size bounds for a styled subtree.
 *
 * The reference table algorithm inspects intrinsicInlineSize/minIntrinsicInlineSize across every
 * descendant of a cell. Keeping that walk in one object makes the same information reusable by
 * tables, floats, inline-blocks, flex and grid instead of growing one-off measurements in each
 * layout strategy.
 */
final class IntrinsicSizeResolver
{
    private const ROOT_FONT_SIZE = 16.0;

    public function __construct(private readonly InlineTextFormatter $inlineFormatter)
    {
    }

    public function measure(StyledNode $node, float $referenceWidth, float $parentFontSize): IntrinsicInlineSize
    {
        if (strtolower(trim($node->style->get('display', 'inline') ?? 'inline')) === 'none') {
            return new IntrinsicInlineSize(0.0, 0.0);
        }

        $fontSize = $this->resolveFontSize($node->style, $parentFontSize);
        $own = $this->inlineFormatter->intrinsicInlineSize($node, $referenceWidth, $fontSize);
        $minContent = $own->minContent;
        $maxContent = $own->maxContent;

        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                continue;
            }
            if (strtolower(trim($child->style->get('display', 'inline') ?? 'inline')) === 'none') {
                continue;
            }

            $childSize = $this->measure($child, $referenceWidth, $fontSize);
            $minContent = max($minContent, $childSize->minContent);
            $maxContent = max($maxContent, $childSize->maxContent);
        }

        return new IntrinsicInlineSize($minContent, max($minContent, $maxContent));
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
