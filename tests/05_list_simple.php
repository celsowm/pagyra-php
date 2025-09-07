<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$pdf->addList([
    'Item 1',
    'Item 2',
    'Item 3'
]);

$pdf->save('05_list_simple.pdf');
