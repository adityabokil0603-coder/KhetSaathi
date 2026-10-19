<?php
declare(strict_types=1);

/** Start the same HttpOnly, SameSite session used by the application pages. */
function startKhetSaathiSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || getenv('KHETSAATHI_COOKIE_SECURE') === '1'),
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);
    }

    $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

/** Add safe browser security headers compatible with the local app assets. */
function sendAppSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
}

/** Return the shared CSRF token for state-changing forms. */
function appCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        startKhetSaathiSession();
    }

    return $_SESSION['csrf_token'];
}

/** Check a submitted token without accepting arrays or other unexpected types. */
function isValidAppCsrfToken(mixed $token): bool
{
    return is_string($token)
        && is_string($_SESSION['csrf_token'] ?? null)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Read and remove the one-time post/redirect/get message for the current page. */
function consumeActionFlash(): ?array
{
    $flash = $_SESSION['action_flash'] ?? null;
    unset($_SESSION['action_flash']);

    if (!is_array($flash)
        || !in_array($flash['type'] ?? null, ['success', 'error'], true)
        || !is_string($flash['message'] ?? null)) {
        return null;
    }

    return $flash;
}
