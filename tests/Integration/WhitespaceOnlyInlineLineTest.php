<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSS 2.1 9.4.2: a line box holding only collapsible white space and inline elements with no
 * margin, padding or border is zero-height and does not exist for margin collapsing. Every
 * inline element used to count as content, so `<p><span> </span></p>` got a whole line and an
 * empty `<p><span></span></p>` stopped its margins from collapsing through. Writs from the court
 * stack nine of those paragraphs to space the text, which pushed a one-page document's signature
 * onto a second page.
 */
final class WhitespaceOnlyInlineLineTest extends TestCase
{
    private const CSS = '*{font-size:12px;line-height:1.2} body{margin:0} p{margin:20px 0}';

    /** @return list<\Pagyra\Layout\LayoutNode> */
    private function paragraphs(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'css' => self::CSS,
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

    private function lastParagraphTop(string $middle): float
    {
        $boxes = $this->paragraphs('<p>um</p>' . $middle . '<p>dois</p>');

        return end($boxes)->box->content->y;
    }

    /** @return array<string, array{0:string}> */
    public static function phantomParagraphs(): array
    {
        return [
            'space in a span' => ['<p><span> </span></p>'],
            'span with an inline style' => ['<p><span style="font-size: medium;"> </span></p>'],
            'empty span' => ['<p><span></span></p>'],
            'nested spans with newlines' => ["<p>\n <strong><span> </span></strong> <em></em>\n</p>"],
        ];
    }

    #[DataProvider('phantomParagraphs')]
    public function testParagraphWithOnlyCollapsibleWhitespaceTakesNoRoom(string $paragraph): void
    {
        $expected = $this->lastParagraphTop('');

        self::assertEqualsWithDelta($expected, $this->lastParagraphTop(str_repeat($paragraph, 9)), 0.001);
    }

    /** @return array<string, array{0:string}> */
    public static function paragraphsThatKeepALine(): array
    {
        return [
            'no-break space' => ['<p><span>&nbsp;</span></p>'],
            'line break' => ['<p><span><br></span></p>'],
            'preserved white space' => ['<p><span style="white-space:pre-wrap"> </span></p>'],
            'inline padding' => ['<p><span style="padding:4px"> </span></p>'],
            'inline border' => ['<p><span style="border:1px solid #000"> </span></p>'],
        ];
    }

    #[DataProvider('paragraphsThatKeepALine')]
    public function testParagraphWithRealInlineContentKeepsItsLine(string $paragraph): void
    {
        self::assertGreaterThan($this->lastParagraphTop('') + 10.0, $this->lastParagraphTop($paragraph));
    }

    public function testSpaceInASpanStillSeparatesWords(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p id="a">um dois</p><p id="b">um<span> </span>dois</p>',
            'css' => self::CSS,
            'viewportWidth' => 500,
            'viewportHeight' => 900,
        ]);

        $texts = [];
        $walk = function ($node) use (&$walk, &$texts): void {
            if (($node->source->node->tagName ?? null) === 'p') {
                $texts[] = trim(implode(' ', array_map(static fn ($line): string => $line->text, $node->lineBoxes)));
            }
            foreach ($node->children as $child) $walk($child);
        };
        $walk($prepared->layoutRoot);

        self::assertSame(['um dois', 'um dois'], $texts);
    }
}
