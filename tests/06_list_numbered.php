<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$pdf->addList([
    'Primeiro passo',
    'Segundo passo',
    'Terceiro passo',
]);

$pdf->save('06_list_numbered.pdf');
