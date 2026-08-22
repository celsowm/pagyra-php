<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

final readonly class PercentLength implements \JsonSerializable
{
    public function __construct(public float $ratio)
    {
    }

    public function jsonSerialize(): array
    {
        return ['kind' => 'percent', 'value' => $this->ratio];
    }
}
