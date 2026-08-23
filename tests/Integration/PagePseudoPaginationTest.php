<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PagePseudoPaginationTest extends TestCase
{
    public function testFirstLeftRightMarginsDriveVariablePagination(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:200px 100px; margin:10px; }'
                . '@page :first { margin-top:20px; margin-bottom:20px; }'
                . '@page :left { margin-top:5px; margin-bottom:15px; margin-left:25px; }'
                . '@page :right { margin-top:10px; margin-bottom:20px; margin-left:30px; }'
                . 'div { margin:0; height:190px; }'
                . '</style><div></div>',
            'pageWidth' => 200.0,
            'pageHeight' => 100.0,
            'viewportWidth' => 200.0,
            'viewportHeight' => 100.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertNotNull($prepared->pageMargins);
        self::assertSame(20.0, $prepared->pageMargins['first']['top']);
        self::assertSame(25.0, $prepared->pageMargins['left']['left']);
        self::assertSame(30.0, $prepared->pageMargins['right']['left']);

        $flow = $prepared->pagination->flow;
        self::assertSame(60.0, $flow->usableHeightForPage(0));
        self::assertSame(80.0, $flow->usableHeightForPage(1));
        self::assertSame(70.0, $flow->usableHeightForPage(2));
        self::assertSame(0.0, $flow->contentStartForPage(0));
        self::assertSame(60.0, $flow->contentStartForPage(1));
        self::assertSame(140.0, $flow->contentStartForPage(2));

        $placement = $prepared->pagination->placements[0];
        self::assertSame(0, $placement->pageIndex);
        self::assertSame(2, $placement->endPageIndex);
        self::assertCount(3, $placement->fragments);
        self::assertSame(60.0, $placement->fragments[0]->height);
        self::assertSame(80.0, $placement->fragments[1]->height);
        self::assertSame(50.0, $placement->fragments[2]->height);
    }

    public function testPrintViewportUsesMostConstrainedPageVariant(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:200px 120px; margin:10px; }'
                . '@page :first { margin-left:40px; margin-right:30px; }'
                . '@page :left { margin-top:30px; margin-bottom:20px; }'
                . 'div { margin:0; width:auto; height:10px; }'
                . '</style><div></div>',
            'pageWidth' => 200.0,
            'pageHeight' => 120.0,
            'viewportWidth' => 500.0,
            'viewportHeight' => 500.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $box = $prepared->layoutRoot->children[0]->box->content;
        self::assertSame(130.0, $box->width);
        self::assertSame(70.0, $prepared->pagination->flow->minimumUsableHeight());
    }
}
