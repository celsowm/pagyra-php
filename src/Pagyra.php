<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Css\FontFaceRuleParser;
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

        $registry = self::buildFontRegistry($options->fontConfig, $options->resourceBaseDir, $cssText);
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
    private static function buildFontRegistry(array $config, ?string $resourceBaseDir = null, string $cssText = ''): FontRegistry
    {
        $registry = new FontRegistry();
        $defs = $config['fontFaceDefs'] ?? [];
        if (!is_array($defs)) $defs = [];

        foreach ((new FontFaceRuleParser())->parse($cssText) as $face) {
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
            'PDF serialization is intentionally not implemented yet. The active pipeline currently stops after the first block-layout pass.'
        );
    }
}
