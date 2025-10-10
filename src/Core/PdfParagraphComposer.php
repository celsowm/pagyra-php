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
        $this->validateFontIsSet();

        $styleManager = $this->pdfBuilder->getStyleManager();
        $styleManager->push();

        $context = $this->initializeParagraphContext($opts);
        $layout = $this->calculateLayoutDimensions($opts, $context);

        // Store layout data in context for later retrieval
        $context['layout'] = $layout;

        $blocks = $this->explodeRunsToBlocksByNewline($runs);
        $renderingResult = $this->renderTextBlocks($blocks, $opts, $layout, $context);

        $this->applyFinalLayoutAdjustments($renderingResult, $context, $layout);
        $this->renderBorders($renderingResult, $context);
        $this->renderBackgroundImage($opts, $context, $renderingResult);

        $styleManager->pop();
    }

    public function drawParagraphBorders(array $box, array $spec): void
    {
        $this->pdfBuilder->drawParagraphBorders($box, $spec);
    }

    private function validateFontIsSet(): void
    {
        $styleManager = $this->pdfBuilder->getStyleManager();
        if ($styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar texto.");
        }
    }

    private function initializeParagraphContext(array $opts): array
    {
        $styleManager = $this->pdfBuilder->getStyleManager();
        $styleManager->applyOptions($opts, $this->pdfBuilder);

        $opsInsertAt = ($this->pdfBuilder->getCurrentPage() !== null)
            ? strlen($this->pdfBuilder->pageContents[$this->pdfBuilder->getCurrentPage()] ?? '')
            : null;

        return [
            'opsInsertAt' => $opsInsertAt,
            'initialCursorY' => $this->pdfBuilder->getCursorY(),
            'borderFragments' => [],
            'fragTop' => $this->pdfBuilder->getCursorY(),
            'fragPage' => $this->pdfBuilder->getCurrentPage(),
            'layout' => null, // Will be set by calculateLayoutDimensions
        ];
    }

    private function calculateLayoutDimensions(array $opts, array $context): array
    {
        $styleManager = $this->pdfBuilder->getStyleManager();
        $borderSpec = $this->borderManager->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $baseX = $this->pdfBuilder->mLeft + $padding[3];
        $wrapWidth = $this->pdfBuilder->getContentAreaWidth() - $padding[1] - $padding[3];

        // Ajuste horizontal com containerPadding (quando vindo de um bloco com padding)
        if (isset($opts['containerPadding']) && is_array($opts['containerPadding'])) {
            $cp = array_values($opts['containerPadding']);
            $cp += [0, 0, 0, 0];
            $baseX += (float)$cp[3];
            $wrapWidth -= (float)$cp[1] + (float)$cp[3];
            if ($wrapWidth < 0) {
                $wrapWidth = 0.0;
            }
        }

        return [
            'borderSpec' => $borderSpec,
            'padding' => $padding,
            'baseX' => $baseX,
            'wrapWidth' => $wrapWidth,
            'align' => $opts['align'] ?? 'left',
            'indent' => (float)($opts['indent'] ?? 0.0),
            'hangIndent' => (float)($opts['hangIndent'] ?? 0.0),
            'spacing' => (float)($opts['spacing'] ?? 0.0),
            'lineHeight' => $styleManager->getLineHeight(),
            'bgColor' => $this->pdfBuilder->normalizeColor($opts['bgcolor'] ?? null),
            'markerSpec' => $opts['listMarker'] ?? null,
            'needMarker' => ($opts['listMarker'] ?? null) !== null,
        ];
    }

    private function renderTextBlocks(array $blocks, array $opts, array $layout, array &$context): array
    {
        $styleManager = $this->pdfBuilder->getStyleManager();
        $lineHeight = $styleManager->getLineHeight();

        // Avança cursor com padding superior
        $this->pdfBuilder->getLayoutManager()->advanceCursor($layout['padding'][0]);

        // Verifica quebra de página inicial
        $prevPage = $this->pdfBuilder->getCurrentPage();
        $prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();
        $this->pdfBuilder->getLayoutManager()->checkPageBreak();

        if ($this->pdfBuilder->getCurrentPage() !== $prevPage) {
            $context['borderFragments'][] = [
                'page' => $prevPage,
                'x' => $this->pdfBuilder->mLeft,
                'y' => $prevBottomMargin,
                'w' => $this->pdfBuilder->getContentAreaWidth(),
                'h' => $context['fragTop'] - $prevBottomMargin,
                'kind' => 'first'
            ];
            $context['fragTop'] = $this->pdfBuilder->getLayoutManager()->getCursorY();
            $context['fragPage'] = $this->pdfBuilder->getCurrentPage();
        }

        $hasWritten = false;
        $lastBlockKey = empty($blocks) ? null : array_key_last($blocks);

        foreach ($blocks as $key => $blockTokens) {
            $isLastBlock = ($key === $lastBlockKey);
            $hasWritten = $this->renderTextBlock($blockTokens, $opts, $layout, $context, $isLastBlock, $hasWritten);
        }

        return [
            'hasWritten' => $hasWritten,
            'finalCursorY' => $this->pdfBuilder->getLayoutManager()->getCursorY(),
        ];
    }

    private function renderTextBlock(array $blockTokens, array $opts, array $layout, array &$context, bool $isLastBlock, bool $hasWritten): bool
    {
        $firstLine = true;
        $lineTokens = [];
        $avail = $layout['wrapWidth'] - ($firstLine ? $layout['indent'] : $layout['hangIndent']);

        foreach ($blockTokens as $tok) {
            $wTok = $this->measureTokenWidth($tok);
            if (empty($lineTokens) && $tok['type'] === 'space') continue;

            if ($wTok <= $avail || empty($lineTokens)) {
                $lineTokens[] = $tok;
                $avail -= $wTok;
                continue;
            }

            // Flush line
            $hasWritten = $this->flushLineTokens($lineTokens, $layout, $context, $firstLine, $hasWritten);
            $firstLine = false;

            // Start new line with current token
            $lineTokens = ($tok['type'] === 'space') ? [] : [$tok];
            $avail = $layout['wrapWidth'] - ($firstLine ? $layout['indent'] : $layout['hangIndent']) - $this->measureTokensWidth($lineTokens);
        }

        // Flush remaining tokens
        if (!empty($lineTokens)) {
            $hasWritten = $this->flushLineTokens($lineTokens, $layout, $context, $firstLine, $hasWritten, $isLastBlock);
        }

        // Apply spacing between blocks
        if ($layout['spacing'] > 0) {
            $this->applyBlockSpacing($layout, $context);
        }

        return $hasWritten;
    }

    private function flushLineTokens(array $lineTokens, array $layout, array &$context, bool $firstLine, bool $hasWritten, bool $isLastBlock = false): bool
    {
        $tokensToFlush = $lineTokens;
        if (end($tokensToFlush)['type'] === 'space') {
            array_pop($tokensToFlush);
        }

        if (!empty($tokensToFlush)) {
            $beforePage = $this->pdfBuilder->getCurrentPage();
            $prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();

            $this->emitRunsLine(
                $tokensToFlush,
                $layout['align'],
                $layout['indent'],
                $layout['wrapWidth'],
                $layout['lineHeight'],
                ($layout['align'] === 'justify' && !$isLastBlock),
                $layout['bgColor'],
                $layout['baseX'],
                $firstLine,
                $layout['hangIndent'],
                $layout['needMarker'] ? $layout['markerSpec'] : null
            );

            if ($this->pdfBuilder->getCurrentPage() !== $beforePage) {
                $context['borderFragments'][] = [
                    'page' => $beforePage,
                    'x' => $this->pdfBuilder->mLeft,
                    'y' => $prevBottomMargin,
                    'w' => $this->pdfBuilder->getContentAreaWidth(),
                    'h' => $context['fragTop'] - $prevBottomMargin,
                    'kind' => empty($context['borderFragments']) ? 'first' : 'middle'
                ];
                $context['fragTop'] = $this->pdfBuilder->getLayoutManager()->getCursorY();
                $context['fragPage'] = $this->pdfBuilder->getCurrentPage();
            }

            $layout['needMarker'] = false;
            $hasWritten = true;
        }

        return $hasWritten;
    }

    private function applyBlockSpacing(array $layout, array &$context): void
    {
        $beforePage = $this->pdfBuilder->getCurrentPage();
        $prevBottomMargin = $this->pdfBuilder->getLayoutManager()->getPageBottomMargin();

        $this->pdfBuilder->getLayoutManager()->advanceCursor($layout['spacing']);
        $this->pdfBuilder->getLayoutManager()->checkPageBreak();

        if ($this->pdfBuilder->getCurrentPage() !== $beforePage) {
            $context['borderFragments'][] = [
                'page' => $beforePage,
                'x' => $this->pdfBuilder->mLeft,
                'y' => $prevBottomMargin,
                'w' => $this->pdfBuilder->getContentAreaWidth(),
                'h' => $context['fragTop'] - $prevBottomMargin,
                'kind' => empty($context['borderFragments']) ? 'first' : 'middle'
            ];
            $context['fragTop'] = $this->pdfBuilder->getLayoutManager()->getCursorY();
            $context['fragPage'] = $this->pdfBuilder->getCurrentPage();
        }
    }

    private function applyFinalLayoutAdjustments(array $renderingResult, array $context, array $layout): void
    {
        if ($renderingResult['hasWritten']) {
            $this->pdfBuilder->getLayoutManager()->advanceCursor($layout['padding'][2]);
        } else {
            $this->pdfBuilder->getLayoutManager()->setCursorY($context['initialCursorY']);
        }
    }

    private function renderBorders(array $renderingResult, array $context): void
    {
        $layout = $this->getLayoutFromContext($context);
        $borderSpec = $layout['borderSpec'];
        $finalCursorY = $renderingResult['finalCursorY'];

        if (!$borderSpec['hasBorder'] || empty($context['borderFragments'])) {
            if ($borderSpec['hasBorder']) {
                $paddedBox = [
                    'x' => $this->pdfBuilder->mLeft,
                    'y' => $finalCursorY,
                    'w' => $this->pdfBuilder->getContentAreaWidth(),
                    'h' => $context['initialCursorY'] - $finalCursorY
                ];
                $this->drawParagraphBorders($paddedBox, $borderSpec);
            }
            return;
        }

        $context['borderFragments'][] = [
            'page' => $this->pdfBuilder->getCurrentPage(),
            'x' => $this->pdfBuilder->mLeft,
            'y' => $finalCursorY,
            'w' => $this->pdfBuilder->getContentAreaWidth(),
            'h' => $context['fragTop'] - $finalCursorY,
            'kind' => 'last'
        ];

        $origPage = $this->pdfBuilder->getCurrentPage();
        foreach ($context['borderFragments'] as $frag) {
            if ($frag['h'] <= 0.001) continue;

            $spec = $borderSpec;
            $spec = $this->adjustBorderSpecForFragment($spec, $frag['kind']);

            $this->pdfBuilder->setCurrentPage($frag['page']);
            $this->drawParagraphBorders([
                'x' => $frag['x'],
                'y' => $frag['y'],
                'w' => $frag['w'],
                'h' => $frag['h']
            ], $spec);
        }
        $this->pdfBuilder->setCurrentPage($origPage);
    }

    private function renderBackgroundImage(array $opts, array $context, array $renderingResult): void
    {
        $bgImgOpt = $opts['backgroundImage'] ?? ($opts['bgimage'] ?? null);
        if ($bgImgOpt === null) return;

        $bg = is_string($bgImgOpt) ? ['alias' => $bgImgOpt] : (array)$bgImgOpt;
        if (empty($bg['alias'])) {
            throw new \InvalidArgumentException("backgroundImage: defina 'alias'.");
        }

        $boxX = $this->pdfBuilder->mLeft;
        $boxY = $renderingResult['finalCursorY'];
        $boxW = $this->pdfBuilder->getContentAreaWidth();
        $boxH = $context['initialCursorY'] - $renderingResult['finalCursorY'];

        if ($context['opsInsertAt'] !== null && $boxW > 0 && $boxH > 0) {
            $this->pdfBuilder->drawBackgroundImageInRect(
                $bg['alias'],
                $boxX,
                $boxY,
                $boxW,
                $boxH,
                $bg,
                $context['opsInsertAt']
            );
        }
    }

    private function getLayoutFromContext(array $context): array
    {
        // Retrieve layout data that was stored in context during paragraph processing
        if (isset($context['layout']) && is_array($context['layout'])) {
            return $context['layout'];
        }

        // Fallback to default structure if layout data is not available
        // This maintains backward compatibility and prevents undefined array key errors
        return [
            'borderSpec' => [
                'hasBorder' => false,
                'width' => [0.0, 0.0, 0.0, 0.0],
                'color' => [0.0, 0.0, 0.0],
                'style' => 'solid',
                'padding' => [0.0, 0.0, 0.0, 0.0]
            ],
            'padding' => [0.0, 0.0, 0.0, 0.0],
            'baseX' => $this->pdfBuilder->mLeft,
            'wrapWidth' => $this->pdfBuilder->getContentAreaWidth(),
            'align' => 'left',
            'indent' => 0.0,
            'hangIndent' => 0.0,
            'spacing' => 0.0,
            'lineHeight' => 12.0, // Default line height
            'bgColor' => null,
            'markerSpec' => null,
            'needMarker' => false,
        ];
    }

    private function adjustBorderSpecForFragment(array $spec, string $kind): array
    {
        if ($kind === 'first') {
            $spec['width'][2] = 0.0;
            if (isset($spec['radius'])) {
                $spec['radius'][2] = 0.0;
                $spec['radius'][3] = 0.0;
            }
        } elseif ($kind === 'middle') {
            $spec['width'][0] = 0.0;
            $spec['width'][2] = 0.0;
            if (isset($spec['radius'])) {
                $spec['radius'] = [0.0, 0.0, 0.0, 0.0];
            }
        } elseif ($kind === 'last') {
            $spec['width'][0] = 0.0;
            if (isset($spec['radius'])) {
                $spec['radius'][0] = 0.0;
                $spec['radius'][1] = 0.0;
            }
        }
        return $spec;
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
