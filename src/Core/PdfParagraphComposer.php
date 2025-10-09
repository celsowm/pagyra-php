<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Text\PdfRun;

class PdfParagraphComposer
{
    private PdfBuilder $pdfBuilder;
    private PdfBorderManager $borderManager;

    public function __construct(PdfBuilder $pdfBuilder)
    {
        $this->pdfBuilder = $pdfBuilder;
        $this->borderManager = $pdfBuilder->getBorderManager();
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
        $styleManager = $this->pdfBuilder->getStyleManager();

        if ($styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar texto.");
        }
        $styleManager->push();

        $__opsInsertAt = ($this->pdfBuilder->getCurrentPage() !== null) ? strlen($this->pdfBuilder->pageContents[$this->pdfBuilder->getCurrentPage()] ?? '') : null;

        $styleManager->applyOptions($opts, $this->pdfBuilder);

        $borderSpec = $this->borderManager->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $baseX = $this->pdfBuilder->mLeft + $padding[3];
        $wrapWidth = $this->pdfBuilder->getContentAreaWidth() - $padding[1] - $padding[3];

        // Ajuste horizontal com containerPadding (quando vindo de um bloco com padding)
        if (isset($opts['containerPadding']) && is_array($opts['containerPadding'])) {
            $cp = array_values($opts['containerPadding']);
            $cp += [0, 0, 0, 0];
            $baseX     += (float)$cp[3];
            $wrapWidth -= (float)$cp[1] + (float)$cp[3];
            if ($wrapWidth < 0) {
                $wrapWidth = 0.0;
            }
        }
        $initialCursorY = $this->pdfBuilder->getCursorY();
        $__borderFragments = [];
        $__fragTop = $initialCursorY;
        $__fragPage = $this->pdfBuilder->getCurrentPage();
        $this->pdfBuilder->getLayoutManager()->advanceCursor($padding[0]);
        $__prevPage = $this->pdfBuilder->getCurrentPage();
        $__prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();
        $this->pdfBuilder->getLayoutManager()->checkPageBreak();
        if ($this->pdfBuilder->getCurrentPage() !== $__prevPage) {
            $__borderFragments[] = ['page' => $__prevPage, 'x' => $this->pdfBuilder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => 'first'];
            $__fragTop = $this->pdfBuilder->getLayoutManager()->getCursorY();
            $__fragPage = $this->pdfBuilder->getCurrentPage();
        }

        $align = $opts['align'] ?? 'left';
        $indent = (float)($opts['indent'] ?? 0.0);
        $hangIndent = (float)($opts['hangIndent'] ?? 0.0);
        $spacing = (float)($opts['spacing'] ?? 0.0);
        $lineH = $styleManager->getLineHeight();
        $bgColor = $this->pdfBuilder->normalizeColor($opts['bgcolor'] ?? null);
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
                $wTok = $this->measureTokenWidth($tok);
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
                    $__beforePage = $this->pdfBuilder->getCurrentPage();
                    $__prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();
                    $this->emitRunsLine($tokensToFlush, $align, $indent, $wrapWidth, $lineH, ($align === 'justify'), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                    if ($this->pdfBuilder->getCurrentPage() !== $__beforePage) {
                        $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->pdfBuilder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                        $__fragTop = $this->pdfBuilder->getLayoutManager()->getCursorY();
                        $__fragPage = $this->pdfBuilder->getCurrentPage();
                    }
                    $needMarker = false;
                    $hasWritten = true;
                }
                $firstLine = false;
                $lineTokens = ($nextToken['type'] === 'space') ? [] : [$nextToken];
                $avail = $wrapWidth - ($firstLine ? $indent : $hangIndent) - $this->measureTokensWidth($lineTokens);
            }
            if (!empty($lineTokens)) {
                $__beforePage = $this->pdfBuilder->getCurrentPage();
                $__prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();
                $this->emitRunsLine($lineTokens, $align, $indent, $wrapWidth, $lineH, ($align === 'justify' && !$isLastBlock), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                if ($this->pdfBuilder->getCurrentPage() !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->pdfBuilder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->pdfBuilder->getLayoutManager()->getCursorY();
                    $__fragPage = $this->pdfBuilder->getCurrentPage();
                }
                $needMarker = false;
                $hasWritten = true;
            }
            if ($spacing > 0) {
                $__beforePage = $this->pdfBuilder->getCurrentPage();
                $__prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();
                $this->pdfBuilder->getLayoutManager()->advanceCursor($spacing);
                $this->pdfBuilder->getLayoutManager()->checkPageBreak();
                if ($this->pdfBuilder->getCurrentPage() !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->pdfBuilder->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->pdfBuilder->getLayoutManager()->getCursorY();
                    $__fragPage = $this->pdfBuilder->getCurrentPage();
                }
            }
        }
        if ($hasWritten) {
            $this->pdfBuilder->getLayoutManager()->advanceCursor($padding[2]);
        } else {
            $this->pdfBuilder->getLayoutManager()->setCursorY($initialCursorY);
        }

        $finalCursorY = $this->pdfBuilder->getLayoutManager()->getCursorY();
        if ($borderSpec['hasBorder'] && !empty($__borderFragments)) {
            $__borderFragments[] = ['page' => $this->pdfBuilder->getCurrentPage(), 'x' => $this->pdfBuilder->mLeft, 'y' => $finalCursorY, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $__fragTop - $finalCursorY, 'kind' => 'last'];
            $__origPage = $this->pdfBuilder->getCurrentPage();
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
                $this->pdfBuilder->setCurrentPage($__frag['page']);
                $this->drawParagraphBorders(['x' => $__frag['x'], 'y' => $__frag['y'], 'w' => $__frag['w'], 'h' => $__frag['h']], $spec);
            }
            $this->pdfBuilder->setCurrentPage($__origPage);
        }
        if ($borderSpec['hasBorder'] && empty($__borderFragments)) {
            $paddedBox = ['x' => $this->pdfBuilder->mLeft, 'y' => $finalCursorY, 'w' => $this->pdfBuilder->getContentAreaWidth(), 'h' => $initialCursorY - $finalCursorY];
            $this->drawParagraphBorders($paddedBox, $borderSpec);
        }
        $bgImgOpt = $opts['backgroundImage'] ?? ($opts['bgimage'] ?? null);
        if ($bgImgOpt !== null) {
            $bg = is_string($bgImgOpt) ? ['alias' => $bgImgOpt] : (array)$bgImgOpt;
            if (empty($bg['alias'])) throw new \InvalidArgumentException("backgroundImage: defina 'alias'.");
            $boxX = $this->pdfBuilder->mLeft;
            $boxY = $finalCursorY;
            $boxW = $this->pdfBuilder->getContentAreaWidth();
            $boxH = $initialCursorY - $finalCursorY;
            if ($__opsInsertAt !== null && $boxW > 0 && $boxH > 0) {
                $this->pdfBuilder->drawBackgroundImageInRect($bg['alias'], $boxX, $boxY, $boxW, $boxH, $bg, $__opsInsertAt);
            }
        }

        $styleManager->pop();
    }

    public function drawParagraphBorders(array $box, array $spec): void
    {
        $this->pdfBuilder->drawParagraphBorders($box, $spec);
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
        $styleManager = $this->pdfBuilder->getStyleManager();
        $textRenderer = $this->pdfBuilder->getTextRenderer();
        $fontManager = $this->pdfBuilder->getFontManager();

        if ($tok['type'] === 'inline') {
            return $tok['opt']['width'] ?? 0;
        }
        $styleManager->push();
        $styleManager->applyOptions($tok['opt'], $this->pdfBuilder);

        $baseSz = $styleManager->getCurrentFontSize();
        $opt = $tok['opt'] ?? [];
        $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
        $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
        $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

        if (abs($scale - 1.0) > 1e-6) {
            $styleManager->setFont($styleManager->getCurrentFontAlias(), $baseSz * $scale);
        }

        $width = $textRenderer->measureTextStyled($tok['text'], $styleManager);

        $styleManager->pop();
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
        $styleManager = $this->pdfBuilder->getStyleManager();
        $fontManager = $this->pdfBuilder->getFontManager();

        $size = max(0.001, $styleManager->getCurrentFontSize());
        $alias = $styleManager->getCurrentFontAlias();
        $style = $styleManager->getStyle();
        $fonts = $fontManager->getFonts();
        $resolvedAlias = null;
        if ($alias !== null) {
            $resolvedAlias = $fontManager->resolveAliasByStyle($alias, $style);
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
        $layoutManager = $this->pdfBuilder->getLayoutManager();
        $textRenderer = $this->pdfBuilder->getTextRenderer();
        $styleManager = $this->pdfBuilder->getStyleManager();

        $layoutManager->checkPageBreak($lineH);
        $renderTokens = $tokens;
        if ($justify && count($renderTokens) > 0 && end($renderTokens)['type'] === 'space') {
            array_pop($renderTokens);
        }
        if (empty($renderTokens)) {
            $layoutManager->advanceCursor($lineH);
            $layoutManager->checkPageBreak();
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
        $lineTop = $layoutManager->getCursorY();

        $lineMetrics = $this->computeLineMetrics($lineH);
        $baselineOffset = $lineMetrics['baselineOffset'];
        $baselineY = $lineTop - $baselineOffset;

        if ($bgColor !== null) {
            $maxSz = $styleManager->getCurrentFontSize();
            $this->pdfBuilder->drawBackgroundRect($baseX, $baselineY - ($maxSz * 0.25), $wrapWidth, $lineH, $bgColor);
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
            $styleManager->push();
            $styleManager->applyOptions([
                'fontAlias' => $markerSpec['fontAlias'],
                'size' => (float)$markerSpec['size'],
                'style' => (string)$markerSpec['style'],
                'color' => $markerSpec['color'],
                'letterSpacing' => 0.0,
                'wordSpacing' => 0.0,
            ], $this->pdfBuilder);

            $mText = (string)$markerSpec['text'];
            $mWidth = (float)$markerSpec['width'];
            $mAlign = strtolower($markerSpec['align'] ?? 'right');
            $mGap = (float)$markerSpec['gap'];
            $measured = $textRenderer->measureTextStyled($mText, $styleManager);
            $boxRight = $baseX + $actualIndent;
            $boxLeft = $boxRight - max($mWidth, $measured + $mGap);
            $mx = ($mAlign === 'right') ? $boxRight - $measured - $mGap : $boxLeft;
            $textRenderer->writeTextLine($mx, $baselineY, $mText, $styleManager, null);
            $styleManager->pop();
        }

        foreach ($renderTokens as $tok) {
            if ($tok['type'] === 'inline') {
                $tok['renderer']($x, $baselineY);
                $x += $this->measureTokenWidth($tok);
                continue;
            }
            $styleManager->push();
            $opt = $tok['opt'] ?? [];
            $styleManager->applyOptions($opt, $this->pdfBuilder);

            $shadow = $this->pdfBuilder->normalizeShadowSpec($opt['textShadow'] ?? null);
            $runBG = $this->pdfBuilder->normalizeColor($opt['bgcolor'] ?? null);
            $href = $opt['href'] ?? null;
            $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
            $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
            $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

            if (abs($scale - 1.0) > 1e-6) {
                $styleManager->setFont(
                    $styleManager->getCurrentFontAlias(),
                    $styleManager->getCurrentFontSize() * $scale
                );
            }

            $dy = isset($opt['baselineShift'])
                ? (float)$opt['baselineShift']
                : ($isSup ? ($lineH * 0.35) : ($isSub ? - ($lineH * 0.15) : 0.0));

            $tokWidth = $textRenderer->measureTextStyled($tok['text'], $styleManager);
            if ($runBG !== null) {
                $this->pdfBuilder->drawBackgroundRect(
                    $x,
                    $baselineY + $dy - ($styleManager->getCurrentFontSize() * 0.25),
                    $tokWidth,
                    $styleManager->getLineHeight(),
                    $runBG
                );
            }

            $textRenderer->writeTextLine($x, $baselineY + $dy, $tok['text'], $styleManager, $shadow);

            if ($href !== null) {
                $linkHeight = $styleManager->getLineHeight();
                $linkY = ($baselineY + $dy) - ($linkHeight * 0.25);
                $this->pdfBuilder->addLinkAbs($x, $linkY, $tokWidth, $linkHeight, $href);
            }

            $x += $tokWidth;
            if ($tok['type'] === 'space') {
                $x += $styleManager->getWordSpacing();
                if ($extraPerGap > 0.0) {
                    $x += $extraPerGap;
                }
            }

            $styleManager->pop();
        }

        $layoutManager->advanceCursor($lineH);
        $layoutManager->checkPageBreak();
    }
}
