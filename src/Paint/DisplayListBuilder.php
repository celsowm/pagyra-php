<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Image\ImageMetadataReader;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Layout\LayoutNode;
use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\LineFragment;
use Pagyra\Pagination\PaginationResult;
use Pagyra\Pagination\PhysicalPageEntry;

final class DisplayListBuilder
{
    private readonly ImageMetadataReader $imageMetadata;

    public function __construct(
        private readonly ?ImageSourceBytesResolver $imageBytes = null,
    ) {
        $this->imageMetadata = new ImageMetadataReader();
    }

    /**
     * @param array{top:float,right:float,bottom:float,left:float} $margins
     */
    public function build(
        PaginationResult $pagination,
        float $pageWidth,
        float $pageHeight,
        array $margins,
    ): DisplayList {
        $pages = [];
        foreach ($pagination->pages as $page) {
            $commands = [];
            foreach ($page->entries as $entry) {
                $this->appendEntry($commands, $entry, $pagination, $margins);
            }
            $pages[] = new PageDisplayList($page->pageIndex, $pageWidth, $pageHeight, $commands);
        }
        return new DisplayList($pages);
    }

    /** @param list<BoxPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendEntry(array &$commands, PhysicalPageEntry $entry, PaginationResult $pagination, array $margins): void
    {
        $node = $entry->placement->node;
        $pageIndex = $entry->fragment->pageIndex;
        $this->appendTopLevelBox($commands, $node, $pageIndex, $entry->placement->offsetY, $pagination, $margins);
        $this->appendLines($commands, $entry->fragment->lines, $margins);
        foreach ($entry->fragment->blocks as $block) $this->appendBlock($commands, $block, $margins);
    }

    /** @param list<BoxPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendTopLevelBox(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $offsetY,
        PaginationResult $pagination,
        array $margins,
    ): void {
        $border = $node->box->borderBox();
        $continuousStart = $border->y + $offsetY;
        $continuousEnd = $border->bottom() + $offsetY;
        $pageStart = $pagination->flow->contentStartForPage($pageIndex);
        $pageEnd = $pageStart + $pagination->flow->contentHeight;
        $start = max($continuousStart, $pageStart);
        $end = min($continuousEnd, $pageEnd);
        if ($end <= $start) return;

        $commands[] = new BoxPaintCommand(
            node: $node,
            pageIndex: $pageIndex,
            x: $border->x + $margins['left'],
            y: ($start - $pageStart) + $margins['top'],
            width: $border->width,
            height: $end - $start,
            backgroundColor: ColorParser::parse($node->source->style->get('background-color')),
        );
    }

    /** @param list<BoxPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBlock(array &$commands, BlockFragment $block, array $margins): void
    {
        $border = $block->node->box->borderBox();
        if ($block->height > 0.0) {
            $commands[] = new BoxPaintCommand(
                node: $block->node,
                pageIndex: $block->pageIndex,
                x: $border->x + $margins['left'],
                y: $block->pageY + $margins['top'],
                width: $border->width,
                height: $block->height,
                backgroundColor: ColorParser::parse($block->node->source->style->get('background-color')),
            );
        }
        $this->appendLines($commands, $block->lines, $margins);
        foreach ($block->children as $child) $this->appendBlock($commands, $child, $margins);
    }

    /**
     * @param list<BoxPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     * @param list<LineFragment> $lines
     */
    private function appendLines(array &$commands, array $lines, array $margins): void
    {
        foreach ($lines as $lineFragment) {
            $line = $lineFragment->line;
            foreach ($line->runs as $run) {
                $weightRaw = strtolower(trim($run->style->get('font-weight', '400') ?? '400'));
                $fontWeight = $weightRaw === 'bold' ? 700 : ($weightRaw === 'normal' ? 400 : (is_numeric($weightRaw) ? (int) $weightRaw : 400));
                $commands[] = new TextPaintCommand(
                    run: $run,
                    pageIndex: $lineFragment->pageIndex,
                    x: $run->x + $margins['left'],
                    y: $lineFragment->pageY + ($run->y - $line->y) + $margins['top'],
                    baseline: $lineFragment->pageBaseline + ($run->baseline - $line->baseline) + $margins['top'],
                    text: $run->text,
                    fontSize: $run->fontSize,
                    fontFamily: $run->style->get('font-family'),
                    fontWeight: max(100, min(900, $fontWeight)),
                    fontStyle: strtolower(trim($run->style->get('font-style', 'normal') ?? 'normal')),
                    color: ColorParser::parse($run->style->get('color', 'black')),
                );
            }

            if ($this->imageBytes === null) continue;
            foreach ($line->atomicBoxes as $box) {
                $node = $box->source->node;
                if (!$node->isElement('img')) continue;
                $source = $node->attribute('src');
                $bytes = $this->imageBytes->resolve($source);
                if ($source === null || $bytes === null) continue;
                try {
                    $metadata = $this->imageMetadata->read($bytes);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                if ($metadata->format !== 'jpeg') continue;

                $contentX = $box->x + $box->margin['left'] + $box->border['left'] + $box->padding['left'];
                $contentY = $box->y + $box->margin['top'] + $box->border['top'] + $box->padding['top'];
                if ($box->contentWidth <= 0.0 || $box->contentHeight <= 0.0) continue;
                $commands[] = new ImagePaintCommand(
                    box: $box,
                    pageIndex: $lineFragment->pageIndex,
                    x: $contentX + $margins['left'],
                    y: $lineFragment->pageY + ($contentY - $line->y) + $margins['top'],
                    width: $box->contentWidth,
                    height: $box->contentHeight,
                    bytes: $bytes,
                    metadata: $metadata,
                    source: $source,
                );
            }
        }
    }
}
