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
            $initialized = true;
        } catch (Throwable $e) {
            /* Most likely cause: `announcements` table hasn't been created
               yet because no admin has opened admin/announcements.php. The
               notification bell will just be empty until then — not fatal. */
            error_log('announcement_reads init failed: ' . $e->getMessage());
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
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            return array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'body' => (string) $row['body'],
                    'created_at' => (string) $row['created_at'],
                    'is_read' => (int) $row['is_read'] === 1,
                ];
            }, $rows);
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
            return (int) $stmt->fetchColumn();
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
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('clms_notifications_mark_all_read failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('clms_notifications_mark_read')) {
    function clms_notifications_mark_read(PDO $pdo, int $userId, int $announcementId): bool
    {
        if ($userId <= 0 || $announcementId <= 0) {
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
