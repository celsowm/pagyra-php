<?php

declare(strict_types=1);

namespace Pagyra\Style;

final readonly class ComputedStyle implements \JsonSerializable
{
    /** @param array<string,string> $properties */
    public function __construct(public array $properties = [])
    {
    }

    public function get(string $property, ?string $default = null): ?string
    {
        return $this->properties[strtolower($property)] ?? $default;
    }

    public function jsonSerialize(): array
    {
        return $this->properties;
    }
}
