<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Image\ReplacedElementSizingResolver;
use PHPUnit\Framework\TestCase;

final class ReplacedElementSizingResolverTest extends TestCase
{
    public function testBorderBoxWidthSubtractsPaddingAndBorderBeforeAspectRatio(): void
    {
        $size = (new ReplacedElementSizingResolver())->resolve(
            intrinsicWidth: 200.0,
            intrinsicHeight: 100.0,
            specifiedWidth: 100.0,
            boxSizing: 'border-box',
            horizontalExtras: 20.0,
        );

        self::assertSame(80.0, $size->width);
        self::assertSame(40.0, $size->height);
    }

    public function testContentBoxWidthDoesNotSubtractExtras(): void
    {
        $size = (new ReplacedElementSizingResolver())->resolve(
            intrinsicWidth: 200.0,
            intrinsicHeight: 100.0,
            specifiedWidth: 100.0,
            boxSizing: 'content-box',
            horizontalExtras: 20.0,
        );

        self::assertSame(100.0, $size->width);
        self::assertSame(50.0, $size->height);
    }

    public function testBorderBoxHeightSubtractsVerticalExtrasAndPreservesRatio(): void
    {
        $size = (new ReplacedElementSizingResolver())->resolve(
            intrinsicWidth: 200.0,
            intrinsicHeight: 100.0,
            specifiedHeight: 60.0,
            boxSizing: 'border-box',
            verticalExtras: 10.0,
        );

        self::assertSame(100.0, $size->width);
        self::assertSame(50.0, $size->height);
    }

    public function testMaxWidthConstraintUsesBorderBoxContentLimitAndPreservesRatio(): void
    {
        $size = (new ReplacedElementSizingResolver())->resolve(
            intrinsicWidth: 200.0,
            intrinsicHeight: 100.0,
            boxSizing: 'border-box',
            horizontalExtras: 20.0,
            maxWidth: 100.0,
        );

        self::assertSame(80.0, $size->width);
        self::assertSame(40.0, $size->height);
    }

    public function testExplicitBothDimensionsDoNotForceIntrinsicRatio(): void
    {
        $size = (new ReplacedElementSizingResolver())->resolve(
            intrinsicWidth: 200.0,
            intrinsicHeight: 100.0,
            specifiedWidth: 120.0,
            specifiedHeight: 90.0,
        );

        self::assertSame(120.0, $size->width);
        self::assertSame(90.0, $size->height);
    }
}
