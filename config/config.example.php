<?php
/**
 * Copy this file to config/config.php and adjust the values.
 * Never commit config/config.php to a public repository.
 */
return [
    'app' => [
        'name'      => 'Diarify',
        'debug'     => false,
        'timezone'  => 'UTC',
        // Leave null to auto-detect from the front controller path.
        'base_path' => null,
        // Set to true when the app sits behind a reverse proxy / Cloudflare Tunnel.
        'trust_proxy' => true,
        // Login is stateless (JWT in the browser's localStorage): a token lives
        // for this many seconds — one year by default.
        'token_ttl' => 31536000,
        // Optional: pin the signing secret here. Left unset, one is generated
        // and kept in storage/jwt.secret.
        // 'jwt_secret' => '',
    ],

    'db' => [
        // Server: MariaDB container `mariadb-main` published on port 3307.
        'dsn'  => 'mysql:host=127.0.0.1;port=3307;dbname=diartify;charset=utf8mb4',
        'user' => 'diartify',
        'pass' => 'change-me',
    ],

    // Folders scanned for music. Relative paths are resolved from ROOT_PATH.
    'music_paths' => [
        '/opt/navidrome/music',
    ],

    'scanner' => [
        // Audio extensions recognised by the scanner.
        'extensions' => ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'mp4', 'aac', 'wav', 'wma'],
        // Embedded/folder cover art is scaled down to this many pixels (long edge).
        'cover_max'  => 600,
        // Folder image names picked up when a file has no embedded art.
        'folder_covers' => ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'front.jpg', 'front.png', 'album.jpg', 'albumart.jpg', 'albumartlarger.jpg'],
    ],
];
