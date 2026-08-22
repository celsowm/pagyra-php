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
}
.child {
    display: block;
    width: auto;
    height: 40px;
    box-sizing: border-box;
    margin: 2px 4px;
    padding: 3px;
    border-width: 1px;
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
}
