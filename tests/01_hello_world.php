<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();
$pdf->addParagraph('Hello, Pagyra!');
$pdf->save('01_hello_world.pdf');