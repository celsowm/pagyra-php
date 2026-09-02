<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Court-document paragraphs use `text-indent: 0.98in` for the first-line indent and
 * `word-wrap: break-word` (the legacy `overflow-wrap` name) to wrap long URLs. Both
 * were dropped: the inline length resolver only knew px/pt/em/rem/%, and only the
 * modern `overflow-wrap` name was read.
 */
final class TextIndentAndWordWrapTest extends TestCase
{
    /** @return list<string> */
    private function lineTexts(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 400,
            'viewportHeight' => 400,
        ]);
        $paragraph = $prepared->layoutRoot->children[0];

        return array_map(static fn ($line): string => $line->text, $paragraph->lineBoxes);
    }

    private function firstLineX(string $html): float
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 400,
            'viewportHeight' => 400,
        ]);

        return $prepared->layoutRoot->children[0]->lineBoxes[0]->x;
    }

    public function testTextIndentOffsetsOnlyTheFirstLine(): void
    {
        $noIndent = $this->firstLineX('<p style="margin:0">um dois tres</p>');
        $indented = $this->firstLineX('<p style="margin:0;text-indent:40px">um dois tres</p>');

        self::assertEqualsWithDelta($noIndent + 40.0, $indented, 0.01);
    }

    public function testTextIndentAcceptsInchAndCentimetreUnits(): void
    {
        $base = $this->firstLineX('<p style="margin:0">x</p>');

        // 0.5in = 48px, 1cm = 37.795...px at 96dpi
        self::assertEqualsWithDelta($base + 48.0, $this->firstLineX('<p style="margin:0;text-indent:0.5in">x</p>'), 0.01);
        self::assertEqualsWithDelta($base + 37.7953, $this->firstLineX('<p style="margin:0;text-indent:1cm">x</p>'), 0.01);
    }

    public function testTextIndentIsInheritedFromTheContainer(): void
    {
        $paragraphLineX = function (string $html): float {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => $html,
                'viewportWidth' => 400,
                'viewportHeight' => 400,
            ]);

            return $prepared->layoutRoot->children[0]->children[0]->lineBoxes[0]->x;
        };

        $base = $paragraphLineX('<div><p style="margin:0">x</p></div>');
        $indented = $paragraphLineX('<div style="text-indent:30px"><p style="margin:0">x</p></div>');

        self::assertEqualsWithDelta($base + 30.0, $indented, 0.01);
    }

    public function testWordWrapBreakWordWrapsALongUnbreakableToken(): void
    {
        $url = 'https://example.test/' . str_repeat('segment-', 20) . 'end';

        $withoutWrap = $this->lineTexts('<p style="margin:0">' . $url . '</p>');
        $withWrap = $this->lineTexts('<p style="margin:0;word-wrap:break-word">' . $url . '</p>');

        self::assertCount(1, $withoutWrap);
        self::assertGreaterThan(1, count($withWrap));
        self::assertSame($url, implode('', $withWrap));
    }
}
