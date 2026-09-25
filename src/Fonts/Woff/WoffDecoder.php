<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Woff;

/**
 * WOFF 1.0 -> sfnt reconstruction.
 *
 * WOFF stores the original sfnt tables independently, optionally zlib-compressed. Decoding is
 * therefore lossless and small enough to keep in pure PHP; the reconstructed bytes can be handed
 * to the existing TrueType parser/subsetter and embedded as FontFile2.
 */
final class WoffDecoder
{
    private const SIGNATURE = 0x774F4646; // wOFF

    public function isWoff(string $binary): bool
    {
        return strlen($binary) >= 4 && $this->u32($binary, 0) === self::SIGNATURE;
    }

    public function decode(string $binary): string
    {
        if (strlen($binary) < 44 || !$this->isWoff($binary)) {
            throw new \InvalidArgumentException('Invalid WOFF header');
        }

        $flavor = $this->u32($binary, 4);
        $declaredLength = $this->u32($binary, 8);
        $numTables = $this->u16($binary, 12);
        $reserved = $this->u16($binary, 14);
        $totalSfntSize = $this->u32($binary, 16);

        if ($reserved !== 0 || $numTables <= 0 || $declaredLength > strlen($binary) || 44 + $numTables * 20 > $declaredLength) {
            throw new \RuntimeException('Malformed WOFF directory');
        }

        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $p = 44 + $i * 20;
            $tag = substr($binary, $p, 4);
            $offset = $this->u32($binary, $p + 4);
            $compLength = $this->u32($binary, $p + 8);
            $origLength = $this->u32($binary, $p + 12);
            $checksum = $this->u32($binary, $p + 16);

            if ($origLength <= 0 || $compLength <= 0 || $compLength > $origLength || $offset + $compLength > $declaredLength) {
                throw new \RuntimeException('Malformed WOFF table entry');
            }

            $stored = substr($binary, $offset, $compLength);
            if ($compLength < $origLength) {
                $data = @gzuncompress($stored);
                if ($data === false || strlen($data) !== $origLength) {
                    throw new \RuntimeException('Unable to decompress WOFF table');
                }
            } else {
                $data = $stored;
            }

            $tables[] = [
                'tag' => $tag,
                'checksum' => $checksum,
                'length' => $origLength,
                'data' => $data,
            ];
        }

        usort($tables, static fn(array $a, array $b): int => strcmp($a['tag'], $b['tag']));

        $pow2 = 1;
        $entrySelector = 0;
        while (($pow2 * 2) <= $numTables) {
            $pow2 *= 2;
            $entrySelector++;
        }
        $searchRange = $pow2 * 16;
        $rangeShift = $numTables * 16 - $searchRange;

        $header = pack('Nnnnn', $flavor, $numTables, $searchRange, $entrySelector, $rangeShift);
        $directory = '';
        $payload = '';
        $offset = 12 + $numTables * 16;

        foreach ($tables as $table) {
            $directory .= $table['tag']
                . pack('N', $table['checksum'])
                . pack('N', $offset)
                . pack('N', $table['length']);
            $payload .= $table['data'];
            $padding = (4 - ($table['length'] % 4)) % 4;
            if ($padding > 0) $payload .= str_repeat("\0", $padding);
            $offset += $table['length'] + $padding;
        }

        $sfnt = $header . $directory . $payload;
        if ($totalSfntSize > 0 && strlen($sfnt) !== $totalSfntSize) {
            throw new \RuntimeException('WOFF totalSfntSize does not match reconstructed font');
        }

        return $sfnt;
    }

    private function u16(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) throw new \RuntimeException('Truncated WOFF u16');
        return unpack('n', substr($data, $offset, 2))[1];
    }

    private function u32(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) throw new \RuntimeException('Truncated WOFF u32');
        return unpack('N', substr($data, $offset, 4))[1];
    }
}
