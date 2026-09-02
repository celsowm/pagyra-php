<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Justification stretches the spaces of every non-final line, but that stretch lived only in the
 * layout's x arithmetic: adjacent runs were merged into one string (they abut exactly, which is
 * what the merge tests for), and the serializer then drew that string with the font's own space
 * width. The line came out short of the right margin — text that reads as ragged/left-aligned
 * where wkhtmltopdf shows a justified block. The stretch now rides on the run and is emitted as
 * PDF word spacing, so the merge stays (splitting every word into its own `Tj` made a real
 * document ten times larger).
 */
final class JustifiedLineWordSpacingTest extends TestCase
{
    private const CSS = 'p { width: 300px; margin: 0; font-size: 12px; line-height: 1.2; text-align: justify; }';
    private const TEXT = 'um dois tres quatro cinco seis sete oito nove dez onze doze treze catorze quinze';

    /** @return list<\Pagyra\Layout\LineBox> */
    private function lines(string $align = 'justify'): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>' . self::TEXT . '</p>',
            'css' => str_replace('justify', $align, self::CSS),
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        return $prepared->layoutRoot->children[0]->lineBoxes;
    }

    public function testNonFinalLinesCarryTheStretchAndReachTheContentWidth(): void
    {
        $lines = $this->lines();
        self::assertGreaterThan(1, count($lines));

        $first = $lines[0];
        self::assertGreaterThan(0.0, $first->runs[0]->justificationWordSpacing);

        $last = $first->runs[count($first->runs) - 1];
        self::assertEqualsWithDelta(300.0, $last->x + $last->width, 0.01);
    }

    public function testTheFinalLineIsNotStretched(): void
    {
        $lines = $this->lines();
        $final = $lines[count($lines) - 1];

        foreach ($final->runs as $run) {
            self::assertSame(0.0, $run->justificationWordSpacing);
        }
    }

    public function testRunsStayMergedSoTheContentStreamDoesNotExplode(): void
    {
        // One merged run per line, not one per word — the size regression this guards against.
        $first = $this->lines()[0];
        self::assertCount(1, $first->runs);
        self::assertStringContainsString(' ', $first->runs[0]->text);
    }

    public function testStretchIsEmittedAsPdfWordSpacing(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>' . self::CSS . '</style><p>' . self::TEXT . '</p>',
        ]);

        self::assertMatchesRegularExpression('/[0-9.]+ Tw/', $pdf);
    }

    public function testLeftAlignedTextCarriesNoStretch(): void
    {
        foreach ($this->lines('left') as $line) {
            foreach ($line->runs as $run) {
                self::assertSame(0.0, $run->justificationWordSpacing);
            }
        }
    }
}
