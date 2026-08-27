<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PrintMediaPipelineTest extends TestCase
{
    public function testPrepareHtmlRenderUsesPrintMediaAndViewport(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '
                <style>
                    p { color: black; width: 100px; }
                    @media screen { p { color: blue; width: 200px; } }
                    @media print and (min-width: 700px) { p { color: red; width: 320px; } }
                </style>
                <p>Hello</p>
            ',
            'viewportWidth' => 800,
            'viewportHeight' => 600,
            'pageWidth' => 800,
            'pageHeight' => 600,
            'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
        ]);

        $p = $prepared->styledRoot->children[3];
        self::assertSame('red', $p->style->get('color'));
        self::assertSame('320px', $p->style->get('width'));
        self::assertSame(320.0, $prepared->layoutRoot->children[0]->box->content->width);
    }

    public function testPortraitMediaUsesRenderViewportHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '
                <style>
                    p { width: 100px; }
                    @media print and (orientation: portrait) { p { width: 180px; } }
                </style>
                <p>Hello</p>
            ',
            'viewportWidth' => 600,
            'viewportHeight' => 900,
        ]);

        self::assertSame('180px', $prepared->styledRoot->children[3]->style->get('width'));
    }
}
