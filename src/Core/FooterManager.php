<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Block\PdfBlockRenderer;

class FooterManager
{
    private PdfBuilder $pdf;
    private bool $isDefined = false;
    private $callback;
    private array $options;
    private float $height = 0.0;
    private float $bottom = 0.0;
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
            throw new \LogicException('Page footer already defined.');
        }

        if ($this->pdf->isMeasurementMode() || $this->pdf->getCurrentPage() === null) {
            throw new \LogicException('No active page to attach footer.');
        }

        $this->callback = $callback;
        $this->options = $options;
        $this->pushesContent = !isset($options['pushContent']) || (bool)$options['pushContent'];
        $this->spacing = isset($options['contentSpacing']) ? max(0.0, (float)$options['contentSpacing']) : 6.0;
        $this->bottom = isset($options['bottom']) ? max(0.0, (float)$options['bottom']) : $this->pdf->baseMargins['bottom'];

        $this->calculateDimensions();
        $this->isDefined = true;
        $this->firstPageId = $this->pdf->getCurrentPage();

        // Render immediately on the current page
        $this->render();
    }

    private function calculateDimensions(): void
    {
        $builder = new PdfBlockBuilder($this->pdf, ['position' => 'relative']);
        ($this->callback)($builder);
        $definition = $builder->getDefinition();
        if (empty($definition['elements'])) {
            $this->height = 0;
            return;
        }

        $measureOptions = $definition['options'];
        $measureOptions['position'] = 'relative';
        unset($measureOptions['x'], $measureOptions['y']);
        $this->height = $this->pdf->measureBlockHeight($definition['elements'], $measureOptions);
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

        $explicitY = array_key_exists('y', $this->options) ? (float)$this->options['y'] : null;

        if ($explicitY !== null) {
            $renderY = $explicitY;
        } else {
            $topFromBottom = $this->bottom + $this->height;
            $renderY = max(0.0, $this->pdf->getPageHeight() - $topFromBottom);
        }

        $blockOptions = $this->options;
        unset($blockOptions['pushContent'], $blockOptions['contentSpacing'], $blockOptions['bottom']);
        $blockOptions['position'] = 'fixed';
        $blockOptions['x'] = $this->options['x'] ?? $this->pdf->baseMargins['left'];
        $blockOptions['y'] = $renderY;
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
        $this->height = $renderer->render($definition['elements'], $blockOptions);
    }

    public function isDefined(): bool
    {
        return $this->isDefined;
    }

    public function pushesContent(): bool
    {
        return $this->pushesContent;
    }

    public function getOffset(float $baseBottomMargin): float
    {
        if (!$this->isDefined || !$this->pushesContent) {
            return $baseBottomMargin;
        }
        return max($baseBottomMargin, $this->bottom + $this->height + $this->spacing);
    }
}
