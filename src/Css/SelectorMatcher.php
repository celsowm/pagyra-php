<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Css;

final class SelectorMatcher
{
    /**
     * @param array<string, mixed> $node
     */
    public function matches(array $node, string $selector): bool
    {
        $selector = trim($selector);
        if ($selector === '' || ($node['type'] ?? '') !== 'element') {
            return false;
        }

        foreach (explode(',', $selector) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($this->matchesSingle($node, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public function calculateSpecificity(string $selector): array
    {
        $currentMax = [0, 0, 0];
        foreach (explode(',', $selector) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $spec = [0, 0, 0];
            $segments = preg_split('/\s+/', $part) ?: [];
            foreach ($segments as $segment) {
                $spec = $this->addSpecificity($spec, $this->specificityForSegment(trim($segment)));
            }
            if ($this->compareSpecificity($spec, $currentMax) > 0) {
                $currentMax = $spec;
            }
        }

        return $currentMax;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function matchesSingle(array $node, string $selector): bool
    {
        $segments = preg_split('/\s+/', trim($selector)) ?: [];
        if ($segments === []) {
            return false;
        }

        $target = array_pop($segments);
        if ($target === null || !$this->matchSimpleSelector($node, $target)) {
            return false;
        }

        if ($segments === []) {
            return true;
        }

        $ancestors = $node['ancestors'] ?? [];
        $ancestorIndex = count($ancestors) - 1;

        foreach (array_reverse($segments) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $matched = false;
            while ($ancestorIndex >= 0) {
                if ($this->matchSimpleSelector($ancestors[$ancestorIndex], $segment)) {
                    $matched = true;
                    $ancestorIndex--;
                    break;
                }
                $ancestorIndex--;
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function matchSimpleSelector(array $node, string $selector): bool
    {
        if (($node['type'] ?? '') !== 'element') {
            return false;
        }

        $selector = trim($selector);
        if ($selector === '' || $selector === '*') {
            return true;
        }

        $remaining = $selector;
        $tag = null;
        $first = $remaining[0] ?? '';
        if ($first !== '.' && $first !== '#' && $first !== '[' && $first !== ':') {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*/', $remaining, $m) === 1) {
                $tag = strtolower($m[0]);
                $remaining = substr($remaining, strlen($m[0]));
            }
        }

        if ($tag !== null && $tag !== '*' && strtolower($node['tag'] ?? '') !== $tag) {
            return false;
        }

        $attributes = $node['attributes'] ?? [];
        $classList = [];
        if (isset($attributes['class'])) {
            $classes = preg_split('/\s+/', (string)$attributes['class']);
            if (is_array($classes)) {
                foreach ($classes as $class) {
                    $class = strtolower(trim((string)$class));
                    if ($class !== '') {
                        $classList[] = $class;
                    }
                }
            }
        }

        while ($remaining !== '') {
            $char = $remaining[0];
            if ($char === '.') {
                $remaining = substr($remaining, 1);
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) !== 1) {
                    return false;
                }
                $className = strtolower($m[0]);
                if (!in_array($className, $classList, true)) {
                    return false;
                }
                $remaining = substr($remaining, strlen($m[0]));
                continue;
            }
            if ($char === '#') {
                $remaining = substr($remaining, 1);
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) !== 1) {
                    return false;
                }
                $id = strtolower((string)($attributes['id'] ?? ''));
                if ($id === '' || strtolower($m[0]) !== $id) {
                    return false;
                }
                $remaining = substr($remaining, strlen($m[0]));
                continue;
            }
            if ($char === '[') {
                $close = strpos($remaining, ']');
                if ($close === false) {
                    return false;
                }
                $content = substr($remaining, 1, $close - 1);
                $remaining = substr($remaining, $close + 1);
                $parts = array_map('trim', explode('=', $content, 2));
                $attrName = strtolower($parts[0] ?? '');
                if ($attrName === '') {
                    return false;
                }
                $attrValue = $attributes[$attrName] ?? null;
                if (count($parts) === 2) {
                    $expected = trim($parts[1], "\"'{}");
                    if ($attrValue === null || strtolower((string)$attrValue) !== strtolower($expected)) {
                        return false;
                    }
                } elseif ($attrValue === null) {
                    return false;
                }
                continue;
            }
            if ($char === ':') {
                $remaining = substr($remaining, 1);
                if (($remaining[0] ?? '') === ':') {
                    $remaining = substr($remaining, 1);
                }
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) === 1) {
                    $remaining = substr($remaining, strlen($m[0]));
                    continue;
                }
                return false;
            }
            return false;
        }

        return true;
    }

    /**
     * @param array{0:int,1:int,2:int} $base
     * @param array{0:int,1:int,2:int} $extra
     * @return array{0:int,1:int,2:int}
     */
    private function addSpecificity(array $base, array $extra): array
    {
        return [
            $base[0] + $extra[0],
            $base[1] + $extra[1],
            $base[2] + $extra[2],
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private function compareSpecificity(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        return 0;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function specificityForSegment(string $segment): array
    {
        $spec = [0, 0, 0];
        $segment = trim($segment);
        if ($segment === '') {
            return $spec;
        }

        $remaining = $segment;
        while ($remaining !== '') {
            $char = $remaining[0];
            if ($char === '#') {
                $remaining = substr($remaining, 1);
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) === 1) {
                    $spec[0]++;
                    $remaining = substr($remaining, strlen($m[0]));
                } else {
                    break;
                }
                continue;
            }
            if ($char === '.') {
                $remaining = substr($remaining, 1);
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) === 1) {
                    $spec[1]++;
                    $remaining = substr($remaining, strlen($m[0]));
                } else {
                    break;
                }
                continue;
            }
            if ($char === '[') {
                $close = strpos($remaining, ']');
                if ($close === false) {
                    break;
                }
                $spec[1]++;
                $remaining = substr($remaining, $close + 1);
                continue;
            }
            if ($char === ':') {
                $remaining = substr($remaining, 1);
                if (($remaining[0] ?? '') === ':') {
                    $remaining = substr($remaining, 1);
                    if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) === 1) {
                        $spec[2]++;
                        $remaining = substr($remaining, strlen($m[0]));
                    }
                    continue;
                }
                if (preg_match('/^[a-zA-Z0-9_-]+/', $remaining, $m) === 1) {
                    $spec[1]++;
                    $remaining = substr($remaining, strlen($m[0]));
                } else {
                    break;
                }
                continue;
            }
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*/', $remaining, $m) === 1) {
                if ($m[0] !== '*') {
                    $spec[2]++;
                }
                $remaining = substr($remaining, strlen($m[0]));
                continue;
            }
            break;
        }

        return $spec;
    }
}
