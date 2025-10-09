<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Block\PdfBlockRenderer;
use Celsowm\PagyraPhp\Color\PdfColor;
use Celsowm\PagyraPhp\Image\PdfImageManager;
use Celsowm\PagyraPhp\Core\PdfLayoutManager;
use Celsowm\PagyraPhp\Text\PdfListRenderer;
use Celsowm\PagyraPhp\Text\PdfParagraphBuilder;
use Celsowm\PagyraPhp\Text\PdfRun;
use Celsowm\PagyraPhp\Core\PdfStreamBuilder;
use Celsowm\PagyraPhp\Core\HeaderManager;
use Celsowm\PagyraPhp\Core\FooterManager;
use Celsowm\PagyraPhp\Core\FixedElementManager;
use Celsowm\PagyraPhp\Core\PdfLinkManager;
use Celsowm\PagyraPhp\Style\PdfStyleManager;
use Celsowm\PagyraPhp\Graphics\State\PdfExtGStateManager;
use Celsowm\PagyraPhp\Font\PdfFontManager;
use Celsowm\PagyraPhp\Table\PdfTableBuilder;
use Celsowm\PagyraPhp\Table\PdfTableManager;
use Celsowm\PagyraPhp\Text\PdfTextRenderer;
use Celsowm\PagyraPhp\Writer\PdfWriter;
use Celsowm\PagyraPhp\Graphics\PdfGraphicsRenderer;

final class PdfBuilder
{

    private PdfWriter $writer;

    private PdfTextRenderer $textRenderer;
    private PdfStyleManager $styleManager;
    private PdfLayoutManager $layoutManager;
    private PdfColor $colorManager;
    private PdfFontManager $fontManager;
    private PdfExtGStateManager $extGStateManager;
    private PdfImageManager $imageManager;

    public float $mLeft;
    public float $mRight;

    private array $pages = [];
    public array $pageContents = [];
    private array $pageResources = [];
    private ?int $currentPage = null;
    private array $fonts = [];
    private array $usedGids = [];
    private array $pageAnnotations = [];
    private array $uriActions = [];

    public array $baseMargins = ['left' => 56.0, 'top' => 56.0, 'right' => 56.0, 'bottom' => 56.0];

    private int $pageBreakSuppression = 0;
    private HeaderManager $headerManager;
    private FooterManager $footerManager;
    private FixedElementManager $fixedElementManager;
    private PdfTableManager $tableManager;
    private PdfGraphicsRenderer $graphicsRenderer;
    private PdfLinkManager $linkManager;
    private PdfMeasurementManager $measurementManager;
    private PdfBorderManager $borderManager;
    private PdfColumnLayoutManager $columnLayoutManager;


    public function __construct(float $w = 595.28, float $h = 841.89)
    {
        $this->writer = new PdfWriter();
        $this->colorManager = new PdfColor();
        $this->fontManager = new PdfFontManager($this);
        $this->textRenderer = new PdfTextRenderer($this, $this->fontManager);
        $this->styleManager = new PdfStyleManager();
        $this->layoutManager = new PdfLayoutManager($this, $w, $h);
        $this->imageManager = new PdfImageManager($this);
        $this->extGStateManager = new PdfExtGStateManager($this);
        $this->headerManager = new HeaderManager($this);
        $this->footerManager = new FooterManager($this);
        $this->fixedElementManager = new FixedElementManager($this);
        $this->tableManager = new PdfTableManager($this);
        $this->graphicsRenderer = new PdfGraphicsRenderer($this);
        $this->linkManager = new PdfLinkManager($this);
        $this->measurementManager = new PdfMeasurementManager();
        $this->borderManager = new PdfBorderManager($this->graphicsRenderer);
        $this->borderManager->setColorNormalizer([$this->colorManager, 'normalize']);
        $this->columnLayoutManager = new PdfColumnLayoutManager($this);

        $this->setMargins(56, 56, 56, 56);
        $this->internal_newPage();
        $this->bootstrapDefaultFont();
    }

    public function getFontManager(): PdfFontManager
    {
        return $this->fontManager;
    }

    public function getHeaderManager(): HeaderManager
    {
        return $this->headerManager;
    }

    public function getFooterManager(): FooterManager
    {
        return $this->footerManager;
    }

    public function getStyleManager(): PdfStyleManager
    {
        return $this->styleManager;
    }

    public function getTextRenderer(): PdfTextRenderer
    {
        return $this->textRenderer;
    }

    public function getImageManager(): PdfImageManager
    {
        return $this->imageManager;
    }

    public function getPageWidth(): float
    {
        return $this->layoutManager->getPageWidth();
    }
    public function getPageHeight(): float
    {
        return $this->layoutManager->getPageHeight();
    }
    public function getContentAreaWidth(): float
    {
        return $this->layoutManager->getContentAreaWidth();
    }
    public function getPageBottomMargin(): float
    {
        return $this->layoutManager->getPageBottomMargin();
    }
    public function getCursorY(): float
    {
        return $this->layoutManager->getCursorY();
    }
    public function setCursorY(float $y): void
    {
        $this->layoutManager->setCursorY($y);
    }

    public function getExtGStateManager(): PdfExtGStateManager
    {
        return $this->extGStateManager;
    }

    public function getLayoutManager(): PdfLayoutManager
    {
        return $this->layoutManager;
    }

    public function getColorManager(): PdfColor
    {
        return $this->colorManager;
    }

    public function getBorderManager(): PdfBorderManager
    {
        return $this->borderManager;
    }

    public function getLeftMargin(): float
    {
        return $this->mLeft;
    }

    public function getRightMargin(): float
    {
        return $this->mRight;
    }

    public function isMeasurementMode(): bool
    {
        return $this->measurementManager->isMeasurementMode();
    }

    public function suppressPageBreaks(): void
    {
        $this->pageBreakSuppression++;
    }

    public function resumePageBreaks(): void
    {
        if ($this->pageBreakSuppression > 0) {
            $this->pageBreakSuppression--;
        }
    }

    public function arePageBreaksSuppressed(): bool
    {
        return $this->pageBreakSuppression > 0;
    }

    public function setObject(int $id, string $content): void
    {
        $this->writer->setObject($id, $content);
    }

    public function getCurrentPage(): ?int
    {
        return $this->currentPage;
    }

    public function appendToPageContent(string $ops): void
    {
        if ($this->measurementManager->isMeasurementMode() || $this->currentPage === null) {
            return;
        }
        $this->pageContents[$this->currentPage] .= $ops;
    }

    public function registerPageResource(string $type, string $label, ?int $value = 0): void
    {
        if ($this->measurementManager->isMeasurementMode() || $this->currentPage === null) {
            return;
        }
        $this->pageResources[$this->currentPage][$type][$label] = $value;
    }

    public function colorOps($spec): string
    {
        return $this->colorManager->getFillOps($spec);
    }

    public function strokeColorOps($spec): string
    {
        return $this->colorManager->getStrokeOps($spec);
    }

    public function addLinkAbs(float $x, float $y, float $width, float $height, string $url): void
    {
        $this->linkManager->addLinkAbs($x, $y, $width, $height, $url);
    }

    public function addLink(string $text, string $url, array $opts = []): void
    {
        $this->linkManager->addLinkText($text, $url, $opts);
    }

    public function addLinkTextAbs(float $x, float $y, string $text, string $url, array $opts = []): void
    {
        $this->linkManager->addLinkTextAbs($x, $y, $text, $url, $opts);
    }

    public function addAnnotation(int $annotId, array $rect, int $actionId): void
    {
        if (!isset($this->pageAnnotations[$this->currentPage])) {
            $this->pageAnnotations[$this->currentPage] = [];
        }
        $this->pageAnnotations[$this->currentPage][] = [
            'id' => $annotId,
            'rect' => $rect,
            'action' => $actionId
        ];
    }

    public function addTTFFont(string $alias, string $ttfPath): void
    {
        $this->fontManager->addTTFFont($alias, $ttfPath);
    }

    public function bindFontVariants(string $baseAlias, array $map): void
    {
        $this->fontManager->bindFontVariants($baseAlias, $map);
    }

    public function setMargins(float $left, float $top, float $right, float $bottom): void
    {
        $this->baseMargins = [
            'left' => $left,
            'top' => $top,
            'right' => $right,
            'bottom' => $bottom,
        ];
        $this->layoutManager->setBaseMargins($top, $right, $bottom, $left);

        $headerOffset = $this->headerManager->getOffset($this->baseMargins['top']);
        if ($this->headerManager->isDefined() && $this->headerManager->pushesContent()) {
            $this->layoutManager->setCursorY($this->getPageHeight() - $headerOffset);
        }

        $footerOffset = $this->footerManager->getOffset($this->baseMargins['bottom']);
        $this->layoutManager->updateBaseBottomMargin($footerOffset);
    }

    public function setHeader(callable $callback, array $options = []): void
    {
        $this->headerManager->set($callback, $options);
        // After setting the header, we need to recalculate margins
        $this->setMargins(
            $this->baseMargins['left'],
            $this->baseMargins['top'],
            $this->baseMargins['right'],
            $this->baseMargins['bottom']
        );

        $this->headerManager->render();
    }

    public function getContentTopOffset(): float
    {
        return $this->headerManager->getOffset($this->baseMargins['top']);
    }

    public function setFooter(callable $callback, array $options = []): void
    {
        $this->footerManager->set($callback, $options);
        // After setting the footer, we need to recalculate margins
        $this->setMargins(
            $this->baseMargins['left'],
            $this->baseMargins['top'],
            $this->baseMargins['right'],
            $this->baseMargins['bottom']
        );

        $this->footerManager->render();
    }

    public function setFont(string $alias, float $size, ?float $lineHeight = null): void
    {
        // Modificar para usar o novo manager para validação
        if (!$this->fontManager->fontExists($alias)) {
            throw new \LogicException("Fonte '{$alias}' não foi adicionada com addTTFFont().");
        }
        $this->styleManager->setFont($alias, $size, $lineHeight);
    }

    public function setTextColor($color): void
    {
        $this->styleManager->setTextColor($this->normalizeColor($color));
    }

    public function setTextSpacing(?float $letter = null, ?float $word = null): void
    {
        $this->styleManager->setTextSpacing($letter, $word);
    }

    public function addList(string|array $items, array $opts = []): void
    {
        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de addList().");
        }
        $renderer = new PdfListRenderer(
            $this,
            $this->textRenderer,
            $this->styleManager
        );
        $renderer->render($items, $opts);
    }

    public function addParagraph(string|array $textOrOpts, array $opts = []): ?PdfParagraphBuilder
    {
        if (is_string($textOrOpts)) {
            $this->addParagraphText($textOrOpts, $opts);
            return null;
        }
        if (is_array($textOrOpts) && empty($opts)) {
            return new PdfParagraphBuilder($this, $textOrOpts);
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

        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar texto.");
        }
        $this->styleManager->push();

        $__opsInsertAt = ($this->currentPage !== null) ? strlen($this->pageContents[$this->currentPage]) : null;

        $this->styleManager->applyOptions($opts, $this);

        $borderSpec = $this->borderManager->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $baseX = $this->mLeft + $padding[3];
        $wrapWidth = $this->layoutManager->getContentAreaWidth() - $padding[1] - $padding[3];

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
        $initialCursorY = $this->layoutManager->getCursorY();
        $__borderFragments = [];
        $__fragTop = $initialCursorY;
        $__fragPage = $this->currentPage;
        $this->layoutManager->advanceCursor($padding[0]);
        $__prevPage = $this->currentPage;
        $__prevBottomMargin = $this->layoutManager->getPageBottomMargin();
        $this->layoutManager->checkPageBreak();
        if ($this->currentPage !== $__prevPage) {
            $__borderFragments[] = ['page' => $__prevPage, 'x' => $this->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => 'first'];
            $__fragTop = $this->layoutManager->getCursorY();
            $__fragPage = $this->currentPage;
        }

        $align = $opts['align'] ?? 'left';
        $indent = (float)($opts['indent'] ?? 0.0);
        $hangIndent = (float)($opts['hangIndent'] ?? 0.0);
        $spacing = (float)($opts['spacing'] ?? 0.0);
        $lineH = $this->styleManager->getLineHeight();
        $bgColor = $this->normalizeColor($opts['bgcolor'] ?? null);
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
                    $__beforePage = $this->currentPage;
                    $__prevBottomMargin = $this->layoutManager->getPageBottomMargin();
                    $this->emitRunsLine($tokensToFlush, $align, $indent, $wrapWidth, $lineH, ($align === 'justify'), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                    if ($this->currentPage !== $__beforePage) {
                        $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                        $__fragTop = $this->layoutManager->getCursorY();
                        $__fragPage = $this->currentPage;
                    }
                    $needMarker = false;
                    $hasWritten = true;
                }
                $firstLine = false;
                $lineTokens = ($nextToken['type'] === 'space') ? [] : [$nextToken];
                $avail = $wrapWidth - ($firstLine ? $indent : $hangIndent) - $this->measureTokensWidth($lineTokens);
            }
            if (!empty($lineTokens)) {
                $__beforePage = $this->currentPage;
                $__prevBottomMargin = $this->layoutManager->getPageBottomMargin();
                $this->emitRunsLine($lineTokens, $align, $indent, $wrapWidth, $lineH, ($align === 'justify' && !$isLastBlock), $bgColor, $baseX, $firstLine, $hangIndent, $needMarker ? $markerSpec : null);
                if ($this->currentPage !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->layoutManager->getCursorY();
                    $__fragPage = $this->currentPage;
                }
                $needMarker = false;
                $hasWritten = true;
            }
            if ($spacing > 0) {
                $__beforePage = $this->currentPage;
                $__prevBottomMargin = $this->layoutManager->getPageBottomMargin();
                $this->layoutManager->advanceCursor($spacing);
                $this->layoutManager->checkPageBreak();
                if ($this->currentPage !== $__beforePage) {
                    $__borderFragments[] = ['page' => $__beforePage, 'x' => $this->mLeft, 'y' => $__prevBottomMargin, 'w' => $this->getContentAreaWidth(), 'h' => $__fragTop - $__prevBottomMargin, 'kind' => empty($__borderFragments) ? 'first' : 'middle'];
                    $__fragTop = $this->layoutManager->getCursorY();
                    $__fragPage = $this->currentPage;
                }
            }
        }
        if ($hasWritten) {
            $this->layoutManager->advanceCursor($padding[2]);
        } else {
            $this->layoutManager->setCursorY($initialCursorY);
        }

        $finalCursorY = $this->layoutManager->getCursorY();
        if ($borderSpec['hasBorder'] && !empty($__borderFragments)) {
            $__borderFragments[] = ['page' => $this->currentPage, 'x' => $this->mLeft, 'y' => $finalCursorY, 'w' => $this->getContentAreaWidth(), 'h' => $__fragTop - $finalCursorY, 'kind' => 'last'];
            $__origPage = $this->currentPage;
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
                $this->currentPage = $__frag['page'];
                $this->drawParagraphBorders(['x' => $__frag['x'], 'y' => $__frag['y'], 'w' => $__frag['w'], 'h' => $__frag['h']], $spec);
            }
            $this->currentPage = $__origPage;
        }
        if ($borderSpec['hasBorder'] && empty($__borderFragments)) {
            $paddedBox = ['x' => $this->mLeft, 'y' => $finalCursorY, 'w' => $this->getContentAreaWidth(), 'h' => $initialCursorY - $finalCursorY];
            $this->drawParagraphBorders($paddedBox, $borderSpec);
        }
        $bgImgOpt = $opts['backgroundImage'] ?? ($opts['bgimage'] ?? null);
        if ($bgImgOpt !== null) {
            $bg = is_string($bgImgOpt) ? ['alias' => $bgImgOpt] : (array)$bgImgOpt;
            if (empty($bg['alias'])) throw new \InvalidArgumentException("backgroundImage: defina 'alias'.");
            $boxX = $this->mLeft;
            $boxY = $finalCursorY;
            $boxW = $this->getContentAreaWidth();
            $boxH = $initialCursorY - $finalCursorY;
            if ($__opsInsertAt !== null && $boxW > 0 && $boxH > 0) {
                $this->drawBackgroundImageInRect($bg['alias'], $boxX, $boxY, $boxW, $boxH, $bg, $__opsInsertAt);
            }
        }

        $this->styleManager->pop();
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
        $this->styleManager->push();
        $this->styleManager->applyOptions($tok['opt'], $this);

        $baseSz = $this->styleManager->getCurrentFontSize();
        $opt = $tok['opt'] ?? [];
        $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
        $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
        $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

        if (abs($scale - 1.0) > 1e-6) {
            $this->styleManager->setFont($this->styleManager->getCurrentFontAlias(), $baseSz * $scale);
        }

        $width = $this->textRenderer->measureTextStyled($tok['text'], $this->styleManager);

        $this->styleManager->pop();
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
        $size = max(0.001, $this->styleManager->getCurrentFontSize());
        $alias = $this->styleManager->getCurrentFontAlias();
        $style = $this->styleManager->getStyle();
        $fonts = $this->fontManager->getFonts();
        $resolvedAlias = null;
        if ($alias !== null) {
            $resolvedAlias = $this->fontManager->resolveAliasByStyle($alias, $style);
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
        $this->layoutManager->checkPageBreak($lineH);
        $renderTokens = $tokens;
        if ($justify && count($renderTokens) > 0 && end($renderTokens)['type'] === 'space') {
            array_pop($renderTokens);
        }
        if (empty($renderTokens)) {
            $this->layoutManager->advanceCursor($lineH);
            $this->layoutManager->checkPageBreak();
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
        $lineTop = $this->layoutManager->getCursorY();

        $lineMetrics = $this->computeLineMetrics($lineH);
        $baselineOffset = $lineMetrics['baselineOffset'];
        $baselineY = $lineTop - $baselineOffset;

        if ($bgColor !== null) {
            $maxSz = $this->styleManager->getCurrentFontSize();
            $this->drawBackgroundRect($baseX, $baselineY - ($maxSz * 0.25), $wrapWidth, $lineH, $bgColor);
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
            $this->styleManager->push();
            $this->styleManager->applyOptions([
                'fontAlias' => $markerSpec['fontAlias'],
                'size' => (float)$markerSpec['size'],
                'style' => (string)$markerSpec['style'],
                'color' => $markerSpec['color'],
                'letterSpacing' => 0.0,
                'wordSpacing' => 0.0,
            ], $this);

            $mText = (string)$markerSpec['text'];
            $mWidth = (float)$markerSpec['width'];
            $mAlign = strtolower($markerSpec['align'] ?? 'right');
            $mGap = (float)$markerSpec['gap'];
            $measured = $this->textRenderer->measureTextStyled($mText, $this->styleManager);
            $boxRight = $baseX + $actualIndent;
            $boxLeft = $boxRight - max($mWidth, $measured + $mGap);
            $mx = ($mAlign === 'right') ? $boxRight - $measured - $mGap : $boxLeft;
            $this->textRenderer->writeTextLine($mx, $baselineY, $mText, $this->styleManager, null);
            $this->styleManager->pop();
        }

        foreach ($renderTokens as $tok) {
            if ($tok['type'] === 'inline') {
                $tok['renderer']($x, $baselineY);
                $x += $this->measureTokenWidth($tok);
                continue;
            }
            $this->styleManager->push();
            $opt = $tok['opt'] ?? [];
            $this->styleManager->applyOptions($opt, $this);

            $shadow = $this->normalizeShadowSpec($opt['textShadow'] ?? null);
            $runBG = $this->normalizeColor($opt['bgcolor'] ?? null);
            $href = $opt['href'] ?? null;
            $isSub = !empty($opt['sub']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sub');
            $isSup = !empty($opt['sup']) || (isset($opt['script']) && strtolower((string)$opt['script']) === 'sup');
            $scale = isset($opt['sizeScale']) ? (float)$opt['sizeScale'] : (($isSub || $isSup) ? 0.75 : 1.0);

            if (abs($scale - 1.0) > 1e-6) {
                $this->styleManager->setFont(
                    $this->styleManager->getCurrentFontAlias(),
                    $this->styleManager->getCurrentFontSize() * $scale
                );
            }

            $dy = isset($opt['baselineShift'])
                ? (float)$opt['baselineShift']
                : ($isSup ? ($lineH * 0.35) : ($isSub ? - ($lineH * 0.15) : 0.0));

            $tokWidth = $this->textRenderer->measureTextStyled($tok['text'], $this->styleManager);
            if ($runBG !== null) {
                $this->drawBackgroundRect(
                    $x,
                    $baselineY + $dy - ($this->styleManager->getCurrentFontSize() * 0.25),
                    $tokWidth,
                    $this->styleManager->getLineHeight(),
                    $runBG
                );
            }

            $this->textRenderer->writeTextLine($x, $baselineY + $dy, $tok['text'], $this->styleManager, $shadow);

            if ($href !== null) {
                $linkHeight = $this->styleManager->getLineHeight();
                $linkY = ($baselineY + $dy) - ($linkHeight * 0.25);
                $this->addLinkAbs($x, $linkY, $tokWidth, $linkHeight, $href);
            }

            $x += $tokWidth;
            if ($tok['type'] === 'space') {
                $x += $this->styleManager->getWordSpacing();
                if ($extraPerGap > 0.0) {
                    $x += $extraPerGap;
                }
            }

            $this->styleManager->pop();
        }

        $this->layoutManager->advanceCursor($lineH);
        $this->layoutManager->checkPageBreak();
    }

    public function addTable($dataOrOptions = null, array $options = [], ?float $adjustedWidth = null): ?PdfTableBuilder
    {
        if ($dataOrOptions === null || (is_array($dataOrOptions) && empty($options) && !isset($dataOrOptions[0]))) {
            return new PdfTableBuilder($this, $dataOrOptions ?? []);
        }
        if (is_array($dataOrOptions)) {
            $this->addTableData($dataOrOptions, $options);
            return null;
        }
        throw new \InvalidArgumentException(
            "addTable(): use (array \$data, array \$options=[]) or (array \$tableOptions) for builder."
        );
    }

    public function addTableData(array $data, array $options = [], ?float $adjustedWidth = null): void
    {
        $this->tableManager->addTableData($data, $options);
    }













    public function addSpacer(float $h): void
    {
        $this->layoutManager->advanceCursor($h);
        $this->layoutManager->checkPageBreak();
    }

    public function addTextAbs(float $x, float $y, string $text, $color = null, array $opts = []): void
    {
        $this->styleManager->push();

        if ($color !== null) $this->styleManager->setTextColor($this->normalizeColor($color));
        $this->styleManager->applyOptions($opts, $this);

        $bgColor = $this->normalizeColor($opts['bgcolor'] ?? null);
        $shadowSpec = $this->normalizeShadowSpec($opts['textShadow'] ?? null);

        if ($bgColor !== null) {
            $textWidth = $this->textRenderer->measureTextStyled($text, $this->styleManager);
            $rectY = $y - ($this->styleManager->getCurrentFontSize() * 0.25);
            $this->drawBackgroundRect($x, $rectY, $textWidth, $this->styleManager->getLineHeight(), $bgColor);
        }
        $this->textRenderer->writeTextLine($x, $y, $text, $this->styleManager, $shadowSpec);

        $this->styleManager->pop();
    }

    public function drawImage(string $alias, float $x, float $y, float $w, ?float $h = null, array $opts = []): void
    {
        if ($this->currentPage === null) return;
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");
        if ($img['w'] <= 0 || $img['h'] <= 0) return;
        if ($h === null) $h = $w * ($img['h'] / $img['w']);

        $ops = "q\n";
        if (isset($opts['alpha']) && (float)$opts['alpha'] < 1.0) {
            [$gsName, $gsId] = $this->getExtGStateManager()->ensureAlphaRef((float)$opts['alpha']);
            $this->registerPageResource('ExtGState', $gsName, $gsId);
            $ops .= "{$gsName} gs\n";
        }
        $ops .= sprintf("%.3F 0 0 %.3F %.3F %.3F cm\n", $w, $h, $x, $y);
        $ops .= $img['name'] . " Do\nQ\n";

        $this->appendToPageContent($ops);
        $this->registerPageResource('XObject', $img['name'], $img['objId']);
    }

    public function addImageBlock(string $alias, array $opts = []): void
    {

        $img = $this->imageManager->getImage($alias);

        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de addImageBlock().");
        }
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");

        $iw = (float)$img['w'];
        $ih = (float)$img['h'];
        $borderSpec = $this->borderManager->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $bgColor = $this->normalizeColor($opts['bgcolor'] ?? null);
        $align = strtolower($opts['align'] ?? 'left');
        $spacing = (float)($opts['spacing'] ?? 0.0);
        $alpha = isset($opts['alpha']) ? max(0.0, min(1.0, (float)$opts['alpha'])) : null;
        $baseX = $this->mLeft + $padding[3];
        $wrapWidth = $this->layoutManager->getContentAreaWidth() - $padding[1] - $padding[3];
        $tw = $opts['w'] ?? null;
        $th = $opts['h'] ?? null;
        $maxW = $opts['maxW'] ?? $wrapWidth;
        $maxH = $opts['maxH'] ?? ($this->getCursorY() - $this->getPageBottomMargin() - $padding[2] - 1);

        if ($tw === null && $th === null) {
            $tw = $maxW;
            $th = $tw * ($ih / $iw);
            if ($th > $maxH) {
                $th = $maxH;
                $tw = $th * ($iw / $ih);
            }
        } elseif ($tw !== null && $th === null) {
            $tw = (float)$tw;
            $th = $tw * ($ih / $iw);
        } elseif ($tw === null && $th !== null) {
            $th = (float)$th;
            $tw = $th * ($iw / $ih);
        } else {
            $tw = (float)$tw;
            $th = (float)$th;
        }

        $expected = isset($opts['maxW']) ? (float)$opts['maxW'] : $wrapWidth;
        if ($expected > 0 && abs($tw) > 0 && abs($tw) < 10 && $expected >= 50) {
            $scale = $expected / abs($tw);
            $tw *= $scale;
            $th *= $scale;
        }

        $tw = abs($tw);
        $th = abs($th);

        $totalH = $padding[0] + $th + $padding[2];
        $this->layoutManager->checkPageBreak($totalH);
        $yTop = $this->layoutManager->getCursorY() - $padding[0];
        $yImg = $yTop - $th;
        $xImg = $baseX;
        if ($align === 'center') $xImg = $baseX + ($wrapWidth - $tw) / 2.0;
        elseif ($align === 'right') $xImg = $baseX + ($wrapWidth - $tw);

        if ($bgColor !== null) {
            $r = $borderSpec['radius'] ?? [0, 0, 0, 0];
            $hasRadius = max($r) > 1e-4;
            $boxX = $this->mLeft;
            $boxY = $yTop - $th - $padding[2];
            $boxW = $this->layoutManager->getContentAreaWidth();
            if ($hasRadius) $this->drawRoundedBackgroundRect($boxX, $boxY, $boxW, $totalH, $r, $bgColor);
            else $this->drawBackgroundRect($boxX, $boxY, $boxW, $totalH, $bgColor);
        }

        $this->drawImage($alias, $xImg, $yImg, $tw, $th, $alpha !== null ? ['alpha' => $alpha] : []);

        if ($borderSpec['hasBorder']) {
            $paddedBox = ['x' => $this->mLeft, 'y' => $yTop - $th - $padding[2], 'w' => $this->layoutManager->getContentAreaWidth(), 'h' => $totalH];
            $this->drawParagraphBorders($paddedBox, $borderSpec);
        }

        $this->layoutManager->setCursorY($yTop - $th - $padding[2]);
        if ($spacing > 0) {
            $this->layoutManager->advanceCursor($spacing);
            $this->layoutManager->checkPageBreak();
        }
    }

    public function buildBackgroundRectOps(float $x, float $y, float $w, float $h, array $color): string
    {
        return $this->graphicsRenderer->buildBackgroundRectOps($x, $y, $w, $h, $color);
    }

    public function drawBackgroundRect(float $x, float $y, float $width, float $height, array $color): void
    {
        $this->graphicsRenderer->drawBackgroundRect($x, $y, $width, $height, $color);
    }

    private function clampCornerRadii(float $w, float $h, array $r): array
    {
        $r = array_map('floatval', $r);
        for ($i = 0; $i < 4; $i++) $r[$i] = max(0.0, min($r[$i], min($w, $h) * 0.5));
        if (($sum = $r[0] + $r[1]) > $w) {
            $r[0] *= $w / $sum;
            $r[1] *= $w / $sum;
        }
        if (($sum = $r[3] + $r[2]) > $w) {
            $r[3] *= $w / $sum;
            $r[2] *= $w / $sum;
        }
        if (($sum = $r[0] + $r[3]) > $h) {
            $r[0] *= $h / $sum;
            $r[3] *= $h / $sum;
        }
        if (($sum = $r[1] + $r[2]) > $h) {
            $r[1] *= $h / $sum;
            $r[2] *= $h / $sum;
        }
        return $r;
    }

    public function buildRoundedRectPath(float $x, float $y, float $w, float $h, array $r): string
    {
        $r = $this->clampCornerRadii($w, $h, $r);
        [$rtl, $rtr, $rbr, $rbl] = $r;
        $K = 0.55228474983;
        $path = sprintf('%.3F %.3F m', $x + $rtl, $y + $h);
        $path .= sprintf(' %.3F %.3F l', $x + $w - $rtr, $y + $h);
        if ($rtr > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $w - $rtr * (1 - $K), $y + $h, $x + $w, $y + $h - $rtr * (1 - $K), $x + $w, $y + $h - $rtr);
        $path .= sprintf(' %.3F %.3F l', $x + $w, $y + $rbr);
        if ($rbr > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $w, $y + $rbr * (1 - $K), $x + $w - $rbr * (1 - $K), $y, $x + $w - $rbr, $y);
        $path .= sprintf(' %.3F %.3F l', $x + $rbl, $y);
        if ($rbl > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $rbl * (1 - $K), $y, $x, $y + $rbl * (1 - $K), $x, $y + $rbl);
        $path .= sprintf(' %.3F %.3F l', $x, $y + $h - $rtl);
        if ($rtl > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x, $y + $h - $rtl * (1 - $K), $x + $rtl * (1 - $K), $y + $h, $x + $rtl, $y + $h);
        $path .= " h\n";
        return $path;
    }

    public function buildRoundedBackgroundRectOps(float $x, float $y, float $w, float $h, array $r, array $color): string
    {
        return $this->graphicsRenderer->buildRoundedBackgroundRectOps($x, $y, $w, $h, $r, $color);
    }

    public function drawRoundedBackgroundRect(float $x, float $y, float $w, float $h, array $r, array $color): void
    {
        $this->graphicsRenderer->drawRoundedBackgroundRect($x, $y, $w, $h, $r, $color);
    }



    private function buildImageOps(string $alias, float $x, float $y, float $w, float $h, ?array $opts = null): string
    {
        if ($this->currentPage === null) return '';
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");

        $ops = "q\n";
        if (isset($opts['alpha']) && (float)$opts['alpha'] < 1.0) {
            [$gsName, $gsId] = $this->getExtGStateManager()->ensureAlphaRef(
                max(0.0, min(1.0, (float)$opts['alpha']))
            );
            $this->registerPageResource('ExtGState', $gsName, $gsId);
            $ops .= "{$gsName} gs\n";
        }
        $ops .= sprintf("%.3F 0 0 %.3F %.3F %.3F cm\n", $w, $h, $x, $y);
        $ops .= $img['name'] . " Do\nQ\n";
        $this->registerPageResource('XObject', $img['name'], $img['objId']);
        return $ops;
    }

    public function insertOpsAt(string $ops, int $at): void
    {
        if ($this->currentPage === null || $ops === '') return;
        $buf = $this->pageContents[$this->currentPage] ?? '';
        $this->pageContents[$this->currentPage] = substr($buf, 0, $at) . $ops . substr($buf, $at);
    }

    private function fitImageInBox(float $imgW, float $imgH, float $boxX, float $boxY, float $boxW, float $boxH, array $opts): array
    {
        $mode = strtolower($opts['mode'] ?? 'cover');
        $align = strtolower($opts['align'] ?? 'center');
        $valign = strtolower($opts['valign'] ?? 'middle');
        $offX = (float)($opts['offsetX'] ?? 0.0);
        $offY = (float)($opts['offsetY'] ?? 0.0);

        if (isset($opts['size'])) {
            $tw = (float)($opts['size']['w'] ?? 0.0);
            $th = (float)($opts['size']['h'] ?? 0.0);
            if ($tw > 0 && $th <= 0) $th = $tw * ($imgH / $imgW);
            if ($th > 0 && $tw <= 0) $tw = $th * ($imgW / $imgH);
            if ($tw > 0 && $th > 0) {
                $x = match ($align) {
                    'left' => $boxX,
                    'right' => $boxX + $boxW - $tw,
                    default => $boxX + ($boxW - $tw) / 2
                };
                $y = match ($valign) {
                    'top' => $boxY + $boxH - $th,
                    'bottom' => $boxY,
                    default => $boxY + ($boxH - $th) / 2
                };
                return [$x + $offX, $y + $offY, $tw, $th];
            }
        }
        if ($mode === 'stretch') return [$boxX + $offX, $boxY + $offY, $boxW, $boxH];

        $scale = 1.0;
        if ($mode === 'contain') $scale = min($boxW / $imgW, $boxH / $imgH);
        elseif ($mode === 'cover') $scale = max($boxW / $imgW, $boxH / $imgH);

        $tw = $imgW * $scale;
        $th = $imgH * $scale;
        $x = match ($align) {
            'left' => $boxX,
            'right' => $boxX + $boxW - $tw,
            default => $boxX + ($boxW - $tw) / 2
        };
        $y = match ($valign) {
            'top' => $boxY + $boxH - $th,
            'bottom' => $boxY,
            default => $boxY + ($boxH - $th) / 2
        };
        return [$x + $offX, $y + $offY, $tw, $th];
    }

    public function drawBackgroundImageInRect(string $alias, float $x, float $y, float $w, float $h, array $opts = [], ?int $insertAt = null): void
    {
        $this->graphicsRenderer->drawBackgroundImageInRect($alias, $x, $y, $w, $h, $opts, $insertAt);
    }

    public function normalizeColor($color): ?array
    {
        return $this->colorManager->normalize($color);
    }

    public function registerFixedElement(array $elements, array $options, float $x, float $y): void
    {
        $this->fixedElementManager->add($elements, $options, $x, $y);
    }

    public function enterMeasurementMode(): void
    {
        $this->measurementManager->enterMeasurementMode();
    }

    public function exitMeasurementMode(): void
    {
        $this->measurementManager->exitMeasurementMode();
    }

    public function measureBlockHeight(array $elements, array $options): float
    {
        return $this->measurementManager->measureBlockHeight($elements, $options, $this);
    }

    public function internal_newPage(): void
    {
        $pageId = $this->newObjectId();
        $this->pages[] = $pageId;
        $this->pageContents[$pageId] = '';
        $this->pageResources[$pageId] = ['Font' => [], 'ExtGState' => [], 'XObject' => [], 'Shading' => []];
        $this->currentPage = $pageId;

        $this->fixedElementManager->renderAll();
    }

    public function output(): string
    {
        foreach ($this->pageAnnotations as $pageId => $annotations) {
            foreach ($annotations as $annot) {
                $rect = sprintf("[%.3F %.3F %.3F %.3F]", $annot['rect'][0], $annot['rect'][1], $annot['rect'][2], $annot['rect'][3]);
                $this->writer->setObject($annot['id'], "<< /Type /Annot /Subtype /Link /Rect {$rect} /Border [0 0 0] /A {$annot['action']} 0 R >>");
            }
        }

        $type0Ids = $this->fontManager->emitFontObjects();
        $pagesId = $this->writer->newObjectId();

        foreach ($this->pages as $pid) {
            $contentId = $this->writer->newObjectId();
            $this->writer->setObject($contentId, PdfStreamBuilder::streamObj($this->pageContents[$pid] ?? ''));

            $fontPairs = [];
            foreach ($this->pageResources[$pid]['Font'] as $label => $_) {
                if (isset($type0Ids[ltrim($label, '/')])) $fontPairs[] = "{$label} {$type0Ids[ltrim($label, '/')]} 0 R";
            }
            $gsPairs = [];
            foreach ($this->pageResources[$pid]['ExtGState'] as $label => $objId) {
                if (is_int($objId) && $objId > 0) {
                    $gsPairs[] = "{$label} {$objId} 0 R";
                }
            }
            $xoPairs = [];
            if (isset($this->pageResources[$pid]['XObject'])) {
                foreach ($this->pageResources[$pid]['XObject'] as $label => $objId) $xoPairs[] = "{$label} {$objId} 0 R";
            }
            $shPairs = [];
            if (isset($this->pageResources[$pid]['Shading'])) {
                foreach ($this->pageResources[$pid]['Shading'] as $label => $objId) {
                    $shPairs[] = "{$label} {$objId} 0 R";
                }
            }

            $resParts = [];
            if (!empty($fontPairs)) $resParts[] = "/Font << " . implode(' ', $fontPairs) . " >>";
            if (!empty($gsPairs)) $resParts[] = "/ExtGState << " . implode(' ', $gsPairs) . " >>";
            if (!empty($xoPairs)) $resParts[] = "/XObject << " . implode(' ', $xoPairs) . " >>";
            if (!empty($shPairs))   $resParts[] = "/Shading << " . implode(' ', $shPairs) . " >>";
            $resources = empty($resParts) ? "<< >>" : "<< " . implode(' ', $resParts) . " >>";

            $annotRefs = [];
            if (isset($this->pageAnnotations[$pid])) {
                foreach ($this->pageAnnotations[$pid] as $annot) $annotRefs[] = "{$annot['id']} 0 R";
            }
            $annotsStr = !empty($annotRefs) ? "/Annots [" . implode(' ', $annotRefs) . "]" : "";

            $this->writer->setObject($pid, "<< /Type /Page /Parent {$pagesId} 0 R /Resources {$resources} /Contents {$contentId} 0 R {$annotsStr} >>");
        }

        $w = $this->getPageWidth();
        $h = $this->getPageHeight();
        $kids = array_map(fn($pid) => "{$pid} 0 R", $this->pages);
        $this->writer->setObject(
            $pagesId,
            "<< /Type /Pages /Kids [ " . implode(' ', $kids) . " ] /Count " . count($kids) . " /MediaBox [0 0 {$w} {$h}] >>"
        );

        $catalogId = $this->writer->newObjectId();
        $this->writer->setObject($catalogId, "<< /Type /Catalog /Pages {$pagesId} 0 R >>");

        return $this->writer->output($catalogId);
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->output());
    }

    public function newObjectId(): int
    {
        return $this->writer->newObjectId();
    }

    private function normalizeShadowSpec($spec): ?array
    {
        if (!is_array($spec)) return null;
        return [
            'dx' => (float)($spec['dx'] ?? 0.6),
            'dy' => (float)($spec['dy'] ?? -0.6),
            'alpha' => max(0.0, min(1.0, (float)($spec['alpha'] ?? 0.35))),
            'blur' => max(0.0, (float)($spec['blur'] ?? 0.0)),
            'samples' => max(1, (int)($spec['samples'] ?? 8)),
            'color' => $this->normalizeColor($spec['color'] ?? ['gray' => 0.0]) ?? ['space' => 'gray', 'v' => [0.0]]
        ];
    }



    public function drawParagraphBorders(array $box, array $spec): void
    {
        $this->graphicsRenderer->drawParagraphBorders($box, $spec);
    }

    public function addHorizontalLine(array $options = []): void
    {
        $opts = array_merge(['width' => '100%', 'height' => 0.5, 'color' => ['gray' => 0.5], 'style' => 'solid', 'align' => 'center', 'spacing' => 5.0, 'dash' => null], $options);
        $availableWidth = $this->layoutManager->getContentAreaWidth();
        $lineWidth = is_string($opts['width']) && str_ends_with($opts['width'], '%') ? $availableWidth * (floatval(rtrim($opts['width'], '%')) / 100) : (is_numeric($opts['width']) ? min((float)$opts['width'], $availableWidth) : $availableWidth);
        $x = match ($opts['align']) {
            'center' => $this->mLeft + ($availableWidth - $lineWidth) / 2,
            'right' => $this->mLeft + $availableWidth - $lineWidth,
            default => $this->mLeft,
        };
        $this->layoutManager->advanceCursor($opts['spacing']);
        $this->layoutManager->checkPageBreak($opts['height']);
        $this->drawHorizontalLineAt($x, $this->layoutManager->getCursorY(), $lineWidth, $opts);
        $this->layoutManager->advanceCursor($opts['height'] + $opts['spacing']);
        $this->layoutManager->checkPageBreak();
    }

    public function addHorizontalLineAbs(float $x, float $y, float $width, array $options = []): void
    {
        $opts = array_merge(['height' => 0.5, 'color' => ['gray' => 0.5], 'style' => 'solid', 'dash' => null], $options);
        $this->drawHorizontalLineAt($x, $y, $width, $opts);
    }

    private function drawHorizontalLineAt(float $x, float $y, float $width, array $opts): void
    {
        $this->graphicsRenderer->drawHorizontalLineAt($x, $y, $width, $opts);
    }

    public function addSeparator(array $options = []): void
    {
        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar um separador.");
        }
        $opts = array_merge(['symbol' => '◆', 'symbolSize' => null, 'symbolColor' => null, 'lineWidth' => '30%', 'lineHeight' => 0.5, 'lineColor' => ['gray' => 0.5], 'lineStyle' => 'solid', 'spacing' => 10.0, 'gap' => 10.0], $options);
        $availableWidth = $this->layoutManager->getContentAreaWidth();
        $lineWidth = is_string($opts['lineWidth']) && str_ends_with($opts['lineWidth'], '%') ? $availableWidth * (floatval(rtrim($opts['lineWidth'], '%')) / 100) : (float)$opts['lineWidth'];

        $this->styleManager->push();
        if ($opts['symbolSize'] !== null) $this->styleManager->setFont($this->styleManager->getCurrentFontAlias(), (float)$opts['symbolSize']);
        if ($opts['symbolColor'] !== null) $this->styleManager->setTextColor($this->normalizeColor($opts['symbolColor']));

        $symbolWidth = $this->textRenderer->measureTextStyled($opts['symbol'], $this->styleManager);
        $centerX = $this->mLeft + $availableWidth / 2;
        $leftLineX = $centerX - $opts['gap'] - $symbolWidth / 2 - $lineWidth;
        $rightLineX = $centerX + $opts['gap'] + $symbolWidth / 2;

        $this->layoutManager->advanceCursor($opts['spacing']);
        $this->layoutManager->checkPageBreak($this->styleManager->getLineHeight());

        $cursorY = $this->layoutManager->getCursorY();
        $lineY = $cursorY - $this->styleManager->getLineHeight() / 2;
        $lineOpts = ['height' => $opts['lineHeight'], 'color' => $opts['lineColor'], 'style' => $opts['lineStyle']];
        $this->drawHorizontalLineAt($leftLineX, $lineY, $lineWidth, $lineOpts);

        if ($opts['symbol'] !== '') {
            $this->textRenderer->writeTextLine($centerX - $symbolWidth / 2, $cursorY, $opts['symbol'], $this->styleManager, null);
        }

        $this->drawHorizontalLineAt($rightLineX, $lineY, $lineWidth, $lineOpts);

        $cursorYDrop = $this->styleManager->getLineHeight() + $opts['spacing'];
        $this->styleManager->pop();

        $this->layoutManager->advanceCursor($cursorYDrop);
        $this->layoutManager->checkPageBreak();
    }

    public function addBlock(array $options = []): PdfBlockBuilder
    {
        return new PdfBlockBuilder($this, $options);
    }

    public function addColumns(array $columns, array $options = []): void
    {
        $this->columnLayoutManager->addColumns($columns, $options);
    }



    public function normalizePadding($padding): array
    {
        return $this->borderManager->normalizePadding($padding);
    }

    public function writeOps(string $ops): void
    {
        if ($this->currentPage === null) return;
        $this->pageContents[$this->currentPage] .= $ops;
    }

    public function addImage(string $alias, string $filePath): void
    {
        $this->imageManager->addImage($alias, $filePath);
    }

    public function addImageData(string $alias, string $data, ?string $hint = null): void
    {
        $this->imageManager->addImageData($alias, $data, $hint);
    }

    private function bootstrapDefaultFont(): void
    {
        $candidates = [
            __DIR__ . '/../../fonts/NotoSans-Regular.ttf',
            __DIR__ . '/../../fonts/DejaVuSans.ttf',
            __DIR__ . '/../../fonts/Roboto-Regular.ttf',
        ];

        foreach ($candidates as $ttf) {
            if (is_string($ttf) && is_file($ttf)) {
                try {
                    $this->addTTFFont('PagyraDefault', $ttf);
                    $this->setFont('PagyraDefault', 12.0);
                    return;
                } catch (\Throwable $e) {
                    // ignora e tenta próxima
                }
            }
        }
    }
}
