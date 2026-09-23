<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ClipPaintCommand;
use Pagyra\Paint\GradientPaintCommand;
use Pagyra\Paint\ImagePaintCommand;
use PHPUnit\Framework\TestCase;

/** `background-image` with images and gradients, positioned, sized, tiled and clipped. */
final class BackgroundImageAndGradientTest extends TestCase
{
    /** 2x1 PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAEUlEQVR4nGP4z8DAwMDAAAAOEgEAv6zTYAAAAABJRU5ErkJggg==';

    /** @return list<object> */
    private function commands(string $style): array
    {
        return Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0,
            'html' => '<div style="width:100px;height:40px;' . $style . '"></div>',
        ])->displayList->pages[0]->commands;
    }

    /** @return list<ImagePaintCommand> */
    private function tiles(string $style): array
    {
        return array_values(array_filter($this->commands($style), static fn(object $c): bool => $c instanceof ImagePaintCommand));
    }

    public function testImageIsTiledAndClippedToTheBox(): void
    {
        $commands = $this->commands('background-image:url(' . self::PNG . ');background-size:20px 10px');
        $tiles = array_values(array_filter($commands, static fn(object $c): bool => $c instanceof ImagePaintCommand));
        $clips = array_values(array_filter($commands, static fn(object $c): bool => $c instanceof ClipPaintCommand));

        self::assertCount(20, $tiles);
        self::assertCount(2, $clips);
        self::assertEqualsWithDelta([0.0, 0.0, 100.0, 40.0], [$clips[0]->x, $clips[0]->y, $clips[0]->width, $clips[0]->height], 1e-6);
    }

    public function testNoRepeatCenterAndCover(): void
    {
        $centered = $this->tiles('background:url(' . self::PNG . ') no-repeat center');
        self::assertCount(1, $centered);
        self::assertEqualsWithDelta([49.0, 19.5, 2.0, 1.0], [$centered[0]->x, $centered[0]->y, $centered[0]->width, $centered[0]->height], 1e-6);

        $cover = $this->tiles('background:url(' . self::PNG . ') no-repeat 0 0 / cover');
        self::assertEqualsWithDelta([100.0, 50.0], [$cover[0]->width, $cover[0]->height], 1e-6);

        $repeatX = $this->tiles('background:url(' . self::PNG . ') repeat-x 0 10px / 50px auto');
        self::assertCount(2, $repeatX);
        self::assertSame([10.0, 10.0], array_map(static fn(ImagePaintCommand $t): float => $t->y, $repeatX));
    }

    public function testLinearGradientGeometryAndStops(): void
    {
        $gradients = array_values(array_filter($this->commands('background:linear-gradient(to right, red, #0000ff 80%, white)'), static fn(object $c): bool => $c instanceof GradientPaintCommand));

        self::assertCount(1, $gradients);
        self::assertSame('linear', $gradients[0]->kind);
        self::assertEqualsWithDelta([0.0, 20.0, 100.0, 20.0], $gradients[0]->geometry, 1e-6);
        self::assertEqualsWithDelta([0.0, 0.8, 1.0], array_map(static fn(array $s): float => $s[0], $gradients[0]->stops), 1e-6);

        $angled = array_values(array_filter($this->commands('background-image:linear-gradient(180deg, red, blue)'), static fn(object $c): bool => $c instanceof GradientPaintCommand))[0];
        self::assertEqualsWithDelta([50.0, 0.0, 50.0, 40.0], $angled->geometry, 1e-6);
    }

    public function testRadialGradientAndPdfShading(): void
    {
        $radial = array_values(array_filter($this->commands('background:radial-gradient(circle at 0 0, red, blue)'), static fn(object $c): bool => $c instanceof GradientPaintCommand))[0];
        self::assertSame('radial', $radial->kind);
        self::assertEqualsWithDelta([0.0, 0.0, hypot(100.0, 40.0), hypot(100.0, 40.0)], $radial->geometry, 1e-6);

        $pdf = Pagyra::renderHtmlToPdf(['html' => '<div style="height:40px;background:linear-gradient(red, blue)"></div>']);
        self::assertStringContainsString('/ShadingType 2', $pdf);
        self::assertStringContainsString('/Shading << /Sh1', $pdf);
        self::assertStringContainsString('/Sh1 sh', $pdf);
    }
}
