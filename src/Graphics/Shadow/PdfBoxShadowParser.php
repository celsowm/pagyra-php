<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\Shadow;

/**
 * Parses CSS box-shadow values and converts them to PDF-compatible shadow specifications.
 */
final class PdfBoxShadowParser
{
    /**
     * Parse a CSS box-shadow value into an array of shadow specifications.
     * 
     * @param string $boxShadowValue CSS box-shadow value (e.g., "0 4px 10px rgba(0, 0, 0, 0.2)")
     * @return array|null Array of shadow specifications or null if invalid
     */
    public function parse(string $boxShadowValue): ?array
    {
        $value = trim($boxShadowValue);
        if ($value === '' || strtolower($value) === 'none') {
            return null;
        }

        // Split multiple shadows by comma (not implemented yet, but prepared)
        $shadows = $this->splitShadows($value);
        $parsed = [];

        foreach ($shadows as $shadow) {
            $shadowSpec = $this->parseSingleShadow(trim($shadow));
            if ($shadowSpec !== null) {
                $parsed[] = $shadowSpec;
            }
        }

        return $parsed === [] ? null : $parsed;
    }

    /**
     * Split multiple box-shadow values by comma.
     */
    private function splitShadows(string $value): array
    {
        // For now, just return single shadow - multiple shadows can be added later
        return [$value];
    }

    /**
     * Parse a single box-shadow value.
     * Format: [inset] <offset-x> <offset-y> [<blur-radius>] [<spread-radius>] <color>
     */
    private function parseSingleShadow(string $shadow): ?array
    {
        // Remove 'inset' keyword if present (not supported in PDF)
        $shadow = preg_replace('/\binset\b/i', '', $shadow);
        $shadow = trim($shadow);

        // Extract color first (can be at beginning or end)
        $color = $this->extractColor($shadow);
        if ($color !== null) {
            $shadow = trim(str_replace($color['original'], '', $shadow));
        }

        // Parse remaining values (offset-x, offset-y, blur-radius, spread-radius)
        $values = preg_split('/\s+/', $shadow);
        $values = array_filter($values, fn($v) => trim($v) !== '');
        $values = array_values($values);

        if (count($values) < 2) {
            return null; // Need at least offset-x and offset-y
        }

        $offsetX = $this->parseLength($values[0]);
        $offsetY = $this->parseLength($values[1]);
        $blurRadius = isset($values[2]) ? $this->parseLength($values[2]) : 0.0;
        $spreadRadius = isset($values[3]) ? $this->parseLength($values[3]) : 0.0;

        if ($offsetX === null || $offsetY === null) {
            return null;
        }

        return [
            'offsetX' => $offsetX,
            'offsetY' => $offsetY,
            'blurRadius' => max(0.0, $blurRadius ?? 0.0),
            'spreadRadius' => $spreadRadius ?? 0.0,
            'color' => $color['parsed'] ?? 'rgba(0, 0, 0, 0.5)',
            'alpha' => $color['alpha'] ?? 0.5,
        ];
    }

    /**
     * Extract color from shadow string.
     */
    private function extractColor(string $shadow): ?array
    {
        // Match rgba/rgb colors
        if (preg_match('/rgba?\([^)]+\)/i', $shadow, $matches)) {
            $colorStr = $matches[0];
            $alpha = $this->extractAlphaFromColor($colorStr);
            return [
                'original' => $colorStr,
                'parsed' => $colorStr,
                'alpha' => $alpha,
            ];
        }

        // Match hex colors
        if (preg_match('/#[0-9a-f]{3,6}/i', $shadow, $matches)) {
            $colorStr = $matches[0];
            return [
                'original' => $colorStr,
                'parsed' => $colorStr,
                'alpha' => 1.0,
            ];
        }

        // Match named colors (basic support)
        $namedColors = ['black', 'white', 'red', 'green', 'blue', 'yellow', 'cyan', 'magenta', 'gray', 'grey'];
        foreach ($namedColors as $namedColor) {
            if (preg_match('/\b' . preg_quote($namedColor, '/') . '\b/i', $shadow)) {
                return [
                    'original' => $namedColor,
                    'parsed' => $namedColor,
                    'alpha' => 1.0,
                ];
            }
        }

        return null;
    }

    /**
     * Extract alpha value from rgba color.
     */
    private function extractAlphaFromColor(string $color): float
    {
        if (preg_match('/rgba?\([^,]+,[^,]+,[^,]+,\s*([0-9]*\.?[0-9]+)\s*\)/i', $color, $matches)) {
            return max(0.0, min(1.0, (float)$matches[1]));
        }
        return 1.0;
    }

    /**
     * Parse a CSS length value to points.
     */
    private function parseLength(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Handle unitless values (assume px)
        if (is_numeric($value)) {
            return (float)$value * 0.75; // px to pt conversion
        }

        // Handle values with units
        if (preg_match('/^([+-]?[0-9]*\.?[0-9]+)(px|pt|em|rem|%)?$/i', $value, $matches)) {
            $number = (float)$matches[1];
            $unit = strtolower($matches[2] ?? 'px');

            switch ($unit) {
                case 'pt':
                    return $number;
                case 'px':
                    return $number * 0.75; // px to pt
                case 'em':
                case 'rem':
                    return $number * 12.0; // Assume 12pt base font
                case '%':
                    return $number * 0.12; // Very rough approximation
                default:
                    return $number * 0.75; // Default to px conversion
            }
        }

        return null;
    }
}
