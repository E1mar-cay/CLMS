<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

header('Content-Type: application/json; charset=UTF-8');

try {
    $totalStudentsStmt = $pdo->query(
        "SELECT COUNT(*) AS total_students
         FROM users
         WHERE role = 'student'"
    );
    $totalStudents = (int) ($totalStudentsStmt->fetch()['total_students'] ?? 0);

    $avgPassStmt = $pdo->query(
        "SELECT COALESCE(AVG(CASE WHEN is_passed = 1 THEN 100 ELSE 0 END), 0) AS average_pass_rate
         FROM exam_attempts
         WHERE status = 'completed'"
    );
    $averagePassRate = (float) ($avgPassStmt->fetch()['average_pass_rate'] ?? 0.0);

    $moduleStmt = $pdo->query(
        "SELECT
            m.id,
            m.title,
            COALESCE(SUM(CASE WHEN up.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_count
         FROM modules m
         LEFT JOIN user_progress up ON up.module_id = m.id
         GROUP BY m.id, m.title
         ORDER BY m.sequence_order ASC, m.id ASC"
    );
    $moduleRows = $moduleStmt->fetchAll();

    $moduleCompletionRates = [];
    foreach ($moduleRows as $row) {
        $completedCount = (int) $row['completed_count'];
        $rate = $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100, 2) : 0.0;
        $moduleCompletionRates[] = [
            'module_id' => (int) $row['id'],
            'module_title' => (string) $row['title'],
            'completion_rate' => $rate,
        ];
    }

    echo json_encode([
        'success' => true,
        'average_pass_rate' => round($averagePassRate, 2),
        'total_active_students' => $totalStudents,
        'module_completion_rates' => $moduleCompletionRates,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load dashboard metrics.',
    ]);
}
