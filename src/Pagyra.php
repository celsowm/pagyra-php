<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Css\StylesheetParser;
use Pagyra\Html\HtmlParser;
use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Style\StyleComputer;
use Pagyra\Units\Units;

final class Pagyra
{
    private function __construct()
    {
    }

    public static function prepareHtmlRender(array|RenderHtmlOptions $options): PreparedRender
    {
        $options = is_array($options) ? RenderHtmlOptions::fromArray($options) : $options;

        $document = (new HtmlParser())->parseDocument($options->html);
        $cssText = $document->mergedEmbeddedCss($options->css);
        $rules = (new StylesheetParser())->parse($cssText);
        $styledRoot = (new StyleComputer())->computeTree($document->root, $rules);
        $layoutRoot = (new BlockLayoutEngine($options->viewportWidth, $options->viewportHeight))->layout($styledRoot);

        return new PreparedRender(
            domRoot: $document->root,
            styledRoot: $styledRoot,
            layoutRoot: $layoutRoot,
            cssText: $cssText,
            stylesheetHrefs: $document->stylesheetHrefs,
            pageSize: [
                'widthPt' => Units::pxToPt($options->pageWidth),
                'heightPt' => Units::pxToPt($options->pageHeight),
            ],
            margins: $options->margins,
        );
    }

    public static function renderHtmlToPdf(array|RenderHtmlOptions $options): string
    {
        throw new \LogicException(
            'PDF serialization is intentionally not implemented yet. The active pipeline currently stops after the first block-layout pass.'
        );
    }
}
