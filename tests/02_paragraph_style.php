<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$pdf->addParagraph('Styled Paragraph!', [
    'align'   => 'center',
    'spacing' => 8,
    'border'  => ['width' => 1, 'color' => '#0066CC', 'style' => 'dashed'],
    'padding' => 12,
    'bgcolor' => '#F0F8FF',
]);

$pdf->save('02_paragraph_style.pdf');