<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ClipPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** `overflow: hidden` clips a box's content to its padding box. */
final class OverflowClipTest extends TestCase
{
    /** @return list<object> */
    private function commands(string $html): array
    {
        return Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero', 'margins' => 0.0])->displayList->pages[0]->commands;
    }

    public function testContentIsWrappedInAClipOfThePaddingBox(): void
    {
        $commands = $this->commands('<div style="height:30px;overflow:hidden;border:2px solid #000;padding:3px">' . str_repeat('texto ', 200) . '</div><p>depois</p>');
        $clips = array_values(array_filter($commands, static fn(object $c): bool => $c instanceof ClipPaintCommand));

        self::assertCount(2, $clips);
        self::assertTrue($clips[0]->opens());
        self::assertFalse($clips[1]->opens());
        self::assertEqualsWithDelta([2.0, 2.0, 36.0], [$clips[0]->x, $clips[0]->y, $clips[0]->height], 1e-6);

        $open = array_search($clips[0], $commands, true);
        $close = array_search($clips[1], $commands, true);
        foreach ($commands as $index => $command) {
            if ($command instanceof TextPaintCommand) {
                self::assertSame(trim($command->text) !== 'depois', $index > $open && $index < $close, $command->text);
            }
        }
    }

    public function testOnlyTheClippedAxisIsBounded(): void
    {
        $clip = array_values(array_filter($this->commands('<div style="height:20px;overflow-y:hidden">x</div>'), static fn(object $c): bool => $c instanceof ClipPaintCommand))[0];

        self::assertEqualsWithDelta(20.0, $clip->height, 1e-6);
        self::assertGreaterThan(1000.0, $clip->width);
    }

    public function testVisibleOverflowAddsNoClipAndThePdfBalancesTheState(): void
    {
        self::assertSame([], array_filter($this->commands('<div style="height:20px">x</div>'), static fn(object $c): bool => $c instanceof ClipPaintCommand));

        $pdf = Pagyra::renderHtmlToPdf(['html' => '<div style="height:20px;overflow:hidden"><p>a</p><p>b</p></div>']);
        self::assertStringContainsString(' re W n', $pdf);
    }

    public function testABoxSplitAcrossPagesIsNotClipped(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageHeight' => 200.0,
            'html' => '<div style="overflow:hidden">' . str_repeat('<p style="margin:0">linha</p>', 30) . '</div>',
        ]);

        self::assertGreaterThan(1, count($prepared->displayList->pages));
        foreach ($prepared->displayList->pages as $page) {
            self::assertSame([], array_values(array_filter($page->commands, static fn(object $c): bool => $c instanceof ClipPaintCommand)));
        }
    }
}
