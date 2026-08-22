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

    public function testIgnoresIncompleteFontFaceRules(): void
    {
        $faces = (new FontFaceRuleParser())->parse('
            @font-face { font-family: MissingSrc; }
            @font-face { src: url("missing-family.ttf"); }
        ');

        self::assertSame([], $faces);
    }
}
