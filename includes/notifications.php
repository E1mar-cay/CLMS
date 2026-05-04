<?php

declare(strict_types=1);

/**
 * Notifications data layer for the student notification bell.
 *
 * Announcements live in the `announcements` table (owned by
 * admin/announcements.php). Per-student read state lives in
 * `announcement_reads` — a simple join table so we can compute
 * "unread" with a LEFT JOIN and never lose announcement history
 * when a student is deleted (cascade) or an announcement is
 * removed (cascade).
 *
 * All functions are defensive: if the underlying tables are
 * missing or a query fails, they return empty values rather
 * than bubbling exceptions into the navbar render.
 */

if (!function_exists('clms_notifications_init')) {
    function clms_notifications_init(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS announcement_reads (
                    user_id INT NOT NULL,
                    announcement_id INT NOT NULL,
                    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, announcement_id),
                    INDEX idx_user_read (user_id, read_at),
                    CONSTRAINT fk_ar_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ar_announcement FOREIGN KEY (announcement_id)
                        REFERENCES announcements(id) ON DELETE CASCADE
                ) ENGINE=InnoDB'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS user_prompt_dismissals (
                    user_id INT NOT NULL,
                    prompt_key VARCHAR(64) NOT NULL,
                    dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, prompt_key),
                    INDEX idx_prompt_dismiss_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $initialized = true;
        } catch (Throwable $e) {
            /* Most likely cause: `announcements` table hasn't been created
               yet because no admin has opened admin/announcements.php. The
               notification bell will just be empty until then — not fatal. */
            error_log('announcement_reads init failed: ' . $e->getMessage());
        }
    }
}

const CLMS_MFA_NUDGE_PROMPT_KEY = 'mfa_optional_nudge';
const CLMS_MFA_NUDGE_ITEM_ID = -1;

if (!function_exists('clms_mfa_nudge_should_show')) {
    function clms_mfa_nudge_should_show(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            clms_notifications_init($pdo);
            require_once __DIR__ . '/mfa.php';
            clms_mfa_ensure_schema($pdo);
            if (!clms_mfa_globally_allowed($pdo)) {
                return false;
            }
            $dStmt = $pdo->prepare(
                'SELECT 1 FROM user_prompt_dismissals WHERE user_id = :uid AND prompt_key = :pk LIMIT 1'
            );
            $dStmt->execute(['uid' => $userId, 'pk' => CLMS_MFA_NUDGE_PROMPT_KEY]);
            if ($dStmt->fetch()) {
                return false;
            }
            $uStmt = $pdo->prepare(
                'SELECT mfa_enabled, mfa_totp_secret FROM users WHERE id = :id LIMIT 1'
            );
            $uStmt->execute(['id' => $userId]);
            $row = $uStmt->fetch();
            if (!$row) {
                return false;
            }
            if (!empty($row['mfa_enabled']) && (int) $row['mfa_enabled'] === 1) {
                return false;
            }
            $secret = trim((string) ($row['mfa_totp_secret'] ?? ''));

            return $secret === '';
        } catch (Throwable $e) {
            error_log('clms_mfa_nudge_should_show: ' . $e->getMessage());

            return false;
        }
    }
}

if (!function_exists('clms_mfa_prompt_dismiss')) {
    function clms_mfa_prompt_dismiss(PDO $pdo, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            clms_notifications_init($pdo);
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO user_prompt_dismissals (user_id, prompt_key) VALUES (:uid, :pk)'
            );
            $stmt->execute(['uid' => $userId, 'pk' => CLMS_MFA_NUDGE_PROMPT_KEY]);
        } catch (Throwable $e) {
            error_log('clms_mfa_prompt_dismiss: ' . $e->getMessage());
        }
    }
}

if (!function_exists('clms_notifications_list')) {
    /**
     * @return list<array{id:int,title:string,body:string,created_at:string,is_read:bool}>
     */
    function clms_notifications_list(PDO $pdo, int $userId, int $limit = 10): array
    {
        if ($userId <= 0) {
            return [];
        }
        clms_notifications_init($pdo);

        $limit = max(1, min(50, $limit));

        try {
            $includeMfa = clms_mfa_nudge_should_show($pdo, $userId);
            $announceLimit = $includeMfa ? max(1, $limit - 1) : $limit;

            $stmt = $pdo->prepare(
                'SELECT
                    a.id,
                    a.title,
                    a.body,
                    a.created_at,
                    CASE WHEN ar.announcement_id IS NULL THEN 0 ELSE 1 END AS is_read
                 FROM announcements a
                 LEFT JOIN announcement_reads ar
                    ON ar.announcement_id = a.id AND ar.user_id = :uid
                 WHERE a.is_active = 1
                 ORDER BY a.created_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $announceLimit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            $out = array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'body' => (string) $row['body'],
                    'created_at' => (string) $row['created_at'],
                    'is_read' => (int) $row['is_read'] === 1,
                    'is_mfa_nudge' => false,
                ];
            }, $rows);

            if ($includeMfa) {
                array_unshift($out, [
                    'id' => CLMS_MFA_NUDGE_ITEM_ID,
                    'title' => 'Add extra sign-in security (optional)',
                    'body' => 'Turn on two-factor authentication so your password alone is not enough if someone else gets it. You can set it up in Account security (profile menu).',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'is_read' => false,
                    'is_mfa_nudge' => true,
                ]);
            }

            return $out;
        } catch (Throwable $e) {
            error_log('clms_notifications_list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('clms_notifications_unread_count')) {
    function clms_notifications_unread_count(PDO $pdo, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        clms_notifications_init($pdo);

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM announcements a
                 LEFT JOIN announcement_reads ar
                    ON ar.announcement_id = a.id AND ar.user_id = :uid
                 WHERE a.is_active = 1 AND ar.announcement_id IS NULL'
            );
            $stmt->execute(['uid' => $userId]);
            $n = (int) $stmt->fetchColumn();
            if (clms_mfa_nudge_should_show($pdo, $userId)) {
                $n++;
            }

            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('clms_notifications_mark_all_read')) {
    /** @return int rows inserted (0 if already caught up) */
    function clms_notifications_mark_all_read(PDO $pdo, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        clms_notifications_init($pdo);

        try {
            /* INSERT IGNORE on the composite PK skips already-read rows, so
               this is idempotent and safe to call every time the dropdown
               opens. */
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO announcement_reads (user_id, announcement_id)
                 SELECT :uid, a.id
                 FROM announcements a
                 WHERE a.is_active = 1'
            );
            $stmt->execute(['uid' => $userId]);
            $inserted = $stmt->rowCount();
            if (clms_mfa_nudge_should_show($pdo, $userId)) {
                clms_mfa_prompt_dismiss($pdo, $userId);
            }

            return $inserted;
        } catch (Throwable $e) {
            error_log('clms_notifications_mark_all_read failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('clms_notifications_mark_read')) {
    function clms_notifications_mark_read(PDO $pdo, int $userId, int $announcementId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if ($announcementId === CLMS_MFA_NUDGE_ITEM_ID) {
            clms_mfa_prompt_dismiss($pdo, $userId);

            return true;
        }
        if ($announcementId <= 0) {
            return false;
        }
        clms_notifications_init($pdo);

        try {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO announcement_reads (user_id, announcement_id)
                 VALUES (:uid, :aid)'
            );
            return $stmt->execute(['uid' => $userId, 'aid' => $announcementId]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('clms_notifications_format_time_ago')) {
    /**
     * Lightweight "2 minutes ago" helper so the bell reads naturally.
     * Falls back to formatted date for anything > 7 days.
     */
    function clms_notifications_format_time_ago(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $delta = time() - $timestamp;
        if ($delta < 60)            return 'Just now';
        if ($delta < 3600)          return (int) floor($delta / 60) . 'm ago';
        if ($delta < 86400)         return (int) floor($delta / 3600) . 'h ago';
        if ($delta < 86400 * 7)     return (int) floor($delta / 86400) . 'd ago';

        return date('M j, Y', $timestamp);
    }
}
