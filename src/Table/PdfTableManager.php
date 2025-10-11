<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Table;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Style\PdfStyleManager;
use Celsowm\PagyraPhp\Text\PdfTextRenderer;

final class PdfTableManager
{
    private PdfBuilder $pdfBuilder;
    private PdfStyleManager $styleManager;
    private PdfTextRenderer $textRenderer;

    public function __construct(PdfBuilder $pdfBuilder)
    {
        $this->pdfBuilder = $pdfBuilder;
        $this->styleManager = $pdfBuilder->getStyleManager();
        $this->textRenderer = $pdfBuilder->getTextRenderer();
    }

    public function addTableData(array $data, array $options = []): void
    {
        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar uma tabela.");
        }
        if (empty($data)) return;

        $this->styleManager->push();

        $opts = array_merge([
            'widths' => 'auto',
            'align' => 'left',
            'headerRow' => null,
            'headerStyle' => 'B',
            'headerBgColor' => '#f0f0f0',
            'headerColor' => null,
            'borders' => true,
            'padding' => 4.0,
            'spacing' => 0.0,
            'alternateRows' => false,
            'altRowColor' => '#f9f9f9',
            'minRowHeight' => null,
            'wrapText' => true,
            'fontSize' => null,
            'lineHeight' => null,
        ], $options);

        if ($opts['fontSize'] !== null) $this->styleManager->setFont($this->styleManager->getCurrentFontAlias(), (float)$opts['fontSize']);
        if ($opts['lineHeight'] !== null) $this->styleManager->applyOptions(['lineHeight' => $opts['lineHeight']], $this->pdfBuilder);

        $numCols = 0;
        foreach ($data as $row) $numCols = max($numCols, count($row));
        if ($numCols === 0) {
            $this->styleManager->pop();
            return;
        }

        $measurementMode = $this->pdfBuilder->isMeasurementMode();

        $adjustedWidth = $opts['adjustedWidth'] ?? null;
        $columnWidths = $this->calculateColumnWidths($data, $opts['widths'], $numCols, $opts, $adjustedWidth);
        $columnAligns = $this->normalizeColumnAligns($opts['align'], $numCols);
        $borderSpec = $this->normalizeTableBorderSpec($opts['borders'], $opts['padding']);
        $cellPadding = $borderSpec['padding'];

        $rowIndex = 0;
        foreach ($data as $row) {
            $isHeader = ($opts['headerRow'] !== null && $rowIndex === $opts['headerRow']);
            $isAltRow = (!$isHeader && $opts['alternateRows'] && ($rowIndex > $opts['headerRow'] ? ($rowIndex - ($opts['headerRow'] + 1)) : $rowIndex) % 2 === 1);

            $rowBgColor = null;
            if ($isHeader && $opts['headerBgColor'] !== null) $rowBgColor = $this->pdfBuilder->normalizeColor($opts['headerBgColor']);
            elseif ($isAltRow && $opts['altRowColor'] !== null) $rowBgColor = $this->pdfBuilder->normalizeColor($opts['altRowColor']);

            $rowHeight = $this->calculateRowHeight($row, $columnWidths, $cellPadding, $opts['wrapText'], $isHeader ? $opts['headerStyle'] : '', $opts['minRowHeight']);
            if (!$measurementMode) {
                $this->pdfBuilder->getLayoutManager()->checkPageBreak($rowHeight);
            }

            if (!$measurementMode) {
                $this->styleManager->push();
                if ($isHeader) $this->styleManager->applyOptions(['style' => $opts['headerStyle']], $this->pdfBuilder);
                if ($isHeader && $opts['headerColor'] !== null) $this->styleManager->setTextColor($this->pdfBuilder->normalizeColor($opts['headerColor']));

                $this->drawTableRow($row, $columnWidths, $columnAligns, $rowHeight, $cellPadding, $borderSpec, $rowBgColor, $opts['wrapText']);

                $this->styleManager->pop();
            }

            $this->pdfBuilder->getLayoutManager()->advanceCursor($rowHeight);
            if ($opts['spacing'] > 0 && $rowIndex < count($data) - 1) $this->pdfBuilder->getLayoutManager()->advanceCursor($opts['spacing']);
            $rowIndex++;
        }
        $this->styleManager->pop();
    }

    private function calculateColumnWidths(array $data, $widths, int $numCols, array $tableOptions, ?float $adjustedWidth = null): array
    {
        $availableWidth = $adjustedWidth ?? $this->pdfBuilder->getLayoutManager()->getContentAreaWidth();

        if (is_array($widths)) {
            $result = [];
            $totalSpecified = array_sum($widths);
            $scale = ($totalSpecified > 0) ? $availableWidth / $totalSpecified : 0;
            for ($i = 0; $i < $numCols; $i++) $result[$i] = isset($widths[$i]) ? $widths[$i] * $scale : 0;
            return $result;
        }

        $maxWidths = array_fill(0, $numCols, 0);
        $rowIndex = 0;
        foreach ($data as $row) {
            $isHeader = (isset($tableOptions['headerRow']) && $rowIndex === $tableOptions['headerRow']);
            $this->styleManager->push();
            if ($isHeader) {
                $this->styleManager->applyOptions(['style' => $tableOptions['headerStyle'] ?? 'B'], $this->pdfBuilder);
            }

            for ($i = 0; $i < $numCols; $i++) {
                if (!isset($row[$i])) {
                    continue;
                }
                $cell = $row[$i];
                $cellOptions = $this->getTableCellOptions($cell);
                [$styleOptions, $unusedBg] = $this->partitionCellOptions($cellOptions);

                $this->styleManager->push();
                if ($styleOptions !== []) {
                    $this->styleManager->applyOptions($styleOptions, $this->pdfBuilder);
                }
                $text = $this->getTableCellText($cell);
                $width = $this->textRenderer->measureTextStyled($text, $this->styleManager);
                $maxWidths[$i] = max($maxWidths[$i], $width);
                $this->styleManager->pop();
            }
            $this->styleManager->pop();
            $rowIndex++;
        }

        $cellPadding = is_numeric($tableOptions['padding'] ?? 4.0) ? (float)($tableOptions['padding']) : 4.0;
        foreach ($maxWidths as &$w) $w += $cellPadding * 2.5;

        $totalMax = array_sum($maxWidths);
        if ($totalMax > 0) {
            $scale = ($totalMax > $availableWidth) ? $availableWidth / $totalMax : 1.0;
            if ($totalMax < $availableWidth) {
                $extra = $availableWidth - $totalMax;
                foreach ($maxWidths as &$w) $w += $extra * ($w / $totalMax);
            } else {
                foreach ($maxWidths as &$w) $w *= $scale;
            }
        } else {
            $maxWidths = array_fill(0, $numCols, $availableWidth / max(1, $numCols));
        }
        return $maxWidths;
    }

    private function normalizeColumnAligns($align, int $numCols): array
    {
        if (is_string($align)) return array_fill(0, $numCols, $align);
        if (is_array($align)) {
            $result = [];
            for ($i = 0; $i < $numCols; $i++) $result[$i] = $align[$i] ?? 'left';
            return $result;
        }
        return array_fill(0, $numCols, 'left');
    }

    private function normalizeTableBorderSpec($borders, $padding): array
    {
        $spec = ['hasBorder' => $borders !== false, 'width' => 0.5, 'color' => $this->pdfBuilder->normalizeColor(['gray' => 0.5]), 'padding' => is_numeric($padding) ? (float)$padding : 4.0];
        if (is_array($borders)) {
            if (isset($borders['width'])) $spec['width'] = (float)$borders['width'];
            if (isset($borders['color'])) $spec['color'] = $this->pdfBuilder->normalizeColor($borders['color']);
        }
        return $spec;
    }

    /**
     * @param mixed $cell
     */
    private function getTableCellText($cell): string
    {
        if (is_array($cell)) {
            $text = $cell['text'] ?? '';
            return is_string($text) ? $text : (string)$text;
        }
        return is_string($cell) ? $cell : (string)$cell;
    }

    /**
     * @param mixed $cell
     * @return array<string, mixed>
     */
    private function getTableCellOptions($cell): array
    {
        if (is_array($cell) && isset($cell['options']) && is_array($cell['options'])) {
            return $cell['options'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function partitionCellOptions(array $options): array
    {
        $styleOptions = $options;
        $bgColor = null;
        if (array_key_exists('bgcolor', $styleOptions)) {
            $bgColor = $styleOptions['bgcolor'];
            unset($styleOptions['bgcolor']);
        }
        return [$styleOptions, $bgColor];
    }

    private function wrapText(string $text, float $maxWidth): array
    {
        if ($maxWidth <= 0 || $this->textRenderer->measureTextStyled($text, $this->styleManager) <= $maxWidth) return [$text];

        $lines = [];
        $currentLine = '';
        $words = explode(' ', $text);
        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            if ($this->textRenderer->measureTextStyled($testLine, $this->styleManager) <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') $lines[] = $currentLine;
                $currentLine = $word;
                if ($this->textRenderer->measureTextStyled($currentLine, $this->styleManager) > $maxWidth) {
                    $longWord = $currentLine;
                    $currentLine = '';
                    $chars = mb_str_split($longWord, 1, 'UTF-8');
                    foreach ($chars as $char) {
                        if ($this->textRenderer->measureTextStyled($currentLine . $char, $this->styleManager) > $maxWidth) {
                            if ($currentLine !== '') $lines[] = $currentLine;
                            $currentLine = $char;
                        } else {
                            $currentLine .= $char;
                        }
                    }
                }
            }
        }
        if ($currentLine !== '') $lines[] = $currentLine;
        return empty($lines) ? [''] : $lines;
    }

    private function calculateRowHeight(array $row, array $columnWidths, float $padding, bool $wrapText, string $style, ?float $minHeight): float
    {
        $this->styleManager->push();
        $this->styleManager->applyOptions(['style' => $style], $this->pdfBuilder);

        $baseLineHeight = $this->styleManager->getLineHeight();
        $maxHeight = $baseLineHeight;

        $columns = min(count($row), count($columnWidths));
        for ($i = 0; $i < $columns; $i++) {
            if (!isset($row[$i], $columnWidths[$i])) {
                continue;
            }
            $cell = $row[$i];
            $cellWidth = $columnWidths[$i] - ($padding * 2);
            if ($cellWidth <= 0) {
                $cellWidth = $columnWidths[$i];
            }

            $cellOptions = $this->getTableCellOptions($cell);
            [$styleOptions, $unusedBg] = $this->partitionCellOptions($cellOptions);

            $this->styleManager->push();
            if ($styleOptions !== []) {
                $this->styleManager->applyOptions($styleOptions, $this->pdfBuilder);
            }
            $text = $this->getTableCellText($cell);
            $lines = $wrapText ? $this->wrapText($text, max(0.0, $cellWidth)) : [$text];
            $cellLineHeight = $this->styleManager->getLineHeight();
            $lineCount = max(1, count($lines));
            $cellHeight = $lineCount * $cellLineHeight;
            $maxHeight = max($maxHeight, $cellHeight);
            $this->styleManager->pop();
        }

        $totalHeight = max($maxHeight + ($padding * 2), $baseLineHeight * 1.5);
        if ($minHeight !== null) {
            $totalHeight = max($totalHeight, $minHeight);
        }

        $this->styleManager->pop();
        return $totalHeight;
    }

    private function getTextVerticalCenterOffset(): float
    {
        $lineHeight = $this->styleManager->getLineHeight();
        $metrics = $this->computeLineMetrics($lineHeight);
        return $metrics['baselineOffset'] - ($lineHeight / 2.0);
    }

    private function computeLineMetrics(float $lineHeight): array
    {
        $size = max(0.001, $this->styleManager->getCurrentFontSize());
        $alias = $this->styleManager->getCurrentFontAlias();
        $style = $this->styleManager->getStyle();
        $fonts = $this->pdfBuilder->getFontManager()->getFonts();
        $resolvedAlias = null;
        if ($alias !== null) {
            $resolvedAlias = $this->pdfBuilder->getFontManager()->resolveAliasByStyle($alias, $style);
        }

        $fontData = null;
        if ($resolvedAlias !== null && isset($fonts[$resolvedAlias])) {
            $fontData = $fonts[$resolvedAlias];
        } elseif ($alias !== null && isset($fonts[$alias])) {
            $fontData = $fonts[$alias];
        }

        if ($fontData !== null) {
            $units = max(1.0, (float)$fontData['unitsPerEm']);
            $ascentPx = ((float)$fontData['ascent'] / $units) * $size;
            $descentPx = (abs((float)$fontData['descent']) / $units) * $size;
        } else {
            $ascentPx = $size * 0.8;
            $descentPx = max($size - $ascentPx, $size * 0.2);
        }

        $glyphHeight = $ascentPx + $descentPx;
        $leading = $lineHeight - $glyphHeight;

        return [
            'baselineOffset' => ($leading / 2.0) + $ascentPx,
            'ascent' => $ascentPx,
            'descent' => $descentPx,
            'leading' => $leading,
            'glyphHeight' => $glyphHeight,
        ];
    }

    private function drawTableRow(array $row, array $columnWidths, array $columnAligns, float $rowHeight, float $padding, array $borderSpec, ?array $bgColor, bool $wrapText): void
    {
        if ($this->pdfBuilder->getCurrentPage() === null) return;

        $x = $this->pdfBuilder->getLeftMargin();
        $y = $this->pdfBuilder->getLayoutManager()->getCursorY() - $rowHeight;
        if ($bgColor !== null) {
            $this->pdfBuilder->drawBackgroundRect($x, $y, array_sum($columnWidths), $rowHeight, $bgColor);
        }

        for ($i = 0; $i < count($columnWidths); $i++) {
            $cellWidth = $columnWidths[$i];
            if ($borderSpec['hasBorder']) {
                $this->drawCellBorder($x, $y, $cellWidth, $rowHeight, $borderSpec);
            }
            if (!isset($row[$i])) {
                $x += $cellWidth;
                continue;
            }

            $cell = $row[$i];
            $cellOptions = $this->getTableCellOptions($cell);
            [$styleOptions, $bgSpec] = $this->partitionCellOptions($cellOptions);

            if ($bgSpec !== null) {
                $cellBgColor = $this->pdfBuilder->normalizeColor($bgSpec);
                if ($cellBgColor !== null) {
                    $this->pdfBuilder->drawBackgroundRect($x, $y, $cellWidth, $rowHeight, $cellBgColor);
                }
            }

            $this->styleManager->push();
            if ($styleOptions !== []) {
                $this->styleManager->applyOptions($styleOptions, $this->pdfBuilder);
            }
            $text = $this->getTableCellText($cell);
            $align = $columnAligns[$i];
            $cellCenterY = $y + ($rowHeight / 2);
            $textBaselineY = $cellCenterY - $this->getTextVerticalCenterOffset();
            $this->drawCellText($text, $x + $padding, $textBaselineY, max(0.0, $cellWidth - ($padding * 2)), $align, $wrapText);
            $this->styleManager->pop();

            $x += $cellWidth;
        }
    }

    private function drawCellBorder(float $x, float $y, float $width, float $height, array $spec): void
    {
        if ($this->pdfBuilder->getCurrentPage() === null) return;
        $ops = "q\n" . sprintf("%.3F w\n", $spec['width']) . $this->pdfBuilder->strokeColorOps($spec['color']) .
            sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $width, $height) . "S\nQ\n";
        $this->pdfBuilder->appendToPageContent($ops);
    }

    private function drawCellText(string $text, float $x, float $y, float $maxWidth, string $align, bool $wrap): void
    {
        $lines = $wrap ? $this->wrapText($text, $maxWidth) : [$text];
        $lineCount = count($lines);
        $lineHeight = $this->styleManager->getLineHeight();
        $yPos = $y + (($lineCount - 1) * $lineHeight) / 2;

        foreach ($lines as $line) {
            $textWidth = $this->textRenderer->measureTextStyled($line, $this->styleManager);
            $xPos = match ($align) {
                'center' => $x + ($maxWidth - $textWidth) / 2,
                'right' => $x + $maxWidth - $textWidth,
                default => $x,
            };
            $this->textRenderer->writeTextLine($xPos, $yPos, $line, $this->styleManager, null);
            $yPos -= $lineHeight;
        }
    }
}
