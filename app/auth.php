<?php
declare(strict_types=1);

/**
 * Login stays intentionally boring: e-mail + password, nothing else.
 * Sessions are long-lived and slide forward on every visit, so listening for
 * days (or weeks) never logs you out.
 */

function current_user(): ?array
{
    if (array_key_exists('diartify_current_user', $GLOBALS)) {
        return $GLOBALS['diartify_current_user'];
    }

    $user = null;
    $id   = $_SESSION['user_id'] ?? null;

    if ($id !== null) {
        $stmt = db()->prepare('SELECT id, name, email, is_admin, created_at FROM users WHERE id = ?');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();

        if ($row) {
            $row['id']       = (int) $row['id'];
            $row['is_admin'] = (int) $row['is_admin'] === 1;
            $user            = $row;
        } else {
            unset($_SESSION['user_id']);
        }
    }

    $GLOBALS['diartify_current_user'] = $user;

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        throw new ApiException('Silakan masuk terlebih dahulu.', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if (empty($user['is_admin'])) {
        throw new ApiException('Hanya admin yang boleh melakukan ini.', 403);
    }
    return $user;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']              = $userId;
    $_SESSION['login_failed']         = 0;
    $_SESSION['login_failed_until']   = 0;

    unset($_SESSION['csrf']);
    unset($GLOBALS['diartify_current_user']);
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    unset($GLOBALS['diartify_current_user']);
}

/**
 * Silently slow down brute force attempts. Never bothers a real user.
 */
function login_throttled(): bool
{
    $until = (int) ($_SESSION['login_failed_until'] ?? 0);
    return $until > time();
}

function login_throttle_fail(): void
{
    $count  = (int) ($_SESSION['login_failed'] ?? 0) + 1;
    $_SESSION['login_failed'] = $count;

    if ($count >= 5) {
        $_SESSION['login_failed_until'] = time() + 300;
        $_SESSION['login_failed']       = 0;
    }
}

function attempt_login(string $email, string $password): bool
{
    if ($email === '' || $password === '' || login_throttled()) {
        return false;
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        login_throttle_fail();
        return false;
    }

    // Re-hash transparently if the algorithm cost changed since signup.
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
    }

    login_user((int) $row['id']);
    return true;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        throw new ApiException('Sesi kamu sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.', 419);
    }
}
