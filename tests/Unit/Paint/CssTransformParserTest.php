<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Paint;

use Pagyra\Paint\CssTransformParser;
use PHPUnit\Framework\TestCase;

final class CssTransformParserTest extends TestCase
{
    public function testComposesTranslateAndScaleLeftToRight(): void
    {
        $matrix = (new CssTransformParser())->parse('translate(10px,20px) scale(2)');

        self::assertNotNull($matrix);
        self::assertEqualsWithDelta(2.0, $matrix->a, 1e-9);
        self::assertEqualsWithDelta(2.0, $matrix->d, 1e-9);
        self::assertEqualsWithDelta(10.0, $matrix->e, 1e-9);
        self::assertEqualsWithDelta(20.0, $matrix->f, 1e-9);
    }

    public function testPercentageTranslationUsesBorderBoxReferences(): void
    {
        $matrix = (new CssTransformParser())->parse('translate(50%,25%)', 200.0, 100.0);

        self::assertNotNull($matrix);
        self::assertEqualsWithDelta(100.0, $matrix->e, 1e-9);
        self::assertEqualsWithDelta(25.0, $matrix->f, 1e-9);
    }

    public function testAnglesSupportCssUnits(): void
    {
        $matrix = (new CssTransformParser())->parse('rotate(.25turn)');

        self::assertNotNull($matrix);
        self::assertEqualsWithDelta(0.0, $matrix->a, 1e-9);
        self::assertEqualsWithDelta(1.0, $matrix->b, 1e-9);
        self::assertEqualsWithDelta(-1.0, $matrix->c, 1e-9);
        self::assertEqualsWithDelta(0.0, $matrix->d, 1e-9);
    }

    public function testSvgRotateAroundCenterComposesTranslations(): void
    {
        $matrix = (new CssTransformParser())->parse('rotate(90 10 20)');

        self::assertNotNull($matrix);
        self::assertEqualsWithDelta(0.0, $matrix->a, 1e-9);
        self::assertEqualsWithDelta(1.0, $matrix->b, 1e-9);
        self::assertEqualsWithDelta(-1.0, $matrix->c, 1e-9);
        self::assertEqualsWithDelta(0.0, $matrix->d, 1e-9);
        self::assertEqualsWithDelta(30.0, $matrix->e, 1e-9);
        self::assertEqualsWithDelta(10.0, $matrix->f, 1e-9);
    }

    public function testTransformOriginSupportsKeywordsPercentagesAndLengths(): void
    {
        $parser = new CssTransformParser();

        self::assertSame([0.0, 100.0], $parser->origin('left bottom', 200.0, 100.0));
        self::assertSame([150.0, 25.0], $parser->origin('75% 25%', 200.0, 100.0));
        self::assertSame([20.0, 50.0], $parser->origin('20px', 200.0, 100.0));
    }
}
