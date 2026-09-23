<?php

declare(strict_types=1);

namespace Pagyra\Css;

/**
 * Recursive-descent parser for one complex selector (no top-level commas), producing the
 * structure SelectorMatcher walks:
 *
 *     ['compounds' => [['combinator' => null|' '|'>'|'+'|'~', 'tag' => ?string, 'ids' => [...],
 *       'classes' => [...], 'attributes' => [...], 'pseudos' => [...]], ...],
 *      'pseudoElement' => ?string]
 *
 * Returns null for anything it cannot read, which makes the selector match nothing — the same
 * outcome a browser gives an invalid selector, minus dropping the rest of the rule's list.
 */
final class SelectorSyntax
{
    private int $pos = 0;
    private readonly int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /** @return array{compounds:list<array>,pseudoElement:?string}|null */
    public function complexSelector(): ?array
    {
        try {
            $this->skipWhitespace();
            $compounds = [];
            $pseudoElement = null;
            $combinator = null;
            while (true) {
                $compound = $this->compound($pseudoElement);
                if ($compound === null) {
                    return null;
                }
                $compound['combinator'] = $combinator;
                $compounds[] = $compound;

                $hadSpace = $this->skipWhitespace();
                if ($this->pos >= $this->length) {
                    break;
                }
                $ch = $this->source[$this->pos];
                if ($ch === '>' || $ch === '+' || $ch === '~') {
                    $combinator = $ch;
                    $this->pos++;
                    $this->skipWhitespace();
                } elseif ($hadSpace) {
                    $combinator = ' ';
                } else {
                    return null;
                }
                if ($pseudoElement !== null) {
                    return null; // nothing may follow a pseudo-element
                }
            }

            return ['compounds' => $compounds, 'pseudoElement' => $pseudoElement];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Splits a selector list at its top-level commas, leaving commas inside parentheses,
     * brackets and strings alone (`:is(h3, h4)`, `[title="a,b"]`).
     *
     * @return list<string>
     */
    public static function splitList(string $list): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $ch = $list[$i];
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $buffer .= $list[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $parts[] = trim($buffer);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private function compound(?string &$pseudoElement): ?array
    {
        $compound = ['combinator' => null, 'tag' => null, 'ids' => [], 'classes' => [], 'attributes' => [], 'pseudos' => []];
        $start = $this->pos;

        if ($this->peek() === '*') {
            $this->pos++;
        } elseif ($this->startsIdentifier()) {
            $compound['tag'] = strtolower($this->identifier());
        }

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '#') {
                $this->pos++;
                $compound['ids'][] = $this->identifier();
            } elseif ($ch === '.') {
                $this->pos++;
                $compound['classes'][] = $this->identifier();
            } elseif ($ch === '[') {
                $compound['attributes'][] = $this->attribute();
            } elseif ($ch === ':') {
                $this->pos++;
                $double = $this->peek() === ':';
                if ($double) {
                    $this->pos++;
                }
                $name = strtolower($this->identifier());
                if ($double || SelectorMatcher::isPseudoElementName($name)) {
                    if ($pseudoElement !== null) {
                        return null;
                    }
                    $pseudoElement = $name;
                    if ($this->peek() === '(') {
                        $this->balancedArgument();
                    }
                    continue;
                }
                if ($pseudoElement !== null) {
                    // A pseudo-class after a pseudo-element (`::before:hover`) is a user-action
                    // state of the pseudo-element, which never applies on paper.
                    $compound['pseudos'][] = ['name' => 'hover'];
                    if ($this->peek() === '(') {
                        $this->balancedArgument();
                    }
                    continue;
                }
                $compound['pseudos'][] = $this->pseudoClass($name);
            } else {
                break;
            }
        }

        return $this->pos === $start ? null : $compound;
    }

    private function pseudoClass(string $name): array
    {
        $pseudo = ['name' => $name];
        if ($this->peek() !== '(') {
            return $pseudo;
        }
        $argument = $this->balancedArgument();
        switch ($name) {
            case 'not':
            case 'is':
            case 'where':
            case 'matches':
            case 'any':
            case '-webkit-any':
                $selectors = [];
                foreach (self::splitList($argument) as $part) {
                    $parsed = (new self($part))->complexSelector();
                    if ($parsed !== null) {
                        $selectors[] = $parsed;
                    }
                }
                $pseudo['selectors'] = $selectors;
                break;
            case 'nth-child':
            case 'nth-last-child':
            case 'nth-of-type':
            case 'nth-last-of-type':
                $nth = self::nth($argument);
                if ($nth === null) {
                    return ['name' => 'invalid'];
                }
                $pseudo['nth'] = $nth;
                break;
            default:
                $pseudo['argument'] = trim($argument, " \t\n\r\"'");
        }

        return $pseudo;
    }

    /** @return array{0:int,1:int}|null */
    private static function nth(string $argument): ?array
    {
        $value = strtolower(trim($argument));
        if ($value === 'odd') {
            return [2, 1];
        }
        if ($value === 'even') {
            return [2, 0];
        }
        if (preg_match('/^[+-]?\d+$/', $value) === 1) {
            return [0, (int) $value];
        }
        if (preg_match('/^([+-]?\d*)n\s*(?:([+-])\s*(\d+))?$/', $value, $m) === 1) {
            $a = match ($m[1]) { '', '+' => 1, '-' => -1, default => (int) $m[1] };
            $b = isset($m[3]) ? (int) $m[3] * ($m[2] === '-' ? -1 : 1) : 0;

            return [$a, $b];
        }

        return null;
    }

    private function attribute(): array
    {
        $this->pos++; // [
        $this->skipWhitespace();
        $name = strtolower($this->identifier());
        $this->skipWhitespace();
        $operator = null;
        $value = '';
        $insensitive = false;
        if ($this->peek() !== ']') {
            foreach (['~=', '|=', '^=', '$=', '*=', '='] as $candidate) {
                if (substr($this->source, $this->pos, strlen($candidate)) === $candidate) {
                    $operator = $candidate;
                    $this->pos += strlen($candidate);
                    break;
                }
            }
            if ($operator === null) {
                throw new \RuntimeException('bad attribute selector');
            }
            $this->skipWhitespace();
            $quote = $this->peek();
            if ($quote === '"' || $quote === "'") {
                $value = $this->string($quote);
            } else {
                $value = $this->identifier();
            }
            $this->skipWhitespace();
            if ($this->startsIdentifier()) {
                $flag = strtolower($this->identifier());
                $insensitive = $flag === 'i';
                $this->skipWhitespace();
            }
        }
        if ($this->peek() !== ']') {
            throw new \RuntimeException('unterminated attribute selector');
        }
        $this->pos++;

        return ['name' => $name, 'operator' => $operator, 'value' => $value, 'insensitive' => $insensitive];
    }

    /** The text between a `(` at the cursor and its matching `)`, cursor left after it. */
    private function balancedArgument(): string
    {
        $this->pos++; // (
        $start = $this->pos;
        $depth = 1;
        $quote = null;
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $this->pos++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $argument = substr($this->source, $start, $this->pos - $start);
                    $this->pos++;

                    return $argument;
                }
            }
            $this->pos++;
        }
        throw new \RuntimeException('unbalanced parenthesis');
    }

    private function string(string $quote): string
    {
        $this->pos++;
        $value = '';
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->length) {
                $value .= $this->escape();
                continue;
            }
            $this->pos++;
            if ($ch === $quote) {
                return $value;
            }
            $value .= $ch;
        }
        throw new \RuntimeException('unterminated string');
    }

    private function startsIdentifier(): bool
    {
        if ($this->pos >= $this->length) {
            return false;
        }
        $ch = $this->source[$this->pos];
        if ($ch === '-' ) {
            $next = $this->source[$this->pos + 1] ?? '';
            return $next !== '' && (ctype_alpha($next) || $next === '_' || $next === '-' || $next === '\\' || ord($next) >= 0x80);
        }

        return ctype_alpha($ch) || $ch === '_' || $ch === '\\' || ord($ch) >= 0x80;
    }

    private function identifier(): string
    {
        $value = '';
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\') {
                $value .= $this->escape();
                continue;
            }
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || ord($ch) >= 0x80) {
                $value .= $ch;
                $this->pos++;
                continue;
            }
            break;
        }
        if ($value === '') {
            throw new \RuntimeException('identifier expected');
        }

        return $value;
    }

    private function escape(): string
    {
        $this->pos++; // backslash
        if (preg_match('/\G[0-9a-fA-F]{1,6}/', $this->source, $m, 0, $this->pos) === 1) {
            $this->pos += strlen($m[0]);
            if ($this->pos < $this->length && ctype_space($this->source[$this->pos])) {
                $this->pos++;
            }
            $codepoint = hexdec($m[0]);

            return mb_chr($codepoint > 0 && $codepoint <= 0x10FFFF ? $codepoint : 0xFFFD, 'UTF-8') ?: '';
        }
        $ch = $this->source[$this->pos] ?? '';
        $this->pos++;

        return $ch;
    }

    private function peek(): string
    {
        return $this->source[$this->pos] ?? '';
    }

    private function skipWhitespace(): bool
    {
        $start = $this->pos;
        while ($this->pos < $this->length && ctype_space($this->source[$this->pos])) {
            $this->pos++;
        }

        return $this->pos > $start;
    }
}
