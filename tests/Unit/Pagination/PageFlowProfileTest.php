<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Pagination;

use Pagyra\Pagination\PageFlow;
use PHPUnit\Framework\TestCase;

final class PageFlowProfileTest extends TestCase
{
    public function testPseudoPageMarginsDriveVariableContentStarts(): void
    {
        $flow = PageFlow::fromPageProfile(100.0, [
            'default' => ['top' => 10.0, 'right' => 10.0, 'bottom' => 10.0, 'left' => 10.0],
            'first' => ['top' => 20.0, 'right' => 10.0, 'bottom' => 20.0, 'left' => 10.0],
            'left' => ['top' => 5.0, 'right' => 30.0, 'bottom' => 15.0, 'left' => 10.0],
            'right' => ['top' => 10.0, 'right' => 10.0, 'bottom' => 20.0, 'left' => 25.0],
        ]);

        self::assertSame(80.0, $flow->contentHeight);
        self::assertSame(60.0, $flow->usableHeightForPage(0));
        self::assertSame(80.0, $flow->usableHeightForPage(1));
        self::assertSame(70.0, $flow->usableHeightForPage(2));
        self::assertSame(80.0, $flow->usableHeightForPage(3));

        self::assertSame(0.0, $flow->contentStartForPage(0));
        self::assertSame(60.0, $flow->contentStartForPage(1));
        self::assertSame(140.0, $flow->contentStartForPage(2));
        self::assertSame(210.0, $flow->contentStartForPage(3));

        self::assertSame(0, $flow->pageIndexAt(59.99));
        self::assertSame(1, $flow->pageIndexAt(60.0));
        self::assertSame(1, $flow->pageIndexAt(139.99));
        self::assertSame(2, $flow->pageIndexAt(140.0));

        self::assertSame(20.0, $flow->effectiveTopForPage(0));
        self::assertSame(5.0, $flow->effectiveTopForPage(1));
        self::assertSame(10.0, $flow->effectiveTopForPage(2));
        self::assertSame(10.0, $flow->marginsForPage(1)['left']);
        self::assertSame(25.0, $flow->marginsForPage(2)['left']);
    }

    public function testUniformConstructorRemainsBackwardCompatible(): void
    {
        $flow = new PageFlow(80.0);
        self::assertSame(80.0, $flow->contentHeight);
        self::assertSame(80.0, $flow->usableHeightForPage(0));
        self::assertSame(80.0, $flow->usableHeightForPage(7));
        self::assertSame(160.0, $flow->contentStartForPage(2));
        self::assertSame(2, $flow->pageIndexAt(160.0));
    }
}
