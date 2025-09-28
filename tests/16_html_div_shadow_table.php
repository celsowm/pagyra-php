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
  <title>Tabela de Produtos</title>
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

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #ddd;
    }

    th {
      background-color: #f4f4f4;
      font-weight: bold;
    }

    tr:hover {
      background-color: #f9f9f9;
    }
  </style>
</head>
<body>

  <div class="container">
    <table>
      <thead>
        <tr>
          <th>Nome</th>
          <th>Preço</th>
          <th>Estoque</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Notebook Dell</td>
          <td>R$ 3.500,00</td>
          <td>12</td>
        </tr>
        <tr>
          <td>Mouse Logitech</td>
          <td>R$ 89,90</td>
          <td>45</td>
        </tr>
        <tr>
          <td>Teclado Mecânico</td>
          <td>R$ 250,00</td>
          <td>8</td>
        </tr>
      </tbody>
    </table>
  </div>

</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('16_html_div_shadow_table.pdf');