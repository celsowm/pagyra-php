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
use Celsowm\PagyraPhp\Style\PdfStyleManager;
use Celsowm\PagyraPhp\Graphics\State\PdfExtGStateManager;
use Celsowm\PagyraPhp\Font\PdfFontManager;
use Celsowm\PagyraPhp\Table\PdfTableBuilder;
use Celsowm\PagyraPhp\Text\PdfTextRenderer;
use Celsowm\PagyraPhp\Writer\PdfWriter;

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
    
    private array $fixedElements = [];

    private array $baseMargins = ['left' => 56.0, 'top' => 56.0, 'right' => 56.0, 'bottom' => 56.0];

    private bool $pageHeaderDefined = false;
    private float $pageHeaderHeight = 0.0;
    private float $pageHeaderOffset = 0.0;
    private float $pageHeaderTop = 0.0;
    private float $pageHeaderSpacing = 0.0;
    private bool $pageHeaderPushesContent = false;
    private bool $pageFooterDefined = false;
    private float $pageFooterHeight = 0.0;
    private float $pageFooterBottom = 0.0;
    private float $pageFooterSpacing = 0.0;
    private bool $pageFooterPushesContent = false;
    private float $pageFooterOffset = 0.0;
    private int $pageHeaderContentLength = 0;
    private int $pageBreakSuppression = 0;

    private bool $measurementMode = false;
    private int $measurementDepth = 0;


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

        $this->setMargins(56, 56, 56, 56);
        $this->internal_newPage();
        $this->bootstrapDefaultFont();
    }

    public function getFontManager(): PdfFontManager
    {
        return $this->fontManager;
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

    public function isMeasurementMode(): bool
    {
        return $this->measurementMode;
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
        if ($this->measurementMode || $this->currentPage === null) {
            return;
        }
        $this->pageContents[$this->currentPage] .= $ops;
    }

    public function registerPageResource(string $type, string $label, ?int $value = 0): void
    {
        if ($this->measurementMode || $this->currentPage === null) {
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
        if ($this->measurementMode || $this->currentPage === null) {
            return;
        }
        $annotId = $this->newObjectId();
        $actionId = $this->getOrCreateUriAction($url);
        if (!isset($this->pageAnnotations[$this->currentPage])) {
            $this->pageAnnotations[$this->currentPage] = [];
        }
        $this->pageAnnotations[$this->currentPage][] = [
            'id' => $annotId,
            'rect' => [$x, $y, $x + $width, $y + $height],
            'action' => $actionId
        ];
    }

    private function getOrCreateUriAction(string $url): int
    {
        $urlHash = md5($url);
        if (!isset($this->uriActions[$urlHash])) {
            $actionId = $this->newObjectId();
            $this->uriActions[$urlHash] = $actionId;
            $escapedUrl = str_replace(
                ['\\', '(', ')'],
                ['\\\\', '\\(', '\\)'],
                $url
            );
            $this->setObject($actionId, "<< /Type /Action /S /URI /URI ({$escapedUrl}) >>");
        }
        return $this->uriActions[$urlHash];
    }

    public function addLink(string $text, string $url, array $opts = []): void
    {
        $opts['href'] = $url;
        $opts['color'] = $opts['color'] ?? '#0000FF';
        $opts['style'] = $opts['style'] ?? 'U';
        $this->addParagraphText($text, $opts);
    }

    public function addLinkTextAbs(float $x, float $y, string $text, string $url, array $opts = []): void
    {
        $opts['style'] = $opts['style'] ?? 'U';
        $opts['color'] = $opts['color'] ?? '#0000FF';

        $this->styleManager->push();
        $this->styleManager->applyOptions($opts, $this);

        $this->addTextAbs($x, $y, $text, $opts['color'], $opts);
        $textWidth = $this->textRenderer->measureTextStyled($text, $this->styleManager);
        $linkHeight = $this->styleManager->getLineHeight();

        $this->styleManager->pop();

        $linkY = $y - ($linkHeight * 0.25);
        $this->addLinkAbs($x, $linkY, $textWidth, $linkHeight, $url);
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

        if ($this->pageHeaderDefined && $this->pageHeaderPushesContent) {
            $this->pageHeaderOffset = max(
                $this->baseMargins['top'],
                $this->pageHeaderTop + $this->pageHeaderHeight + $this->pageHeaderSpacing
            );
            $this->layoutManager->setCursorY($this->getPageHeight() - $this->pageHeaderOffset);
        }

        $desiredBottomMargin = $this->baseMargins['bottom'];

        if ($this->pageFooterDefined) {
            if ($this->pageFooterPushesContent) {
                $this->pageFooterOffset = max(
                    $desiredBottomMargin,
                    $this->pageFooterBottom + $this->pageFooterHeight + $this->pageFooterSpacing
                );
                $desiredBottomMargin = $this->pageFooterOffset;
            } else {
                $this->pageFooterOffset = $desiredBottomMargin;
            }
        }

        $this->layoutManager->updateBaseBottomMargin($desiredBottomMargin);
    }

    public function setHeader(callable $callback, array $options = []): void
    {
        if ($this->pageHeaderDefined) {
            throw new \LogicException('Page header already defined.');
        }
        if ($this->measurementMode || $this->currentPage === null) {
            throw new \LogicException('No active page to attach header.');
        }

        $existingContent = $this->pageContents[$this->currentPage] ?? '';
        if (trim($existingContent) !== '') {
            throw new \LogicException('Call setHeader() before adding content to the first page.');
        }

        $pushContent = !isset($options['pushContent']) || (bool)$options['pushContent'];
        $spacing = isset($options['contentSpacing']) ? max(0.0, (float)$options['contentSpacing']) : 6.0;
        $x = isset($options['x']) ? (float)$options['x'] : $this->baseMargins['left'];
        $y = isset($options['y']) ? max(0.0, (float)$options['y']) : 0.0;

        $blockOptions = $options;
        unset($blockOptions['pushContent'], $blockOptions['contentSpacing']);
        $blockOptions['position'] = 'fixed';
        $blockOptions['x'] = $x;
        $blockOptions['y'] = $y;
        if (!isset($blockOptions['width'])) {
            $blockOptions['width'] = '100%';
        }

        $builder = new PdfBlockBuilder($this, $blockOptions);
        $callback($builder);
        $definition = $builder->getDefinition();
        if (empty($definition['elements'])) {
            return;
        }

        $renderer = new PdfBlockRenderer($this);
        $height = $renderer->render($definition['elements'], $definition['options']);

        if ($this->currentPage !== null) {
            $this->pageHeaderContentLength = strlen($this->pageContents[$this->currentPage] ?? '');
        }

        $this->pageHeaderDefined = true;
        $this->pageHeaderHeight = $height;
        $this->pageHeaderTop = $y;
        $this->pageHeaderSpacing = $pushContent ? $spacing : 0.0;
        $this->pageHeaderPushesContent = $pushContent;
        $this->pageHeaderOffset = $pushContent
            ? max($this->baseMargins['top'], $y + $height + $spacing)
            : $this->baseMargins['top'];

        if ($pushContent) {
            $this->setCursorY($this->getPageHeight() - $this->pageHeaderOffset);
        }
    }

    public function getContentTopOffset(): float
    {
        if ($this->pageHeaderDefined && $this->pageHeaderPushesContent) {
            return $this->pageHeaderOffset;
        }
        return $this->baseMargins['top'];
    }

    public function setFooter(callable $callback, array $options = []): void
    {
        if ($this->pageFooterDefined) {
            throw new \LogicException('Page footer already defined.');
        }
        if ($this->measurementMode || $this->currentPage === null) {
            throw new \LogicException('No active page to attach footer.');
        }

        $existingContent = $this->pageContents[$this->currentPage] ?? '';
        if (trim($existingContent) !== '') {
            $contentLength = strlen($existingContent);
            $headerLength = $this->pageHeaderContentLength;
            $headerOnlyContent = ($headerLength > 0 && $contentLength === $headerLength);
            if (!$headerOnlyContent) {
                throw new \LogicException('Call setFooter() before adding content to the first page.');
            }
        }

        $pushContent = !isset($options['pushContent']) || (bool)$options['pushContent'];
        $spacing = isset($options['contentSpacing']) ? max(0.0, (float)$options['contentSpacing']) : 6.0;
        $x = isset($options['x']) ? (float)$options['x'] : $this->baseMargins['left'];
        $bottomOffset = isset($options['bottom']) ? max(0.0, (float)$options['bottom']) : $this->baseMargins['bottom'];
        $explicitY = array_key_exists('y', $options) ? (float)$options['y'] : null;

        $blockOptions = $options;
        unset($blockOptions['pushContent'], $blockOptions['contentSpacing'], $blockOptions['bottom']);
        $blockOptions['position'] = 'fixed';
        $blockOptions['x'] = $x;
        if (!isset($blockOptions['width'])) {
            $blockOptions['width'] = '100%';
        }

        $builder = new PdfBlockBuilder($this, $blockOptions);
        $callback($builder);
        $definition = $builder->getDefinition();
        if (empty($definition['elements'])) {
            return;
        }

        $measureOptions = $definition['options'];
        $measureOptions['position'] = 'relative';
        unset($measureOptions['x'], $measureOptions['y']);
        $height = $this->measureBlockHeight($definition['elements'], $measureOptions);

        if ($explicitY !== null) {
            $renderY = $explicitY;
            $footerBottom = max(0.0, $this->getPageHeight() - $renderY - $height);
        } else {
            $footerBottom = $bottomOffset;
            $topFromBottom = $footerBottom + $height;
            $renderY = max(0.0, $this->getPageHeight() - $topFromBottom);
        }

        $renderOptions = $definition['options'];
        $renderOptions['position'] = 'fixed';
        $renderOptions['x'] = $x;
        $renderOptions['y'] = $renderY;
        if (!isset($renderOptions['width'])) {
            $renderOptions['width'] = '100%';
        }

        $renderer = new PdfBlockRenderer($this);
        $renderedHeight = $renderer->render($definition['elements'], $renderOptions);

        $this->pageFooterDefined = true;
        $this->pageFooterHeight = $renderedHeight;
        $this->pageFooterBottom = $footerBottom;
        $this->pageFooterSpacing = $pushContent ? $spacing : 0.0;
        $this->pageFooterPushesContent = $pushContent;
        $this->pageFooterOffset = $pushContent
            ? max($this->baseMargins['bottom'], $footerBottom + $renderedHeight + $spacing)
            : $this->baseMargins['bottom'];

        if ($pushContent) {
            $this->layoutManager->updateBaseBottomMargin($this->pageFooterOffset);
        }
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

    public function addListItem(string|array $runsOuString, array $opts = []): void
    {
        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de addList/addListItem().");
        }
        $align = $opts['align'] ?? 'left';
        $baseIndent = (float)($opts['indent'] ?? 0.0);
        $gap = (float)($opts['gap'] ?? 6.0);
        $itemSpacing = (float)($opts['itemSpacing'] ?? 2.0);
        $lineHeight = (float)($opts['lineHeight'] ?? $this->styleManager->getLineHeight());
        $markerText = (string)($opts['listMarker']['text'] ?? ($opts['bulletChar'] ?? '•'));
        $markerFont = $opts['listMarker']['fontAlias'] ?? $this->styleManager->getCurrentFontAlias();
        $markerSize = (float)($opts['listMarker']['size'] ?? $this->styleManager->getCurrentFontSize());
        $markerStyle = (string)($opts['listMarker']['style'] ?? '');
        $markerColor = $opts['listMarker']['color'] ?? null;
        $markerAlign = strtolower($opts['listMarker']['align'] ?? 'right');
        $markerWidth = (float)($opts['listMarker']['width'] ?? 0.0);

        if ($markerWidth <= 0.0) {
            $this->styleManager->push();
            $this->styleManager->setFont($markerFont, $markerSize)->setTextSpacing(0.0, 0.0);
            $markerMeasured = $this->textRenderer->measureTextStyled($markerText, $this->styleManager);
            $this->styleManager->pop();
            $markerWidth = $markerMeasured + $gap;
        }

        $indentTotal = $baseIndent + $markerWidth;
        $runs = [];
        if (is_string($runsOuString)) {
            $runs = [new PdfRun($runsOuString, [])];
        } elseif (is_array($runsOuString)) {
            foreach ($runsOuString as $r) {
                if ($r instanceof PdfRun) $runs[] = $r;
                elseif (is_array($r)) $runs[] = new PdfRun($r['text'] ?? '', $r['options'] ?? []);
                elseif (is_string($r)) $runs[] = new PdfRun($r, []);
            }
        }
        $parOpts = [
            'align' => $align,
            'lineHeight' => $lineHeight,
            'spacing' => $itemSpacing,
            'indent' => $indentTotal,
            'hangIndent' => $indentTotal,
            'listMarker' => [
                'text' => $markerText,
                'width' => $markerWidth,
                'gap' => $gap,
                'align' => $markerAlign,
                'fontAlias' => $markerFont,
                'size' => $markerSize,
                'style' => $markerStyle,
                'color' => $markerColor
            ],
        ];
        if (isset($opts['bgcolor'])) $parOpts['bgcolor'] = $opts['bgcolor'];
        if (isset($opts['border'])) $parOpts['border'] = $opts['border'];
        if (isset($opts['padding'])) $parOpts['padding'] = $opts['padding'];
        $this->addParagraphRuns($runs, $parOpts);
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

        $borderSpec = $this->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
        $padding = $borderSpec['padding'];
        $baseX = $this->mLeft + $padding[3];
        $wrapWidth = $this->layoutManager->getContentAreaWidth() - $padding[1] - $padding[3];
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
        $y = $this->layoutManager->getCursorY();

        if ($bgColor !== null) {
            $maxSz = $this->styleManager->getCurrentFontSize();
            $this->drawBackgroundRect($baseX, $y - ($maxSz * 0.25), $wrapWidth, $lineH, $bgColor);
        }

        $spaces = array_values(array_filter($renderTokens, fn($t) => $t['type'] === 'space'));
        $extraPerGap = 0.0;
        if ($justify && count($spaces) > 0) {
            $extra = $targetWidth - $lineWidth;
            if ($extra > 0.001) $extraPerGap = $extra / count($spaces);
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
            $this->textRenderer->writeTextLine($mx, $y, $mText, $this->styleManager, null);
            $this->styleManager->pop();
        }

        foreach ($renderTokens as $tok) {
            if ($tok['type'] === 'inline') {
                $tok['renderer']($x, $y);
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
            $dy = isset($opt['baselineShift']) ? (float)$opt['baselineShift'] : ($isSup ? ($lineH * 0.35) : ($isSub ? - ($lineH * 0.15) : 0.0));

            $tokWidth = $this->textRenderer->measureTextStyled($tok['text'], $this->styleManager);
            if ($runBG !== null) {
                $this->drawBackgroundRect($x, $y + $dy - ($this->styleManager->getCurrentFontSize() * 0.25), $tokWidth, $this->styleManager->getLineHeight(), $runBG);
            }

            $this->textRenderer->writeTextLine($x, $y + $dy, $tok['text'], $this->styleManager, $shadow);

            if ($href !== null) {
                $linkHeight = $this->styleManager->getLineHeight();
                $linkY = ($y + $dy) - ($linkHeight * 0.25);
                $this->addLinkAbs($x, $linkY, $tokWidth, $linkHeight, $href);
            }

            $x += $tokWidth;
            if ($tok['type'] === 'space') {
                $x += $this->styleManager->getWordSpacing();
                if ($extraPerGap > 0.0) $x += $extraPerGap;
            }

            $this->styleManager->pop();
        }
        $this->layoutManager->advanceCursor($lineH);
        $this->layoutManager->checkPageBreak();
    }

    public function addTable($dataOrOptions = null, array $options = []): ?PdfTableBuilder
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

    public function addTableData(array $data, array $options = []): void
    {
        if ($this->styleManager->getCurrentFontAlias() === null) {
            throw new \LogicException("Defina uma fonte com setFont() antes de adicionar uma tabela.");
        }
        if (empty($data)) return;

        $this->styleManager->push();

        $opts = array_merge([
            'widths' => 'auto',
            'align' => 'left',
            'headerRow' => null,
            'headerStyle' => 'B',
            'headerBgColor' => '#f0f0f0',
            'headerColor' => null,
            'borders' => true,
            'padding' => 4.0,
            'spacing' => 0.0,
            'alternateRows' => false,
            'altRowColor' => '#f9f9f9',
            'minRowHeight' => null,
            'wrapText' => true,
            'fontSize' => null,
            'lineHeight' => null,
        ], $options);

        if ($opts['fontSize'] !== null) $this->styleManager->setFont($this->styleManager->getCurrentFontAlias(), (float)$opts['fontSize']);
        if ($opts['lineHeight'] !== null) $this->styleManager->applyOptions(['lineHeight' => $opts['lineHeight']], $this);

        $numCols = 0;
        foreach ($data as $row) $numCols = max($numCols, count($row));
        if ($numCols === 0) {
            $this->styleManager->pop();
            return;
        }

        $columnWidths = $this->calculateColumnWidths($data, $opts['widths'], $numCols, $opts);
        $columnAligns = $this->normalizeColumnAligns($opts['align'], $numCols);
        $borderSpec = $this->normalizeTableBorderSpec($opts['borders'], $opts['padding']);
        $cellPadding = $borderSpec['padding'];

        $rowIndex = 0;
        foreach ($data as $row) {
            $isHeader = ($opts['headerRow'] !== null && $rowIndex === $opts['headerRow']);
            $isAltRow = (!$isHeader && $opts['alternateRows'] && ($rowIndex > $opts['headerRow'] ? ($rowIndex - ($opts['headerRow'] + 1)) : $rowIndex) % 2 === 1);

            $rowBgColor = null;
            if ($isHeader && $opts['headerBgColor'] !== null) $rowBgColor = $this->normalizeColor($opts['headerBgColor']);
            elseif ($isAltRow && $opts['altRowColor'] !== null) $rowBgColor = $this->normalizeColor($opts['altRowColor']);

            $rowHeight = $this->calculateRowHeight($row, $columnWidths, $cellPadding, $opts['wrapText'], $isHeader ? $opts['headerStyle'] : '', $opts['minRowHeight']);
            $this->layoutManager->checkPageBreak($rowHeight);

            $this->styleManager->push();
            if ($isHeader) $this->styleManager->applyOptions(['style' => $opts['headerStyle']], $this);
            if ($isHeader && $opts['headerColor'] !== null) $this->styleManager->setTextColor($this->normalizeColor($opts['headerColor']));

            $this->drawTableRow($row, $columnWidths, $columnAligns, $rowHeight, $cellPadding, $borderSpec, $rowBgColor, $opts['wrapText']);

            $this->styleManager->pop();

            $this->layoutManager->advanceCursor($rowHeight);
            if ($opts['spacing'] > 0 && $rowIndex < count($data) - 1) $this->layoutManager->advanceCursor($opts['spacing']);
            $rowIndex++;
        }
        $this->styleManager->pop();
    }

    private function calculateColumnWidths(array $data, $widths, int $numCols, array $tableOptions): array
    {
        $availableWidth = $this->layoutManager->getContentAreaWidth();
        if (is_array($widths)) {
            $result = [];
            $totalSpecified = array_sum($widths);
            $scale = ($totalSpecified > 0) ? $availableWidth / $totalSpecified : 0;
            for ($i = 0; $i < $numCols; $i++) $result[$i] = isset($widths[$i]) ? $widths[$i] * $scale : 0;
            return $result;
        }

        $maxWidths = array_fill(0, $numCols, 0);
        $rowIndex = 0;
        foreach ($data as $row) {
            $isHeader = (isset($tableOptions['headerRow']) && $rowIndex === $tableOptions['headerRow']);
            $this->styleManager->push();
            if ($isHeader) {
                $this->styleManager->applyOptions(['style' => $tableOptions['headerStyle'] ?? 'B'], $this);
            }

            for ($i = 0; $i < $numCols; $i++) {
                if (!isset($row[$i])) {
                    continue;
                }
                $cell = $row[$i];
                $cellOptions = $this->getTableCellOptions($cell);
                [$styleOptions, $unusedBg] = $this->partitionCellOptions($cellOptions);

                $this->styleManager->push();
                if ($styleOptions !== []) {
                    $this->styleManager->applyOptions($styleOptions, $this);
                }
                $text = $this->getTableCellText($cell);
                $width = $this->textRenderer->measureTextStyled($text, $this->styleManager);
                $maxWidths[$i] = max($maxWidths[$i], $width);
                $this->styleManager->pop();
            }
            $this->styleManager->pop();
            $rowIndex++;
        }

        $cellPadding = is_numeric($tableOptions['padding'] ?? 4.0) ? (float)($tableOptions['padding']) : 4.0;
        foreach ($maxWidths as &$w) $w += $cellPadding * 2.5;

        $totalMax = array_sum($maxWidths);
        if ($totalMax > 0) {
            $scale = ($totalMax > $availableWidth) ? $availableWidth / $totalMax : 1.0;
            if ($totalMax < $availableWidth) {
                $extra = $availableWidth - $totalMax;
                foreach ($maxWidths as &$w) $w += $extra * ($w / $totalMax);
            } else {
                foreach ($maxWidths as &$w) $w *= $scale;
            }
        } else {
            $maxWidths = array_fill(0, $numCols, $availableWidth / max(1, $numCols));
        }
        return $maxWidths;
    }

    private function normalizeColumnAligns($align, int $numCols): array
    {
        if (is_string($align)) return array_fill(0, $numCols, $align);
        if (is_array($align)) {
            $result = [];
            for ($i = 0; $i < $numCols; $i++) $result[$i] = $align[$i] ?? 'left';
            return $result;
        }
        return array_fill(0, $numCols, 'left');
    }

    private function normalizeTableBorderSpec($borders, $padding): array
    {
        $spec = ['hasBorder' => $borders !== false, 'width' => 0.5, 'color' => $this->normalizeColor(['gray' => 0.5]), 'padding' => is_numeric($padding) ? (float)$padding : 4.0];
        if (is_array($borders)) {
            if (isset($borders['width'])) $spec['width'] = (float)$borders['width'];
            if (isset($borders['color'])) $spec['color'] = $this->normalizeColor($borders['color']);
        }
        return $spec;
    }

    /**
     * @param mixed $cell
     */
    private function getTableCellText($cell): string
    {
        if (is_array($cell)) {
            $text = $cell['text'] ?? '';
            return is_string($text) ? $text : (string)$text;
        }
        return is_string($cell) ? $cell : (string)$cell;
    }

    /**
     * @param mixed $cell
     * @return array<string, mixed>
     */
    private function getTableCellOptions($cell): array
    {
        if (is_array($cell) && isset($cell['options']) && is_array($cell['options'])) {
            return $cell['options'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function partitionCellOptions(array $options): array
    {
        $styleOptions = $options;
        $bgColor = null;
        if (array_key_exists('bgcolor', $styleOptions)) {
            $bgColor = $styleOptions['bgcolor'];
            unset($styleOptions['bgcolor']);
        }
        return [$styleOptions, $bgColor];
    }

    private function wrapText(string $text, float $maxWidth): array
    {
        if ($maxWidth <= 0 || $this->textRenderer->measureTextStyled($text, $this->styleManager) <= $maxWidth) return [$text];

        $lines = [];
        $currentLine = '';
        $words = explode(' ', $text);
        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            if ($this->textRenderer->measureTextStyled($testLine, $this->styleManager) <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') $lines[] = $currentLine;
                $currentLine = $word;
                if ($this->textRenderer->measureTextStyled($currentLine, $this->styleManager) > $maxWidth) {
                    $longWord = $currentLine;
                    $currentLine = '';
                    $chars = mb_str_split($longWord, 1, 'UTF-8');
                    foreach ($chars as $char) {
                        if ($this->textRenderer->measureTextStyled($currentLine . $char, $this->styleManager) > $maxWidth) {
                            if ($currentLine !== '') $lines[] = $currentLine;
                            $currentLine = $char;
                        } else {
                            $currentLine .= $char;
                        }
                    }
                }
            }
        }
        if ($currentLine !== '') $lines[] = $currentLine;
        return empty($lines) ? [''] : $lines;
    }

    private function calculateRowHeight(array $row, array $columnWidths, float $padding, bool $wrapText, string $style, ?float $minHeight): float
    {
        $this->styleManager->push();
        $this->styleManager->applyOptions(['style' => $style], $this);

        $baseLineHeight = $this->styleManager->getLineHeight();
        $maxHeight = $baseLineHeight;

        $columns = min(count($row), count($columnWidths));
        for ($i = 0; $i < $columns; $i++) {
            if (!isset($row[$i], $columnWidths[$i])) {
                continue;
            }
            $cell = $row[$i];
            $cellWidth = $columnWidths[$i] - ($padding * 2);
            if ($cellWidth <= 0) {
                $cellWidth = $columnWidths[$i];
            }

            $cellOptions = $this->getTableCellOptions($cell);
            [$styleOptions, $unusedBg] = $this->partitionCellOptions($cellOptions);

            $this->styleManager->push();
            if ($styleOptions !== []) {
                $this->styleManager->applyOptions($styleOptions, $this);
            }
            $text = $this->getTableCellText($cell);
            $lines = $wrapText ? $this->wrapText($text, max(0.0, $cellWidth)) : [$text];
            $cellLineHeight = $this->styleManager->getLineHeight();
            $lineCount = max(1, count($lines));
            $cellHeight = $lineCount * $cellLineHeight;
            $maxHeight = max($maxHeight, $cellHeight);
            $this->styleManager->pop();
        }

        $totalHeight = max($maxHeight + ($padding * 2), $baseLineHeight * 1.5);
        if ($minHeight !== null) {
            $totalHeight = max($totalHeight, $minHeight);
        }

        $this->styleManager->pop();
        return $totalHeight;
    }

    private function getTextVerticalCenterOffset(): float
    {
        $alias = $this->styleManager->getCurrentFontAlias();
        $size = $this->styleManager->getCurrentFontSize();
        if ($alias === null) return $size * 0.3;

        $font = $this->fonts[$alias] ?? null;
        if ($font !== null) {
            $offset = (($font['ascent'] + $font['descent']) / 2 / $font['unitsPerEm']) * $size;
            return $offset;
        }
        return $size * 0.3;
    }

    private function drawTableRow(array $row, array $columnWidths, array $columnAligns, float $rowHeight, float $padding, array $borderSpec, ?array $bgColor, bool $wrapText): void
    {
        if ($this->currentPage === null) return;

        $x = $this->mLeft;
        $y = $this->layoutManager->getCursorY() - $rowHeight;
        if ($bgColor !== null) {
            $this->drawBackgroundRect($x, $y, array_sum($columnWidths), $rowHeight, $bgColor);
        }

        for ($i = 0; $i < count($columnWidths); $i++) {
            $cellWidth = $columnWidths[$i];
            if ($borderSpec['hasBorder']) {
                $this->drawCellBorder($x, $y, $cellWidth, $rowHeight, $borderSpec);
            }
            if (!isset($row[$i])) {
                $x += $cellWidth;
                continue;
            }

            $cell = $row[$i];
            $cellOptions = $this->getTableCellOptions($cell);
            [$styleOptions, $bgSpec] = $this->partitionCellOptions($cellOptions);

            if ($bgSpec !== null) {
                $cellBgColor = $this->normalizeColor($bgSpec);
                if ($cellBgColor !== null) {
                    $this->drawBackgroundRect($x, $y, $cellWidth, $rowHeight, $cellBgColor);
                }
            }

            $this->styleManager->push();
            if ($styleOptions !== []) {
                $this->styleManager->applyOptions($styleOptions, $this);
            }
            $text = $this->getTableCellText($cell);
            $align = $columnAligns[$i];
            $cellCenterY = $y + ($rowHeight / 2);
            $textBaselineY = $cellCenterY - $this->getTextVerticalCenterOffset();
            $this->drawCellText($text, $x + $padding, $textBaselineY, max(0.0, $cellWidth - ($padding * 2)), $align, $wrapText);
            $this->styleManager->pop();

            $x += $cellWidth;
        }
    }

    private function drawCellBorder(float $x, float $y, float $width, float $height, array $spec): void
    {
        if ($this->currentPage === null) return;
        $ops = "q\n" . sprintf("%.3F w\n", $spec['width']) . $this->strokeColorOps($spec['color']) .
            sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $width, $height) . "S\nQ\n";
        $this->pageContents[$this->currentPage] .= $ops;
    }

    private function drawCellText(string $text, float $x, float $y, float $maxWidth, string $align, bool $wrap): void
    {
        $lines = $wrap ? $this->wrapText($text, $maxWidth) : [$text];
        $lineCount = count($lines);
        $lineHeight = $this->styleManager->getLineHeight();
        $yPos = $y + (($lineCount - 1) * $lineHeight) / 2;

        foreach ($lines as $line) {
            $textWidth = $this->textRenderer->measureTextStyled($line, $this->styleManager);
            $xPos = match ($align) {
                'center' => $x + ($maxWidth - $textWidth) / 2,
                'right' => $x + $maxWidth - $textWidth,
                default => $x,
            };
            $this->textRenderer->writeTextLine($xPos, $yPos, $line, $this->styleManager, null);
            $yPos -= $lineHeight;
        }
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
        $borderSpec = $this->normalizeBorderSpec($opts['border'] ?? null, $opts['padding'] ?? null);
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
        return "q\n" . $this->colorOps($color) . sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $w, $h) . "f\nQ\n";
    }

    public function drawBackgroundRect(float $x, float $y, float $width, float $height, array $color): void
    {
        if ($this->currentPage === null) return;
        $this->pageContents[$this->currentPage] .= $this->buildBackgroundRectOps($x, $y, $width, $height, $color);
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
        return "q\n" . $this->colorOps($color) . $this->buildRoundedRectPath($x, $y, $w, $h, $r) . "f\nQ\n";
    }

    public function drawRoundedBackgroundRect(float $x, float $y, float $w, float $h, array $r, array $color): void
    {
        if ($this->currentPage === null) return;
        $this->pageContents[$this->currentPage] .= $this->buildRoundedBackgroundRectOps($x, $y, $w, $h, $r, $color);
    }

    private function borderIsUniform(array $spec): bool
    {
        $eq4 = function (array $arr, float $eps = 1e-6): bool {
            if (count($arr) !== 4) return false;
            $a = $arr[0];
            for ($i = 1; $i < 4; $i++) {
                if (is_numeric($a) && is_numeric($arr[$i])) {
                    if (abs($a - $arr[$i]) > $eps) return false;
                } else {
                    if ($arr[$i] !== $a) return false;
                }
            }
            return true;
        };
        return $eq4($spec['width']) && $eq4($spec['dash']) && $eq4($spec['color']);
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
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");

        $alpha = $opts['alpha'] ?? 0.08;
        $repeat = strtolower($opts['repeat'] ?? 'no-repeat');
        $opsAll = '';
        if ($repeat !== 'tile') {
            [$ix, $iy, $iw, $ih] = $this->fitImageInBox($img['w'], $img['h'], $x, $y, $w, $h, $opts);
            $opsAll = $this->buildImageOps($alias, $ix, $iy, $iw, $ih, ['alpha' => $alpha]);
        } else {
            $tw = $opts['tileSize']['w'] ?? null;
            $th = $opts['tileSize']['h'] ?? null;
            if ($tw === null && $th === null) {
                $th = max(24.0, $h * 0.25);
                $tw = $th * ($img['w'] / $img['h']);
            } elseif ($tw !== null && $th === null) $th = (float)$tw * ($img['h'] / $img['w']);
            elseif ($tw === null && $th !== null) $tw = (float)$th * ($img['w'] / $img['h']);
            $tw = (float)$tw;
            $th = (float)$th;
            $gapX = (float)($opts['tileGap']['x'] ?? 0.0);
            $gapY = (float)($opts['tileGap']['y'] ?? 0.0);
            for ($yy = $y; $yy < $y + $h; $yy += $th + $gapY) {
                for ($xx = $x; $xx < $x + $w; $xx += $tw + $gapX) {
                    $opsAll .= $this->buildImageOps($alias, $xx, $yy, $tw, $th, ['alpha' => $alpha]);
                }
            }
        }
        if ($opsAll !== '') {
            if ($insertAt !== null) $this->insertOpsAt($opsAll, $insertAt);
            else $this->appendToPageContent($opsAll);
        }
    }

    public function normalizeColor($color): ?array
    {
        return $this->colorManager->normalize($color);
    }

    public function registerFixedElement(array $elements, array $options, float $x, float $y): void
    {
        if ($this->measurementMode) {
            return;
        }
        $key = md5(serialize([$elements, $options]));
        if (!isset($this->fixedElements[$key])) {
            $this->fixedElements[$key] = [
                'elements' => $elements,
                'options' => $options,
                'x' => $x,
                'y' => $y
            ];
        }
    }

    private function enterMeasurementMode(): void
    {
        $this->measurementDepth++;
        if ($this->measurementDepth === 1) {
            $this->measurementMode = true;
        }
    }

    private function exitMeasurementMode(): void
    {
        if ($this->measurementDepth > 0) {
            $this->measurementDepth--;
        }
        if ($this->measurementDepth === 0) {
            $this->measurementMode = false;
        }
    }

    public function measureBlockHeight(array $elements, array $options): float
    {
        $layoutState = $this->layoutManager->snapshotState();
        $origLeft  = $this->mLeft;
        $origRight = $this->mRight;

        $this->enterMeasurementMode();

        try {

            $borderSpec = $this->normalizeBorderSpec($options['border'] ?? null, $options['padding'] ?? null);
            $padding    = $borderSpec['padding'];
            $margin     = $this->normalizePadding($options['margin'] ?? 0);

            $avail = $this->getContentAreaWidth();
            $wSpec = $options['width'] ?? '100%';

            $blockW = match (true) {
                is_string($wSpec) && str_ends_with($wSpec, '%')
                => $avail * max(0.0, min(1.0, (float) rtrim($wSpec, '%') / 100.0)),
                is_numeric($wSpec)
                => min((float)$wSpec, $avail),
                default
                => $avail,
            };

            $align = strtolower($options['align'] ?? 'left');
            $effectiveW = $blockW + $margin[1] + $margin[3];

            $x = match ($align) {
                'center' => $this->mLeft + ($avail - $effectiveW) / 2.0 + $margin[3],
                'right'  => $this->mLeft + $avail - $effectiveW + $margin[3],
                default  => $this->mLeft + $margin[3],
            };

            $startY = $this->getCursorY() - $margin[0];

            $this->mLeft  = $x + $padding[3];
            $this->mRight = $this->getPageWidth() - ($x + $blockW - $padding[1]);

            $this->setCursorY($startY - $padding[0]);

            foreach ($elements as $el) {
                $type = $el['type'] ?? null;

                $fn = match ($type) {
                    'paragraph' => function () use ($el) {
                        $this->addParagraphText((string)($el['content'] ?? ''), (array)($el['options'] ?? []));
                    },
                    'image' => function () use ($el) {
                        $this->addImageBlock((string)($el['alias'] ?? ''), (array)($el['options'] ?? []));
                    },
                    'table' => function () use ($el) {
                        $this->addTableData((array)($el['data'] ?? []), (array)($el['options'] ?? []));
                    },
                    'list' => function () use ($el) {
                        $this->addList($el['items'] ?? [], (array)($el['options'] ?? []));
                    },
                    'spacer' => function () use ($el) {
                        $this->addSpacer((float)($el['height'] ?? 0.0));
                    },
                    'hr' => function () use ($el) {
                        $this->addHorizontalLine((array)($el['options'] ?? []));
                    },
                    'block' => function () use ($el) {
                        if (isset($el['builder']) && method_exists($el['builder'], 'getDefinition')) {
                            $def = $el['builder']->getDefinition();
                            $this->measureBlockHeight((array)($def['elements'] ?? []), (array)($def['options'] ?? []));
                        }
                    },
                    default => function () { /* no-op p/ tipos desconhecidos */
                    },
                };

                $fn();
            }

            $contentBottomY = $this->getCursorY();
            $contentHeight  = $startY - $contentBottomY; // já exclui padding-top
            return max(0.0, $padding[0] + $contentHeight + $padding[2]);
        } finally {
            $this->layoutManager->restoreState($layoutState);
            $this->mLeft  = $origLeft;
            $this->mRight = $origRight;
            $this->exitMeasurementMode();
        }
    }

    public function internal_newPage(): void
    {
        $pageId = $this->newObjectId();
        $this->pages[] = $pageId;
        $this->pageContents[$pageId] = '';
        $this->pageResources[$pageId] = ['Font' => [], 'ExtGState' => [], 'XObject' => [], 'Shading' => []];
        $this->currentPage = $pageId;

        // NÃO renderiza fixos durante measurement (evita reentrância/loops)
        if (!$this->measurementMode && !empty($this->fixedElements)) {
            $originalCursorY = $this->getCursorY();
            foreach ($this->fixedElements as $fixedElement) {
                $renderer = new PdfBlockRenderer($this);
                $renderer->render(
                    $fixedElement['elements'],
                    array_merge($fixedElement['options'], [
                        'position' => 'absolute',
                        'x' => $fixedElement['x'],
                        'y' => $fixedElement['y']
                    ])
                );
            }
            if ($this->pageHeaderDefined && $this->pageHeaderPushesContent) {
                $this->setCursorY($this->getPageHeight() - $this->pageHeaderOffset);
            } else {
                $this->setCursorY($originalCursorY);
            }
        }
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

    private function emitFontObjects(): array
    {
        $type0Ids = [];
        foreach ($this->fonts as $alias => $font) {
            if (empty($this->usedGids[$alias])) continue;

            $spaceGid = $font['cmap'][0x20] ?? null;
            $dwUnits = $spaceGid !== null ? (int)$font['adv'][$spaceGid] : (int)round(array_sum($font['adv']) / max(1, count($font['adv'])));
            $DW = (int)round(($dwUnits * 1000) / $font['unitsPerEm']);
            $W = [];
            foreach ($this->usedGids[$alias] as $gid => $_) {
                $w = (int)round((($font['adv'][$gid] ?? $dwUnits) * 1000) / $font['unitsPerEm']);
                if ($w !== $DW) $W[] = "{$gid} [{$w}]";
            }
            $WArr = count($W) ? "/W [ " . implode(' ', $W) . " ]" : "";
            $toUniId = $this->newObjectId();
            $this->setObject($toUniId, PdfStreamBuilder::streamObj($this->buildToUnicodeCMap($font['name'], $this->usedGids[$alias])));
            $fileId = $this->newObjectId();
            $this->setObject($fileId, PdfStreamBuilder::streamObj($font['ttf']));
            $descId = $this->newObjectId();
            $bboxPdf = array_map(fn($v) => (int)round(($v * 1000) / $font['unitsPerEm']), $font['bbox']);
            $ascent = (int)round(($font['ascent'] * 1000) / $font['unitsPerEm']);
            $descent = (int)round(($font['descent'] * 1000) / $font['unitsPerEm']);
            $italicAnglePdf = (int)round(($font['italicAngle'] ?? 0.0));
            $this->setObject($descId, "<< /Type /FontDescriptor /FontName /{$font['name']} /Flags 32 " .
                "/Ascent {$ascent} /Descent {$descent} /CapHeight {$ascent} /ItalicAngle {$italicAnglePdf} " .
                "/FontBBox [ {$bboxPdf[0]} {$bboxPdf[1]} {$bboxPdf[2]} {$bboxPdf[3]} ] " .
                "/StemV 80 /FontFile2 {$fileId} 0 R >>");
            $cidId = $this->newObjectId();
            $cidInfo = "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>";
            $this->setObject($cidId, "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$font['name']} {$cidInfo} /CIDToGIDMap /Identity /DW {$DW} {$WArr} /FontDescriptor {$descId} 0 R >>");
            $type0Id = $this->newObjectId();
            $this->setObject($type0Id, "<< /Type /Font /Subtype /Type0 /BaseFont /{$font['name']} /Encoding /Identity-H /DescendantFonts [ {$cidId} 0 R ] /ToUnicode {$toUniId} 0 R >>");
            $type0Ids[$alias] = $type0Id;
        }
        return $type0Ids;
    }

    private function buildToUnicodeCMap(string $psName, array $gidToUni): string
    {
        $pairs = [];
        foreach ($gidToUni as $gid => $uni) $pairs[] = sprintf("<%04X> <%04X>\n", $gid, $uni);
        $chunks = array_chunk($pairs, 100);
        $bf = '';
        foreach ($chunks as $chunk) $bf .= count($chunk) . " beginbfchar\n" . implode('', $chunk) . "endbfchar\n";
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n" .
            "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def\n" .
            "/CMapName /{$psName} def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" .
            $bf . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    public function newObjectId(): int
    {
        return $this->writer->newObjectId();
    }

    private function sanitizePSName(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9\-\+_]/', '', str_replace(' ', '-', $s)) ?: 'Embedded';
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

    public function normalizeBorderSpec($border, $padding): array
    {
        $spec = ['hasBorder' => $border !== null, 'width' => [0.0, 0.0, 0.0, 0.0], 'color' => [null, null, null, null], 'dash' => [null, null, null, null], 'padding' => [0.0, 0.0, 0.0, 0.0], 'radius' => [0.0, 0.0, 0.0, 0.0]];
        if (is_numeric($padding)) $spec['padding'] = array_fill(0, 4, (float)$padding);
        elseif (is_array($padding) && count($padding) === 4) $spec['padding'] = array_map('floatval', $padding);
        if (!$spec['hasBorder']) {
            if (is_array($border) && isset($border['radius'])) {
                $r = $border['radius'];
                if (is_numeric($r)) $spec['radius'] = array_fill(0, 4, (float)$r);
                elseif (is_array($r) && count($r) === 4) $spec['radius'] = array_map('floatval', $r);
            }
            return $spec;
        }
        $width = $border['width'] ?? 1.0;
        if (is_numeric($width)) $spec['width'] = array_fill(0, 4, (float)$width);
        elseif (is_array($width) && count($width) === 4) $spec['width'] = array_map('floatval', $width);
        $color = $border['color'] ?? ['gray' => 0.0];
        if (isset($color['space']) || is_string($color)) $spec['color'] = array_fill(0, 4, $this->normalizeColor($color));
        elseif (is_array($color) && count($color) === 4) {
            for ($i = 0; $i < 4; $i++) $spec['color'][$i] = $this->normalizeColor($color[$i]);
        }
        $style = $border['style'] ?? 'solid';
        $spec['dash'] = ($style === 'dashed') ? array_fill(0, 4, '[3 3] 0 d') : array_fill(0, 4, '[] 0 d');
        if (isset($border['radius'])) {
            $r = $border['radius'];
            if (is_numeric($r)) $spec['radius'] = array_fill(0, 4, (float)$r);
            elseif (is_array($r) && count($r) === 4) $spec['radius'] = array_map('floatval', $r);
        }
        return $spec;
    }

    public function drawParagraphBorders(array $box, array $spec): void
    {
        if ($this->currentPage === null) return;
        $x = $box['x'];
        $y = $box['y'];
        $w = $box['w'];
        $h = $box['h'];
        $r = $spec['radius'] ?? [0, 0, 0, 0];
        $hasRadius = is_array($r) ? (max($r) > 1e-4) : ((float)$r > 1e-4);
        if (!is_array($r)) $r = array_fill(0, 4, (float)$r);

        if ($hasRadius && $this->borderIsUniform($spec) && $spec['width'][0] > 1e-3) {
            $ops = "q\n" . sprintf("%.3F w\n", $spec['width'][0]) . $this->strokeColorOps($spec['color'][0]) . $spec['dash'][0] . "\n1 j\n" .
                $this->buildRoundedRectPath($x, $y, $w, $h, $r) . "S\nQ\n";
            $this->appendToPageContent($ops);
            return;
        }
        $ops = "q\n";
        $sides = [[[$x, $y + $h], [$x + $w, $y + $h]], [[$x + $w, $y + $h], [$x + $w, $y]], [[$x + $w, $y], [$x, $y]], [[$x, $y], [$x, $y + $h]]];
        for ($i = 0; $i < 4; $i++) {
            if ($spec['width'][$i] > 1e-3) {
                $ops .= sprintf("%.3F w\n", $spec['width'][$i]) . $this->strokeColorOps($spec['color'][$i]) . $spec['dash'][$i] . "\n" .
                    sprintf("%.3F %.3F m\n", $sides[$i][0][0], $sides[$i][0][1]) . sprintf("%.3F %.3F l\n", $sides[$i][1][0], $sides[$i][1][1]) . "S\n";
            }
        }
        $this->appendToPageContent($ops . "Q\n");
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
        if ($this->currentPage === null) return;
        $ops = "q\n" . sprintf("%.3F w\n", $opts['height']);
        if (($color = $this->normalizeColor($opts['color'])) !== null) $ops .= $this->strokeColorOps($color);
        if ($opts['dash'] !== null && is_array($opts['dash'])) $ops .= sprintf("[%.1F %.1F] 0 d\n", $opts['dash'][0], $opts['dash'][1]);
        else $ops .= match ($opts['style']) {
            'dashed' => "[6 3] 0 d\n",
            'dotted' => "[1 2] 0 d\n",
            default => "[] 0 d\n",
        };
        $ops .= sprintf("%.3F %.3F m\n%.3F %.3F l\nS\nQ\n", $x, $y, $x + $width, $y);
        $this->appendToPageContent($ops);
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
        if (empty($columns)) {
            return;
        }

        $opts = array_merge([
            'gap' => 10.0,
            'widths' => 'equal',
        ], $options);

        $this->layoutManager->checkPageBreak();
        $startY = $this->layoutManager->getCursorY();
        $availableWidth = $this->layoutManager->getContentAreaWidth();

        $columnWidths = $this->calculateColumnWidthsFromOptions(
            $opts['widths'],
            count($columns),
            $availableWidth,
            $opts['gap']
        );

        $columnHeights = [];
        $currentX = 0;

        foreach ($columns as $i => $columnContent) {

            $width = $columnWidths[$i];
            $this->layoutManager->pushContext($currentX, $width);
            $this->layoutManager->setCursorY($startY);

            $this->renderColumnContent($columnContent, $width);
            $columnHeights[] = $startY - $this->layoutManager->getCursorY();
            $this->layoutManager->popContext();

            $currentX += $width + $opts['gap'];
        }

        $maxHeight = empty($columnHeights) ? 0 : max($columnHeights);
        $this->layoutManager->setCursorY($startY - $maxHeight);
    }

    private function calculateColumnWidthsFromOptions($widths, int $numColumns, float $availableWidth, float $gap): array
    {
        $contentWidth = $availableWidth - (($numColumns - 1) * $gap);
        if ($widths === 'equal') return array_fill(0, $numColumns, $contentWidth / $numColumns);

        if (is_array($widths)) {
            $result = [];
            $fixedTotal = 0;
            $percentTotal = 0;
            foreach ($widths as $w) {
                if (is_string($w) && str_ends_with($w, '%')) $percentTotal += floatval(rtrim($w, '%'));
                elseif (is_numeric($w)) $fixedTotal += (float)$w;
            }
            $remainingForPercent = $contentWidth - $fixedTotal;
            for ($i = 0; $i < $numColumns; $i++) {
                $w = $widths[$i] ?? 'auto';
                if (is_string($w) && str_ends_with($w, '%')) {
                    $result[] = (floatval(rtrim($w, '%')) / $percentTotal) * $remainingForPercent;
                } elseif (is_numeric($w)) {
                    $result[] = (float)$w;
                } else {
                    $result[] = $remainingForPercent / ($numColumns - count(array_filter($widths, 'is_numeric')));
                }
            }
            return $result;
        }
        return array_fill(0, $numColumns, $contentWidth / $numColumns);
    }

    private function renderColumnContent($content, float $columnWidth): void
    {
        if (is_callable($content)) $content($this);
        elseif ($content instanceof PdfBlockBuilder) $content->end();
        elseif (is_array($content)) {
            foreach ($content as $element) {
                if (is_string($element)) $this->addParagraphText($element, []);
                elseif (is_array($element) && isset($element['type'])) $this->renderColumnElement($element);
                elseif (is_callable($element)) $element($this);
            }
        } elseif (is_string($content)) {
            $this->addParagraphText($content, []);
        }
    }

    private function renderColumnElement(array $element): void
    {
        match ($element['type'] ?? '') {
            'paragraph', 'p' => $this->addParagraphText($element['text'] ?? $element['content'] ?? '', $element['options'] ?? []),
            'image', 'img' => $this->addImageBlock($element['alias'] ?? $element['src'] ?? '', $element['options'] ?? []),
            'table' => $this->addTableData($element['data'] ?? [], $element['options'] ?? []),
            'list', 'ul', 'ol' => $this->addList($element['items'] ?? $element['data'] ?? [], $element['options'] ?? []),
            'spacer', 'space' => $this->addSpacer($element['height'] ?? 10),
            'line', 'hr' => $this->addHorizontalLine($element['options'] ?? []),
            'separator' => $this->addSeparator($element['options'] ?? [])
        };
    }

    private function drawColumnSeparators(array $columnsData, float $y, float $height, $separatorOptions): void
    {
        $sepOpts = array_merge(['width' => 0.5, 'color' => ['gray' => 0.7], 'style' => 'solid', 'margin' => 5], is_array($separatorOptions) ? $separatorOptions : []);
        $sepColor = $this->normalizeColor($sepOpts['color']);
        for ($i = 0; $i < count($columnsData) - 1; $i++) {
            $col = $columnsData[$i];
            $nextCol = $columnsData[$i + 1];
            $gap = $nextCol['x'] - ($col['x'] + $col['width']);
            $sepX = $col['x'] + $col['width'] + ($gap / 2);
            $sepY1 = $y - $sepOpts['margin'];
            $sepY2 = $y - $height + $sepOpts['margin'];
            if ($this->currentPage === null) continue;

            $ops = "q\n" . sprintf("%.3F w\n", $sepOpts['width']) . $this->strokeColorOps($sepColor) .
                (match ($sepOpts['style']) {
                    'dashed' => "[6 3] 0 d\n",
                    'dotted' => "[1 2] 0 d\n",
                    default => "[] 0 d\n"
                }) .
                sprintf("%.3F %.3F m\n%.3F %.3F l\nS\nQ\n", $sepX, $sepY1, $sepX, $sepY2);
            $this->appendToPageContent($ops);
        }
    }

    public function normalizePadding($padding): array
    {
        if (is_numeric($padding)) return array_fill(0, 4, (float)$padding);
        if (is_array($padding)) {
            $c = count($padding);
            if ($c === 1) return array_fill(0, 4, (float)$padding[0]);
            if ($c === 2) return [(float)$padding[0], (float)$padding[1], (float)$padding[0], (float)$padding[1]];
            if ($c === 3) return [(float)$padding[0], (float)$padding[1], (float)$padding[2], (float)$padding[1]];
            if ($c === 4) return array_map('floatval', $padding);
        }
        return [0.0, 0.0, 0.0, 0.0];
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
