<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$data = [
    ['Name', 'Position', 'City'],
    ['Alice', 'Dev', 'Rio de Janeiro'],
    ['Bob', 'Ops', 'São Paulo'],
    ['Carol', 'Design', 'Recife'],
];

$pdf->addTable($data, [
    'border'      => 0.5,
    'cellPadding' => 6,
]);

$pdf->save('03_table_simple.pdf');
