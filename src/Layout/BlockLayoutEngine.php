<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use Pagyra\Geometry\Edges;
use Pagyra\Geometry\Rect;
use Pagyra\Style\StyledNode;

final class BlockLayoutEngine
{
    private const ROOT_FONT_SIZE = 16.0;

    private readonly LengthParser $lengthParser;

    public function __construct(
        private readonly float $viewportWidth,
        private readonly float $viewportHeight,
    ) {
        $this->lengthParser = new LengthParser($viewportWidth, $viewportHeight);
    }

    public function layout(StyledNode $root): LayoutNode
    {
        return $this->layoutDocument($root);
    }

    private function layoutDocument(StyledNode $root): LayoutNode
    {
        $children = [];
        $cursorY = 0.0;

        foreach ($root->children as $child) {
            if ($this->display($child) === 'none' || !$this->isBlockLevel($child)) {
                continue;
            }

            $layout = $this->layoutBlock($child, 0.0, $cursorY, $this->viewportWidth, $this->viewportHeight, self::ROOT_FONT_SIZE);
            $children[] = $layout;
            $cursorY = $layout->box->marginBox()->bottom();
        }

        $box = new LayoutBox(new Rect(0.0, 0.0, $this->viewportWidth, max(0.0, $cursorY)));
        return new LayoutNode($root, $box, $children, self::ROOT_FONT_SIZE);
    }

    private function layoutBlock(
        StyledNode $styled,
        float $containingX,
        float $flowY,
        float $containingWidth,
        float $containingHeight,
        float $parentFontSize,
    ): LayoutNode {
        $fontSize = $this->resolveFontSize($styled, $parentFontSize);
        $margin = $this->resolveEdges($styled, 'margin', $containingWidth, $containingHeight, $fontSize);
        $padding = $this->resolveEdges($styled, 'padding', $containingWidth, $containingHeight, $fontSize);
        $border = $this->resolveBorderEdges($styled, $containingWidth, $containingHeight, $fontSize);

        $available = max(0.0, $containingWidth - $margin->horizontal());
        $horizontalNonContent = $padding->horizontal() + $border->horizontal();
        $widthValue = $styled->style->get('width', 'auto') ?? 'auto';

        if (strtolower(trim($widthValue)) === 'auto') {
            $contentWidth = max(0.0, $available - $horizontalNonContent);
        } else {
            $resolvedWidth = $this->resolveLength($widthValue, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentWidth = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box'
                ? max(0.0, $resolvedWidth - $horizontalNonContent)
                : max(0.0, $resolvedWidth);
        }

        $contentWidth = $this->applyHorizontalConstraints(
            $styled,
            $contentWidth,
            $horizontalNonContent,
            $containingWidth,
            $containingHeight,
            $fontSize,
        );

        $contentX = $containingX + $margin->left + $border->left + $padding->left;
        $contentY = $flowY + $margin->top + $border->top + $padding->top;
        $cursorY = $contentY;
        $children = [];

        foreach ($styled->children as $child) {
            if ($this->display($child) === 'none' || !$this->isBlockLevel($child)) {
                continue;
            }

            $childLayout = $this->layoutBlock(
                $child,
                $contentX,
                $cursorY,
                $contentWidth,
                $containingHeight,
                $fontSize,
            );
            $children[] = $childLayout;
            $cursorY = $childLayout->box->marginBox()->bottom();
        }

        $autoContentHeight = max(0.0, $cursorY - $contentY);
        $heightValue = $styled->style->get('height', 'auto') ?? 'auto';
        $verticalNonContent = $padding->vertical() + $border->vertical();

        if (strtolower(trim($heightValue)) === 'auto') {
            $contentHeight = $autoContentHeight;
        } else {
            $resolvedHeight = $this->resolveLength($heightValue, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            $contentHeight = ($styled->style->get('box-sizing') ?? 'content-box') === 'border-box'
                ? max(0.0, $resolvedHeight - $verticalNonContent)
                : max(0.0, $resolvedHeight);
        }

        $contentHeight = $this->applyVerticalConstraints(
            $styled,
            $contentHeight,
            $verticalNonContent,
            $containingWidth,
            $containingHeight,
            $fontSize,
        );

        return new LayoutNode(
            $styled,
            new LayoutBox(
                new Rect($contentX, $contentY, $contentWidth, $contentHeight),
                $padding,
                $border,
                $margin,
            ),
            $children,
            $fontSize,
        );
    }

    private function display(StyledNode $node): string
    {
        if ($node->node->type === 'text') {
            return 'inline';
        }
        return strtolower($node->style->get('display', 'inline') ?? 'inline');
    }

    private function isBlockLevel(StyledNode $node): bool
    {
        return in_array($this->display($node), ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true);
    }

    private function resolveFontSize(StyledNode $node, float $parentFontSize): float
    {
        $value = $node->style->get('font-size');
        if ($value === null) {
            return $parentFontSize;
        }

        return max(0.0, $this->resolveLength($value, $parentFontSize, $parentFontSize, $this->viewportWidth, $this->viewportHeight, 'zero'));
    }

    private function resolveEdges(
        StyledNode $node,
        string $prefix,
        float $widthReference,
        float $heightReference,
        float $fontSize,
    ): Edges {
        $shorthand = $node->style->get($prefix);
        $parts = $shorthand !== null ? preg_split('/\s+/', trim($shorthand)) ?: [] : [];
        [$st, $sr, $sb, $sl] = $this->expandFour($parts);

        return new Edges(
            $this->resolveLength($node->style->get($prefix . '-top', $st) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get($prefix . '-right', $sr) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get($prefix . '-bottom', $sb) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get($prefix . '-left', $sl) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
        );
    }

    private function resolveBorderEdges(
        StyledNode $node,
        float $widthReference,
        float $heightReference,
        float $fontSize,
    ): Edges {
        $shorthand = $node->style->get('border-width');
        $parts = $shorthand !== null ? preg_split('/\s+/', trim($shorthand)) ?: [] : [];
        [$st, $sr, $sb, $sl] = $this->expandFour($parts);

        return new Edges(
            $this->resolveLength($node->style->get('border-top-width', $st) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get('border-right-width', $sr) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get('border-bottom-width', $sb) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
            $this->resolveLength($node->style->get('border-left-width', $sl) ?? '0', $widthReference, $fontSize, $widthReference, $heightReference, 'zero'),
        );
    }

    /** @param list<string> $parts @return array{?string,?string,?string,?string} */
    private function expandFour(array $parts): array
    {
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null, $parts[3] ?? null],
        };
    }

    private function resolveLength(
        string $value,
        float $reference,
        float $fontSize,
        float $containerWidth,
        float $containerHeight,
        string $auto,
    ): float {
        $parsed = $this->lengthParser->parseLengthOrAuto($value);
        return LengthResolver::resolve(
            $parsed,
            $reference,
            $fontSize,
            self::ROOT_FONT_SIZE,
            $containerWidth,
            $containerHeight,
            $auto,
        );
    }

    private function applyHorizontalConstraints(
        StyledNode $node,
        float $contentWidth,
        float $nonContent,
        float $containingWidth,
        float $containingHeight,
        float $fontSize,
    ): float {
        foreach ([['min-width', true], ['max-width', false]] as [$property, $isMin]) {
            $value = $node->style->get($property);
            if ($value === null || strtolower(trim($value)) === 'none') continue;
            $resolved = $this->resolveLength($value, $containingWidth, $fontSize, $containingWidth, $containingHeight, 'zero');
            if (($node->style->get('box-sizing') ?? 'content-box') === 'border-box') {
                $resolved = max(0.0, $resolved - $nonContent);
            }
            $contentWidth = $isMin ? max($contentWidth, $resolved) : min($contentWidth, $resolved);
        }
        return max(0.0, $contentWidth);
    }

    private function applyVerticalConstraints(
        StyledNode $node,
        float $contentHeight,
        float $nonContent,
        float $containingWidth,
        float $containingHeight,
        float $fontSize,
    ): float {
        foreach ([['min-height', true], ['max-height', false]] as [$property, $isMin]) {
            $value = $node->style->get($property);
            if ($value === null || strtolower(trim($value)) === 'none') continue;
            $resolved = $this->resolveLength($value, $containingHeight, $fontSize, $containingWidth, $containingHeight, 'zero');
            if (($node->style->get('box-sizing') ?? 'content-box') === 'border-box') {
                $resolved = max(0.0, $resolved - $nonContent);
            }
            $contentHeight = $isMin ? max($contentHeight, $resolved) : min($contentHeight, $resolved);
        }
        return max(0.0, $contentHeight);
    }
}
