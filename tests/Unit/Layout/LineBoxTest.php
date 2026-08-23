<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Layout;

use Pagyra\Layout\LineBox;
use Pagyra\Layout\TextRun;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class LineBoxTest extends TestCase
{
    public function testFallbackOrderingKeepsEqualPositionTextRunsStable(): void
    {
        $style = new ComputedStyle();
        $first = new TextRun(10.0, 0.0, 0.0, 10.0, 8.0, 'a', 10.0, $style);
        $second = new TextRun(10.0, 0.0, 0.0, 10.0, 8.0, 'b', 10.0, $style);

        $line = new LineBox(0.0, 0.0, 20.0, 10.0, 8.0, 'ab', [$first, $second]);

        self::assertSame([$first, $second], $line->orderedItems());
    }
}
