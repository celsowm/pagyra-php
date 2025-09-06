<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Table;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Table\PdfTableRow;


final class PdfTableBuilder
{
    private PdfBuilder $pdf;
    private array $tableOptions;
    private array $rows = [];
    private ?array $header = null;
    private array $columnWidths = [];
    private array $columnAligns = [];

    public function __construct(PdfBuilder $pdf, array $tableOptions = [])
    {
        $this->pdf = $pdf;
        $this->tableOptions = $tableOptions;
    }

    public function addHeader(array $cells, array $options = []): self
    {
        $this->header = ['cells' => $cells, 'options' => $options];
        return $this;
    }

    public function addRow(array $cells, array $options = []): self
    {
        $this->rows[] = new PdfTableRow($cells, $options);
        return $this;
    }

    public function addRows(array $rows): self
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->addRow($row);
            } elseif ($row instanceof PdfTableRow) {
                $this->rows[] = $row;
            }
        }
        return $this;
    }

    public function setColumnWidths(array $widths): self
    {
        $this->columnWidths = $widths;
        return $this;
    }

    public function setColumnAligns(array $aligns): self
    {
        $this->columnAligns = $aligns;
        return $this;
    }

    public function setBorders(bool|array $borders): self
    {
        $this->tableOptions['borders'] = $borders;
        return $this;
    }

    public function setPadding(float|array $padding): self
    {
        $this->tableOptions['padding'] = $padding;
        return $this;
    }

    public function setSpacing(float $spacing): self
    {
        $this->tableOptions['spacing'] = $spacing;
        return $this;
    }

    public function end(): PdfBuilder
    {
        $data = [];
        if ($this->header !== null) {
            $data[] = $this->header['cells'];
            $this->tableOptions['headerRow'] = 0;
            if (!empty($this->header['options'])) {
                $this->tableOptions['headerOptions'] = $this->header['options'];
            }
        }
        foreach ($this->rows as $row) {
            $data[] = $row->cells;
        }

        if (!empty($this->columnWidths)) {
            $this->tableOptions['widths'] = $this->columnWidths;
        }
        if (!empty($this->columnAligns)) {
            $this->tableOptions['align'] = $this->columnAligns;
        }

        $this->pdf->addTableData($data, $this->tableOptions);
        return $this->pdf;
    }
}