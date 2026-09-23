<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\SelectorMatcher;
use Pagyra\Dom\Node;
use PHPUnit\Framework\TestCase;

final class SelectorMatcherTest extends TestCase
{
    private SelectorMatcher $matcher;
    private Node $body;
    /** @var list<Node> */
    private array $items;
    private Node $list;
    private Node $heading;
    private Node $paragraph;
    private Node $second;

    protected function setUp(): void
    {
        $this->matcher = new SelectorMatcher();
        $this->items = [
            Node::element('li', ['class' => 'a'], [Node::text('1')]),
            Node::element('li', ['class' => 'b'], [Node::text('2')]),
            Node::element('li', ['class' => 'c', 'lang' => 'pt-BR'], [Node::text('3')]),
            Node::element('li', [], []),
        ];
        $this->list = Node::element('ul', ['id' => 'lista'], $this->items);
        $this->heading = Node::element('h2', [], [Node::text('T')]);
        $this->paragraph = Node::element('p', ['data-x' => 'um,dois', 'title' => 'Arquivo.PDF'], [Node::text('p1')]);
        $this->second = Node::element('p', [], [Node::text('p2')]);
        $this->body = Node::element('body', ['class' => 'ck-content'], [
            $this->heading, Node::text(' '), $this->paragraph, $this->second, $this->list,
        ]);
    }

    private function item(int $index): Node
    {
        return $this->items[$index];
    }

    /** @return list<Node> */
    private function itemAncestors(): array
    {
        return [$this->body, $this->list];
    }

    public function testStructuralPseudoClasses(): void
    {
        $ancestors = $this->itemAncestors();
        self::assertTrue($this->matcher->matches($this->item(0), 'li:first-child', $ancestors));
        self::assertFalse($this->matcher->matches($this->item(1), 'li:first-child', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(3), 'li:last-child', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(1), 'li:nth-child(2)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(2), 'li:nth-child(odd)', $ancestors));
        self::assertFalse($this->matcher->matches($this->item(1), 'li:nth-child(odd)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(1), 'li:nth-child(2n)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(0), 'li:nth-child(-n+2)', $ancestors));
        self::assertFalse($this->matcher->matches($this->item(2), 'li:nth-child(-n+2)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(2), 'li:nth-last-child(2)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(3), 'li:empty', $ancestors));
        self::assertFalse($this->matcher->matches($this->item(0), 'li:empty', $ancestors));
    }

    public function testTypeFamilyCountsOnlySameTagSiblings(): void
    {
        $ancestors = [$this->body];
        self::assertTrue($this->matcher->matches($this->paragraph, 'p:first-of-type', $ancestors));
        self::assertFalse($this->matcher->matches($this->paragraph, 'p:first-child', $ancestors));
        self::assertTrue($this->matcher->matches($this->second, 'p:last-of-type', $ancestors));
        self::assertTrue($this->matcher->matches($this->list, 'ul:only-of-type', $ancestors));
        self::assertTrue($this->matcher->matches($this->second, 'p:nth-of-type(2)', $ancestors));
    }

    public function testSiblingCombinatorsSkipTextNodes(): void
    {
        $ancestors = [$this->body];
        self::assertTrue($this->matcher->matches($this->paragraph, 'h2 + p', $ancestors));
        self::assertFalse($this->matcher->matches($this->second, 'h2 + p', $ancestors));
        self::assertTrue($this->matcher->matches($this->second, 'h2 ~ p', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(0), 'h2 ~ ul > li', $this->itemAncestors()));
        self::assertFalse($this->matcher->matches($this->item(0), 'p + h2 li', $this->itemAncestors()));
    }

    public function testDescendantMatchingBacktracks(): void
    {
        $inner = Node::element('span', [], []);
        $b = Node::element('div', ['class' => 'x'], [$inner]);
        $a = Node::element('div', ['class' => 'y'], [$b]);

        // `.y > * span`: the greedy walk takes `.x` for `*`, then needs `.y` as its parent.
        self::assertTrue($this->matcher->matches($inner, '.y > * span', [$a, $b]));
        self::assertTrue($this->matcher->matches($inner, 'div.y div span', [$a, $b]));
    }

    public function testNegationAndMatchesAny(): void
    {
        $ancestors = $this->itemAncestors();
        self::assertTrue($this->matcher->matches($this->item(1), 'li:not(.a)', $ancestors));
        self::assertFalse($this->matcher->matches($this->item(0), 'li:not(.a, .c)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(2), ':is(.a, .c)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(2), 'ul :where(li.c)', $ancestors));
        self::assertTrue($this->matcher->matches($this->item(2), 'li:lang(pt)', $ancestors));
    }

    public function testAttributeValuesAreNotMistakenForClassesOrIds(): void
    {
        $ancestors = [$this->body];
        self::assertTrue($this->matcher->matches($this->paragraph, 'p[title$=".PDF"]', $ancestors));
        self::assertTrue($this->matcher->matches($this->paragraph, 'p[title$=".pdf" i]', $ancestors));
        self::assertFalse($this->matcher->matches($this->paragraph, 'p[title$=".pdf"]', $ancestors));
        self::assertTrue($this->matcher->matches($this->paragraph, '[data-x="um,dois"]', $ancestors));
        self::assertTrue($this->matcher->matches($this->paragraph, '[data-x*=","]', $ancestors));
    }

    public function testInteractionStatesAndPseudoElementsNeverMatchTheElement(): void
    {
        $link = Node::element('a', ['href' => 'https://x.test/'], [Node::text('x')]);
        self::assertTrue($this->matcher->matches($link, 'a:link', [$this->body]));
        self::assertFalse($this->matcher->matches($link, 'a:hover', [$this->body]));
        self::assertFalse($this->matcher->matches($link, 'a:visited', [$this->body]));
        self::assertFalse($this->matcher->matches($this->paragraph, 'p::before', [$this->body]));
        self::assertFalse($this->matcher->matches($this->paragraph, 'p:after', [$this->body]));
        self::assertSame('before', $this->matcher->pseudoElement('p.x::before'));
        self::assertSame('after', $this->matcher->pseudoElement('p:after'));
        self::assertNull($this->matcher->pseudoElement('p:first-child'));
    }

    public function testRootAndBodyAncestry(): void
    {
        $html = Node::element('html', [], [$this->body]);
        self::assertTrue($this->matcher->matches($html, ':root', []));
        self::assertTrue($this->matcher->matches($this->paragraph, '.ck-content p', [$html, $this->body]));
        self::assertTrue($this->matcher->matches($this->paragraph, 'html > body > p', [$html, $this->body]));
    }

    public function testMalformedSelectorsMatchNothing(): void
    {
        foreach (['p[', 'p:nth-child(x)', 'p >', '::before p', 'p..x'] as $selector) {
            self::assertFalse($this->matcher->matches($this->paragraph, $selector, [$this->body]), $selector);
        }
    }

    public function testSpecificity(): void
    {
        self::assertSame(1, $this->matcher->specificity('p'));
        self::assertSame(11, $this->matcher->specificity('li:first-child'));
        self::assertSame(12, $this->matcher->specificity('h2 + p.x'));
        self::assertSame(111, $this->matcher->specificity('#lista li.a'));
        self::assertSame(21, $this->matcher->specificity('a[href$=".pdf"]:link'));
        self::assertSame(11, $this->matcher->specificity('li:not(.a)'));
        self::assertSame(1, $this->matcher->specificity(':where(.a, #b) li'));
        self::assertSame(101, $this->matcher->specificity(':is(.a, #b) li'));
        self::assertSame(2, $this->matcher->specificity('p::before'));
    }
}
