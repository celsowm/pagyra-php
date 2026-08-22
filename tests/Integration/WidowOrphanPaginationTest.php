<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class WidowOrphanPaginationTest extends TestCase
{
    public function testDefaultWidowsMoveShortParagraphToNextPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<style>@page { size:300px 100px; margin:10px; } .spacer { height:40px; margin:0; } p { margin:0; white-space:pre; font-size:16px; line-height:20px; }</style><div class=\"spacer\"></div><p>one\ntwo\nthree</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(80.0, $prepared->pagination->flow->contentHeight);

        $paragraph = $prepared->pagination->placements[1];
        self::assertSame(1, $paragraph->pageIndex);
        self::assertSame(1, $paragraph->endPageIndex);
        self::assertSame(40.0, $paragraph->offsetY);
        self::assertCount(1, $paragraph->fragments);
        self::assertCount(3, $paragraph->fragments[0]->lines);
        self::assertSame(['one', 'two', 'three'], array_map(
            static fn($line): string => $line->line->text,
            $paragraph->fragments[0]->lines,
        ));

        self::assertSame(40.0, $prepared->layoutRoot->children[1]->box->marginBox()->y);
    }

    public function testWidowsAndOrphansOneAllowNaturalSplit(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<style>@page { size:300px 100px; margin:10px; } .spacer { height:40px; margin:0; } p { margin:0; white-space:pre; font-size:16px; line-height:20px; widows:1; orphans:1; }</style><div class=\"spacer\"></div><p>one\ntwo\nthree</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        $paragraph = $prepared->pagination->placements[1];
        self::assertSame(0.0, $paragraph->offsetY);
        self::assertSame(0, $paragraph->pageIndex);
        self::assertSame(1, $paragraph->endPageIndex);
        self::assertCount(2, $paragraph->fragments[0]->lines);
        self::assertCount(1, $paragraph->fragments[1]->lines);
    }

    public function testOversizedParagraphIsNotMovedForWidowOrphanConstraint(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<style>@page { size:300px 100px; margin:10px; } p { margin:0; white-space:pre; font-size:16px; line-height:20px; widows:4; orphans:4; }</style><p>one\ntwo\nthree\nfour\nfive</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        $paragraph = $prepared->pagination->placements[0];
        self::assertSame(0.0, $paragraph->offsetY);
        self::assertSame(0, $paragraph->pageIndex);
        self::assertGreaterThan(0, $paragraph->endPageIndex);
    }
}
