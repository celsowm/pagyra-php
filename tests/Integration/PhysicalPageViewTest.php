<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PhysicalPageViewTest extends TestCase
{
    public function testRightBreakPreservesSkippedPhysicalPage(): void
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

        self::assertSame(3, $prepared->pagination->pageCount);
        self::assertCount(3, $prepared->pagination->pages);
        self::assertSame(0, $prepared->pagination->pages[0]->pageIndex);
        self::assertCount(1, $prepared->pagination->pages[0]->entries);
        self::assertSame(1, $prepared->pagination->pages[1]->pageIndex);
        self::assertCount(0, $prepared->pagination->pages[1]->entries);
        self::assertSame(2, $prepared->pagination->pages[2]->pageIndex);
        self::assertCount(1, $prepared->pagination->pages[2]->entries);
        self::assertSame(
            $prepared->pagination->placements[1],
            $prepared->pagination->pages[2]->entries[0]->placement,
        );
        self::assertSame(2, $prepared->pagination->pages[2]->entries[0]->fragment->pageIndex);
    }

    public function testEntriesPreserveTopLevelFlowOrderWithinPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:300px 200px; margin:20px; } p { margin:0; height:40px; }</style>'
                . '<p>one</p><p>two</p><p>three</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $page = $prepared->pagination->pages[0];
        self::assertCount(3, $page->entries);
        self::assertSame($prepared->pagination->placements[0], $page->entries[0]->placement);
        self::assertSame($prepared->pagination->placements[1], $page->entries[1]->placement);
        self::assertSame($prepared->pagination->placements[2], $page->entries[2]->placement);
    }
}
