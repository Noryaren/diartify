<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

date_default_timezone_set((string) config('app.timezone', 'UTC'));

ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$sessionDays = max(1, (int) config('app.session_days', 90));
$sessionLife = $sessionDays * 86400;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $sessionLife);

    session_name('diartify_session');
    session_set_cookie_params([
        'lifetime' => $sessionLife,
        'path'     => '/',
        'domain'   => '',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Keep the session cookie sliding while you are actively using the app, so a
// long listening marathon never ends in a silent log-out.
if (!empty($_SESSION['user_id']) && !headers_sent()) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $sessionLife,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

if (!headers_sent()) {
    $nonce = csp_nonce();

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), autoplay=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'nonce-{$nonce}'; "
        . "connect-src 'self'; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );
}

final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
