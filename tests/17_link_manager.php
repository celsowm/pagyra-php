<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

echo "Testing PdfLinkManager functionality...\n";

try {
    $pdf = new PdfBuilder();

    // Test 1: Add a simple font for testing
    $pdf->addTTFFont('TestFont', __DIR__ . '/../fonts/NotoSans-Regular.ttf');
    $pdf->setFont('TestFont', 12.0);

    // Test 2: Add text with link using addLink method
    $pdf->addParagraphText('This is a link: ');
    $pdf->addLink('Click here to visit Google', 'https://www.google.com');
    $pdf->addParagraphText(' and this is normal text after the link.');

    // Test 3: Add text with link at absolute position
    $pdf->addLinkTextAbs(100, 700, 'Absolute positioned link', 'https://www.example.com', [
        'color' => '#FF0000',
        'style' => 'U'
    ]);

    // Test 4: Add another paragraph with multiple links
    $pdf->addSpacer(20);
    $pdf->addParagraphText('Multiple links in one paragraph: ');
    $pdf->addLink('GitHub', 'https://github.com');
    $pdf->addParagraphText(', ');
    $pdf->addLink('Stack Overflow', 'https://stackoverflow.com');
    $pdf->addParagraphText(', and ');
    $pdf->addLink('PHP.net', 'https://php.net');

    // Test 5: Add a link with custom options
    $pdf->addSpacer(20);
    $pdf->addLink('Custom styled link', 'https://example.com', [
        'color' => '#008000',
        'style' => 'U'
    ]);

    // Test 6: Save the PDF
    $outputPath = __DIR__ . '/17_link_manager.pdf';
    $pdf->save($outputPath);

    echo "✓ PDF with links created successfully: {$outputPath}\n";
    echo "✓ Tests completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Error during testing: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
