<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$pdf->addImage('duck', 'assets/images/duck.jpg');
$pdf->addImageBlock('duck', [
    'maxW'  => 200,
    'align' => 'center',
]);

$pdf->save('04_image_simple.pdf');
