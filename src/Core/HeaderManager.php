<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Block\PdfBlockRenderer;

class HeaderManager
{
    private PdfBuilder $pdf;
    private bool $isDefined = false;
    private $callback;
    private array $options;
    private float $height = 0.0;
    private float $top = 0.0;
    private float $spacing = 0.0;
    private bool $pushesContent = false;
    private int $firstPageId;

    public function __construct(PdfBuilder $pdf)
    {
        $this->pdf = $pdf;
    }

    public function set(callable $callback, array $options = []): void
    {
        if ($this->isDefined) {
            throw new \LogicException('Page header already defined.');
        }

        if ($this->pdf->isMeasurementMode() || $this->pdf->getCurrentPage() === null) {
            throw new \LogicException('No active page to attach header.');
        }

        $existingContent = $this->pdf->pageContents[$this->pdf->getCurrentPage()] ?? '';
        if (trim($existingContent) !== '') {
            throw new \LogicException('Call setHeader() before adding content to the first page.');
        }

        $this->callback = $callback;
        $this->options = $options;
        $this->pushesContent = !isset($options['pushContent']) || (bool)$options['pushContent'];
        $this->spacing = isset($options['contentSpacing']) ? max(0.0, (float)$options['contentSpacing']) : 6.0;
        $this->top = isset($options['y']) ? max(0.0, (float)$options['y']) : 0.0;

        $this->calculateDimensions();

        $this->isDefined = true;
        $this->firstPageId = $this->pdf->getCurrentPage();

        // Render immediately on the current page
        $this->render();
    }

    private function calculateDimensions(): void
    {
        $blockOptions = $this->options;
        unset($blockOptions['pushContent'], $blockOptions['contentSpacing']);
        $blockOptions['position'] = 'fixed';
        $blockOptions['x'] = $this->options['x'] ?? $this->pdf->baseMargins['left'];
        $blockOptions['y'] = $this->top;
        if (!isset($blockOptions['width'])) {
            $blockOptions['width'] = '100%';
        }

        $builder = new PdfBlockBuilder($this->pdf, $blockOptions);
        ($this->callback)($builder);
        $definition = $builder->getDefinition();

        if (empty($definition['elements'])) {
            $this->height = 0;
            return;
        }

        $renderer = new PdfBlockRenderer($this->pdf);

        // We need to measure the height without actually rendering to the page's main content stream
        $this->pdf->suppressPageBreaks();
        $this->pdf->enterMeasurementMode();
        try {
            $this->height = $renderer->render($definition['elements'], $definition['options']);
        } finally {
            $this->pdf->exitMeasurementMode();
            $this->pdf->resumePageBreaks();
        }
    }

    public function render(): void
    {
        if (!$this->isDefined) {
            return;
        }

        // Avoid re-rendering on the first page if it was already rendered by set()
        if (isset($this->firstPageId) && $this->pdf->getCurrentPage() === $this->firstPageId) {
            $isNewPage = count(array_filter($this->pdf->pageContents, function ($content) {
                return trim($content) !== '';
            })) <= 1;
            if (!$isNewPage) return;
        }

        $blockOptions = $this->options;
        unset($blockOptions['pushContent'], $blockOptions['contentSpacing']);
        $blockOptions['position'] = 'fixed';
        $blockOptions['x'] = $this->options['x'] ?? $this->pdf->baseMargins['left'];
        $blockOptions['y'] = $this->top;
        if (!isset($blockOptions['width'])) {
            $blockOptions['width'] = '100%';
        }

        $builder = new PdfBlockBuilder($this->pdf, $blockOptions);
        ($this->callback)($builder);
        $definition = $builder->getDefinition();

        if (empty($definition['elements'])) {
            return;
        }

        $renderer = new PdfBlockRenderer($this->pdf);
        $renderer->render($definition['elements'], $definition['options']);
    }

    public function isDefined(): bool
    {
        return $this->isDefined;
    }

    public function pushesContent(): bool
    {
        return $this->pushesContent;
    }

    public function getOffset(float $baseTopMargin): float
    {
        if (!$this->isDefined || !$this->pushesContent) {
            return $baseTopMargin;
        }
        return max($baseTopMargin, $this->top + $this->height + $this->spacing);
    }
}
