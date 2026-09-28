<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/**
 * Drops pages at the end of the document that would come out with nothing on them.
 *
 * Documents written in word processors often end with a run of empty paragraphs
 * (`<p>&nbsp;</p>`) after the signature. They have height, so when the text above ends near the
 * bottom of a page they overflow onto a new one, which then holds only a no-break space per line
 * and a transparent box per paragraph: a blank sheet at the end of the PDF.
 *
 * Only trailing pages are removed, never the first one, and never one a forced break asked for
 * (`break-before: page` onto an empty block, `break-before: right` skipping a page): those pages
 * are the author's, even when they come out empty. A blank page in the middle is kept for the
 * same reason. A page counts as blank only when every command on it is known to leave no mark:
 * text made of whitespace with no decoration line, or a box with no background. Anything else —
 * border, image, gradient, SVG, clip or group — keeps the page.
 */
final class TrailingBlankPageTrimmer
{
    private const WHITESPACE = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/u';

    /** @param int $keepThroughPage last page index a forced break sent content to (-1: none) */
    public function trim(DisplayList $displayList, int $keepThroughPage = -1): DisplayList
    {
        $pages = $displayList->pages;
        while (
            count($pages) > 1
            && array_key_last($pages) > $keepThroughPage
            && $this->isBlank($pages[array_key_last($pages)])
        ) {
            array_pop($pages);
        }
        return count($pages) === count($displayList->pages) ? $displayList : new DisplayList($pages);
    }

    private function isBlank(PageDisplayList $page): bool
    {
        foreach ($page->commands as $command) {
            if (!$this->leavesNoMark($command)) return false;
        }
        return true;
    }

    private function leavesNoMark(object $command): bool
    {
        if ($command instanceof BoxPaintCommand) {
            return $command->backgroundColor === null || $command->backgroundColor->a <= 0.0;
        }
        if ($command instanceof TextPaintCommand) {
            if ($command->underline || $command->lineThrough || $command->overline) return false;
            return preg_match(self::WHITESPACE, $command->text) === 1;
        }
        return false;
    }
}
