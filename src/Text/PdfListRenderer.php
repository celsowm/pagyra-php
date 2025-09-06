<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Text;
use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Text\PdfRun;
use Celsowm\PagyraPhp\Style\PdfStyleManager;
use Celsowm\PagyraPhp\Text\PdfTextRenderer;


final class PdfListRenderer
{
    private PdfBuilder $pdf;
    private PdfTextRenderer $textRenderer;
    private PdfStyleManager $styleManager;
    private array $usedGids = [];

    public function __construct(
        PdfBuilder $pdf,
        PdfTextRenderer $textRenderer,
        PdfStyleManager $styleManager
    ) {
        $this->pdf = $pdf;
        $this->textRenderer = $textRenderer;
        $this->styleManager = $styleManager;
    }

    public function render(string|array $items, array $opts): array
    {
        $opts['typeByLevel'] = array_map('strtolower', $opts['typeByLevel'] ?? ['decimal', 'lower-alpha', 'lower-roman', 'bullet']);
        $opts['bulletCharsByLevel'] = $opts['bulletCharsByLevel'] ?? ['•', '◦', '▪', '–'];
        $opts['levelIndent'] = (float)($opts['levelIndent'] ?? 20.0);
        $opts['indent'] = (float)($opts['indent'] ?? 0.0);
        $opts['gap'] = (float)($opts['gap'] ?? 6.0);
        $opts['itemSpacing'] = (float)($opts['itemSpacing'] ?? 2.0);
        $opts['markerSize'] = (float)($opts['markerSize'] ?? $this->styleManager->getCurrentFontSize());
        $opts['markerSizeScale'] = (float)($opts['markerSizeScale'] ?? 1.0);
        $opts['markerAlign'] = $opts['markerAlign'] ?? 'right';
        $opts['autoType'] = $opts['autoType'] ?? true;
        $opts['indentUnit'] = (int)($opts['indentUnit'] ?? 2);
        $opts['tabSize'] = (int)($opts['tabSize'] ?? 4);
        $opts['startByLevel'] = $opts['startByLevel'] ?? [];
        $opts['align'] = $opts['align'] ?? 'left';
        [$tree, $detectedStarts] = $this->normalizeListInput($items, $opts);
        $opts['startByLevel'] = $opts['startByLevel'] + $detectedStarts;
        $counters = [];

        $initialY = $this->pdf->getCursorY();
        $this->renderListLevel($tree, $opts, 0, $counters);
        $finalY = $this->pdf->getCursorY();

        $height = $initialY - $finalY;

        return [
            'ops' => '',
            'height' => $height,
            'usedGids' => $this->usedGids,
        ];
    }

    private function renderListLevel(array $nodes, array $baseOpts, int $level, array &$counters): void
    {
        $typeByLevel = $baseOpts['typeByLevel'];
        $typeDefault = $typeByLevel[min($level, count($typeByLevel) - 1)];
        $bulletChars = $baseOpts['bulletCharsByLevel'];
        $bullet = $bulletChars[min($level, count($bulletChars) - 1)];
        if (!isset($counters[$level])) {
            $counters[$level] = (int)($baseOpts['startByLevel'][$level] ?? 1);
        }
        $indentThis = (float)$baseOpts['indent'] + $level * (float)$baseOpts['levelIndent'];
        foreach ($nodes as $node) {
            $runs = [];
            $children = [];
            $nodeType = strtolower($node['type'] ?? $typeDefault);
            if (isset($node['runs']) && is_array($node['runs'])) {
                foreach ($node['runs'] as $r) {
                    if ($r instanceof PdfRun) $runs[] = $r;
                    elseif (is_array($r)) $runs[] = new PdfRun((string)($r['text'] ?? ''), (array)($r['options'] ?? []));
                    elseif (is_string($r)) $runs[] = new PdfRun($r, []);
                }
            } elseif (isset($node['text'])) {
                $runs[] = new PdfRun((string)$node['text'], (array)($node['options'] ?? []));
            } elseif (is_string($node)) {
                $runs[] = new PdfRun($node, []);
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $children = $node['children'];
            }
            $markerText = ($nodeType === 'bullet')
                ? $bullet
                : $this->formatListMarker($nodeType, $counters[$level], $baseOpts);
            $markerSize = $baseOpts['markerSize'] * ($level === 0 ? 1.0 : pow($baseOpts['markerSizeScale'], $level));

            $this->styleManager->push();
            $alias = $baseOpts['markerFontAlias'] ?? $this->styleManager->getCurrentFontAlias();
            $this->styleManager->setFont($alias, $markerSize);
            $this->styleManager->setTextSpacing(0.0, 0.0);
            $markerMeasured = $this->textRenderer->measureTextStyled($markerText, $this->styleManager);
            $this->styleManager->pop();

            $markerWidth = (float)($baseOpts['markerWidth'] ?? ($markerMeasured + $baseOpts['gap']));
            $itemOpts = [
                'align' => $baseOpts['align'],
                'lineHeight' => $baseOpts['lineHeight'] ?? $this->styleManager->getLineHeight(),
                'spacing' => $baseOpts['itemSpacing'],
                'bgcolor' => $baseOpts['bgcolor'] ?? null,
                'border' => $baseOpts['border']  ?? null,
                'padding' => $baseOpts['padding'] ?? null,
                'indent' => $indentThis,
                'hangIndent' => $indentThis,
                'gap' => $baseOpts['gap'],
                'listMarker' => [
                    'text' => $markerText,
                    'width' => $markerWidth,
                    'gap' => $baseOpts['gap'],
                    'align' => $baseOpts['markerAlign'],
                    'fontAlias' => $baseOpts['markerFontAlias'] ?? $this->styleManager->getCurrentFontAlias(),
                    'size' => $markerSize,
                    'style' => $baseOpts['markerStyle'] ?? '',
                    'color' => $baseOpts['markerColor'] ?? null,
                ],
            ];
            $this->pdf->addListItem($runs ?: [''], $itemOpts);

            if ($nodeType !== 'bullet') {
                $counters[$level]++;
            }
            if (!empty($children)) {
                $this->renderListLevel($children, $baseOpts, $level + 1, $counters);
            }
        }
    }

    private function normalizeListInput(string|array $items, array $opts): array
    {
        if (is_string($items)) {
            return $this->parseMarkdownLikeList($items, $opts);
        }
        $tree = [];
        foreach ($items as $it) {
            if (is_string($it)) {
                $tree[] = ['text' => $it];
            } elseif ($it instanceof PdfRun) {
                $tree[] = ['runs' => [$it]];
            } elseif (is_array($it)) {
                $node = [];
                if (isset($it['runs'])) $node['runs'] = $it['runs'];
                if (isset($it['text'])) $node['text'] = $it['text'];
                if (isset($it['options'])) $node['options'] = $it['options'];
                if (isset($it['type'])) $node['type'] = strtolower((string)$it['type']);
                if (isset($it['children'])) $node['children'] = $it['children'];
                if ($node === []) {
                    $node['runs'] = $this->normalizeRuns($it);
                }
                $tree[] = $node;
            }
        }
        return [$tree, []];
    }

    private function parseMarkdownLikeList(string $text, array $opts): array
    {
        $lines = preg_split('/\R/u', $text);
        $tree = [];
        $stack = [];
        $stack[] = ['level' => -1, 'children' => &$tree];
        $startByLevel = [];
        $chosenTypeByLevel = [];
        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if ($line === '') continue;
            if (!preg_match('/^([ \t]*)(.*)$/', $line, $m)) continue;
            $indentStr = $m[1];
            $rest = ltrim($m[2]);
            $spaces = 0;
            $len = strlen($indentStr);
            for ($i = 0; $i < $len; $i++) {
                $spaces += ($indentStr[$i] === "\t") ? (int)$opts['tabSize'] : 1;
            }
            $level = intdiv($spaces, max(1, (int)$opts['indentUnit']));
            [$type, $startNum, $content] = $this->detectMarkerAndContent($rest);
            if ($type === null) {
                $type = $chosenTypeByLevel[$level] ?? ($opts['autoType'] ? $opts['typeByLevel'][min($level, count($opts['typeByLevel']) - 1)] : 'bullet');
            } else {
                $chosenTypeByLevel[$level] = $type;
            }
            if ($startNum !== null && !isset($startByLevel[$level])) {
                $startByLevel[$level] = max(1, $startNum);
            }
            if ($level > end($stack)['level'] + 1) {
                $level = end($stack)['level'] + 1;
            }
            while (!empty($stack) && end($stack)['level'] >= $level) array_pop($stack);
            $parent = &$stack[count($stack) - 1]['children'];
            $node = ['text' => $content, 'type' => $type, 'children' => []];
            $parent[] = $node;
            $idx = count($parent) - 1;
            $stack[] = ['level' => $level, 'children' => &$parent[$idx]['children']];
        }
        return [$tree, $startByLevel];
    }

    private function detectMarkerAndContent(string $rest): array
    {
        if (preg_match('/^([-*+])\s+(.*)$/u', $rest, $m)) {
            return ['bullet', null, $m[2]];
        }
        if (preg_match('/^(\d+)\.\s+(.*)$/u', $rest, $m)) {
            return ['decimal', (int)$m[1], $m[2]];
        }
        if (preg_match('/^([a-z])\.\s+(.*)$/u', $rest, $m)) {
            $start = ord($m[1]) - 96;
            return ['lower-alpha', $start, $m[2]];
        }
        if (preg_match('/^([A-Z])\.\s+(.*)$/u', $rest, $m)) {
            $start = ord($m[1]) - 64;
            return ['upper-alpha', $start, $m[2]];
        }
        if (preg_match('/^([ivxlcdm]+)\.\s+(.*)$/u', $rest, $m)) {
            return ['lower-roman', $this->romanToInt($m[1]), $m[2]];
        }
        if (preg_match('/^([IVXLCDM]+)\.\s+(.*)$/u', $rest, $m)) {
            return ['upper-roman', $this->romanToInt(strtolower($m[1])), $m[2]];
        }
        return [null, null, $rest];
    }

    private function romanToInt(string $roman): int
    {
        $map = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 20, 'c' => 100, 'd' => 500, 'm' => 1000];
        $roman = strtolower($roman);
        $sum = 0;
        $prev = 0;
        for ($i = strlen($roman) - 1; $i >= 0; $i--) {
            $val = $map[$roman[$i]] ?? 0;
            if ($val < $prev) $sum -= $val;
            else $sum += $val;
            $prev = $val;
        }
        return max(1, $sum);
    }

    private function normalizeRuns(array $arr): array
    {
        $runs = [];
        foreach ($arr as $r) {
            if ($r instanceof PdfRun) $runs[] = $r;
            elseif (is_array($r)) $runs[] = new PdfRun((string)($r['text'] ?? ''), (array)($r['options'] ?? []));
            elseif (is_string($r)) $runs[] = new PdfRun($r, []);
        }
        return $runs;
    }

    private function formatListMarker(string $type, int $n, array $opts): string
    {
        $bullet = (string)($opts['bulletChar'] ?? '•');
        return match ($type) {
            'decimal' => $n . '.',
            'lower-alpha' => $this->intToAlpha($n, false) . '.',
            'upper-alpha' => $this->intToAlpha($n, true) . '.',
            'lower-roman' => $this->intToRoman($n, false) . '.',
            'upper-roman' => $this->intToRoman($n, true) . '.',
            'bullet' => $bullet,
            default => $bullet,
        };
    }

    private function intToAlpha(int $n, bool $upper): string
    {
        $n = max(1, $n);
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(($n % 26) + 97) . $s;
            $n = intdiv($n, 26);
        }
        return $upper ? strtoupper($s) : $s;
    }

    private function intToRoman(int $n, bool $upper): string
    {
        $n = max(1, min(3999, $n));
        $map = [
            ['M', 1000],
            ['CM', 900],
            ['D', 500],
            ['CD', 400],
            ['C', 100],
            ['XC', 90],
            ['L', 50],
            ['XL', 40],
            ['X', 10],
            ['IX', 9],
            ['V', 5],
            ['IV', 4],
            ['I', 1]
        ];
        $out = '';
        foreach ($map as [$sym, $val]) {
            while ($n >= $val) {
                $out .= $sym;
                $n -= $val;
            }
        }
        $out = $upper ? $out : strtolower($out);
        return $out;
    }
}