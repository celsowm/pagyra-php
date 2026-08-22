<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\FontFaceRuleParser;
use PHPUnit\Framework\TestCase;

final class FontFaceRuleParserTest extends TestCase
{
    public function testParsesFamilySourceWeightAndStyle(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face {
                font-family: "Fixture";
                src: url("fixture.ttf") format("truetype");
                font-weight: bold;
                font-style: oblique;
            }
        ');

        self::assertSame([[
            'family' => 'Fixture',
            'src' => 'fixture.ttf',
            'weight' => 700,
            'style' => 'italic',
        ]], $faces);
    }

    public function testPrefersSupportedSfntSourceOverWoff2(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face {
                font-family: Fixture;
                src:
                    url("fixture.woff2") format("woff2"),
                    url("fixture.ttf") format("truetype");
            }
        ');

        self::assertSame('fixture.ttf', $faces[0]['src']);
    }

    public function testKeepsBase64FontDataUrlIntact(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face {
                font-family: Embedded;
                src: url("data:font/ttf;base64,QUJDRA==") format("truetype");
            }
        ');

        self::assertSame('data:font/ttf;base64,QUJDRA==', $faces[0]['src']);
    }

    public function testFiltersFontFacesByMediaContext(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @media screen {
                @font-face { font-family: ScreenOnly; src: url("screen.ttf"); }
            }
            @media print and (min-width: 700px) {
                @font-face { font-family: PrintWide; src: url("print.ttf"); }
            }
        ', 'print', 800, 600);

        self::assertCount(1, $faces);
        self::assertSame('PrintWide', $faces[0]['family']);
        self::assertSame('print.ttf', $faces[0]['src']);
    }

    public function testIgnoresIncompleteFontFaceRules(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face { font-family: MissingSrc; }
            @font-face { src: url("missing-family.ttf"); }
        ');

        self::assertSame([], $faces);
    }
}
