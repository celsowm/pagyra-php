<?php

declare(strict_types=1);

namespace Pagyra\Layout;

/**
 * Content-driven inline size bounds used by shrink-to-fit and intrinsic layout.
 *
 * maxContent is the preferred width with soft wrapping disabled. minContent is the narrowest
 * width the content can normally wrap to without overflowing an unbreakable item.
 */
final readonly class IntrinsicInlineSize implements \JsonSerializable
{
    public function __construct(
        public float $minContent,
        public float $maxContent,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'minContent' => $this->minContent,
            'maxContent' => $this->maxContent,
        ];
    }
}
