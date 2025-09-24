<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter;

use Celsowm\PagyraPhp\Converter\Flow\BlockFlowRenderer;
use Celsowm\PagyraPhp\Converter\Flow\LengthConverter;
use Celsowm\PagyraPhp\Converter\Flow\ListFlowRenderer;
use Celsowm\PagyraPhp\Converter\Flow\MarginCalculator;
use Celsowm\PagyraPhp\Converter\Flow\ParagraphBuilder;
use Celsowm\PagyraPhp\Converter\Flow\TableFlowRenderer;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class FlowRenderer
{
    private BlockFlowRenderer $blockRenderer;
    private ListFlowRenderer $listRenderer;
    private TableFlowRenderer $tableRenderer;

    public function __construct(
        ?BlockFlowRenderer $blockRenderer = null,
        ?ListFlowRenderer $listRenderer = null,
        ?TableFlowRenderer $tableRenderer = null,
        ?ParagraphBuilder $paragraphBuilder = null,
        ?MarginCalculator $marginCalculator = null,
        ?LengthConverter $lengthConverter = null
    ) {
        $lengthConverter ??= new LengthConverter();
        $paragraphBuilder ??= new ParagraphBuilder($lengthConverter);
        $marginCalculator ??= new MarginCalculator($lengthConverter);

        $this->blockRenderer = $blockRenderer ?? new BlockFlowRenderer($paragraphBuilder, $marginCalculator);
        $this->listRenderer = $listRenderer ?? new ListFlowRenderer($paragraphBuilder, $marginCalculator);
        $this->tableRenderer = $tableRenderer ?? new TableFlowRenderer($paragraphBuilder, $lengthConverter);
    }

    /**
     * @param array<string, mixed> $flow
     * @param array<string, ComputedStyle> $computedStyles
     */
    public function render(
        array $flow,
        PdfBuilder $pdf,
        HtmlDocument $document,
        array $computedStyles
    ): void {

        $type = strtolower((string)($flow['type'] ?? ''));
        if ($type === 'table') {
            $this->tableRenderer->render($flow, $pdf, $computedStyles);
            return;
        }
        if ($type === 'list') {
            $this->listRenderer->render($flow, $pdf, $computedStyles, $document);
            return;
        }
        if ($type === 'block') {
            $this->blockRenderer->render($flow, $pdf, $document, $computedStyles);
        }
    }
}
