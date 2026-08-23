<?php

declare(strict_types=1);

namespace Pagyra\Svg;

final readonly class SvgDocument implements \JsonSerializable
{
    /**
     * @param array{minX:float,minY:float,width:float,height:float}|null $viewBox
     * @param list<array<string,mixed>> $shapes
     */
    public function __construct(
        public ?float $width,
        public ?float $height,
        public ?array $viewBox,
        public array $shapes,
        public string $preserveAspectRatio = 'xMidYMid meet',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'viewBox' => $this->viewBox,
            'preserveAspectRatio' => $this->preserveAspectRatio,
            'shapes' => $this->shapes,
        ];
    }
}
