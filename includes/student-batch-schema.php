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
