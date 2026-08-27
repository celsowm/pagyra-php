<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PageViewportMediaQueryTest extends TestCase
{
    public function testPageContentAreaConstrainsPrintViewportForMediaQueries(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:500px 300px; margin:50px; }'
                . '@media print and (max-width:450px) { p { width:123px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 794,
            'viewportHeight' => 1123,
        ]);

        self::assertSame('123px', $prepared->styledRoot->children[1]->style->get('width'));
        self::assertSame(123.0, $prepared->layoutRoot->children[0]->box->content->width);
        self::assertSame(375.0, $prepared->pageSize['widthPt']);
        self::assertSame(225.0, $prepared->pageSize['heightPt']);
    }

    public function testExplicitSmallerViewportRemainsTheUpperBound(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:800px 600px; margin:50px; }'
                . '@media print and (max-width:350px) { p { width:111px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 320,
            'viewportHeight' => 240,
        ]);

        self::assertSame('111px', $prepared->styledRoot->children[1]->style->get('width'));
        self::assertSame(111.0, $prepared->layoutRoot->children[0]->box->content->width);
    }

    public function testPageMediaRulesAreReevaluatedUntilViewportStabilizes(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@media print and (max-width:700px) {'
                . '  @page { size:500px 300px; margin:50px; }'
                . '}'
                . '@media print and (max-width:450px) { p { width:123px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 794,
            'viewportHeight' => 1123,
        ]);

        self::assertSame(375.0, $prepared->pageSize['widthPt']);
        self::assertSame(225.0, $prepared->pageSize['heightPt']);
        self::assertSame(50.0, $prepared->margins['left']);
        self::assertSame('123px', $prepared->styledRoot->children[1]->style->get('width'));
        self::assertSame(123.0, $prepared->layoutRoot->children[0]->box->content->width);
    }
}
