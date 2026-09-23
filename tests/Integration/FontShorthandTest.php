<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** The `font` shorthand reaches the painted text instead of being ignored. */
final class FontShorthandTest extends TestCase
{
    public function testShorthandSetsSizeWeightStyleFamilyAndLineHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="font: italic bold 20px/40px Arial, sans-serif; margin:0">texto</p>',
        ]);
        $text = null;
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) $text = $command;
        }

        self::assertNotNull($text);
        self::assertEqualsWithDelta(20.0, $text->fontSize, 0.001);
        self::assertSame(700, $text->fontWeight);
        self::assertSame('italic', $text->fontStyle);
        self::assertStringContainsString('Arial', (string) $text->fontFamily);
        self::assertEqualsWithDelta(40.0, $text->run->height, 0.001);
    }
}
