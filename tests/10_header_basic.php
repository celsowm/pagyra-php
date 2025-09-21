<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();
$pdf->setMargins(40, 24, 40, 40);

$pdf->setHeader(function (PdfBlockBuilder $header) {
    $header
        ->addParagraph('Pagyra PDF Header', [
            'align' => 'center',
            'style' => 'B',
            'spacing' => 2,
            'lineHeight' => 16,
        ])
        ->addHorizontalLine([
            'color' => '#cccccc',
            'width' => 1.0,
            'margin' => [6, 0, 0, 0],
        ]);
}, [
    'y' => 12,
    'contentSpacing' => 10,
]);

for ($i = 1; $i <= 60; $i++) {
    $pdf->addParagraph("Content line {$i}");
}

$pdf->save('10_header_basic.pdf');
