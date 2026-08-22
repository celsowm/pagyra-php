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

    public function testIgnoresIncompleteFontFaceRules(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face { font-family: MissingSrc; }
            @font-face { src: url("missing-family.ttf"); }
        ');

        self::assertSame([], $faces);
    }
}
