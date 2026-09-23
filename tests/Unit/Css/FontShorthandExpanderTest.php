<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\DeclarationParser;
use Pagyra\Css\FontShorthandExpander;
use PHPUnit\Framework\TestCase;

final class FontShorthandExpanderTest extends TestCase
{
    public function testExpandsEveryPart(): void
    {
        self::assertSame([
            'font-style' => 'italic',
            'font-variant' => 'small-caps',
            'font-weight' => 'bold',
            'font-stretch' => 'normal',
            'line-height' => '1.5',
            'font-size' => '20px',
            'font-family' => 'Arial, sans-serif',
        ], (new FontShorthandExpander())->expand('font', 'italic small-caps bold 20px/1.5 Arial, sans-serif'));
    }

    public function testOmittedPartsResetToTheirInitialValue(): void
    {
        $expanded = (new FontShorthandExpander())->expand('font', '11.0pt "Calibri", sans-serif');

        self::assertSame('normal', $expanded['font-weight']);
        self::assertSame('normal', $expanded['line-height']);
        self::assertSame('11.0pt', $expanded['font-size']);
        self::assertSame('"Calibri", sans-serif', $expanded['font-family']);
    }

    public function testSpacedLineHeightAndNumericWeight(): void
    {
        $expanded = (new FontShorthandExpander())->expand('font', '600 1.2em / 2 "Open Sans"');

        self::assertSame('600', $expanded['font-weight']);
        self::assertSame('1.2em', $expanded['font-size']);
        self::assertSame('2', $expanded['line-height']);
    }

    public function testCssWideKeywordGoesToEveryLonghand(): void
    {
        $expanded = (new FontShorthandExpander())->expand('font', 'inherit');

        self::assertSame(['inherit'], array_values(array_unique($expanded)));
        self::assertArrayHasKey('font-family', $expanded);
    }

    public function testWhatDoesNotParseIsLeftAlone(): void
    {
        $expander = new FontShorthandExpander();
        self::assertNull($expander->expand('font', 'bold 12px'));
        self::assertNull($expander->expand('font', 'caption'));
        self::assertNull($expander->expand('font-size', '12px Arial'));
    }

    public function testLaterLonghandBeatsTheShorthandAndViceVersa(): void
    {
        $parser = new DeclarationParser();
        self::assertSame('2', $parser->parse('font: 12px Arial; line-height: 2')['line-height']);
        self::assertSame('normal', $parser->parse('line-height: 2; font: 12px Arial')['line-height']);
    }
}
