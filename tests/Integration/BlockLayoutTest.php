<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class BlockLayoutTest extends TestCase
{
    public function testNestedBlockFlowAndBoxSizing(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div class="outer"><div class="child"></div></div>',
            'css' => <<<'CSS'
.outer {
    display: block;
    width: 300px;
    margin: 10px;
    padding: 20px;
    border-width: 5px;
    border-style: solid;
}
.child {
    display: block;
    width: auto;
    height: 40px;
    box-sizing: border-box;
    margin: 2px 4px;
    padding: 3px;
    border-width: 1px;
    border-style: solid;
}
CSS,
            'viewportWidth' => 500,
            'viewportHeight' => 700,
        ]);

        $outer = $prepared->layoutRoot->children[0];
        $child = $outer->children[0];

        self::assertSame(35.0, $outer->box->content->x);
        self::assertSame(35.0, $outer->box->content->y);
        self::assertSame(300.0, $outer->box->content->width);
        self::assertSame(44.0, $outer->box->content->height);
        self::assertSame(350.0, $outer->box->borderBox()->width);
        self::assertSame(114.0, $outer->box->marginBox()->height);

        self::assertSame(43.0, $child->box->content->x);
        self::assertSame(41.0, $child->box->content->y);
        self::assertSame(284.0, $child->box->content->width);
        self::assertSame(32.0, $child->box->content->height);
        self::assertSame(292.0, $child->box->borderBox()->width);
        self::assertSame(40.0, $child->box->borderBox()->height);
        self::assertSame(300.0, $child->box->marginBox()->width);
        self::assertSame(44.0, $child->box->marginBox()->height);

        self::assertSame(500.0, $prepared->layoutRoot->box->content->width);
        self::assertSame(114.0, $prepared->layoutRoot->box->content->height);
    }

    public function testMinMaxConstraintsApplyToContentBox(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div class="box"></div>',
            'css' => '.box { display:block; width: 50px; min-width: 120px; max-width: 140px; height: 10px; min-height: 30px; }',
            'viewportWidth' => 400,
            'viewportHeight' => 600,
        ]);

        $box = $prepared->layoutRoot->children[0]->box;
        self::assertSame(120.0, $box->content->width);
        self::assertSame(30.0, $box->content->height);
    }

    public function testHorizontalAutoMarginsCenterFixedWidthBlock(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div class="centered"></div>',
            'css' => '.centered { display:block; width:100px; height:10px; margin-left:auto; margin-right:auto; }',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $box = $prepared->layoutRoot->children[0]->box;
        self::assertSame(100.0, $box->margin->left);
        self::assertSame(100.0, $box->margin->right);
        self::assertSame(100.0, $box->content->x);
        self::assertSame(300.0, $box->marginBox()->width);
    }

    public function testAdjacentVerticalMarginsCollapse(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div class="a"></div><div class="b"></div>',
            'css' => <<<'CSS'
.a { display:block; height:10px; margin-bottom:20px; }
.b { display:block; height:10px; margin-top:30px; }
CSS,
            'viewportWidth' => 100,
            'viewportHeight' => 200,
        ]);

        $a = $prepared->layoutRoot->children[0];
        $b = $prepared->layoutRoot->children[1];

        self::assertSame(0.0, $a->box->content->y);
        self::assertSame(40.0, $b->box->content->y);
        self::assertSame(30.0, $b->box->borderBox()->y - $a->box->borderBox()->bottom());
        self::assertSame(50.0, $prepared->layoutRoot->box->content->height);
    }
}
