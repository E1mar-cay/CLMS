<?php

declare(strict_types=1);

/**
 * Ensures exam_types table and exam_type_id columns exist (idempotent).
 */
function clms_ensure_exam_types_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS exam_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_exam_types_active_sort (is_active, sort_order, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('clms_ensure_exam_types_schema: exam_types table — ' . $e->getMessage());
    }

    try {
        $qCol = $pdo->query("SHOW COLUMNS FROM questions LIKE 'exam_type_id'")->fetch();
        if (!$qCol) {
            $pdo->exec('ALTER TABLE questions ADD COLUMN exam_type_id INT NULL AFTER module_id');
            $pdo->exec('ALTER TABLE questions ADD INDEX idx_questions_exam_type (exam_type_id)');
        }
    } catch (Throwable $e) {
        error_log('clms_ensure_exam_types_schema: questions.exam_type_id — ' . $e->getMessage());
    }

    try {
        $aCol = $pdo->query("SHOW COLUMNS FROM exam_attempts LIKE 'exam_type_id'")->fetch();
        if (!$aCol) {
            $pdo->exec('ALTER TABLE exam_attempts ADD COLUMN exam_type_id INT NULL AFTER course_id');
            $pdo->exec('ALTER TABLE exam_attempts ADD INDEX idx_exam_attempts_type (exam_type_id)');
        }
    } catch (Throwable $e) {
        error_log('clms_ensure_exam_types_schema: exam_attempts.exam_type_id — ' . $e->getMessage());
    }
}
