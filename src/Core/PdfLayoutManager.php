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


    public function snapshotState(): array
    {
        return [
            'layoutStack' => $this->layoutStack,
            'cursorY' => $this->cursorY,
        ];
    }

    public function restoreState(array $state): void
    {
        $this->layoutStack = $state['layoutStack'];
        $this->currentContext = end($this->layoutStack);
        $this->cursorY = $state['cursorY'];
        $this->pdf->mLeft = $this->currentContext['mLeft'];
        $this->pdf->mRight = $this->currentContext['mRight'];
    }

    public function updateBaseBottomMargin(float $bottom): void
    {
        if (empty($this->layoutStack)) {
            return;
        }

        $bottom = max(0.0, $bottom);
        $this->layoutStack[0]['mBottom'] = $bottom;
        $this->layoutStack[0]['height'] = $this->pageHeight - $this->layoutStack[0]['mTop'] - $bottom;

        foreach ($this->layoutStack as $index => &$context) {
            if ($index === 0) {
                continue;
            }
            if ($context['mBottom'] < $bottom) {
                $context['mBottom'] = $bottom;
            }
            $context['height'] = $this->pageHeight - $context['mTop'] - $context['mBottom'];
        }
        unset($context);

        $this->currentContext = end($this->layoutStack);
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
            if ($this->pdf->isMeasurementMode() || $this->pdf->arePageBreaksSuppressed()) {
                $this->cursorY = $this->currentContext['mBottom'];
                return;
            }
            $this->pdf->internal_newPage();
            $this->resetCursor();
        }
    }

    public function resetCursor(): void
    {
        $topMargin = $this->currentContext['mTop'];
        $contentTop = $this->pdf->getContentTopOffset();
        if ($contentTop > $topMargin) {
            $topMargin = $contentTop;
        }
        $this->cursorY = $this->pageHeight - $topMargin;
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