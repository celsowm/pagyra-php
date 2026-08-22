<?php

declare(strict_types=1);

namespace Pagyra\Html;

use Pagyra\Dom\Node;

final readonly class HtmlDocument implements \JsonSerializable
{
    /**
     * @param list<string> $embeddedCss
     * @param list<string> $stylesheetHrefs
     */
    public function __construct(
        public Node $root,
        public array $embeddedCss = [],
        public array $stylesheetHrefs = [],
    ) {
    }

    public function mergedEmbeddedCss(string $externalCss = ''): string
    {
        $chunks = [];
        if (trim($externalCss) !== '') {
            $chunks[] = $externalCss;
        }
        foreach ($this->embeddedCss as $css) {
            if (trim($css) !== '') {
                $chunks[] = $css;
            }
        }

        return implode("\n", $chunks);
    }

    public function jsonSerialize(): array
    {
        return [
            'root' => $this->root,
            'embeddedCss' => $this->embeddedCss,
            'stylesheetHrefs' => $this->stylesheetHrefs,
        ];
    }
}
