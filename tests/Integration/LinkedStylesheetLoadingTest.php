<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Style\StyledNode;
use PHPUnit\Framework\TestCase;

final class LinkedStylesheetLoadingTest extends TestCase
{
    public function testRelativeLinkedStylesheetParticipatesInCascade(): void
    {
        $dir = $this->temporaryDirectory();
        file_put_contents($dir . '/site.css', 'p { width: 120px; }');

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<link rel="stylesheet" href="site.css"><p>hello</p>',
                'resourceBaseDir' => $dir,
                'viewportWidth' => 400,
                'viewportHeight' => 300,
            ]);

            self::assertStringContainsString('p { width: 120px; }', $prepared->cssText);
            self::assertSame('120px', $this->firstElement($prepared->styledRoot, 'p')->style->get('width'));
        } finally {
            @unlink($dir . '/site.css');
            @rmdir($dir);
        }
    }

    public function testLinkedStylesheetIsAppendedAfterEmbeddedStyleLikeReference(): void
    {
        $dir = $this->temporaryDirectory();
        file_put_contents($dir . '/site.css', 'p { width: 140px; }');

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<style>p { width: 80px; }</style><link rel="stylesheet" href="site.css"><p>hello</p>',
                'resourceBaseDir' => $dir,
                'viewportWidth' => 400,
                'viewportHeight' => 300,
            ]);

            self::assertSame('140px', $this->firstElement($prepared->styledRoot, 'p')->style->get('width'));
        } finally {
            @unlink($dir . '/site.css');
            @rmdir($dir);
        }
    }

    public function testRemoteLinkedStylesheetIsNotFetched(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<link rel="stylesheet" href="https://example.com/site.css"><p>hello</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        self::assertSame('', $prepared->cssText);
        self::assertSame(['https://example.com/site.css'], $prepared->stylesheetHrefs);
    }

    private function temporaryDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/pagyra-css-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Unable to create temporary stylesheet directory');
        }
        return $dir;
    }

    /** First descendant element with the given tag, so the assertions do not depend on where
     * whitespace text nodes happen to fall in the styled tree. */
    private function firstElement(StyledNode $node, string $tag): StyledNode
    {
        if ($node->node->isElement($tag)) return $node;
        foreach ($node->children as $child) {
            $found = $this->firstElementOrNull($child, $tag);
            if ($found !== null) return $found;
        }
        self::fail('elemento <' . $tag . '> nao encontrado na arvore de estilos');
    }

    private function firstElementOrNull(StyledNode $node, string $tag): ?StyledNode
    {
        if ($node->node->isElement($tag)) return $node;
        foreach ($node->children as $child) {
            $found = $this->firstElementOrNull($child, $tag);
            if ($found !== null) return $found;
        }
        return null;
    }

}
