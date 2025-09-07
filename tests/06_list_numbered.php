<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

// lista simples numerada (prefixando manualmente os itens)
$pdf->addList([
    'Primeiro passo',
    'Segundo passo',
    'Terceiro passo',
], [
    'spacing' => 6,
    'align'   => 'left',
    'padding' => 6,
    'border'  => ['style' => 'solid'],
]);

$pdf->save('06_list_numbered.pdf');
