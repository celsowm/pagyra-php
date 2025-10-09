<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

class PdfLinkManager
{
    private array $uriActions = [];

    public function __construct(
        private PdfBuilder $pdfBuilder
    ) {}

    public function addLinkAbs(float $x, float $y, float $width, float $height, string $url): void
    {
        if ($this->pdfBuilder->isMeasurementMode() || $this->pdfBuilder->getCurrentPage() === null) {
            return;
        }

        $annotId = $this->pdfBuilder->newObjectId();
        $actionId = $this->getOrCreateUriAction($url);

        $this->pdfBuilder->addAnnotation($annotId, [$x, $y, $x + $width, $y + $height], $actionId);
    }

    public function addLinkText(string $text, string $url, array $opts = []): void
    {
        $opts['href'] = $url;
        $opts['color'] = $opts['color'] ?? '#0000FF';
        $opts['style'] = $opts['style'] ?? 'U';
        $this->pdfBuilder->addParagraphText($text, $opts);
    }

    public function addLinkTextAbs(float $x, float $y, string $text, string $url, array $opts = []): void
    {
        $opts['style'] = $opts['style'] ?? 'U';
        $opts['color'] = $opts['color'] ?? '#0000FF';

        $this->pdfBuilder->getStyleManager()->push();
        $this->pdfBuilder->getStyleManager()->applyOptions($opts, $this->pdfBuilder);

        $this->pdfBuilder->addTextAbs($x, $y, $text, $opts['color'], $opts);
        $textWidth = $this->pdfBuilder->getTextRenderer()->measureTextStyled($text, $this->pdfBuilder->getStyleManager());
        $linkHeight = $this->pdfBuilder->getStyleManager()->getLineHeight();

        $this->pdfBuilder->getStyleManager()->pop();

        $linkY = $y - ($linkHeight * 0.25);
        $this->addLinkAbs($x, $linkY, $textWidth, $linkHeight, $url);
    }

    private function getOrCreateUriAction(string $url): int
    {
        $urlHash = md5($url);
        if (!isset($this->uriActions[$urlHash])) {
            $actionId = $this->pdfBuilder->newObjectId();
            $this->uriActions[$urlHash] = $actionId;
            $escapedUrl = str_replace(
                ['\\', '(', ')'],
                ['\\\\', '\\(', '\\)'],
                $url
            );
            $this->pdfBuilder->setObject($actionId, "<< /Type /Action /S /URI /URI ({$escapedUrl}) >>");
        }
        return $this->uriActions[$urlHash];
    }



    public function getUriActions(): array
    {
        return $this->uriActions;
    }
}
