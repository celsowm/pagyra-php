<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Css;

/**
 * Lightweight representation of parsed CSS rules.
 */
final class CssOM
{
    /**
     * @var array<int, array{selector: string, declarations: array<string, string>, order: int}>
     */
    private array $rules = [];

    private int $orderSeq = 0;

    public function addRule(string $selector, array $declarations): void
    {
        $selector = trim($selector);
        if ($selector === '' || $declarations === []) {
            return;
        }

        $this->rules[] = [
            'selector' => $selector,
            'declarations' => $declarations,
            'order' => $this->orderSeq++,
        ];
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>, order: int}>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function clear(): void
    {
        $this->rules = [];
        $this->orderSeq = 0;
    }
}
