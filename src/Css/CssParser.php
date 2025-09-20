<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Css;

final class CssParser
{
    public function parse(string $css): CssOM
    {
        $om = new CssOM();
        if ($css === '') {
            return $om;
        }

        $normalized = $this->removeComments($css);
        $blocks = preg_split('/}/', $normalized) ?: [];

        foreach ($blocks as $rawBlock) {
            if (strpos($rawBlock, '{') === false) {
                continue;
            }
            [$rawSelectors, $rawDeclarations] = array_map('trim', explode('{', $rawBlock, 2));
            if ($rawSelectors === '' || $rawDeclarations === '') {
                continue;
            }
            $declarations = $this->parseDeclarations($rawDeclarations);
            if ($declarations === []) {
                continue;
            }
            foreach (explode(',', $rawSelectors) as $selector) {
                $om->addRule(trim($selector), $declarations);
            }
        }

        return $om;
    }

    /**
     * @return array<string, string>
     */
    private function parseDeclarations(string $block): array
    {
        $result = [];
        foreach (explode(';', $block) as $declaration) {
            if (strpos($declaration, ':') === false) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            if ($property === '' || $value === '') {
                continue;
            }
            $result[strtolower($property)] = $value;
        }
        return $result;
    }

    private function removeComments(string $css): string
    {
        return trim(preg_replace('/\/\*.*?\*\//s', '', $css) ?? '');
    }
}
