<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Text;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Text\PdfRun;


final class PdfParagraphBuilder
{
    private PdfBuilder $pdf;
    private array $paragraphOptions;
    private array $runs = [];

    public function __construct(PdfBuilder $pdf, array $paragraphOptions = [])
    {
        $this->pdf = $pdf;
        $this->paragraphOptions = $paragraphOptions;
    }

    public function addRun(string $text, array $options = []): self
    {
        $this->runs[] = new PdfRun($text, $options);
        return $this;
    }

    public function addSpan(string $text, array $options = []): self
    {
        return $this->addRun($text, $options);
    }

    public function end(): PdfBuilder
    {
        $this->pdf->addParagraphRuns($this->runs, $this->paragraphOptions);
        return $this->pdf;
    }
}