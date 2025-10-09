<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

echo "Testing PdfMeasurementManager...\n";

try {
    $pdf = new PdfBuilder();

    // Add a font for testing
    $pdf->addTTFFont('test-font', __DIR__ . '/../fonts/NotoSans-Regular.ttf');
    $pdf->setFont('test-font', 12);

    // Test measurement mode methods
    echo "Initial measurement mode: " . ($pdf->isMeasurementMode() ? 'true' : 'false') . "\n";

    $pdf->enterMeasurementMode();
    echo "After enterMeasurementMode: " . ($pdf->isMeasurementMode() ? 'true' : 'false') . "\n";

    // Test measuring block height
    $elements = [
        [
            'type' => 'paragraph',
            'content' => 'This is a test paragraph for measuring height.',
            'options' => []
        ],
        [
            'type' => 'spacer',
            'height' => 20.0
        ],
        [
            'type' => 'paragraph',
            'content' => 'This is another paragraph to measure.',
            'options' => []
        ]
    ];

    $options = [
        'width' => '100%',
        'padding' => 10,
        'margin' => 5
    ];

    $height = $pdf->measureBlockHeight($elements, $options);
    echo "Measured block height: {$height}\n";

    $pdf->exitMeasurementMode();
    echo "After exitMeasurementMode: " . ($pdf->isMeasurementMode() ? 'true' : 'false') . "\n";

    // Test normal PDF generation still works
    $pdf->addParagraph('This is a normal paragraph after measurement mode.');
    $pdf->addSpacer(10);
    $pdf->addParagraph('Everything should work normally.');

    // Save the PDF
    $pdf->save(__DIR__ . '/18_measurement_manager.pdf');

    echo "PDF saved successfully!\n";
    echo "Test completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
