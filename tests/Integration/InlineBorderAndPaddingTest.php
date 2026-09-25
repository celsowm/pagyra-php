<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\TextRun;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `border` and `padding` on an inline element (`<span>`, `<a>`, `<mark>`...) were ignored
 * outright: only `background-color` reached the paint layer, via TextRun::$inlineBackground. A
 * badge or a tag styled with a border — a common pattern editors and CSS frameworks emit on a
 * `<span>` — rendered as plain, unbordered text.
 *
 * The horizontal edges are part of inline layout, not merely paint: padding and border reserve
 * advance width, move the glyphs inward and can therefore change wrapping exactly like a browser.
 */
final class InlineBorderAndPaddingTest extends TestCase
{
    /** @return list<BoxPaintCommand> */
    private function textRunBoxes(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender(['pagedBodyMargin' => 'zero', 'margins' => 0.0, 'html' => $html]);
        $boxes = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof BoxPaintCommand && $command->node instanceof TextRun) $boxes[] = $command;
        }

        return $boxes;
    }

    public function testBorderIsDrawnOnAllFourSidesAroundTheText(): void
    {
        $boxes = $this->textRunBoxes('<p style="margin:0"><span style="border:1px solid red">badge</span></p>');

        $borders = array_values(array_filter($boxes, static fn(BoxPaintCommand $b) => $b->decorative));
        self::assertCount(4, $borders);
        foreach ($borders as $border) {
            self::assertEqualsWithDelta(255.0, $border->backgroundColor->r, 0.5);
            self::assertEqualsWithDelta(0.0, $border->backgroundColor->g, 0.5);
        }
    }

    public function testPaddingWidensTheBackgroundBandAndKeepsItsOuterEdge(): void
    {
        $withoutPadding = $this->textRunBoxes('<p style="margin:0"><span style="background:yellow">badge</span></p>');
        $withPadding = $this->textRunBoxes('<p style="margin:0"><span style="background:yellow;padding-left:10px;padding-right:6px">badge</span></p>');

        self::assertCount(1, $withoutPadding);
        self::assertCount(1, $withPadding);
        self::assertEqualsWithDelta($withoutPadding[0]->width + 16.0, $withPadding[0]->width, 0.5);
        self::assertEqualsWithDelta($withoutPadding[0]->x, $withPadding[0]->x, 0.5);
    }

    public function testBorderAndPaddingCombineIntoOneWiderBand(): void
    {
        $boxes = $this->textRunBoxes('<p style="margin:0"><span style="border:2px solid blue;padding:0 5px">badge</span></p>');

        $backgroundless = array_values(array_filter($boxes, static fn(BoxPaintCommand $b) => !$b->decorative));
        self::assertCount(0, $backgroundless);
        $borders = array_values(array_filter($boxes, static fn(BoxPaintCommand $b) => $b->decorative));
        self::assertCount(4, $borders);
    }

    public function testPaddingAndBorderPushFollowingInlineContentAside(): void
    {
        $plain = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="margin:0">A<span>B</span>C</p>',
        ]);
        $decorated = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="margin:0">A<span style="padding:0 10px;border:2px solid red">B</span>C</p>',
        ]);

        $plainRuns = $plain->layoutRoot->children[0]->lineBoxes[0]->runs;
        $decoratedRuns = $decorated->layoutRoot->children[0]->lineBoxes[0]->runs;
        self::assertCount(3, $plainRuns);
        self::assertCount(3, $decoratedRuns);

        // 10px padding + 2px border on each horizontal side reserves 24px of real advance.
        self::assertEqualsWithDelta(
            $plainRuns[2]->x + 24.0,
            $decoratedRuns[2]->x,
            0.5,
        );
        self::assertEqualsWithDelta(
            $plainRuns[1]->x + 12.0,
            $decoratedRuns[1]->x,
            0.5,
        );
    }

    public function testInlineEdgesParticipateInWrapping(): void
    {
        $plain = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<p style="margin:0;width:50px;font-size:16px">A<span>B</span>C</p>',
            'viewportWidth' => 100,
            'viewportHeight' => 200,
        ]);
        $decorated = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<p style="margin:0;width:50px;font-size:16px">A<span style="padding:0 12px;border:1px solid">B</span>C</p>',
            'viewportWidth' => 100,
            'viewportHeight' => 200,
        ]);

        self::assertCount(1, $plain->layoutRoot->children[0]->lineBoxes);
        self::assertGreaterThan(1, count($decorated->layoutRoot->children[0]->lineBoxes));
    }

    public function testPlainSpanWithNeitherIsUnaffected(): void
    {
        $boxes = $this->textRunBoxes('<p style="margin:0"><span>plain</span></p>');

        self::assertSame([], $boxes);
    }

    public function testNoneBorderStyleDrawsNothing(): void
    {
        $boxes = $this->textRunBoxes('<p style="margin:0"><span style="border-style:none;border-width:3px;border-color:red">x</span></p>');

        self::assertSame([], $boxes);
    }
}
