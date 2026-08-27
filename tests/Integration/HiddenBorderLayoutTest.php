<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class HiddenBorderLayoutTest extends TestCase
{
    public function testNoneBorderStyleZerosBlockAndAtomicBorderGeometry(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:100px;height:20px;border-width:10px;border-style:none">'
                . '<img width="20" height="10" style="border-width:10px;border-style:none">'
                . '</div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $block = $prepared->layoutRoot->children[0];
        self::assertSame(0.0, $block->box->border->top);
        self::assertSame(0.0, $block->box->border->right);
        self::assertSame(0.0, $block->box->border->bottom);
        self::assertSame(0.0, $block->box->border->left);
        self::assertSame(100.0, $block->box->content->width);

        self::assertNotEmpty($block->lineBoxes);
        self::assertNotEmpty($block->lineBoxes[0]->atomicBoxes);
        $image = $block->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(0.0, $image->border['top']);
        self::assertSame(0.0, $image->border['right']);
        self::assertSame(0.0, $image->border['bottom']);
        self::assertSame(0.0, $image->border['left']);
        self::assertSame(20.0, $image->contentWidth);
        self::assertSame(10.0, $image->contentHeight);
    }
}
