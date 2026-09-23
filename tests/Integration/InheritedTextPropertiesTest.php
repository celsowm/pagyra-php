<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * The text properties CSS Text defines as inherited reach the text of descendant elements, and not
 * only the text directly inside the element that declared them. Before, `text-transform`,
 * `letter-spacing`, `word-spacing`, `word-break` and `overflow-wrap` stopped at the declaring
 * element, so `<p style="text-transform:uppercase">a <b>b</b></p>` painted "A b".
 */
final class InheritedTextPropertiesTest extends TestCase
{
    /** @return list<TextPaintCommand> */
    private function textCommands(string $html): array
    {
        $commands = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) {
                    $commands[] = $command;
                }
            }
        }

        return $commands;
    }

    private function paintedText(string $html): string
    {
        return implode('', array_map(static fn(TextPaintCommand $c): string => $c->text, $this->textCommands($html)));
    }

    public function testTextTransformReachesNestedInlineElements(): void
    {
        $text = $this->paintedText('<p style="text-transform:uppercase">procedimento <b>comum <i>cível</i></b> fim</p>');

        self::assertSame('PROCEDIMENTO COMUM CÍVEL FIM', trim(preg_replace('/\s+/', ' ', $text) ?? ''));
    }

    public function testTextTransformReachesNestedBlocks(): void
    {
        $text = $this->paintedText('<div style="text-transform:uppercase"><p>autor</p><p><span>réu</span></p></div>');

        self::assertSame('AUTORRÉU', $text);
    }

    public function testNestedElementCanStillOverrideTheInheritedTransform(): void
    {
        $text = $this->paintedText('<p style="text-transform:uppercase">abc <span style="text-transform:none">def</span></p>');

        self::assertStringContainsString('ABC', $text);
        self::assertStringContainsString('def', $text);
    }

    public function testInitialResetsAnInheritedTransform(): void
    {
        $text = $this->paintedText('<p style="text-transform:uppercase">abc <span style="text-transform:initial">def</span></p>');

        self::assertStringContainsString('def', $text);
    }

    public function testLetterSpacingWidensNestedRunsToo(): void
    {
        $plain = $this->textCommands('<p>x <b>wide</b> y</p>');
        $spaced = $this->textCommands('<p style="letter-spacing:10px">x <b>wide</b> y</p>');

        $boldPlain = array_values(array_filter($plain, static fn(TextPaintCommand $c): bool => $c->text === 'wide'))[0];
        $boldSpaced = array_values(array_filter($spaced, static fn(TextPaintCommand $c): bool => $c->text === 'wide'))[0];

        self::assertSame('10px', $boldSpaced->run->style->get('letter-spacing'));
        self::assertGreaterThanOrEqual($boldPlain->run->width + 30.0, $boldSpaced->run->width);
    }

    public function testWordBreakIsInheritedByNestedText(): void
    {
        $commands = $this->textCommands(
            '<div style="width:60px;word-break:break-all"><span>abcdefghijklmnopqrstuvwxyz</span></div>',
        );

        self::assertGreaterThan(1, count($commands));
        foreach ($commands as $command) {
            self::assertLessThanOrEqual(60.5, $command->run->width);
        }
    }
}
