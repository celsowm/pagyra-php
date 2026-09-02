<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Inheritance may only fill properties the UA stylesheet did not set on an element.
 * Previously the inherited value overwrote the UA one, so `<a>` inside a coloured
 * paragraph lost its `#0000EE`, and `<strong>` under a light-weight ancestor lost bold.
 */
final class UaStyleBeatsInheritanceTest extends TestCase
{
    private function colorOf(string $html, string $tag): ?string
    {
        $styled = Pagyra::prepareHtmlRender(['html' => $html])->styledRoot;

        $found = null;
        $walk = function ($node) use (&$walk, $tag, &$found): void {
            if ($found !== null) return;
            if (($node->node->tagName ?? null) === $tag) {
                $found = $node->style->get('color');
                return;
            }
            foreach ($node->children as $child) $walk($child);
        };
        $walk($styled);

        return $found;
    }

    public function testAnchorKeepsItsUaColorInsideAColouredParagraph(): void
    {
        $color = $this->colorOf(
            '<style>p { color: #052229; }</style><p>texto <a href="https://example.test/">link</a></p>',
            'a',
        );

        self::assertSame('#0000EE', $color);
    }

    public function testAuthorRuleStillOverridesTheUaAnchorColor(): void
    {
        $color = $this->colorOf(
            '<style>a { color: #333333; }</style><p><a href="https://example.test/">link</a></p>',
            'a',
        );

        self::assertSame('#333333', $color);
    }

    public function testStrongKeepsBoldUnderALightWeightAncestor(): void
    {
        $styled = Pagyra::prepareHtmlRender([
            'html' => '<div style="font-weight:300">a <strong>b</strong></div>',
        ])->styledRoot;

        $weight = null;
        $walk = function ($node) use (&$walk, &$weight): void {
            if (($node->node->tagName ?? null) === 'strong') {
                $weight = $node->style->get('font-weight');
                return;
            }
            foreach ($node->children as $child) $walk($child);
        };
        $walk($styled);

        self::assertSame('bold', $weight);
    }
}
