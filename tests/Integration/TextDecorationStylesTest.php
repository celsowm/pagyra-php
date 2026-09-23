<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Css\DeclarationParser;
use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** `overline`, `text-decoration-style` and `text-decoration-color` reach the paint layer and the PDF. */
final class TextDecorationStylesTest extends TestCase
{
    private function command(string $html): TextPaintCommand
    {
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) return $command;
        }
        self::fail('no text');
    }

    public function testShorthandCarriesStyleAndColourAndResetsThem(): void
    {
        $parser = new DeclarationParser();
        self::assertSame(
            ['text-decoration-color' => 'red', 'text-decoration-line' => 'underline', 'text-decoration-style' => 'wavy'],
            $parser->parse('text-decoration: underline wavy red'),
        );
        self::assertSame(
            ['text-decoration-color' => 'currentcolor', 'text-decoration-line' => 'overline', 'text-decoration-style' => 'solid'],
            $parser->parse('text-decoration: overline'),
        );
    }

    public function testPaintCommandCarriesTheDecoration(): void
    {
        $command = $this->command('<p style="text-decoration: overline dotted #00ff00">x</p>');

        self::assertTrue($command->overline);
        self::assertFalse($command->underline);
        self::assertSame('dotted', $command->decorationStyle);
        self::assertSame(255, (int) round($command->decorationColor->g));
    }

    public function testDecorationOfAnAncestorKeepsItsStyle(): void
    {
        $command = $this->command('<p style="text-decoration: underline double"><span>x</span></p>');

        self::assertTrue($command->underline);
        self::assertSame('double', $command->decorationStyle);
    }

    public function testPdfDrawsEachStyle(): void
    {
        $render = static fn(string $style): string => Pagyra::renderHtmlToPdf(['html' => '<p style="text-decoration: underline ' . $style . ' red">texto</p>']);

        self::assertMatchesRegularExpression('/1 0 0 RG\n[\d.]+ w\n\[[\d.]+ [\d.]+\] 0 d\n/', $render('dotted'));
        self::assertMatchesRegularExpression('/1 0 0 RG\n[\d.]+ w\n\[[\d.]+ [\d.]+\] 0 d\n/', $render('dashed'));
        self::assertGreaterThan(4, substr_count($render('wavy'), " l\n"));
        self::assertSame(2, substr_count($render('double'), "1 0 0 rg\n"));
    }
}
