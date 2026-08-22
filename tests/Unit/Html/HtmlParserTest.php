<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Html;

use Pagyra\Html\HtmlParser;
use Pagyra\Html\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class HtmlParserTest extends TestCase
{
    public function testFragmentBecomesBodyContent(): void
    {
        $document = (new HtmlParser())->parseDocument('<p id="x" class="a b" style="color:red">Hello <strong>World</strong></p>');
        $p = $document->root->children[0];

        self::assertSame('p', $p->tagName);
        self::assertSame('x', $p->id());
        self::assertSame(['a', 'b'], $p->classes());
        self::assertSame('color:red', $p->inlineStyle());
        self::assertSame('Hello World', $p->textContent());
    }

    public function testDocumentCollectsStylesAndSkipsMetadataFromContent(): void
    {
        $html = '<!doctype html><html><head><title>X</title><style>.a{color:red}</style><link rel="stylesheet" href="site.css"><script>x()</script></head><body><div class="a">A</div></body></html>';
        $document = (new HtmlParser())->parseDocument($html);

        self::assertSame(['.a{color:red}'], $document->embeddedCss);
        self::assertSame(['site.css'], $document->stylesheetHrefs);
        self::assertCount(1, $document->root->children);
        self::assertSame('div', $document->root->children[0]->tagName);
    }

    public function testImageAndSvgRecognition(): void
    {
        $root = (new HtmlParser())->parse('<img src="a.png"><svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>');

        self::assertTrue($root->children[0]->isImage());
        self::assertTrue($root->children[1]->isSvg());
        self::assertSame('a.png', $root->children[0]->attribute('src'));
    }

    public function testWhitespaceNormalizerIsExplicitAndDoesNotMutateDomParsing(): void
    {
        self::assertSame(' a b ', TextNormalizer::collapse(" a\n\t b "));
        self::assertTrue(TextNormalizer::isWhitespaceOnly(" \n\t "));
    }
}
