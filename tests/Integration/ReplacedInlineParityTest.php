<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class ReplacedInlineParityTest extends TestCase
{
    public function testAutoImageShrinksAgainstAvailableContentWidthAfterOuterExtras(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img width="200" height="100" style="margin:3px;padding:10px;border-width:2px"></p>',
            'viewportWidth' => 100,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(70.0, $box->contentWidth);
        self::assertSame(35.0, $box->contentHeight);
        self::assertSame(100.0, $box->width);
    }

    public function testInlineSvgParticipatesAsAtomicReplacedElement(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><svg viewBox="0 0 400 200"></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertTrue($box->source->node->isSvg());
        self::assertSame(400.0, $box->contentWidth);
        self::assertSame(200.0, $box->contentHeight);
    }
}
