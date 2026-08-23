<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

final class PageFlow implements \JsonSerializable
{
    /**
     * Backward-compatible default usable height. Variable-profile callers
     * should use usableHeightForPage().
     */
    public readonly float $contentHeight;

    private readonly float $pageHeight;

    /** @var array{default:array{top:float,right:float,bottom:float,left:float},first?:array{top:float,right:float,bottom:float,left:float},left?:array{top:float,right:float,bottom:float,left:float},right?:array{top:float,right:float,bottom:float,left:float}} */
    private readonly array $margins;

    /** @var list<float> */
    private array $contentStarts = [0.0];

    /**
     * Uniform backward-compatible constructor by default.
     *
     * @param array{default:array{top:float,right:float,bottom:float,left:float},first?:array{top:float,right:float,bottom:float,left:float},left?:array{top:float,right:float,bottom:float,left:float},right?:array{top:float,right:float,bottom:float,left:float}}|null $margins
     */
    public function __construct(float $contentHeight, ?float $pageHeight = null, ?array $margins = null)
    {
        if ($contentHeight <= 0.0) {
            throw new \InvalidArgumentException('contentHeight must be greater than zero');
        }

        if ($pageHeight === null || $margins === null) {
            $this->pageHeight = $contentHeight;
            $this->margins = [
                'default' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ];
            $this->contentHeight = $contentHeight;
            return;
        }

        if ($pageHeight <= 0.0) {
            throw new \InvalidArgumentException('pageHeight must be greater than zero');
        }
        if (!isset($margins['default'])) {
            throw new \InvalidArgumentException('Variable page flow requires default margins');
        }

        $this->pageHeight = $pageHeight;
        $this->margins = $this->normalizeProfile($margins);
        $default = $this->margins['default'];
        $this->contentHeight = max(1.0, $pageHeight - $default['top'] - $default['bottom']);
    }

    /**
     * @param array{default:array{top:float,right:float,bottom:float,left:float},first?:array{top:float,right:float,bottom:float,left:float},left?:array{top:float,right:float,bottom:float,left:float},right?:array{top:float,right:float,bottom:float,left:float}} $margins
     */
    public static function fromPageProfile(float $pageHeight, array $margins): self
    {
        $default = $margins['default'];
        $contentHeight = max(1.0, $pageHeight - $default['top'] - $default['bottom']);
        return new self($contentHeight, $pageHeight, $margins);
    }

    /** @return array{top:float,right:float,bottom:float,left:float} */
    public function marginsForPage(int $pageIndex): array
    {
        $index = max(0, $pageIndex);
        if ($index === 0 && isset($this->margins['first'])) {
            return $this->margins['first'];
        }
        if ($index % 2 === 0) {
            return $this->margins['right'] ?? $this->margins['default'];
        }
        return $this->margins['left'] ?? $this->margins['default'];
    }

    public function usableHeightForPage(int $pageIndex): float
    {
        $margins = $this->marginsForPage($pageIndex);
        return max(1.0, $this->pageHeight - $margins['top'] - $margins['bottom']);
    }

    public function effectiveTopForPage(int $pageIndex): float
    {
        return $this->marginsForPage($pageIndex)['top'];
    }

    public function pageIndexAt(float $contentY): int
    {
        if (!is_finite($contentY) || $contentY <= 0.0) {
            return 0;
        }

        $last = count($this->contentStarts) - 1;
        while ($contentY >= $this->contentStarts[$last] + $this->usableHeightForPage($last)) {
            $this->contentStarts[] = $this->contentStarts[$last] + $this->usableHeightForPage($last);
            $last++;
        }

        $low = 0;
        $high = count($this->contentStarts) - 1;
        while ($low < $high) {
            $middle = (int) ceil(($low + $high) / 2);
            if ($this->contentStarts[$middle] <= $contentY) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return $low;
    }

    public function contentStartForPage(int $pageIndex): float
    {
        $target = max(0, $pageIndex);
        while (count($this->contentStarts) <= $target) {
            $previous = count($this->contentStarts) - 1;
            $this->contentStarts[] = $this->contentStarts[$previous] + $this->usableHeightForPage($previous);
        }
        return $this->contentStarts[$target];
    }

    public function minimumUsableHeight(): float
    {
        return min(
            $this->usableHeightForPage(0),
            $this->usableHeightForPage(1),
            $this->usableHeightForPage(2),
        );
    }

    public function maximumUsableHeight(): float
    {
        return max(
            $this->usableHeightForPage(0),
            $this->usableHeightForPage(1),
            $this->usableHeightForPage(2),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'contentHeight' => $this->contentHeight,
            'pageHeight' => $this->pageHeight,
            'margins' => $this->margins,
        ];
    }

    /**
     * @param array<string,array{top:float,right:float,bottom:float,left:float}> $profile
     * @return array{default:array{top:float,right:float,bottom:float,left:float},first?:array{top:float,right:float,bottom:float,left:float},left?:array{top:float,right:float,bottom:float,left:float},right?:array{top:float,right:float,bottom:float,left:float}}
     */
    private function normalizeProfile(array $profile): array
    {
        $result = [];
        foreach (['default', 'first', 'left', 'right'] as $variant) {
            if (!isset($profile[$variant])) continue;
            $entry = $profile[$variant];
            $result[$variant] = [
                'top' => max(0.0, (float) ($entry['top'] ?? 0.0)),
                'right' => max(0.0, (float) ($entry['right'] ?? 0.0)),
                'bottom' => max(0.0, (float) ($entry['bottom'] ?? 0.0)),
                'left' => max(0.0, (float) ($entry['left'] ?? 0.0)),
            ];
        }
        /** @var array{default:array{top:float,right:float,bottom:float,left:float},first?:array{top:float,right:float,bottom:float,left:float},left?:array{top:float,right:float,bottom:float,left:float},right?:array{top:float,right:float,bottom:float,left:float}} $result */
        return $result;
    }
}
