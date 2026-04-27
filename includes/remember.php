<?php

declare(strict_types=1);

/**
 * Persistent "Keep me signed in" support.
 *
 * Uses the selector + validator token pattern:
 *   - cookie value:  "<selector>:<validator>"
 *   - DB stores:     selector (indexed lookup) + sha256(validator)
 *
 * On auto-login the token is rotated; on validator mismatch we assume theft
 * and revoke every remember token for that user.
 */

require_once __DIR__ . '/auth.php';

const CLMS_REMEMBER_COOKIE = 'clms_remember';
const CLMS_REMEMBER_LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 days

/**
 * Resolve the shared PDO connection. Gated pages already load database.php
 * before invoking any auth helper, but we lazy-load it as a safety net for
 * callers (like login.php) that reach us from less predictable code paths.
 */
function clms_remember_get_pdo(): ?PDO
{
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    $databaseFile = dirname(__DIR__) . '/database.php';
    if (is_file($databaseFile)) {
        require_once $databaseFile;
    }

    return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
}

function clms_remember_init_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            validator_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remember_user (user_id),
            INDEX idx_remember_expires (expires_at),
            CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
 */
function clms_remember_cookie_params(): array
{
    return [
        'expires' => time() + CLMS_REMEMBER_LIFETIME_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * @return array{selector:string,validator:string}|null
 */
function clms_remember_parse_cookie(): ?array
{
    $raw = $_COOKIE[CLMS_REMEMBER_COOKIE] ?? null;
    if (!is_string($raw) || strpos($raw, ':') === false) {
        return null;
    }

    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector)) {
        return null;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }

    return ['selector' => $selector, 'validator' => $validator];
}

/**
 * Mint a fresh token, persist it, and write the cookie. Safe to call any
 * time before output is flushed.
 */
function clms_remember_issue_token(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $pdo = clms_remember_get_pdo();
    if ($pdo === null) {
        return;
    }

    try {
        clms_remember_init_schema($pdo);

        $selector = bin2hex(random_bytes(12));   // 24 hex chars
        $validator = bin2hex(random_bytes(32));  // 64 hex chars
        $validatorHash = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + CLMS_REMEMBER_LIFETIME_SECONDS);

        $stmt = $pdo->prepare(
            'INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at)
             VALUES (:uid, :sel, :hash, :exp)'
        );
        $stmt->execute([
            'uid' => $userId,
            'sel' => $selector,
            'hash' => $validatorHash,
            'exp' => $expiresAt,
        ]);

        setcookie(
            CLMS_REMEMBER_COOKIE,
            $selector . ':' . $validator,
            clms_remember_cookie_params()
        );
        // Keep $_COOKIE in sync so same-request calls see the new value.
        $_COOKIE[CLMS_REMEMBER_COOKIE] = $selector . ':' . $validator;
    } catch (Throwable $e) {
        error_log('clms_remember_issue_token: ' . $e->getMessage());
    }
}

function clms_remember_clear_cookie(): void
{
    $params = clms_remember_cookie_params();
    $params['expires'] = time() - 42000;
    setcookie(CLMS_REMEMBER_COOKIE, '', $params);
    unset($_COOKIE[CLMS_REMEMBER_COOKIE]);
}

/**
 * If the current request has a valid remember cookie but no active session,
 * restore the session and rotate the token. No-ops otherwise.
 *
 * Must be called before any output is flushed.
 */
function clms_remember_try_autologin(): void
{
    static $tried = false;
    if ($tried) {
        return;
    }
    $tried = true;

    clms_session_start();
    if (clms_logged_in()) {
        return;
    }

    $parsed = clms_remember_parse_cookie();
    if ($parsed === null) {
        return;
    }

    $pdo = clms_remember_get_pdo();
    if ($pdo === null) {
        return;
    }

    try {
        clms_remember_init_schema($pdo);

        $stmt = $pdo->prepare(
            'SELECT t.id, t.user_id, t.validator_hash, t.expires_at,
                    u.email, u.role, u.first_name
             FROM auth_remember_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.selector = :sel
             LIMIT 1'
        );
        $stmt->execute(['sel' => $parsed['selector']]);
        $row = $stmt->fetch();

        if (!$row) {
            // Unknown selector: cookie is stale or forged.
            clms_remember_clear_cookie();
            return;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $del = $pdo->prepare('DELETE FROM auth_remember_tokens WHERE id = :id');
            $del->execute(['id' => (int) $row['id']]);
            clms_remember_clear_cookie();
            return;
        }

        $candidateHash = hash('sha256', $parsed['validator']);
        if (!hash_equals((string) $row['validator_hash'], $candidateHash)) {
            // Selector matched but validator didn't: treat as theft and
            // revoke every remember token for this user so the real owner
            // must log in again.
            $purge = $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = :uid');
            $purge->execute(['uid' => (int) $row['user_id']]);
            clms_remember_clear_cookie();
            return;
        }

        $role = (string) ($row['role'] ?? '');
        if (!in_array($role, ['student', 'instructor', 'admin'], true)) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['role'] = $role;
        $_SESSION['email'] = (string) ($row['email'] ?? '');
        $_SESSION['first_name'] = (string) ($row['first_name'] ?? '');

        // Rotate: every successful auto-login gets a fresh token. Limits the
        // window a leaked cookie stays usable and lets us detect replays.
        $delOld = $pdo->prepare('DELETE FROM auth_remember_tokens WHERE id = :id');
        $delOld->execute(['id' => (int) $row['id']]);
        clms_remember_issue_token((int) $row['user_id']);

        // Opportunistic cleanup of anything already past its expiry.
        $pdo->exec('DELETE FROM auth_remember_tokens WHERE expires_at < NOW()');
    } catch (Throwable $e) {
        error_log('clms_remember_try_autologin: ' . $e->getMessage());
    }
}

/**
 * Revoke the token tied to the current cookie (if any) and clear the cookie.
 * Called from logout; other devices keep their own tokens.
 */
function clms_remember_revoke_current(): void
{
    $parsed = clms_remember_parse_cookie();
    clms_remember_clear_cookie();

    if ($parsed === null) {
        return;
    }

    $pdo = clms_remember_get_pdo();
    if ($pdo === null) {
        return;
    }

    try {
        clms_remember_init_schema($pdo);
        $stmt = $pdo->prepare('DELETE FROM auth_remember_tokens WHERE selector = :sel');
        $stmt->execute(['sel' => $parsed['selector']]);
    } catch (Throwable $e) {
        error_log('clms_remember_revoke_current: ' . $e->getMessage());
    }
}
