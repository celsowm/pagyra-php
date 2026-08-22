<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Css\FontFaceRuleParser;
use Pagyra\Css\PageStyleResolver;
use Pagyra\Css\StylesheetParser;
use Pagyra\Css\StylesheetSourceLoader;
use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\GlyphTextMetrics;
use Pagyra\Html\HtmlParser;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Image\ImageSourceIntrinsicSizeResolver;
use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Pagination\PaginationEngine;
use Pagyra\Paint\DisplayListBuilder;
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

        [$pageStyle, $viewportWidth, $viewportHeight] = self::stabilizePageConfiguration($cssText, $options);

        $rules = (new StylesheetParser())->parse(
            $cssText,
            mediaType: 'print',
            viewportWidth: $viewportWidth,
            viewportHeight: $viewportHeight,
        );
        $styledRoot = (new StyleComputer())->computeTree($document->root, $rules);

        $registry = self::buildFontRegistry(
            $options->fontConfig,
            $options->resourceBaseDir,
            $cssText,
            $viewportWidth,
            $viewportHeight,
        );
        $textMetrics = new GlyphTextMetrics($registry);
        $layoutRoot = (new BlockLayoutEngine($viewportWidth, $viewportHeight, $textMetrics))->layout($styledRoot);
        $pageContentHeight = max(
            1.0,
            $pageStyle['height'] - $pageStyle['margins']['top'] - $pageStyle['margins']['bottom'],
        );
        $pagination = (new PaginationEngine())->paginate($layoutRoot, $pageContentHeight);
        $displayList = (new DisplayListBuilder())->build(
            $pagination,
            $pageStyle['width'],
            $pageStyle['height'],
            $pageStyle['margins'],
        );

        return new PreparedRender(
            domRoot: $document->root,
            styledRoot: $styledRoot,
            layoutRoot: $layoutRoot,
            cssText: $cssText,
            stylesheetHrefs: $document->stylesheetHrefs,
            pageSize: [
                'widthPt' => Units::pxToPt($pageStyle['width']),
                'heightPt' => Units::pxToPt($pageStyle['height']),
            ],
            margins: $pageStyle['margins'],
            pagination: $pagination,
            displayList: $displayList,
        );
    }

    /**
     * @return array{0:array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}},1:float,2:float}
     */
    private static function stabilizePageConfiguration(string $cssText, RenderHtmlOptions $options): array
    {
        $resolver = new PageStyleResolver();
        $viewportWidth = $options->viewportWidth;
        $viewportHeight = $options->viewportHeight;
        $pageStyle = $resolver->resolve(
            $cssText,
            $options->pageWidth,
            $options->pageHeight,
            $options->margins,
            'print',
            $viewportWidth,
            $viewportHeight,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$nextViewportWidth, $nextViewportHeight] = self::resolvePrintViewport($options, $pageStyle);
            $nextPageStyle = $resolver->resolve(
                $cssText,
                $options->pageWidth,
                $options->pageHeight,
                $options->margins,
                'print',
                $nextViewportWidth,
                $nextViewportHeight,
            );

            if (self::samePageConfiguration(
                $pageStyle,
                $viewportWidth,
                $viewportHeight,
                $nextPageStyle,
                $nextViewportWidth,
                $nextViewportHeight,
            )) {
                return [$nextPageStyle, $nextViewportWidth, $nextViewportHeight];
            }

            $pageStyle = $nextPageStyle;
            $viewportWidth = $nextViewportWidth;
            $viewportHeight = $nextViewportHeight;
        }

        [$viewportWidth, $viewportHeight] = self::resolvePrintViewport($options, $pageStyle);
        return [$pageStyle, $viewportWidth, $viewportHeight];
    }

    /**
     * @param array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}} $left
     * @param array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}} $right
     */
    private static function samePageConfiguration(
        array $left,
        float $leftViewportWidth,
        float $leftViewportHeight,
        array $right,
        float $rightViewportWidth,
        float $rightViewportHeight,
    ): bool {
        foreach ([
            [$left['width'], $right['width']],
            [$left['height'], $right['height']],
            [$leftViewportWidth, $rightViewportWidth],
            [$leftViewportHeight, $rightViewportHeight],
        ] as [$a, $b]) {
            if (abs($a - $b) > 0.01) return false;
        }

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (abs($left['margins'][$side] - $right['margins'][$side]) > 0.01) return false;
        }
        return true;
    }

    /**
     * @param array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}} $pageStyle
     * @return array{0:float,1:float}
     */
    private static function resolvePrintViewport(RenderHtmlOptions $options, array $pageStyle): array
    {
        $contentWidth = max(1.0, $pageStyle['width'] - $pageStyle['margins']['left'] - $pageStyle['margins']['right']);
        $contentHeight = max(1.0, $pageStyle['height'] - $pageStyle['margins']['top'] - $pageStyle['margins']['bottom']);

        return [
            min($options->viewportWidth, $contentWidth),
            min($options->viewportHeight, $contentHeight),
        ];
    }

    /** @param array<string,mixed> $config */
    private static function buildFontRegistry(
        array $config,
        ?string $resourceBaseDir = null,
        string $cssText = '',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): FontRegistry {
        $registry = new FontRegistry();
        $defs = $config['fontFaceDefs'] ?? [];
        if (!is_array($defs)) $defs = [];

        foreach ((new FontFaceRuleParser())->parse($cssText, 'print', $viewportWidth, $viewportHeight) as $face) {
            $defs[] = $face;
        }

        foreach ($defs as $def) {
            if (!is_array($def)) continue;
            $family = $def['family'] ?? $def['name'] ?? null;
            $src = $def['src'] ?? null;
            if (!is_string($family) || $family === '' || !is_string($src) || $src === '') continue;

            $weight = is_numeric($def['weight'] ?? null) ? (int) $def['weight'] : 400;
            $style = is_string($def['style'] ?? null) ? $def['style'] : 'normal';

            try {
                $embedded = self::decodeBase64FontDataUrl($src);
                if ($embedded !== null) {
                    $registry->registerData($family, $embedded, $weight, $style);
                    continue;
                }

                $path = self::resolveFontPath($src, $resourceBaseDir);
                if ($path === null || !is_file($path)) continue;
                $registry->registerFile($family, $path, $weight, $style);
            } catch (\InvalidArgumentException|\RuntimeException) {
                continue;
            }
        }
        return $registry;
    }

    private static function decodeBase64FontDataUrl(string $src): ?string
    {
        if (!str_starts_with(strtolower(trim($src)), 'data:')) {
            return null;
        }

        $comma = strpos($src, ',');
        if ($comma === false) {
            return null;
        }

        $metadata = substr($src, 5, $comma - 5);
        if (preg_match('/(?:^|;)base64(?:;|$)/i', $metadata) !== 1) {
            return null;
        }

        $payload = preg_replace('/\s+/', '', substr($src, $comma + 1)) ?? '';
        $binary = base64_decode($payload, true);
        return $binary === false ? null : $binary;
    }

    private static function resolveFontPath(string $src, ?string $resourceBaseDir): ?string
    {
        $src = trim($src);
        if ($src === '' || preg_match('/^(?:https?:)?\/\//i', $src) === 1 || str_starts_with(strtolower($src), 'data:')) {
            return null;
        }

        if (str_starts_with(strtolower($src), 'file://')) {
            $path = rawurldecode(substr($src, 7));
            if (preg_match('/^\/[a-zA-Z]:[\\\/]/', $path) === 1) {
                $path = substr($path, 1);
            }
            return $path;
        }

        if (str_starts_with($src, '/') || str_starts_with($src, '\\\\') || preg_match('/^[a-zA-Z]:[\\\/]/', $src) === 1) {
            return rawurldecode($src);
        }

        if ($resourceBaseDir === null) {
            return null;
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($src));
        return rtrim($resourceBaseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    public static function renderHtmlToPdf(array|RenderHtmlOptions $options): string
    {
        throw new \LogicException(
            'PDF serialization is intentionally not implemented yet. The active pipeline now reaches pagination and a first physical display-list paint stage, but PDF serialization is not implemented yet.'
        );
    }
}
