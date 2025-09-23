<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Block\PdfBlockRenderer;

class FixedElementManager
{
    private PdfBuilder $pdf;
    private array $elements = [];

    public function __construct(PdfBuilder $pdf)
    {
        $this->pdf = $pdf;
    }

    public function add(array $elements, array $options, float $x, float $y): void
    {
        if ($this->pdf->isMeasurementMode()) {
            return;
        }
        $key = md5(serialize([$elements, $options]));
        if (!isset($this->elements[$key])) {
            $this->elements[$key] = [
                'elements' => $elements,
                'options' => $options,
                'x' => $x,
                'y' => $y
            ];
        }
    }

    public function renderAll(): void
    {
        if ($this->pdf->isMeasurementMode() || empty($this->elements)) {
            return;
        }

        $originalCursorY = $this->pdf->getCursorY();

        foreach ($this->elements as $element) {
            $renderer = new PdfBlockRenderer($this->pdf);
            $renderer->render(
                $element['elements'],
                array_merge($element['options'], [
                    'position' => 'absolute',
                    'x' => $element['x'],
                    'y' => $element['y']
                ])
            );
        }

        // Restore cursor position after rendering fixed elements
        $headerManager = $this->pdf->getHeaderManager();
        if ($headerManager->isDefined() && $headerManager->pushesContent()) {
            $this->pdf->setCursorY($this->pdf->getPageHeight() - $headerManager->getOffset($this->pdf->baseMargins['top']));
        } else {
            $this->pdf->setCursorY($originalCursorY);
        }
    }
}
