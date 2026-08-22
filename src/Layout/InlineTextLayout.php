<?php

declare(strict_types=1);

namespace Pagyra\Layout;

final readonly class InlineTextLayout implements \JsonSerializable
{
    /** @param list<LineBox> $lines */
    public function __construct(
        public array $lines,
        public float $height,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'lines' => $this->lines,
            'height' => $this->height,
        ];
    }
}
