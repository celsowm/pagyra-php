<?php

declare(strict_types=1);

namespace Pagyra\Paint;

final readonly class PageDisplayList implements \JsonSerializable
{
    /** @param list<BoxPaintCommand|TextPaintCommand> $commands */
    public function __construct(
        public int $pageIndex,
        public float $width,
        public float $height,
        public array $commands = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'pageIndex' => $this->pageIndex,
            'width' => $this->width,
            'height' => $this->height,
            'commands' => $this->commands,
        ];
    }
}
