<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Css\Color\Rgba;
use Pagyra\Geometry\Rect;
use Pagyra\Image\ImageMetadataReader;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Image\ObjectFit;
use Pagyra\Image\ObjectFitResolver;
use Pagyra\Image\ObjectPositionParser;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;
use Pagyra\Layout\LineBox;
use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\LineFragment;
use Pagyra\Pagination\PaginationResult;
use Pagyra\Pagination\PhysicalPageEntry;

final class DisplayListBuilder
{
    private const EPSILON = 0.000001;

    private readonly ImageMetadataReader $imageMetadata;

    public function __construct(
        private readonly ?ImageSourceBytesResolver $imageBytes = null,
    ) {
        $this->imageMetadata = new ImageMetadataReader();
    }

    /** @param array<string,mixed> $margins */
    public function build(
        PaginationResult $pagination,
        float $pageWidth,
        float $pageHeight,
        array $margins,
    ): DisplayList {
        $pages = [];
        foreach ($pagination->pages as $page) {
            $commands = [];
            $pageMargins = $this->marginsForPage($margins, $page->pageIndex);
            foreach ($page->entries as $entry) {
                $this->appendEntry($commands, $entry, $pagination, $pageMargins);
            }
            $pages[] = new PageDisplayList($page->pageIndex, $pageWidth, $pageHeight, $commands);
        }
        return new DisplayList($pages);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendEntry(array &$commands, PhysicalPageEntry $entry, PaginationResult $pagination, array $margins): void
    {
        $node = $entry->placement->node;
        $pageIndex = $entry->fragment->pageIndex;
        $this->appendTopLevelBox($commands, $node, $pageIndex, $entry->placement->offsetY, $pagination, $margins);
        $this->appendLines($commands, $entry->fragment->lines, $margins);
        foreach ($entry->fragment->blocks as $block) $this->appendBlock($commands, $block, $margins);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendTopLevelBox(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $offsetY,
        PaginationResult $pagination,
        array $margins,
    ): void {
        $border = $node->box->borderBox();
        $continuousStart = $border->y + $offsetY;
        $continuousEnd = $border->bottom() + $offsetY;
        $pageStart = $pagination->flow->contentStartForPage($pageIndex);
        $pageEnd = $pageStart + $pagination->flow->usableHeightForPage($pageIndex);
        $start = max($continuousStart, $pageStart);
        $end = min($continuousEnd, $pageEnd);
        if ($end <= $start) return;

        $x = $border->x + $margins['left'];
        $y = ($start - $pageStart) + $margins['top'];
        $width = $border->width;
        $height = $end - $start;
        $drawTop = abs($start - $continuousStart) <= self::EPSILON;
        $drawBottom = abs($end - $continuousEnd) <= self::EPSILON;
        $radius = $this->fragmentRadius(
            BorderRadiusResolver::resolve($node->source->style, $border->width, $border->height),
            $drawTop,
            $drawBottom,
        );

        $commands[] = new BoxPaintCommand(
            node: $node,
            pageIndex: $pageIndex,
            x: $x,
            y: $y,
            width: $width,
            height: $height,
            backgroundColor: ColorParser::parse($node->source->style->get('background-color')),
            borderRadius: BorderRadiusResolver::normalize($radius, $width, $height),
        );
        $this->appendBorders($commands, $node, $pageIndex, $x, $y, $width, $height, $drawTop, $drawBottom);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBlock(array &$commands, BlockFragment $block, array $margins): void
    {
        $border = $block->node->box->borderBox();
        if ($block->height > 0.0) {
            $x = $border->x + $margins['left'];
            $y = $block->pageY + $margins['top'];
            $wholeBox = $block->height + self::EPSILON >= $border->height;
            $radius = $wholeBox
                ? BorderRadiusResolver::resolve($block->node->source->style, $border->width, $border->height)
                : new BorderRadius();
            $commands[] = new BoxPaintCommand(
                node: $block->node,
                pageIndex: $block->pageIndex,
                x: $x,
                y: $y,
                width: $border->width,
                height: $block->height,
                backgroundColor: ColorParser::parse($block->node->source->style->get('background-color')),
                borderRadius: BorderRadiusResolver::normalize($radius, $border->width, $block->height),
            );

            $this->appendBorders(
                $commands,
                $block->node,
                $block->pageIndex,
                $x,
                $y,
                $border->width,
                $block->height,
                $wholeBox,
                $wholeBox,
            );
        }
        $this->appendLines($commands, $block->lines, $margins);
        foreach ($block->children as $child) $this->appendBlock($commands, $child, $margins);
    }

    private function fragmentRadius(BorderRadius $radius, bool $drawTop, bool $drawBottom): BorderRadius
    {
        return new BorderRadius(
            $drawTop ? $radius->topLeft : new CornerRadius(),
            $drawTop ? $radius->topRight : new CornerRadius(),
            $drawBottom ? $radius->bottomRight : new CornerRadius(),
            $drawBottom ? $radius->bottomLeft : new CornerRadius(),
        );
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBorders(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $drawTop,
        bool $drawBottom,
    ): void {
        if ($width <= 0.0 || $height <= 0.0) return;
        if ($drawTop && $drawBottom && $this->appendRoundedUniformBorder($commands, $node, $pageIndex, $x, $y, $width, $height)) return;

        $edges = $node->box->border;
        $top = max(0.0, min($edges->top, $height));
        $right = max(0.0, min($edges->right, $width));
        $bottom = max(0.0, min($edges->bottom, $height));
        $left = max(0.0, min($edges->left, $width));

        if ($drawTop && $top > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'top', $x, $y, $width, $top);
        if ($drawBottom && $bottom > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'bottom', $x, $y + $height - $bottom, $width, $bottom);

        $sideY = $y + ($drawTop ? $top : 0.0);
        $sideHeight = max(0.0, $height - ($drawTop ? $top : 0.0) - ($drawBottom ? $bottom : 0.0));
        if ($left > 0.0 && $sideHeight > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'left', $x, $sideY, $left, $sideHeight);
        if ($right > 0.0 && $sideHeight > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'right', $x + $width - $right, $sideY, $right, $sideHeight);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendRoundedUniformBorder(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
    ): bool {
        $edges = $node->box->border;
        $borderWidth = $edges->top;
        if ($borderWidth <= 0.0
            || abs($edges->right - $borderWidth) > self::EPSILON
            || abs($edges->bottom - $borderWidth) > self::EPSILON
            || abs($edges->left - $borderWidth) > self::EPSILON) return false;

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if ($this->borderStyle($node, $side) !== 'solid') return false;
        }
        $colors = array_map(fn (string $side): ?Rgba => $this->borderColor($node, $side), ['top', 'right', 'bottom', 'left']);
        if (!$colors[0] instanceof Rgba || $colors[0]->a <= 0.0) return false;
        foreach (array_slice($colors, 1) as $color) {
            if (!$color instanceof Rgba || !$this->sameColor($colors[0], $color)) return false;
        }

        $outer = BorderRadiusResolver::resolve($node->source->style, $width, $height);
        if ($outer->isZero()) return false;
        $innerWidth = max(0.0, $width - 2.0 * $borderWidth);
        $innerHeight = max(0.0, $height - 2.0 * $borderWidth);
        $inner = BorderRadiusResolver::normalize(new BorderRadius(
            new CornerRadius(max(0.0, $outer->topLeft->x - $borderWidth), max(0.0, $outer->topLeft->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->topRight->x - $borderWidth), max(0.0, $outer->topRight->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->bottomRight->x - $borderWidth), max(0.0, $outer->bottomRight->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->bottomLeft->x - $borderWidth), max(0.0, $outer->bottomLeft->y - $borderWidth)),
        ), $innerWidth, $innerHeight);

        $commands[] = new RoundedBorderPaintCommand($node, $pageIndex, $x, $y, $width, $height, $borderWidth, $colors[0], $outer, $inner);
        return true;
    }

    private function sameColor(Rgba $a, Rgba $b): bool
    {
        return abs($a->r - $b->r) <= self::EPSILON
            && abs($a->g - $b->g) <= self::EPSILON
            && abs($a->b - $b->b) <= self::EPSILON
            && abs($a->a - $b->a) <= self::EPSILON;
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBorderSide(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        string $side,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        if ($this->borderStyle($node, $side) !== 'solid') return;
        $color = $this->borderColor($node, $side);
        if (!$color instanceof Rgba || $color->a <= 0.0) return;
        $commands[] = new BorderPaintCommand($node, $pageIndex, $side, $x, $y, $width, $height, $color);
    }

    private function borderStyle(LayoutNode $node, string $side): string
    {
        return $this->styleBorderStyle($node->source->style, $side);
    }

    private function borderColor(LayoutNode $node, string $side): ?Rgba
    {
        return $this->styleBorderColor($node->source->style, $side);
    }

    /** @param list<string> $parts @return list<string> */
    private function expandFourValues(array $parts): array
    {
        if ($parts === []) return ['', '', '', ''];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    /**
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     * @param list<LineFragment> $lines
     */
    private function appendLines(array &$commands, array $lines, array $margins): void
    {
        foreach ($lines as $lineFragment) {
            $line = $lineFragment->line;
            foreach ($line->runs as $run) {
                $weightRaw = strtolower(trim($run->style->get('font-weight', '400') ?? '400'));
                $fontWeight = $weightRaw === 'bold' ? 700 : ($weightRaw === 'normal' ? 400 : (is_numeric($weightRaw) ? (int) $weightRaw : 400));
                $commands[] = new TextPaintCommand(
                    run: $run,
                    pageIndex: $lineFragment->pageIndex,
                    x: $run->x + $margins['left'],
                    y: $lineFragment->pageY + ($run->y - $line->y) + $margins['top'],
                    baseline: $lineFragment->pageBaseline + ($run->baseline - $line->baseline) + $margins['top'],
                    text: $run->text,
                    fontSize: $run->fontSize,
                    fontFamily: $run->style->get('font-family'),
                    fontWeight: max(100, min(900, $fontWeight)),
                    fontStyle: strtolower(trim($run->style->get('font-style', 'normal') ?? 'normal')),
                    color: ColorParser::parse($run->style->get('color', 'black')),
                );
            }

            foreach ($line->atomicBoxes as $box) {
                $this->appendAtomicBox($commands, $box, $line, $lineFragment, $margins);
            }
        }
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBox(array &$commands, AtomicInlineBox $box, LineBox $line, LineFragment $lineFragment, array $margins): void
    {
        $outerPageY = $lineFragment->pageY + ($box->y - $line->y) + $margins['top'];
        $borderX = $box->x + $box->margin['left'] + $margins['left'];
        $borderY = $outerPageY + $box->margin['top'];
        $borderWidth = $box->contentWidth + $box->padding['left'] + $box->padding['right'] + $box->border['left'] + $box->border['right'];
        $borderHeight = $box->contentHeight + $box->padding['top'] + $box->padding['bottom'] + $box->border['top'] + $box->border['bottom'];

        if ($borderWidth > 0.0 && $borderHeight > 0.0) {
            $radius = BorderRadiusResolver::resolve($box->style, $borderWidth, $borderHeight);
            $commands[] = new BoxPaintCommand(
                node: $box,
                pageIndex: $lineFragment->pageIndex,
                x: $borderX,
                y: $borderY,
                width: $borderWidth,
                height: $borderHeight,
                backgroundColor: ColorParser::parse($box->style->get('background-color')),
                borderRadius: $radius,
            );
            $this->appendAtomicBorders($commands, $box, $lineFragment->pageIndex, $borderX, $borderY, $borderWidth, $borderHeight, $radius);
        }

        if ($box->source->node->isElement('img')) {
            $this->appendAtomicImage($commands, $box, $line, $lineFragment, $margins);
        }

        if ($box->contentLines !== []) {
            $nested = [];
            foreach ($box->contentLines as $index => $contentLine) {
                $pageY = $lineFragment->pageY + ($contentLine->y - $line->y);
                $pageBaseline = $lineFragment->pageY + ($contentLine->baseline - $line->y);
                $nested[] = new LineFragment(
                    line: $contentLine,
                    lineIndex: $index,
                    pageIndex: $lineFragment->pageIndex,
                    pageY: $pageY,
                    pageBaseline: $pageBaseline,
                    continuousY: $contentLine->y,
                    continuousBaseline: $contentLine->baseline,
                );
            }
            $this->appendLines($commands, $nested, $margins);
        }
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBorders(
        array &$commands,
        AtomicInlineBox $box,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        BorderRadius $outerRadius,
    ): void {
        $edges = $box->border;
        $top = max(0.0, min($edges['top'], $height));
        $right = max(0.0, min($edges['right'], $width));
        $bottom = max(0.0, min($edges['bottom'], $height));
        $left = max(0.0, min($edges['left'], $width));

        if ($top > 0.0
            && abs($right - $top) <= self::EPSILON
            && abs($bottom - $top) <= self::EPSILON
            && abs($left - $top) <= self::EPSILON
            && !$outerRadius->isZero()) {
            $styles = array_map(fn(string $side): string => $this->styleBorderStyle($box->style, $side), ['top', 'right', 'bottom', 'left']);
            $colors = array_map(fn(string $side): ?Rgba => $this->styleBorderColor($box->style, $side), ['top', 'right', 'bottom', 'left']);
            if (count(array_unique($styles)) === 1 && $styles[0] === 'solid' && $colors[0] instanceof Rgba && $colors[0]->a > 0.0) {
                $same = true;
                foreach (array_slice($colors, 1) as $color) {
                    if (!$color instanceof Rgba || !$this->sameColor($colors[0], $color)) { $same = false; break; }
                }
                if ($same) {
                    $innerWidth = max(0.0, $width - 2.0 * $top);
                    $innerHeight = max(0.0, $height - 2.0 * $top);
                    $inner = BorderRadiusResolver::normalize(new BorderRadius(
                        new CornerRadius(max(0.0, $outerRadius->topLeft->x - $top), max(0.0, $outerRadius->topLeft->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->topRight->x - $top), max(0.0, $outerRadius->topRight->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->bottomRight->x - $top), max(0.0, $outerRadius->bottomRight->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->bottomLeft->x - $top), max(0.0, $outerRadius->bottomLeft->y - $top)),
                    ), $innerWidth, $innerHeight);
                    $commands[] = new RoundedBorderPaintCommand($box, $pageIndex, $x, $y, $width, $height, $top, $colors[0], $outerRadius, $inner);
                    return;
                }
            }
        }

        if ($top > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'top', $x, $y, $width, $top);
        if ($bottom > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'bottom', $x, $y + $height - $bottom, $width, $bottom);
        $sideY = $y + $top;
        $sideHeight = max(0.0, $height - $top - $bottom);
        if ($left > 0.0 && $sideHeight > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'left', $x, $sideY, $left, $sideHeight);
        if ($right > 0.0 && $sideHeight > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'right', $x + $width - $right, $sideY, $right, $sideHeight);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBorderSide(array &$commands, AtomicInlineBox $box, int $pageIndex, string $side, float $x, float $y, float $width, float $height): void
    {
        if ($this->styleBorderStyle($box->style, $side) !== 'solid') return;
        $color = $this->styleBorderColor($box->style, $side);
        if (!$color instanceof Rgba || $color->a <= 0.0) return;
        $commands[] = new BorderPaintCommand($box, $pageIndex, $side, $x, $y, $width, $height, $color);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicImage(array &$commands, AtomicInlineBox $box, LineBox $line, LineFragment $lineFragment, array $margins): void
    {
        if ($this->imageBytes === null) return;
        $source = $box->source->node->attribute('src');
        $bytes = $this->imageBytes->resolve($source);
        if ($source === null || $bytes === null) return;
        try {
            $metadata = $this->imageMetadata->read($bytes);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (!in_array($metadata->format, ['jpeg', 'png'], true)) return;
        if ($box->contentWidth <= 0.0 || $box->contentHeight <= 0.0) return;

        $contentX = $box->x + $box->margin['left'] + $box->border['left'] + $box->padding['left'] + $margins['left'];
        $contentY = $lineFragment->pageY
            + (($box->y + $box->margin['top'] + $box->border['top'] + $box->padding['top']) - $line->y)
            + $margins['top'];
        $contentBox = new Rect($contentX, $contentY, $box->contentWidth, $box->contentHeight);
        $fitRaw = strtolower(trim($box->style->get('object-fit', 'fill') ?? 'fill'));
        $fit = ObjectFit::tryFrom($fitRaw) ?? ObjectFit::Fill;
        $position = ObjectPositionParser::parse($box->style->get('object-position'));
        $destination = ObjectFitResolver::resolve($metadata->width, $metadata->height, $contentBox, $fit, $position);
        $clipRect = ObjectFitResolver::needsClip($destination, $contentBox) ? $contentBox : null;

        $commands[] = new ImagePaintCommand(
            box: $box,
            pageIndex: $lineFragment->pageIndex,
            x: $destination->x,
            y: $destination->y,
            width: $destination->width,
            height: $destination->height,
            bytes: $bytes,
            metadata: $metadata,
            source: $source,
            clipRect: $clipRect,
        );
    }

    private function styleBorderStyle(\Pagyra\Style\ComputedStyle $style, string $side): string
    {
        $specific = $style->get('border-' . $side . '-style');
        if ($specific !== null && trim($specific) !== '') return strtolower(trim($specific));
        $raw = trim($style->get('border-style', 'none') ?? 'none');
        $parts = preg_split('/\s+/', $raw) ?: ['none'];
        $expanded = $this->expandFourValues($parts);
        return strtolower($expanded[array_search($side, ['top', 'right', 'bottom', 'left'], true)] ?? 'none');
    }

    private function styleBorderColor(\Pagyra\Style\ComputedStyle $style, string $side): ?Rgba
    {
        $specific = trim($style->get('border-' . $side . '-color') ?? '');
        $raw = $specific !== '' ? $specific : trim($style->get('border-color') ?? '');
        if ($specific === '' && $raw !== '' && !str_contains($raw, '(')) {
            $parts = preg_split('/\s+/', $raw) ?: [];
            if (count($parts) > 1) {
                $expanded = $this->expandFourValues($parts);
                $raw = $expanded[array_search($side, ['top', 'right', 'bottom', 'left'], true)] ?? $raw;
            }
        }
        if ($raw === '' || strtolower($raw) === 'currentcolor') $raw = $style->get('color', 'black') ?? 'black';
        return ColorParser::parse($raw);
    }

    /** @param array<string,mixed> $profile @return array{top:float,right:float,bottom:float,left:float} */
    private function marginsForPage(array $profile, int $pageIndex): array
    {
        if (!isset($profile['default']) || !is_array($profile['default'])) {
            return [
                'top' => (float) ($profile['top'] ?? 0.0),
                'right' => (float) ($profile['right'] ?? 0.0),
                'bottom' => (float) ($profile['bottom'] ?? 0.0),
                'left' => (float) ($profile['left'] ?? 0.0),
            ];
        }

        $index = max(0, $pageIndex);
        if ($index === 0 && isset($profile['first']) && is_array($profile['first'])) $resolved = $profile['first'];
        elseif ($index % 2 === 0 && isset($profile['right']) && is_array($profile['right'])) $resolved = $profile['right'];
        elseif ($index % 2 === 1 && isset($profile['left']) && is_array($profile['left'])) $resolved = $profile['left'];
        else $resolved = $profile['default'];

        return [
            'top' => (float) ($resolved['top'] ?? 0.0),
            'right' => (float) ($resolved['right'] ?? 0.0),
            'bottom' => (float) ($resolved['bottom'] ?? 0.0),
            'left' => (float) ($resolved['left'] ?? 0.0),
        ];
    }
}
