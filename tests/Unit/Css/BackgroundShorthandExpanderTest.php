<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\DeclarationParser;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class BackgroundShorthandExpanderTest extends TestCase
{
    public function testBackgroundShorthandWithOnlyAColorExpandsToBackgroundColor(): void
    {
        $parsed = (new DeclarationParser())->parse('background:#123456');
        self::assertSame('#123456', $parsed['background-color']);
    }

    public function testColorTokenIsFoundRegardlessOfItsPositionInTheShorthand(): void
    {
        self::assertSame('red', (new DeclarationParser())->parse('background:red no-repeat center')['background-color']);
        self::assertSame('red', (new DeclarationParser())->parse('background:no-repeat center red')['background-color']);
    }

    public function testRgbaColorFunctionIsRecognizedInTheShorthand(): void
    {
        $parsed = (new DeclarationParser())->parse('background:rgba(255, 0, 0, .5) no-repeat');
        self::assertSame('rgba(255, 0, 0, .5)', $parsed['background-color']);
    }

    public function testShorthandWithoutAnyColorTokenLeavesBackgroundColorUnset(): void
    {
        $parsed = (new DeclarationParser())->parse('background:url(x.png) no-repeat center');
        self::assertArrayNotHasKey('background-color', $parsed);
    }

    public function testALaterExplicitBackgroundColorDeclarationStillWinsOverTheShorthand(): void
    {
        $parsed = (new DeclarationParser())->parse('background:red;background-color:blue');
        self::assertSame('blue', $parsed['background-color']);
    }

    public function testBackgroundShorthandPaintsTheSameBoxAsTheLonghandInARenderedPdf(): void
    {
        $shorthand = Pagyra::renderHtmlToPdf([
            'html' => '<div style="margin:0;width:50px;height:20px;background:#112233"></div>',
            'viewportWidth' => 100,
            'viewportHeight' => 50,
        ]);
        $longhand = Pagyra::renderHtmlToPdf([
            'html' => '<div style="margin:0;width:50px;height:20px;background-color:#112233"></div>',
            'viewportWidth' => 100,
            'viewportHeight' => 50,
        ]);

        self::assertSame($longhand, $shorthand);
        self::assertStringContainsString('0.066667 0.133333 0.2 rg', $shorthand);
    }
}
