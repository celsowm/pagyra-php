<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Resolve;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\PdfFontManager;
use SplObjectStorage;

final class FontResolver
{
    private SystemFontRepository $repository;
    private FontStackParser $parser;
    /** @var array<string, array{family:string, variants:array<string, array<string, mixed>>}>|null */
    private ?array $families = null;
    /** @var SplObjectStorage<PdfBuilder, array{families:array<string, array<string, string>>}> */
    private SplObjectStorage $pdfState;

    /** @var array<string, array<int, string>> */
    private array $genericFallbacks = [
        'sans-serif' => ['segoe ui', 'arial', 'helvetica', 'roboto', 'ubuntu', 'open sans', 'noto sans', 'verdana', 'tahoma'],
        'serif' => ['times new roman', 'georgia', 'cambria', 'garamond', 'noto serif', 'pt serif'],
        'monospace' => ['consolas', 'courier new', 'dejavu sans mono', 'liberation mono', 'menlo', 'monaco', 'lucida console'],
        'system-ui' => ['segoe ui', 'san francisco', 'sf pro text', 'roboto', 'ubuntu'],
    ];

    public function __construct(?SystemFontRepository $repository = null, ?FontStackParser $parser = null)
    {
        $this->repository = $repository ?? new SystemFontRepository(new SystemFontLocator());
        $this->parser = $parser ?? new FontStackParser();
        $this->pdfState = new SplObjectStorage();
    }

    public function reset(): void
    {
        $this->families = null;
        $this->pdfState = new SplObjectStorage();
    }

    public function resolve(PdfBuilder $pdf, ?string $fontFamily, string $styleMarkers): ?string
    {
        $families = $this->loadFamilies();
        $candidates = $this->parser->parse($fontFamily);
        $aliases = $this->getPdfFamilies($pdf);

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeName($candidate);
            if (isset($this->genericFallbacks[$normalized])) {
                $match = $this->resolveGeneric($pdf, $styleMarkers, $this->genericFallbacks[$normalized], $families, $aliases);
                if ($match !== null) {
                    return $match;
                }
                continue;
            }
            if (!isset($families[$normalized])) {
                continue;
            }
            $alias = $this->ensureFamilyAlias($pdf, $normalized, $families[$normalized], $styleMarkers, $aliases);
            if ($alias !== null) {
                return $alias;
            }
        }

        if ($candidates !== []) {
            foreach ($candidates as $candidate) {
                $normalized = $this->normalizeName($candidate);
                if (!isset($this->genericFallbacks[$normalized])) {
                    continue;
                }
                $alias = $this->resolveGeneric($pdf, $styleMarkers, $this->genericFallbacks[$normalized], $families, $aliases);
                if ($alias !== null) {
                    return $alias;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $fallbackList
     * @param array<string, array{family:string, variants:array<string, array<string, mixed>>}> $families
     * @param array<string, array<string, string>> $aliases
     */
    private function resolveGeneric(
        PdfBuilder $pdf,
        string $styleMarkers,
        array $fallbackList,
        array $families,
        array &$aliases
    ): ?string {
        foreach ($fallbackList as $familyName) {
            $key = $this->normalizeName($familyName);
            if (!isset($families[$key])) {
                continue;
            }
            $alias = $this->ensureFamilyAlias($pdf, $key, $families[$key], $styleMarkers, $aliases);
            if ($alias !== null) {
                return $alias;
            }
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $family
     * @param array<string, array<string, string>> $aliases
     */
    private function ensureFamilyAlias(
        PdfBuilder $pdf,
        string $familyKey,
        array $family,
        string $styleMarkers,
        array &$aliases
    ): ?string {
        if (isset($aliases[$familyKey])) {
            return $this->pickAliasByStyle($aliases[$familyKey], $styleMarkers);
        }
        $registered = $this->registerFamily($pdf, $familyKey, $family);
        if ($registered === null) {
            return null;
        }
        $aliases[$familyKey] = $registered;
        $this->setPdfFamilies($pdf, $aliases);
        return $this->pickAliasByStyle($registered, $styleMarkers);
    }

    /**
     * @param array<string, array<string, mixed>> $family
     * @return array<string, string>|null
     */
    private function registerFamily(PdfBuilder $pdf, string $familyKey, array $family): ?array
    {
        if (!isset($family['variants']) || !is_array($family['variants'])) {
            return null;
        }
        $variants = $family['variants'];
        if ($variants === []) {
            return null;
        }
        if (!isset($variants['R'])) {
            $first = reset($variants);
            if (!is_array($first) || !isset($first['path'])) {
                return null;
            }
            $variants['R'] = $first;
        }
        if (!isset($variants['R']['path'])) {
            return null;
        }

        $fontManager = $pdf->getFontManager();
        $baseAlias = $this->makeBaseAlias($family['family'] ?? $familyKey);
        $baseAlias = $this->ensureUniqueAlias($fontManager, $baseAlias);

        if (!$this->registerFontFile($pdf, $baseAlias, $variants['R']['path'])) {
            return null;
        }

        $aliasMap = ['R' => $baseAlias];
        $bindings = [];
        foreach (['B', 'I', 'BI'] as $variantKey) {
            if (!isset($variants[$variantKey]['path'])) {
                continue;
            }
            $variantAlias = $this->ensureUniqueAlias($fontManager, $baseAlias . '_' . $variantKey);
            if (!$this->registerFontFile($pdf, $variantAlias, $variants[$variantKey]['path'])) {
                continue;
            }
            $aliasMap[$variantKey] = $variantAlias;
            $bindings[$variantKey] = $variantAlias;
        }

        if ($bindings !== []) {
            $pdf->bindFontVariants($baseAlias, $bindings);
        }

        return $aliasMap;
    }

    private function registerFontFile(PdfBuilder $pdf, string $alias, string $path): bool
    {
        $fontManager = $pdf->getFontManager();
        if ($fontManager->fontExists($alias)) {
            return true;
        }
        try {
            $pdf->addTTFFont($alias, $path);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, string> $aliases
     */
    private function pickAliasByStyle(array $aliases, string $styleMarkers): ?string
    {
        $style = strtoupper($styleMarkers);
        $bold = str_contains($style, 'B');
        $italic = str_contains($style, 'I');
        if ($bold && $italic && isset($aliases['BI'])) {
            return $aliases['BI'];
        }
        if ($bold && isset($aliases['B'])) {
            return $aliases['B'];
        }
        if ($italic && isset($aliases['I'])) {
            return $aliases['I'];
        }
        return $aliases['R'] ?? null;
    }

    private function makeBaseAlias(string $familyName): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', $familyName);
        $normalized = trim((string)$normalized, '_');
        if ($normalized === '') {
            $normalized = 'Font';
        }
        return 'OSF_' . $normalized;
    }

    private function ensureUniqueAlias(PdfFontManager $fontManager, string $alias): string
    {
        $candidate = $alias;
        $suffix = 1;
        while ($fontManager->fontExists($candidate)) {
            $candidate = $alias . '_' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function loadFamilies(): array
    {
        if ($this->families === null) {
            $this->families = $this->repository->getFamilies();
        }
        return $this->families;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getPdfFamilies(PdfBuilder $pdf): array
    {
        if (!$this->pdfState->offsetExists($pdf)) {
            $this->pdfState[$pdf] = ['families' => []];
        }
        $state = $this->pdfState[$pdf];
        return $state['families'] ?? [];
    }

    /**
     * @param array<string, array<string, string>> $families
     */
    private function setPdfFamilies(PdfBuilder $pdf, array $families): void
    {
        $state = $this->pdfState->offsetExists($pdf) ? $this->pdfState[$pdf] : ['families' => []];
        $state['families'] = $families;
        $this->pdfState[$pdf] = $state;
    }

    private function normalizeName(string $name): string
    {
        $lower = strtolower(trim($name));
        $collapsed = preg_replace('/\s+/', ' ', $lower);
        return is_string($collapsed) ? $collapsed : $lower;
    }
}
