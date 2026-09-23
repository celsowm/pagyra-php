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
        /**
         * The `<html>` and `<body>` elements themselves, which the content tree in `$root` does not
         * include (it starts at the body's children). They carry no layout of their own here, but
         * their style is the root of the cascade: `body { font-family }`, `html { font-size }`,
         * `:root { --var }`, `<body class="x">` for `.x p`. `bodyElement` holds the same child
         * nodes as `$root`, so sibling and structural selectors can see the top-level elements.
         */
        public ?Node $htmlElement = null,
        public ?Node $bodyElement = null,
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
