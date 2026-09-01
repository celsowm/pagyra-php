<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Style\StyledNode;
use PHPUnit\Framework\TestCase;

final class PageViewportMediaQueryTest extends TestCase
{
    public function testPageContentAreaConstrainsPrintViewportForMediaQueries(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:500px 300px; margin:50px; }'
                . '@media print and (max-width:450px) { p { width:123px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 794,
            'viewportHeight' => 1123,
        ]);

        self::assertSame('123px', $this->firstElement($prepared->styledRoot, 'p')->style->get('width'));
        self::assertSame(123.0, $prepared->layoutRoot->children[0]->box->content->width);
        self::assertSame(375.0, $prepared->pageSize['widthPt']);
        self::assertSame(225.0, $prepared->pageSize['heightPt']);
    }

    public function testExplicitSmallerViewportRemainsTheUpperBound(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:800px 600px; margin:50px; }'
                . '@media print and (max-width:350px) { p { width:111px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 320,
            'viewportHeight' => 240,
        ]);

        self::assertSame('111px', $this->firstElement($prepared->styledRoot, 'p')->style->get('width'));
        self::assertSame(111.0, $prepared->layoutRoot->children[0]->box->content->width);
    }

    public function testPageMediaRulesAreReevaluatedUntilViewportStabilizes(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@media print and (max-width:700px) {'
                . '  @page { size:500px 300px; margin:50px; }'
                . '}'
                . '@media print and (max-width:450px) { p { width:123px; } }'
                . '</style><p style="margin:0">hello</p>',
            'viewportWidth' => 794,
            'viewportHeight' => 1123,
        ]);

        self::assertSame(375.0, $prepared->pageSize['widthPt']);
        self::assertSame(225.0, $prepared->pageSize['heightPt']);
        self::assertSame(50.0, $prepared->margins['left']);
        self::assertSame('123px', $this->firstElement($prepared->styledRoot, 'p')->style->get('width'));
        self::assertSame(123.0, $prepared->layoutRoot->children[0]->box->content->width);
    }

    /** First descendant element with the given tag, so the assertions do not depend on where
     * whitespace text nodes happen to fall in the styled tree. */
    private function firstElement(StyledNode $node, string $tag): StyledNode
    {
        if ($node->node->isElement($tag)) return $node;
        foreach ($node->children as $child) {
            $found = $this->firstElementOrNull($child, $tag);
            if ($found !== null) return $found;
        }
        self::fail('elemento <' . $tag . '> nao encontrado na arvore de estilos');
    }

    private function firstElementOrNull(StyledNode $node, string $tag): ?StyledNode
    {
        if ($node->node->isElement($tag)) return $node;
        foreach ($node->children as $child) {
            $found = $this->firstElementOrNull($child, $tag);
            if ($found !== null) return $found;
        }
        return null;
    }

}
