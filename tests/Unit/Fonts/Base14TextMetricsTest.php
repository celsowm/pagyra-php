<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class Base14TextMetricsTest extends TestCase
{
    private function width(string $text, array $properties, float $fontSize = 100.0): float
    {
        return (new HeuristicTextMetrics())->measure($text, new ComputedStyle($properties), $fontSize)->inlineSize;
    }

    public function testMappedFamilyMeasuresWithTheRealAdvanceWidths(): void
    {
        // Times-Roman: M is 889/1000 em, i is 278/1000 em.
        self::assertSame(889.0, $this->width('MMMMMMMMMM', ['font-family' => 'Times New Roman']));
        self::assertSame(278.0, $this->width('iiiiiiiiii', ['font-family' => 'Times New Roman']));
    }

    public function testBoldResolvesToTheBoldWidthTableRatherThanAFlatMultiplier(): void
    {
        // Times-Bold M is 944/1000 em, not Times-Roman's 889 scaled by a weight factor.
        self::assertEqualsWithDelta(94.4, $this->width('M', ['font-family' => 'Times', 'font-weight' => 'bold']), 0.001);
        self::assertEqualsWithDelta(94.4, $this->width('M', ['font-family' => 'Times', 'font-weight' => '700']), 0.001);
        self::assertEqualsWithDelta(88.9, $this->width('M', ['font-family' => 'Times', 'font-weight' => '500']), 0.001);
    }

    public function testFamilyAliasesFollowTheReferenceTable(): void
    {
        self::assertEqualsWithDelta(83.3, $this->width('M', ['font-family' => 'Arial']), 0.001);
        self::assertEqualsWithDelta(83.3, $this->width('M', ['font-family' => 'sans-serif']), 0.001);
        self::assertEqualsWithDelta(88.9, $this->width('M', ['font-family' => 'Georgia']), 0.001);
        self::assertEqualsWithDelta(60.0, $this->width('M', ['font-family' => 'monospace']), 0.001);
        self::assertEqualsWithDelta(60.0, $this->width('M', ['font-family' => 'Courier New']), 0.001);
    }

    public function testFirstMappableFamilyInTheListWins(): void
    {
        self::assertEqualsWithDelta(88.9, $this->width('M', ['font-family' => '"Times New Roman", Arial']), 0.001);
        self::assertEqualsWithDelta(83.3, $this->width('M', ['font-family' => 'Fonte Inexistente, Arial, serif']), 0.001);
    }

    public function testUnmappedOrAbsentFamilyFallsBackToTimesLikeTheSerializer(): void
    {
        // PdfSerializer::base14Font() ends in Times when nothing in the stack matches, and draws
        // the run with it. Measuring these with the per-character estimate instead — which is
        // what this did before — left every such run measured narrower than it is drawn, so the
        // next run started on top of it.
        self::assertEqualsWithDelta(889.0, $this->width('MMMMMMMMMM', ['font-family' => 'Fonte Inexistente']), 0.001);
        self::assertEqualsWithDelta(889.0, $this->width('MMMMMMMMMM', []), 0.001);
    }

    public function testFamilyNameIsMatchedAsASubstringLikeTheSerializer(): void
    {
        // "Arial Narrow" is no exact alias, but the serializer matches it on "arial" and draws
        // Helvetica; the measurement has to reach the same face.
        self::assertEqualsWithDelta(83.3, $this->width('M', ['font-family' => 'Arial Narrow']), 0.001);
    }

    public function testAccentedCharacterUsesItsOwnWinAnsiWidth(): void
    {
        // The accent does not change the advance in these faces, so "ê" measures like "e" (444).
        // Under the reference's StandardEncoding keying, byte 234 would be "OE" (889) instead.
        self::assertSame(
            $this->width('e', ['font-family' => 'Times']),
            $this->width('ê', ['font-family' => 'Times']),
        );
        self::assertEqualsWithDelta(31.0, $this->width('º', ['font-family' => 'Times']), 0.001);
    }

    public function testItalicResolvesToTheItalicTable(): void
    {
        self::assertEqualsWithDelta(83.3, $this->width('M', ['font-family' => 'Times', 'font-style' => 'italic']), 0.001);
        self::assertEqualsWithDelta(88.9, $this->width('M', ['font-family' => 'Times', 'font-style' => 'normal']), 0.001);
    }

    public function testCharacterOutsideTheCodepageMeasuresAsTheReplacementTheSerializerWrites(): void
    {
        // U+2003 EM SPACE has no WinAnsi byte; the serializer writes "?" for it.
        self::assertSame(
            $this->width('?', ['font-family' => 'Times']),
            $this->width("\u{2003}", ['font-family' => 'Times']),
        );
    }

    public function testSpacingIsStillAddedOnTopOfTheRealWidths(): void
    {
        $plain = $this->width('MM M', ['font-family' => 'Times'], 100.0);
        $spaced = $this->width('MM M', ['font-family' => 'Times', 'letter-spacing' => '10px', 'word-spacing' => '5px'], 100.0);

        self::assertSame($plain + 3 * 10.0 + 1 * 5.0, $spaced);
    }

    public function testMeasurementMatchesWhatTheSerializerWouldDraw(): void
    {
        // A run of capitals is where the old estimate was worst: it came out far narrower than
        // the glyphs the viewer actually advances by, so the following run overlapped it.
        $text = 'KAYENE HEBERLE MAC CULLOCH BRANDAO';
        $style = ['font-family' => "'Times New Roman', Georgia, Times, serif", 'font-weight' => 'bold'];

        $expected = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $expected += \Pagyra\Fonts\Base14\TimesBoldWidths::WIDTHS[ord($char)];
        }

        self::assertEqualsWithDelta($expected / 1000.0 * 16.0, $this->width($text, $style, 16.0), 0.001);
    }
}
