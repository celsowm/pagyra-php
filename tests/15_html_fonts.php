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
    <title>Exemplo de Fontes do Windows</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            background-color: #f4f4f4;
        }

        h1 {
            font-family: "Arial", "Helvetica", sans-serif;
            color: #333;
        }

        p.segoe {
            font-family: "Segoe UI", sans-serif;
        }

        p.tahoma {
            font-family: "Tahoma", Geneva, Verdana, sans-serif;
        }

        p.courier {
            font-family: "Courier New", Courier, monospace;
        }

        p.times {
            font-family: "Times New Roman", Times, serif;
        }
    </style>
</head>
<body>
    <h1>Exemplo de Fontes Comuns no Windows</h1>

    <p class="segoe">Este parágrafo usa a fonte Segoe UI (padrão do Windows).</p>
    <p class="tahoma">Este parágrafo usa a fonte Tahoma.</p>
    <p class="courier">Este parágrafo usa a fonte Courier New (fonte monoespaçada).</p>
    <p class="times">Este parágrafo usa a fonte Times New Roman.</p>
</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('15_html_fonts.pdf');