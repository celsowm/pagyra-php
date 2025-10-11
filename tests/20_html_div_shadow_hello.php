<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Converter\HtmlToPdfConverter;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hello World</title>
  <style>
    .container {
      width: 90%;
      max-width: 600px;
      margin: 20px auto;
      padding: 20px;
      background-color: #fff;
      border-radius: 15px; /* Bordas arredondadas */
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2); /* Sombra suave */
      font-family: Arial, sans-serif;
    }

    .hello-text {
      font-size: 24px;
      font-weight: bold;
      color: #333;
      text-align: center;
      padding: 20px;
    }
  </style>
</head>
<body>

  <div class="container">
    <span class="hello-text">Hello World</span>
  </div>

</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('20_html_div_shadow_hello.pdf');
