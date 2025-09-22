<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Graphics\Painter\PdfBackgroundPainter;
use Celsowm\PagyraPhp\Graphics\Gradient\PdfGradientFactory;
use Celsowm\PagyraPhp\Graphics\Shading\PdfShadingRegistry;
use Celsowm\PagyraPhp\Block\PdfBlockRenderer;

$pdf = new PdfBuilder();
$bgPainter = new PdfBackgroundPainter(
    $pdf,
    new PdfGradientFactory($pdf),
    new PdfShadingRegistry($pdf)
);

$renderer = new PdfBlockRenderer($pdf, $bgPainter);
$elements = [
    ['type' => 'paragraph', 'content' => 'Hello, Gradient!', 'options' => []],
];

$options = [
    'width'   => '100%',
    'margin'  => [24, 24, 24, 24],
    'padding' => [24, 24, 24, 24],
    'radius'  => [12, 12, 12, 12],
    'bggradient' => [
        'type'  => 'linear',
        'angle' => 45,
        'stops' => [
            ['offset' => 0.00, 'color' => '#4f8cff'],
            ['offset' => 1.00, 'color' => '#0f1115'],
        ],
    ],
];

$renderer->render($elements, $options);
$pdf->save('13_gradient_background.pdf');
