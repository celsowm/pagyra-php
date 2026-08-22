<?php

declare(strict_types=1);

namespace Pagyra\Css;

final readonly class StyleRule
{
    /** @param array<string,string> $declarations */
    public function __construct(
        public string $selector,
        public array $declarations,
        public int $sourceOrder,
        public int $specificity,
    ) {
    }
}
