<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html\Style;

final class ComputedStyle
{
    /**
     * @var array<string, array{value: string, specificity: array<int, int>, order: int}>
     */
    private array $properties = [];

    private static array $inheritableProperties = [
        'color',
        'font',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'letter-spacing',
        'line-height',
        'list-style',
        'list-style-image',
        'list-style-position',
        'list-style-type',
        'text-align',
        'text-indent',
        'text-transform',
        'white-space',
        'word-spacing',
    ];

    public function inheritFrom(ComputedStyle $parentStyle): void
    {
        foreach ($parentStyle->properties as $name => $meta) {
            if (in_array($name, self::$inheritableProperties, true)) {
                // Inherited properties have the lowest specificity and order.
                $this->properties[$name] = [
                    'value' => $meta['value'],
                    'specificity' => [0, 0, 0],
                    'order' => -1,
                ];
            }
        }
    }

    /**
     * @param array<string, string> $declarations
     * @param array<int, int> $specificity
     */
    public function applyDeclarations(array $declarations, array $specificity, int $order): void
    {
        foreach ($declarations as $name => $value) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || $value === '') {
                continue;
            }
            $value = trim((string)$value);
            $current = $this->properties[$name] ?? null;
            if ($current === null || $this->shouldOverride($current['specificity'], $current['order'], $specificity, $order)) {
                $this->properties[$name] = [
                    'value' => $value,
                    'specificity' => $specificity,
                    'order' => $order,
                ];
            }
        }
    }

    /**
     * @param array<int, int> $existingSpec
     * @param array<int, int> $incomingSpec
     */
    private function shouldOverride(array $existingSpec, int $existingOrder, array $incomingSpec, int $incomingOrder): bool
    {
        if ($incomingSpec[0] !== $existingSpec[0]) {
            return $incomingSpec[0] > $existingSpec[0];
        }
        if ($incomingSpec[1] !== $existingSpec[1]) {
            return $incomingSpec[1] > $existingSpec[1];
        }
        if ($incomingSpec[2] !== $existingSpec[2]) {
            return $incomingSpec[2] > $existingSpec[2];
        }
        return $incomingOrder >= $existingOrder;
    }

    public function get(string $property, ?string $default = null): ?string
    {
        $property = strtolower($property);
        if (!isset($this->properties[$property])) {
            return $default;
        }
        return $this->properties[$property]['value'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->properties as $name => $meta) {
            $out[$name] = $meta['value'];
        }
        return $out;
    }
}