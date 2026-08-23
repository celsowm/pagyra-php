<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;
use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\PaginationResult;
use Pagyra\Style\ComputedStyle;

final class BorderPatternExpander
{
    private const EPSILON = 0.000001;

    /** @var array<int,array{first:int,last:int}> */
    private array $pageRanges = [];

    public function expand(DisplayList $displayList, PaginationResult $pagination): DisplayList
    {
        $this->pageRanges = $this->collectPageRanges($pagination);
        $pages = [];

        foreach ($displayList->pages as $page) {
            $commands = [];
            $skipBorders = [];

            foreach ($page->commands as $command) {
                if ($command instanceof BoxPaintCommand && $this->usesStrokeMode($command->node)) {
                    $commands[] = $command;
                    $key = $this->commandKey($command->node, $command->pageIndex);
                    $skipBorders[$key] = true;
                    array_push($commands, ...$this->strokedBorderSegments($command));
                    continue;
                }

                if (($command instanceof BorderPaintCommand || $command instanceof RoundedBorderPaintCommand)
                    && isset($skipBorders[$this->commandKey($command->node, $command->pageIndex)])) {
                    continue;
                }

                $commands[] = $command;
            }

            $pages[] = new PageDisplayList($page->pageIndex, $page->width, $page->height, $commands);
        }

        return new DisplayList($pages);
    }

    private function usesStrokeMode(LayoutNode|AtomicInlineBox $node): bool
    {
        $style = $this->style($node);
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if ($this->borderStyle($style, $side) !== 'solid') return true;
        }
        return false;
    }

    /** @return list<BorderPaintCommand> */
    private function strokedBorderSegments(BoxPaintCommand $command): array
    {
        $node = $command->node;
        $style = $this->style($node);
        $widths = $this->borderWidths($node);
        [$drawTop, $drawBottom] = $this->fragmentEdges($node, $command->pageIndex);

        $x = $command->x;
        $y = $command->y;
        $w = $command->width;
        $h = $command->height;
        if ($w <= 0.0 || $h <= 0.0) return [];

        $leftCenter = $x + $widths['left'] / 2.0;
        $rightCenter = $x + $w - $widths['right'] / 2.0;
        $topCenter = $y + ($drawTop ? $widths['top'] / 2.0 : 0.0);
        $bottomCenter = $y + $h - ($drawBottom ? $widths['bottom'] / 2.0 : 0.0);

        $segments = [];
        if ($drawTop) {
            array_push($segments, ...$this->sideSegments(
                $node, $command->pageIndex, 'top', $style, $widths['top'],
                $leftCenter, $topCenter, $rightCenter, $topCenter,
            ));
        }
        array_push($segments, ...$this->sideSegments(
            $node, $command->pageIndex, 'right', $style, $widths['right'],
            $rightCenter, $topCenter, $rightCenter, $bottomCenter,
        ));
        if ($drawBottom) {
            array_push($segments, ...$this->sideSegments(
                $node, $command->pageIndex, 'bottom', $style, $widths['bottom'],
                $rightCenter, $bottomCenter, $leftCenter, $bottomCenter,
            ));
        }
        array_push($segments, ...$this->sideSegments(
            $node, $command->pageIndex, 'left', $style, $widths['left'],
            $leftCenter, $bottomCenter, $leftCenter, $topCenter,
        ));

        return $segments;
    }

    /** @return list<BorderPaintCommand> */
    private function sideSegments(
        LayoutNode|AtomicInlineBox $node,
        int $pageIndex,
        string $side,
        ComputedStyle $style,
        float $lineWidth,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
    ): array {
        if ($lineWidth <= self::EPSILON) return [];
        $borderStyle = $this->borderStyle($style, $side);
        if (in_array($borderStyle, ['none', 'hidden'], true)) return [];
        $color = $this->borderColor($style, $side);
        if (!$color instanceof Rgba || $color->a <= 0.0) return [];

        $pattern = match ($borderStyle) {
            'dashed' => [3.0 * $lineWidth, 3.0 * $lineWidth],
            'dotted' => [$lineWidth, $lineWidth],
            default => null,
        };

        return $this->rectanglesAlongLine(
            $node, $pageIndex, $side, $x1, $y1, $x2, $y2, $lineWidth, $color, $pattern,
        );
    }

    /**
     * @param list<float>|null $pattern
     * @return list<BorderPaintCommand>
     */
    private function rectanglesAlongLine(
        LayoutNode|AtomicInlineBox $node,
        int $pageIndex,
        string $side,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lineWidth,
        Rgba $color,
        ?array $pattern,
    ): array {
        $horizontal = abs($y2 - $y1) <= self::EPSILON;
        $length = $horizontal ? abs($x2 - $x1) : abs($y2 - $y1);
        if ($length <= self::EPSILON) return [];

        if ($pattern === null) {
            return [$this->segmentRect($node, $pageIndex, $side, $x1, $y1, $x2, $y2, $lineWidth, $color)];
        }

        $on = max(0.0, $pattern[0] ?? 0.0);
        $off = max(0.0, $pattern[1] ?? 0.0);
        if ($on <= self::EPSILON || $on + $off <= self::EPSILON) return [];

        $segments = [];
        $position = 0.0;
        $direction = $horizontal
            ? ($x2 >= $x1 ? 1.0 : -1.0)
            : ($y2 >= $y1 ? 1.0 : -1.0);

        while ($position < $length - self::EPSILON) {
            $visible = min($on, $length - $position);
            if ($horizontal) {
                $start = $x1 + $direction * $position;
                $end = $x1 + $direction * ($position + $visible);
                $segments[] = $this->segmentRect($node, $pageIndex, $side, $start, $y1, $end, $y1, $lineWidth, $color);
            } else {
                $start = $y1 + $direction * $position;
                $end = $y1 + $direction * ($position + $visible);
                $segments[] = $this->segmentRect($node, $pageIndex, $side, $x1, $start, $x1, $end, $lineWidth, $color);
            }
            $position += $on + $off;
        }

        return $segments;
    }

    private function segmentRect(
        LayoutNode|AtomicInlineBox $node,
        int $pageIndex,
        string $side,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lineWidth,
        Rgba $color,
    ): BorderPaintCommand {
        if (abs($y2 - $y1) <= self::EPSILON) {
            return new BorderPaintCommand(
                $node,
                $pageIndex,
                $side,
                min($x1, $x2),
                $y1 - $lineWidth / 2.0,
                abs($x2 - $x1),
                $lineWidth,
                $color,
            );
        }

        return new BorderPaintCommand(
            $node,
            $pageIndex,
            $side,
            $x1 - $lineWidth / 2.0,
            min($y1, $y2),
            $lineWidth,
            abs($y2 - $y1),
            $color,
        );
    }

    /** @return array{top:float,right:float,bottom:float,left:float} */
    private function borderWidths(LayoutNode|AtomicInlineBox $node): array
    {
        if ($node instanceof LayoutNode) {
            return [
                'top' => max(0.0, $node->box->border->top),
                'right' => max(0.0, $node->box->border->right),
                'bottom' => max(0.0, $node->box->border->bottom),
                'left' => max(0.0, $node->box->border->left),
            ];
        }
        return [
            'top' => max(0.0, (float) $node->border['top']),
            'right' => max(0.0, (float) $node->border['right']),
            'bottom' => max(0.0, (float) $node->border['bottom']),
            'left' => max(0.0, (float) $node->border['left']),
        ];
    }

    private function style(LayoutNode|AtomicInlineBox $node): ComputedStyle
    {
        return $node instanceof LayoutNode ? $node->source->style : $node->style;
    }

    private function borderStyle(ComputedStyle $style, string $side): string
    {
        $specific = $style->get('border-' . $side . '-style');
        if ($specific !== null && trim($specific) !== '') return strtolower(trim($specific));
        $parts = $this->expandFour($style->get('border-style', 'none') ?? 'none');
        $index = array_search($side, ['top', 'right', 'bottom', 'left'], true);
        return strtolower($parts[$index === false ? 0 : $index] ?? 'none');
    }

    private function borderColor(ComputedStyle $style, string $side): ?Rgba
    {
        $specific = trim($style->get('border-' . $side . '-color') ?? '');
        $raw = $specific !== '' ? $specific : trim($style->get('border-color') ?? '');
        if ($specific === '' && $raw !== '' && !str_contains($raw, '(')) {
            $parts = preg_split('/\s+/', $raw) ?: [];
            if (count($parts) > 1) {
                $expanded = $this->expandFour(implode(' ', $parts));
                $index = array_search($side, ['top', 'right', 'bottom', 'left'], true);
                $raw = $expanded[$index === false ? 0 : $index] ?? $raw;
            }
        }
        if ($raw === '' || strtolower($raw) === 'currentcolor') {
            $raw = $style->get('color', 'black') ?? 'black';
        }
        return ColorParser::parse($raw);
    }

    /** @return list<string> */
    private function expandFour(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: ['none'];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    /** @return array{0:bool,1:bool} */
    private function fragmentEdges(LayoutNode|AtomicInlineBox $node, int $pageIndex): array
    {
        if ($node instanceof AtomicInlineBox) return [true, true];
        $range = $this->pageRanges[spl_object_id($node)] ?? null;
        if ($range === null) return [true, true];
        return [$pageIndex === $range['first'], $pageIndex === $range['last']];
    }

    /** @return array<int,array{first:int,last:int}> */
    private function collectPageRanges(PaginationResult $pagination): array
    {
        $pagesByNode = [];
        foreach ($pagination->pages as $page) {
            foreach ($page->entries as $entry) {
                $this->recordPage($pagesByNode, $entry->placement->node, $page->pageIndex);
                foreach ($entry->fragment->blocks as $block) {
                    $this->collectBlockPages($pagesByNode, $block);
                }
            }
        }

        $ranges = [];
        foreach ($pagesByNode as $id => $pages) {
            $ranges[$id] = ['first' => min($pages), 'last' => max($pages)];
        }
        return $ranges;
    }

    /** @param array<int,list<int>> $pagesByNode */
    private function collectBlockPages(array &$pagesByNode, BlockFragment $fragment): void
    {
        $this->recordPage($pagesByNode, $fragment->node, $fragment->pageIndex);
        foreach ($fragment->children as $child) $this->collectBlockPages($pagesByNode, $child);
    }

    /** @param array<int,list<int>> $pagesByNode */
    private function recordPage(array &$pagesByNode, LayoutNode $node, int $pageIndex): void
    {
        $id = spl_object_id($node);
        $pagesByNode[$id] ??= [];
        if (!in_array($pageIndex, $pagesByNode[$id], true)) $pagesByNode[$id][] = $pageIndex;
    }

    private function commandKey(LayoutNode|AtomicInlineBox $node, int $pageIndex): string
    {
        return spl_object_id($node) . ':' . $pageIndex;
    }
}
