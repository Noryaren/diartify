<?php
declare(strict_types=1);

/**
 * Audio streaming with HTTP Range support (seek in the browser) and
 * cover art delivery.
 */

function stream_song(int $songId): void
{
    require_user();

    $stmt = db()->prepare('SELECT title, format, file_path, duration_ms FROM songs WHERE id = ? LIMIT 1');
    $stmt->execute([$songId]);
    $song = $stmt->fetch();

    if (!$song) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lagu tidak ditemukan.';
        exit;
    }

    $path = (string) $song['file_path'];
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File musik tidak dapat diakses di server.';
        exit;
    }

    $size  = (int) filesize($path);
    $mime  = stream_mime((string) $song['format']);
    $title = (string) $song['title'];

    $download = !empty($_GET['download']);

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    $disposition = $download ? 'attachment' : 'inline';
    $filename    = basename($path);
    header(
        'Content-Disposition: ' . $disposition
        . '; filename="' . addcslashes($filename, '"\\') . '"'
        . "; filename*=UTF-8''" . rawurlencode($filename)
    );

    $start = 0;
    $end   = $size - 1;
    $partial = false;

    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && $size > 0) {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                // Suffix range: last N bytes.
                $suffix = (int) $m[2];
                $start  = max(0, $size - $suffix);
                $end    = $size - 1;
            } else {
                $start = (int) $m[1];
                $end   = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            }

            if ($start <= $end && $start < $size) {
                $partial = true;
            } else {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
        }
    }

    $length = $partial ? $end - $start + 1 : $size;

    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        exit;
    }

    if ($start > 0) {
        fseek($fh, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !connection_aborted()) {
        $chunk = fread($fh, min(8192 * 32, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    fclose($fh);
    exit;
}

function stream_mime(string $format): string
{
    return match (strtolower($format)) {
        'mp3'  => 'audio/mpeg',
        'flac' => 'audio/flac',
        'ogg', 'oga' => 'audio/ogg',
        'opus' => 'audio/ogg; codecs=opus',
        'm4a', 'mp4' => 'audio/mp4',
        'aac'  => 'audio/aac',
        'wav'  => 'audio/wav',
        'wma'  => 'audio/x-ms-wma',
        default => 'application/octet-stream',
    };
}

function cover_image(string $key): void
{
    require_user();

    if (!preg_match('/^[a-f0-9]{40}\.(webp|jpg|jpeg|png)$/', $key)) {
        http_response_code(400);
        exit;
    }

    $file = storage_path('covers/' . $key);
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }

    $mime = match (pathinfo($key, PATHINFO_EXTENSION)) {
        'webp' => 'image/webp',
        'png'  => 'image/png',
        default => 'image/jpeg',
    };

    $etag = '"' . $key . '"';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=2592000');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}
