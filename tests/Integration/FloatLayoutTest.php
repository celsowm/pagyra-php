<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class FloatLayoutTest extends TestCase
{
    public function testFloatLeftAndFloatRightSiblingsShareTheSameRowInsteadOfStacking(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px">'
                . '<div style="float:left"><span>esquerda</span></div>'
                . '<div style="float:right"><span>direita</span></div>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        self::assertCount(2, $container->children);
        [$left, $right] = $container->children;

        self::assertSame(0.0, $left->box->content->y);
        self::assertSame(0.0, $right->box->content->y);
        self::assertSame(0.0, $left->box->content->x);
        self::assertGreaterThan($left->box->content->x + $left->box->content->width, $right->box->content->x);
        self::assertSame(400.0, $right->box->content->x + $right->box->content->width);
    }

    public function testFloatRunHeightIsTheTallestFloatNotTheSumOfBoth(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px;font-size:10px">'
                . '<div style="float:left;height:12px"><span>a</span></div>'
                . '<div style="float:right;height:40px"><span>b</span></div>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        self::assertSame(40.0, $container->box->content->height);
    }

    public function testNormalFlowSiblingAfterAFloatRunClearsBelowIt(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px">'
                . '<div style="float:left;height:30px"><span>a</span></div>'
                . '<p style="margin:0">depois</p>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        [$float, $after] = $container->children;
        self::assertSame(0.0, $float->box->content->y);
        self::assertGreaterThanOrEqual(30.0, $after->box->content->y);
    }

    public function testTwoLeftFloatsStackHorizontallySideBySideNotOnTopOfEachOther(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px">'
                . '<div style="float:left"><span>um</span></div>'
                . '<div style="float:left"><span>dois</span></div>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        [$first, $second] = $container->children;
        self::assertSame(0.0, $first->box->content->y);
        self::assertSame(0.0, $second->box->content->y);
        self::assertSame(0.0, $first->box->content->x);
        self::assertEqualsWithDelta($first->box->content->x + $first->box->content->width, $second->box->content->x, 0.01);
    }

    public function testFloatWithAutoWidthShrinksToItsInlineContentInsteadOfFillingTheContainer(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px;font-size:16px">'
                . '<div style="float:left"><span>curto</span></div>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        $float = $container->children[0];
        self::assertLessThan(200.0, $float->box->content->width);
        self::assertGreaterThan(0.0, $float->box->content->width);
    }

    public function testFloatWithExplicitWidthKeepsThatWidthInsteadOfShrinkToFit(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:400px">'
                . '<div style="float:left;width:120px"><span>x</span></div>'
                . '</div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        self::assertSame(120.0, $container->children[0]->box->content->width);
    }

    public function testRealWorldFooterPatternRendersBothFloatsOnTheSameLineInThePdf(): void
    {
        // Mirrors the exact pattern found in ~79% of a real-world corpus of court
        // notification documents: two floated divs used as a two-column footer.
        $html = '<footer style="margin:0;font-size:11pt">'
            . '<div style="float:left;font-weight:bold"><span>0802320-14.2026.8.19.0021</span></div>'
            . '<div style="float:right;font-weight:bold"><span>190004408723</span></div>'
            . '</footer>';

        $prepared = Pagyra::prepareHtmlRender(['html' => $html, 'viewportWidth' => 400, 'viewportHeight' => 100]);
        $footer = $prepared->layoutRoot->children[0];

        self::assertCount(2, $footer->children);
        self::assertSame($footer->children[0]->box->content->y, $footer->children[1]->box->content->y);
        self::assertEqualsWithDelta($footer->box->content->height, $footer->children[0]->box->content->height, 0.01);
    }
}
