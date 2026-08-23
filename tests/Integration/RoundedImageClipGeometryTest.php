<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ImagePaintCommand;
use PHPUnit\Framework\TestCase;

final class RoundedImageClipGeometryTest extends TestCase
{
    public function testCoverClipShrinksBorderRadiusThroughBorderAndPadding(): void
    {
        $jpeg = $this->jpegFixture();
        $src = 'data:image/jpeg;base64,' . base64_encode($jpeg);
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:100px;height:100px;object-fit:cover;border:4px solid black;padding:6px;border-radius:30px"></p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $images = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof ImagePaintCommand,
        ));
        self::assertCount(1, $images);
        $image = $images[0];
        self::assertNotNull($image->clipRect);
        self::assertSame(100.0, $image->clipRect->width);
        self::assertSame(100.0, $image->clipRect->height);
        self::assertNotNull($image->clipRadius);
        self::assertSame(20.0, $image->clipRadius->topLeft->x);
        self::assertSame(20.0, $image->clipRadius->topLeft->y);
        self::assertSame(20.0, $image->clipRadius->bottomRight->x);
        self::assertSame(20.0, $image->clipRadius->bottomRight->y);
        self::assertSame(200.0, $image->width);
        self::assertSame(100.0, $image->height);
    }

    private function jpegFixture(): string
    {
        return "\xff\xd8"
            . "\xff\xe0" . pack('n', 4) . "\x00\x00"
            . "\xff\xc0" . pack('n', 17)
            . "\x08" . pack('n', 100) . pack('n', 200) . "\x03"
            . str_repeat("\x00", 9)
            . "\xff\xd9";
    }
}
