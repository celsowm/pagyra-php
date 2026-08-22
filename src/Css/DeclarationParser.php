<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class DeclarationParser
{
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
        foreach (explode(';', $css) as $chunk) {
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

            $declarations[strtolower($property)] = ['value' => $value, 'important' => $important];
        }

        ksort($declarations);
        return $declarations;
    }
}
