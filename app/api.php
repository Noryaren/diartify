<?php
declare(strict_types=1);

/**
 * JSON API used by the single-page player.
 */

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new ApiException('Body JSON tidak valid.', 400);
    }

    return $data;
}

function api_paging(int $default = 48): array
{
    $limit  = (int) ($_GET['limit'] ?? $default);
    $limit  = max(1, min(200, $limit));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    return [$limit, $offset];
}

/* -------------------------------------------------------------------------
 * Row → DTO
 * ---------------------------------------------------------------------- */

function api_song_sql(int $userId, string $extraCols = ''): string
{
    return 'SELECT s.id, s.album_id, s.artist_id, s.title, s.track_no, s.disc_no, s.duration_ms,
                   s.year, s.genre, s.format, s.bitrate, s.sample_rate, s.channels,
                   s.cover_key, s.added_at,
                   a.title AS album_title, a.cover_key AS album_cover,
                   alar.name AS album_artist_name,
                   ar.name AS artist_name,
                   f.song_id IS NOT NULL AS liked,
                   st.play_count, st.last_played_at'
            . ($extraCols !== '' ? ', ' . $extraCols : '') . '
            FROM songs s
            LEFT JOIN albums a ON a.id = s.album_id
            LEFT JOIN artists alar ON alar.id = a.artist_id
            LEFT JOIN artists ar ON ar.id = s.artist_id
            LEFT JOIN favorites f ON f.song_id = s.id AND f.user_id = ' . $userId . '
            LEFT JOIN song_stats st ON st.song_id = s.id AND st.user_id = ' . $userId;
}

function api_song(array $row, bool $withLyrics = false): array
{
    $song = [
        'id'          => (int) $row['id'],
        'title'       => (string) $row['title'],
        'trackNo'     => (int) $row['track_no'],
        'discNo'      => (int) $row['disc_no'],
        'durationMs'  => (int) $row['duration_ms'],
        'year'        => $row['year'] !== null ? (int) $row['year'] : null,
        'genre'       => $row['genre'] ?? null,
        'format'      => (string) $row['format'],
        'bitrate'     => $row['bitrate'] !== null ? (int) $row['bitrate'] : null,
        'artist'      => [
            'id'   => $row['artist_id'] !== null ? (int) $row['artist_id'] : null,
            'name' => (string) ($row['artist_name'] ?? $row['album_artist_name'] ?? 'Unknown Artist'),
        ],
        'album'       => [
            'id'    => $row['album_id'] !== null ? (int) $row['album_id'] : null,
            'title' => (string) ($row['album_title'] ?? 'Unknown Album'),
        ],
        'albumArtist' => (string) ($row['album_artist_name'] ?? $row['artist_name'] ?? 'Unknown Artist'),
        'cover'       => cover_url($row['album_cover'] ?? null) ?: cover_url($row['cover_key'] ?? null),
        'streamUrl'   => song_stream_url((int) $row['id']),
        'liked'       => !empty($row['liked']),
        'playCount'   => (int) ($row['play_count'] ?? 0),
        'playedAt'    => $row['last_played_at'] ?? null,
    ];

    if ($withLyrics && !empty($row['lyrics'])) {
        $song['lyrics'] = (string) $row['lyrics'];
    }

    return $song;
}

function api_album(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'title'      => (string) $row['title'],
        'year'       => $row['year'] !== null ? (int) $row['year'] : null,
        'genre'      => $row['genre'] ?? null,
        'cover'      => cover_url($row['cover_key'] ?? null),
        'artist'     => [
            'id'   => (int) $row['artist_id'],
            'name' => (string) $row['artist_name'],
        ],
        'songCount'  => (int) ($row['song_count'] ?? 0),
        'durationMs' => (int) ($row['duration_ms'] ?? 0),
        'addedAt'    => $row['added_at'] ?? null,
    ];
}

function api_artist(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'cover'      => cover_url($row['cover_key'] ?? null),
        'songCount'  => (int) ($row['song_count'] ?? 0),
        'albumCount' => (int) ($row['album_count'] ?? 0),
        'playCount'  => (int) ($row['play_count'] ?? 0),
    ];
}

function api_playlist(array $row): array
{
    return [
        'id'          => (int) $row['id'],
        'name'        => (string) $row['name'],
        'description' => (string) ($row['description'] ?? ''),
        'songCount'   => (int) ($row['song_count'] ?? 0),
        'durationMs'  => (int) ($row['duration_ms'] ?? 0),
        'cover'       => cover_url($row['cover_key'] ?? null),
        'createdAt'   => $row['created_at'] ?? null,
        'updatedAt'   => $row['updated_at'] ?? null,
    ];
}

/* -------------------------------------------------------------------------
 * Auth
 * ---------------------------------------------------------------------- */

function api_health(): void
{
    $dbOk = true;
    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        $dbOk = false;
    }

    json_response([
        'ok'      => true,
        'name'    => 'diartify',
        'version' => '1.0.0',
        'db'      => $dbOk,
    ]);
}

function api_login(): void
{
    if (jwt_secret_broken()) {
        json_response([
            'ok'    => false,
            'error' => 'Konfigurasi login rusak: storage/jwt.secret tidak bisa dibaca oleh '
                . 'user web (www-data), jadi token tidak pernah valid. Perbaiki dengan: '
                . 'sudo chown -R www-data:www-data /home/diartify/storage',
        ], 500);
    }

    $input = json_input();
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass  = (string) ($input['password'] ?? '');

    $token = attempt_login($email, $pass);

    if ($token === null) {
        json_response(['ok' => false, 'error' => 'Email atau password salah.'], 401);
    }

    json_response(['ok' => true, 'token' => $token, 'user' => current_user()]);
}

function api_register(): void
{
    if (jwt_secret_broken()) {
        json_response([
            'ok'    => false,
            'error' => 'Konfigurasi login rusak: storage/jwt.secret tidak bisa dibaca oleh '
                . 'user web (www-data), jadi token tidak pernah valid. Perbaiki dengan: '
                . 'sudo chown -R www-data:www-data /home/diartify/storage',
        ], 500);
    }

    $input = json_input();
    $name  = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass  = (string) ($input['password'] ?? '');

    if ($name === '') {
        throw new ApiException('Nama wajib diisi.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Email tidak valid.', 422);
    }
    if (strlen($pass) < 6) {
        throw new ApiException('Password minimal 6 karakter.', 422);
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        throw new ApiException('Email sudah terdaftar.', 409);
    }

    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, 0, NOW())'
    );
    $stmt->execute([$name, mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT)]);

    $token = login_user((int) db()->lastInsertId());
    json_response(['ok' => true, 'token' => $token, 'user' => current_user()], 201);
}

function api_logout(): void
{
    // Stateless: the server keeps no token state, the browser drops it.
    json_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Home
 * ---------------------------------------------------------------------- */

function api_home(): void
{
    $user = require_user();
    $uid  = $user['id'];

    $recentAlbums = db()->query(
        'SELECT a.id, a.title, a.year, a.genre, a.cover_key, a.added_at,
                ar.id AS artist_id, ar.name AS artist_name,
                (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id) AS song_count
         FROM albums a
         JOIN artists ar ON ar.id = a.artist_id
         ORDER BY a.added_at DESC, a.id DESC
         LIMIT 14'
    )->fetchAll();

    $recentlyPlayed = db()->prepare(
        api_song_sql($uid, 'st.last_played_at AS lp') . '
         WHERE st.user_id = ? AND st.last_played_at IS NOT NULL
         ORDER BY st.last_played_at DESC
         LIMIT 14'
    );
    $recentlyPlayed->execute([$uid]);
    $recentlyPlayed = $recentlyPlayed->fetchAll();

    $topArtists = db()->prepare(
        'SELECT ar.id, ar.name, ar.cover_key,
                COALESCE(SUM(st.play_count), 0) AS play_count,
                (SELECT COUNT(*) FROM songs s2 WHERE s2.artist_id = ar.id) AS song_count,
                (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id) AS album_count
         FROM song_stats st
         JOIN songs s ON s.id = st.song_id
         JOIN artists ar ON ar.id = s.artist_id
         WHERE st.user_id = ?
         GROUP BY ar.id
         ORDER BY play_count DESC, ar.name
         LIMIT 10'
    );
    $topArtists->execute([$uid]);
    $topArtists = $topArtists->fetchAll();

    $topSongs = db()->prepare(
        api_song_sql($uid, 'st.play_count AS pc') . '
         WHERE st.user_id = ? AND st.play_count > 0
         ORDER BY pc DESC, st.last_played_at DESC
         LIMIT 10'
    );
    $topSongs->execute([$uid]);
    $topSongs = $topSongs->fetchAll();

    $totals = db()->query(
        'SELECT (SELECT COUNT(*) FROM songs) AS songs,
                (SELECT COUNT(*) FROM albums) AS albums,
                (SELECT COUNT(*) FROM artists) AS artists,
                COALESCE((SELECT SUM(duration_ms) FROM songs), 0) AS duration_ms'
    )->fetch();

    json_response([
        'ok'             => true,
        'recentAlbums'   => array_map('api_album', $recentAlbums),
        'recentlyPlayed' => array_map(api_song(...), $recentlyPlayed),
        'topArtists'     => array_map('api_artist', $topArtists),
        'topSongs'       => array_map(api_song(...), $topSongs),
        'totals'         => [
            'songs'      => (int) $totals['songs'],
            'albums'     => (int) $totals['albums'],
            'artists'    => (int) $totals['artists'],
            'durationMs' => (int) $totals['duration_ms'],
        ],
        'lastScan'       => scanner_status(),
    ]);
}

/* -------------------------------------------------------------------------
 * Albums
 * ---------------------------------------------------------------------- */

function api_albums(): void
{
    require_user();
    [$limit, $offset] = api_paging(48);

    $sort = (string) ($_GET['sort'] ?? 'recent');
    $order = match ($sort) {
        'title'  => 'a.title ASC',
        'artist' => 'ar.sort_name ASC, a.title ASC',
        'year'   => 'a.year DESC, a.title ASC',
        default  => 'a.added_at DESC, a.id DESC',
    };

    $total = (int) db()->query('SELECT COUNT(*) FROM albums')->fetchColumn();

    $rows = db()->prepare(
        "SELECT a.id, a.title, a.year, a.genre, a.cover_key, a.added_at,
                ar.id AS artist_id, ar.name AS artist_name,
                (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM songs s WHERE s.album_id = a.id) AS duration_ms
         FROM albums a
         JOIN artists ar ON ar.id = a.artist_id
         ORDER BY {$order}
         LIMIT {$limit} OFFSET {$offset}"
    );
    $rows->execute();

    json_response([
        'ok'    => true,
        'items' => array_map('api_album', $rows->fetchAll()),
        'total' => $total,
    ]);
}

function api_album_detail(int $albumId): void
{
    $user = require_user();
    $uid  = $user['id'];

    $stmt = db()->prepare(
        'SELECT a.id, a.title, a.year, a.genre, a.cover_key, a.added_at,
                ar.id AS artist_id, ar.name AS artist_name,
                (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM songs s WHERE s.album_id = a.id) AS duration_ms
         FROM albums a
         JOIN artists ar ON ar.id = a.artist_id
         WHERE a.id = ? LIMIT 1'
    );
    $stmt->execute([$albumId]);
    $album = $stmt->fetch();

    if (!$album) {
        throw new ApiException('Album tidak ditemukan.', 404);
    }

    $songs = db()->prepare(
        api_song_sql($uid, 's.lyrics') . '
         WHERE s.album_id = ?
         ORDER BY s.disc_no, s.track_no, s.title'
    );
    $songs->execute([$albumId]);

    json_response([
        'ok'    => true,
        'album' => api_album($album),
        'songs' => array_map(static fn ($row) => api_song($row, true), $songs->fetchAll()),
    ]);
}

/* -------------------------------------------------------------------------
 * Artists
 * ---------------------------------------------------------------------- */

function api_artists(): void
{
    require_user();
    [$limit, $offset] = api_paging(48);

    $total = (int) db()->query('SELECT COUNT(*) FROM artists')->fetchColumn();

    $rows = db()->prepare(
        'SELECT ar.id, ar.name, ar.cover_key,
                (SELECT COUNT(*) FROM songs s WHERE s.artist_id = ar.id) AS song_count,
                (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id) AS album_count,
                0 AS play_count
         FROM artists ar
         ORDER BY ar.sort_name ASC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $rows->execute();

    json_response([
        'ok'    => true,
        'items' => array_map('api_artist', $rows->fetchAll()),
        'total' => $total,
    ]);
}

function api_artist_detail(int $artistId): void
{
    $user = require_user();
    $uid  = $user['id'];

    $stmt = db()->prepare(
        'SELECT ar.id, ar.name, ar.cover_key,
                (SELECT COUNT(*) FROM songs s WHERE s.artist_id = ar.id) AS song_count,
                (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id) AS album_count,
                0 AS play_count
         FROM artists ar WHERE ar.id = ? LIMIT 1'
    );
    $stmt->execute([$artistId]);
    $artist = $stmt->fetch();

    if (!$artist) {
        throw new ApiException('Artis tidak ditemukan.', 404);
    }

    $albums = db()->prepare(
        'SELECT a.id, a.title, a.year, a.genre, a.cover_key, a.added_at,
                ar.id AS artist_id, ar.name AS artist_name,
                (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM songs s WHERE s.album_id = a.id) AS duration_ms
         FROM albums a
         JOIN artists ar ON ar.id = a.artist_id
         WHERE a.artist_id = ?
         ORDER BY a.year DESC, a.added_at DESC
         LIMIT 60'
    );
    $albums->execute([$artistId]);

    $top = db()->prepare(
        api_song_sql($uid) . '
         WHERE s.artist_id = ?
         ORDER BY COALESCE(st.play_count, 0) DESC, s.added_at DESC
         LIMIT 10'
    );
    $top->execute([$artistId]);

    json_response([
        'ok'     => true,
        'artist' => api_artist($artist),
        'albums' => array_map('api_album', $albums->fetchAll()),
        'topSongs' => array_map('api_song', $top->fetchAll()),
    ]);
}

/* -------------------------------------------------------------------------
 * Songs
 * ---------------------------------------------------------------------- */

function api_songs(): void
{
    $user = require_user();
    $uid  = $user['id'];
    [$limit, $offset] = api_paging(100);

    $sort = (string) ($_GET['sort'] ?? 'recent');
    $q    = clean_search_term($_GET['q'] ?? '');

    $order = match ($sort) {
        'title'    => 's.title ASC',
        'artist'   => 'ar.sort_name IS NULL, ar.sort_name ASC, s.title ASC',
        'album'    => 'a.title ASC, s.disc_no, s.track_no',
        'plays'    => 'COALESCE(st.play_count, 0) DESC, s.added_at DESC',
        'duration' => 's.duration_ms DESC',
        default    => 's.added_at DESC, s.id DESC',
    };

    $where  = '';
    $params = [];

    if ($q !== '') {
        $like = like_term($q);
        $where = 'WHERE s.title LIKE ? OR ar.name LIKE ? OR a.title LIKE ?';
        $params = [$like, $like, $like];
    }

    $countStmt = db()->prepare(
        'SELECT COUNT(*)
         FROM songs s
         LEFT JOIN albums a ON a.id = s.album_id
         LEFT JOIN artists ar ON ar.id = s.artist_id ' . $where
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        api_song_sql($uid) . ' ' . $where . " ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);

    json_response([
        'ok'    => true,
        'items' => array_map('api_song', $stmt->fetchAll()),
        'total' => $total,
    ]);
}

function api_liked(): void
{
    $user = require_user();
    $uid  = $user['id'];
    [$limit, $offset] = api_paging(100);

    $countStmt = db()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $countStmt->execute([$uid]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        api_song_sql($uid) . '
         JOIN favorites fav ON fav.song_id = s.id AND fav.user_id = ?
         ORDER BY fav.created_at DESC, fav.song_id DESC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $stmt->execute([$uid]);

    json_response([
        'ok'    => true,
        'items' => array_map('api_song', $stmt->fetchAll()),
        'total' => $total,
    ]);
}

function api_like_song(int $songId, bool $on): void
{
    $user = require_user();
    $uid  = $user['id'];

    if ($on) {
        $stmt = db()->prepare('INSERT IGNORE INTO favorites (user_id, song_id, created_at) VALUES (?, ?, NOW())');
    } else {
        $stmt = db()->prepare('DELETE FROM favorites WHERE user_id = ? AND song_id = ?');
    }
    $stmt->execute([$uid, $songId]);

    json_response(['ok' => true, 'liked' => $on]);
}

function api_played_song(int $songId): void
{
    $user = require_user();
    $uid  = $user['id'];

    $exists = db()->prepare('SELECT id FROM songs WHERE id = ? LIMIT 1');
    $exists->execute([$songId]);
    if (!$exists->fetch()) {
        throw new ApiException('Lagu tidak ditemukan.', 404);
    }

    $stmt = db()->prepare(
        'INSERT INTO song_stats (user_id, song_id, play_count, last_played_at)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE play_count = play_count + 1, last_played_at = NOW()'
    );
    $stmt->execute([$uid, $songId]);

    json_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Search
 * ---------------------------------------------------------------------- */

function api_search(): void
{
    $user = require_user();
    $uid  = $user['id'];
    $q    = clean_search_term($_GET['q'] ?? '');

    if ($q === '') {
        json_response([
            'ok'       => true,
            'query'    => '',
            'songs'    => [],
            'albums'   => [],
            'artists'  => [],
            'playlists' => [],
        ]);
    }

    $like = like_term($q);

    $songs = db()->prepare(
        api_song_sql($uid) . '
         WHERE s.title LIKE ? OR ar.name LIKE ? OR a.title LIKE ?
         ORDER BY s.title ASC LIMIT 12'
    );
    $songs->execute([$like, $like, $like]);

    $albums = db()->prepare(
        'SELECT a.id, a.title, a.year, a.genre, a.cover_key, a.added_at,
                ar.id AS artist_id, ar.name AS artist_name,
                (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM songs s WHERE s.album_id = a.id) AS duration_ms
         FROM albums a
         JOIN artists ar ON ar.id = a.artist_id
         WHERE a.title LIKE ? OR ar.name LIKE ?
         ORDER BY a.title ASC LIMIT 8'
    );
    $albums->execute([$like, $like]);

    $artists = db()->prepare(
        'SELECT ar.id, ar.name, ar.cover_key,
                (SELECT COUNT(*) FROM songs s WHERE s.artist_id = ar.id) AS song_count,
                (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id) AS album_count,
                0 AS play_count
         FROM artists ar
         WHERE ar.name LIKE ?
         ORDER BY ar.name ASC LIMIT 8'
    );
    $artists->execute([$like]);

    $playlists = db()->prepare(
        'SELECT p.id, p.name, p.description, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = p.id) AS duration_ms,
                (SELECT s.cover_key FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id
                  WHERE ps.playlist_id = p.id AND s.cover_key IS NOT NULL ORDER BY ps.position LIMIT 1) AS cover_key
         FROM playlists p
         WHERE p.user_id = ? AND p.name LIKE ?
         ORDER BY p.updated_at DESC LIMIT 8'
    );
    $playlists->execute([$uid, $like]);

    json_response([
        'ok'        => true,
        'query'     => $q,
        'songs'     => array_map('api_song', $songs->fetchAll()),
        'albums'    => array_map('api_album', $albums->fetchAll()),
        'artists'   => array_map('api_artist', $artists->fetchAll()),
        'playlists' => array_map('api_playlist', $playlists->fetchAll()),
    ]);
}

/* -------------------------------------------------------------------------
 * Playlists
 * ---------------------------------------------------------------------- */

function api_playlists(): void
{
    $user = require_user();
    $uid  = $user['id'];

    $rows = db()->prepare(
        'SELECT p.id, p.name, p.description, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = p.id) AS duration_ms,
                (SELECT s.cover_key FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id
                  WHERE ps.playlist_id = p.id AND s.cover_key IS NOT NULL ORDER BY ps.position LIMIT 1) AS cover_key
         FROM playlists p
         WHERE p.user_id = ?
         ORDER BY p.updated_at DESC'
    );
    $rows->execute([$uid]);

    json_response(['ok' => true, 'items' => array_map('api_playlist', $rows->fetchAll())]);
}

function api_playlist_create(): void
{
    $user  = require_user();
    $input = json_input();

    $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 190);
    $desc = mb_substr(trim((string) ($input['description'] ?? '')), 0, 1000);

    if ($name === '') {
        throw new ApiException('Nama playlist wajib diisi.', 422);
    }

    $stmt = db()->prepare('INSERT INTO playlists (user_id, name, description, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->execute([$user['id'], $name, $desc]);
    $id = (int) db()->lastInsertId();

    json_response(['ok' => true, 'id' => $id], 201);
}

function api_playlist_owned(int $playlistId): array
{
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM playlists WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$playlistId, $user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ApiException('Playlist tidak ditemukan.', 404);
    }

    return $row;
}

function api_playlist_detail(int $playlistId): void
{
    $row = api_playlist_owned($playlistId);
    $user = require_user();
    $uid  = $user['id'];

    $songs = db()->prepare(
        'SELECT s.id, s.album_id, s.artist_id, s.title, s.track_no, s.disc_no, s.duration_ms,
                s.year, s.genre, s.format, s.bitrate, s.sample_rate, s.channels,
                s.cover_key, s.added_at, s.lyrics,
                a.title AS album_title, a.cover_key AS album_cover,
                alar.name AS album_artist_name,
                ar.name AS artist_name,
                f.song_id IS NOT NULL AS liked,
                st.play_count, st.last_played_at,
                ps.id AS playlist_song_id, ps.position
         FROM playlist_songs ps
         JOIN songs s ON s.id = ps.song_id
         LEFT JOIN albums a ON a.id = s.album_id
         LEFT JOIN artists alar ON alar.id = a.artist_id
         LEFT JOIN artists ar ON ar.id = s.artist_id
         LEFT JOIN favorites f ON f.song_id = s.id AND f.user_id = ' . $uid . '
         LEFT JOIN song_stats st ON st.song_id = s.id AND st.user_id = ' . $uid . '
         WHERE ps.playlist_id = ?
         ORDER BY ps.position, ps.id'
    );
    $songs->execute([$playlistId]);

    $items = [];
    foreach ($songs->fetchAll() as $row2) {
        $song            = api_song($row2, true);
        $song['entryId'] = (int) $row2['playlist_song_id'];
        $song['position'] = (int) $row2['position'];
        $items[] = $song;
    }

    $meta = db()->prepare(
        'SELECT p.id, p.name, p.description, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) AS song_count,
                (SELECT COALESCE(SUM(s.duration_ms), 0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = p.id) AS duration_ms,
                (SELECT s.cover_key FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id
                  WHERE ps.playlist_id = p.id AND s.cover_key IS NOT NULL ORDER BY ps.position LIMIT 1) AS cover_key
         FROM playlists p WHERE p.id = ?'
    );
    $meta->execute([$playlistId]);

    json_response([
        'ok'       => true,
        'playlist' => api_playlist($meta->fetch()),
        'songs'    => $items,
    ]);
}

function api_playlist_update(int $playlistId): void
{
    api_playlist_owned($playlistId);
    $input = json_input();

    $sets   = [];
    $params = [];

    if (array_key_exists('name', $input)) {
        $name = mb_substr(trim((string) $input['name']), 0, 190);
        if ($name === '') {
            throw new ApiException('Nama playlist wajib diisi.', 422);
        }
        $sets[]   = 'name = ?';
        $params[] = $name;
    }

    if (array_key_exists('description', $input)) {
        $sets[]   = 'description = ?';
        $params[] = mb_substr(trim((string) $input['description']), 0, 1000);
    }

    if ($sets === []) {
        throw new ApiException('Tidak ada perubahan.', 422);
    }

    $sets[] = 'updated_at = NOW()';
    $params[] = $playlistId;

    db()->prepare('UPDATE playlists SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($params);

    json_response(['ok' => true]);
}

function api_playlist_delete(int $playlistId): void
{
    api_playlist_owned($playlistId);
    db()->prepare('DELETE FROM playlists WHERE id = ?')->execute([$playlistId]);
    json_response(['ok' => true]);
}

function api_playlist_add_song(int $playlistId): void
{
    api_playlist_owned($playlistId);
    $input   = json_input();
    $songId  = (int) ($input['songId'] ?? 0);

    $check = db()->prepare('SELECT id FROM songs WHERE id = ? LIMIT 1');
    $check->execute([$songId]);
    if (!$check->fetch()) {
        throw new ApiException('Lagu tidak ditemukan.', 404);
    }

    $pos = db()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM playlist_songs WHERE playlist_id = ?');
    $pos->execute([$playlistId]);
    $position = (int) $pos->fetchColumn();

    db()->prepare('INSERT INTO playlist_songs (playlist_id, song_id, position, added_at) VALUES (?, ?, ?, NOW())')
        ->execute([$playlistId, $songId, $position]);

    db()->prepare('UPDATE playlists SET updated_at = NOW() WHERE id = ?')->execute([$playlistId]);

    json_response(['ok' => true, 'position' => $position], 201);
}

function api_playlist_remove_song(int $playlistId, int $entryId): void
{
    api_playlist_owned($playlistId);

    $stmt = db()->prepare('SELECT position FROM playlist_songs WHERE id = ? AND playlist_id = ? LIMIT 1');
    $stmt->execute([$entryId, $playlistId]);
    $position = $stmt->fetch();
    $position = $position !== false ? (int) $position['position'] : null;

    if ($position === null) {
        throw new ApiException('Lagu tidak ada di playlist.', 404);
    }

    $del = db()->prepare('DELETE FROM playlist_songs WHERE id = ? AND playlist_id = ?');
    $del->execute([$entryId, $playlistId]);

    // Compact positions so the ordering stays dense.
    db()->prepare(
        'UPDATE playlist_songs SET position = position - 1 WHERE playlist_id = ? AND position > ?'
    )->execute([$playlistId, $position]);

    db()->prepare('UPDATE playlists SET updated_at = NOW() WHERE id = ?')->execute([$playlistId]);

    json_response(['ok' => true]);
}

function api_playlist_reorder(int $playlistId): void
{
    api_playlist_owned($playlistId);
    $input = json_input();
    $ids   = $input['songIds'] ?? [];

    if (!is_array($ids) || $ids === [] || count($ids) > 2000) {
        throw new ApiException('Urutan tidak valid.', 422);
    }

    $ids = array_map('intval', $ids);

    $known = db()->prepare(
        'SELECT id FROM playlist_songs WHERE playlist_id = ?'
    );
    $known->execute([$playlistId]);
    $known = array_map('intval', array_column($known->fetchAll(), 'id'));

    sort($known);
    $sorted = $ids;
    sort($sorted);

    if ($sorted !== $known) {
        throw new ApiException('Urutan tidak cocok dengan isi playlist.', 422);
    }

    $stmt = db()->prepare('UPDATE playlist_songs SET position = ? WHERE id = ? AND playlist_id = ?');
    foreach ($ids as $index => $entryId) {
        $stmt->execute([$index, $entryId, $playlistId]);
    }

    db()->prepare('UPDATE playlists SET updated_at = NOW() WHERE id = ?')->execute([$playlistId]);

    json_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Scanner
 * ---------------------------------------------------------------------- */

function api_scan(): void
{
    require_admin();

    $status = scanner_status();
    if ($status !== null && $status['status'] === 'running') {
        $started = strtotime($status['started_at'] . ' UTC');
        if ($started !== false && (time() - $started) < 600) {
            throw new ApiException('Scan sedang berjalan.', 409);
        }
    }

    set_time_limit(600);
    ini_set('memory_limit', '512M');

    $stats = scanner_run();

    json_response([
        'ok'    => true,
        'stats' => $stats,
        'scan'  => scanner_status(),
    ]);
}

function api_scan_status(): void
{
    require_admin();
    json_response(['ok' => true, 'scan' => scanner_status()]);
}

/* -------------------------------------------------------------------------
 * Admin — user management (CRUD)
 * ---------------------------------------------------------------------- */

/** Number of accounts that still hold the admin flag. */
function admin_count(): int
{
    $n = db()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();

    return (int) $n;
}

function api_admin_users(): void
{
    require_admin();

    [$limit, $offset] = api_paging(200);

    $total = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $stmt = db()->query(
        'SELECT u.id, u.name, u.email, u.is_admin, u.created_at,
                (SELECT COUNT(*) FROM playlists p WHERE p.user_id = u.id) AS playlist_count
         FROM users u
         ORDER BY u.is_admin DESC, u.created_at ASC, u.id ASC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );

    $items = array_map(static function (array $row): array {
        $row['id']       = (int) $row['id'];
        $row['is_admin'] = (int) $row['is_admin'] === 1;

        return $row;
    }, $stmt->fetchAll());

    json_response(['ok' => true, 'items' => $items, 'total' => $total]);
}

/** Validate the shared create/update payload. Returns normalised fields. */
function admin_user_input(array $input, bool $isUpdate, ?int $userId = null): array
{
    $name    = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
    $email   = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass    = (string) ($input['password'] ?? '');
    $isAdmin = !empty($input['is_admin']);

    if ($name === '') {
        throw new ApiException('Nama wajib diisi.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Email tidak valid.', 422);
    }
    // On update an empty password means "keep the current one".
    if ($pass !== '' && strlen($pass) < 6) {
        throw new ApiException('Password minimal 6 karakter.', 422);
    }

    $sql    = 'SELECT id FROM users WHERE email = ? LIMIT 1';
    $params = [$email];
    if ($isUpdate && $userId !== null) {
        $sql    = 'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        throw new ApiException('Email sudah dipakai user lain.', 409);
    }

    return ['name' => $name, 'email' => $email, 'password' => $pass, 'is_admin' => $isAdmin];
}

function api_admin_user_create(): void
{
    require_admin();

    $in = admin_user_input(json_input(), false);

    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $in['name'],
        mb_substr($in['email'], 0, 190),
        password_hash($in['password'], PASSWORD_DEFAULT),
        $in['is_admin'] ? 1 : 0,
    ]);

    $id = (int) db()->lastInsertId();
    $row = db()->prepare('SELECT id, name, email, is_admin, created_at FROM users WHERE id = ?');
    $row->execute([$id]);
    $user = $row->fetch();
    $user['id']       = (int) $user['id'];
    $user['is_admin'] = (int) $user['is_admin'] === 1;

    json_response(['ok' => true, 'user' => $user], 201);
}

function api_admin_user_update(int $userId): void
{
    $me    = require_admin();
    $input = json_input();

    $stmt = db()->prepare('SELECT id, name, email, is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $before = $stmt->fetch();
    if (!$before) {
        throw new ApiException('User tidak ditemukan.', 404);
    }

    $in = admin_user_input($input, true, $userId);

    // Never let the last admin lock everyone out of the panel.
    if ((int) $before['is_admin'] === 1 && !$in['is_admin'] && admin_count() <= 1) {
        throw new ApiException('Tidak bisa mencabut admin terakhir. Jadikan admin lain dulu.', 409);
    }

    $fields = [];
    $params = [];

    $fields[] = 'name = ?';
    $params[] = $in['name'];

    if ($in['email'] !== '') {
        $fields[] = 'email = ?';
        $params[] = mb_substr($in['email'], 0, 190);
    }
    $fields[] = 'is_admin = ?';
    $params[] = $in['is_admin'] ? 1 : 0;

    if ($in['password'] !== '') {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($in['password'], PASSWORD_DEFAULT);
    }

    $params[] = $userId;
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true, 'user_id' => $userId, 'self' => $userId === (int) $me['id']]);
}

function api_admin_user_delete(int $userId): void
{
    $me = require_admin();

    if ($userId === (int) $me['id']) {
        throw new ApiException('Tidak bisa menghapus akun yang sedang masuk.', 409);
    }

    $stmt = db()->prepare('SELECT id, is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw new ApiException('User tidak ditemukan.', 404);
    }

    if ((int) $target['is_admin'] === 1 && admin_count() <= 1) {
        throw new ApiException('Tidak bisa menghapus admin terakhir.', 409);
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

    json_response(['ok' => true, 'user_id' => $userId]);
}

/* -------------------------------------------------------------------------
 * Dispatch
 * ---------------------------------------------------------------------- */

function api_dispatch(string $method, string $path): void
{
    try {
        $path = '/' . trim($path, '/');

        if ($method === 'GET' && $path === '/health') {
            api_health();
        }

        if ($method === 'POST' && $path === '/auth/login') {
            api_login();
        }

        if ($method === 'POST' && $path === '/auth/register') {
            api_register();
        }

        if ($method === 'POST' && $path === '/auth/logout') {
            api_logout();
        }

        $user = require_user();

        if ($method === 'GET' && $path === '/me') {
            json_response(['ok' => true, 'user' => $user]);
        }

        if ($method === 'GET' && $path === '/home') {
            api_home();
        }

        if ($method === 'GET' && $path === '/albums') {
            api_albums();
        }

        if (preg_match('#^/album/(\d+)$#', $path, $m) && $method === 'GET') {
            api_album_detail((int) $m[1]);
        }

        if ($method === 'GET' && $path === '/artists') {
            api_artists();
        }

        if (preg_match('#^/artist/(\d+)$#', $path, $m) && $method === 'GET') {
            api_artist_detail((int) $m[1]);
        }

        if ($method === 'GET' && $path === '/songs') {
            api_songs();
        }

        if ($method === 'GET' && $path === '/liked') {
            api_liked();
        }

        if (preg_match('#^/songs/(\d+)/like$#', $path, $m)) {
            if ($method === 'POST') {
                api_like_song((int) $m[1], true);
            }
            if ($method === 'DELETE') {
                api_like_song((int) $m[1], false);
            }
        }

        if (preg_match('#^/songs/(\d+)/played$#', $path, $m) && $method === 'POST') {
            api_played_song((int) $m[1]);
        }

        if ($method === 'GET' && $path === '/search') {
            api_search();
        }

        if ($method === 'GET' && $path === '/playlists') {
            api_playlists();
        }

        if ($method === 'POST' && $path === '/playlists') {
            api_playlist_create();
        }

        if (preg_match('#^/playlist/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                api_playlist_detail($id);
            }
            if ($method === 'PATCH') {
                api_playlist_update($id);
            }
            if ($method === 'DELETE') {
                api_playlist_delete($id);
            }
        }

        if (preg_match('#^/playlist/(\d+)/songs$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'POST') {
                api_playlist_add_song($id);
            }
        }

        if (preg_match('#^/playlist/(\d+)/songs/(\d+)$#', $path, $m) && $method === 'DELETE') {
            api_playlist_remove_song((int) $m[1], (int) $m[2]);
        }

        if (preg_match('#^/playlist/(\d+)/reorder$#', $path, $m) && $method === 'POST') {
            api_playlist_reorder((int) $m[1]);
        }

        if ($method === 'POST' && $path === '/scan') {
            api_scan();
        }

        if ($method === 'GET' && $path === '/scan/status') {
            api_scan_status();
        }

        /* Admin — user CRUD */
        if (preg_match('#^/admin/users$#', $path)) {
            if ($method === 'GET') {
                api_admin_users();
            }
            if ($method === 'POST') {
                api_admin_user_create();
            }
        }

        if (preg_match('#^/admin/users/(\d+)$#', $path, $m)) {
            $uid = (int) $m[1];
            if ($method === 'PATCH') {
                api_admin_user_update($uid);
            }
            if ($method === 'DELETE') {
                api_admin_user_delete($uid);
            }
        }

        throw new ApiException('Endpoint tidak ditemukan.', 404);
    } catch (ApiException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        error_log('diartify api: ' . $e->getMessage());
        $message = config('app.debug')
            ? $e->getMessage()
            : 'Terjadi kesalahan di server.';
        json_response(['ok' => false, 'error' => $message], 500);
    }
}
