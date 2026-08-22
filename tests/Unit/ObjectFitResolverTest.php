<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Geometry\Rect;
use Pagyra\Image\ObjectFit;
use Pagyra\Image\ObjectFitResolver;
use Pagyra\Image\ObjectPosition;
use PHPUnit\Framework\TestCase;

final class ObjectFitResolverTest extends TestCase
{
    public function testFillReturnsContentBox(): void
    {
        $box = new Rect(10.0, 20.0, 200.0, 100.0);

        self::assertEquals(
            $box,
            ObjectFitResolver::resolve(400.0, 400.0, $box, ObjectFit::Fill),
        );
    }

    public function testContainPreservesAspectRatioAndCenters(): void
    {
        $rect = ObjectFitResolver::resolve(
            400.0,
            200.0,
            new Rect(10.0, 20.0, 100.0, 100.0),
            ObjectFit::Contain,
        );

        self::assertSame(10.0, $rect->x);
        self::assertSame(45.0, $rect->y);
        self::assertSame(100.0, $rect->width);
        self::assertSame(50.0, $rect->height);
        self::assertFalse(ObjectFitResolver::needsClip($rect, new Rect(10.0, 20.0, 100.0, 100.0)));
    }

    public function testCoverPreservesAspectRatioAndNeedsClip(): void
    {
        $box = new Rect(0.0, 0.0, 100.0, 100.0);
        $rect = ObjectFitResolver::resolve(400.0, 200.0, $box, ObjectFit::Cover);

        self::assertSame(-50.0, $rect->x);
        self::assertSame(0.0, $rect->y);
        self::assertSame(200.0, $rect->width);
        self::assertSame(100.0, $rect->height);
        self::assertTrue(ObjectFitResolver::needsClip($rect, $box));
    }

    public function testNoneKeepsIntrinsicSizeAndUsesObjectPosition(): void
    {
        $rect = ObjectFitResolver::resolve(
            20.0,
            10.0,
            new Rect(100.0, 200.0, 100.0, 50.0),
            ObjectFit::None,
            new ObjectPosition(1.0, 1.0),
        );

        self::assertSame(180.0, $rect->x);
        self::assertSame(240.0, $rect->y);
        self::assertSame(20.0, $rect->width);
        self::assertSame(10.0, $rect->height);
    }

    public function testScaleDownNeverUpscales(): void
    {
        $rect = ObjectFitResolver::resolve(
            20.0,
            10.0,
            new Rect(0.0, 0.0, 100.0, 100.0),
            ObjectFit::ScaleDown,
        );

        self::assertSame(40.0, $rect->x);
        self::assertSame(45.0, $rect->y);
        self::assertSame(20.0, $rect->width);
        self::assertSame(10.0, $rect->height);
    }

    public function testZeroIntrinsicDimensionFallsBackToContentBox(): void
    {
        $box = new Rect(5.0, 6.0, 70.0, 80.0);

        self::assertEquals(
            $box,
            ObjectFitResolver::resolve(0.0, 100.0, $box, ObjectFit::Contain),
        );
    }
}
