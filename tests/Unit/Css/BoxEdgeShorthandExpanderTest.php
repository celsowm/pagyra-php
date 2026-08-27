<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\DeclarationParser;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class BoxEdgeShorthandExpanderTest extends TestCase
{
    public function testMarginShorthandExpandsToAllFourSides(): void
    {
        $parsed = (new DeclarationParser())->parse('margin:5px');
        self::assertSame('5px', $parsed['margin-top']);
        self::assertSame('5px', $parsed['margin-right']);
        self::assertSame('5px', $parsed['margin-bottom']);
        self::assertSame('5px', $parsed['margin-left']);
    }

    public function testPaddingShorthandExpandsToAllFourSides(): void
    {
        $parsed = (new DeclarationParser())->parse('padding:1px 2px 3px 4px');
        self::assertSame('1px', $parsed['padding-top']);
        self::assertSame('2px', $parsed['padding-right']);
        self::assertSame('3px', $parsed['padding-bottom']);
        self::assertSame('4px', $parsed['padding-left']);
    }

    public function testTwoAndThreeValueFormsExpandLikeTheStandardCssBoxShorthand(): void
    {
        $two = (new DeclarationParser())->parse('margin:1px 2px');
        self::assertSame(['1px', '2px', '1px', '2px'], [
            $two['margin-top'], $two['margin-right'], $two['margin-bottom'], $two['margin-left'],
        ]);

        $three = (new DeclarationParser())->parse('margin:1px 2px 3px');
        self::assertSame(['1px', '2px', '3px', '2px'], [
            $three['margin-top'], $three['margin-right'], $three['margin-bottom'], $three['margin-left'],
        ]);
    }

    /**
     * The bug this expander fixes: p/h1/h2/h3 (margin-top/margin-bottom) and ul/ol
     * (margin-top/margin-bottom/padding-left) carry UA-stylesheet longhand defaults. Before
     * expansion, a `margin`/`padding` shorthand only ever overwrote its own unexpanded key, so
     * ComputedStyle::get('margin-top', ...) kept finding the UA default already sitting under
     * that exact key and never fell through to the shorthand's value.
     */
    public function testMarginShorthandOverridesTheUserAgentDefaultOnAParagraph(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0">text</p>',
            'viewportWidth' => 200,
            'viewportHeight' => 200,
        ]);

        self::assertSame(0.0, $prepared->layoutRoot->children[0]->box->margin->top);
        self::assertSame(0.0, $prepared->layoutRoot->children[0]->box->margin->bottom);
    }

    public function testMarginShorthandOverridesTheUserAgentDefaultOnAHeading(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<h1 style="margin:5px">title</h1>',
            'viewportWidth' => 200,
            'viewportHeight' => 200,
        ]);

        self::assertSame(5.0, $prepared->layoutRoot->children[0]->box->margin->top);
    }

    public function testMarginAndPaddingShorthandsOverrideTheUserAgentDefaultsOnAList(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<ul style="margin:0;padding:0"><li>a</li></ul>',
            'viewportWidth' => 200,
            'viewportHeight' => 200,
        ]);

        $ul = $prepared->layoutRoot->children[0];
        self::assertSame(0.0, $ul->box->margin->top);
        self::assertSame(0.0, $ul->box->padding->left);
    }

    public function testALaterExplicitLonghandStillWinsOverAnEarlierShorthand(): void
    {
        $parsed = (new DeclarationParser())->parse('margin:5px;margin-top:9px');
        self::assertSame('9px', $parsed['margin-top']);
        self::assertSame('5px', $parsed['margin-right']);
    }

    public function testShorthandWithMoreThanFourValuesIsIgnoredRatherThanMisparsed(): void
    {
        $parsed = (new DeclarationParser())->parse('margin:1px 2px 3px 4px 5px');
        self::assertArrayNotHasKey('margin-top', $parsed);
    }
}
