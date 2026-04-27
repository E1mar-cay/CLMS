<?php

declare(strict_types=1);

require_once __DIR__ . '/sneat-paths.php';

function clms_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function clms_csrf_token(): string
{
    clms_session_start();
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function clms_csrf_validate(?string $token): bool
{
    clms_session_start();
    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $token);
}

function clms_redirect(string $relativePath): void
{
    global $clmsWebBase;
    $target = $clmsWebBase . '/' . ltrim($relativePath, '/');
    header('Location: ' . $target);
    exit;
}

function clms_logged_in(): bool
{
    clms_session_start();

    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

function clms_current_role(): ?string
{
    clms_session_start();
    $role = $_SESSION['role'] ?? null;

    return is_string($role) ? $role : null;
}

function clms_redirect_if_logged_in(): void
{
    if (!clms_logged_in()) {
        // Visitors with a valid "Keep me signed in" cookie get bounced
        // straight to their dashboard without having to re-enter creds.
        clms_try_remember_autologin();
        if (!clms_logged_in()) {
            return;
        }
    }

    $role = clms_current_role();
    if ($role === 'admin') {
        clms_redirect('admin/dashboard.php');
    }
    if ($role === 'instructor') {
        clms_redirect('instructor/dashboard.php');
    }
    if ($role === 'student') {
        clms_redirect('student/dashboard.php');
    }

    clms_redirect('login.php');
}

function clms_require_login(): void
{
    clms_session_start();
    if (!clms_logged_in()) {
        // Try to rebuild the session from the remember-me cookie before
        // deciding the user is truly unauthenticated.
        clms_try_remember_autologin();
        if (!clms_logged_in()) {
            clms_redirect('login.php');
        }
    }
}

/**
 * Thin wrapper so callers don't have to `require_once` remember.php themselves.
 * Safe to call even if remember.php has already been loaded.
 */
function clms_try_remember_autologin(): void
{
    require_once __DIR__ . '/remember.php';
    clms_remember_try_autologin();
}

/**
 * @param list<string> $allowedRoles
 */
function clms_require_roles(array $allowedRoles): void
{
    clms_require_login();
    $role = clms_current_role();
    if ($role === null || !in_array($role, $allowedRoles, true)) {
        if ($role === 'student') {
            clms_redirect('student/dashboard.php');
        }
        if ($role === 'instructor') {
            clms_redirect('instructor/dashboard.php');
        }
        if ($role === 'admin') {
            clms_redirect('admin/dashboard.php');
        }
        clms_redirect('login.php');
    }
}

function clms_logout(): void
{
    clms_session_start();

    // Kill the persistent cookie *before* we tear down the session so we
    // don't leave a dangling token behind that would silently log the user
    // straight back in on the next request.
    require_once __DIR__ . '/remember.php';
    clms_remember_revoke_current();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}
