<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PaginatedBorderRadiusTest extends TestCase
{
    public function testOnlyOuterPageFragmentsKeepRoundedCorners(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:200px 80px;margin:10px}</style>'
                . '<div style="margin:0;height:150px;background:red;border-radius:20px"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 80,
        ]);

        self::assertCount(3, $prepared->displayList->pages);
        $boxes = [];
        foreach ($prepared->displayList->pages as $page) {
            $pageBoxes = array_values(array_filter(
                $page->commands,
                static fn (object $command): bool => $command instanceof BoxPaintCommand && $command->backgroundColor !== null,
            ));
            self::assertNotEmpty($pageBoxes);
            $boxes[] = $pageBoxes[0];
        }

        self::assertGreaterThan(0.0, $boxes[0]->borderRadius->topLeft->x);
        self::assertSame(0.0, $boxes[0]->borderRadius->bottomLeft->x);

        self::assertTrue($boxes[1]->borderRadius->isZero());

        self::assertSame(0.0, $boxes[2]->borderRadius->topLeft->x);
        self::assertGreaterThan(0.0, $boxes[2]->borderRadius->bottomLeft->x);
    }
}
