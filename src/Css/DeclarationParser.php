<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class DeclarationParser
{
    /** @return array<string,string> */
    public function parse(string $css): array
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

            $declarations[strtolower($property)] = $value;
        }

        ksort($declarations);
        return $declarations;
    }
}
