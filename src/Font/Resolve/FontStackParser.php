<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Resolve;

final class FontStackParser
{
    /**
     * @return array<int, string>
     */
    public function parse(?string $fontFamily): array
    {
        if ($fontFamily === null) {
            return [];
        }
        $input = trim($fontFamily);
        if ($input === '') {
            return [];
        }
        $tokens = [];
        $buffer = '';
        $length = strlen($input);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $input[$i + 1];
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                    continue;
                }
                $buffer .= $char;
                continue;
            }
            if ($char === '"' || $char === '\'') {
                $quote = $char;
                continue;
            }
            if ($char === ',') {
                $tokens[] = $this->normalizeToken($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($buffer !== '' || $quote === null) {
            $tokens[] = $this->normalizeToken($buffer);
        }
        $tokens = array_filter($tokens, static fn(string $token): bool => $token !== '');
        return array_values($tokens);
    }

    private function normalizeToken(string $token): string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return '';
        }
        $trimmed = trim($trimmed, "\"' ");
        $normalized = preg_replace('/\s+/', ' ', $trimmed);
        return is_string($normalized) ? $normalized : $trimmed;
    }
}
