<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class CssFontFaceLoadingTest extends TestCase
{
    public function testLinkedStylesheetFontFaceLoadsRelativeFont(): void
    {
        $dir = $this->temporaryDirectory();
        $styles = $dir . '/styles';
        $fonts = $dir . '/fonts';
        mkdir($styles);
        mkdir($fonts);
        file_put_contents($fonts . '/fixture.ttf', $this->fontFixture());
        file_put_contents(
            $styles . '/site.css',
            '@font-face{font-family:"FixtureCss";src:url("../fonts/fixture.ttf") format("truetype");font-weight:400;font-style:normal;} p{font-family:"FixtureCss";font-size:10px;margin:0;}',
        );

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<link rel="stylesheet" href="styles/site.css"><p>AB</p>',
                'resourceBaseDir' => $dir,
                'viewportWidth' => 400,
                'viewportHeight' => 300,
            ]);

            self::assertStringContainsString('@font-face', $prepared->cssText);
            self::assertStringContainsString('file://', $prepared->cssText);
            self::assertEqualsWithDelta(11.6, $prepared->layoutRoot->children[0]->lineBoxes[0]->width, 0.001);
        } finally {
            @unlink($styles . '/site.css');
            @unlink($fonts . '/fixture.ttf');
            @rmdir($styles);
            @rmdir($fonts);
            @rmdir($dir);
        }
    }

    public function testLinkedStylesheetWoffFontIsDecodedAndUsed(): void
    {
        $dir = $this->temporaryDirectory();
        $styles = $dir . '/styles';
        $fonts = $dir . '/fonts';
        mkdir($styles);
        mkdir($fonts);
        file_put_contents($fonts . '/fixture.woff', $this->woffFixture());
        file_put_contents(
            $styles . '/site.css',
            '@font-face{font-family:"FixtureWoff";src:url("../fonts/fixture.woff") format("woff");font-weight:400;font-style:normal;} p{font-family:"FixtureWoff";font-size:10px;margin:0;}',
        );

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<link rel="stylesheet" href="styles/site.css"><p>AB</p>',
                'resourceBaseDir' => $dir,
                'viewportWidth' => 400,
                'viewportHeight' => 300,
            ]);

            self::assertEqualsWithDelta(11.6, $prepared->layoutRoot->children[0]->lineBoxes[0]->width, 0.001);
            $face = $prepared->fontRegistry?->resolveFace('FixtureWoff');
            self::assertNotNull($face);
            self::assertSame("\x00\x01\x00\x00", substr($face->binary, 0, 4));
        } finally {
            @unlink($styles . '/site.css');
            @unlink($fonts . '/fixture.woff');
            @rmdir($styles);
            @rmdir($fonts);
            @rmdir($dir);
        }
    }

    private function temporaryDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/pagyra-css-font-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Unable to create temporary font directory');
        }
        return $dir;
    }

    private function woffFixture(): string
    {
        $sfnt = $this->fontFixture();
        $numTables = unpack('n', substr($sfnt, 4, 2))[1];
        $records = [];
        for ($i = 0; $i < $numTables; $i++) {
            $p = 12 + $i * 16;
            $tag = substr($sfnt, $p, 4);
            $checksum = unpack('N', substr($sfnt, $p + 4, 4))[1];
            $offset = unpack('N', substr($sfnt, $p + 8, 4))[1];
            $length = unpack('N', substr($sfnt, $p + 12, 4))[1];
            $data = substr($sfnt, $offset, $length);
            $compressed = gzcompress($data, 9);
            if ($compressed === false || strlen($compressed) >= strlen($data)) $compressed = $data;
            $records[] = compact('tag', 'checksum', 'length', 'compressed');
        }

        $offset = 44 + $numTables * 20;
        $directory = '';
        $payload = '';
        foreach ($records as $record) {
            $compLength = strlen($record['compressed']);
            $directory .= $record['tag']
                . pack('N', $offset)
                . pack('N', $compLength)
                . pack('N', $record['length'])
                . pack('N', $record['checksum']);
            $payload .= $record['compressed'];
            $padding = (4 - ($compLength % 4)) % 4;
            if ($padding > 0) $payload .= str_repeat("\0", $padding);
            $offset += $compLength + $padding;
        }

        $length = 44 + strlen($directory) + strlen($payload);
        $header = pack(
            'NNNnnNNnnNNNNN',
            0x774F4646,
            unpack('N', substr($sfnt, 0, 4))[1],
            $length,
            $numTables,
            0,
            strlen($sfnt),
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
        );

        return $header . $directory . $payload;
    }

    private function fontFixture(): string
    {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('n', 1000), 18, 2);

        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', 800), 4, 2);
        $hhea = substr_replace($hhea, pack('n', 0x10000 - 200), 6, 2);
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
