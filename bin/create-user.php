<?php
declare(strict_types=1);

/**
 * Create a user account (no self-registration on the web).
 *
 *   php bin/create-user.php "Nama" email@contoh.id [password]
 *
 * The first account created automatically becomes admin.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';

$name  = $argv[1] ?? null;
$email = $argv[2] ?? null;
$pass  = $argv[3] ?? null;

if ($name === null || $email === null) {
    fwrite(STDERR, "Pakai: php bin/create-user.php \"Nama\" email@contoh.id [password]\n");
    exit(1);
}

$email = mb_strtolower(trim($email));
$name  = trim($name);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email tidak valid.\n");
    exit(1);
}

if ($pass === null) {
    echo 'Password: ';
    $pass = rtrim((string) fgets(STDIN));
}

if (strlen($pass) < 6) {
    fwrite(STDERR, "Password minimal 6 karakter.\n");
    exit(1);
}

$exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$exists->execute([$email]);
if ($exists->fetch()) {
    fwrite(STDERR, "Email sudah terdaftar.\n");
    exit(1);
}

$hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
$admin    = $hasUsers ? 0 : 1;

$stmt = db()->prepare('INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())');
$stmt->execute([
    mb_substr($name, 0, 80),
    mb_substr($email, 0, 190),
    password_hash($pass, PASSWORD_DEFAULT),
    $admin,
]);

$id = (int) db()->lastInsertId();

printf(
    "User dibuat: #%d %s <%s>%s\n",
    $id,
    $name,
    $email,
    $admin ? '  (admin)' : ''
);
