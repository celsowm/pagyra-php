<?php

declare(strict_types=1);

namespace Pagyra;

use Pagyra\Core\PreparedRender;
use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Css\FontFaceRuleParser;
use Pagyra\Css\PageStyleProfileResolver;
use Pagyra\Css\StylesheetParser;
use Pagyra\Css\StylesheetSourceLoader;
use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\GlyphTextMetrics;
use Pagyra\Html\HtmlParser;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Image\ImageSourceIntrinsicSizeResolver;
use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Pagination\PageFlow;
use Pagyra\Pagination\PaginationEngine;
use Pagyra\Paint\BorderPaintCommand;
use Pagyra\Paint\BorderPatternExpander;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\ClipPaintCommand;
use Pagyra\Paint\DisplayListBuilder;
use Pagyra\Paint\GradientPaintCommand;
use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Paint\RoundedBorderPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Paint\OpacityGroupNormalizer;
use Pagyra\Paint\TrailingBlankPageTrimmer;
use Pagyra\Paint\VisibilityFilter;
use Pagyra\Pdf\PdfSerializer;
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
            mediaType: $options->media,
            viewportWidth: $viewportWidth,
            viewportHeight: $viewportHeight,
        );
        $styledRoot = (new StyleComputer())->computeTree(
            $document->root,
            $rules,
            $document->htmlElement,
            $document->bodyElement,
            zeroBodyMargin: $options->pagedBodyMargin === 'zero',
        );

        $registry = self::buildFontRegistry(
            $options->fontConfig,
            $options->resourceBaseDir,
            $cssText,
            $viewportWidth,
            $viewportHeight,
            $options->media,
        );
        $textMetrics = new GlyphTextMetrics($registry);
        $layoutRoot = (new BlockLayoutEngine($viewportWidth, $viewportHeight, $textMetrics))->layout($styledRoot);

        $pageFlow = PageFlow::fromPageProfile($pageStyle['height'], $pageStyle['margins']);
        $pagination = (new PaginationEngine())->paginate($layoutRoot, $pageFlow);
        $displayList = (new DisplayListBuilder($sourceBytes, $textMetrics))->build(
            $pagination,
            $pageStyle['width'],
            $pageStyle['height'],
            $pageStyle['margins'],
        );
        $displayList = (new BorderPatternExpander())->expand($displayList, $pagination);
        $displayList = (new OpacityGroupNormalizer())->normalize($displayList);
        $displayList = (new VisibilityFilter())->apply($displayList);
        $displayList = (new TrailingBlankPageTrimmer())->trim($displayList, $pagination->lastForcedBreakPage);

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
            margins: $pageStyle['margins']['default'],
            pagination: $pagination,
            displayList: $displayList,
            fontRegistry: $registry,
            pageMargins: $pageStyle['margins'],
        );
    }

    /**
     * @return array{0:array{width:float,height:float,margins:array{default:array{top:float,right:float,bottom:float,left:float},first:array{top:float,right:float,bottom:float,left:float},left:array{top:float,right:float,bottom:float,left:float},right:array{top:float,right:float,bottom:float,left:float}}},1:float,2:float}
     */
    private static function stabilizePageConfiguration(string $cssText, RenderHtmlOptions $options): array
    {
        $resolver = new PageStyleProfileResolver();
        $viewportWidth = $options->viewportWidth;
        $viewportHeight = $options->viewportHeight;
        $pageStyle = $resolver->resolve(
            $cssText,
            $options->pageWidth,
            $options->pageHeight,
            $options->margins,
            $options->media,
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
                $options->media,
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
        foreach (['default', 'first', 'left', 'right'] as $variant) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (abs($left['margins'][$variant][$side] - $right['margins'][$variant][$side]) > 0.01) return false;
            }
        }
        return true;
    }

    private static function resolvePrintViewport(RenderHtmlOptions $options, array $pageStyle): array
    {
        $contentWidths = [];
        $contentHeights = [];
        foreach (['default', 'first', 'left', 'right'] as $variant) {
            $margins = $pageStyle['margins'][$variant];
            $contentWidths[] = max(1.0, $pageStyle['width'] - $margins['left'] - $margins['right']);
            $contentHeights[] = max(1.0, $pageStyle['height'] - $margins['top'] - $margins['bottom']);
        }
        return [
            min($options->viewportWidth, min($contentWidths)),
            min($options->viewportHeight, min($contentHeights)),
        ];
    }

    private static function buildFontRegistry(
        array $config,
        ?string $resourceBaseDir = null,
        string $cssText = '',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
        string $mediaType = 'print',
    ): FontRegistry {
        $registry = new FontRegistry();
        $registry->registerLastResortFile('DejaVu Sans', dirname(__DIR__) . '/assets/fonts/dejavu/DejaVuSans.ttf');
        $defs = $config['fontFaceDefs'] ?? [];
        if (!is_array($defs)) $defs = [];

        foreach ((new FontFaceRuleParser())->parse($cssText, $mediaType, $viewportWidth, $viewportHeight) as $face) $defs[] = $face;
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
        if (!str_starts_with(strtolower(trim($src)), 'data:')) return null;
        $comma = strpos($src, ',');
        if ($comma === false) return null;
        $metadata = substr($src, 5, $comma - 5);
        if (preg_match('/(?:^|;)base64(?:;|$)/i', $metadata) !== 1) return null;
        $payload = preg_replace('/\s+/', '', substr($src, $comma + 1)) ?? '';
        $binary = base64_decode($payload, true);
        return $binary === false ? null : $binary;
    }

    private static function resolveFontPath(string $src, ?string $resourceBaseDir): ?string
    {
        $src = trim($src);
        if ($src === '' || preg_match('/^(?:https?:)?\/\//i', $src) === 1 || str_starts_with(strtolower($src), 'data:')) return null;
        if (str_starts_with(strtolower($src), 'file://')) {
            $path = rawurldecode(substr($src, 7));
            if (preg_match('#^/[a-zA-Z]:[\\\\/]#', $path) === 1) $path = substr($path, 1);
            return $path;
        }
        if (str_starts_with($src, '/') || str_starts_with($src, '\\\\') || preg_match('#^[a-zA-Z]:[\\\\/]#', $src) === 1) return rawurldecode($src);
        if ($resourceBaseDir === null) return null;
        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($src));
        return rtrim($resourceBaseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    public static function renderHtmlToPdf(array|RenderHtmlOptions $options): string
    {
        $options = is_array($options) ? RenderHtmlOptions::fromArray($options) : $options;
        $contentScale = $options->contentScale;
        $prepared = self::prepareHtmlRender($options->scaledForContentZoom($contentScale));
        if ($prepared->displayList === null) throw new \LogicException('Display list generation failed before PDF serialization.');
        [$prepared, $contentScale] = self::shrinkToFitWidth($options, $prepared, $contentScale);
        return (new PdfSerializer())->serialize($prepared->displayList, $prepared->fontRegistry, $contentScale);
    }

    /** Layouts tried at a smaller scale before giving up and keeping the first one. */
    private const SHRINK_TO_FIT_PASSES = 3;

    /** Ink this far past the right edge of the content box (layout px) still counts as fitting. */
    private const SHRINK_TO_FIT_TOLERANCE = 1.0;

    /**
     * wkhtmltopdf never draws past the right margin: when the content is wider than the page (a
     * table pasted from Word with `width: 1339px`, a URL with no break opportunity) its "smart
     * shrinking" lays the whole document out on a wider page and scales it down to fit. With
     * `minContentScale` set the render does the same: it measures how far the ink goes past the
     * content box, lays out again at the scale that brings it back, and keeps that layout only if
     * it fits. Content that grows with the page (an image with `max-width: 100%` pushed right by
     * `text-indent`) never fits that way, so the first layout stays and the text is not shrunk for
     * nothing. Without `minContentScale`, or when nothing overflows, the first layout is returned.
     *
     * @return array{0:PreparedRender,1:float}
     */
    private static function shrinkToFitWidth(RenderHtmlOptions $options, PreparedRender $prepared, float $contentScale): array
    {
        $minScale = $options->minContentScale;
        if ($minScale === null || $minScale >= $contentScale) return [$prepared, $contentScale];

        $candidate = $prepared;
        $scale = $contentScale;
        for ($pass = 0; $pass <= self::SHRINK_TO_FIT_PASSES; $pass++) {
            $ratio = self::horizontalOverflowRatio($candidate);
            if ($ratio <= 1.0) return [$candidate, $scale];
            if ($pass === self::SHRINK_TO_FIT_PASSES || $scale <= $minScale) break;

            $scale = max($minScale, $scale / $ratio);
            $candidate = self::prepareHtmlRender($options->scaledForContentZoom($scale));
            if ($candidate->displayList === null) break;
        }

        return [$prepared, $contentScale];
    }

    /**
     * How much wider than the content box the ink is: 1.0 when everything is drawn inside it,
     * otherwise the widest page's (ink right edge − left margin) / content width. Only the right
     * edge counts, since that is where an overflow is cut; ink inside an open clip counts up to
     * the clip, and a box without background has no ink of its own.
     */
    private static function horizontalOverflowRatio(PreparedRender $prepared): float
    {
        if ($prepared->displayList === null) return 1.0;
        $left = (float) $prepared->margins['left'];
        $right = (float) $prepared->margins['right'];
        $ratio = 1.0;

        foreach ($prepared->displayList->pages as $page) {
            $edge = $page->width - $right;
            $available = $edge - $left;
            if ($available <= 0.0) continue;

            $inkRight = $edge;
            $clips = [];
            foreach ($page->commands as $command) {
                if ($command instanceof ClipPaintCommand) {
                    if ($command->opens()) {
                        $clips[] = (float) $command->x + (float) $command->width;
                    } else {
                        array_pop($clips);
                    }
                    continue;
                }
                $commandRight = self::inkRight($command);
                if ($commandRight === null) continue;
                if ($clips !== []) $commandRight = min($commandRight, min($clips));
                $inkRight = max($inkRight, $commandRight);
            }

            if ($inkRight > $edge + self::SHRINK_TO_FIT_TOLERANCE) {
                $ratio = max($ratio, ($inkRight - $left) / $available);
            }
        }

        return $ratio;
    }

    private static function inkRight(object $command): ?float
    {
        return match (true) {
            $command instanceof TextPaintCommand => $command->x + $command->run->width,
            $command instanceof ImagePaintCommand => $command->clipRect !== null
                ? min($command->x + $command->width, $command->clipRect->right())
                : $command->x + $command->width,
            $command instanceof BoxPaintCommand => $command->backgroundColor !== null ? $command->x + $command->width : null,
            $command instanceof BorderPaintCommand,
            $command instanceof RoundedBorderPaintCommand,
            $command instanceof GradientPaintCommand => $command->x + $command->width,
            default => null,
        };
    }
}
