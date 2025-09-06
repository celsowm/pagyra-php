<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;
use Celsowm\PagyraPhp\Core\PdfBuilder;


final class PdfLayoutManager
{
    private PdfBuilder $pdf;
    private float $pageWidth;
    private float $pageHeight;
    private array $layoutStack = [];
    private array $currentContext;
    private float $cursorY = 0.0;

    public function __construct(PdfBuilder $pdf, float $w, float $h)
    {
        $this->pdf = $pdf;
        $this->pageWidth = $w;
        $this->pageHeight = $h;
    }

    public function setBaseMargins(float $top, float $right, float $bottom, float $left): void
    {
        $baseContext = [
            'x'      => $left,
            'y'      => $top,
            'width'  => $this->pageWidth - $left - $right,
            'height' => $this->pageHeight - $top - $bottom,
            'mTop'   => $top,
            'mBottom' => $bottom,
            'mLeft'  => $left,
            'mRight' => $right,
        ];

        $this->layoutStack = [$baseContext];
        $this->currentContext = $baseContext;

        $this->pdf->mLeft = $left;
        $this->pdf->mRight = $right;

        $this->resetCursor();
    }

    public function pushContext(float $x, float $width, ?float $topMargin = null): void
    {
        $parent = $this->currentContext;

        $newContext = [
            'x'      => $parent['x'] + $x,
            'y'      => $this->cursorY,
            'width'  => $width,
            'mTop'   => $topMargin ?? $parent['mTop'],
            'mBottom' => $parent['mBottom'],
            'mLeft'  => $parent['x'] + $x,
            'mRight' => $this->pageWidth - ($parent['x'] + $x + $width),
        ];

        $this->layoutStack[] = $newContext;
        $this->currentContext = $newContext;
        $this->pdf->mLeft = $this->currentContext['mLeft'];
        $this->pdf->mRight = $this->currentContext['mRight'];
    }

    public function popContext(): bool
    {
        if (count($this->layoutStack) <= 1) {
            return false;
        }

        array_pop($this->layoutStack);
        $this->currentContext = end($this->layoutStack);
        $this->pdf->mLeft = $this->currentContext['mLeft'];
        $this->pdf->mRight = $this->currentContext['mRight'];

        return true;
    }

    public function advanceCursor(float $height): void
    {
        $this->cursorY -= $height;
    }

    public function checkPageBreak(float $neededHeight = 0.0): void
    {
        if (($this->cursorY - $neededHeight) < $this->currentContext['mBottom']) {
            $this->pdf->internal_newPage();
            $this->resetCursor();
        }
    }

    public function resetCursor(): void
    {
        $this->cursorY = $this->pageHeight - $this->currentContext['mTop'];
    }

    public function getCursorY(): float
    {
        return $this->cursorY;
    }
    public function setCursorY(float $y): void
    {
        $this->cursorY = $y;
    }
    public function getContentAreaWidth(): float
    {
        return $this->currentContext['width'];
    }
    public function getPageWidth(): float
    {
        return $this->pageWidth;
    }
    public function getPageHeight(): float
    {
        return $this->pageHeight;
    }
    public function getPageBottomMargin(): float
    {
        return $this->currentContext['mBottom'];
    }
}