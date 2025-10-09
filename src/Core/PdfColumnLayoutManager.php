<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;

class PdfColumnLayoutManager
{
    private PdfBuilder $pdfBuilder;

    public function __construct(PdfBuilder $pdfBuilder)
    {
        $this->pdfBuilder = $pdfBuilder;
    }

    public function addColumns(array $columns, array $options = []): void
    {
        if (empty($columns)) {
            return;
        }

        $opts = array_merge([
            'gap' => 10.0,
            'widths' => 'equal',
        ], $options);

        $this->pdfBuilder->getLayoutManager()->checkPageBreak();
        $startY = $this->pdfBuilder->getCursorY();
        $availableWidth = $this->pdfBuilder->getContentAreaWidth();

        $columnWidths = $this->calculateColumnWidthsFromOptions(
            $opts['widths'],
            count($columns),
            $availableWidth,
            $opts['gap']
        );

        $columnHeights = [];
        $currentX = 0;

        foreach ($columns as $i => $columnContent) {
            $width = $columnWidths[$i];
            $this->pdfBuilder->getLayoutManager()->pushContext($currentX, $width);
            $this->pdfBuilder->getLayoutManager()->setCursorY($startY);

            $this->renderColumnContent($columnContent, $width);
            $columnHeights[] = $startY - $this->pdfBuilder->getCursorY();
            $this->pdfBuilder->getLayoutManager()->popContext();

            $currentX += $width + $opts['gap'];
        }

        $maxHeight = empty($columnHeights) ? 0 : max($columnHeights);
        $this->pdfBuilder->getLayoutManager()->setCursorY($startY - $maxHeight);
    }

    private function calculateColumnWidthsFromOptions($widths, int $numColumns, float $availableWidth, float $gap): array
    {
        $contentWidth = $availableWidth - (($numColumns - 1) * $gap);
        if ($widths === 'equal') {
            return array_fill(0, $numColumns, $contentWidth / $numColumns);
        }

        if (is_array($widths)) {
            $result = [];
            $fixedTotal = 0;
            $percentTotal = 0;
            foreach ($widths as $w) {
                if (is_string($w) && str_ends_with($w, '%')) {
                    $percentTotal += floatval(rtrim($w, '%'));
                } elseif (is_numeric($w)) {
                    $fixedTotal += (float)$w;
                }
            }
            $remainingForPercent = $contentWidth - $fixedTotal;
            for ($i = 0; $i < $numColumns; $i++) {
                $w = $widths[$i] ?? 'auto';
                if (is_string($w) && str_ends_with($w, '%')) {
                    $result[] = (floatval(rtrim($w, '%')) / $percentTotal) * $remainingForPercent;
                } elseif (is_numeric($w)) {
                    $result[] = (float)$w;
                } else {
                    $result[] = $remainingForPercent / ($numColumns - count(array_filter($widths, 'is_numeric')));
                }
            }
            return $result;
        }
        return array_fill(0, $numColumns, $contentWidth / $numColumns);
    }

    private function renderColumnContent($content, float $columnWidth): void
    {
        if (is_callable($content)) {
            $content($this->pdfBuilder);
        } elseif ($content instanceof PdfBlockBuilder) {
            $content->end();
        } elseif (is_array($content)) {
            foreach ($content as $element) {
                if (is_string($element)) {
                    $this->pdfBuilder->addParagraphText($element, []);
                } elseif (is_array($element) && isset($element['type'])) {
                    $this->renderColumnElement($element);
                } elseif (is_callable($element)) {
                    $element($this->pdfBuilder);
                }
            }
        } elseif (is_string($content)) {
            $this->pdfBuilder->addParagraphText($content, []);
        }
    }

    private function renderColumnElement(array $element): void
    {
        match ($element['type'] ?? '') {
            'paragraph', 'p' => $this->pdfBuilder->addParagraphText($element['text'] ?? $element['content'] ?? '', $element['options'] ?? []),
            'image', 'img' => $this->pdfBuilder->addImageBlock($element['alias'] ?? $element['src'] ?? '', $element['options'] ?? []),
            'table' => $this->pdfBuilder->addTableData($element['data'] ?? [], $element['options'] ?? []),
            'list', 'ul', 'ol' => $this->pdfBuilder->addList($element['items'] ?? $element['data'] ?? [], $element['options'] ?? []),
            'spacer', 'space' => $this->pdfBuilder->addSpacer($element['height'] ?? 10),
            'line', 'hr' => $this->pdfBuilder->addHorizontalLine($element['options'] ?? []),
            'separator' => $this->pdfBuilder->addSeparator($element['options'] ?? [])
        };
    }

    private function drawColumnSeparators(array $columnsData, float $y, float $height, $separatorOptions): void
    {
        $sepOpts = array_merge([
            'width' => 0.5,
            'color' => ['gray' => 0.7],
            'style' => 'solid',
            'margin' => 5
        ], is_array($separatorOptions) ? $separatorOptions : []);

        $sepColor = $this->pdfBuilder->normalizeColor($sepOpts['color']);
        for ($i = 0; $i < count($columnsData) - 1; $i++) {
            $col = $columnsData[$i];
            $nextCol = $columnsData[$i + 1];
            $gap = $nextCol['x'] - ($col['x'] + $col['width']);
            $sepX = $col['x'] + $col['width'] + ($gap / 2);
            $sepY1 = $y - $sepOpts['margin'];
            $sepY2 = $y - $height + $sepOpts['margin'];
            if ($this->pdfBuilder->getCurrentPage() === null) {
                continue;
            }

            $ops = "q\n" . sprintf("%.3F w\n", $sepOpts['width']) . $this->pdfBuilder->strokeColorOps($sepColor) .
                (match ($sepOpts['style']) {
                    'dashed' => "[6 3] 0 d\n",
                    'dotted' => "[1 2] 0 d\n",
                    default => "[] 0 d\n"
                }) .
                sprintf("%.3F %.3F m\n%.3F %.3F l\nS\nQ\n", $sepX, $sepY1, $sepX, $sepY2);
            $this->pdfBuilder->appendToPageContent($ops);
        }
    }
}
