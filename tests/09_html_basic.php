<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Converter\HtmlToPdfConverter;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório Básico</title>
    <style>
        body {
            font-family: PagyraDefault, sans-serif;
            font-size: 14pt;
            line-height: 1.5;
            color: #222222;
        }
        h1 {
            font-size: 24pt;
            margin-bottom: 12pt;
            text-align: center;
        }
        p {
            margin: 0 0 10pt 0;
        }
        p.intro {
            font-size: 16pt;
        }
        a {
            color: #1a73e8;
            text-decoration: underline;
        }
        ul {
            margin: 8pt 0 12pt 30pt;
        }
        li {
            margin-bottom: 4pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16pt;
        }
        th {
            background-color: #f0f4ff;
            font-weight: bold;
            text-align: left;
        }
        th, td {
            padding: 6pt 8pt;
            border: 1px solid #d0d6e2;
        }
        .note {
            font-size: 12pt;
            color: #555555;
        }
    </style>
</head>
<body>
    <h1>Resumo Mensal</h1>
    <p class="intro">Este documento demonstra o fluxo HTML básico renderizado pelo Pagyra usando o novo conversor HtmlToPdfConverter.</p>
    <p>Você pode combinar <strong>ênfase</strong>, <em>itálico</em> e até mesmo referências externas como <a href="https://pagyra.dev">site oficial</a>.</p>

    <p>Principais destaques:</p>
    <ul>
        <li>Compatibilidade com estilos inline e CSS embutido</li>
        <li>Suporte a links clicáveis</li>
        <li>Renderização simplificada de tabelas</li>
    </ul>

    <table>
        <thead>
            <tr>
                <th>Indicador</th>
                <th>Valor</th>
                <th>Variação</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Receita</td>
                <td>R$ 128.450</td>
                <td style="color: #0a8a0a;">+8,2%</td>
            </tr>
            <tr>
                <td>Novos clientes</td>
                <td>276</td>
                <td style="color: #0a8a0a;">+5,5%</td>
            </tr>
            <tr>
                <td>Tickets</td>
                <td>412</td>
                <td style="color: #c8261b;">-2,1%</td>
            </tr>
        </tbody>
    </table>

    <p class="note">Observação: os dados acima são fictícios e servem apenas para fins de demonstração.</p>
</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('09_html_basic.pdf');