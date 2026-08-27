<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class HelloWorldSnapshotTest extends TestCase
{
    public function testPreparedRenderMatchesBootstrapGolden(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>Hello World</p>',
            'pageWidth' => 800,
            'pageHeight' => 1100,
            'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
        ]);

        $snapshot = $prepared->jsonSerialize();
        unset($snapshot['layoutRoot']);

        $actual = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $expected = file_get_contents(__DIR__ . '/../Golden/hello-world.prepared.json');

        self::assertSame($expected, $actual);
        self::assertSame(760.0, $prepared->layoutRoot->box->content->width);
        self::assertSame(51.2, $prepared->layoutRoot->box->content->height);
    }
}
