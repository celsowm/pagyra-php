<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Style\StyledNode;
use PHPUnit\Framework\TestCase;

final class ComputedStylePipelineTest extends TestCase
{
    public function testEmbeddedExternalAndInlineCssReachStyledTree(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>.note { color: blue; font-size: 14px }</style><p id="x" class="note" style="color: green">Hello</p>',
            'css' => '#x { font-weight: 700 }',
        ]);

        $p = $this->firstElement($prepared->styledRoot, 'p');

        self::assertSame('green', $p->style->get('color'));
        self::assertSame('14px', $p->style->get('font-size'));
        self::assertSame('700', $p->style->get('font-weight'));
        self::assertStringContainsString('#x { font-weight: 700 }', $prepared->cssText);
        self::assertStringContainsString('.note { color: blue; font-size: 14px }', $prepared->cssText);
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
