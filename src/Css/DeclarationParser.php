<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class DeclarationParser
{
    public function __construct(
        private readonly BorderShorthandExpander $borderShorthandExpander = new BorderShorthandExpander(),
        private readonly BackgroundShorthandExpander $backgroundShorthandExpander = new BackgroundShorthandExpander(),
    ) {
    }

    /** @return array<string,string> */
    public function parse(string $css): array
    {
        $plain = [];
        foreach ($this->parseWithPriority($css) as $property => $entry) {
            $plain[$property] = $entry['value'];
        }
        ksort($plain);
        return $plain;
    }

    /** @return array<string,array{value:string,important:bool}> */
    public function parseWithPriority(string $css): array
    {
        $declarations = [];
        foreach ($this->splitDeclarations($css) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $chunk, 2));
            if ($property === '' || $value === '') {
                continue;
            }

            $important = preg_match('/!\s*important\s*$/i', $value) === 1;
            if ($important) {
                $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
            }
            if ($value === '') {
                continue;
            }

            $property = strtolower($property);
            $expanded = $this->borderShorthandExpander->expand($property, $value)
                ?? $this->backgroundShorthandExpander->expand($property, $value);
            if ($expanded !== null) {
                foreach ($expanded as $expandedProperty => $expandedValue) {
                    $this->assignDeclaration($declarations, $expandedProperty, $expandedValue, $important);
                }
                continue;
            }

            $this->assignDeclaration($declarations, $property, $value, $important);
        }

        ksort($declarations);
        return $declarations;
    }

    /** @param array<string,array{value:string,important:bool}> $declarations */
    private function assignDeclaration(array &$declarations, string $property, string $value, bool $important): void
    {
        $current = $declarations[$property] ?? null;
        if ($current !== null && $current['important'] && !$important) return;
        $declarations[$property] = ['value' => $value, 'important' => $important];
    }

    /** @return list<string> */
    private function splitDeclarations(string $css): array
    {
        $chunks = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $escaped = false;
        $length = strlen($css);

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $buffer .= $char;
                $escaped = true;
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && $depth === 0) {
                $chunks[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
