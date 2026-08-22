<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

final readonly class RelativeLength implements \JsonSerializable
{
    public function __construct(
        public string $unit,
        public float $value,
    ) {
        if (!in_array($unit, ['em', 'rem'], true)) {
            throw new \InvalidArgumentException('RelativeLength unit must be em or rem');
        }
    }

    public function jsonSerialize(): array
    {
        return ['kind' => 'relative', 'unit' => $this->unit, 'value' => $this->value];
    }
}
