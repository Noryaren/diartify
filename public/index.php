<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/tags.php';
require __DIR__ . '/../app/scanner.php';
require __DIR__ . '/../app/stream.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$base   = base_path();

if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}

$uri = '/' . ltrim($uri, '/');

try {
    if ($uri === '/api' || str_starts_with($uri, '/api/')) {
        require __DIR__ . '/../app/api.php';
        api_dispatch($method, substr($uri, 4) ?: '/');
        exit;
    }

    if ($method === 'GET' || $method === 'HEAD') {
        if (preg_match('#^/stream/(\d+)$#', $uri, $matches)) {
            stream_song((int) $matches[1]);
        }

        if (preg_match('#^/cover/([a-f0-9]{40}\.[a-z0-9]{3,4})$#', $uri, $matches)) {
            cover_image($matches[1]);
        }
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    // Let PHP's built-in server serve static files while developing.
    if (PHP_SAPI === 'cli-server') {
        $file = __DIR__ . $uri;
        if ($uri !== '/' && is_file($file)) {
            return false;
        }
    }

    require __DIR__ . '/../app/views/shell.php';
} catch (ApiException $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} catch (Throwable $e) {
    error_log('diartify: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Terjadi kesalahan di server.';
    exit;
}
