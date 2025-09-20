<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Css\CssParser;
use Celsowm\PagyraPhp\Html\HtmlParser;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\CssCascade;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Html\Style\Layout\FlowComposer;

final class HtmlToPdfConverter
{
    private HtmlParser $htmlParser;
    private CssParser $cssParser;
    private CssCascade $cssCascade;
    private FlowComposer $flowComposer;

    public function __construct(
        ?HtmlParser $htmlParser = null,
        ?CssParser $cssParser = null,
        ?CssCascade $cssCascade = null,
        ?FlowComposer $flowComposer = null
    ) {
        $this->htmlParser = $htmlParser ?? new HtmlParser();
        $this->cssParser = $cssParser ?? new CssParser();
        $this->cssCascade = $cssCascade ?? new CssCascade();
        $this->flowComposer = $flowComposer ?? new FlowComposer();
    }

    public function convert(string $html, PdfBuilder $pdf, ?string $css = null): void
    {
        $document = $this->htmlParser->parse($html);
        $stylesheet = $css ?? $this->extractEmbeddedCss($document);
        $cssOm = $this->cssParser->parse($stylesheet);
        $computedStyles = $this->cssCascade->compute($document, $cssOm);
        $flows = $this->flowComposer->compose($document, $computedStyles);

        foreach ($flows as $flow) {
            if (($flow['type'] ?? '') !== 'block') {
                continue;
            }
            $text = (string)($flow['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $style = $flow['style'] ?? null;
            $options = ($style instanceof ComputedStyle)
                ? $this->buildParagraphOptions($style)
                : [];

            $pdf->addParagraphText($text, $options);
        }
    }

    private function buildParagraphOptions(ComputedStyle $style): array
    {
        $options = [];
        $map = $style->toArray();

        if (isset($map['text-align'])) {
            $align = strtolower($map['text-align']);
            if (in_array($align, ['left', 'right', 'center', 'justify'], true)) {
                $options['align'] = $align;
            }
        }

        if (isset($map['font-size'])) {
            $options['size'] = $this->parseLength($map['font-size'], 12.0);
        }

        if (isset($map['line-height'])) {
            $options['lineHeight'] = $this->parseLength($map['line-height'], 1.2 * ($options['size'] ?? 12.0));
        }

        if (isset($map['color'])) {
            $options['color'] = $map['color'];
        }

        if (isset($map['font-weight']) && strtolower($map['font-weight']) === 'bold') {
            $options['style'] = 'bold';
        }

        if (isset($map['letter-spacing'])) {
            $options['letterSpacing'] = $this->parseLength($map['letter-spacing'], 0.0);
        }

        if (isset($map['word-spacing'])) {
            $options['wordSpacing'] = $this->parseLength($map['word-spacing'], 0.0);
        }

        return $options;
    }

    private function parseLength(string $value, float $default): float
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }

        if (preg_match('/^([0-9]*\.?[0-9]+)(px|pt|em|rem)?$/i', $value, $m) === 1) {
            $number = (float)$m[1];
            $unit = strtolower($m[2] ?? 'pt');
            return match ($unit) {
                'px' => $number * 0.75,
                'em', 'rem' => $number * $default,
                default => $number,
            };
        }

        return $default;
    }

    private function extractEmbeddedCss(HtmlDocument $document): string
    {
        $css = [];
        $document->eachElement(function (array $node) use (&$css): void {
            if (strtolower((string)($node['tag'] ?? '')) !== 'style') {
                return;
            }
            $css[] = $this->collectText($node);
        });
        return trim(implode("\n", array_filter($css)));
    }

    private function collectText(array $node): string
    {
        $buffer = '';
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $buffer .= $child['text'] ?? '';
            } elseif ($type === 'element') {
                $buffer .= $this->collectText($child);
            }
        }
        return trim($buffer);
    }
}
