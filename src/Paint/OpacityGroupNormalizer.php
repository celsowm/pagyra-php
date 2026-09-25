<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;

/**
 * Removes opacity already represented by enclosing OpacityGroupPaintCommand scopes from each
 * primitive's alpha. The resulting display list is visually identical under the old per-command
 * model only when groups are ignored; with PDF transparency groups, the factored alpha is applied
 * exactly once to the composited subtree.
 */
final class OpacityGroupNormalizer
{
    private const EPSILON = 1e-9;

    public function normalize(DisplayList $displayList): DisplayList
    {
        $pages = [];
        foreach ($displayList->pages as $page) {
            $factorStack = [];
            $factor = 1.0;
            $commands = [];

            foreach ($page->commands as $command) {
                if ($command instanceof OpacityGroupPaintCommand) {
                    if ($command->opens()) {
                        $factorStack[] = $command->normalizedOpacity();
                        $factor *= $command->normalizedOpacity();
                    } else {
                        $last = array_pop($factorStack);
                        if ($last !== null) {
                            $factor = 1.0;
                            foreach ($factorStack as $active) $factor *= $active;
                        }
                    }
                    $commands[] = $command;
                    continue;
                }

                $commands[] = $this->normalizeCommand($command, $factor);
            }

            $pages[] = new PageDisplayList($page->pageIndex, $page->width, $page->height, $commands);
        }

        return new DisplayList($pages);
    }

    private function normalizeCommand(object $command, float $factor): object
    {
        if ($factor >= 1.0 - self::EPSILON || $factor <= self::EPSILON) {
            return $command;
        }

        if ($command instanceof BoxPaintCommand) {
            return new BoxPaintCommand(
                $command->node,
                $command->pageIndex,
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $this->normalizeColor($command->backgroundColor, $factor),
                $command->borderRadius,
                $command->decorative,
            );
        }

        if ($command instanceof BorderPaintCommand) {
            return new BorderPaintCommand(
                $command->node,
                $command->pageIndex,
                $command->side,
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $this->normalizeColor($command->color, $factor) ?? $command->color,
            );
        }

        if ($command instanceof RoundedBorderPaintCommand) {
            return new RoundedBorderPaintCommand(
                $command->node,
                $command->pageIndex,
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $command->borderWidth,
                $this->normalizeColor($command->color, $factor) ?? $command->color,
                $command->outerRadius,
                $command->innerRadius,
            );
        }

        if ($command instanceof TextPaintCommand) {
            return new TextPaintCommand(
                $command->run,
                $command->pageIndex,
                $command->x,
                $command->y,
                $command->baseline,
                $command->text,
                $command->fontSize,
                $command->fontFamily,
                $command->fontWeight,
                $command->fontStyle,
                $this->normalizeColor($command->color, $factor),
                $command->underline,
                $command->lineThrough,
                $command->linkHref,
                $command->overline,
                $command->decorationStyle,
                $this->normalizeColor($command->decorationColor, $factor),
            );
        }

        if ($command instanceof ImagePaintCommand) {
            return new ImagePaintCommand(
                $command->box,
                $command->pageIndex,
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $command->bytes,
                $command->metadata,
                $command->source,
                $command->clipRect,
                $command->clipRadius,
                min(1.0, $command->opacity / $factor),
            );
        }

        if ($command instanceof SvgPathPaintCommand) {
            return new SvgPathPaintCommand(
                $command->box,
                $command->pageIndex,
                $command->segments,
                $this->normalizeColor($command->fill, $factor),
                $this->normalizeColor($command->stroke, $factor),
                $command->strokeWidth,
                $command->fillRule,
            );
        }

        if ($command instanceof GradientPaintCommand) {
            $stops = array_map(
                fn(array $stop): array => [$stop[0], $this->normalizeColor($stop[1], $factor) ?? $stop[1]],
                $command->stops,
            );
            return new GradientPaintCommand(
                $command->node,
                $command->pageIndex,
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $command->kind,
                $command->geometry,
                $stops,
            );
        }

        return $command;
    }

    private function normalizeColor(?Rgba $color, float $factor): ?Rgba
    {
        if (!$color instanceof Rgba || $factor <= self::EPSILON) return $color;

        return new Rgba(
            $color->r,
            $color->g,
            $color->b,
            max(0.0, min(1.0, $color->a / $factor)),
        );
    }
}
