<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

use Pagyra\Units\Units;

/**
 * The CSS math functions — `calc()`, `min()`, `max()`, `clamp()` — parsed into a small tree and
 * evaluated when the length is resolved, because `%`, `em` and the container units only get a
 * value then (CSS Values 4 §10).
 *
 * The previous parser only summed `<number><unit>` terms it found by regex: `*` and `/` were
 * dropped (`calc(100% / 3)` came out as 100%), bare numbers were skipped, viewport units were
 * unknown, and `min()`/`max()`/`clamp()` were not recognised at all, so the whole declaration
 * was lost.
 *
 * Each evaluated node yields a value and whether it is a length (px) or a plain number, so
 * `2 * 10px` and `10px / 2` work and `10px * 10px` is rejected like a browser would.
 */
final readonly class MathExpression
{
    /** @param array<string,mixed> $root */
    private function __construct(private array $root)
    {
    }

    public static function parse(string $value, float $viewportWidth, float $viewportHeight): ?self
    {
        $tokens = self::tokenize(strtolower(trim($value)));
        if ($tokens === null) {
            return null;
        }
        $position = 0;
        $node = self::parseFactor($tokens, $position, $viewportWidth, $viewportHeight);
        if ($node === null || $position !== count($tokens) || $node['type'] !== 'function') {
            return null;
        }

        return new self($node);
    }

    public function evaluate(float $reference, float $fontSize, float $rootFontSize, float $containerWidth, float $containerHeight): float
    {
        $result = self::evaluateNode($this->root, [
            'reference' => $reference,
            'fontSize' => $fontSize,
            'rootFontSize' => $rootFontSize,
            'containerWidth' => $containerWidth,
            'containerHeight' => $containerHeight,
        ]);

        return $result === null || !is_finite($result[0]) ? 0.0 : $result[0];
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,float> $context
     * @return array{0:float,1:bool}|null value and whether it is a length
     */
    private static function evaluateNode(array $node, array $context): ?array
    {
        switch ($node['type']) {
            case 'number':
                return [$node['value'], false];
            case 'length':
                return [$node['value'], true];
            case 'relative':
                $value = $node['value'];
                return [match ($node['unit']) {
                    '%' => $value / 100.0 * $context['reference'],
                    'em' => $value * $context['fontSize'],
                    'rem' => $value * $context['rootFontSize'],
                    'ex', 'ch' => $value * 0.5 * $context['fontSize'],
                    'cqw', 'cqi' => $value / 100.0 * $context['containerWidth'],
                    'cqh', 'cqb' => $value / 100.0 * $context['containerHeight'],
                    'cqmin' => $value / 100.0 * min($context['containerWidth'], $context['containerHeight']),
                    'cqmax' => $value / 100.0 * max($context['containerWidth'], $context['containerHeight']),
                    default => 0.0,
                }, true];
            case 'binary':
                $left = self::evaluateNode($node['left'], $context);
                $right = self::evaluateNode($node['right'], $context);
                if ($left === null || $right === null) return null;
                switch ($node['operator']) {
                    case '+':
                    case '-':
                        if ($left[1] !== $right[1] && !($left[0] == 0.0 || $right[0] == 0.0)) return null;
                        $sum = $node['operator'] === '+' ? $left[0] + $right[0] : $left[0] - $right[0];
                        return [$sum, $left[1] || $right[1]];
                    case '*':
                        if ($left[1] && $right[1]) return null;
                        return [$left[0] * $right[0], $left[1] || $right[1]];
                    default:
                        if ($right[1] || $right[0] == 0.0) return null;
                        return [$left[0] / $right[0], $left[1]];
                }
            case 'function':
                $values = [];
                foreach ($node['arguments'] as $argument) {
                    $value = self::evaluateNode($argument, $context);
                    if ($value === null) return null;
                    $values[] = $value;
                }
                $isLength = array_reduce($values, static fn(bool $carry, array $v): bool => $carry || $v[1], false);
                $numbers = array_map(static fn(array $v): float => $v[0], $values);
                return match ($node['name']) {
                    'calc' => $values[0],
                    'min' => [min($numbers), $isLength],
                    'max' => [max($numbers), $isLength],
                    'clamp' => count($numbers) === 3 ? [max($numbers[0], min($numbers[1], $numbers[2])), $isLength] : null,
                    default => null,
                };
        }

        return null;
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @return array<string,mixed>|null
     */
    private static function parseSum(array $tokens, int &$position, float $vw, float $vh): ?array
    {
        $left = self::parseProduct($tokens, $position, $vw, $vh);
        while ($left !== null && isset($tokens[$position]) && $tokens[$position][0] === 'op' && in_array($tokens[$position][1], ['+', '-'], true)) {
            $operator = $tokens[$position++][1];
            $right = self::parseProduct($tokens, $position, $vw, $vh);
            if ($right === null) return null;
            $left = ['type' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /** @param list<array{0:string,1:string}> $tokens */
    private static function parseProduct(array $tokens, int &$position, float $vw, float $vh): ?array
    {
        $left = self::parseFactor($tokens, $position, $vw, $vh);
        while ($left !== null && isset($tokens[$position]) && $tokens[$position][0] === 'op' && in_array($tokens[$position][1], ['*', '/'], true)) {
            $operator = $tokens[$position++][1];
            $right = self::parseFactor($tokens, $position, $vw, $vh);
            if ($right === null) return null;
            $left = ['type' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /** @param list<array{0:string,1:string}> $tokens */
    private static function parseFactor(array $tokens, int &$position, float $vw, float $vh): ?array
    {
        $token = $tokens[$position] ?? null;
        if ($token === null) return null;

        if ($token[0] === 'function') {
            $position++;
            $arguments = [];
            while (true) {
                $argument = self::parseSum($tokens, $position, $vw, $vh);
                if ($argument === null) return null;
                $arguments[] = $argument;
                $next = $tokens[$position++] ?? null;
                if ($next === null) return null;
                if ($next[0] === 'close') break;
                if ($next[0] !== 'comma') return null;
            }
            if ($token[1] === 'calc' && count($arguments) !== 1) return null;
            return ['type' => 'function', 'name' => $token[1], 'arguments' => $arguments];
        }
        if ($token[0] === 'open') {
            $position++;
            $inner = self::parseSum($tokens, $position, $vw, $vh);
            if ($inner === null || ($tokens[$position][0] ?? null) !== 'close') return null;
            $position++;
            return $inner;
        }
        if ($token[0] !== 'dimension') return null;
        $position++;
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)([a-z%]*)$/', $token[1], $m) !== 1) return null;
        $value = (float) $m[1];

        return match ($m[2]) {
            '' => ['type' => 'number', 'value' => $value],
            'px' => ['type' => 'length', 'value' => $value],
            'pt' => ['type' => 'length', 'value' => Units::ptToPx($value)],
            'pc' => ['type' => 'length', 'value' => Units::pcToPx($value)],
            'in' => ['type' => 'length', 'value' => Units::inToPx($value)],
            'cm' => ['type' => 'length', 'value' => Units::cmToPx($value)],
            'mm' => ['type' => 'length', 'value' => Units::mmToPx($value)],
            'q' => ['type' => 'length', 'value' => Units::qToPx($value)],
            'vw' => ['type' => 'length', 'value' => $value / 100.0 * $vw],
            'vh' => ['type' => 'length', 'value' => $value / 100.0 * $vh],
            'vmin' => ['type' => 'length', 'value' => $value / 100.0 * min($vw, $vh)],
            'vmax' => ['type' => 'length', 'value' => $value / 100.0 * max($vw, $vh)],
            '%', 'em', 'rem', 'ex', 'ch', 'cqw', 'cqh', 'cqi', 'cqb', 'cqmin', 'cqmax' => ['type' => 'relative', 'value' => $value, 'unit' => $m[2]],
            default => null,
        };
    }

    /** @return list<array{0:string,1:string}>|null */
    private static function tokenize(string $value): ?array
    {
        $tokens = [];
        $length = strlen($value);
        $i = 0;
        while ($i < $length) {
            $ch = $value[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (preg_match('/\G(calc|min|max|clamp|-webkit-calc|-moz-calc)\(/', $value, $m, 0, $i) === 1) {
                $tokens[] = ['function', str_ends_with($m[1], 'calc') ? 'calc' : $m[1]];
                $i += strlen($m[0]);
                continue;
            }
            // A sign glued to a number is part of it unless it follows a value, in which case it
            // is the operator of `a-b`, which CSS itself only allows with spaces around it.
            $previous = $tokens === [] ? null : $tokens[count($tokens) - 1][0];
            $signAllowed = $previous === null || in_array($previous, ['op', 'open', 'function', 'comma'], true);
            $pattern = $signAllowed ? '/\G[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?(?:[a-z]+|%)?/' : '/\G(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?(?:[a-z]+|%)?/';
            if (preg_match($pattern, $value, $m, 0, $i) === 1) {
                $tokens[] = ['dimension', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            $tokens[] = match ($ch) {
                '+', '-', '*', '/' => ['op', $ch],
                '(' => ['open', $ch],
                ')' => ['close', $ch],
                ',' => ['comma', $ch],
                default => ['invalid', $ch],
            };
            if ($tokens[count($tokens) - 1][0] === 'invalid') return null;
            $i++;
        }

        return $tokens;
    }
}
