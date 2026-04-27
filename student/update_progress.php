<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    clms_require_roles(['student']);

    try {
        $videoColumnCheck = $pdo->query("SHOW COLUMNS FROM user_progress LIKE 'video_completed'")->fetch();
        if (!$videoColumnCheck) {
            $pdo->exec('ALTER TABLE user_progress ADD COLUMN video_completed TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        error_log('user_progress.video_completed migration failed: ' . $e->getMessage());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $moduleId = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
    $lastWatchedSecond = filter_input(INPUT_POST, 'last_watched_second', FILTER_VALIDATE_INT);
    $isCompletedRaw = strtolower(trim((string) ($_POST['is_completed'] ?? '0')));

    $completionMap = [
        '1' => 1,
        'true' => 1,
        'yes' => 1,
        '0' => 0,
        'false' => 0,
        'no' => 0,
    ];

    if ($moduleId === false || $moduleId === null || $moduleId <= 0) {
        throw new RuntimeException('Invalid module_id.');
    }
    if ($lastWatchedSecond === false || $lastWatchedSecond === null || $lastWatchedSecond < 0) {
        throw new RuntimeException('Invalid last_watched_second.');
    }
    if (!array_key_exists($isCompletedRaw, $completionMap)) {
        throw new RuntimeException('Invalid is_completed flag.');
    }
    $isCompleted = $completionMap[$isCompletedRaw];

    $moduleCheckStmt = $pdo->prepare('SELECT id FROM modules WHERE id = :module_id LIMIT 1');
    $moduleCheckStmt->execute(['module_id' => $moduleId]);
    if (!$moduleCheckStmt->fetch()) {
        throw new RuntimeException('Module not found.');
    }

    $quizCountStmt = $pdo->prepare('SELECT COUNT(*) AS question_count FROM questions WHERE module_id = :module_id');
    $quizCountStmt->execute(['module_id' => $moduleId]);
    $moduleHasQuiz = (int) ($quizCountStmt->fetch()['question_count'] ?? 0) > 0;

    $videoCompletedValue = $isCompleted === 1 ? 1 : 0;
    $moduleIsCompleted = ($isCompleted === 1 && !$moduleHasQuiz) ? 1 : 0;
    $completedAtValue = $moduleIsCompleted === 1 ? date('Y-m-d H:i:s') : null;

    $progressStmt = $pdo->prepare(
        'INSERT INTO user_progress (user_id, module_id, is_completed, video_completed, last_watched_second, completed_at)
         VALUES (:user_id, :module_id, :is_completed, :video_completed, :last_watched_second, :completed_at)
         ON DUPLICATE KEY UPDATE
           last_watched_second = GREATEST(last_watched_second, VALUES(last_watched_second)),
           is_completed = GREATEST(is_completed, VALUES(is_completed)),
           video_completed = GREATEST(video_completed, VALUES(video_completed)),
           completed_at = COALESCE(completed_at, VALUES(completed_at))'
    );
    $progressStmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'module_id' => (int) $moduleId,
        'is_completed' => $moduleIsCompleted,
        'video_completed' => $videoCompletedValue,
        'last_watched_second' => (int) $lastWatchedSecond,
        'completed_at' => $completedAtValue,
    ]);

    echo json_encode([
        'success' => true,
        'last_watched_second' => (int) $lastWatchedSecond,
        'is_completed' => $moduleIsCompleted,
        'video_completed' => $videoCompletedValue,
        'module_has_quiz' => $moduleHasQuiz,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    if (!($e instanceof RuntimeException)) {
        error_log($e->getMessage());
    }
    echo json_encode([
        'success' => false,
        'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update progress.',
    ]);
}
