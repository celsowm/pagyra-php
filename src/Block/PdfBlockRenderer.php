<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Block;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Image\PdfImageManager;
use Celsowm\PagyraPhp\Text\PdfTextRenderer;


final class PdfBlockRenderer
{
    private PdfBuilder $pdf;
    private PdfTextRenderer $textRenderer;
    private PdfImageManager $imageManager;

    public function __construct(PdfBuilder $pdf)
    {
        $this->pdf = $pdf;
        $this->textRenderer = $pdf->getTextRenderer();
        $this->imageManager = $pdf->getImageManager();
    }

    public function render(array $elements, array $options): float
    {
        $position = $options['position'] ?? 'relative';
        $borderSpec = $this->pdf->normalizeBorderSpec($options['border'] ?? null, $options['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $margin = $this->normalizeMargin($options['margin'] ?? 0);
        $bgColor = $this->pdf->normalizeColor($options['bgcolor'] ?? null);
        $width = $this->calculateWidth($options['width'] ?? '100%');

        $x = $this->calculateX($width, $options['align'] ?? 'left', $margin);

        $y = $this->pdf->getCursorY();
        $originalY = $y;
        $isAbsolute = false;

        if ($position === 'absolute' && isset($options['x'], $options['y'])) {
            $x = (float)$options['x'];
            $y = $this->pdf->getPageHeight() - (float)$options['y'];
            $isAbsolute = true;
        } elseif ($position === 'fixed' && isset($options['x'], $options['y'])) {
            $x = (float)$options['x'];
            $y = $this->pdf->getPageHeight() - (float)$options['y'];
            $isAbsolute = true;
            $this->pdf->registerFixedElement($elements, $options, (float)$options['x'], (float)$options['y']);
        } elseif ($position === 'sticky') {
            $stickyTop = (float)($options['stickyTop'] ?? 0);
            $stickyTriggerY = $this->pdf->getPageHeight() - $stickyTop;

            if ($originalY <= $stickyTriggerY) {
                $y = $stickyTriggerY;
                $isAbsolute = true;
            } else {
                $this->pdf->setCursorY($y - $margin[0]);
                $isAbsolute = false;
            }
        } else {
            $this->pdf->setCursorY($y - $margin[0]);
            $isAbsolute = false;

            if ($position === 'relative') {
                $x += (float)($options['left'] ?? 0) - (float)($options['right'] ?? 0);
                $y -= (float)($options['top'] ?? 0) - (float)($options['bottom'] ?? 0);
            }
        }

        $width -= ($margin[1] + $margin[3]);
        $startY = $isAbsolute ? $y : $this->pdf->getCursorY();
        $contentStartY = $startY - $padding[0];

        $originalState = [
            'mLeft' => $this->pdf->mLeft,
            'mRight' => $this->pdf->mRight,
            'cursorY' => $this->pdf->getCursorY(),
        ];

        $this->pdf->mLeft = $x + $padding[3];
        $this->pdf->mRight = $this->pdf->getPageWidth() - ($x + $width - $padding[1]);
        $this->pdf->setCursorY($contentStartY);

        foreach ($elements as $element) {
            match ($element['type']) {
                'paragraph' => $this->pdf->addParagraphText($element['content'], $element['options']),
                'image' => $this->pdf->addImageBlock($element['alias'], $element['options']),
                'table' => $this->pdf->addTableData($element['data'], $element['options']),
                'list' => $this->pdf->addList($element['items'], $element['options']),
                'spacer' => $this->pdf->addSpacer($element['height']),
                'hr' => $this->pdf->addHorizontalLine($element['options']),
                'block' => $element['builder']->end(),
                default => null,
            };
        }

        $contentEndY = $this->pdf->getCursorY();
        $contentHeight = $contentStartY - $contentEndY;
        $totalHeight = $padding[0] + $contentHeight + $padding[2];

        if (($options['minHeight'] ?? null) !== null) {
            $totalHeight = max($totalHeight, (float)$options['minHeight']);
        }
        if (($options['maxHeight'] ?? null) !== null && $totalHeight > $options['maxHeight']) {
            $totalHeight = (float)$options['maxHeight'];
        }

        $this->drawBackground($x, $startY - $totalHeight, $width, $totalHeight, $bgColor, $borderSpec['radius'] ?? null);
        if ($borderSpec['hasBorder']) {
            $this->drawBorder($x, $startY - $totalHeight, $width, $totalHeight, $borderSpec);
        }

        $this->pdf->mLeft = $originalState['mLeft'];
        $this->pdf->mRight = $originalState['mRight'];

        if ($isAbsolute) {
            $this->pdf->setCursorY($originalState['cursorY']);
        } else {
            $this->pdf->setCursorY($startY - $totalHeight - $margin[2]);
        }

        return $totalHeight;
    }

    private function normalizeMargin(float|array|null $margin): array
    {
        return $this->pdf->normalizePadding($margin ?? 0);
    }

    private function calculateWidth(string|float $spec): float
    {
        $availableWidth = $this->pdf->getContentAreaWidth();
        if (is_string($spec) && str_ends_with($spec, '%')) {
            $percent = floatval(rtrim($spec, '%')) / 100;
            return $availableWidth * $percent;
        }
        if (is_numeric($spec)) {
            return min((float)$spec, $availableWidth);
        }
        return $availableWidth;
    }

    private function calculateX(float $blockWidth, string $align, array $margin): float
    {
        $availableWidth = $this->pdf->getContentAreaWidth();
        $effectiveWidth = $blockWidth + $margin[1] + $margin[3];
        return match (strtolower($align)) {
            'center' => $this->pdf->mLeft + ($availableWidth - $effectiveWidth) / 2.0 + $margin[3],
            'right' => $this->pdf->mLeft + $availableWidth - $effectiveWidth + $margin[3],
            default => $this->pdf->mLeft + $margin[3],
        };
    }

    private function drawBackground(float $x, float $y, float $w, float $h, ?array $color, ?array $radius): void
    {
        if ($color === null) return;

        $r = $radius ?? [0, 0, 0, 0];
        $hasRadius = is_array($r) ? (max($r) > 0.0001) : (float)$r > 0.0001;
        if (!is_array($r)) $r = array_fill(0, 4, (float)$r);

        if ($hasRadius) {
            $this->pdf->drawRoundedBackgroundRect($x, $y, $w, $h, $r, $color);
        } else {
            $this->pdf->drawBackgroundRect($x, $y, $w, $h, $color);
        }
    }

    private function drawBorder(float $x, float $y, float $w, float $h, array $spec): void
    {
        $box = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        $this->pdf->drawParagraphBorders($box, $spec);
    }
}