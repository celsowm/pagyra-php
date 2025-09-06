<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Text;

final class PdfRun
{
    public function __construct(
        public string $text,
        public array $options = [],
        public bool $isInline = false,
        public ?Closure $inlineRenderer = null
    ) {}

    public function hasLink(): bool
    {
        return isset($this->options['href']) && !empty($this->options['href']);
    }

    public function getLink(): ?string
    {
        return $this->options['href'] ?? null;
    }
}