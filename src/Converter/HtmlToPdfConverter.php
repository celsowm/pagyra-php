<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Css\CssParser;
use Celsowm\PagyraPhp\Html\HtmlParser;
use Celsowm\PagyraPhp\Html\Style\CssCascade;
use Celsowm\PagyraPhp\Html\Style\Layout\FlowComposer;

final class HtmlToPdfConverter
{
    private HtmlParser $htmlParser;
    private CssParser $cssParser;
    private CssCascade $cssCascade;
    private FlowComposer $flowComposer;
    private StylesheetResolver $stylesheetResolver;
    private FlowRenderer $flowRenderer;

    public function __construct(
        ?HtmlParser $htmlParser = null,
        ?CssParser $cssParser = null,
        ?CssCascade $cssCascade = null,
        ?FlowComposer $flowComposer = null,
        ?StylesheetResolver $stylesheetResolver = null,
        ?FlowRenderer $flowRenderer = null
    ) {
        $this->htmlParser = $htmlParser ?? new HtmlParser();
        $this->cssParser = $cssParser ?? new CssParser();
        $this->cssCascade = $cssCascade ?? new CssCascade();
        $this->flowComposer = $flowComposer ?? new FlowComposer();
        $this->stylesheetResolver = $stylesheetResolver ?? new StylesheetResolver();
        $this->flowRenderer = $flowRenderer ?? new FlowRenderer();
    }

    public function convert(string $html, PdfBuilder $pdf, ?string $css = null): void
    {
        $document = $this->htmlParser->parse($html);
        $stylesheet = $this->stylesheetResolver->resolve($document, $css);
        $cssOm = $this->cssParser->parse($stylesheet);
        $computedStyles = $this->cssCascade->compute($document, $cssOm);
        $flows = $this->flowComposer->compose($document, $computedStyles);

        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }

            $this->flowRenderer->render($flow, $pdf, $document, $computedStyles);
        }
    }
}
