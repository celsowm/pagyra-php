<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\TextRun;

final readonly class TextPaintCommand implements \JsonSerializable
{
    public function __construct(
        public TextRun $run,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $baseline,
        public string $text,
        public float $fontSize,
        public ?string $fontFamily,
        public int $fontWeight,
        public string $fontStyle,
        public ?Rgba $color,
        public bool $underline = false,
        public bool $lineThrough = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'text',
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'baseline' => $this->baseline,
            'text' => $this->text,
            'fontSize' => $this->fontSize,
            'fontFamily' => $this->fontFamily,
            'fontWeight' => $this->fontWeight,
            'fontStyle' => $this->fontStyle,
            'color' => $this->color,
            'underline' => $this->underline,
            'lineThrough' => $this->lineThrough,
            'run' => $this->run,
        ];
    }
}
