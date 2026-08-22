<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class DescendantLineFragmentationTest extends TestCase
{
    public function testDescendantTextLinesFollowPhysicalBlockFragments(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:200px 80px; margin:20px; }</style>'
                . '<section style="margin:0">'
                . '<p style="margin:0;white-space:pre;font-size:16px;line-height:20px">one\ntwo\nthree</p>'
                . '</section>',
            'viewportWidth' => 200,
            'viewportHeight' => 80,
        ]);

        $placement = $prepared->pagination->placements[0];
        self::assertSame(40.0, $prepared->pagination->flow->contentHeight);
        self::assertCount(2, $placement->fragments);

        $paragraphPage0 = $placement->fragments[0]->blocks[0];
        self::assertCount(2, $paragraphPage0->lines);
        self::assertSame('one', $paragraphPage0->lines[0]->line->text);
        self::assertSame('two', $paragraphPage0->lines[1]->line->text);

        $paragraphPage1 = $placement->fragments[1]->blocks[0];
        self::assertCount(1, $paragraphPage1->lines);
        self::assertSame('three', $paragraphPage1->lines[0]->line->text);
        self::assertSame(0.0, $paragraphPage1->lines[0]->pageY);
    }
}
