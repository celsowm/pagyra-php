<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\DeclarationParser;
use PHPUnit\Framework\TestCase;

final class DeclarationParserStructuredValueTest extends TestCase
{
    public function testSemicolonInsideUrlDoesNotSplitDeclaration(): void
    {
        $parsed = (new DeclarationParser())->parse(
            'src: url("data:font/ttf;base64,QUJDRA==") format("truetype"); font-weight: 700;'
        );

        self::assertSame('url("data:font/ttf;base64,QUJDRA==") format("truetype")', $parsed['src']);
        self::assertSame('700', $parsed['font-weight']);
    }

    public function testSemicolonInsideQuotedStringDoesNotSplitDeclaration(): void
    {
        $parsed = (new DeclarationParser())->parse('content: "a;b"; color: red;');

        self::assertSame('"a;b"', $parsed['content']);
        self::assertSame('red', $parsed['color']);
    }
}
