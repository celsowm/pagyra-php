<?php

declare(strict_types=1);

namespace Pagyra\Core;

final readonly class RenderHtmlOptions
{
    public function __construct(
        public string $html,
        public string $css = '',
        public float $viewportWidth = 794.0,
        public float $viewportHeight = 1123.0,
        public float $pageWidth = 794.0,
        public float $pageHeight = 1123.0,
        public array $margins = ['top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0],
    ) {
        if ($this->html === '') {
            throw new \InvalidArgumentException('html must not be empty');
        }
    }

    public static function fromArray(array $options): self
    {
        if (!isset($options['html']) || !is_string($options['html'])) {
            throw new \InvalidArgumentException('html is required and must be a string');
        }

        $margins = self::normalizeMargins($options['margins'] ?? []);

        return new self(
            html: $options['html'],
            css: isset($options['css']) && is_string($options['css']) ? $options['css'] : '',
            viewportWidth: self::positiveNumber($options['viewportWidth'] ?? 794.0, 'viewportWidth'),
            viewportHeight: self::positiveNumber($options['viewportHeight'] ?? 1123.0, 'viewportHeight'),
            pageWidth: self::positiveNumber($options['pageWidth'] ?? 794.0, 'pageWidth'),
            pageHeight: self::positiveNumber($options['pageHeight'] ?? 1123.0, 'pageHeight'),
            margins: $margins,
        );
    }

    private static function normalizeMargins(mixed $value): array
    {
        $defaults = ['top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0];
        if (!is_array($value)) {
            return $defaults;
        }

        foreach ($defaults as $side => $default) {
            if (array_key_exists($side, $value)) {
                $defaults[$side] = self::nonNegativeNumber($value[$side], "margins.$side");
            }
        }

        return $defaults;
    }

    private static function positiveNumber(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("$name must be numeric");
        }
        if ($value <= 0) {
            throw new \InvalidArgumentException("$name must be greater than zero");
        }

        return (float) $value;
    }

    private static function nonNegativeNumber(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("$name must be numeric");
        }
        if ($value < 0) {
            throw new \InvalidArgumentException("$name must be non-negative");
        }

        return (float) $value;
    }
}
