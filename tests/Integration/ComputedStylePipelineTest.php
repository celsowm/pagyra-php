<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class ComputedStylePipelineTest extends TestCase
{
    public function testEmbeddedExternalAndInlineCssReachStyledTree(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>.note { color: blue; font-size: 14px }</style><p id="x" class="note" style="color: green">Hello</p>',
            'css' => '#x { font-weight: 700 }',
        ]);

        $p = $prepared->styledRoot->children[1];

        self::assertSame('green', $p->style->get('color'));
        self::assertSame('14px', $p->style->get('font-size'));
        self::assertSame('700', $p->style->get('font-weight'));
        self::assertStringContainsString('#x { font-weight: 700 }', $prepared->cssText);
        self::assertStringContainsString('.note { color: blue; font-size: 14px }', $prepared->cssText);
    }
}
