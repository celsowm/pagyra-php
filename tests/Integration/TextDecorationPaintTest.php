<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class TextDecorationPaintTest extends TestCase
{
    /** @return list<TextPaintCommand> */
    private function textCommands(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 150,
        ]);

        return array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof TextPaintCommand,
        ));
    }

    public function testUnderlineSetsFlagOnTextPaintCommand(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration:underline">Hello</p>');

        self::assertCount(1, $texts);
        self::assertTrue($texts[0]->underline);
        self::assertFalse($texts[0]->lineThrough);
    }

    public function testLineThroughSetsFlagOnTextPaintCommand(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration:line-through">Hello</p>');

        self::assertCount(1, $texts);
        self::assertFalse($texts[0]->underline);
        self::assertTrue($texts[0]->lineThrough);
    }

    public function testBothLinesCanBeCombinedInTheShorthand(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration:underline line-through">Hello</p>');

        self::assertCount(1, $texts);
        self::assertTrue($texts[0]->underline);
        self::assertTrue($texts[0]->lineThrough);
    }

    public function testTextDecorationNoneProducesNoDecoration(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration:none">Hello</p>');

        self::assertCount(1, $texts);
        self::assertFalse($texts[0]->underline);
        self::assertFalse($texts[0]->lineThrough);
    }

    public function testUnknownTextDecorationTokenIsIgnored(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration:wavy">Hello</p>');

        self::assertCount(1, $texts);
        self::assertFalse($texts[0]->underline);
        self::assertFalse($texts[0]->lineThrough);
    }

    public function testUnderlineIsInheritedByDescendantsWithoutTheirOwnDecoration(): void
    {
        $texts = $this->textCommands(
            '<p style="margin:0;font-size:20px;text-decoration:underline">before <span>inherited</span></p>'
        );

        self::assertCount(2, $texts);
        self::assertTrue($texts[0]->underline);
        self::assertTrue($texts[1]->underline);
    }

    public function testDescendantCanOverrideInheritedDecorationWithNone(): void
    {
        $texts = $this->textCommands(
            '<p style="margin:0;font-size:20px;text-decoration:underline">before '
            . '<span style="text-decoration:none">not underlined</span></p>'
        );

        self::assertCount(2, $texts);
        self::assertTrue($texts[0]->underline);
        self::assertFalse($texts[1]->underline);
    }

    public function testLonghandTextDecorationLineIsAlsoSupported(): void
    {
        $texts = $this->textCommands('<p style="margin:0;font-size:20px;text-decoration-line:underline">Hello</p>');

        self::assertCount(1, $texts);
        self::assertTrue($texts[0]->underline);
    }

    public function testUnderlinePaintsAFilledRectangleBelowTheBaselineInThePdf(): void
    {
        $html = '<p style="margin:0;font-size:20px;color:#ff0000;text-decoration:underline">Hi</p>';

        $withDecoration = Pagyra::renderHtmlToPdf(['html' => $html, 'viewportWidth' => 300, 'viewportHeight' => 150]);
        $withoutDecoration = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0;font-size:20px;color:#ff0000">Hi</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 150,
        ]);

        self::assertSame(1, substr_count($withDecoration, "re f\nQ\n") - substr_count($withoutDecoration, "re f\nQ\n"));
        self::assertStringContainsString("1 0 0 rg\n", $withDecoration);
    }
}
