<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class ForcedPageBreakPaginationTest extends TestCase
{
    public function testBreakBeforeMovesFollowingBlockToNextPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'p { margin:0; height:40px; }'
                . '#second { break-before:page; }'
                . '</style><p>one</p><p id="second">two</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(160.0, $prepared->pagination->flow->contentHeight);
        self::assertSame(0, $prepared->pagination->placements[0]->pageIndex);
        self::assertSame(1, $prepared->pagination->placements[1]->pageIndex);
        self::assertSame(120.0, $prepared->pagination->placements[1]->offsetY);
        self::assertSame(160.0, $prepared->pagination->placements[1]->startY);
        self::assertSame(2, $prepared->pagination->pageCount);

        self::assertSame(40.0, $prepared->layoutRoot->children[1]->box->marginBox()->y);
    }

    public function testLegacyPageBreakAfterAlwaysMovesNextBlock(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'p { margin:0; height:40px; }'
                . '#first { page-break-after:always; }'
                . '</style><p id="first">one</p><p>two</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(0, $prepared->pagination->placements[0]->pageIndex);
        self::assertSame(1, $prepared->pagination->placements[1]->pageIndex);
        self::assertSame(120.0, $prepared->pagination->placements[1]->offsetY);
        self::assertSame(2, $prepared->pagination->pageCount);
    }

    public function testRightBreakCanSkipAParityPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'p { margin:0; height:40px; }'
                . '#second { break-before:right; }'
                . '</style><p>one</p><p id="second">two</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(2, $prepared->pagination->placements[1]->pageIndex);
        self::assertSame(280.0, $prepared->pagination->placements[1]->offsetY);
        self::assertSame(320.0, $prepared->pagination->placements[1]->startY);
        self::assertSame(3, $prepared->pagination->pageCount);
    }

    public function testTallBlockReportsAllPagesItSpans(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'div { margin:0; height:350px; }'
                . '</style><div>tall</div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertNotNull($prepared->pagination);
        $placement = $prepared->pagination->placements[0];
        self::assertSame(0, $placement->pageIndex);
        self::assertSame(2, $placement->endPageIndex);
        self::assertSame(3, $prepared->pagination->pageCount);
    }
}
