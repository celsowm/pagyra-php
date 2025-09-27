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
  <title>Relatório Detalhado</title>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Roboto', Arial, sans-serif;
      margin: 0;
      padding: 20px;
      color: #333;
      background-color: #fafafa;
    }

    header {
      text-align: center;
      margin-bottom: 30px;
      padding-bottom: 15px;
      border-bottom: 2px solid #4a90e2;
    }

    h1 {
      color: #2c3e50;
      margin: 0;
    }

    .gradient-box {
      background: linear-gradient(to right, #6a11cb, #2575fc);
      padding: 20px;
      text-align: center;
      color: white;
      border-radius: 8px;
      margin-bottom: 25px;
    }

    .section {
      background: white;
      padding: 20px;
      margin-bottom: 20px;
      border-radius: 6px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    h2 {
      color: #4a90e2;
      border-bottom: 1px solid #eee;
      padding-bottom: 8px;
    }

    ul, ol {
      padding-left: 20px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }

    th, td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
    }

    th {
      background-color: #f4f6f8;
      font-weight: bold;
    }

    tr:nth-child(even) {
      background-color: #f9f9f9;
    }

    .footer {
      margin-top: 40px;
      text-align: center;
      font-size: 0.9em;
      color: #777;
    }

    img.logo {
      width: 120px;
      height: auto;
      display: block;
      margin: 0 auto 15px;
    }
  </style>
</head>
<body>
  <header>
    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+CiAgPHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiM0YTkxZTIiLz4KICA8dGV4dCB4PSI1MCIgeT0iNTUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyMCIgZmlsbD0id2hpdGUiIHRleHQtYW5jaG9yPSJtaWRkbGUiPkxPR088L3RleHQ+Cjwvc3ZnPg==" alt="Logo" class="logo">
    <h1>Relatório Mensal de Desempenho</h1>
  </header>

  <div class="gradient-box">
    <h2>Visão Geral – Abril de 2025</h2>
  </div>

  <div class="section">
    <h2>Resumo Executivo</h2>
    <p>Este relatório apresenta os principais indicadores de desempenho do mês de abril de 2025, incluindo métricas de vendas, engajamento e satisfação do cliente.</p>
  </div>

  <div class="section">
    <h2>Destaques do Mês</h2>
    <ul>
      <li>Aumento de 22% nas vendas em relação ao mês anterior.</li>
      <li>Lançamento bem-sucedido do novo produto X.</li>
      <li>Redução de 15% no tempo médio de atendimento ao cliente.</li>
    </ul>
  </div>

  <div class="section">
    <h2>Dados de Vendas</h2>
    <table>
      <thead>
        <tr>
          <th>Produto</th>
          <th>Unidades Vendidas</th>
          <th>Receita (R$)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Produto A</td>
          <td>1.250</td>
          <td>62.500,00</td>
        </tr>
        <tr>
          <td>Produto B</td>
          <td>890</td>
          <td>44.500,00</td>
        </tr>
        <tr>
          <td>Produto C</td>
          <td>2.100</td>
          <td>105.000,00</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="section">
    <h2>Próximos Passos</h2>
    <ol>
      <li>Expandir campanha de marketing digital.</li>
      <li>Implementar novo sistema de CRM até maio.</li>
      <li>Realizar treinamento de equipe em experiência do cliente.</li>
    </ol>
  </div>

  <div class="footer">
    <p>Gerado automaticamente em <?php echo date('d/m/Y'); ?> – Confidencial</p>
  </div>
</body>
</html>
HTML;

// Substitui a tag PHP dentro da string heredoc por uma data fixa ou use sprintf se quiser dinamismo
$html = str_replace('<?php echo date(\'d/m/Y\'); ?>', date('d/m/Y'), $html);

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('12_html_complex.pdf');