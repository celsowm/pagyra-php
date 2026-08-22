<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class DescendantBlockPaginationTest extends TestCase
{
    public function testDescendantBlockIsClippedIntoPhysicalPageFragments(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:200px 120px; margin:20px; }</style>'
                . '<section style="margin:0">'
                . '<div style="margin:0;height:50px"></div>'
                . '<div style="margin:0;height:50px"></div>'
                . '</section>',
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(80.0, $prepared->pagination->flow->contentHeight);
        self::assertCount(2, $placement->fragments);

        $firstPageBlocks = $placement->fragments[0]->blocks;
        self::assertCount(2, $firstPageBlocks);
        self::assertSame($prepared->layoutRoot->children[0]->children[0], $firstPageBlocks[0]->node);
        self::assertSame(0.0, $firstPageBlocks[0]->pageY);
        self::assertSame(50.0, $firstPageBlocks[0]->height);
        self::assertSame($prepared->layoutRoot->children[0]->children[1], $firstPageBlocks[1]->node);
        self::assertSame(50.0, $firstPageBlocks[1]->pageY);
        self::assertSame(30.0, $firstPageBlocks[1]->height);

        $secondPageBlocks = $placement->fragments[1]->blocks;
        self::assertCount(1, $secondPageBlocks);
        self::assertSame($prepared->layoutRoot->children[0]->children[1], $secondPageBlocks[0]->node);
        self::assertSame(0.0, $secondPageBlocks[0]->pageY);
        self::assertSame(20.0, $secondPageBlocks[0]->height);
    }

    public function testDescendantHierarchyIsPreservedPerPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:200px 90px; margin:20px; }</style>'
                . '<section style="margin:0">'
                . '<div style="margin:0">'
                . '<div style="margin:0;height:30px"></div>'
                . '<div style="margin:0;height:50px"></div>'
                . '</div>'
                . '</section>',
            'viewportWidth' => 200,
            'viewportHeight' => 90,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(50.0, $prepared->pagination->flow->contentHeight);
        self::assertCount(2, $placement->fragments);

        $wrapperPage0 = $placement->fragments[0]->blocks[0];
        self::assertSame($prepared->layoutRoot->children[0]->children[0], $wrapperPage0->node);
        self::assertSame(50.0, $wrapperPage0->height);
        self::assertCount(2, $wrapperPage0->children);
        self::assertSame(30.0, $wrapperPage0->children[0]->height);
        self::assertSame(20.0, $wrapperPage0->children[1]->height);

        $wrapperPage1 = $placement->fragments[1]->blocks[0];
        self::assertSame($prepared->layoutRoot->children[0]->children[0], $wrapperPage1->node);
        self::assertSame(30.0, $wrapperPage1->height);
        self::assertCount(1, $wrapperPage1->children);
        self::assertSame($prepared->layoutRoot->children[0]->children[0]->children[1], $wrapperPage1->children[0]->node);
        self::assertSame(30.0, $wrapperPage1->children[0]->height);
    }
}
