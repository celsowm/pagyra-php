<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

final readonly class TextMeasurement implements \JsonSerializable
{
    public function __construct(
        public float $inlineSize,
        public float $minInlineSize,
        public float $blockSize,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'inlineSize' => $this->inlineSize,
            'minInlineSize' => $this->minInlineSize,
            'blockSize' => $this->blockSize,
        ];
    }
}
