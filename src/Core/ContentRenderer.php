<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Text\PdfListRenderer;
use Celsowm\PagyraPhp\Text\PdfRun;
use Celsowm\PagyraPhp\Table\PdfTableBuilder;

class ContentRenderer
{
    public function __construct(private PdfBuilder $builder)
    {
    }

    public function addParagraph(string|array $textOrOpts, array $opts = []): ?PdfParagraphBuilder
    {
        if (is_string($textOrOpts)) {
            $this->addParagraphText($textOrOpts, $opts);
            return null;
        }
        if (is_array($textOrOpts) && empty($opts)) {
            return new PdfParagraphBuilder($this->builder, $textOrOpts);
        }
        throw new \InvalidArgumentException(
            "addParagraph(): use (string \$text, array \$opts=[]) ou (array \$paragraphOptions) para builder."
        );
    }

    public function addParagraphText(string $text, array $opts = []): void
    {
        $runOptKeys = [
            'style',
            'color',
            'letterSpacing',
            'wordSpacing',
            'textShadow',
            'fontAlias',
            'size',
            'href',
            'sub',
            'sup',
            'script',
            'baselineShift',
            'sizeScale'
        ];
        $runOpts = [];
        foreach ($runOptKeys as $k) {
            if (array_key_exists($k, $opts)) $runOpts[$k] = $opts[$k];
        }
        $parOpts = $opts;
        foreach ($runOptKeys as $k) unset($parOpts[$k]);

        $this->addParagraphRuns([new PdfRun($text, $runOpts)], $parOpts);
    }

    public function addParagraphRuns(array $runs, array $opts = []): void
    {
        $this->builder->getStyleManager()->push();

        $__opsInsertAt = ($this->builder->getCurrentPage() !== null) ? strlen($this->builder->pageContents[$this->builder->getCurrentPage()]) : null;

        $this->builder->getStyleManager()->applyOptions($opts, $this->builder);

        $borderSpec = DocumentUtils::normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null, $this->builder);
        $padding = $borderSpec['padding'];
        $baseX = $this->builder->mLeft + $padding[3];
        $wrapWidth = $this->builder->getLayoutManager()->getContentAreaWidth() - $padding[1] - $padding[3];

        // Ajuste horizontal com containerPadding (quando vindo de um bloco com padding)
        if (isset($opts['containerPadding']) && is_array($opts['containerPadding'])) {
            $cp = array_values($opts['containerPadding']);
            $cp += [0,0,0,0];
            $baseX     += (float)$cp[3];
            $wrapWidth -= (float)$cp[1] + (float)$cp[3];
            if ($wrapWidth < 0) { $wrapWidth = 0.0; }
        }
$initialCursorY = $this->builder->getLayoutManager()->getCursorY();
        $__borderFragments = [];
        $__fragTop = $initialCursorY;
        $__fragPage = $this->builder->getCurrentPage();
        $this->builder->getLayoutManager()->advanceCursor($padding[0]);
        $__prevPage = $this->builder->getCurrentPage();
        $__prevBottomMargin = $this->builder->getPageBottomMargin();
        $this->builder->getLayoutManager()->checkPageBreak();
        if ($this->builder->getCurrentPage() !== $__prevPage) {
            $__borderFragments[] = ['page' => $__prevPage, 'x' => $this->builder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->builder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => 'first'];
            $__fragTop = $this->builder->getLayoutManager()->getCursorY();
            $__fragPage = $this->builder->getCurrentPage();
        }

        $align = $opts['align'] ?? 'left';
        $indent = (float)($opts['indent'] ?? 0.0);
        $hangIndent = (float)($opts['hangIndent'] ?? 0.0);
        $spacing = (float)($opts['spacing'] ?? 0.0);
        $lineH = $this->builder->getStyleManager()->getLineHeight();
        $bgColor = DocumentUtils::normalizeColor($opts['bgcolor'] ?? null, $this->builder);
        $markerSpec = $opts['listMarker'] ?? null;
        $needMarker = $markerSpec !== null;

        $blocks = $this->explodeRunsToBlocksByNewline($runs);
        $hasWritten = false;
        $lastBlockKey = empty($blocks) ? null : array_key_last($blocks);
        foreach ($blocks as $key => $blockTokens) {
            $isLastBlock = ($key === $lastBlockKey);
            $firstLine = true;
            $lineTokens = [];
            $avail = $wrapWidth - ($firstLine ? $indent : $hangIndent);
            foreach ($blockTokens as $tok) {
                $wTok = $tok['type'] === 'inline' ? $tok['opt']['width'] ?? 0 : $this->measureTokenWidth($tok);
                if (empty($lineTokens) && $tok['type'] === 'space') continue;

                if ($wTok <= $avail || empty($lineTokens)) {
                    $lineTokens[] = $tok;
                    $avail -= $wTok;
                    continue;
                }
                $tokensToFlush = $lineTokens;
                $nextToken = $tok;
                if (end($tokensToFlush)['type'] === 'space') array_pop($tokensToFlush);

                if (!empty($tokensToFlush)) {
                    $__beforePage = $this->builder->getCurrentPage();
                    $__prevBottomMargin = $this->builder->getPageBottomMargin();
                    $this->emitRunsLine($tokensToFlush, $align, $indent, $wrapWidth, $lineH, ($align === 'justify'), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                    if ($this->builder->getCurrentPage() !== $__beforePage) {
                        $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->builder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->builder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                        $__fragTop = $this->builder->getLayoutManager()->getCursorY();
                        $__fragPage = $this->builder->getCurrentPage();
                    }
                    $needMarker = false;
                    $hasWritten = true;
                }
                $firstLine = false;
                $lineTokens = ($nextToken['type'] === 'space') ? [] : [$nextToken];
                $avail = $wrapWidth - ($firstLine ? $indent : $hangIndent) - (empty($lineTokens) ? 0 : $this->measureTokensWidth($lineTokens));
            }
            if (!empty($lineTokens)) {
                $__beforePage = $this->builder->getCurrentPage();
                $__prevBottomMargin = $this->builder->getPageBottomMargin();
                $this->emitRunsLine($lineTokens, $align, $indent, $wrapWidth, $lineH, ($align === 'justify' && !$isLastBlock), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                if ($this->builder->getCurrentPage() !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->builder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->builder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->builder->getLayoutManager()->getCursorY();
                    $__fragPage = $this->builder->getCurrentPage();
                }
                $needMarker = false;
                $hasWritten = true;
            }
            if ($spacing > 0) {
                $__beforePage = $this->builder->getCurrentPage();
                $__prevBottomMargin = $this->builder->getPageBottomMargin();
                $this->builder->getLayoutManager()->advanceCursor($spacing);
                $this->builder->getLayoutManager()->checkPageBreak();
                if ($this->builder->getCurrentPage() !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->builder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->builder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->builder->getLayoutManager()->getCursorY();
                    $__fragPage = $this->builder->getCurrentPage();
                }
            }
        }
        if ($hasWritten) {
            $this->builder->getLayoutManager()->advanceCursor($padding[2]);
        } else {
            $this->builder->getLayoutManager()->setCursorY($initialCursorY);
        }

        $finalCursorY = $this->builder->getCursorY();
        if ($borderSpec['hasBorder'] && !empty($__borderFragments)) {
            $__borderFragments[] = ['page' => $this->builder->getCurrentPage(), 'x' => $this->builder->mLeft, 'y' => $finalCursorY, 'w' => $this->builder->getContentAreaWidth(), 'h' => $__fragTop - $finalCursorY, 'kind' => 'last'];
            $__origPage = $this->builder->getCurrentPage();
            foreach ($__borderFragments as $__frag) {
                if ($__frag['h'] <= 0.001) continue;
                $spec = $borderSpec;
                if ($__frag['kind'] === 'first') {
                    $spec['width'][2] = 0.0;
                    if (isset($spec['radius'])) {
                        $spec['radius'][2] = 0.0;
                        $spec['radius'][3] = 0.0;
                    }
                } elseif ($__frag['kind'] === 'middle') {
                    $spec['width'][0] = 0.0;
                    $spec['width'][2] = 0.0;
                    if (isset($spec['radius'])) {
                        $spec['radius'] = [0.0, 0.0, 0.0, 0.0];
                    }
                } elseif ($__frag['kind'] === 'last') {
                    $spec['width'][0] = 0.0;
                    if (isset($spec['radius'])) {
                        $spec['radius'][0] = 0.0;
                        $spec['radius'][1] = 0.0;
                    }
                }
                $this->builder->currentPage = $__frag['page'];
                $this->builder->getGraphicsRenderer()->drawParagraphBorders(['x' => $__frag['x'], 'y' => $__frag['y'], 'w' => $__frag['w'], 'h' => $__frag['h']], $spec);
            }
            $this->builder->currentPage = $__origPage;
        }
        if ($borderSpec['hasBorder'] && empty($__borderFragments)) {
            $paddedBox = ['x' => $this->builder->mLeft, 'y' => $finalCursorY, 'w' => $this->builder->getContentAreaWidth(), 'h' => $initialCursorY - $finalCursorY];
            $this->builder->getGraphicsRenderer()->drawParagraphBorders($paddedBox, $borderSpec);
        }
        $bgImgOpt = $opts['backgroundImage'] ?? ($opts['bgimage'] ?? null);
        if ($bgImgOpt !== null) {
            $bg = is_string($bgImgOpt) ? ['alias' => $bgImgOpt] : (array)$bgImgOpt;
            if (empty($bg['alias'])) throw new \InvalidArgumentException("backgroundImage: defina 'alias'.");
            $boxX = $this->builder->mLeft;
            $boxY = $finalCursorY;
            $boxW = $this->builder->getContentAreaWidth();
            $boxH = $initialCursorY - $finalCursorY;
            if ($__opsInsertAt !== null && $boxW > 0 && $boxH > 0) {
                $this->builder->getGraphicsRenderer()->drawBackgroundImageInRect($bg['alias'], $boxX, $boxY, $boxW, $boxH, $bg, $__opsInsertAt);
            }
        }

        $this->builder->getStyleManager()->pop();
    }

    private function explodeRunsToBlocksByNewline(array $runs): array
    {
        $blocks = [[]];
        foreach ($runs as $run) {
            if (!$run instanceof PdfRun) {
                $run = new PdfRun($run['text'] ?? '', $run['options'] ?? []);
            }

            if ($run->isInline && $run->inlineRenderer !== null) {
                $blocks[array_key_last($blocks)][] = [
                    'type' => 'inline',
                    'renderer' => $run->inlineRenderer,
                    'opt' => $run->options
                ];
                continue;
            }

            if ($run->text === '') continue;

            $parts = preg_split('/\R/u', $run->text);
            if ($parts === false) {
                $parts = [$run->text];
            }
            $lastPartIdx = count($parts) - 1;
            foreach ($parts as $j => $part) {
                $pieces = preg_split('/(\s+)/u', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
                if ($pieces === false) {
                    $pieces = [$part];
                }
                foreach ($pieces as $p) {
                    if ($p === '') continue;
                    $isSpace = preg_match('/^\s+$/u', $p);
                    $blocks[array_key_last($blocks)][] = ['type' => $isSpace ? 'space' : 'word', 'text' => $p, 'opt' => $run->options];
                }
                if ($j < $lastPartIdx) $blocks[] = [];
            }
        }
        return array_values(array_filter($blocks, fn($b) => !empty($b)));
    }

    private function measureTokenWidth(array $tok): float
    {
        if ($tok['type'] === 'inline') {
            return $tok['opt']['width'] ?? 0;
        }
        $this->builder->getStyleManager()->push();
        $this->builder->getStyleManager()->applyOptions($tok['opt'], $this->builder);

        $baseSz = $this->builder->getStyleManager()->getCurrentFontSize();
        $opt = $tok['opt'] ?? [];
        $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
        $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
        $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

        if (abs($scale - 1.0) > 1e-6) {
            $this->builder->getStyleManager()->setFont($this->builder->getStyleManager()->getCurrentFontAlias(), $baseSz * $scale);
        }

        $width = $this->builder->getTextRenderer()->measureTextStyled($tok['text'], $this->builder->getStyleManager());

        $this->builder->getStyleManager()->pop();
        return $width;
    }

    private function measureTokensWidth(array $tokens): float
    {
        $sum = 0.0;
        foreach ($tokens as $tok) {
            $sum += $this->measureTokenWidth($tok);
        }
        return $sum;
    }

    private function computeLineMetrics(float $lineHeight): array
    {
        $size = max(0.001, $this->builder->getStyleManager()->getCurrentFontSize());
        $alias = $this->builder->getStyleManager()->getCurrentFontAlias();
        $style = $this->builder->getStyleManager()->getStyle();
        $fonts = $this->builder->getFontManager()->getFonts();
        $resolvedAlias = null;
        if ($alias !== null) {
            $resolvedAlias = $this->builder->getFontManager()->resolveAliasByStyle($alias, $style);
        }

        $fontData = null;
        if ($resolvedAlias !== null && isset($fonts[$resolvedAlias])) {
            $fontData = $fonts[$resolvedAlias];
        } elseif ($alias !== null && isset($fonts[$alias])) {
            $fontData = $fonts[$alias];
        }

        if ($fontData !== null) {
            $units = max(1.0, (float)$fontData['unitsPerEm']);
            $ascentPx = ((float)$fontData['ascent'] / $units) * $size;
            $descentPx = (abs((float)$fontData['descent']) / $units) * $size;
        } else {
            $ascentPx = $size * 0.8;
            $descentPx = max($size - $ascentPx, $size * 0.2);
        }

        $glyphHeight = $ascentPx + $descentPx;
        $leading = $lineHeight - $glyphHeight;

        return [
            'baselineOffset' => ($leading / 2.0) + $ascentPx,
            'ascent' => $ascentPx,
            'descent' => $descentPx,
            'leading' => $leading,
            'glyphHeight' => $glyphHeight,
        ];
    }

    private function emitRunsLine(
        array $tokens,
        string $align,
        float $indent,
        float $wrapWidth,
        float $lineH,
        bool $justify,
        ?array $bgColor,
        float $baseX,
        bool $isFirst,
        float $hangIndent = 0.0,
        ?array $markerSpec = null
    ): void {
        $this->builder->getLayoutManager()->checkPageBreak($lineH);
        $renderTokens = $tokens;
        if ($justify && count($renderTokens) > 0 && end($renderTokens)['type'] === 'space') {
            array_pop($renderTokens);
        }
        if (empty($renderTokens)) {
            $this->builder->getLayoutManager()->advanceCursor($lineH);
            $this->builder->getLayoutManager()->checkPageBreak();
            return;
        }
        $actualIndent = $isFirst ? $indent : $hangIndent;
        $lineWidth = $this->measureTokensWidth($renderTokens);
        $targetWidth = $wrapWidth - $actualIndent;
        $x = match ($align) {
            'center' => $baseX + $actualIndent + ($targetWidth - $lineWidth) / 2.0,
            'right' => $baseX + $actualIndent + ($targetWidth - $lineWidth),
            default => $baseX + $actualIndent,
        };
        $lineTop = $this->builder->getLayoutManager()->getCursorY();

        $lineMetrics = $this->computeLineMetrics($lineH);
        $baselineOffset = $lineMetrics['baselineOffset'];
        $baselineY = $lineTop - $baselineOffset;

        if ($bgColor !== null) {
            $maxSz = $this->builder->getStyleManager()->getCurrentFontSize();
            $this->builder->getGraphicsRenderer()->drawBackgroundRect($baseX, $baselineY - ($maxSz * 0.25), $wrapWidth, $lineH, $bgColor);
        }

        $spaces = array_values(array_filter($renderTokens, fn($t) => $t['type'] === 'space'));
        $extraPerGap = 0.0;
        if ($justify && count($spaces) > 0) {
            $extra = $targetWidth - $lineWidth;
            if ($extra > 0.001) {
                $extraPerGap = $extra / count($spaces);
            }
        }

        if ($markerSpec !== null && $isFirst) {
            $this->builder->getStyleManager()->push();
            $this->builder->getStyleManager()->applyOptions([
                'fontAlias' => $markerSpec['fontAlias'],
                'size' => (float)$markerSpec['size'],
                'style' => (string)$markerSpec['style'],
                'color' => $markerSpec['color'],
                'letterSpacing' => 0.0,
                'wordSpacing' => 0.0,
            ], $this->builder);

            $mText = (string)$markerSpec['text'];
            $mWidth = (float)$markerSpec['width'];
            $mAlign = strtolower($markerSpec['align'] ?? 'right');
            $mGap = (float)$markerSpec['gap'];
            $measured = $this->builder->getTextRenderer()->measureTextStyled($mText, $this->builder->getStyleManager());
            $boxRight = $baseX + $actualIndent;
            $boxLeft = $boxRight - max($mWidth, $measured + $mGap);
            $mx = ($mAlign === 'right') ? $boxRight - $measured - $mGap : $boxLeft;
            $this->builder->getTextRenderer()->writeTextLine($mx, $baselineY, $mText, $this->builder->getStyleManager(), null);
            $this->builder->getStyleManager()->pop();
        }

        foreach ($renderTokens as $tok) {
            if ($tok['type'] === 'inline') {
                $tok['renderer']($x, $baselineY);
                $x += $this->measureTokenWidth($tok);
                continue;
            }
            $this->builder->getStyleManager()->push();
            $opt = $tok['opt'] ?? [];
            $this->builder->getStyleManager()->applyOptions($opt, $this->builder);

            $shadow = DocumentUtils::normalizeShadowSpec($opt['textShadow'] ?? null, $this->builder);
            $runBG = DocumentUtils::normalizeColor($opt['bgcolor'] ?? null, $this->builder);
            $href = $opt['href'] ?? null;
            $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
            $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
            $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

            if (abs($scale - 1.0) > 1e-6) {
                $this->builder->getStyleManager()->setFont(
                    $this->builder->getStyleManager()->getCurrentFontAlias(),
                    $this->builder->getStyleManager()->getCurrentFontSize() * $scale
                );
            }

            $dy = isset($opt['baselineShift'])
                ? (float)$opt['baselineShift']
                : ($isSup ? ($lineH * 0.35) : ($isSub ? -($lineH * 0.15) : 0.0));

            $tokWidth = $this->builder->getTextRenderer()->measureTextStyled($tok['text'], $this->builder->getStyleManager());
            if ($runBG !== null) {
                $this->builder->getGraphicsRenderer()->drawBackgroundRect(
                    $x,
                    $baselineY + $dy - ($this->builder->getStyleManager()->getCurrentFontSize() * 0.25),
                    $tokWidth,
                    $this->builder->getStyleManager()->getLineHeight(),
                    $runBG
                );
            }

            $this->builder->getTextRenderer()->writeTextLine($x, $baselineY + $dy, $tok['text'], $this->builder->getStyleManager(), $shadow);

            if ($href !== null) {
                $linkHeight = $this->builder->getStyleManager()->getLineHeight();
                $linkY = ($baselineY + $dy) - ($linkHeight * 0.25);
                $this->builder->addLinkAbs($x, $linkY, $tokWidth, $linkHeight, $href);
            }

            $x += $tokWidth;
            if ($tok['type'] === 'space') {
                $x += $this->builder->getStyleManager()->getWordSpacing();
                if ($extraPerGap > 0.0) {
                    $x += $extraPerGap;
                }
            }

            $this->builder->getStyleManager()->pop();
        }

        $this->builder->getLayoutManager()->advanceCursor($lineH);
        $this->builder->getLayoutManager()->checkPageBreak();
    }

    // More content methods continue here
}
