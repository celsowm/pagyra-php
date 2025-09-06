<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Block;
use Celsowm\PagyraPhp\Block\PdfBlockRenderer;
use Celsowm\PagyraPhp\Core\PdfBuilder;


final class PdfBlockBuilder
{
    private PdfBuilder $pdf;
    private array $elements = [];
    private array $options;

    public function __construct(PdfBuilder $pdf, array $options = [])
    {
        $this->pdf = $pdf;
        $this->options = $options;
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

    public function addBlock(callable $callback): self
    {
        $nested = new PdfBlockBuilder($this->pdf, []);
        $callback($nested);
        $this->elements[] = ['type' => 'block', 'builder' => $nested];
        return $this;
    }

    public function end(): PdfBuilder
    {
        $renderer = new PdfBlockRenderer($this->pdf);
        $renderer->render($this->elements, $this->options);
        return $this->pdf;
    }
}