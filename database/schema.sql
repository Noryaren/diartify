-- Diarify database schema
-- Works on MariaDB 10.6+ and MySQL 8.0+
-- Usage: mariadb -u diartify -p diartify < database/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)     NOT NULL,
    email         VARCHAR(190)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    is_admin      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artists (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(255)    NOT NULL,
    sort_name  VARCHAR(255)    NOT NULL DEFAULT '',
    cover_key  VARCHAR(64)     NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_artists_name (name(191)),
    KEY idx_artists_sort (sort_name(191))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS albums (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id  BIGINT UNSIGNED NOT NULL,
    title      VARCHAR(255)    NOT NULL,
    year       SMALLINT UNSIGNED NULL,
    genre      VARCHAR(128)    NULL,
    cover_key  VARCHAR(64)     NULL,
    added_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_albums_artist_title (artist_id, title(191)),
    KEY idx_albums_added (added_at),
    CONSTRAINT fk_albums_artist FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS songs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    album_id     BIGINT UNSIGNED NULL,
    artist_id    BIGINT UNSIGNED NULL,
    title        VARCHAR(255)    NOT NULL,
    track_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    disc_no      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    duration_ms  INT UNSIGNED    NOT NULL DEFAULT 0,
    year         SMALLINT UNSIGNED NULL,
    genre        VARCHAR(128)    NULL,
    format       VARCHAR(8)      NOT NULL DEFAULT 'mp3',
    bitrate      INT UNSIGNED    NULL,
    sample_rate  INT UNSIGNED    NULL,
    channels     TINYINT UNSIGNED NULL,
    cover_key    VARCHAR(64)     NULL,
    lyrics       MEDIUMTEXT      NULL,
    file_path    VARCHAR(768)    NOT NULL,
    file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_mtime   INT UNSIGNED    NOT NULL DEFAULT 0,
    added_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_songs_path (file_path(191)),
    KEY idx_songs_album (album_id, disc_no, track_no, id),
    KEY idx_songs_artist (artist_id),
    KEY idx_songs_title (title(191)),
    KEY idx_songs_added (added_at),
    KEY idx_songs_duration (duration_ms),
    CONSTRAINT fk_songs_album FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE SET NULL,
    CONSTRAINT fk_songs_artist FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlists (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(190)    NOT NULL,
    description VARCHAR(1000)   NOT NULL DEFAULT '',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_playlists_user (user_id, updated_at),
    CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_songs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id BIGINT UNSIGNED NOT NULL,
    song_id     BIGINT UNSIGNED NOT NULL,
    position    INT UNSIGNED    NOT NULL DEFAULT 0,
    added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_playlist_songs (playlist_id, position, id),
    KEY idx_playlist_songs_song (song_id),
    CONSTRAINT fk_playlist_songs_playlist FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE,
    CONSTRAINT fk_playlist_songs_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
    user_id    BIGINT UNSIGNED NOT NULL,
    song_id    BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, song_id),
    KEY idx_favorites_song (song_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS song_stats (
    user_id        BIGINT UNSIGNED NOT NULL,
    song_id        BIGINT UNSIGNED NOT NULL,
    play_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    last_played_at DATETIME        NULL,
    PRIMARY KEY (user_id, song_id),
    KEY idx_stats_recent (user_id, last_played_at),
    KEY idx_stats_song (song_id),
    CONSTRAINT fk_stats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_stats_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_runs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    status         ENUM ('running', 'ok', 'error') NOT NULL DEFAULT 'running',
    files_seen     INT UNSIGNED    NOT NULL DEFAULT 0,
    songs_added    INT UNSIGNED    NOT NULL DEFAULT 0,
    songs_updated  INT UNSIGNED    NOT NULL DEFAULT 0,
    songs_removed  INT UNSIGNED    NOT NULL DEFAULT 0,
    message        VARCHAR(1000)   NOT NULL DEFAULT '',
    started_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at    DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_scan_started (started_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
