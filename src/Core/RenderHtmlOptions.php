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
        public array $fontConfig = [],
        public ?string $resourceBaseDir = null,
        public float $contentScale = 1.0,
        public string $media = 'print',
    ) {
        if ($this->html === '') throw new \InvalidArgumentException('html must not be empty');
        if ($this->contentScale <= 0) throw new \InvalidArgumentException('contentScale must be greater than zero');
        if (!in_array($this->media, ['print', 'screen', 'all'], true)) {
            throw new \InvalidArgumentException("media must be 'print', 'screen' or 'all'");
        }
        if ($this->resourceBaseDir !== null && !self::isAbsoluteResourceBase($this->resourceBaseDir)) {
            throw new \InvalidArgumentException('resourceBaseDir must be an absolute local path or file:// URL');
        }
    }

    public static function fromArray(array $options): self
    {
        if (!isset($options['html']) || !is_string($options['html'])) throw new \InvalidArgumentException('html is required and must be a string');
        $resourceBaseDir = $options['resourceBaseDir'] ?? null;
        if ($resourceBaseDir !== null && !is_string($resourceBaseDir)) {
            throw new \InvalidArgumentException('resourceBaseDir must be a string or null');
        }

        return new self(
            html: $options['html'],
            css: isset($options['css']) && is_string($options['css']) ? $options['css'] : '',
            viewportWidth: self::positiveNumber($options['viewportWidth'] ?? 794.0, 'viewportWidth'),
            viewportHeight: self::positiveNumber($options['viewportHeight'] ?? 1123.0, 'viewportHeight'),
            pageWidth: self::positiveNumber($options['pageWidth'] ?? 794.0, 'pageWidth'),
            pageHeight: self::positiveNumber($options['pageHeight'] ?? 1123.0, 'pageHeight'),
            margins: self::normalizeMargins($options['margins'] ?? []),
            fontConfig: is_array($options['fontConfig'] ?? null) ? $options['fontConfig'] : [],
            resourceBaseDir: $resourceBaseDir,
            contentScale: self::positiveNumber($options['contentScale'] ?? 1.0, 'contentScale'),
            media: is_string($options['media'] ?? null) ? strtolower(trim($options['media'])) : 'print',
        );
    }

    /**
     * Returns a copy laid out on a page inflated by 1/contentScale, so that after the
     * PDF serializer scales the sheet back down by contentScale the physical page size
     * is unchanged while roughly 1/contentScale more content flows onto each page — the
     * behaviour wkhtmltopdf gives for free by drawing everything at 0.8x. The returned
     * options carry contentScale 1.0; the caller keeps the original factor for serialization.
     */
    public function scaledForContentZoom(float $contentScale): self
    {
        if ($contentScale <= 0) throw new \InvalidArgumentException('contentScale must be greater than zero');
        if ($contentScale === 1.0) return $this;

        $inflate = static fn (float $value): float => $value / $contentScale;

        return new self(
            html: $this->html,
            css: $this->css,
            viewportWidth: $inflate($this->viewportWidth),
            viewportHeight: $inflate($this->viewportHeight),
            pageWidth: $inflate($this->pageWidth),
            pageHeight: $inflate($this->pageHeight),
            margins: array_map($inflate, $this->margins),
            fontConfig: $this->fontConfig,
            resourceBaseDir: $this->resourceBaseDir,
            contentScale: 1.0,
            media: $this->media,
        );
    }

    private static function normalizeMargins(mixed $value): array
    {
        $defaults = ['top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0];
        if (!is_array($value)) return $defaults;
        foreach ($defaults as $side => $default) if (array_key_exists($side, $value)) $defaults[$side] = self::nonNegativeNumber($value[$side], "margins.$side");
        return $defaults;
    }

    private static function positiveNumber(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) throw new \InvalidArgumentException("$name must be numeric");
        if ($value <= 0) throw new \InvalidArgumentException("$name must be greater than zero");
        return (float) $value;
    }

    private static function nonNegativeNumber(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) throw new \InvalidArgumentException("$name must be numeric");
        if ($value < 0) throw new \InvalidArgumentException("$name must be non-negative");
        return (float) $value;
    }

    private static function isAbsoluteResourceBase(string $value): bool
    {
        $value = trim($value);
        if ($value === '') return false;
        if (str_starts_with(strtolower($value), 'file://')) {
            $value = rawurldecode(substr($value, 7));
            if (preg_match('#^/[a-zA-Z]:[\\\\/]#', $value) === 1) {
                $value = substr($value, 1);
            }
        }

        return str_starts_with($value, '/')
            || str_starts_with($value, '\\\\')
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $value) === 1;
    }
}
