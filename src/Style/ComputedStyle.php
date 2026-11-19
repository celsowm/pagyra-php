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
        // Properties are stored lower-cased and nearly every caller already asks in lower case;
        // the lookup is the hottest call of a render, so lower-case only when it misses.
        return $this->properties[$property] ?? $this->properties[strtolower($property)] ?? $default;
    }

    public function jsonSerialize(): array
    {
        return $this->properties;
    }
}
