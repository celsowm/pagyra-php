<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class InlineBlockBlockFormattingContextTest extends TestCase
{
    public function testBlockChildInsideInlineBlockBecomesRealNestedLayoutNode(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<p style="margin:0;font-size:10px;line-height:20px">'
                . '<span style="display:inline-block;width:80px">'
                . '<div style="height:30px">inside</div>'
                . '</span>'
                . '</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame([], $box->contentLines);
        self::assertCount(1, $box->contentBlocks);
        self::assertSame('div', $box->contentBlocks[0]->source->node->tagName);
        self::assertGreaterThanOrEqual(30.0, $box->contentHeight);
        self::assertSame('inside', trim($box->contentBlocks[0]->lineBoxes[0]->text));
    }

    public function testMixedInlineAndBlockContentKeepsDocumentPaintOrder(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<p style="margin:0;font-size:10px;line-height:20px">'
                . '<span style="display:inline-block;width:100px">'
                . 'before<div style="margin:0">middle</div>after'
                . '</span>'
                . '</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertCount(3, $box->contentBlocks);
        self::assertSame(BlockLayoutEngine::ANONYMOUS_TAG, $box->contentBlocks[0]->source->node->tagName);
        self::assertSame('div', $box->contentBlocks[1]->source->node->tagName);
        self::assertSame(BlockLayoutEngine::ANONYMOUS_TAG, $box->contentBlocks[2]->source->node->tagName);

        $painted = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) {
                $text = trim($command->text);
                if ($text !== '') $painted[] = $text;
            }
        }

        self::assertSame(['before', 'middle', 'after'], $painted);
    }

    public function testInlineBlockBaselineComesFromLastLineInsideBlockDescendant(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<p style="margin:0;font-size:10px;line-height:20px">'
                . 'A <span style="display:inline-block;width:60px">'
                . '<div style="margin:0">alpha</div>'
                . '<div style="margin:0">omega</div>'
                . '</span> Z'
                . '</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        $box = $line->atomicBoxes[0];
        self::assertCount(2, $box->contentBlocks);

        $lastLine = $box->contentBlocks[1]->lineBoxes[0];
        self::assertSame('omega', trim($lastLine->text));
        self::assertEqualsWithDelta($line->baseline, $lastLine->baseline, 0.0001);
    }
}
