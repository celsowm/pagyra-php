<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\RoundedBorderPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class AtomicInlinePaintTest extends TestCase
{
    public function testInlineBlockPaintsBackgroundRoundedBorderAndInnerText(): void
    {
        $html = '<style>@page{size:240px 120px;margin:0}p{margin:0}</style>'
            . '<p>before <span style="display:inline-block;width:60px;padding:4px;border:2px solid red;border-radius:6px;background:#00ff00">hello hello</span> after</p>';
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'pageWidth' => 240.0,
            'pageHeight' => 120.0,
            'viewportWidth' => 240.0,
            'viewportHeight' => 120.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $commands = $prepared->displayList?->pages[0]->commands ?? [];
        $atomicBoxes = array_values(array_filter(
            $commands,
            static fn (object $command): bool => $command instanceof BoxPaintCommand
                && $command->node instanceof AtomicInlineBox
                && $command->backgroundColor !== null,
        ));
        self::assertCount(1, $atomicBoxes);
        self::assertSame(72.0, $atomicBoxes[0]->width);
        self::assertSame(6.0, $atomicBoxes[0]->borderRadius->topLeft->x);

        $rounded = array_values(array_filter(
            $commands,
            static fn (object $command): bool => $command instanceof RoundedBorderPaintCommand
                && $command->node instanceof AtomicInlineBox,
        ));
        self::assertCount(1, $rounded);
        self::assertSame(2.0, $rounded[0]->borderWidth);

        $texts = array_values(array_filter($commands, static fn (object $command): bool => $command instanceof TextPaintCommand));
        $painted = implode('', array_map(static fn (TextPaintCommand $command): string => $command->text, $texts));
        self::assertStringContainsString('before ', $painted);
        self::assertGreaterThanOrEqual(2, substr_count($painted, 'hello'));
        self::assertStringContainsString(' after', $painted);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'pageWidth' => 240.0,
            'pageHeight' => 120.0,
            'viewportWidth' => 240.0,
            'viewportHeight' => 120.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);
        self::assertStringContainsString("f*\n", $pdf);
        self::assertGreaterThanOrEqual(2, substr_count($pdf, 'hello'));
    }

    public function testNestedInlineBlocksPaintNestedContentLinesRecursively(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:260px 140px;margin:0}p{margin:0}</style>'
                . '<p><span style="display:inline-block;width:120px;background:#eeeeee">outer <span style="display:inline-block;width:50px;background:#dddddd">inner</span> end</span></p>',
            'pageWidth' => 260.0,
            'pageHeight' => 140.0,
            'viewportWidth' => 260.0,
            'viewportHeight' => 140.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $commands = $prepared->displayList?->pages[0]->commands ?? [];
        $atomicBackgrounds = array_values(array_filter(
            $commands,
            static fn (object $command): bool => $command instanceof BoxPaintCommand
                && $command->node instanceof AtomicInlineBox
                && $command->backgroundColor !== null,
        ));
        self::assertCount(2, $atomicBackgrounds);

        $texts = array_values(array_filter($commands, static fn (object $command): bool => $command instanceof TextPaintCommand));
        $painted = implode('', array_map(static fn (TextPaintCommand $command): string => $command->text, $texts));
        self::assertStringContainsString('outer ', $painted);
        self::assertStringContainsString('inner', $painted);
        self::assertStringContainsString(' end', $painted);
    }
}
