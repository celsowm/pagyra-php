<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\StylesheetParser;
use Pagyra\Html\HtmlParser;
use Pagyra\Style\StyleComputer;
use PHPUnit\Framework\TestCase;

final class CascadeTest extends TestCase
{
    public function testSpecificitySourceOrderInheritanceAndInlineStyle(): void
    {
        $root = (new HtmlParser())->parse('<div id="main" class="box"><span style="color: purple">X</span></div>');
        $rules = (new StylesheetParser())->parse('div { color: red; font-size: 12px } .box { color: blue } #main { color: green } .box { font-weight: 700 }');
        $styled = (new StyleComputer())->computeTree($root, $rules);

        $div = $styled->children[0];
        $span = $div->children[0];

        self::assertSame('green', $div->style->get('color'));
        self::assertSame('700', $div->style->get('font-weight'));
        self::assertSame('12px', $div->style->get('font-size'));
        self::assertSame('purple', $span->style->get('color'));
        self::assertSame('700', $span->style->get('font-weight'));
        self::assertSame('12px', $span->style->get('font-size'));
    }

    public function testCommaSeparatedSelectorsAndCompoundSimpleSelector(): void
    {
        $root = (new HtmlParser())->parse('<p class="note hot">A</p><span class="note">B</span>');
        $rules = (new StylesheetParser())->parse('p.note.hot, span.note { text-align: center }');
        $styled = (new StyleComputer())->computeTree($root, $rules);

        self::assertSame('center', $styled->children[0]->style->get('text-align'));
        self::assertSame('center', $styled->children[1]->style->get('text-align'));
    }
}
