<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Image;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\PdfByteReader;
use Celsowm\PagyraPhp\Core\PdfStreamBuilder;


final class PdfImageManager
{
    private array $images = [];
    private int $imageSeq = 0;
    private PdfBuilder $pdf;

    public function __construct(PdfBuilder $pdf)
    {
        $this->pdf = $pdf;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function getImage(string $alias): ?array
    {
        return $this->images[$alias] ?? null;
    }

    public function addImage(string $alias, string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("Imagem não encontrada: {$filePath}");
        }
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException("Falha ao ler imagem: {$filePath}");
        }
        $this->addImageData($alias, $data, null);
    }

    public function addImageData(string $alias, string $data, ?string $hint = null): void
    {
        $fmt = $hint ? strtolower($hint) : $this->detectImageFormat($data);
        if ($fmt === 'jpeg') {
            $meta = $this->embedJPEG($data);
        } elseif ($fmt === 'png') {
            $meta = $this->embedPNG($data);
        } else {
            throw new \RuntimeException("Formato de imagem não suportado.");
        }
        $this->images[$alias] = $meta;
    }

    private function detectImageFormat(string $data): ?string
    {
        if (strlen($data) >= 2 && $data[0] === "\xFF" && $data[1] === "\xD8") return 'jpeg';
        if (strlen($data) >= 8 && substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") return 'png';
        return null;
    }

    private function embedJPEG(string $data): array
    {
        $i = 2;
        $len = strlen($data);
        $w = $h = $bpc = 8;
        $comp = 3;
        $found = false;
        while ($i + 4 < $len) {
            if ($data[$i] !== "\xFF") {
                $i++;
                continue;
            }
            while ($i < $len && $data[$i] === "\xFF") $i++;
            if ($i >= $len) break;
            $marker = ord($data[$i++]);
            if (in_array($marker, [0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7, 0xD9], true)) continue;
            if ($i + 1 >= $len) break;
            $segLen = (ord($data[$i]) << 8) | ord($data[$i + 1]);
            $i += 2;
            if ($i + $segLen - 2 > $len) break;
            $seg = substr($data, $i, $segLen - 2);
            $i += $segLen - 2;
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                $bpc = ord($seg[0]);
                $h = (ord($seg[1]) << 8) | ord($seg[2]);
                $w = (ord($seg[3]) << 8) | ord($seg[4]);
                $comp = ord($seg[5]);
                $found = true;
                break;
            }
        }
        if (!$found || $w <= 0 || $h <= 0) {
            throw new \RuntimeException("JPEG inválido.");
        }
        $cs = match ($comp) {
            1 => '/DeviceGray',
            3 => '/DeviceRGB',
            4 => '/DeviceCMYK',
            default => '/DeviceRGB'
        };
        $decode = ($cs === '/DeviceCMYK') ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
        $imgId = $this->pdf->newObjectId();
        $dict = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /BitsPerComponent {$bpc} /ColorSpace {$cs}{$decode} /Filter /DCTDecode >>";
        $this->pdf->setObject($imgId, PdfStreamBuilder::streamObjWithDict($dict, $data));
        $name = '/Im' . (++$this->imageSeq);
        return ['objId' => $imgId, 'name' => $name, 'w' => $w, 'h' => $h];
    }

    private function embedPNG(string $data): array
    {
        if (!(strlen($data) >= 8 && substr($data, 0, 8) === "\x89PNG\r\n\x1A\n")) {
            throw new \RuntimeException("PNG inválido (assinatura).");
        }
        $pos = 8;
        $len = strlen($data);
        $w = $h = $bit = 8;
        $ct = 2;
        $interlace = 0;
        $plte = '';
        $idat = '';
        $trns = '';
        while ($pos + 8 <= $len) {
            $clen = PdfByteReader::beU32($data, $pos);
            $pos += 4;
            if ($pos + 4 > $len) break;
            $type = substr($data, $pos, 4);
            $pos += 4;
            if ($pos + $clen + 4 > $len) break;
            $chunk = substr($data, $pos, $clen);
            $pos += $clen;
            $crc = substr($data, $pos, 4);
            $pos += 4;
            if ($type === 'IHDR') {
                $w = PdfByteReader::beU32($chunk, 0);
                $h = PdfByteReader::beU32($chunk, 4);
                $bit = ord($chunk[8]);
                $ct = ord($chunk[9]);
                $comp = ord($chunk[10]);
                $filt = ord($chunk[11]);
                $interlace = ord($chunk[12]);
                if ($comp !== 0 || $filt !== 0) throw new \RuntimeException("PNG com compressão/filtro não suportados.");
            } elseif ($type === 'PLTE') {
                $plte = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'tRNS') {
                $trns = $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($interlace !== 0) {
            throw new \RuntimeException("PNG interlaced não suportado.");
        }
        if ($w <= 0 || $h <= 0) {
            throw new \RuntimeException("PNG inválido (dimensões).");
        }
        $colors = 1;
        $colorSpace = '/DeviceGray';
        $mask = '';
        $decode = '';
        if ($ct === 0) {
            $colors = 1;
            $colorSpace = '/DeviceGray';
            if ($trns !== '' && strlen($trns) >= 2) {
                $gray = PdfByteReader::be16($trns, 0);
                $mask = sprintf(' /Mask [%u %u]', $gray, $gray);
            }
        } elseif ($ct === 2) {
            $colors = 3;
            $colorSpace = '/DeviceRGB';
            if ($trns !== '' && strlen($trns) >= 6) {
                $r = PdfByteReader::be16($trns, 0);
                $g = PdfByteReader::be16($trns, 2);
                $b = PdfByteReader::be16($trns, 4);
                $mask = sprintf(' /Mask [%u %u %u %u %u %u]', $r, $r, $g, $g, $b, $b);
            }
        } elseif ($ct === 3) {
            if ($plte === '') throw new \RuntimeException("PNG Indexed sem PLTE.");
            $n = intdiv(strlen($plte), 3) - 1;
            $hex = strtoupper(bin2hex($plte));
            $colorSpace = "[ /Indexed /DeviceRGB {$n} <{$hex}> ]";
            $colors = 1;
            if ($trns !== '') {
                $trnsArray = [];
                for ($i = 0; $i < strlen($trns); $i++) {
                    if (ord($trns[$i]) < 255) {
                        $trnsArray[] = $i;
                    }
                }
                if (!empty($trnsArray)) {
                    $maskRanges = [];
                    sort($trnsArray);
                    $start = $trnsArray[0];
                    $end = $trnsArray[0];
                    for ($i = 1; $i < count($trnsArray); $i++) {
                        if ($trnsArray[$i] == $end + 1) {
                            $end = $trnsArray[$i];
                        } else {
                            $maskRanges[] = $start;
                            $maskRanges[] = $end;
                            $start = $end = $trnsArray[$i];
                        }
                    }
                    $maskRanges[] = $start;
                    $maskRanges[] = $end;
                    $mask = ' /Mask [' . implode(' ', $maskRanges) . ']';
                }
            }
        } else {
            throw new \RuntimeException("Tipo de cor PNG não suportado: {$ct}");
        }
        $decodeParms = "<< /Predictor 15 /Colors {$colors} /BitsPerComponent {$bit} /Columns {$w} >>";
        $imgId = $this->pdf->newObjectId();
        $dict = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /BitsPerComponent {$bit} /ColorSpace {$colorSpace} /Filter /FlateDecode /DecodeParms {$decodeParms}{$mask} >>";
        $this->pdf->setObject($imgId, PdfStreamBuilder::streamObjWithDict($dict, gzuncompress($idat)));
        $name = '/Im' . (++$this->imageSeq);
        return ['objId' => $imgId, 'name' => $name, 'w' => $w, 'h' => $h];
    }
}