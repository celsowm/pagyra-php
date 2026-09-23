<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class UnsupportedDisplayFallbackTest extends TestCase
{
    public function testDisplayFlexFallsBackToBlockInsteadOfDisappearingFromLayout(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:300px"><footer style="display:flex"><p>conteudo</p></footer></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $outer = $prepared->layoutRoot->children[0];
        self::assertCount(1, $outer->children);
        self::assertSame('footer', $outer->children[0]->source->node->tagName);
        self::assertGreaterThan(0.0, $outer->children[0]->box->content->height);
    }

    public function testDisplayGridFallsBackToBlockInsteadOfDisappearingFromLayout(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:300px"><div style="display:grid"><p>conteudo</p></div></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $outer = $prepared->layoutRoot->children[0];
        self::assertCount(1, $outer->children);
        self::assertGreaterThan(0.0, $outer->children[0]->box->content->height);
    }

    public function testFlexChildrenAreLaidOutInARowNowThatFlexIsImplemented(): void
    {
        // Flex used to fall back to block, which stacked the children; FlexLayoutTest covers the
        // layout itself, this only pins that the fallback is gone for flex (grid keeps it).
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;display:flex">'
                . '<div style="height:10px"><span>a</span></div>'
                . '<div style="height:10px"><span>b</span></div>'
                . '</div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $flexContainer = $prepared->layoutRoot->children[0];
        self::assertCount(2, $flexContainer->children);
        self::assertSame(0.0, $flexContainer->children[0]->box->content->y);
        self::assertSame(0.0, $flexContainer->children[1]->box->content->y);
        self::assertGreaterThan($flexContainer->children[0]->box->content->x, $flexContainer->children[1]->box->content->x);
    }
}
