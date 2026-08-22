<?php

declare(strict_types=1);

namespace Pagyra\Dom;

final readonly class Node implements \JsonSerializable
{
    /**
     * @param array<string,string> $attributes
     * @param list<Node> $children
     */
    public function __construct(
        public string $type,
        public ?string $tagName = null,
        public ?string $text = null,
        public array $attributes = [],
        public array $children = [],
        public ?float $intrinsicWidth = null,
        public ?float $intrinsicHeight = null,
    ) {
    }

    /** @param list<Node> $children */
    public static function document(array $children): self
    {
        return new self('document', children: $children);
    }

    /** @param array<string,string> $attributes @param list<Node> $children */
    public static function element(
        string $tagName,
        array $attributes,
        array $children,
        ?float $intrinsicWidth = null,
        ?float $intrinsicHeight = null,
    ): self {
        return new self(
            'element',
            strtolower($tagName),
            attributes: $attributes,
            children: $children,
            intrinsicWidth: $intrinsicWidth,
            intrinsicHeight: $intrinsicHeight,
        );
    }

    public static function text(string $text): self
    {
        return new self('text', text: $text);
    }

    public function attribute(string $name): ?string
    {
        $name = strtolower($name);
        $value = $this->attributes[$name] ?? null;
        if ($value !== null) {
            return $value;
        }

        if ($this->isImage()) {
            if ($name === 'width' && $this->intrinsicWidth !== null) {
                return $this->formatIntrinsicDimension($this->intrinsicWidth);
            }
            if ($name === 'height' && $this->intrinsicHeight !== null) {
                return $this->formatIntrinsicDimension($this->intrinsicHeight);
            }
        }

        return null;
    }

    public function id(): ?string
    {
        $id = $this->attribute('id');
        return $id === null || $id === '' ? null : $id;
    }

    /** @return list<string> */
    public function classes(): array
    {
        $class = trim($this->attribute('class') ?? '');
        if ($class === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $class) ?: []));
    }

    public function inlineStyle(): ?string
    {
        $style = trim($this->attribute('style') ?? '');
        return $style === '' ? null : $style;
    }

    public function isElement(string $tagName): bool
    {
        return $this->type === 'element' && $this->tagName === strtolower($tagName);
    }

    public function isImage(): bool
    {
        return $this->isElement('img');
    }

    public function isSvg(): bool
    {
        return $this->isElement('svg');
    }

    public function textContent(): string
    {
        if ($this->type === 'text') {
            return $this->text ?? '';
        }

        $text = '';
        foreach ($this->children as $child) {
            $text .= $child->textContent();
        }
        return $text;
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
        if ($this->intrinsicWidth !== null) {
            $data['intrinsicWidth'] = $this->intrinsicWidth;
        }
        if ($this->intrinsicHeight !== null) {
            $data['intrinsicHeight'] = $this->intrinsicHeight;
        }

        return $data;
    }

    private function formatIntrinsicDimension(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
