<?php

declare(strict_types=1);

use Pagyra\Pagyra;

require dirname(__DIR__) . '/vendor/autoload.php';

$publicDir = __DIR__ . '/public';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uriPath === '/render') {
    header('Content-Type: application/pdf');

    try {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !isset($body['html']) || !is_string($body['html']) || trim($body['html']) === '') {
            throw new InvalidArgumentException("Request must include a non-empty 'html' field.");
        }

        $number = static function (mixed $value, float $fallback): float {
            if (!is_int($value) && !is_float($value)) return $fallback;
            return $value > 0 ? (float) $value : $fallback;
        };

        $pageWidth = $number($body['pageWidth'] ?? null, 794.0);
        $pageHeight = $number($body['pageHeight'] ?? null, 1123.0);
        $viewportWidth = min($number($body['viewportWidth'] ?? null, $pageWidth), $pageWidth);
        $viewportHeight = min($number($body['viewportHeight'] ?? null, $pageHeight), $pageHeight);
        $margin = max(0.0, min($number($body['margin'] ?? null, 48.0), min($pageWidth, $pageHeight) / 2.0));

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $body['html'],
            'css' => is_string($body['css'] ?? null) ? $body['css'] : '',
            'viewportWidth' => $viewportWidth,
            'viewportHeight' => $viewportHeight,
            'pageWidth' => $pageWidth,
            'pageHeight' => $pageHeight,
            'margins' => ['top' => $margin, 'right' => $margin, 'bottom' => $margin, 'left' => $margin],
            'resourceBaseDir' => $publicDir,
        ]);

        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    } catch (Throwable $error) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uriPath === '/prepare') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !isset($body['html']) || !is_string($body['html']) || trim($body['html']) === '') {
            throw new InvalidArgumentException("Request must include a non-empty 'html' field.");
        }
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $body['html'],
            'css' => is_string($body['css'] ?? null) ? $body['css'] : '',
            'viewportWidth' => isset($body['viewportWidth']) && is_numeric($body['viewportWidth']) ? max(1.0, (float) $body['viewportWidth']) : 794.0,
            'viewportHeight' => isset($body['viewportHeight']) && is_numeric($body['viewportHeight']) ? max(1.0, (float) $body['viewportHeight']) : 1123.0,
            'pageWidth' => isset($body['pageWidth']) && is_numeric($body['pageWidth']) ? max(1.0, (float) $body['pageWidth']) : 794.0,
            'pageHeight' => isset($body['pageHeight']) && is_numeric($body['pageHeight']) ? max(1.0, (float) $body['pageHeight']) : 1123.0,
            'resourceBaseDir' => $publicDir,
        ]);
        echo json_encode($prepared, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return true;
}

$file = $publicDir . ($uriPath === '/' ? '/index.html' : $uriPath);
$real = realpath($file);
$publicReal = realpath($publicDir);
if ($real !== false && $publicReal !== false && str_starts_with($real, $publicReal) && is_file($real)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
