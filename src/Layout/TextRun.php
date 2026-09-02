<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\ComputedStyle;

final readonly class TextRun implements \JsonSerializable
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $baseline,
        public string $text,
        public float $fontSize,
        public ComputedStyle $style,
        /**
         * Extra advance each space in this run carries because the line is justified. It only
         * exists in the layout's x arithmetic, so the serializer has to reproduce it (as `Tw`,
         * or as a TJ adjustment for embedded fonts) or the drawn line falls short of the margin.
         */
        public float $justificationWordSpacing = 0.0,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'baseline' => $this->baseline,
            'text' => $this->text,
            'fontSize' => $this->fontSize,
            'style' => $this->style,
            'justificationWordSpacing' => $this->justificationWordSpacing,
        ];
    }
}
