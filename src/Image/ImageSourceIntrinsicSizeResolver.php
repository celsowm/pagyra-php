<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ImageSourceIntrinsicSizeResolver
{
    public function __construct(
        private readonly ImageSourceBytesResolver $sourceBytes = new ImageSourceBytesResolver(),
        private readonly ImageMetadataReader $rasterReader = new ImageMetadataReader(),
        private readonly SvgIntrinsicSizeReader $svgReader = new SvgIntrinsicSizeReader(),
    ) {
    }

    public function resolve(?string $source): ?ReplacedElementSize
    {
        $bytes = $this->sourceBytes->resolve($source);
        if ($bytes === null) {
            return null;
        }

        try {
            $metadata = $this->rasterReader->read($bytes);
            return new ReplacedElementSize((float) $metadata->width, (float) $metadata->height);
        } catch (\InvalidArgumentException) {
            return $this->svgReader->read($bytes);
        }
    }
}
