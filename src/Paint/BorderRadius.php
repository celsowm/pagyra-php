<?php

declare(strict_types=1);

namespace Pagyra\Paint;

final readonly class BorderRadius implements \JsonSerializable
{
    public function __construct(
        public CornerRadius $topLeft = new CornerRadius(),
        public CornerRadius $topRight = new CornerRadius(),
        public CornerRadius $bottomRight = new CornerRadius(),
        public CornerRadius $bottomLeft = new CornerRadius(),
    ) {
    }

    public function isZero(): bool
    {
        return $this->topLeft->x <= 0.0 && $this->topLeft->y <= 0.0
            && $this->topRight->x <= 0.0 && $this->topRight->y <= 0.0
            && $this->bottomRight->x <= 0.0 && $this->bottomRight->y <= 0.0
            && $this->bottomLeft->x <= 0.0 && $this->bottomLeft->y <= 0.0;
    }

    public function jsonSerialize(): array
    {
        return [
            'topLeft' => $this->topLeft,
            'topRight' => $this->topRight,
            'bottomRight' => $this->bottomRight,
            'bottomLeft' => $this->bottomLeft,
        ];
    }
}
