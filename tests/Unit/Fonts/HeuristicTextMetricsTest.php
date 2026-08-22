<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class HeuristicTextMetricsTest extends TestCase
{
    public function testNormalLineHeightMatchesReferenceRatio(): void
    {
        $metrics = new HeuristicTextMetrics();
        self::assertSame(24.0, $metrics->lineHeight(new ComputedStyle(), 20.0));
    }

    public function testUnitlessAndPercentageLineHeight(): void
    {
        $metrics = new HeuristicTextMetrics();
        self::assertSame(30.0, $metrics->lineHeight(new ComputedStyle(['line-height' => '1.5']), 20.0));
        self::assertSame(30.0, $metrics->lineHeight(new ComputedStyle(['line-height' => '150%']), 20.0));
    }

    public function testMeasurementReturnsIntrinsicAndMinimumWidths(): void
    {
        $metrics = new HeuristicTextMetrics();
        $measurement = $metrics->measure('Todo poder emana', new ComputedStyle(), 16.0);

        self::assertGreaterThan(0.0, $measurement->inlineSize);
        self::assertGreaterThan(0.0, $measurement->minInlineSize);
        self::assertLessThanOrEqual($measurement->inlineSize, $measurement->minInlineSize);
        self::assertSame(19.2, $measurement->blockSize);
    }
}
