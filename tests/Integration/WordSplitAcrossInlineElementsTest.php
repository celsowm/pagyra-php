<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A word the markup splits across inline elements, `re<span>direcionado</span>`, is still one
 * word: the boundary between two elements is not a break opportunity (CSS Text 3 §5), only the
 * white space is. The formatter broke the line before any token that overflowed, so when the
 * boundary fell at the end of a line the word came out cut in two with no hyphen ("ser re" /
 * "direcionado", "CORTE E" / "SPECIAL" in TJRJ decisions) where wkhtmltopdf and browsers move
 * the whole word down.
 */
final class WordSplitAcrossInlineElementsTest extends TestCase
{
    /** @return list<string> */
    private function lineTexts(string $body, string $style = 'width:190px'): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font:16px Arial;' . $style . '">' . $body . '</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 400,
        ]);

        return array_map(static fn ($line): string => trim($line->text), $prepared->layoutRoot->children[0]->lineBoxes);
    }

    /** @return array<string, array{0:string}> */
    public static function splitWords(): array
    {
        return [
            'second half in a span' => ['aaaa bbbb cccc dddd re<span>direcionado</span> ao fim'],
            'second half in bold' => ['aaaa bbbb cccc dddd re<b>direcionado</b> ao fim'],
            'first half in a span' => ['aaaa bbbb cccc dddd <span>re</span>direcionado ao fim'],
            'empty span inside' => ['aaaa bbbb cccc dddd re<span></span>direcionado ao fim'],
            'three pieces' => ['aaaa bbbb cccc dddd <i>r</i>e<span>direcionado</span> ao fim'],
        ];
    }

    #[DataProvider('splitWords')]
    public function testTheWholeWordMovesToTheNextLine(string $body): void
    {
        self::assertSame(['aaaa bbbb cccc dddd', 'redirecionado ao fim'], $this->lineTexts($body));
    }

    public function testASpaceInsideTheSpanIsStillABreakOpportunity(): void
    {
        self::assertSame(
            ['aaaa bbbb cccc dddd re', 'direcionado ao fim'],
            $this->lineTexts('aaaa bbbb cccc dddd re<span> </span>direcionado ao fim'),
        );
    }

    public function testJustifiedLineIsStretchedWithoutTheCarriedPiece(): void
    {
        $lines = $this->lineTexts('aaaa bbbb cccc dddd re<span>direcionado</span> ao fim', 'width:190px;text-align:justify');

        self::assertSame(['aaaa bbbb cccc dddd', 'redirecionado ao fim'], $lines);
    }

    public function testAWordWiderThanTheLineOverflowsWhole(): void
    {
        // Nothing before the word to keep on the line: like any word wider than the line, it
        // overflows instead of being cut where the elements meet.
        self::assertSame(['aaaaaaaaaabbbbbbbbbb'], $this->lineTexts('<span>aaaaaaaaaa</span>bbbbbbbbbb', 'width:100px'));
    }

    public function testWithBreakWordAWordWiderThanTheLineBreaksAtTheBoundary(): void
    {
        self::assertSame(
            ['aaaaaaaaaa', 'bbbbbbbbbb'],
            $this->lineTexts('<span>aaaaaaaaaa</span>bbbbbbbbbb', 'width:100px;word-wrap:break-word'),
        );
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function wordsWiderThanTheLineWithTheirPieces(): array
    {
        return [
            // `horas&nbsp;semanais.&nbsp;(<a>https://…</a>`: the URL alone is wider than the line
            'the piece after the boundary is wider than the line' => [
                'aaaa bbbb&nbsp;(<span>' . str_repeat('c', 60) . '</span>',
                'aaaa bbbb (',
                str_repeat('c', 60),
            ],
            // `orçamentário"</em>.&nbsp;(https://…)`: the URL fits a line, the whole word does not
            'only the whole word is wider than the line' => [
                'aaaa <em>cccc"</em>.&nbsp;(' . str_repeat('d', 17) . ')',
                'aaaa cccc"',
                '. (' . str_repeat('d', 17) . ')',
            ],
        ];
    }

    #[DataProvider('wordsWiderThanTheLineWithTheirPieces')]
    public function testWithBreakWordAWordThatDoesNotFitEvenWholeBreaksAtTheBoundary(string $body, string $firstLine, string $rest): void
    {
        // Carrying the pieces before the boundary down with the rest of the word gains nothing
        // when the word is wider than a line anyway: they only took room the rest then ran past
        // the right edge into, cut off at the edge of the sheet in TJRJ and TRF2 decisions.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font:16px Arial;width:190px;word-wrap:break-word">' . $body . '</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 400,
        ]);
        $paragraph = $prepared->layoutRoot->children[0];
        $texts = array_map(static fn ($line): string => str_replace("\u{00A0}", ' ', trim($line->text)), $paragraph->lineBoxes);

        self::assertSame($firstLine, $texts[0]);
        self::assertSame($rest, implode('', array_slice($texts, 1)));

        // paint commands are placed on the page, so the page margin comes before the paragraph
        $right = $prepared->margins['left'] + $paragraph->box->content->x + 190.0;
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) {
                self::assertLessThanOrEqual($right + 0.01, $command->x + $command->run->width, $command->text);
            }
        }
    }

    public function testShrinkToFitWidthCoversTheWholeWord(): void
    {
        // A float in a 10px container is as wide as its min-content, which used to be that of the
        // widest piece ("direcionado"), so the word was cut inside the float.
        $float = static function (string $body): array {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<div style="width:10px"><div style="float:left;font:16px Arial">' . $body . '</div></div>',
                'viewportWidth' => 400,
                'viewportHeight' => 400,
            ]);
            $box = $prepared->layoutRoot->children[0]->children[0];

            return [$box->box->content->width, array_map(static fn ($line): string => $line->text, $box->lineBoxes)];
        };

        [$whole, $wholeLines] = $float('redirecionado');
        [$split, $splitLines] = $float('re<span>direcionado</span>');

        self::assertEqualsWithDelta($whole, $split, 0.01);
        self::assertSame($wholeLines, $splitLines);
    }
}
