<?php

declare(strict_types=1);

namespace Pagyra\Dom;

final readonly class Node implements \JsonSerializable
{
    /** @param array<string,string> $attributes
     *  @param list<Node> $children
     */
    public function __construct(
        public string $type,
        public ?string $tagName = null,
        public ?string $text = null,
        public array $attributes = [],
        public array $children = [],
    ) {
    }

    public static function document(array $children): self
    {
        return new self('document', children: $children);
    }

    public static function element(string $tagName, array $attributes, array $children): self
    {
        return new self('element', strtolower($tagName), attributes: $attributes, children: $children);
    }

    public static function text(string $text): self
    {
        return new self('text', text: $text);
    }

    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type];
        if ($this->tagName !== null) {
            $data['tagName'] = $this->tagName;
        }
        if ($this->text !== null) {
            $data['text'] = $this->text;
        }
        if ($this->attributes !== []) {
            $data['attributes'] = $this->attributes;
        }
        if ($this->children !== []) {
            $data['children'] = $this->children;
        }

        return $data;
    }
}
