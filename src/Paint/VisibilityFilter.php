<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;
use Pagyra\Style\ComputedStyle;

final class VisibilityFilter
{
    public function apply(DisplayList $displayList): DisplayList
    {
        $pages = [];
        foreach ($displayList->pages as $page) {
            $commands = array_values(array_filter(
                $page->commands,
                fn (object $command): bool => $this->isVisible($command),
            ));
            $pages[] = new PageDisplayList($page->pageIndex, $page->width, $page->height, $commands);
        }
        return new DisplayList($pages);
    }

    private function isVisible(object $command): bool
    {
        $style = $this->styleFor($command);
        if ($style === null) return true;
        $visibility = strtolower(trim($style->get('visibility', 'visible') ?? 'visible'));
        return !in_array($visibility, ['hidden', 'collapse'], true);
    }

    private function styleFor(object $command): ?ComputedStyle
    {
        if ($command instanceof TextPaintCommand) return $command->run->style;
        if ($command instanceof ImagePaintCommand) return $command->box->style;
        if ($command instanceof BoxPaintCommand || $command instanceof BorderPaintCommand || $command instanceof RoundedBorderPaintCommand) {
            $node = $command->node;
            if ($node instanceof LayoutNode) return $node->source->style;
            if ($node instanceof AtomicInlineBox) return $node->style;
        }
        return null;
    }
}
