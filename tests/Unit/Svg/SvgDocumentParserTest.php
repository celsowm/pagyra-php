<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Svg;

use Pagyra\Dom\Node;
use Pagyra\Svg\SvgDocumentParser;
use PHPUnit\Framework\TestCase;

final class SvgDocumentParserTest extends TestCase
{
    public function testParsesViewBoxAndInheritedShapeStyle(): void
    {
        $svg = Node::element('svg', [
            'viewbox' => '0 0 100 50',
            'fill' => '#123456',
            'stroke' => 'black',
            'stroke-width' => '2',
        ], [
            Node::element('g', ['fill-opacity' => '0.5'], [
                Node::element('path', ['d' => 'M0 0 L10 0 L10 10 Z'], []),
            ]),
        ]);

        $document = (new SvgDocumentParser())->parse($svg);

        self::assertNotNull($document);
        self::assertSame(['minX' => 0.0, 'minY' => 0.0, 'width' => 100.0, 'height' => 50.0], $document->viewBox);
        self::assertSame(100.0, $document->width);
        self::assertSame(50.0, $document->height);
        self::assertCount(1, $document->shapes);
        self::assertSame('path', $document->shapes[0]['type']);
        self::assertSame('#123456', $document->shapes[0]['fill']);
        self::assertSame('black', $document->shapes[0]['stroke']);
        self::assertSame(2.0, $document->shapes[0]['strokeWidth']);
        self::assertSame(0.5, $document->shapes[0]['fillOpacity']);
    }

    public function testInlineStyleOverridesPresentationAttributes(): void
    {
        $svg = Node::element('svg', [], [
            Node::element('rect', [
                'x' => '1', 'y' => '2', 'width' => '10', 'height' => '20',
                'fill' => 'red',
                'style' => 'fill: blue; stroke: #fff; opacity: .25',
            ], []),
        ]);

        $document = (new SvgDocumentParser())->parse($svg);
        $shape = $document?->shapes[0] ?? null;

        self::assertNotNull($shape);
        self::assertSame('rect', $shape['type']);
        self::assertSame(1.0, $shape['x']);
        self::assertSame(2.0, $shape['y']);
        self::assertSame(10.0, $shape['width']);
        self::assertSame(20.0, $shape['height']);
        self::assertSame('blue', $shape['fill']);
        self::assertSame('#fff', $shape['stroke']);
        self::assertSame(0.25, $shape['opacity']);
    }

    public function testParsesCircleEllipseLineAndPointShapes(): void
    {
        $svg = Node::element('svg', [], [
            Node::element('circle', ['cx' => '5', 'cy' => '6', 'r' => '4'], []),
            Node::element('ellipse', ['cx' => '7', 'cy' => '8', 'rx' => '3', 'ry' => '2'], []),
            Node::element('line', ['x1' => '1', 'y1' => '2', 'x2' => '3', 'y2' => '4'], []),
            Node::element('polyline', ['points' => '0,0 10,5 20,0'], []),
            Node::element('polygon', ['points' => '1 1 5 1 3 4'], []),
        ]);

        $document = (new SvgDocumentParser())->parse($svg);

        self::assertNotNull($document);
        self::assertSame(['circle', 'ellipse', 'line', 'polyline', 'polygon'], array_column($document->shapes, 'type'));
        self::assertCount(3, $document->shapes[3]['points']);
        self::assertSame(['x' => 20.0, 'y' => 0.0], $document->shapes[3]['points'][2]);
    }

    public function testRejectsNonSvgRootAndInvalidEmptyShapes(): void
    {
        $parser = new SvgDocumentParser();
        self::assertNull($parser->parse(Node::element('div', [], [])));

        $svg = Node::element('svg', [], [
            Node::element('path', ['d' => ''], []),
            Node::element('rect', ['width' => '0', 'height' => '20'], []),
            Node::element('circle', ['r' => '0'], []),
            Node::element('polygon', ['points' => '1,2'], []),
        ]);
        $document = $parser->parse($svg);
        self::assertNotNull($document);
        self::assertSame([], $document->shapes);
    }
}
