<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `Tc` and `Tw` belong to the graphics state, not to the text object: `BT` resets the text
 * matrix, not the spacing. Emitting them only when non-zero let the word spacing of one
 * justified line survive into every text object drawn afterwards, so lines that must not
 * stretch were spread and the following run was overprinted — the layout had positioned it
 * without that extra advance. Every text object therefore states both explicitly.
 */
final class TextStateDoesNotLeakBetweenRunsTest extends TestCase
{
    private function contentStreams(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)endstream/s', $pdf, $m);
        return implode("\n", $m[1]);
    }

    public function testEveryTextObjectStatesItsOwnWordSpacing(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>p{width:300px;margin:0;font-size:12px;text-align:justify}</style>'
                . '<p>um dois tres quatro cinco seis sete oito nove dez onze doze treze catorze</p>'
                . '<p>curta.</p>',
        ]);

        // Each BT ... ET block must set Tc and Tw before its first show operator. A block may
        // set Tw more than once: since the per-glyph font fallback (4c046a2) a run is written as
        // segments and every segment restates the word spacing its font needs.
        preg_match_all('/BT\n(.*?)ET\n/s', $this->contentStreams($pdf), $objects);
        self::assertNotEmpty($objects[1]);
        foreach ($objects[1] as $object) {
            $beforeShow = preg_split('/ T[jJ]\n/', $object, 2)[0];
            self::assertMatchesRegularExpression('/ Tw\n/', $beforeShow, 'todo BT precisa declarar seu proprio Tw');
            self::assertMatchesRegularExpression('/ Tc\n/', $beforeShow, 'todo BT precisa declarar seu proprio Tc');
        }
    }

    public function testTheStretchedLineAndTheShortOneGetDifferentWordSpacing(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>p{width:300px;margin:0;font-size:12px;text-align:justify}</style>'
                . '<p>um dois tres quatro cinco seis sete oito nove dez onze doze treze catorze</p>'
                . '<p>curta.</p>',
        ]);

        preg_match_all('/(-?[0-9.]+) Tw\n/', $this->contentStreams($pdf), $m);
        $valores = array_map('floatval', $m[1]);

        self::assertContains(0.0, $valores, 'a linha nao justificada tem de zerar o Tw');
        self::assertNotEmpty(array_filter($valores, static fn (float $v): bool => $v > 0.0));
    }
}
