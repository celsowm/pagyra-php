<?php

declare(strict_types=1);

namespace Pagyra\Style;

/**
 * CSS counters and their scopes (CSS Lists 3 §4.4, CSS 2.1 §12.4.1), kept while the style tree is
 * walked in document order.
 *
 * A counter created by `counter-reset` on an element (or implicitly by `counter-increment` when
 * none is in scope) is visible to that element, its descendants and its following siblings with
 * their descendants — so it lives until the element's parent is finished. Each instance records
 * the tree depth of the element that created it, and `closeScopesFrom($depth)` drops every
 * instance created at that depth or deeper when a parent at `$depth - 1` is done.
 */
final class CounterScopes
{
    /** @var array<string,list<array{value:int,depth:int}>> innermost instance last */
    private array $counters = [];

    /** Nesting level of `open-quote`/`close-quote`. */
    public int $quoteDepth = 0;

    public function reset(): void
    {
        $this->counters = [];
        $this->quoteDepth = 0;
    }

    /** Applies an element's `counter-reset`, `counter-set` and `counter-increment`, in that order. */
    public function apply(ComputedStyle $style, int $depth): void
    {
        foreach (self::pairs($style->get('counter-reset'), 0) as [$name, $value]) {
            $this->counters[$name][] = ['value' => $value, 'depth' => $depth];
        }
        foreach (self::pairs($style->get('counter-set'), 0) as [$name, $value]) {
            $this->ensure($name, $depth);
            $this->counters[$name][array_key_last($this->counters[$name])]['value'] = $value;
        }
        foreach (self::pairs($style->get('counter-increment'), 1) as [$name, $value]) {
            $this->ensure($name, $depth);
            $this->counters[$name][array_key_last($this->counters[$name])]['value'] += $value;
        }
    }

    /** The innermost value of a counter, 0 when none is in scope (CSS 2.1: it is then reset). */
    public function value(string $name): int
    {
        $instances = $this->counters[$name] ?? [];

        return $instances === [] ? 0 : $instances[array_key_last($instances)]['value'];
    }

    /** @return list<int> every value of the counter from the outermost scope in, for counters() */
    public function values(string $name): array
    {
        $values = array_map(static fn(array $instance): int => $instance['value'], $this->counters[$name] ?? []);

        return $values === [] ? [0] : $values;
    }

    public function closeScopesFrom(int $depth): void
    {
        foreach ($this->counters as $name => $instances) {
            while ($instances !== [] && $instances[array_key_last($instances)]['depth'] >= $depth) {
                array_pop($instances);
            }
            if ($instances === []) {
                unset($this->counters[$name]);
            } else {
                $this->counters[$name] = $instances;
            }
        }
    }

    private function ensure(string $name, int $depth): void
    {
        if (($this->counters[$name] ?? []) === []) {
            $this->counters[$name] = [['value' => 0, 'depth' => $depth]];
        }
    }

    /**
     * `name [integer]? name [integer]? ...` or `none`.
     *
     * @return list<array{0:string,1:int}>
     */
    private static function pairs(?string $value, int $default): array
    {
        $value = trim($value ?? '');
        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }
        $tokens = preg_split('/\s+/', $value) ?: [];
        $pairs = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $name = $tokens[$i];
            if (preg_match('/^-?[a-zA-Z_][\w-]*$/', $name) !== 1 || in_array(strtolower($name), ['none', 'inherit', 'initial', 'unset'], true)) {
                continue;
            }
            $amount = $default;
            if (isset($tokens[$i + 1]) && preg_match('/^[+-]?\d+$/', $tokens[$i + 1]) === 1) {
                $amount = (int) $tokens[++$i];
            }
            $pairs[] = [$name, $amount];
        }

        return $pairs;
    }
}
