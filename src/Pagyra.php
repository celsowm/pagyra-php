<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Html\HtmlParser;
use Pagyra\Units\Units;

final class Pagyra
{
    private function __construct()
    {
    }

    public static function prepareHtmlRender(array|RenderHtmlOptions $options): PreparedRender
    {
        $options = is_array($options) ? RenderHtmlOptions::fromArray($options) : $options;
        $domRoot = (new HtmlParser())->parse($options->html);

        return new PreparedRender(
            domRoot: $domRoot,
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
            'PDF serialization is intentionally not implemented in the bootstrap slice. Use prepareHtmlRender() until the layout and paint pipeline exists.'
        );
    }
}
