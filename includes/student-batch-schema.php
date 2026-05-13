<?php

declare(strict_types=1);

/**
 * Optional cohort label for filtering Student Activity (admin + instructor).
 */
function clms_ensure_users_student_batch_column(PDO $pdo): void
{
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'student_batch'")->fetch();
        if (!$check) {
            $pdo->exec('ALTER TABLE users ADD COLUMN student_batch VARCHAR(80) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        error_log('users.student_batch migration failed: ' . $e->getMessage());
    }
}

/**
 * Soft-archive columns for student accounts.
 *
 * Archiving a batch / cohort moves every student in it into an
 * "archived" state — they're hidden from the default students list
 * but the rows (and all their progress, attempts, certificates) are
 * preserved on disk. An admin can restore archived students later.
 *
 * `account_is_archived`   — 0 (visible by default) or 1 (hidden).
 * `account_archived_at`   — timestamp of the most recent archive.
 *
 * Idempotent — safe to call on every request, same as the existing
 * runtime migrations in this codebase.
 */
function clms_ensure_users_archive_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach ([
        'account_is_archived' => 'TINYINT NOT NULL DEFAULT 0',
        'account_archived_at' => 'DATETIME NULL DEFAULT NULL',
    ] as $columnName => $columnSpec) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM users LIKE '" . $columnName . "'")->fetch();
            if (!$check) {
                $pdo->exec('ALTER TABLE users ADD COLUMN ' . $columnName . ' ' . $columnSpec);
            }
        } catch (Throwable $e) {
            error_log('users.' . $columnName . ' migration failed: ' . $e->getMessage());
        }
    }
}
