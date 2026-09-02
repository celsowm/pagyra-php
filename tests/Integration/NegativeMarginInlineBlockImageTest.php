<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * A block-level <img> nested in an inline-block is laid out by the inline formatter,
 * whose length resolver used to clamp every edge value to >= 0 and so silently dropped
 * negative margins. Real court headers pull the brasão left with `margin-left:-10px`
 * to hang past the text's padding; without the shift the header text overlapped the logo.
 */
final class NegativeMarginInlineBlockImageTest extends TestCase
{
    private function pngDataUrl(): string
    {
        $idat = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($idat);
        $ihdr = pack('N', 1) . pack('N', 1) . chr(8) . chr(2) . "\x00\x00\x00";
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idat) . $chunk('IEND', '');

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** @return array{float,float} the [width, x] of the first image draw matrix */
    private function firstImageMatrix(string $pdf): array
    {
        self::assertSame(
            1,
            preg_match('/([\d.]+) 0 0 [\d.]+ (-?[\d.]+) [\d.]+ cm\s*\/Im1 Do/', $pdf, $m),
            'expected an image draw matrix in the PDF content stream',
        );
        return [(float) $m[1], (float) $m[2]];
    }

    public function testNegativeLeftMarginShiftsAnInlineBlockImageLeft(): void
    {
        $src = $this->pngDataUrl();
        $template = '<div><div style="display:inline-block">'
            . '<img src="' . $src . '" style="display:block;width:40px;height:20px;margin-left:%s">'
            . '</div></div>';

        $plain = Pagyra::renderHtmlToPdf([
            'html' => sprintf($template, '0px'),
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);
        $pulled = Pagyra::renderHtmlToPdf([
            'html' => sprintf($template, '-10px'),
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        [$plainWidth, $plainX] = $this->firstImageMatrix($plain);
        [$pulledWidth, $pulledX] = $this->firstImageMatrix($pulled);

        // width unchanged, x moved left by 10px == 7.5pt
        self::assertEqualsWithDelta($plainWidth, $pulledWidth, 0.01);
        self::assertEqualsWithDelta($plainX - 7.5, $pulledX, 0.01);
    }
}
