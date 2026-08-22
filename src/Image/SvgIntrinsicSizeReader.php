<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class SvgIntrinsicSizeReader
{
    public function read(string $bytes): ?ReplacedElementSize
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $ok = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$ok || !$document->documentElement instanceof \DOMElement) {
            return null;
        }

        $root = $document->documentElement;
        if (strtolower($root->localName ?? $root->tagName) !== 'svg') {
            return null;
        }

        $width = $this->positiveNumber($root->getAttribute('width'));
        $height = $this->positiveNumber($root->getAttribute('height'));
        $viewBox = $this->parseViewBox($root->getAttribute('viewBox'));

        if ($viewBox !== null) {
            $width ??= $viewBox['width'];
            $height ??= $viewBox['height'];
        }

        $width ??= 100.0;
        $height ??= $width;

        return new ReplacedElementSize($width, $height);
    }

    private function positiveNumber(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (preg_match('/^[\s]*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $raw, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];
        return is_finite($value) && $value > 0.0 ? $value : null;
    }

    /** @return array{width:float,height:float}|null */
    private function parseViewBox(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }

        $width = (float) $parts[2];
        $height = (float) $parts[3];
        if (!is_finite($width) || !is_finite($height) || $width <= 0.0 || $height <= 0.0) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }
}
