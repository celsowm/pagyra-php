<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `font-variant: small-caps` was parsed and inherited (StyleComputer) but never reached the paint
 * layer, so `<span style="font-variant: small-caps">abc</span>` came out lowercase like plain text.
 * Without small-caps glyphs of our own, pagyra-js's approximation is upper-casing only what is
 * painted, which is what this port now mirrors.
 */
final class FontVariantSmallCapsTest extends TestCase
{
    private function command(string $html): TextPaintCommand
    {
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) return $command;
        }
        self::fail('no text');
    }

    public function testLowercaseTextIsPaintedUppercase(): void
    {
        $command = $this->command('<p style="font-variant: small-caps">abc Def</p>');

        self::assertSame('ABC DEF', $command->text);
    }

    public function testMeasuredWidthComesFromTheOriginalLowercaseText(): void
    {
        $command = $this->command('<p style="font-variant: small-caps">abc</p>');

        self::assertSame('abc', $command->run->text);
    }

    public function testIsInheritedFromAnAncestor(): void
    {
        $command = $this->command('<div style="font-variant: small-caps"><span>abc</span></div>');

        self::assertSame('ABC', $command->text);
    }

    public function testNormalIsLeftAlone(): void
    {
        $command = $this->command('<p style="font-variant: normal">abc</p>');

        self::assertSame('abc', $command->text);
    }
}
