<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * CSS 2.1 8.3.1: an empty block (no in-flow content, zero or auto height, no vertical border or
 * padding) lets its own margins collapse together with the margins on either side of it, so it
 * takes no room. Here it was a zero-height box between two margins and an empty `<p></p>` cost a
 * whole paragraph margin. The eproc template writes `<p class="identificacao_processo"><p ...>`,
 * which the parser turns into an empty paragraph followed by the real one; that plus the scale
 * pushed the document footer onto a page of its own.
 */
final class EmptyBlockMarginCollapseThroughTest extends TestCase
{
    private const CSS = '*{font-size:12px;line-height:1.2} body{margin:0} p{margin:20px 0}';

    /** @return list<\Pagyra\Layout\LayoutNode> */
    private function paragraphs(string $html, string $css = self::CSS): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'css' => $css,
            'viewportWidth' => 500,
            'viewportHeight' => 900,
        ]);

        $found = [];
        $walk = function ($node) use (&$walk, &$found): void {
            if (($node->source->node->tagName ?? null) === 'p') $found[] = $node;
            foreach ($node->children as $child) $walk($child);
        };
        $walk($prepared->layoutRoot);

        return $found;
    }

    private function gap(\Pagyra\Layout\LayoutNode $above, \Pagyra\Layout\LayoutNode $below): float
    {
        return $below->box->content->y - ($above->box->content->y + $above->box->content->height);
    }

    public function testEmptyParagraphBetweenSiblingsTakesNoRoom(): void
    {
        $with = $this->paragraphs('<p>um</p><p></p><p>dois</p>');
        $without = $this->paragraphs('<p>um</p><p>dois</p>');

        self::assertEqualsWithDelta(20.0, $this->gap($with[0], $with[2]), 0.001);
        self::assertEqualsWithDelta($without[1]->box->content->y, $with[2]->box->content->y, 0.001);
    }

    public function testNestedParagraphPatternFromEprocInsideASection(): void
    {
        // What the parser makes of `<section><p class=x><p class=x>texto</p></section>`.
        $html = '<p>antes</p><section><p class="x"><p class="x">texto</p></section>';
        $boxes = $this->paragraphs($html);

        self::assertCount(3, $boxes);
        self::assertEqualsWithDelta(20.0, $this->gap($boxes[0], $boxes[2]), 0.001);
    }

    public function testLargerMarginOfTheEmptyBlockWins(): void
    {
        $boxes = $this->paragraphs('<p>um</p><p class="vazio"></p><p>dois</p>', self::CSS . ' p.vazio{margin:35px 0 5px}');

        self::assertEqualsWithDelta(35.0, $this->gap($boxes[0], $boxes[2]), 0.001);
    }

    public function testLeadingEmptyBlockCollapsesIntoTheParentTop(): void
    {
        $boxes = $this->paragraphs('<div><p></p><p>texto</p></div>', self::CSS . ' div{margin:0}');

        self::assertEqualsWithDelta(20.0, $boxes[1]->box->content->y, 0.001);
    }

    public function testLeadingEmptyBlockAtTheBodyTop(): void
    {
        $boxes = $this->paragraphs('<p></p><p>texto</p>', '*{font-size:12px;line-height:1.2} body{margin:8px} p{margin:20px 0}');

        self::assertEqualsWithDelta(20.0, $boxes[1]->box->content->y, 0.001);
    }

    public function testParentPaddingKeepsTheCollapsedMarginInside(): void
    {
        $boxes = $this->paragraphs('<div><p></p><p>texto</p></div>', self::CSS . ' div{margin:0;padding-top:10px}');

        // 10 (padding) + 20 (the empty paragraph's margins collapsed with the next one's).
        self::assertEqualsWithDelta(30.0, $boxes[1]->box->content->y, 0.001);
    }

    public function testBlocksWithContentHeightOrBorderStillTakeRoom(): void
    {
        $nbsp = $this->paragraphs("<p>um</p><p>\u{00A0}</p><p>dois</p>");
        self::assertGreaterThan(40.0, $this->gap($nbsp[0], $nbsp[2]));

        $br = $this->paragraphs('<p>um</p><p><br></p><p>dois</p>');
        self::assertGreaterThan(40.0, $this->gap($br[0], $br[2]));

        $height = $this->paragraphs('<p>um</p><p style="height:10px"></p><p>dois</p>');
        self::assertEqualsWithDelta(50.0, $this->gap($height[0], $height[2]), 0.001);

        $border = $this->paragraphs('<p>um</p><p style="border-top:1px solid #000"></p><p>dois</p>');
        self::assertEqualsWithDelta(41.0, $this->gap($border[0], $border[2]), 0.001);
    }
}
