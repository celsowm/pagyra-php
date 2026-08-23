<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\PageStyleProfileResolver;
use PHPUnit\Framework\TestCase;

final class PageStyleProfileResolverTest extends TestCase
{
    public function testFirstLeftAndRightMarginsResolveWithPageSpecificity(): void
    {
        $resolved = (new PageStyleProfileResolver())->resolve(
            '@page { size: 200px 100px; margin: 10px; }'
            . '@page :right { margin-left: 30px; margin-top: 12px; }'
            . '@page :left { margin-right: 40px; }'
            . '@page :first { margin-left: 50px; margin-top: 20px; }',
            200.0,
            100.0,
            ['top' => 5.0, 'right' => 5.0, 'bottom' => 5.0, 'left' => 5.0],
        );

        self::assertSame(200.0, $resolved['width']);
        self::assertSame(100.0, $resolved['height']);
        self::assertSame(['top' => 10.0, 'right' => 10.0, 'bottom' => 10.0, 'left' => 10.0], $resolved['margins']['default']);
        self::assertSame(['top' => 20.0, 'right' => 10.0, 'bottom' => 10.0, 'left' => 50.0], $resolved['margins']['first']);
        self::assertSame(['top' => 10.0, 'right' => 40.0, 'bottom' => 10.0, 'left' => 10.0], $resolved['margins']['left']);
        self::assertSame(['top' => 12.0, 'right' => 10.0, 'bottom' => 10.0, 'left' => 30.0], $resolved['margins']['right']);
    }

    public function testImportantDefaultMarginCanBeatPseudoRule(): void
    {
        $resolved = (new PageStyleProfileResolver())->resolve(
            '@page { margin-left: 11px !important; margin-top: 9px; }'
            . '@page :left { margin-left: 44px; margin-top: 22px; }',
            200.0,
            100.0,
            ['top' => 5.0, 'right' => 5.0, 'bottom' => 5.0, 'left' => 5.0],
        );

        self::assertSame(11.0, $resolved['margins']['left']['left']);
        self::assertSame(22.0, $resolved['margins']['left']['top']);
    }

    public function testInactiveMediaPageRuleDoesNotAffectProfile(): void
    {
        $resolved = (new PageStyleProfileResolver())->resolve(
            '@page { margin: 10px; }'
            . '@media screen { @page :left { margin-left: 70px; } }'
            . '@media print { @page :right { margin-left: 33px; } }',
            200.0,
            100.0,
            ['top' => 5.0, 'right' => 5.0, 'bottom' => 5.0, 'left' => 5.0],
            'print',
            180.0,
            80.0,
        );

        self::assertSame(10.0, $resolved['margins']['left']['left']);
        self::assertSame(33.0, $resolved['margins']['right']['left']);
    }
}
