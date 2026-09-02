<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * CSS 2.1 8.3.1: a block's top margin collapses with the top margin of its first in-flow child
 * when no top border or padding separates them, and an auto-height block's bottom margin
 * collapses with its last child's. Only sibling margins collapsed here before, so the eproc
 * ementa pattern `<ol><li class=x><p class=x>` — where all three carry `margin: 5mm 0` — stacked
 * three margins at each end of every list item instead of one, adding a whole page of blank
 * space to a twelve-item ementa.
 */
final class ParentChildMarginCollapsingTest extends TestCase
{
    /** @return array<string,\Pagyra\Layout\LayoutNode> */
    private function boxes(string $html, string $css): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'css' => $css,
            'viewportWidth' => 500,
            'viewportHeight' => 900,
        ]);

        $found = [];
        $walk = function ($node) use (&$walk, &$found): void {
            $tag = $node->source->node->tagName ?? null;
            if ($tag !== null && !isset($found[$tag])) $found[$tag] = $node;
            foreach ($node->children as $child) $walk($child);
        };
        $walk($prepared->layoutRoot);

        return $found;
    }

    public function testNestedFirstChildrenShareOneTopMargin(): void
    {
        $boxes = $this->boxes(
            '<ol><li><p>item</p></li></ol>',
            '*{font-size:12px;line-height:1.2} p{margin:20px 0} ol{margin:20px 0;padding-left:40px} li{margin:20px 0}',
        );

        // One 20px margin, not three stacked.
        self::assertSame(20.0, $boxes['ol']->box->content->y);
        self::assertSame(20.0, $boxes['li']->box->content->y);
        self::assertSame(20.0, $boxes['p']->box->content->y);
    }

    public function testLastChildBottomMarginEscapesTheAutoHeightParent(): void
    {
        $boxes = $this->boxes(
            '<ol><li><p>item</p></li></ol>',
            '*{font-size:12px;line-height:1.2} p{margin:20px 0} ol{margin:20px 0;padding-left:40px} li{margin:20px 0}',
        );

        // The <ol> is exactly as tall as the line; the trailing margin sits outside it.
        self::assertSame($boxes['p']->box->content->height, $boxes['ol']->box->content->height);
        self::assertSame(20.0, $boxes['ol']->box->margin->bottom);
    }

    public function testFollowingSiblingIsSpacedByASingleCollapsedMargin(): void
    {
        $boxes = $this->boxes(
            '<ol><li><p>item</p></li></ol><div id="after">depois</div>',
            '*{font-size:12px;line-height:1.2} p{margin:20px 0} ol{margin:20px 0;padding-left:40px} li{margin:20px 0} div{margin:20px 0}',
        );

        $ol = $boxes['ol']->box;
        $after = $boxes['div']->box;
        self::assertSame(20.0, $after->content->y - ($ol->content->y + $ol->content->height));
    }

    public function testTopPaddingOnTheParentStopsTheCollapse(): void
    {
        $boxes = $this->boxes(
            '<div id="wrap"><p>x</p></div>',
            '*{font-size:12px;line-height:1.2} p{margin:20px 0} div{margin:20px 0;padding-top:10px}',
        );

        // 20 (div margin) + 10 (padding) + 20 (p margin, no longer collapsing through).
        self::assertSame(50.0, $boxes['p']->box->content->y);
    }

    public function testLeadingInlineContentStopsTheCollapse(): void
    {
        $boxes = $this->boxes(
            '<div>texto<p>x</p></div>',
            '*{font-size:12px;line-height:1.2} p{margin:20px 0} div{margin:20px 0}',
        );

        // The div opens with a line box, so the paragraph keeps its own margin below it.
        self::assertGreaterThan(20.0 + 14.4, $boxes['p']->box->content->y);
    }
}
