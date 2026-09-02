<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class RecursivePaginationBehaviorTest extends TestCase
{
    public function testDescendantBreakBeforeMovesOnlyThatDescendantAndFollowingFlow(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:200px 100px;margin:0} section,div{margin:0}</style>'
                . '<section><div style="height:20px"></div><div style="height:20px;break-before:page"></div><div style="height:20px"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(0.0, $placement->offsetY);
        self::assertSame(0, $placement->pageIndex);
        self::assertSame(1, $placement->endPageIndex);

        $page0 = $placement->fragments[0];
        $page1 = $placement->fragments[1];
        self::assertCount(1, $page0->blocks);
        self::assertSame(0.0, $page0->blocks[0]->pageY);
        self::assertCount(2, $page1->blocks);
        self::assertSame(0.0, $page1->blocks[0]->pageY);
        self::assertSame(20.0, $page1->blocks[1]->pageY);
    }

    public function testDescendantRightBreakPreservesSkippedParityPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:200px 100px;margin:0} section,div{margin:0}</style>'
                . '<section><div style="height:20px"></div><div style="height:20px;break-before:right"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(2, $placement->endPageIndex);
        self::assertSame(3, $prepared->pagination->pageCount);
        self::assertCount(0, $prepared->pagination->pages[1]->entries);
        self::assertCount(1, $placement->fragments[0]->blocks);
        self::assertCount(0, $placement->fragments[1]->blocks);
        self::assertCount(1, $placement->fragments[2]->blocks);
        self::assertSame(0.0, $placement->fragments[2]->blocks[0]->pageY);
    }

    public function testDescendantBreakAfterMovesFollowingSibling(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:200px 100px;margin:0} section,div{margin:0}</style>'
                . '<section><div style="height:20px;break-after:page"></div><div style="height:20px"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(1, $placement->endPageIndex);
        self::assertCount(1, $placement->fragments[0]->blocks);
        self::assertCount(1, $placement->fragments[1]->blocks);
        self::assertSame(0.0, $placement->fragments[1]->blocks[0]->pageY);
    }

    public function testDescendantBreakInsideAvoidMovesWholeBoxToNextPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:200px 100px;margin:0} section,div{margin:0}</style>'
                . '<section><div style="height:80px"></div><div style="height:30px;break-inside:avoid"></div><div style="height:10px"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertCount(1, $placement->fragments[0]->blocks);
        self::assertCount(2, $placement->fragments[1]->blocks);
        self::assertSame(0.0, $placement->fragments[1]->blocks[0]->pageY);
        self::assertSame(30.0, $placement->fragments[1]->blocks[1]->pageY);
    }

    public function testDescendantWidowsMoveParagraphInsideWrapper(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:300px 80px;margin:0} section,div,p{margin:0} p{white-space:pre;font-size:16px;line-height:20px}</style>'
                . '<section><div style="height:40px"></div><p>one' . "\n" . 'two' . "\n" . 'three</p></section>',
            'viewportWidth' => 300,
            'viewportHeight' => 80,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(1, $placement->endPageIndex);
        $page1 = $placement->fragments[1];
        self::assertCount(1, $page1->blocks);
        self::assertSame(0.0, $page1->blocks[0]->pageY);
        self::assertCount(3, $page1->blocks[0]->lines);
    }
}
