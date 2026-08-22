<?php

declare(strict_types=1);

namespace Pagyra\Style;

use Pagyra\Dom\Node;

final readonly class StyledNode implements \JsonSerializable
{
    /** @param list<StyledNode> $children */
    public function __construct(
        public Node $node,
        public ComputedStyle $style,
        public array $children = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'node' => $this->node,
            'style' => $this->style,
            'children' => $this->children,
        ];
    }
}
