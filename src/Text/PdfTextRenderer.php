<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Text;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\PdfFontManager;
use Celsowm\PagyraPhp\Style\PdfStyleManager;


final class PdfTextRenderer
{
    private PdfBuilder $pdf;
    private PdfFontManager $fontManager;

    public function __construct(PdfBuilder $pdf, PdfFontManager $fontManager)
    {
        $this->pdf = $pdf;
        $this->fontManager = $fontManager;
    }

    public function measureTextStyled(string $s, PdfStyleManager $styleManager): float
    {
        $baseAlias = $styleManager->getCurrentFontAlias();
        if ($baseAlias === null) {
            return 0.0;
        }
        $style = $styleManager->getStyle();
        $alias = $this->fontManager->resolveAliasByStyle($baseAlias, $style);
        $fonts = $this->fontManager->getFonts();
        $font = $fonts[$alias] ?? $fonts[$baseAlias];
        $cps = $this->utf8ToCodepoints($s);
        $wUnits = 0;
        $spaceCount = 0;
        foreach ($cps as $cp) {
            if ($cp === 0x20) $spaceCount++;
            $gid = $font['cmap'][$cp] ?? 0;
            $wUnits += $font['adv'][$gid] ?? 0;
        }
        $base = ($wUnits / $font['unitsPerEm']) * $styleManager->getCurrentFontSize();
        $charCount = count($cps);
        $pairs = max(0, $charCount - 1);
        $extra = ($pairs * $styleManager->getLetterSpacing()) + ($spaceCount * $styleManager->getWordSpacing());
        return $base + $extra;
    }

    public function writeTextLine(float $x, float $y, string $utf8, PdfStyleManager $styleManager, ?array $shadowSpec = null): void
    {
        if ($this->pdf->getCurrentPage() === null) return;

        $currentFontAlias = $styleManager->getCurrentFontAlias();
        if ($currentFontAlias === null) return;

        $style = $styleManager->getStyle();
        $size = $styleManager->getCurrentFontSize();
        $wordSpacing = $styleManager->getWordSpacing();
        $letterSpacing = $styleManager->getLetterSpacing();
        $textColor = $styleManager->getTextColor() ?? ['space' => 'gray', 'v' => [0.0]];

        $aliasResolved = $this->fontManager->resolveAliasByStyle($currentFontAlias, $style);
        $label = '/' . $aliasResolved;
        $this->pdf->registerPageResource('Font', $label);

        $skew = 0.0;
        if (str_contains(strtoupper($style), 'I')) {
            $base = $currentFontAlias;
            $hasRealItalic = ($aliasResolved !== $base);
            if (!$hasRealItalic) {
                $skew = tan(deg2rad($styleManager->getItalicAngleDeg()));
            }
        }

        $useTJ = !(abs($wordSpacing) < 1e-6);
        $elemsTJ = null;
        if ($useTJ) {
            $wsNum = - ($wordSpacing * 1000.0 / $size);
            $parts = preg_split('/( )/u', $utf8, -1, PREG_SPLIT_DELIM_CAPTURE);
            $elems = [];
            $last = count($parts) - 1;
            foreach ($parts as $i => $part) {
                if ($part === '') continue;
                $elems[] = $this->utf8ToHexStringForTJ($part, $aliasResolved);
                if ($part === ' ' && $i !== $last) {
                    $elems[] = sprintf('%.3F', $wsNum);
                }
            }
            $elemsTJ = $elems;
        }

        if ($shadowSpec !== null) {
            $this->pdf->appendToPageContent($this->emitShadowTextOps($label, $size, $skew, $x, $y, $utf8, $elemsTJ, $shadowSpec, $styleManager));
        }

        $styleOps = $this->beginTextStyleOps($style, $size, $textColor, $this->pdf);
        $s = $styleOps;
        $s .= "BT\n";
        $s .= sprintf("%s %.3F Tf\n", $label, $size);
        $s .= $this->pdf->colorOps($textColor);
        $s .= sprintf("1 0 %.5F 1 %.3F %.3F Tm\n", $skew, $x, $y);
        $s .= $this->textStateSpacingOps($letterSpacing);

        if ($elemsTJ === null) {
            $hex = $this->utf8ToHexStringForTJ($utf8, $aliasResolved);
            $s .= "{$hex} Tj\n";
        } else {
            $s .= "[ " . implode(' ', $elemsTJ) . " ] TJ\n";
        }

        $s .= "ET\n";
        if (!empty($styleOps)) {
            $s .= $this->endTextStyleOps();
        }

        if ($this->hasUnderline($style)) {
            $textWidth = $this->measureTextStyled($utf8, $styleManager);
            $s .= $this->drawUnderlineOps($x, $y, $textWidth, $size, $textColor);
        }
        $this->pdf->appendToPageContent($s);
    }

    private function utf8ToHexStringForTJ(string $utf8, string $alias): string
    {
        $fonts = $this->fontManager->getFonts();
        $font = $fonts[$alias];
        $cps = $this->utf8ToCodepoints($utf8);
        $gids = [];
        foreach ($cps as $cp) {
            $gid = $font['cmap'][$cp] ?? 0;
            $gids[] = $gid;
            $this->fontManager->updateUsedGid($alias, $gid, $cp);
        }
        $hex = $gids ? strtoupper(bin2hex(pack('n*', ...$gids))) : '';
        return "<{$hex}>";
    }

    private function utf8ToCodepoints(string $s): array
    {
        $cps = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b1 = ord($s[$i]);
            if ($b1 < 0x80) {
                $cps[] = $b1;
            } elseif (($b1 & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cps[] = (($b1 & 0x1F) << 6) | (ord($s[++$i]) & 0x3F);
            } elseif (($b1 & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cps[] = (($b1 & 0x0F) << 12) | ((ord($s[++$i]) & 0x3F) << 6) | (ord($s[++$i]) & 0x3F);
            } elseif (($b1 & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cps[] = (($b1 & 0x07) << 18) | ((ord($s[++$i]) & 0x3F) << 12) | ((ord($s[++$i]) & 0x3F) << 6) | ((ord($s[++$i]) & 0x3F) << 6) | (ord($s[++$i]) & 0x3F);
            } else {
                $cps[] = 0xFFFD;
            }
        }
        return $cps;
    }

    private function hasUnderline(string $style): bool
    {
        return str_contains(strtoupper($style), 'U');
    }

    private function beginTextStyleOps(string $style, float $fontSize, ?array $textColor, PdfBuilder $pdf): string
    {
        $style = strtoupper($style);
        $bold = str_contains($style, 'B');
        if (!$bold) {
            return '';
        }
        $ops = "q\n";
        if ($bold) {
            $w = max(0.5, $fontSize * 0.04);
            $ops .= sprintf("%.3F w\n", $w);
            $ops .= "2 Tr\n";
            $ops .= $pdf->strokeColorOps($textColor ?? ['space' => 'gray', 'v' => [0.0]]);
        }
        return $ops;
    }

    private function endTextStyleOps(): string
    {
        return "Q\n";
    }

    private function drawUnderlineOps(float $x, float $yBaseline, float $textWidth, float $fontSize, ?array $textColor): string
    {
        $pos = -0.20 * $fontSize;
        $th = max(0.3, 0.06 * $fontSize);
        $y = $yBaseline + $pos;
        $ops = "q\n";
        $ops .= $this->pdf->strokeColorOps($textColor);
        $ops .= sprintf("%.3F w\n", $th);
        $ops .= sprintf("%.3F %.3F m\n", $x, $y);
        $ops .= sprintf("%.3F %.3F l\n", $x + $textWidth, $y);
        $ops .= "S\nQ\n";
        return $ops;
    }

    private function emitShadowTextOps(string $label, float $size, float $skew, float $x, float $y, string $utf8, ?array $elemsTJ, ?array $shadow, PdfStyleManager $styleManager): string
    {
        if ($shadow === null) return '';
        $dx = $shadow['dx'];
        $dy = $shadow['dy'];
        $blur = $shadow['blur'];
        $samp = $shadow['samples'];
        $one = function (float $offX, float $offY) use ($label, $size, $skew, $x, $y, $utf8, $elemsTJ, $shadow, $styleManager): string {
            $ops = "q\n";
            if ($shadow['alpha'] < 1.0) {
                $gs = $this->pdf->ensureExtGState($shadow['alpha']);
                if ($this->pdf->getCurrentPage() !== null) {
                    $this->pdf->registerPageResource('ExtGState', $gs);
                }
                $ops .= "{$gs} gs\n";
            }
            $ops .= "BT\n";
            $ops .= sprintf("%s %.3F Tf\n", $label, $size);
            $ops .= "0 Tr\n";
            $ops .= $this->pdf->colorOps($shadow['color']);
            $ops .= sprintf("1 0 %.5F 1 %.3F %.3F Tm\n", $skew, $x + $offX, $y + $offY);
            $ops .= $this->textStateSpacingOps($styleManager->getLetterSpacing());
            if ($elemsTJ !== null) {
                $ops .= "[ " . implode(' ', $elemsTJ) . " ] TJ\n";
            } else {
                $ops .= $this->utf8ToHexStringForTJ($utf8, ltrim($label, '/')) . " Tj\n";
            }
            $ops .= "ET\nQ\n";
            return $ops;
        };
        $opsAll = '';
        if ($blur <= 0.0001) {
            $opsAll .= $one($dx, $dy);
        } else {
            $R = $blur;
            $N = $samp;
            for ($i = 0; $i < $N; $i++) {
                $ang = 2.0 * M_PI * ($i / $N);
                $opsAll .= $one($dx + $R * cos($ang), $dy + $R * sin($ang));
            }
        }
        return $opsAll;
    }

    private function textStateSpacingOps(float $letterSpacing): string
    {
        $ls = abs($letterSpacing) > 1e-6 ? $letterSpacing : 0.0;
        return sprintf("%.3F Tc\n0 Tw\n", $ls);
    }
}
