<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

class PdfMeasurementManager
{
    private bool $measurementMode = false;
    private int $measurementDepth = 0;

    public function enterMeasurementMode(): void
    {
        $this->measurementDepth++;
        if ($this->measurementDepth === 1) {
            $this->measurementMode = true;
        }
    }

    public function exitMeasurementMode(): void
    {
        if ($this->measurementDepth > 0) {
            $this->measurementDepth--;
        }
        if ($this->measurementDepth === 0) {
            $this->measurementMode = false;
        }
    }

    public function isMeasurementMode(): bool
    {
        return $this->measurementMode;
    }

    public function measureBlockHeight(array $elements, array $options, PdfBuilder $pdfBuilder): float
    {
        $layoutState = $pdfBuilder->getLayoutManager()->snapshotState();
        $origLeft  = $pdfBuilder->getLeftMargin();
        $origRight = $pdfBuilder->getRightMargin();

        $this->enterMeasurementMode();

        try {
            $borderSpec = $pdfBuilder->normalizeBorderSpec($options['border'] ?? null, $options['padding'] ?? null);
            $padding    = $borderSpec['padding'];
            $margin     = $pdfBuilder->normalizePadding($options['margin'] ?? 0);

            $avail = $pdfBuilder->getContentAreaWidth();
            $wSpec = $options['width'] ?? '100%';

            $blockW = match (true) {
                is_string($wSpec) && str_ends_with($wSpec, '%')
                => $avail * max(0.0, min(1.0, (float) rtrim($wSpec, '%') / 100.0)),
                is_numeric($wSpec)
                => min((float)$wSpec, $avail),
                default
                => $avail,
            };

            $align = strtolower($options['align'] ?? 'left');
            $effectiveW = $blockW + $margin[1] + $margin[3];

            $x = match ($align) {
                'center' => $pdfBuilder->getLeftMargin() + ($avail - $effectiveW) / 2.0 + $margin[3],
                'right'  => $pdfBuilder->getLeftMargin() + $avail - $effectiveW + $margin[3],
                default  => $pdfBuilder->getLeftMargin() + $margin[3],
            };

            $startY = $pdfBuilder->getCursorY() - $margin[0];

            $pdfBuilder->mLeft = $x + $padding[3];
            $pdfBuilder->mRight = $pdfBuilder->getPageWidth() - ($x + $blockW - $padding[1]);

            $pdfBuilder->setCursorY($startY - $padding[0]);

            foreach ($elements as $el) {
                $type = $el['type'] ?? null;

                $fn = match ($type) {
                    'paragraph' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addParagraphText((string)($el['content'] ?? ''), (array)($el['options'] ?? []));
                    },
                    'image' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addImageBlock((string)($el['alias'] ?? ''), (array)($el['options'] ?? []));
                    },
                    'table' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addTableData((array)($el['data'] ?? []), (array)($el['options'] ?? []));
                    },
                    'list' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addList($el['items'] ?? [], (array)($el['options'] ?? []));
                    },
                    'spacer' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addSpacer((float)($el['height'] ?? 0.0));
                    },
                    'hr' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addHorizontalLine((array)($el['options'] ?? []));
                    },
                    'block' => function () use ($el, $pdfBuilder) {
                        if (isset($el['builder']) && method_exists($el['builder'], 'getDefinition')) {
                            $def = $el['builder']->getDefinition();
                            $childElements = (array)($def['elements'] ?? []);
                            $childOptions  = (array)($def['options'] ?? []);
                            $height = $this->measureBlockHeight($childElements, $childOptions, $pdfBuilder);
                            $position = strtolower((string)($childOptions['position'] ?? 'relative'));
                            if ($position !== 'absolute' && $position !== 'fixed') {
                                $margins = $pdfBuilder->normalizePadding($childOptions['margin'] ?? 0);
                                $total = $margins[0] + $height + $margins[2];
                                if ($total > 0.0) {
                                    $pdfBuilder->setCursorY($pdfBuilder->getCursorY() - $total);
                                }
                            }
                        }
                    },
                    'runs' => function () use ($el, $pdfBuilder) {
                        $pdfBuilder->addParagraphRuns((array)($el['runs'] ?? []), (array)($el['options'] ?? []));
                    },
                    default => function () { /* no-op p/ tipos desconhecidos */
                    },
                };

                $fn();
            }

            $contentBottomY = $pdfBuilder->getCursorY();
            $contentHeight  = $startY - $contentBottomY; // já exclui padding-top
            return max(0.0, $padding[0] + $contentHeight + $padding[2]);
        } finally {
            $pdfBuilder->getLayoutManager()->restoreState($layoutState);
            $pdfBuilder->mLeft = $origLeft;
            $pdfBuilder->mRight = $origRight;
            $this->exitMeasurementMode();
        }
    }
}
