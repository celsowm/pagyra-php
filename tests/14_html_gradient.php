<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Converter\HtmlToPdfConverter;

$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <style>
    .gradient-box {
      background: linear-gradient(to right, #ff7e5f, #feb47b);
      padding: 20px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="gradient-box">
    <h1>Hello World</h1>
  </div>
</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('14_html_gradient.pdf');
