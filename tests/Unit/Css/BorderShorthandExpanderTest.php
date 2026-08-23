<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\DeclarationParser;
use PHPUnit\Framework\TestCase;

final class BorderShorthandExpanderTest extends TestCase
{
    public function testBorderExpandsWidthStyleAndColorToAllSides(): void
    {
        $parsed = (new DeclarationParser())->parse('border:4px solid rgba(255, 0, 0, .5)');

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertSame('4px', $parsed['border-' . $side . '-width']);
            self::assertSame('solid', $parsed['border-' . $side . '-style']);
            self::assertSame('rgba(255, 0, 0, .5)', $parsed['border-' . $side . '-color']);
        }
    }

    public function testBorderStyleWithoutWidthUsesMediumWidthLikeReference(): void
    {
        $parsed = (new DeclarationParser())->parse('border:solid red');
        self::assertSame('3px', $parsed['border-top-width']);
        self::assertSame('solid', $parsed['border-top-style']);
        self::assertSame('red', $parsed['border-top-color']);
    }

    public function testWidthKeywordsAndFourValueShorthandsExpand(): void
    {
        $parsed = (new DeclarationParser())->parse(
            'border-width:thin medium thick 7px; border-style:solid dashed dotted double; border-color:red green blue black',
        );

        self::assertSame('1px', $parsed['border-top-width']);
        self::assertSame('3px', $parsed['border-right-width']);
        self::assertSame('5px', $parsed['border-bottom-width']);
        self::assertSame('7px', $parsed['border-left-width']);
        self::assertSame('dashed', $parsed['border-right-style']);
        self::assertSame('blue', $parsed['border-bottom-color']);
    }

    public function testLaterLonghandOverridesNormalShorthand(): void
    {
        $parsed = (new DeclarationParser())->parse('border:1px solid red; border-left-width:5px');
        self::assertSame('1px', $parsed['border-top-width']);
        self::assertSame('5px', $parsed['border-left-width']);
    }

    public function testImportantShorthandIsNotOverriddenByNormalLonghand(): void
    {
        $parsed = (new DeclarationParser())->parseWithPriority(
            'border:1px solid red !important; border-left-width:5px',
        );
        self::assertSame('1px', $parsed['border-left-width']['value']);
        self::assertTrue($parsed['border-left-width']['important']);
    }

    public function testSideShorthandAffectsOnlyRequestedSide(): void
    {
        $parsed = (new DeclarationParser())->parse('border-top:thick dotted #123456');
        self::assertSame('5px', $parsed['border-top-width']);
        self::assertSame('dotted', $parsed['border-top-style']);
        self::assertSame('#123456', $parsed['border-top-color']);
        self::assertArrayNotHasKey('border-right-width', $parsed);
    }
}
