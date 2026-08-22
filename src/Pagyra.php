<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Css\StylesheetParser;
use Pagyra\Css\StylesheetSourceLoader;
use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\GlyphTextMetrics;
use Pagyra\Html\HtmlParser;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Image\ImageSourceIntrinsicSizeResolver;
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

        $sourceBytes = new ImageSourceBytesResolver($options->resourceBaseDir);
        $imageSizes = new ImageSourceIntrinsicSizeResolver($sourceBytes);
        $document = (new HtmlParser($imageSizes))->parseDocument($options->html);

        $cssText = $document->mergedEmbeddedCss($options->css);
        $stylesheetLoader = new StylesheetSourceLoader($options->resourceBaseDir);
        foreach ($document->stylesheetHrefs as $href) {
            $linkedCss = $stylesheetLoader->load($href);
            if (trim($linkedCss) !== '') {
                $cssText .= ($cssText === '' ? '' : "\n") . $linkedCss;
            }
        }

        $rules = (new StylesheetParser())->parse($cssText);
        $styledRoot = (new StyleComputer())->computeTree($document->root, $rules);

        $registry = self::buildFontRegistry($options->fontConfig);
        $textMetrics = new GlyphTextMetrics($registry);
        $layoutRoot = (new BlockLayoutEngine($options->viewportWidth, $options->viewportHeight, $textMetrics))->layout($styledRoot);

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

    /** @param array<string,mixed> $config */
    private static function buildFontRegistry(array $config): FontRegistry
    {
        $registry = new FontRegistry();
        $defs = $config['fontFaceDefs'] ?? [];
        if (!is_array($defs)) return $registry;

        foreach ($defs as $def) {
            if (!is_array($def)) continue;
            $family = $def['family'] ?? $def['name'] ?? null;
            $src = $def['src'] ?? null;
            if (!is_string($family) || $family === '' || !is_string($src) || $src === '') continue;
            if (str_starts_with($src, 'file://')) $src = substr($src, 7);
            if (!is_file($src)) continue;
            $weight = is_numeric($def['weight'] ?? null) ? (int) $def['weight'] : 400;
            $style = is_string($def['style'] ?? null) ? $def['style'] : 'normal';
            $registry->registerFile($family, $src, $weight, $style);
        }
        return $registry;
    }

    public static function renderHtmlToPdf(array|RenderHtmlOptions $options): string
    {
        throw new \LogicException(
            'PDF serialization is intentionally not implemented yet. The active pipeline currently stops after the first block-layout pass.'
        );
    }
}
