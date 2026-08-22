<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\Ttf\TtfParser;
use PHPUnit\Framework\TestCase;

final class TtfParserTest extends TestCase
{
    public function testParsesCoreMetricTablesAndKernPairs(): void
    {
        $metrics = (new TtfParser())->parse($this->fontFixture());

        self::assertSame(1000, $metrics->unitsPerEm);
        self::assertSame(800, $metrics->ascent);
        self::assertSame(-200, $metrics->descent);
        self::assertSame(600, $metrics->advanceWidth(1));
        self::assertSame(610, $metrics->advanceWidth(2));
        self::assertSame(1, $metrics->glyphId(65));
        self::assertSame(2, $metrics->glyphId(66));
        self::assertSame(-50, $metrics->kerning(1, 2));
    }

    private function fontFixture(): string
    {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('n', 1000), 18, 2);

        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', 800), 4, 2);
        $hhea = substr_replace($hhea, pack('n', 0x10000 - 200), 6, 2);
        $hhea = substr_replace($hhea, pack('n', 0), 8, 2);
        $hhea = substr_replace($hhea, pack('n', 3), 34, 2);

        $maxp = str_repeat("\0", 6);
        $maxp = substr_replace($maxp, pack('n', 3), 4, 2);
        $hmtx = pack('nnnnnn', 500, 0, 600, 0, 610, 0);

        $cmap12 = pack('nnNNN', 12, 0, 28, 0, 1) . pack('NNN', 65, 66, 1);
        $cmap = pack('nnnnN', 0, 1, 3, 10, 12) . $cmap12;

        $kernSubtable = pack('nnn', 0, 20, 0)
            . pack('nnnn', 1, 0, 0, 0)
            . pack('nnn', 1, 2, 0x10000 - 50);
        $kern = pack('nn', 0, 1) . $kernSubtable;

        $tables = [
            'head' => $head,
            'hhea' => $hhea,
            'maxp' => $maxp,
            'hmtx' => $hmtx,
            'cmap' => $cmap,
            'kern' => $kern,
        ];

        $headerSize = 12 + count($tables) * 16;
        $offset = $headerSize;
        $directory = '';
        $payload = '';
        foreach ($tables as $tag => $data) {
            $directory .= pack('a4NNN', $tag, 0, $offset, strlen($data));
            $payload .= $data;
            $offset += strlen($data);
        }

        return pack('Nnnnn', 0x00010000, count($tables), 0, 0, 0) . $directory . $payload;
    }
}
