<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();
$pdf->setMargins(42, 32, 42, 42);

$pdf->setHeader(function (PdfBlockBuilder $header) {
    $header
        ->addParagraph('Pagyra Report', [
            'align' => 'center',
            'style' => 'B',
            'size' => 14,
            'spacing' => 2,
            'lineHeight' => 18,
        ])
        ->addParagraph('Quarterly Highlights', [
            'align' => 'center',
            'size' => 10,
            'color' => '#555555',
        ])
        ->addHorizontalLine([
            'color' => '#cccccc',
            'width' => 0.75,
            'margin' => [6, 0, 0, 0],
        ]);
}, [
    'y' => 10,
    'contentSpacing' => 14,
]);

$pdf->setFooter(function (PdfBlockBuilder $footer) {
    $footer
        ->addHorizontalLine([
            'color' => '#cccccc',
            'width' => 0.5,
            'margin' => [0, 0, 6, 0],
        ])
        ->addParagraph('CONFIDENTIAL • Pagyra Labs', [
            'align' => 'center',
            'size' => 9,
            'color' => '#555555',
        ]);
}, [
    'bottom' => 18,
    'contentSpacing' => 10,
]);

$sections = [
    'Executive Summary' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Etiam non diam sed odio volutpat faucibus. Donec venenatis.',
    'Key Metrics' => 'Integer accumsan enim id iaculis luctus. Sed consequat nibh et lectus congue dictum. Pellentesque habitant morbi tristique.',
    'Product Updates' => 'Suspendisse potenti. Morbi efficitur, sem sed porta ultricies, justo felis auctor nisl, eget fermentum arcu eros sed erat.',
    'Roadmap' => 'Nam id velit sed ipsum tincidunt interdum. Maecenas luctus pharetra ante, eti eu feugiat turpis varius at.',
    'Customer Stories' => 'Praesent sagittis neque a turpis aliquet, sed efficitur purus pretium. Cras dictum magna dui, sed condimentum metus porttitor nec.',
];

foreach ($sections as $title => $body) {
    $pdf->addParagraph($title, [
        'style' => 'B',
        'size' => 13,
        'spacing' => 4,
    ]);

    $pdf->addParagraph($body, [
        'size' => 11,
        'lineHeight' => 16,
    ]);

    for ($i = 0; $i < 6; $i++) {
        $pdf->addParagraph('• Insight ' . ($i + 1) . ' — ' . $body, [
            'size' => 10,
            'lineHeight' => 15,
            'indent' => 12,
        ]);
    }

    $pdf->addSpacer(14);
}

$pdf->save('11_header_footer.pdf');
