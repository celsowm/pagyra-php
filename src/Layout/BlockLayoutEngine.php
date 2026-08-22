<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Geometry\Edges;
use Pagyra\Geometry\Rect;
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

        foreach ($root->children as $child) {
            if ($this->display($child) === 'none' || !$this->isBlockLevel($child)) continue;
            $childFontSize = $this->resolveFontSize($child, self::ROOT_FONT_SIZE);
            $childTopMargin = $this->resolveMarginSide($child, 'top', $this->viewportWidth, $this->viewportHeight, $childFontSize);
            $flowY = $previousBorderBottom === null ? $cursorY : $previousBorderBottom + BlockMath::collapseMarginSet([$previousBottomMargin, $childTopMargin]) - $childTopMargin;
            $layout = $this->layoutBlock($child, 0.0, $flowY, $this->viewportWidth, $this->viewportHeight, self::ROOT_FONT_SIZE);
            $children[] = $layout;
            $previousBorderBottom = $layout->box->borderBox()->bottom();
            $previousBottomMargin = $layout->box->margin->bottom;
            $cursorY = $previousBorderBottom + $previousBottomMargin;
        }

        return new LayoutNode($root, new LayoutBox(new Rect(0.0, 0.0, $this->viewportWidth, max(0.0, $cursorY))), $children, self::ROOT_FONT_SIZE);
    }

    private function layoutBlock(StyledNode $styled, float $containingX, float $flowY, float $containingWidth, float $containingHeight, float $parentFontSize): LayoutNode
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

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;
        $cursorY = $contentY;
        $children = [];
        $previousBorderBottom = null;
        $previousBottomMargin = 0.0;

        foreach ($styled->children as $child) {
            if ($this->display($child) === 'none' || !$this->isBlockLevel($child)) continue;
            $childFontSize = $this->resolveFontSize($child, $fontSize);
            $childTopMargin = $this->resolveMarginSide($child, 'top', $contentWidth, $containingHeight, $childFontSize);
            $childFlowY = $previousBorderBottom === null ? $cursorY : $previousBorderBottom + BlockMath::collapseMarginSet([$previousBottomMargin, $childTopMargin]) - $childTopMargin;
            $childLayout = $this->layoutBlock($child, $contentX, $childFlowY, $contentWidth, $containingHeight, $fontSize);
            $children[] = $childLayout;
            $previousBorderBottom = $childLayout->box->borderBox()->bottom();
            $previousBottomMargin = $childLayout->box->margin->bottom;
            $cursorY = $previousBorderBottom + $previousBottomMargin;
        }

        $inlineLayout = $this->hasInlineContent($styled) ? $this->inlineTextFormatter->layout($styled, $contentX, $contentY, $contentWidth, $fontSize) : new InlineTextLayout([], 0.0);
        $autoContentHeight = max(max(0.0, $cursorY - $contentY), $inlineLayout->height);
        $heightValue = $styled->style->get('height', 'auto') ?? 'auto';
        $verticalNonContent = $padding->vertical() + $border->vertical();
        if ($this->isAuto($heightValue)) {
            $contentHeight = $autoContentHeight;
        } else {
            $resolvedHeight = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentHeight = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box' ? max(0.0, $resolvedHeight - $verticalNonContent) : max(0.0, $resolvedHeight);
        }
        $contentHeight = $this->applyVerticalConstraints($styled, $contentHeight, $verticalNonContent, $containingWidth, $containingHeight, $fontSize);

        return new LayoutNode($styled, new LayoutBox(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $padding, $border, $margin), $children, $fontSize, $inlineLayout->lines);
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

    private function isBlockLevel(StyledNode $node): bool
    {
        return in_array($this->display($node), ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true);
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
        return new Edges($this->resolveLength($node->style->get('border-top-width', $top) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($node->style->get('border-right-width', $right) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($node->style->get('border-bottom-width', $bottom) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'), $this->resolveLength($node->style->get('border-left-width', $left) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'));
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
