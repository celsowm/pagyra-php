<?php

declare(strict_types=1);

namespace Pagyra\Tests\Parity;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class HelloWorldParityTest extends TestCase
{
    public function testPreparedRenderMatchesBootstrapGolden(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>Hello World</p>',
            'pageWidth' => 800,
            'pageHeight' => 1100,
            'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
        ]);

        $actual = json_encode($prepared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $expected = file_get_contents(__DIR__ . '/../Golden/hello-world.prepared.json');

        self::assertSame($expected, $actual);
    }
}
