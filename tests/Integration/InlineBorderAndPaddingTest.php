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
 * Like the background it is painted alongside, this stays a paint-only decoration: it widens the
 * band drawn around the text rather than the box the text is laid out in, so the padding does not
 * actually push neighbouring inline content aside (TextRun's own docblock explains why).
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

    public function testPaddingWidensTheBackgroundBandWithoutMovingTheText(): void
    {
        $withoutPadding = $this->textRunBoxes('<p style="margin:0"><span style="background:yellow">badge</span></p>');
        $withPadding = $this->textRunBoxes('<p style="margin:0"><span style="background:yellow;padding-left:10px;padding-right:6px">badge</span></p>');

        self::assertCount(1, $withoutPadding);
        self::assertCount(1, $withPadding);
        self::assertEqualsWithDelta($withoutPadding[0]->width + 16.0, $withPadding[0]->width, 0.5);
        self::assertEqualsWithDelta($withoutPadding[0]->x - 10.0, $withPadding[0]->x, 0.5);
    }

    public function testBorderAndPaddingCombineIntoOneWiderBand(): void
    {
        $boxes = $this->textRunBoxes('<p style="margin:0"><span style="border:2px solid blue;padding:0 5px">badge</span></p>');

        $backgroundless = array_values(array_filter($boxes, static fn(BoxPaintCommand $b) => !$b->decorative));
        self::assertCount(0, $backgroundless);
        $borders = array_values(array_filter($boxes, static fn(BoxPaintCommand $b) => $b->decorative));
        self::assertCount(4, $borders);
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
