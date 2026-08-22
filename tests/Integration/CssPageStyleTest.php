<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class CssPageStyleTest extends TestCase
{
    public function testEmbeddedPageRuleChangesPreparedPageSizeAndMargins(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size: letter landscape; margin: 0.5in 1in; }</style><p>Hello</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        self::assertEqualsWithDelta(792.0, $prepared->pageSize['widthPt'], 0.0001);
        self::assertEqualsWithDelta(612.0, $prepared->pageSize['heightPt'], 0.0001);
        self::assertSame([
            'top' => 48.0,
            'right' => 96.0,
            'bottom' => 48.0,
            'left' => 96.0,
        ], $prepared->margins);
    }

    public function testPageRuleFallsBackToExplicitOptionsWhenSizeIsAuto(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size: auto; margin-top: 10px; }</style><p>Hello</p>',
            'pageWidth' => 640,
            'pageHeight' => 900,
            'margins' => ['top' => 20, 'right' => 30, 'bottom' => 40, 'left' => 50],
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        self::assertEqualsWithDelta(480.0, $prepared->pageSize['widthPt'], 0.0001);
        self::assertEqualsWithDelta(675.0, $prepared->pageSize['heightPt'], 0.0001);
        self::assertSame([
            'top' => 10.0,
            'right' => 30.0,
            'bottom' => 40.0,
            'left' => 50.0,
        ], $prepared->margins);
    }
}
