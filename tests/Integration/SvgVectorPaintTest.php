<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ClipPaintCommand;
use Pagyra\Paint\SvgPathPaintCommand;
use PHPUnit\Framework\TestCase;

final class SvgVectorPaintTest extends TestCase
{
    /** @return array{0:ClipPaintCommand,1:SvgPathPaintCommand} */
    private function svgCommands(string $svg): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="margin:0">' . $svg . '</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $clip = null;
        $path = null;
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($clip === null && $command instanceof ClipPaintCommand && $command->opens()) {
                $clip = $command;
            }
            if ($path === null && $command instanceof SvgPathPaintCommand) {
                $path = $command;
            }
        }

        self::assertInstanceOf(ClipPaintCommand::class, $clip);
        self::assertInstanceOf(SvgPathPaintCommand::class, $path);

        return [$clip, $path];
    }

    public function testInlineSvgEmitsVectorPathWithDefaultMeetAlignment(): void
    {
        [$clip, $path] = $this->svgCommands(
            '<svg width="100" height="50" viewBox="0 0 10 10">'
            . '<rect x="0" y="0" width="10" height="10" fill="#ff0000"/>'
            . '</svg>',
        );

        self::assertEqualsWithDelta(100.0, $clip->width, 1e-6);
        self::assertEqualsWithDelta(50.0, $clip->height, 1e-6);

        $move = $path->segments[0];
        self::assertSame('M', $move['type']);
        self::assertEqualsWithDelta($clip->x + 25.0, (float) $move['x'], 1e-6);
        self::assertEqualsWithDelta($clip->y, (float) $move['y'], 1e-6);

        self::assertNotNull($path->fill);
        self::assertSame(255, $path->fill->r);
        self::assertSame(0, $path->fill->g);
        self::assertSame(0, $path->fill->b);
    }

    public function testPreserveAspectRatioNoneMapsIndependentAxes(): void
    {
        [$clip, $path] = $this->svgCommands(
            '<svg width="100" height="50" viewBox="0 0 10 10" preserveAspectRatio="none">'
            . '<rect width="10" height="10" fill="black"/>'
            . '</svg>',
        );

        $segments = $path->segments;
        self::assertEqualsWithDelta($clip->x, (float) $segments[0]['x'], 1e-6);
        self::assertEqualsWithDelta($clip->y, (float) $segments[0]['y'], 1e-6);
        self::assertEqualsWithDelta($clip->x + 100.0, (float) $segments[1]['x'], 1e-6);
        self::assertEqualsWithDelta($clip->y + 50.0, (float) $segments[2]['y'], 1e-6);
    }

    public function testGroupTransformIsComposedBeforeViewportMapping(): void
    {
        [$clip, $path] = $this->svgCommands(
            '<svg width="100" height="100" viewBox="0 0 10 10">'
            . '<g transform="translate(2 3)"><path d="M0 0 L1 0" fill="none" stroke="blue"/></g>'
            . '</svg>',
        );

        self::assertEqualsWithDelta($clip->x + 20.0, (float) $path->segments[0]['x'], 1e-6);
        self::assertEqualsWithDelta($clip->y + 30.0, (float) $path->segments[0]['y'], 1e-6);
        self::assertNotNull($path->stroke);
    }

    public function testCircleAndRoundedRectBecomeCubicVectorSegments(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="margin:0"><svg width="100" height="100" viewBox="0 0 100 100">'
                . '<circle cx="25" cy="25" r="20" fill="red"/>'
                . '<rect x="50" y="10" width="40" height="30" rx="5" fill="green"/>'
                . '</svg></p>',
        ]);

        $paths = array_values(array_filter(
            $prepared->displayList->pages[0]->commands,
            static fn(object $command): bool => $command instanceof SvgPathPaintCommand,
        ));

        self::assertCount(2, $paths);
        foreach ($paths as $path) {
            self::assertTrue((bool) array_filter(
                $path->segments,
                static fn(array $segment): bool => ($segment['type'] ?? '') === 'C',
            ));
        }
    }

    public function testSvgFillAndStrokeAreSerializedAsPdfVectorOperators(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<svg width="40" height="40" viewBox="0 0 10 10">'
                . '<path d="M1 1 L9 1 L9 9 Z" fill="#ff0000" stroke="#0000ff" stroke-width="1"/>'
                . '</svg>',
        ]);

        self::assertStringContainsString("1 0 0 rg\n", $pdf);
        self::assertStringContainsString("0 0 1 RG\n", $pdf);
        self::assertStringContainsString("m\n", $pdf);
        self::assertStringContainsString("l\n", $pdf);
        self::assertStringContainsString("h\n", $pdf);
        self::assertStringContainsString("f\n", $pdf);
        self::assertStringContainsString("S\n", $pdf);
    }
}
