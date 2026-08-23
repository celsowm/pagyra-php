<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class BreakInsidePaginationTest extends TestCase
{
    public function testBreakInsideAvoidMovesCrossingBlockToNextPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 100px; margin:10px; } .spacer { height:50px; margin:0; } .keep { height:40px; margin:0; break-inside:avoid; }</style><div class="spacer"></div><div class="keep"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        $keep = $prepared->pagination->placements[1];
        self::assertSame(1, $keep->pageIndex);
        self::assertSame(30.0, $keep->offsetY);
        self::assertSame(80.0, $keep->startY);
        self::assertSame(1, $keep->endPageIndex);
    }

    public function testAvoidPageAliasUsesSameBehavior(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 100px; margin:10px; } .spacer { height:50px; margin:0; } .keep { height:40px; margin:0; break-inside:avoid-page; }</style><div class="spacer"></div><div class="keep"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(1, $prepared->pagination->placements[1]->pageIndex);
        self::assertSame(30.0, $prepared->pagination->placements[1]->offsetY);
    }

    public function testLegacyPageBreakInsideAvoidIsSupported(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 100px; margin:10px; } .spacer { height:50px; margin:0; } .keep { height:40px; margin:0; page-break-inside:avoid; }</style><div class="spacer"></div><div class="keep"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(1, $prepared->pagination->placements[1]->pageIndex);
    }

    public function testOversizedAvoidBoxStillMovesToNextPageLikeReference(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 100px; margin:10px; } .spacer { height:50px; margin:0; } .keep { height:100px; margin:0; break-inside:avoid; }</style><div class="spacer"></div><div class="keep"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        $keep = $prepared->pagination->placements[1];
        self::assertSame(30.0, $keep->offsetY);
        self::assertSame(80.0, $keep->startY);
        self::assertGreaterThan($keep->pageIndex, $keep->endPageIndex);
    }

    public function testForcedBreakRunsBeforeBreakInsideAvoidPass(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 100px; margin:10px; } .spacer { height:30px; margin:0; } .keep { height:100px; margin:0; break-before:page; break-inside:avoid; }</style><div class="spacer"></div><div class="keep"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        $keep = $prepared->pagination->placements[1];
        self::assertSame(130.0, $keep->offsetY);
        self::assertSame(160.0, $keep->startY);
        self::assertSame(2, $keep->pageIndex);
    }
}
