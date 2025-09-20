<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$tableData = [
    ['Quarter', 'Revenue', 'Expenses', 'Delta', 'Highlights'],
    ['Q1 2025', '$120k', '$82k', '+$38k', 'Initial release after consolidating backlog and onboarding analytics pipeline.'],
    ['Q2 2025', '$138k', '$91k', '+$47k', 'Expanded outreach in two new channels; onboarding refinements cut activation time by 30 percent.'],
    ['Q3 2025', '$129k', '$97k', '+$32k', 'Supply constraints slowed shipments, but automated alerts protected the largest customer cohort.'],
    ['Q4 2025', '$155k', '$111k', '+$44k', 'Preparing compliance review while migrating billing to the new provider and hardening SLO dashboards.'],
];

$pdf->addTable($tableData, [
    'headerRow' => 0,
    'headerStyle' => 'B',
    'headerBgColor' => '#1f3c88',
    'headerColor' => '#ffffff',
    'alternateRows' => true,
    'altRowColor' => '#f5f8ff',
    'align' => ['left', 'right', 'right', 'right', 'left'],
    'widths' => [90, 70, 70, 70, 200],
    'padding' => 8,
    'borders' => ['width' => 0.6, 'color' => '#3c3c3c'],
    'spacing' => 3,
    'minRowHeight' => 28,
    'wrapText' => true,
    'fontSize' => 11,
    'lineHeight' => 16,
]);

$pdf->save('08_complex_table.pdf');
