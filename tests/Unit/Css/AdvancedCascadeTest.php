<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\StylesheetParser;
use Pagyra\Html\HtmlParser;
use Pagyra\Style\StyleComputer;
use PHPUnit\Framework\TestCase;

final class AdvancedCascadeTest extends TestCase
{
    public function testAdvancedCascade(): void
    {
        $root = (new HtmlParser())->parse('<section class="outer"><div data-kind="legal"><span class="note">X</span></div></section>');
        $rules = (new StylesheetParser())->parse('.outer span.note { color: red } .outer > div[data-kind="legal"] > span { font-weight: 700 }');
        $styled = (new StyleComputer())->computeTree($root, $rules);
        $span = $styled->children[0]->children[0]->children[0];
        self::assertSame('red', $span->style->get('color'));
        self::assertSame('700', $span->style->get('font-weight'));

        $root = (new HtmlParser())->parse('<p class="x" style="color: blue">X</p>');
        $rules = (new StylesheetParser())->parse('.x { color: red !important }');
        $styled = (new StyleComputer())->computeTree($root, $rules);
        self::assertSame('red', $styled->children[0]->style->get('color'));

        $root = (new HtmlParser())->parse('<div class="theme"><span class="child">X</span></div>');
        $rules = (new StylesheetParser())->parse('.theme { --brand: #123456 } .child { color: var(--brand) }');
        $styled = (new StyleComputer())->computeTree($root, $rules);
        self::assertSame('#123456', $styled->children[0]->children[0]->style->get('color'));
    }
}
