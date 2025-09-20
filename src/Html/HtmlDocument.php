<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html;

final class HtmlDocument
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $roots;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $elementIndex = [];

    /**
     * @param array<int, array<string, mixed>> $roots
     */
    public function __construct(array $roots)
    {
        $this->roots = $roots;
        $this->indexElements($this->roots);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getElement(string $nodeId): ?array
    {
        return $this->elementIndex[$nodeId] ?? null;
    }

    /**
     * @param callable(array<string, mixed>):void $callback
     */
    public function eachElement(callable $callback): void
    {
        $this->walkElements($this->roots, $callback);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param callable(array<string, mixed>):void $callback
     */
    private function walkElements(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if ($type === 'element') {
                $callback($node);
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $this->walkElements($children, $callback);
                }
            } elseif (isset($node['children']) && is_array($node['children'])) {
                $this->walkElements($node['children'], $callback);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function indexElements(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'element') {
                $nodeId = (string)($node['nodeId'] ?? '');
                if ($nodeId !== '') {
                    $this->elementIndex[$nodeId] = $node;
                }
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $this->indexElements($children);
                }
            } elseif (isset($node['children']) && is_array($node['children'])) {
                $this->indexElements($node['children']);
            }
        }
    }
}
