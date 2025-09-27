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
        $imageResources = $this->collectImageResources($document, $pdf);
        $stylesheet = $this->stylesheetResolver->resolve($document, $css);
        $cssOm = $this->cssParser->parse($stylesheet);
        $computedStyles = $this->cssCascade->compute($document, $cssOm);
        $flows = $this->flowComposer->compose($document, $computedStyles, $imageResources);

        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }

            $this->flowRenderer->render($flow, $pdf, $document, $computedStyles);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $imageResources
     */
    private function collectImageResources(\Celsowm\PagyraPhp\Html\HtmlDocument $document, PdfBuilder $pdf): array
    {
        $resources = [];

        $document->eachElement(function (array $node) use (&$resources, $pdf): void {
            $tag = strtolower((string)($node['tag'] ?? ''));
            if ($tag !== 'img') {
                return;
            }

            $nodeId = (string)($node['nodeId'] ?? '');
            if ($nodeId === '') {
                return;
            }

            $src = (string)($node['attributes']['src'] ?? '');
            $src = trim($src);
            if ($src === '') {
                return;
            }

            $resource = $this->createImageResource($src, $node, $pdf);
            if ($resource !== null) {
                $resources[$nodeId] = $resource;
            }
        });

        return $resources;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function createImageResource(string $src, array $node, PdfBuilder $pdf): ?array
    {
        $nodeId = (string)($node['nodeId'] ?? '');
        if ($nodeId === '') {
            return null;
        }

        if (str_starts_with($src, 'data:')) {
            $parsed = $this->parseDataUri($src);
            if ($parsed === null) {
                return null;
            }

            if ($parsed['mime'] === 'image/svg+xml') {
                $summary = $this->summarizeSvgImage($parsed['data']);
                if ($summary === null) {
                    return null;
                }

                return [
                    'type' => 'svg',
                    'alias' => null,
                    'svg' => $parsed['data'],
                    'width' => $summary['width'],
                    'height' => $summary['height'],
                    'background' => $summary['background'],
                    'text' => $summary['text'],
                ];
            }

            $hint = $this->mapMimeToImageHint($parsed['mime']);
            if ($hint === null) {
                return null;
            }

            $alias = 'img_' . $nodeId;
            $pdf->addImageData($alias, $parsed['data'], $hint);
            $meta = $pdf->getImageManager()->getImage($alias);

            return [
                'type' => 'bitmap',
                'alias' => $alias,
                'width' => $meta['w'] ?? null,
                'height' => $meta['h'] ?? null,
            ];
        }

        $cleanSrc = $this->stripQueryAndFragment($src);
        $path = $this->resolveImagePath($cleanSrc);
        if ($path === null) {
            return null;
        }

        $alias = 'img_' . $nodeId;
        $pdf->addImage($alias, $path);
        $meta = $pdf->getImageManager()->getImage($alias);

        return [
            'type' => 'bitmap',
            'alias' => $alias,
            'width' => $meta['w'] ?? null,
            'height' => $meta['h'] ?? null,
        ];
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function parseDataUri(string $uri): ?array
    {
        if (preg_match('/^data:(?P<mime>[^;,]+)(?:;charset=[^;,]*)?;base64,(?P<data>.+)$/i', $uri, $matches) !== 1) {
            return null;
        }

        $binary = base64_decode(preg_replace('/\s+/', '', (string)$matches['data']), true);
        if ($binary === false) {
            return null;
        }

        return [
            'mime' => strtolower((string)$matches['mime']),
            'data' => $binary,
        ];
    }

    private function mapMimeToImageHint(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            default => null,
        };
    }

    private function resolveImagePath(string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $src) === 1 || str_starts_with($src, '/') || str_starts_with($src, '\\\\')) {
            return is_file($src) ? $src : null;
        }

        $candidates = [];
        $cwd = getcwd();
        if ($cwd !== false) {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . $src;
        }
        $projectRoot = dirname(__DIR__, 2);
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . $src;

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @return array{width: float|null, height: float|null, background: ?string, text: array<string, mixed>}|null
     */
    private function summarizeSvgImage(string $svg): ?array
    {
        $xml = @simplexml_load_string($svg);
        if (!($xml instanceof \SimpleXMLElement)) {
            return null;
        }

        $width = $this->parseSvgLength((string)($xml['width'] ?? ''));
        $height = $this->parseSvgLength((string)($xml['height'] ?? ''));

        if (($width === null || $height === null) && isset($xml['viewBox'])) {
            $parts = preg_split('/[\s,]+/', trim((string)$xml['viewBox'])) ?: [];
            if (count($parts) === 4) {
                $vbWidth = (float)$parts[2];
                $vbHeight = (float)$parts[3];
                if ($width === null) {
                    $width = $vbWidth * 0.75;
                }
                if ($height === null) {
                    $height = $vbHeight * 0.75;
                }
            }
        }

        $background = null;
        foreach ($xml->children() as $child) {
            if ($child->getName() === 'rect') {
                $fill = (string)($child['fill'] ?? '');
                if ($fill !== '') {
                    $background = $fill;
                }
                break;
            }
        }

        $textSpec = [
            'content' => '',
            'color' => '#000000',
            'fontSize' => 12.0,
            'style' => '',
            'align' => 'center',
        ];

        foreach ($xml->children() as $child) {
            if ($child->getName() === 'text') {
                $textSpec['content'] = trim((string)$child);
                $fill = (string)($child['fill'] ?? '');
                if ($fill !== '') {
                    $textSpec['color'] = $fill;
                }
                $fontSizeAttr = (string)($child['font-size'] ?? '');
                $fontSizeParsed = $this->parseSvgLength($fontSizeAttr);
                if ($fontSizeParsed !== null && $fontSizeParsed > 0) {
                    $textSpec['fontSize'] = $fontSizeParsed;
                }
                $fontWeight = strtolower((string)($child['font-weight'] ?? ''));
                if ($fontWeight === 'bold' || $fontWeight === 'bolder' || (is_numeric($fontWeight) && (int)$fontWeight >= 600)) {
                    $textSpec['style'] = 'bold';
                }
                $anchor = strtolower((string)($child['text-anchor'] ?? ''));
                if ($anchor === 'start') {
                    $textSpec['align'] = 'left';
                } elseif ($anchor === 'end') {
                    $textSpec['align'] = 'right';
                } elseif ($anchor === 'middle') {
                    $textSpec['align'] = 'center';
                }
                break;
            }
        }

        if ($height === null) {
            $height = $textSpec['fontSize'] * 2.0;
        }
        if ($width === null) {
            $width = $textSpec['fontSize'] * 4.0;
        }

        return [
            'width' => $width,
            'height' => $height,
            'background' => $background,
            'text' => $textSpec,
        ];
    }

    private function parseSvgLength(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([0-9]*\.?[0-9]+)([a-z%]*)$/i', $value, $matches) !== 1) {
            return null;
        }

        $number = (float)$matches[1];
        $unit = strtolower($matches[2] ?? '');

        return match ($unit) {
            '', 'px' => $number * 0.75,
            'pt' => $number,
            default => $number * 0.75,
        };
    }

    private function stripQueryAndFragment(string $src): string
    {
        $clean = explode('#', $src, 2)[0];
        return explode('?', $clean, 2)[0];
    }
}
