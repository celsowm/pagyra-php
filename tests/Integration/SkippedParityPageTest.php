<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use PHPUnit\Framework\TestCase;

final class SkippedParityPageTest extends TestCase
{
    private const PAGE = '<style>@page{size:200px 100px;margin:0} section,div{margin:0}</style>';

    public function testPageSkippedByRightBreakCarriesNoEntry(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => self::PAGE
                . '<section><div style="height:20px"></div><div style="height:20px;break-before:right"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertSame(3, $prepared->pagination->pageCount);
        self::assertCount(0, $prepared->pagination->pages[1]->entries);
        self::assertCount(1, $prepared->pagination->pages[0]->entries);
        self::assertCount(1, $prepared->pagination->pages[2]->entries);
    }

    public function testSkippedPageStaysBlankEvenWhenTheWrapperHasABackground(): void
    {
        // The wrapper spans the skipped page only because its subtree was pushed across it, so
        // painting its background there would fill a page that must come out empty.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => self::PAGE
                . '<section style="background:#eee"><div style="height:20px"></div>'
                . '<div style="height:20px;break-before:right"></div></section>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertSame([], $prepared->displayList->pages[1]->commands);
        self::assertNotSame([], $prepared->displayList->pages[0]->commands);
        self::assertNotSame([], $prepared->displayList->pages[2]->commands);
    }

    public function testABlockThatGenuinelySpansPagesStillPaintsOnEveryOne(): void
    {
        // The other side of the same coin: here the element's own box really does cover the
        // middle page, so it has to keep painting there.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => self::PAGE . '<div style="height:260px;background:#ccc"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertCount(3, $prepared->displayList->pages);
        foreach ($prepared->displayList->pages as $page) {
            $backgrounds = array_filter(
                $page->commands,
                static fn (object $c): bool => $c instanceof BoxPaintCommand && $c->backgroundColor !== null,
            );
            self::assertNotSame([], $backgrounds, "pagina {$page->pageIndex} perdeu o fundo");
        }
    }

    public function testLeafWithOnlyABorderKeepsItsEntry(): void
    {
        // A leaf carries no blocks and no lines, so it must qualify by its own box alone.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => self::PAGE . '<div style="height:40px;border:2px solid red"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertCount(1, $prepared->pagination->pages[0]->entries);
        self::assertNotSame([], $prepared->displayList->pages[0]->commands);
    }
}
