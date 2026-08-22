<?php

declare(strict_types=1);

namespace Pagyra\Pdf;

final readonly class PngPdfImageData
{
    public function __construct(
        public int $width,
        public int $height,
        public int $bitsPerComponent,
        public int $colors,
        public string $colorSpace,
        public string $compressedData,
        public bool $usesPngPredictor = true,
        public ?string $alphaCompressedData = null,
    ) {
    }
}
