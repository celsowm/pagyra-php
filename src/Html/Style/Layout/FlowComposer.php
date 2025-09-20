<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html\Style\Layout;

use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class FlowComposer
{
    /**
     * @param array<string, ComputedStyle> $styles
     * @return array<int, array{type: string, tag: string, nodeId: string, text: string, style: ComputedStyle|null}>
     */
    public function compose(HtmlDocument $document, array $styles): array
    {
        $flows = [];
        $blockTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'div'];

        $document->eachElement(function (array $node) use (&$flows, $styles, $blockTags): void {
            $tag = strtolower((string)($node['tag'] ?? ''));
            if (!in_array($tag, $blockTags, true)) {
                return;
            }
            if ($tag === 'style' || $tag === 'script') {
                return;
            }
            if ($tag === 'div') {
                foreach ($node['children'] ?? [] as $child) {
                    if (($child['type'] ?? '') === 'element' && in_array(strtolower((string)($child['tag'] ?? '')), $blockTags, true)) {
                        return;
                    }
                }
            }

            $text = $this->collectText($node);
            if ($text === '') {
                return;
            }

            $nodeId = (string)($node['nodeId'] ?? '');
            $flows[] = [
                'type' => 'block',
                'tag' => $tag,
                'nodeId' => $nodeId,
                'text' => $text,
                'style' => $styles[$nodeId] ?? null,
            ];
        });

        return $flows;
    }

    private function collectText(array $node): string
    {
        $buffer = '';
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $buffer .= ' ' . ($child['text'] ?? '');
            } elseif ($type === 'element') {
                $buffer .= ' ' . $this->collectText($child);
            }
        }
        $clean = trim($buffer);
        return $clean === '' ? '' : (preg_replace('/\s+/', ' ', $clean) ?? '');
    }
}
