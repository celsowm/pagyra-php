<?php

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;

$pdf = new PdfBuilder();

$pdf->addList([
    [
        'text' => 'Planejamento semanal',
        'children' => [
            [
                'text' => 'Definir metas principais',
                'children' => [
                    'Listar prioridades',
                    'Separar responsabilidades',
                ],
            ],
            [
                'text' => 'Organizar recursos',
                'children' => [
                    'Conferir ferramentas',
                    'Alinhar agenda da equipe',
                ],
            ],
        ],
    ],
    [
        'text' => 'Execucao',
        'children' => [
            'Monitorar progresso',
            'Registrar aprendizados',
        ],
    ],
    [
        'text' => 'Encerramento',
        'children' => [
            [
                'text' => 'Revisao geral',
                'children' => [
                    'Compartilhar resultados',
                    'Planejar proximos passos',
                ],
            ],
        ],
    ],
], [
    'typeByLevel' => ['upper-roman', 'decimal', 'bullet'],
    'bulletCharsByLevel' => ['-', '>', '*'],
    'levelIndent' => 22,
    'gap' => 8,
    'itemSpacing' => 4,
    'markerSizeScale' => 0.9,
]);

$pdf->save('07_list_nested.pdf');
