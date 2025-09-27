<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Resolve;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class SystemFontLocator
{
    /** @var array<int, string>|null */
    private ?array $directories;
    private FontInfoExtractor $extractor;
    /** @var array<int, string> */
    private array $extensions = ['ttf', 'otf'];

    /**
     * @param array<int, string>|null $directories
     */
    public function __construct(?FontInfoExtractor $extractor = null, ?array $directories = null)
    {
        $this->extractor = $extractor ?? new FontInfoExtractor();
        $this->directories = $directories;
    }

    /**
     * @return array<int, array{family:string, subfamily:?string, fullName:?string, postscriptName:?string, path:string, weight:int, italic:bool}>
     */
    public function locate(): array
    {
        $fonts = [];
        $seen = [];
        foreach ($this->directories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $dir,
                        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $path = $fileInfo->getPathname();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, $this->extensions, true)) {
                    continue;
                }
                $info = $this->extractor->extract($path);
                if ($info !== null) {
                    $fonts[] = $info;
                }
            }
        }
        return $fonts;
    }

    public function computeSignature(): string
    {
        $parts = [PHP_OS_FAMILY];
        foreach ($this->directories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $real = realpath($dir) ?: $dir;
            $mtime = @filemtime($dir) ?: 0;
            $parts[] = $real . ':' . $mtime;
        }
        return sha1(implode('|', $parts));
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        if ($this->directories !== null) {
            return $this->directories;
        }
        $dirs = [];
        switch (PHP_OS_FAMILY) {
            case 'Windows':
                $windowsDir = getenv('WINDIR') ?: 'C:\\Windows';
                $dirs[] = $windowsDir . DIRECTORY_SEPARATOR . 'Fonts';
                break;
            case 'Darwin':
                $dirs[] = '/System/Library/Fonts';
                $dirs[] = '/Library/Fonts';
                $home = getenv('HOME');
                if (is_string($home) && $home !== '') {
                    $dirs[] = $home . '/Library/Fonts';
                }
                break;
            default:
                $dirs[] = '/usr/share/fonts';
                $dirs[] = '/usr/local/share/fonts';
                $home = getenv('HOME');
                if (is_string($home) && $home !== '') {
                    $dirs[] = $home . '/.fonts';
                    $dirs[] = $home . '/.local/share/fonts';
                }
                break;
        }
        $this->directories = array_values(array_unique(array_filter($dirs, static fn(string $dir): bool => $dir !== '')));
        return $this->directories;
    }
}
