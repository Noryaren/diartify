<?php
declare(strict_types=1);

/**
 * Load configuration once. A local config/config.php overrides the defaults.
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = [
            'app' => [
                'name'         => getenv('APP_NAME') ?: 'Diarify',
                'debug'        => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
                'timezone'     => 'UTC',
                'base_path'    => null,
                'trust_proxy'  => filter_var(getenv('TRUST_PROXY') ?: '1', FILTER_VALIDATE_BOOL),
                'token_ttl'    => 31536000,
            ],
            'db' => [
                'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=diartify;charset=utf8mb4',
                'user' => getenv('DB_USER') ?: 'diartify',
                'pass' => getenv('DB_PASS') ?: '',
            ],
            'music_paths' => array_values(array_filter(array_map(
                'trim',
                explode(PATH_SEPARATOR, (string) (getenv('MUSIC_PATHS') ?: ''))
            ))),
            'scanner' => [
                'extensions'    => ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'mp4', 'aac', 'wav', 'wma'],
                'cover_max'     => 600,
                'folder_covers' => ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'front.jpg', 'front.png', 'album.jpg', 'albumart.jpg', 'albumartlarger.jpg'],
            ],
        ];

        $file = ROOT_PATH . '/config/config.php';
        if (is_file($file)) {
            $local = require $file;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Path prefix when the app is mounted in a sub-directory. Empty string at root.
 */
function base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $configured = config('app.base_path');
    if ($configured !== null) {
        $base = rtrim('/' . trim((string) $configured, '/'), '/');
        if ($base === '/') {
            $base = '';
        }
        return $base;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    // PHP's built-in server reports the requested static file as SCRIPT_NAME,
    // which would be mistaken for a sub-directory mount.
    if (PHP_SAPI === 'cli-server' || $script === '' || !str_ends_with($script, '.php')) {
        return '';
    }

    $dir  = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');

    return $base;
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . ($path === '/' ? '/' : $path);
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (config('app.trust_proxy')) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }

        $cf = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cf !== '' && str_contains($cf, '"scheme":"https"')) {
            return true;
        }
    }

    return false;
}

function csp_nonce(): string
{
    if (empty($GLOBALS['diartify_nonce'])) {
        $GLOBALS['diartify_nonce'] = base64_encode(random_bytes(18));
    }
    return $GLOBALS['diartify_nonce'];
}

/**
 * Escape a user supplied search term for a SQL LIKE clause.
 */
function like_term(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

/**
 * Trim a user supplied search term down to something sane.
 */
function clean_search_term(mixed $value): string
{
    return mb_substr(trim((string) $value), 0, 120);
}

function song_stream_url(int $songId): string
{
    return with_media_token(url('/stream/' . $songId));
}

function cover_url(?string $coverKey): string
{
    if ($coverKey === null || $coverKey === '') {
        return '';
    }
    return with_media_token(url('/cover/' . rawurlencode($coverKey)));
}

/**
 * <audio> and <img> cannot send an Authorization header, so media URLs carry
 * the caller's own bearer token as a "t" query parameter instead.
 */
function with_media_token(string $path): string
{
    $token = function_exists('auth_token') ? auth_token() : null;

    if ($token === null || $token === '') {
        return $path;
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . 't=' . rawurlencode($token);
}

/**
 * Absolute path inside the storage directory (cover cache, logs, ...).
 */
function storage_path(string $path = ''): string
{
    $base = ROOT_PATH . '/storage';
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/**
 * Translate a file path from the machine that scanned the library into a path
 * usable on the machine that serves it (they are normally the same server).
 */
function library_paths(): array
{
    $paths = config('music_paths');
    if (!is_array($paths) || $paths === []) {
        return [];
    }

    $out = [];
    foreach ($paths as $path) {
        $path = rtrim((string) $path, '/');
        if ($path === '') {
            continue;
        }
        // Normalise "./../" segments so scan bookkeeping sees one canonical path.
        $real = realpath($path);
        $out[] = $real !== false ? $real : $path;
    }

    return $out;
}
