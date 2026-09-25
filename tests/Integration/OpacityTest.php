<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Paint\OpacityGroupPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** `opacity` fades everything an element paints, compounded with its ancestors'. */
final class OpacityTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @return list<object> */
    private function commands(string $html): array
    {
        return Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages[0]->commands;
    }

    public function testTextBackgroundAndNestedElementsAreFaded(): void
    {
        $commands = $this->commands('<div style="opacity:.5;background:#ff0000">a <span style="opacity:50%">b</span></div><p>c</p>');
        $alpha = [];
        foreach ($commands as $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) !== '') $alpha[trim($command->text)] = $command->color->a;
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) $alpha['fundo'] = $command->backgroundColor->a;
        }

        // The div's 0.5 is now isolated at group level. Its opaque text/background therefore
        // return to alpha 1 inside the form; the inline span has no independent paint box yet,
        // so its own 0.5 remains on the run. Effective visual alphas are still a=.5, b=.25.
        self::assertEqualsWithDelta(['a' => 1.0, 'b' => 0.5, 'c' => 1.0, 'fundo' => 1.0], $alpha, 1e-6);

        $groups = array_values(array_filter(
            $commands,
            static fn(object $command): bool => $command instanceof OpacityGroupPaintCommand && $command->opens(),
        ));
        self::assertCount(1, $groups);
        self::assertEqualsWithDelta(0.5, $groups[0]->normalizedOpacity(), 1e-9);
    }

    public function testImagesAreFadedThroughAGraphicsState(): void
    {
        $image = null;
        foreach ($this->commands('<p style="opacity:0.3"><img src="' . self::PNG . '" width="10" height="10"></p>') as $command) {
            if ($command instanceof ImagePaintCommand) $image = $command;
        }
        self::assertNotNull($image);
        self::assertEqualsWithDelta(1.0, $image->opacity, 1e-6);

        $groups = array_values(array_filter(
            $this->commands('<p style="opacity:0.3"><img src="' . self::PNG . '" width="10" height="10"></p>'),
            static fn(object $command): bool => $command instanceof OpacityGroupPaintCommand && $command->opens(),
        ));
        self::assertCount(1, $groups);
        self::assertEqualsWithDelta(0.3, $groups[0]->normalizedOpacity(), 1e-9);

        $pdf = Pagyra::renderHtmlToPdf(['html' => '<p style="opacity:0.3"><img src="' . self::PNG . '" width="10" height="10"></p>']);
        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString('/Group << /S /Transparency', $pdf);
        self::assertStringContainsString('/ca 0.3 /CA 0.3', $pdf);
    }

    public function testOpaqueDocumentsAreUnchanged(): void
    {
        foreach ($this->commands('<p style="opacity:1">x</p>') as $command) {
            if ($command instanceof TextPaintCommand) self::assertEqualsWithDelta(1.0, $command->color->a, 1e-9);
        }
    }
}
