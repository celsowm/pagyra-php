<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Block;

use Celsowm\PagyraPhp\Block\PdfBlockRenderer;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Graphics\Painter\PdfBackgroundPainter;


final class PdfBlockBuilder
{
    private PdfBuilder $pdf;
    private array $elements = [];
    private array $options;
    private ?PdfBackgroundPainter $bgPainter;

    public function __construct(PdfBuilder $pdf, array $options = [], ?PdfBackgroundPainter $bgPainter = null)
    {
        $this->pdf = $pdf;
        $this->options = $options;
        $this->bgPainter = $bgPainter;
    }

    public function addParagraph(string $text, array $opts = []): self
    {
        $this->elements[] = ['type' => 'paragraph', 'content' => $text, 'options' => $opts];
        return $this;
    }

    public function addImage(string $alias, array $opts = []): self
    {
        $this->elements[] = ['type' => 'image', 'alias' => $alias, 'options' => $opts];
        return $this;
    }

    public function addTable(array $data, array $opts = []): self
    {
        $this->elements[] = ['type' => 'table', 'data' => $data, 'options' => $opts];
        return $this;
    }

    public function addList($items, array $opts = []): self
    {
        $this->elements[] = ['type' => 'list', 'items' => $items, 'options' => $opts];
        return $this;
    }

    public function addSpacer(float $height): self
    {
        $this->elements[] = ['type' => 'spacer', 'height' => $height];
        return $this;
    }

    public function addHorizontalLine(array $opts = []): self
    {
        $this->elements[] = ['type' => 'hr', 'options' => $opts];
        return $this;
    }

    public function addBlock(array|callable $optsOrCallback, ?callable $callback = null): self
    {
        if (is_callable($optsOrCallback)) {
            $callback = $optsOrCallback;
            $opts = [];
        } else {
            $opts = $optsOrCallback ?? [];
        }

        $nested = new PdfBlockBuilder($this->pdf, $opts, $this->bgPainter);
        if ($callback) {
            $callback($nested);
        }
        $this->elements[] = ['type' => 'block', 'builder' => $nested, 'options' => $opts];
        return $this;
    }

    public function addParagraphRuns(array $runs, array $opts = []): self
    {
        $this->elements[] = ['type' => 'runs', 'runs' => $runs, 'options' => $opts];
        return $this;
    }

    public function getDefinition(): array
    {
        return ['elements' => $this->elements, 'options' => $this->options];
    }

    public function end(): PdfBuilder
    {
        $renderer = new PdfBlockRenderer($this->pdf, $this->bgPainter);
        $renderer->render($this->elements, $this->options);
        return $this->pdf;
    }
}
