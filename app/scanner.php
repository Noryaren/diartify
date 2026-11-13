<?php
declare(strict_types=1);

/**
 * Library scanner: walks the configured music folders, reads tags with
 * audio_probe(), keeps artists/albums/songs in sync and caches cover art.
 *
 * Incremental by default: a file whose size and mtime did not change is
 * skipped, so re-scans of a few thousand songs take seconds on a small box.
 */

function scanner_run(?callable $progress = null): array
{
    $roots = library_paths();
    if ($roots === []) {
        throw new RuntimeException('Tidak ada folder musik yang dikonfigurasi (music_paths).');
    }

    $pdo = db();

    $stmt = $pdo->prepare("INSERT INTO scan_runs (status) VALUES ('running')");
    $stmt->execute();
    $runId = (int) $pdo->lastInsertId();

    $stats = [
        'files'    => 0,
        'added'    => 0,
        'updated'  => 0,
        'removed'  => 0,
        'skipped'  => 0,
        'errors'   => 0,
        'run_id'   => $runId,
    ];

    $started = microtime(true);

    try {
        $existing = [];
        foreach ($pdo->query('SELECT id, file_path, file_size, file_mtime FROM songs') as $row) {
            $existing[$row['file_path']] = [
                'id'    => (int) $row['id'],
                'size'  => (int) $row['file_size'],
                'mtime' => (int) $row['file_mtime'],
            ];
        }

        $seen       = [];
        $dirCovers  = [];

        foreach (scanner_audio_files($roots) as $path) {
            $stats['files']++;

            $size  = @filesize($path);
            $mtime = @filemtime($path);

            if ($size === false || $mtime === false) {
                $stats['errors']++;
                continue;
            }

            $seen[$path] = true;

            if (
                isset($existing[$path])
                && $existing[$path]['size'] === $size
                && $existing[$path]['mtime'] === $mtime
            ) {
                $stats['skipped']++;
            } else {
                try {
                    $outcome = scanner_index_file($path, $size, $mtime, $dirCovers, $pdo);
                    $stats[$outcome]++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    error_log('diartify scan: ' . $path . ' — ' . $e->getMessage());
                }
            }

            if ($progress !== null && $stats['files'] % 25 === 0) {
                $progress($stats);
            }
        }

        // Songs whose file disappeared. Keep rows only for configured roots
        // that are currently unmounted (music drive not plugged in) so a scan
        // never wipes the library just because a volume is missing.
        $missingRoots = array_values(array_filter($roots, static fn ($r): bool => !is_dir($r)));

        foreach ($existing as $path => $info) {
            if (isset($seen[$path])) {
                continue;
            }

            $underMissingRoot = false;
            foreach ($missingRoots as $root) {
                if (str_starts_with($path, rtrim($root, '/') . '/')) {
                    $underMissingRoot = true;
                    break;
                }
            }

            if ($underMissingRoot) {
                continue;
            }

            $del = $pdo->prepare('DELETE FROM songs WHERE id = ?');
            $del->execute([$info['id']]);
            $stats['removed']++;
        }

        scanner_prune($pdo);
        scanner_repair_covers($pdo);

        $elapsed = round(microtime(true) - $started, 2);

        $upd = $pdo->prepare(
            "UPDATE scan_runs SET status = 'ok', files_seen = ?, songs_added = ?, songs_updated = ?, songs_removed = ?, message = ?, finished_at = NOW() WHERE id = ?"
        );
        $upd->execute([
            $stats['files'],
            $stats['added'],
            $stats['updated'],
            $stats['removed'],
            "Selesai dalam {$elapsed} detik, {$stats['errors']} galat.",
            $runId,
        ]);

        $stats['elapsed'] = $elapsed;
    } catch (Throwable $e) {
        $upd = $pdo->prepare(
            "UPDATE scan_runs SET status = 'error', message = ?, finished_at = NOW() WHERE id = ?"
        );
        $upd->execute([mb_substr($e->getMessage(), 0, 1000), $runId]);
        throw $e;
    }

    if ($progress !== null) {
        $progress($stats);
    }

    return $stats;
}

function scanner_audio_files(array $roots): Generator
{
    $exts = array_flip(array_map('strtolower', (array) config('scanner.extensions', [])));

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $it  = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($it as $file) {
            try {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $name = $file->getFilename();
                if ($name === '' || $name[0] === '.') {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!isset($exts[$ext])) {
                    continue;
                }

                $real = realpath($file->getPathname());
                if ($real !== false) {
                    yield $real;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }
}

function scanner_index_file(string $path, int $size, int $mtime, array &$dirCovers, PDO $pdo): string
{
    $info = audio_probe($path);

    $title = $info['title'];
    if ($title === null || trim($title) === '') {
        $title = pathinfo($path, PATHINFO_FILENAME);
        $title = str_replace(['_', '  '], [' ', ' '], $title);
        // Drop a leading track prefix like "01. " or "01 - ".
        $title = preg_replace('/^\d{1,3}[\s._)-]+/', '', $title) ?: $title;
    }
    $title = mb_substr(trim($title), 0, 255);

    $albumTitle = ($info['album'] !== null && trim($info['album']) !== '')
        ? mb_substr(trim($info['album']), 0, 255)
        : 'Unknown Album';

    $albumArtist = ($info['album_artist'] !== null && trim($info['album_artist']) !== '')
        ? trim($info['album_artist'])
        : (($info['artist'] !== null && trim($info['artist']) !== '') ? trim($info['artist']) : 'Unknown Artist');

    $performer = ($info['artist'] !== null && trim($info['artist']) !== '')
        ? trim($info['artist'])
        : $albumArtist;

    $albumArtist = mb_substr($albumArtist, 0, 255);
    $performer   = mb_substr($performer, 0, 255);

    $albumArtistId = scanner_artist_id($albumArtist, $pdo);
    $performerId   = $performer === $albumArtist ? $albumArtistId : scanner_artist_id($performer, $pdo);

    $albumId = scanner_album_id($albumArtistId, $albumTitle, $info['year'], $info['genre'], $pdo);

    // Cover art: embedded first, then a folder image shared across the album.
    $coverKey = null;
    if ($info['cover'] !== null) {
        $coverKey = scanner_save_cover($info['cover']['data']);
    }
    if ($coverKey === null) {
        $dir = dirname($path);
        if (!isset($dirCovers[$dir])) {
            $dirCovers[$dir] = scanner_folder_cover($dir);
        }
        if ($dirCovers[$dir] !== null) {
            $coverKey = $dirCovers[$dir];
        }
    }

    $dir      = dirname($path);
    $ext      = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $duration = max(0, (int) $info['duration_ms']);

    $existing = null;
    $stmt     = $pdo->prepare('SELECT id, cover_key FROM songs WHERE file_path = ? LIMIT 1');
    $stmt->execute([$path]);
    $row = $stmt->fetch();
    if ($row) {
        $existing = $row;
    }

    if ($existing !== null) {
        $upd = $pdo->prepare(
            'UPDATE songs SET album_id = ?, artist_id = ?, title = ?, track_no = ?, disc_no = ?, duration_ms = ?, year = ?, genre = ?, format = ?, bitrate = ?, sample_rate = ?, channels = ?, cover_key = ?, lyrics = ?, file_size = ?, file_mtime = ? WHERE id = ?'
        );
        $upd->execute([
            $albumId,
            $performerId,
            $title,
            max(0, (int) $info['track_no']),
            max(1, (int) $info['disc_no']),
            $duration,
            $info['year'],
            $info['genre'],
            mb_substr($ext !== '' ? $ext : 'audio', 0, 8),
            $info['bitrate'],
            $info['sample_rate'],
            $info['channels'],
            $coverKey ?? $existing['cover_key'],
            $info['lyrics'],
            $size,
            $mtime,
            (int) $existing['id'],
        ]);
        $songId = (int) $existing['id'];
        $outcome = 'updated';
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO songs (album_id, artist_id, title, track_no, disc_no, duration_ms, year, genre, format, bitrate, sample_rate, channels, cover_key, lyrics, file_path, file_size, file_mtime, added_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $albumId,
            $performerId,
            $title,
            max(0, (int) $info['track_no']),
            max(1, (int) $info['disc_no']),
            $duration,
            $info['year'],
            $info['genre'],
            mb_substr($ext !== '' ? $ext : 'audio', 0, 8),
            $info['bitrate'],
            $info['sample_rate'],
            $info['channels'],
            $coverKey,
            $info['lyrics'],
            $path,
            $size,
            $mtime,
        ]);
        $songId  = (int) $pdo->lastInsertId();
        $outcome = 'added';
    }

    // Backfill album metadata the first track happens to carry.
    $setAlbum = [];
    $args     = [];
    if ($info['year'] !== null) {
        $setAlbum[] = 'year = COALESCE(year, ?)';
        $args[]     = $info['year'];
    }
    if ($info['genre'] !== null) {
        $setAlbum[] = 'genre = COALESCE(genre, ?)';
        $args[]     = $info['genre'];
    }
    if ($coverKey !== null) {
        $setAlbum[] = 'cover_key = COALESCE(cover_key, ?)';
        $args[]     = $coverKey;
    }
    if ($setAlbum !== []) {
        $args[] = $albumId;
        $pdo->prepare('UPDATE albums SET ' . implode(', ', $setAlbum) . ' WHERE id = ?')
            ->execute($args);
    }

    // An album's cover should also light up its artist card.
    if ($coverKey !== null) {
        $pdo->prepare('UPDATE artists SET cover_key = COALESCE(cover_key, ?) WHERE id = ?')
            ->execute([$coverKey, $albumArtistId]);
    }

    return $outcome;
}

function scanner_artist_id(string $name, PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM artists WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $sort = preg_replace('/^the\s+/i', '', $name) ?: $name;

    $ins = $pdo->prepare('INSERT INTO artists (name, sort_name, created_at) VALUES (?, ?, NOW())');
    $ins->execute([$name, mb_substr($sort, 0, 255)]);

    return (int) $pdo->lastInsertId();
}

function scanner_album_id(int $artistId, string $title, ?int $year, ?string $genre, PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM albums WHERE artist_id = ? AND title = ? LIMIT 1');
    $stmt->execute([$artistId, $title]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $ins = $pdo->prepare('INSERT INTO albums (artist_id, title, year, genre, added_at) VALUES (?, ?, ?, ?, NOW())');
    $ins->execute([$artistId, $title, $year, $genre]);

    return (int) $pdo->lastInsertId();
}

/**
 * Store cover art as a downscaled image, keyed by content hash.
 * Uses WebP when the GD build supports it, otherwise JPEG/PNG.
 */
function scanner_save_cover(string $bytes): ?string
{
    if ($bytes === '' || strlen($bytes) > 20 * 1024 * 1024) {
        return null;
    }

    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $ext = match (true) {
        function_exists('imagewebp') => 'webp',
        function_exists('imagejpeg') => 'jpg',
        function_exists('imagepng')  => 'png',
        default => null,
    };

    if ($ext === null) {
        return null;
    }

    $max  = max(160, (int) config('scanner.cover_max', 600));
    $key  = sha1($bytes . '|' . $max . '|' . $ext) . '.' . $ext;
    $file = storage_path('covers/' . $key);

    if (is_file($file)) {
        return $key;
    }

    $img = @imagecreatefromstring($bytes);
    if ($img === false) {
        return null;
    }

    $w = imagesx($img);
    $h = imagesy($img);

    if (max($w, $h) > $max) {
        $scale   = $max / max($w, $h);
        $resized = @imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        if ($resized !== false) {
            $img = $resized;
        }
    }

    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
        return null;
    }

    $ok = match ($ext) {
        'webp' => @imagewebp($img, $file, 82),
        'jpg'  => @imagejpeg($img, $file, 85),
        default => @imagepng($img, $file, 6),
    };

    if ($ok === false || !is_file($file) || (int) filesize($file) === 0) {
        return null;
    }

    return $key;
}

function scanner_folder_cover(string $dir): ?string
{
    foreach ((array) config('scanner.folder_covers', []) as $name) {
        $candidate = $dir . '/' . $name;
        if (is_file($candidate) && is_readable($candidate)) {
            $bytes = @file_get_contents($candidate);
            if ($bytes !== false) {
                $key = scanner_save_cover($bytes);
                if ($key !== null) {
                    return $key;
                }
            }
        }
    }

    // Any image sitting in the folder (case-insensitive) as a last resort.
    $entries = @scandir($dir);
    if ($entries === false) {
        return null;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && !str_starts_with($entry, '.')) {
            $bytes = @file_get_contents($dir . '/' . $entry);
            if ($bytes !== false) {
                $key = scanner_save_cover($bytes);
                if ($key !== null) {
                    return $key;
                }
            }
        }
    }

    return null;
}

function scanner_prune(PDO $pdo): void
{
    $pdo->exec('DELETE a FROM albums a WHERE NOT EXISTS (SELECT 1 FROM songs s WHERE s.album_id = a.id)');
    $pdo->exec(
        'DELETE a FROM artists a
         WHERE NOT EXISTS (SELECT 1 FROM songs s WHERE s.artist_id = a.id)
           AND NOT EXISTS (SELECT 1 FROM albums al WHERE al.artist_id = a.id)'
    );
}

/**
 * Make sure every artist and album can show a cover even if only a track
 * carries one (and the other way around for tracks without album art).
 */
function scanner_repair_covers(PDO $pdo): void
{
    $pdo->exec(
        'UPDATE artists a
         JOIN (
             SELECT artist_id, MIN(cover_key) AS ck FROM albums WHERE cover_key IS NOT NULL GROUP BY artist_id
         ) t ON t.artist_id = a.id
         SET a.cover_key = COALESCE(a.cover_key, t.ck)'
    );

    $pdo->exec(
        'UPDATE artists a
         JOIN (
             SELECT artist_id, MIN(cover_key) AS ck FROM songs WHERE cover_key IS NOT NULL GROUP BY artist_id
         ) t ON t.artist_id = a.id
         SET a.cover_key = COALESCE(a.cover_key, t.ck)'
    );

    $pdo->exec(
        'UPDATE albums al
         JOIN (
             SELECT album_id, MIN(cover_key) AS ck FROM songs WHERE cover_key IS NOT NULL GROUP BY album_id
         ) t ON t.album_id = al.id
         SET al.cover_key = COALESCE(al.cover_key, t.ck)'
    );
}

function scanner_status(): ?array
{
    $row = db()->query(
        'SELECT id, status, files_seen, songs_added, songs_updated, songs_removed, message, started_at, finished_at
         FROM scan_runs ORDER BY id DESC LIMIT 1'
    )->fetch();

    if (!$row) {
        return null;
    }

    $row['id']          = (int) $row['id'];
    $row['files_seen']  = (int) $row['files_seen'];
    $row['songs_added'] = (int) $row['songs_added'];
    $row['songs_updated'] = (int) $row['songs_updated'];
    $row['songs_removed'] = (int) $row['songs_removed'];

    return $row;
}
