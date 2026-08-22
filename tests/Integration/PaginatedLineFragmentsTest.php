<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PaginatedLineFragmentsTest extends TestCase
{
    public function testPreformattedLinesAreAssignedToPageFragmentsByBaseline(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<style>@page { size:300px 50px; margin:5px; } p { margin:0; white-space:pre; font-size:16px; line-height:20px; }</style><p>one\ntwo\nthree\nfour\nfive</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(40.0, $prepared->pagination->flow->contentHeight);
        self::assertSame(3, $prepared->pagination->pageCount);

        $placement = $prepared->pagination->placements[0];
        self::assertCount(3, $placement->fragments);
        self::assertSame([2, 2, 1], array_map(
            static fn($fragment): int => count($fragment->lines),
            $placement->fragments,
        ));

        self::assertSame(['one', 'two'], array_map(
            static fn($line): string => $line->line->text,
            $placement->fragments[0]->lines,
        ));
        self::assertSame(['three', 'four'], array_map(
            static fn($line): string => $line->line->text,
            $placement->fragments[1]->lines,
        ));
        self::assertSame(['five'], array_map(
            static fn($line): string => $line->line->text,
            $placement->fragments[2]->lines,
        ));

        foreach ($placement->fragments as $fragment) {
            foreach ($fragment->lines as $line) {
                self::assertSame($fragment->pageIndex, $line->pageIndex);
                self::assertGreaterThanOrEqual(0.0, $line->pageBaseline);
                self::assertLessThanOrEqual(40.0, $line->pageBaseline);
            }
        }

        $originalLines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertSame(0.0, $originalLines[0]->y);
        self::assertSame(80.0, $originalLines[4]->y);
    }

    public function testForcedBreakOffsetIsAppliedToPaginatedLineCoordinatesOnly(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<style>@page { size:300px 60px; margin:10px; } p { margin:0; white-space:pre; font-size:16px; line-height:20px; } #second { break-before:page; }</style><p>one</p><p id=\"second\">two\nthree</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        $second = $prepared->pagination->placements[1];
        self::assertSame(1, $second->pageIndex);
        self::assertGreaterThan(0.0, $second->offsetY);
        self::assertCount(2, $second->fragments[0]->lines);

        $fragmentLine = $second->fragments[0]->lines[0];
        $originalLine = $prepared->layoutRoot->children[1]->lineBoxes[0];
        self::assertEqualsWithDelta($originalLine->y + $second->offsetY, $fragmentLine->continuousY, 0.0001);
        self::assertSame($originalLine->y, $fragmentLine->line->y);
    }
}
