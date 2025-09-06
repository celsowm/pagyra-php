<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Style;
use Celsowm\PagyraPhp\Core\PdfBuilder;


final class PdfStyleManager
{
    private array $styleStack = [];
    private array $currentState;

    public function __construct()
    {
        $this->currentState = [
            'fontAlias'      => null,
            'fontSize'       => 12.0,
            'lineHeight'     => 15.0,
            'textColor'      => ['space' => 'gray', 'v' => [0.0]],
            'letterSpacing'  => 0.0,
            'wordSpacing'    => 0.0,
            'italicAngleDeg' => 12.0,
            'style'          => '',
        ];
    }

    public function push(): void
    {
        $this->styleStack[] = $this->currentState;
    }

    public function pop(): bool
    {
        if (empty($this->styleStack)) {
            return false;
        }
        $this->currentState = array_pop($this->styleStack);
        return true;
    }

    public function getCurrentFontAlias(): ?string
    {
        return $this->currentState['fontAlias'];
    }

    public function getCurrentFontSize(): float
    {
        return $this->currentState['fontSize'];
    }

    public function getLineHeight(): float
    {
        return $this->currentState['lineHeight'];
    }

    public function getTextColor(): ?array
    {
        return $this->currentState['textColor'];
    }

    public function getLetterSpacing(): float
    {
        return $this->currentState['letterSpacing'];
    }

    public function getWordSpacing(): float
    {
        return $this->currentState['wordSpacing'];
    }

    public function getItalicAngleDeg(): float
    {
        return $this->currentState['italicAngleDeg'];
    }

    public function getStyle(): string
    {
        return $this->currentState['style'];
    }

    public function setFont(string $alias, float $size, ?float $lineHeight = null): self
    {
        $this->currentState['fontAlias'] = $alias;
        $this->currentState['fontSize'] = $size;
        $this->currentState['lineHeight'] = $lineHeight ?? $size * 1.25;
        return $this;
    }

    public function setTextColor(?array $color): self
    {
        $this->currentState['textColor'] = $color;
        return $this;
    }

    public function setTextSpacing(?float $letter, ?float $word): self
    {
        if ($letter !== null) {
            $this->currentState['letterSpacing'] = $letter;
        }
        if ($word !== null) {
            $this->currentState['wordSpacing'] = $word;
        }
        return $this;
    }

    public function applyOptions(array $options, PdfBuilder $pdf): void
    {
        if (isset($options['fontAlias'])) {
            $this->currentState['fontAlias'] = $options['fontAlias'];
        }
        if (isset($options['size'])) {
            $this->currentState['fontSize'] = (float)$options['size'];
        }
        if (isset($options['color'])) {
            $this->currentState['textColor'] = $pdf->normalizeColor($options['color']);
        }
        if (isset($options['letterSpacing'])) {
            $this->currentState['letterSpacing'] = (float)$options['letterSpacing'];
        }
        if (isset($options['wordSpacing'])) {
            $this->currentState['wordSpacing'] = (float)$options['wordSpacing'];
        }
        if (isset($options['style'])) {
            $this->currentState['style'] = (string)$options['style'];
        }
        if (isset($options['lineHeight'])) {
            $this->currentState['lineHeight'] = (float)$options['lineHeight'];
        }
    }
}