<?php

declare(strict_types=1);

/**
 * Append-only audit trail for security-sensitive actions.
 * Failures are swallowed so primary flows are never blocked.
 */

function clms_audit_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                action VARCHAR(80) NOT NULL,
                target_type VARCHAR(60) NULL,
                target_id BIGINT NULL,
                actor_user_id INT UNSIGNED NULL,
                actor_role VARCHAR(20) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(512) NULL,
                details_json TEXT NULL,
                INDEX idx_audit_created (created_at),
                INDEX idx_audit_action (action),
                INDEX idx_audit_actor (actor_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('clms_audit_ensure_schema: ' . $e->getMessage());
    }
}

/**
 * @param array<string,mixed> $details
 */
function clms_audit_log(
    PDO $pdo,
    string $action,
    ?string $targetType,
    ?int $targetId,
    array $details = [],
    ?int $actorUserId = null
): void {
    try {
        clms_audit_ensure_schema($pdo);

        if ($actorUserId === null && isset($_SESSION['user_id'])) {
            $actorUserId = (int) $_SESSION['user_id'];
            if ($actorUserId <= 0) {
                $actorUserId = null;
            }
        }

        $actorRole = null;
        if ($actorUserId !== null && isset($_SESSION['role']) && is_string($_SESSION['role'])) {
            $actorRole = $_SESSION['role'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || $ip === '') {
            $ip = null;
        }
        if ($ip !== null && strlen($ip) > 45) {
            $ip = substr($ip, 0, 45);
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua) || $ua === '') {
            $ua = null;
        } else {
            $ua = mb_substr($ua, 0, 512);
        }

        $json = $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== null && strlen($json) > 65000) {
            $json = mb_substr($json, 0, 65000) . '…';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (action, target_type, target_id, actor_user_id, actor_role, ip_address, user_agent, details_json)
             VALUES
                (:action, :target_type, :target_id, :actor_user_id, :actor_role, :ip_address, :user_agent, :details_json)'
        );
        $stmt->execute([
            'action' => mb_substr($action, 0, 80),
            'target_type' => $targetType === null ? null : mb_substr($targetType, 0, 60),
            'target_id' => $targetId,
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole === null ? null : mb_substr($actorRole, 0, 20),
            'ip_address' => $ip,
            'user_agent' => $ua,
            'details_json' => $json,
        ]);
    } catch (Throwable $e) {
        error_log('clms_audit_log: ' . $e->getMessage());
    }
}
