<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['student']);

try {
    $moduleColumnCheck = $pdo->query("SHOW COLUMNS FROM questions LIKE 'module_id'")->fetch();
    if (!$moduleColumnCheck) {
        $pdo->exec('ALTER TABLE questions ADD COLUMN module_id INT NULL AFTER course_id');
        $pdo->exec('ALTER TABLE questions ADD INDEX idx_questions_module (module_id)');
    }
} catch (Throwable $e) {
    error_log('questions.module_id migration failed: ' . $e->getMessage());
}

try {
    $videoColumnCheck = $pdo->query("SHOW COLUMNS FROM user_progress LIKE 'video_completed'")->fetch();
    if (!$videoColumnCheck) {
        $pdo->exec('ALTER TABLE user_progress ADD COLUMN video_completed TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE user_progress SET video_completed = 1 WHERE is_completed = 1');
    }
} catch (Throwable $e) {
    error_log('user_progress.video_completed migration failed: ' . $e->getMessage());
}

try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS module_quiz_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            module_id INT NOT NULL,
            score DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_points DECIMAL(10,2) NOT NULL DEFAULT 0,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_module (user_id, module_id),
            CONSTRAINT fk_mqa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_mqa_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
} catch (Throwable $e) {
    error_log('module_quiz_attempts init failed: ' . $e->getMessage());
}

$moduleId = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT);
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);

if (($moduleId === false || $moduleId === null || $moduleId <= 0) && ($courseId === false || $courseId === null || $courseId <= 0)) {
    clms_redirect('student/dashboard.php');
}

if ($moduleId !== false && $moduleId !== null && $moduleId > 0) {
    // Explicit module request — respect it as-is.
    $moduleStmt = $pdo->prepare(
        'SELECT m.id, m.course_id, m.title, m.video_url, m.duration_minutes, m.sequence_order, c.title AS course_title
         FROM modules m
         INNER JOIN courses c ON c.id = m.course_id
         WHERE m.id = :module_id
         LIMIT 1'
    );
    $moduleStmt->execute(['module_id' => $moduleId]);
    $module = $moduleStmt->fetch();
} else {
    /*
     * Entering via ?course_id=X (e.g. the dashboard "Continue" / "Resume"
     * buttons). Route the student to the most useful next step rather
     * than always dropping them on module 1:
     *
     *   1. If every module is already complete AND the course has a
     *      final exam, jump straight to the final exam.
     *   2. Otherwise, resume at the first INCOMPLETE module (so a
     *      student who got through modules 1–3 lands on module 4, not
     *      module 1).
     *   3. If all modules are complete but no final exam exists, fall
     *      back to the first module so they can still review.
     */
    $courseIdInt = (int) $courseId;

    $progressListStmt = $pdo->prepare(
        'SELECT m.id, m.sequence_order,
                COALESCE(up.is_completed, 0) AS is_completed
         FROM modules m
         LEFT JOIN user_progress up
                ON up.module_id = m.id AND up.user_id = :user_id
         WHERE m.course_id = :course_id
         ORDER BY m.sequence_order ASC, m.id ASC'
    );
    $progressListStmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'course_id' => $courseIdInt,
    ]);
    $progressList = $progressListStmt->fetchAll();

    if ($progressList === []) {
        clms_redirect('student/dashboard.php');
    }

    $firstIncompleteId = null;
    foreach ($progressList as $pRow) {
        if ((int) $pRow['is_completed'] !== 1) {
            $firstIncompleteId = (int) $pRow['id'];
            break;
        }
    }

    if ($firstIncompleteId === null) {
        // All modules done — prefer the final exam when it exists.
        $finalExamExistsStmt = $pdo->prepare(
            'SELECT 1 FROM questions WHERE course_id = :course_id LIMIT 1'
        );
        $finalExamExistsStmt->execute(['course_id' => $courseIdInt]);
        if ($finalExamExistsStmt->fetchColumn()) {
            clms_redirect('student/take_exam.php?course_id=' . $courseIdInt);
        }
        // No exam to take: land on the first module as a review starting point.
        $targetModuleId = (int) $progressList[0]['id'];
    } else {
        $targetModuleId = $firstIncompleteId;
    }

    $moduleStmt = $pdo->prepare(
        'SELECT m.id, m.course_id, m.title, m.video_url, m.duration_minutes, m.sequence_order, c.title AS course_title
         FROM modules m
         INNER JOIN courses c ON c.id = m.course_id
         WHERE m.id = :module_id
         LIMIT 1'
    );
    $moduleStmt->execute(['module_id' => $targetModuleId]);
    $module = $moduleStmt->fetch();
}

if (!$module) {
    clms_redirect('student/dashboard.php');
}

$enrollmentCheckStmt = $pdo->prepare(
    'SELECT 1
     FROM courses c
     INNER JOIN modules m ON m.course_id = c.id
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id_progress
     LEFT JOIN exam_attempts ea ON ea.course_id = c.id AND ea.user_id = :user_id_attempt
     LEFT JOIN certificates cert ON cert.course_id = c.id AND cert.user_id = :user_id_certificate
     WHERE c.id = :course_id
       AND (up.id IS NOT NULL OR ea.id IS NOT NULL OR cert.id IS NOT NULL)
     LIMIT 1'
);
$enrollmentCheckStmt->execute([
    'user_id_progress' => (int) $_SESSION['user_id'],
    'user_id_attempt' => (int) $_SESSION['user_id'],
    'user_id_certificate' => (int) $_SESSION['user_id'],
    'course_id' => (int) $module['course_id'],
]);
if (!$enrollmentCheckStmt->fetch()) {
    clms_redirect('student/dashboard.php');
}

$progressStmt = $pdo->prepare(
    'SELECT last_watched_second, is_completed, video_completed
     FROM user_progress
     WHERE user_id = :user_id AND module_id = :module_id
     LIMIT 1'
);
$progressStmt->execute([
    'user_id' => (int) $_SESSION['user_id'],
    'module_id' => (int) $module['id'],
]);
$progress = $progressStmt->fetch();

$lastWatchedSecond = (int) ($progress['last_watched_second'] ?? 0);
$isCompleted = (bool) ($progress['is_completed'] ?? false);
$videoCompleted = (bool) ($progress['video_completed'] ?? false) || $isCompleted;

$courseModulesStmt = $pdo->prepare(
    'SELECT m.id, m.title, m.sequence_order,
            COALESCE(up.is_completed, 0) AS is_completed,
            (SELECT COUNT(*) FROM questions q WHERE q.module_id = m.id) AS question_count
     FROM modules m
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id
     WHERE m.course_id = :course_id
     ORDER BY m.sequence_order ASC, m.id ASC'
);
$courseModulesStmt->execute([
    'course_id' => (int) $module['course_id'],
    'user_id' => (int) $_SESSION['user_id'],
]);
$courseModuleList = $courseModulesStmt->fetchAll();
$prevModuleRow = null;
$nextModuleRow = null;
$currentIndex = -1;
$allModulesCompleted = $courseModuleList !== [];
foreach ($courseModuleList as $idx => $row) {
    if ((int) $row['id'] === (int) $module['id']) {
        $currentIndex = $idx;
    }
    if ((int) $row['is_completed'] !== 1) {
        $allModulesCompleted = false;
    }
}
if ($currentIndex > 0) {
    $prevModuleRow = $courseModuleList[$currentIndex - 1];
}
if ($currentIndex >= 0 && $currentIndex < count($courseModuleList) - 1) {
    $nextModuleRow = $courseModuleList[$currentIndex + 1];
}

$finalExamExistsStmt = $pdo->prepare(
    'SELECT 1 FROM questions WHERE course_id = :course_id LIMIT 1'
);
$finalExamExistsStmt->execute(['course_id' => (int) $module['course_id']]);
$hasFinalExam = (bool) $finalExamExistsStmt->fetchColumn();

$moduleQuizRows = [];
$moduleQuizStmt = $pdo->prepare(
    'SELECT q.id, q.question_text, q.question_type, q.points, a.id AS answer_id, a.answer_text
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.module_id = :module_id
     ORDER BY q.id ASC, a.id ASC'
);
$moduleQuizStmt->execute(['module_id' => (int) $module['id']]);
$moduleQuizRows = $moduleQuizStmt->fetchAll();

$moduleQuestions = [];
foreach ($moduleQuizRows as $row) {
    $qId = (int) $row['id'];
    if (!isset($moduleQuestions[$qId])) {
        $moduleQuestions[$qId] = [
            'id' => $qId,
            'question_text' => (string) $row['question_text'],
            'question_type' => (string) $row['question_type'],
            'points' => (float) $row['points'],
            'answers' => [],
        ];
    }
    if ($row['answer_id'] !== null) {
        $moduleQuestions[$qId]['answers'][] = [
            'id' => (int) $row['answer_id'],
            'answer_text' => (string) $row['answer_text'],
        ];
    }
}

$quizFeedback = null;
$submittedResponses = [];
$postAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

if ($postAction === 'mark_video_watched') {
    try {
        if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid request token. Please refresh and try again.');
        }
        $markVideoStmt = $pdo->prepare(
            'INSERT INTO user_progress (user_id, module_id, is_completed, video_completed, last_watched_second)
             VALUES (:user_id, :module_id, 0, 1, :last_watched_second)
             ON DUPLICATE KEY UPDATE
               video_completed = GREATEST(video_completed, VALUES(video_completed)),
               last_watched_second = GREATEST(last_watched_second, VALUES(last_watched_second))'
        );
        $markVideoStmt->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'module_id' => (int) $module['id'],
            'last_watched_second' => $lastWatchedSecond,
        ]);
        $videoCompleted = true;
    } catch (Throwable $e) {
        error_log('mark_video_watched failed: ' . $e->getMessage());
    }
}

if ($postAction === 'submit_module_quiz') {
    $rawSubmittedForRender = $_POST['responses'] ?? [];
    if (is_array($rawSubmittedForRender)) {
        $submittedResponses = $rawSubmittedForRender;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_module_quiz') {
    try {
        if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid request token. Please refresh and try again.');
        }
        if ($moduleQuestions === []) {
            throw new RuntimeException('This module has no quiz questions.');
        }

        $rawResponses = $_POST['responses'] ?? [];
        if (!is_array($rawResponses)) {
            $rawResponses = [];
        }

        $gradingStmt = $pdo->prepare(
            'SELECT q.id, q.question_type, q.points, a.id AS answer_id, a.answer_text, a.is_correct, a.sequence_position
             FROM questions q
             LEFT JOIN answers a ON a.question_id = q.id
             WHERE q.module_id = :module_id
             ORDER BY q.id ASC, a.id ASC'
        );
        $gradingStmt->execute(['module_id' => (int) $module['id']]);
        $gradingRows = $gradingStmt->fetchAll();

        $gradingQuestions = [];
        foreach ($gradingRows as $row) {
            $qId = (int) $row['id'];
            if (!isset($gradingQuestions[$qId])) {
                $gradingQuestions[$qId] = [
                    'id' => $qId,
                    'question_type' => (string) $row['question_type'],
                    'points' => (float) $row['points'],
                    'answers' => [],
                ];
            }
            if ($row['answer_id'] !== null) {
                $gradingQuestions[$qId]['answers'][] = [
                    'id' => (int) $row['answer_id'],
                    'answer_text' => (string) $row['answer_text'],
                    'is_correct' => (bool) $row['is_correct'],
                    'sequence_position' => $row['sequence_position'] === null ? null : (int) $row['sequence_position'],
                ];
            }
        }

        $totalPossible = 0.0;
        $totalAwarded = 0.0;
        $perQuestionFeedback = [];

        foreach ($gradingQuestions as $q) {
            $qId = (int) $q['id'];
            $type = $q['question_type'];
            $points = (float) $q['points'];
            $answers = $q['answers'];
            $submitted = $rawResponses[(string) $qId] ?? $rawResponses[$qId] ?? [];
            if (!is_array($submitted)) {
                $submitted = [];
            }

            if ($type !== 'essay') {
                $totalPossible += $points;
            }
            $awarded = 0.0;
            $isCorrectFlag = false;

            if ($type === 'true_false' || $type === 'single_choice') {
                $selected = filter_var($submitted['single'] ?? null, FILTER_VALIDATE_INT);
                $correctIds = array_map(
                    static fn (array $a): int => (int) $a['id'],
                    array_values(array_filter($answers, static fn (array $a): bool => (bool) $a['is_correct']))
                );
                if ($selected !== false && $selected !== null && in_array((int) $selected, $correctIds, true) && count($correctIds) === 1) {
                    $awarded = $points;
                    $isCorrectFlag = true;
                }
            } elseif ($type === 'multiple_select') {
                $multi = $submitted['multiple'] ?? [];
                if (!is_array($multi)) {
                    $multi = [];
                }
                $submittedIds = [];
                foreach ($multi as $v) {
                    $idVal = filter_var($v, FILTER_VALIDATE_INT);
                    if ($idVal !== false && $idVal !== null) {
                        $submittedIds[] = (int) $idVal;
                    }
                }
                $submittedIds = array_values(array_unique($submittedIds));
                sort($submittedIds);

                $correctIds = array_map(
                    static fn (array $a): int => (int) $a['id'],
                    array_values(array_filter($answers, static fn (array $a): bool => (bool) $a['is_correct']))
                );
                sort($correctIds);

                if ($submittedIds === $correctIds && $submittedIds !== []) {
                    $awarded = $points;
                    $isCorrectFlag = true;
                }
            } elseif ($type === 'fill_blank') {
                $text = trim((string) ($submitted['text'] ?? ''));
                $correctAnswers = array_map(
                    static fn (array $a): string => trim((string) $a['answer_text']),
                    array_values(array_filter($answers, static fn (array $a): bool => (bool) $a['is_correct']))
                );
                foreach ($correctAnswers as $correct) {
                    if ($text !== '' && mb_strtolower($text) === mb_strtolower($correct)) {
                        $awarded = $points;
                        $isCorrectFlag = true;
                        break;
                    }
                }
            } elseif ($type === 'sequencing') {
                $seq = $submitted['sequence'] ?? [];
                if (!is_array($seq)) {
                    $seq = [];
                }
                $dbMap = [];
                foreach ($answers as $a) {
                    if ($a['sequence_position'] !== null) {
                        $dbMap[(int) $a['id']] = (int) $a['sequence_position'];
                    }
                }
                ksort($dbMap);

                $submittedMap = [];
                foreach ($seq as $answerIdRaw => $posRaw) {
                    $answerId = filter_var($answerIdRaw, FILTER_VALIDATE_INT);
                    $position = filter_var($posRaw, FILTER_VALIDATE_INT);
                    if ($answerId !== false && $answerId !== null && $position !== false && $position !== null && $position > 0) {
                        $submittedMap[(int) $answerId] = (int) $position;
                    }
                }
                ksort($submittedMap);

                if ($submittedMap === $dbMap && $submittedMap !== []) {
                    $awarded = $points;
                    $isCorrectFlag = true;
                }
            }

            $totalAwarded += $awarded;
            $perQuestionFeedback[$qId] = [
                'awarded' => $awarded,
                'points' => $points,
                'correct' => $isCorrectFlag,
                'type' => $type,
            ];
        }

        $percentage = $totalPossible > 0 ? ($totalAwarded / $totalPossible) * 100 : 0.0;

        $insertAttemptStmt = $pdo->prepare(
            'INSERT INTO module_quiz_attempts (user_id, module_id, score, total_points, percentage)
             VALUES (:user_id, :module_id, :score, :total_points, :percentage)'
        );
        $insertAttemptStmt->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'module_id' => (int) $module['id'],
            'score' => number_format($totalAwarded, 2, '.', ''),
            'total_points' => number_format($totalPossible, 2, '.', ''),
            'percentage' => number_format($percentage, 2, '.', ''),
        ]);

        if ($percentage >= 70.0) {
            $markCompletedStmt = $pdo->prepare(
                'INSERT INTO user_progress (user_id, module_id, is_completed, last_watched_second, completed_at)
                 VALUES (:user_id, :module_id, 1, :last_watched_second, :completed_at)
                 ON DUPLICATE KEY UPDATE
                   is_completed = GREATEST(is_completed, VALUES(is_completed)),
                   completed_at = COALESCE(completed_at, VALUES(completed_at))'
            );
            $markCompletedStmt->execute([
                'user_id' => (int) $_SESSION['user_id'],
                'module_id' => (int) $module['id'],
                'last_watched_second' => $lastWatchedSecond,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $isCompleted = true;
        }

        $quizFeedback = [
            'score' => $totalAwarded,
            'total' => $totalPossible,
            'percentage' => $percentage,
            'passed' => $percentage >= 70.0,
            'per_question' => $perQuestionFeedback,
        ];
    } catch (Throwable $e) {
        $quizFeedback = [
            'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to grade quiz.',
        ];
        if (!($e instanceof RuntimeException)) {
            error_log($e->getMessage());
        }
    }
}

$latestAttemptStmt = $pdo->prepare(
    'SELECT score, total_points, percentage, attempted_at
     FROM module_quiz_attempts
     WHERE user_id = :user_id AND module_id = :module_id
     ORDER BY id DESC
     LIMIT 1'
);
$latestAttemptStmt->execute([
    'user_id' => (int) $_SESSION['user_id'],
    'module_id' => (int) $module['id'],
]);
$latestModuleAttempt = $latestAttemptStmt->fetch();

if ($postAction === 'submit_module_quiz' || $postAction === 'mark_video_watched') {
    $courseModulesStmt->execute([
        'course_id' => (int) $module['course_id'],
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $courseModuleList = $courseModulesStmt->fetchAll();
    $prevModuleRow = null;
    $nextModuleRow = null;
    $currentIndex = -1;
    $allModulesCompleted = $courseModuleList !== [];
    foreach ($courseModuleList as $idx => $row) {
        if ((int) $row['id'] === (int) $module['id']) {
            $currentIndex = $idx;
        }
        if ((int) $row['is_completed'] !== 1) {
            $allModulesCompleted = false;
        }
    }
    if ($currentIndex > 0) {
        $prevModuleRow = $courseModuleList[$currentIndex - 1];
    }
    if ($currentIndex >= 0 && $currentIndex < count($courseModuleList) - 1) {
        $nextModuleRow = $courseModuleList[$currentIndex + 1];
    }
}

/**
 * Detect the provider of a video URL and produce an embeddable URL where possible.
 * Supported providers: YouTube (watch, youtu.be, shorts, embed), Vimeo, direct file.
 */
$clmsDetectVideoSource = static function (string $url): array {
    $trimmed = trim($url);
    if ($trimmed === '') {
        return ['type' => 'invalid', 'reason' => 'Video URL is empty.'];
    }
    if (preg_match('#^https?://(?:www\.|m\.)?youtube\.com/watch#i', $trimmed)) {
        $parts = parse_url($trimmed);
        parse_str($parts['query'] ?? '', $queryParams);
        $videoId = isset($queryParams['v']) ? (string) $queryParams['v'] : '';
        if ($videoId !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
            return [
                'type' => 'youtube',
                'video_id' => $videoId,
                'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?enablejsapi=1&rel=0&modestbranding=1',
            ];
        }
        return ['type' => 'invalid', 'reason' => 'That YouTube link does not contain a video ID. Paste the video watch URL (https://www.youtube.com/watch?v=...).'];
    }
    if (preg_match('#^https?://youtu\.be/([A-Za-z0-9_-]+)#i', $trimmed, $m)) {
        return [
            'type' => 'youtube',
            'video_id' => $m[1],
            'embed_url' => 'https://www.youtube.com/embed/' . $m[1] . '?enablejsapi=1&rel=0&modestbranding=1',
        ];
    }
    if (preg_match('#^https?://(?:www\.)?youtube\.com/(?:embed|shorts|v)/([A-Za-z0-9_-]+)#i', $trimmed, $m)) {
        return [
            'type' => 'youtube',
            'video_id' => $m[1],
            'embed_url' => 'https://www.youtube.com/embed/' . $m[1] . '?enablejsapi=1&rel=0&modestbranding=1',
        ];
    }
    if (preg_match('#^https?://(?:www\.)?youtube\.com/(?:results|playlist|feed|channel|user|c)/?#i', $trimmed)) {
        return ['type' => 'invalid', 'reason' => 'That is a YouTube search/playlist/channel page, not a single video. Paste a specific video URL.'];
    }
    if (preg_match('#^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)#i', $trimmed, $m)) {
        return [
            'type' => 'vimeo',
            'video_id' => $m[1],
            'embed_url' => 'https://player.vimeo.com/video/' . $m[1],
        ];
    }
    if (preg_match('#\.(?:mp4|webm|ogg|m4v|mov)(?:$|\?)#i', $trimmed)) {
        return ['type' => 'file', 'embed_url' => $trimmed];
    }
    return ['type' => 'invalid', 'reason' => 'Unsupported video URL. Use a YouTube/Vimeo link or a direct .mp4/.webm file URL.'];
};

$videoSource = $clmsDetectVideoSource((string) $module['video_url']);

$pageTitle = 'View Module | Criminology LMS';
$activeStudentPage = 'view_module';
$currentModuleId = (int) $module['id'];

require_once __DIR__ . '/includes/layout-top.php';
?>
              <h4 class="fw-bold py-3 mb-4">View Module</h4>

              <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-start">
                  <div>
                    <h5 class="mb-1"><?php echo htmlspecialchars((string) $module['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                    <small class="text-muted"><?php echo htmlspecialchars((string) $module['course_title'], ENT_QUOTES, 'UTF-8'); ?></small>
                  </div>
<?php if ($isCompleted) : ?>
                  <span class="badge bg-label-success">Completed</span>
<?php endif; ?>
                </div>
                <div class="card-body">
<?php if ($videoSource['type'] === 'invalid') : ?>
                  <div class="alert alert-warning mb-0" role="alert">
                    <h6 class="alert-heading mb-1">This module's video link cannot be played</h6>
                    <p class="mb-2"><?php echo htmlspecialchars((string) ($videoSource['reason'] ?? 'The URL is not valid.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="mb-0 small">
                      Saved URL:
                      <code><?php echo htmlspecialchars((string) $module['video_url'], ENT_QUOTES, 'UTF-8'); ?></code>
                    </p>
                    <hr />
                    <p class="mb-0 small">Please ask your instructor to update the video URL. Accepted formats include YouTube watch links (<code>https://www.youtube.com/watch?v=XXXXXXXXXXX</code>), <code>youtu.be</code> short links, Vimeo links, or direct <code>.mp4</code>/<code>.webm</code> files.</p>
                  </div>
<?php elseif ($videoSource['type'] === 'youtube') : ?>
                  <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                    <div id="moduleYouTubePlayer"></div>
                  </div>
                  <p class="text-muted mt-3 mb-0">
                    Your progress is saved automatically while the video plays.
                  </p>
<?php elseif ($videoSource['type'] === 'vimeo') : ?>
                  <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                    <iframe
                      src="<?php echo htmlspecialchars((string) $videoSource['embed_url'], ENT_QUOTES, 'UTF-8'); ?>"
                      allow="autoplay; fullscreen; picture-in-picture"
                      allowfullscreen
                      class="w-100 h-100"
                      referrerpolicy="strict-origin-when-cross-origin"></iframe>
                  </div>
                  <p class="text-muted mt-3 mb-0">
                    Progress tracking is limited for Vimeo embeds. Please watch the full video to complete this module.
                  </p>
<?php else : ?>
                  <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                    <video
                      id="moduleVideo"
                      controls
                      preload="metadata"
                      class="w-100 h-100"
                      src="<?php echo htmlspecialchars((string) $videoSource['embed_url'], ENT_QUOTES, 'UTF-8'); ?>">
                      Your browser does not support the video element.
                    </video>
                  </div>
                  <p class="text-muted mt-3 mb-0">
                    Your progress is saved automatically and synced when you pause or leave this page.
                  </p>
<?php endif; ?>
                </div>
              </div>

<?php if ($moduleQuestions !== []) : ?>
<?php if (!$videoCompleted) : ?>
              <div class="card mb-4" id="moduleQuizCard">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h5 class="mb-1">Module Assessment</h5>
                    <small class="text-muted">Locked until you finish watching the video.</small>
                  </div>
                  <span class="badge bg-label-secondary"><i class="bx bx-lock-alt me-1"></i>Locked</span>
                </div>
                <div class="card-body text-center py-5">
                  <i class="bx bx-lock-alt text-muted" style="font-size: 3rem;"></i>
                  <h6 class="mt-3 mb-2">Finish the video to unlock the assessment</h6>
                  <p class="text-muted mb-4">
                    This module contains <strong><?php echo count($moduleQuestions); ?> question(s)</strong>.
                    Watch the video above to completion and the assessment will appear here.
                  </p>
<?php if ($videoSource['type'] === 'vimeo' || $videoSource['type'] === 'invalid') : ?>
                  <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $module['id'] . '#moduleQuizCard', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" value="mark_video_watched" />
                    <button type="submit" class="btn btn-outline-primary">
                      <i class="bx bx-check me-1"></i> I've finished watching — unlock assessment
                    </button>
                  </form>
                  <p class="small text-muted mt-2 mb-0">This module's player can't auto-detect completion.</p>
<?php endif; ?>
                </div>
              </div>
<?php else : ?>
              <div class="card mb-4" id="moduleQuizCard">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h5 class="mb-1">Module Assessment</h5>
                    <small class="text-muted">Answer the questions below to check what you learned. Scoring at least 70% marks this module complete and unlocks the next one.</small>
                  </div>
                  <span class="badge bg-label-primary"><?php echo count($moduleQuestions); ?> question(s)</span>
                </div>
                <div class="card-body">
<?php if ($quizFeedback !== null && isset($quizFeedback['error'])) : ?>
                  <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars((string) $quizFeedback['error'], ENT_QUOTES, 'UTF-8'); ?>
                  </div>
<?php elseif ($quizFeedback !== null) : ?>
                  <div class="alert <?php echo $quizFeedback['passed'] ? 'alert-success' : 'alert-warning'; ?>" role="alert">
                    <h6 class="alert-heading mb-1"><?php echo $quizFeedback['passed'] ? 'Great job!' : 'Keep practicing'; ?></h6>
                    <p class="mb-0">
                      You scored <strong><?php echo number_format((float) $quizFeedback['score'], 2); ?> / <?php echo number_format((float) $quizFeedback['total'], 2); ?></strong>
                      (<strong><?php echo number_format((float) $quizFeedback['percentage'], 2); ?>%</strong>).
                      <?php echo $quizFeedback['passed']
                          ? 'This module is now marked complete.'
                          : 'You need at least 70% to auto-complete this module. You can retake it below.'; ?>
                    </p>
                  </div>
<?php elseif ($latestModuleAttempt !== false && $latestModuleAttempt !== null) : ?>
                  <div class="alert alert-info" role="alert">
                    Last attempt: <strong><?php echo number_format((float) $latestModuleAttempt['score'], 2); ?> / <?php echo number_format((float) $latestModuleAttempt['total_points'], 2); ?></strong>
                    (<strong><?php echo number_format((float) $latestModuleAttempt['percentage'], 2); ?>%</strong>)
                    on <?php echo htmlspecialchars((string) date('F j, Y · g:i A', strtotime((string) $latestModuleAttempt['attempted_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
                  </div>
<?php endif; ?>

                  <form method="post" id="moduleQuizForm" action="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $module['id'] . '#moduleQuizCard', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" value="submit_module_quiz" />
<?php $quizIndex = 0; ?>
<?php foreach ($moduleQuestions as $mq) : ?>
<?php
  $quizIndex++;
  $fb = $quizFeedback['per_question'][$mq['id']] ?? null;
  $respForQ = $submittedResponses[(int) $mq['id']] ?? $submittedResponses[(string) $mq['id']] ?? [];
  if (!is_array($respForQ)) { $respForQ = []; }
  $submittedSingle = filter_var($respForQ['single'] ?? null, FILTER_VALIDATE_INT);
  $submittedMultiRaw = is_array($respForQ['multiple'] ?? null) ? $respForQ['multiple'] : [];
  $submittedMulti = [];
  foreach ($submittedMultiRaw as $__mv) {
      $__mvInt = filter_var($__mv, FILTER_VALIDATE_INT);
      if ($__mvInt !== false && $__mvInt !== null) { $submittedMulti[] = (int) $__mvInt; }
  }
  $submittedText = (string) ($respForQ['text'] ?? '');
  $submittedSeqMap = is_array($respForQ['sequence'] ?? null) ? $respForQ['sequence'] : [];
?>
                    <div class="border rounded p-3 mb-3">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <strong>Question <?php echo $quizIndex; ?></strong>
                        <div>
                          <span class="badge bg-label-primary"><?php echo number_format((float) $mq['points'], 2); ?> pts</span>
<?php if ($fb !== null && $mq['question_type'] !== 'essay') : ?>
                          <span class="badge <?php echo $fb['correct'] ? 'bg-label-success' : 'bg-label-danger'; ?> ms-1">
                            <?php echo $fb['correct'] ? 'Correct' : 'Incorrect'; ?>
                          </span>
<?php endif; ?>
                        </div>
                      </div>
                      <p class="mb-3"><?php echo nl2br(htmlspecialchars((string) $mq['question_text'], ENT_QUOTES, 'UTF-8')); ?></p>

<?php if ($mq['question_type'] === 'true_false' || $mq['question_type'] === 'single_choice') : ?>
<?php foreach ($mq['answers'] as $ans) : ?>
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="radio"
                          name="responses[<?php echo (int) $mq['id']; ?>][single]"
                          id="mq<?php echo (int) $mq['id']; ?>_<?php echo (int) $ans['id']; ?>"
                          value="<?php echo (int) $ans['id']; ?>"
                          <?php echo ($submittedSingle !== false && $submittedSingle !== null && (int) $submittedSingle === (int) $ans['id']) ? 'checked' : ''; ?> />
                        <label class="form-check-label" for="mq<?php echo (int) $mq['id']; ?>_<?php echo (int) $ans['id']; ?>">
                          <?php echo htmlspecialchars((string) $ans['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                      </div>
<?php endforeach; ?>

<?php elseif ($mq['question_type'] === 'multiple_select') : ?>
<?php foreach ($mq['answers'] as $ans) : ?>
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox"
                          name="responses[<?php echo (int) $mq['id']; ?>][multiple][]"
                          id="mq<?php echo (int) $mq['id']; ?>_<?php echo (int) $ans['id']; ?>"
                          value="<?php echo (int) $ans['id']; ?>"
                          <?php echo in_array((int) $ans['id'], $submittedMulti, true) ? 'checked' : ''; ?> />
                        <label class="form-check-label" for="mq<?php echo (int) $mq['id']; ?>_<?php echo (int) $ans['id']; ?>">
                          <?php echo htmlspecialchars((string) $ans['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                      </div>
<?php endforeach; ?>

<?php elseif ($mq['question_type'] === 'fill_blank') : ?>
                      <input type="text" class="form-control"
                        name="responses[<?php echo (int) $mq['id']; ?>][text]"
                        placeholder="Type your answer"
                        value="<?php echo htmlspecialchars($submittedText, ENT_QUOTES, 'UTF-8'); ?>" />

<?php elseif ($mq['question_type'] === 'sequencing') : ?>
                      <p class="small text-muted mb-2">Set the correct order for each item.</p>
<?php $orderMaxMq = count($mq['answers']); ?>
<?php foreach ($mq['answers'] as $ans) : ?>
<?php $submittedPosForAns = filter_var($submittedSeqMap[(string) $ans['id']] ?? $submittedSeqMap[(int) $ans['id']] ?? null, FILTER_VALIDATE_INT); ?>
                      <div class="row align-items-center mb-2">
                        <div class="col-md-8">
                          <label class="form-label mb-0"><?php echo htmlspecialchars((string) $ans['answer_text'], ENT_QUOTES, 'UTF-8'); ?></label>
                        </div>
                        <div class="col-md-4">
                          <select class="form-select" name="responses[<?php echo (int) $mq['id']; ?>][sequence][<?php echo (int) $ans['id']; ?>]">
                            <option value="">Select order</option>
<?php for ($i = 1; $i <= $orderMaxMq; $i++) : ?>
                            <option value="<?php echo $i; ?>" <?php echo ($submittedPosForAns !== false && $submittedPosForAns !== null && (int) $submittedPosForAns === $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
<?php endfor; ?>
                          </select>
                        </div>
                      </div>
<?php endforeach; ?>

<?php elseif ($mq['question_type'] === 'essay') : ?>
                      <textarea class="form-control" rows="3"
                        name="responses[<?php echo (int) $mq['id']; ?>][text]"
                        placeholder="Type your response"><?php echo htmlspecialchars($submittedText, ENT_QUOTES, 'UTF-8'); ?></textarea>
                      <small class="text-muted">Essay items are for reflection and do not affect the auto-score.</small>
<?php endif; ?>
                    </div>
<?php endforeach; ?>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-primary">Submit Answers</button>
                      <small class="text-muted align-self-center"><i class="bx bx-save me-1"></i>Your answers auto-save as you type.</small>
                    </div>
                  </form>
                </div>
              </div>
<?php endif; ?>
<?php endif; ?>

<?php
  $hasModuleQuiz = $moduleQuestions !== [];
  $currentModuleUnlocked = $isCompleted || !$hasModuleQuiz;
?>
<?php if ($hasModuleQuiz && !$isCompleted) : ?>
              <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
                <i class="bx bx-lock-alt fs-4 me-2"></i>
                <div>
                  Submit the module assessment with a score of at least 70% to unlock the next module<?php echo $hasFinalExam ? ' and the final exam' : ''; ?>.
                </div>
              </div>
<?php endif; ?>

              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
<?php if ($prevModuleRow !== null) : ?>
                  <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $prevModuleRow['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx bx-chevron-left me-1"></i> Previous: <?php echo htmlspecialchars((string) $prevModuleRow['title'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
<?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                  <a class="btn btn-label-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx bx-grid-alt me-1"></i> Back to Dashboard
                  </a>
<?php if ($nextModuleRow !== null) : ?>
<?php if ($currentModuleUnlocked) : ?>
                  <a class="btn btn-primary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $nextModuleRow['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    Next: <?php echo htmlspecialchars((string) $nextModuleRow['title'], ENT_QUOTES, 'UTF-8'); ?> <i class="bx bx-chevron-right ms-1"></i>
                  </a>
<?php else : ?>
                  <button class="btn btn-primary" type="button" disabled title="Pass the module assessment to unlock">
                    <i class="bx bx-lock-alt me-1"></i> Next Module Locked
                  </button>
<?php endif; ?>
<?php elseif ($hasFinalExam) : ?>
<?php if ($allModulesCompleted) : ?>
                  <a class="btn btn-success" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_exam.php?course_id=' . (int) $module['course_id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx bx-certification me-1"></i> Take Final Exam
                  </a>
<?php else : ?>
                  <button class="btn btn-success" type="button" disabled title="Finish every module assessment first">
                    <i class="bx bx-lock-alt me-1"></i> Final Exam Locked
                  </button>
<?php endif; ?>
<?php endif; ?>
                </div>
              </div>

<?php if ($nextModuleRow === null && $hasFinalExam && !$allModulesCompleted) : ?>
              <div class="alert alert-warning mb-4" role="alert">
                <strong>Final exam is locked.</strong> You still have incomplete modules:
                <ul class="mb-0 mt-2">
<?php foreach ($courseModuleList as $cmRow) : ?>
<?php if ((int) $cmRow['is_completed'] !== 1) : ?>
                  <li>
                    <a href="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $cmRow['id'], ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo htmlspecialchars((string) $cmRow['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  </li>
<?php endif; ?>
<?php endforeach; ?>
                </ul>
              </div>
<?php endif; ?>

<?php if ($videoSource['type'] === 'file') : ?>
              <script>
                (() => {
                  const video = document.getElementById('moduleVideo');
                  if (!video) return;

                  const moduleId = <?php echo (int) $module['id']; ?>;
                  const userId = <?php echo (int) $_SESSION['user_id']; ?>;
                  const csrfToken = <?php echo json_encode(clms_csrf_token(), JSON_THROW_ON_ERROR); ?>;
                  const apiUrl = <?php echo json_encode($clmsWebBase . '/student/update_progress.php', JSON_THROW_ON_ERROR); ?>;
                  const storageKey = `clms_module_progress_${userId}_${moduleId}`;
                  const serverLastSecond = <?php echo $lastWatchedSecond; ?>;
                  let completionSent = <?php echo $isCompleted ? 'true' : 'false'; ?>;

                  const persistedSecond = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0;
                  const resumeSecond = Math.max(serverLastSecond, persistedSecond);

                  const persistLocally = () => {
                    localStorage.setItem(storageKey, String(Math.max(0, Math.floor(video.currentTime || 0))));
                  };

                  const sendProgress = (isCompleted = false, useBeacon = false) => {
                    const second = Math.max(0, Math.floor(video.currentTime || 0));
                    const payload = new URLSearchParams();
                    payload.set('module_id', String(moduleId));
                    payload.set('last_watched_second', String(second));
                    payload.set('is_completed', isCompleted ? '1' : '0');
                    payload.set('csrf_token', csrfToken);

                    if (useBeacon && navigator.sendBeacon) {
                      navigator.sendBeacon(apiUrl, payload);
                      return;
                    }

                    fetch(apiUrl, {
                      method: 'POST',
                      headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                      },
                      body: payload.toString(),
                      keepalive: useBeacon
                    }).catch(() => {});
                  };

                  const checkCompletionThreshold = () => {
                    if (completionSent) return;
                    if (!Number.isFinite(video.duration) || video.duration <= 0) return;
                    if ((video.currentTime / video.duration) >= 0.95) {
                      completionSent = true;
                      sendProgress(true, false);
                    }
                  };

                  video.addEventListener('loadedmetadata', () => {
                    if (resumeSecond > 0 && resumeSecond < video.duration) {
                      video.currentTime = resumeSecond;
                    }
                  });

                  video.addEventListener('timeupdate', checkCompletionThreshold);
                  video.addEventListener('pause', () => sendProgress(false, false));
                  video.addEventListener('ended', () => {
                    completionSent = true;
                    sendProgress(true, false);
                  });

                  setInterval(persistLocally, 5000);
                  window.addEventListener('beforeunload', () => {
                    persistLocally();
                    sendProgress(completionSent, true);
                  });
                })();
              </script>
<?php elseif ($videoSource['type'] === 'youtube') : ?>
              <script src="https://www.youtube.com/iframe_api"></script>
              <script>
                (() => {
                  const moduleId = <?php echo (int) $module['id']; ?>;
                  const userId = <?php echo (int) $_SESSION['user_id']; ?>;
                  const csrfToken = <?php echo json_encode(clms_csrf_token(), JSON_THROW_ON_ERROR); ?>;
                  const apiUrl = <?php echo json_encode($clmsWebBase . '/student/update_progress.php', JSON_THROW_ON_ERROR); ?>;
                  const videoId = <?php echo json_encode((string) ($videoSource['video_id'] ?? ''), JSON_THROW_ON_ERROR); ?>;
                  const storageKey = `clms_module_progress_${userId}_${moduleId}`;
                  const serverLastSecond = <?php echo $lastWatchedSecond; ?>;
                  let completionSent = <?php echo $isCompleted ? 'true' : 'false'; ?>;
                  let player = null;
                  let pollingHandle = null;

                  const persistedSecond = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0;
                  const resumeSecond = Math.max(serverLastSecond, persistedSecond);

                  const getCurrentSecond = () => {
                    try { return Math.max(0, Math.floor(player && player.getCurrentTime ? player.getCurrentTime() : 0)); }
                    catch (_e) { return 0; }
                  };

                  const getDuration = () => {
                    try { return player && player.getDuration ? Number(player.getDuration()) || 0 : 0; }
                    catch (_e) { return 0; }
                  };

                  const persistLocally = () => {
                    localStorage.setItem(storageKey, String(getCurrentSecond()));
                  };

                  const sendProgress = (isCompleted = false, useBeacon = false) => {
                    const payload = new URLSearchParams();
                    payload.set('module_id', String(moduleId));
                    payload.set('last_watched_second', String(getCurrentSecond()));
                    payload.set('is_completed', isCompleted ? '1' : '0');
                    payload.set('csrf_token', csrfToken);

                    if (useBeacon && navigator.sendBeacon) {
                      navigator.sendBeacon(apiUrl, payload);
                      return;
                    }

                    fetch(apiUrl, {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                      body: payload.toString(),
                      keepalive: useBeacon
                    }).catch(() => {});
                  };

                  const checkCompletionThreshold = () => {
                    if (completionSent) return;
                    const duration = getDuration();
                    if (!duration || duration <= 0) return;
                    if ((getCurrentSecond() / duration) >= 0.95) {
                      completionSent = true;
                      sendProgress(true, false);
                    }
                  };

                  window.onYouTubeIframeAPIReady = () => {
                    player = new YT.Player('moduleYouTubePlayer', {
                      videoId: videoId,
                      playerVars: { rel: 0, modestbranding: 1, enablejsapi: 1 },
                      events: {
                        onReady: () => {
                          if (resumeSecond > 0) {
                            try { player.seekTo(resumeSecond, true); } catch (_e) {}
                          }
                        },
                        onStateChange: (event) => {
                          if (event.data === YT.PlayerState.PLAYING) {
                            if (pollingHandle) return;
                            pollingHandle = setInterval(() => {
                              persistLocally();
                              checkCompletionThreshold();
                            }, 5000);
                          } else {
                            if (pollingHandle) {
                              clearInterval(pollingHandle);
                              pollingHandle = null;
                            }
                            if (event.data === YT.PlayerState.PAUSED) {
                              persistLocally();
                              sendProgress(false, false);
                            } else if (event.data === YT.PlayerState.ENDED) {
                              completionSent = true;
                              sendProgress(true, false);
                            }
                          }
                        }
                      }
                    });
                  };

                  window.addEventListener('beforeunload', () => {
                    persistLocally();
                    sendProgress(completionSent, true);
                  });
                })();
              </script>
<?php endif; ?>

<?php if ($quizFeedback !== null && !isset($quizFeedback['error'])) : ?>
              <div class="modal fade" id="moduleQuizScoreModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Module Assessment Result</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center py-4">
<?php if ($quizFeedback['passed']) : ?>
                      <i class="bx bx-check-circle text-success" style="font-size: 4rem;"></i>
                      <h4 class="mt-2 mb-1">Passed!</h4>
<?php else : ?>
                      <i class="bx bx-error-circle text-warning" style="font-size: 4rem;"></i>
                      <h4 class="mt-2 mb-1">Not Quite</h4>
<?php endif; ?>
                      <div class="display-4 fw-bold my-3 <?php echo $quizFeedback['passed'] ? 'text-success' : 'text-warning'; ?>">
                        <?php echo number_format((float) $quizFeedback['percentage'], 0); ?>%
                      </div>
<?php
  $correctCount = 0;
  $gradableCount = 0;
  foreach ($quizFeedback['per_question'] ?? [] as $pf) {
      if (($pf['type'] ?? '') === 'essay') { continue; }
      $gradableCount++;
      if (!empty($pf['correct'])) { $correctCount++; }
  }
?>
                      <p class="mb-2">
                        You answered <strong><?php echo $correctCount; ?></strong> out of <strong><?php echo $gradableCount; ?></strong> correctly.
                      </p>
                      <p class="mb-0 text-muted small">
                        Score: <?php echo number_format((float) $quizFeedback['score'], 2); ?> / <?php echo number_format((float) $quizFeedback['total'], 2); ?> points
                      </p>
<?php if ($quizFeedback['passed']) : ?>
                      <p class="mt-3 mb-0 text-success"><i class="bx bx-check me-1"></i>This module is now marked complete.</p>
<?php else : ?>
                      <p class="mt-3 mb-0 text-muted">You need at least 70% to unlock the next module. Review your answers and try again.</p>
<?php endif; ?>
                    </div>
                    <div class="modal-footer">
<?php if ($quizFeedback['passed']) : ?>
<?php if ($nextModuleRow !== null) : ?>
                      <a class="btn btn-primary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/view_module.php?module_id=' . (int) $nextModuleRow['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        Next Module <i class="bx bx-chevron-right ms-1"></i>
                      </a>
<?php elseif ($hasFinalExam && $allModulesCompleted) : ?>
                      <a class="btn btn-success" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_exam.php?course_id=' . (int) $module['course_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-certification me-1"></i> Take Final Exam
                      </a>
<?php endif; ?>
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Review Answers</button>
<?php else : ?>
                      <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Review &amp; Retake</button>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
<?php endif; ?>

              <script>
                (() => {
                  if ('scrollRestoration' in history) {
                    history.scrollRestoration = 'manual';
                  }

                  const moduleId = <?php echo (int) $module['id']; ?>;
                  const userId = <?php echo (int) $_SESSION['user_id']; ?>;
                  const scrollKey = `clms_vm_scroll_${userId}_${moduleId}`;
                  const answersKey = `clms_vm_answers_${userId}_${moduleId}`;
                  const hasServerFeedback = <?php echo $quizFeedback !== null && !isset($quizFeedback['error']) ? 'true' : 'false'; ?>;

                  const form = document.getElementById('moduleQuizForm');

                  const saveScroll = () => {
                    try { sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0)); }
                    catch (_e) {}
                  };

                  const restoreScroll = () => {
                    let savedRaw = null;
                    try { savedRaw = sessionStorage.getItem(scrollKey); } catch (_e) {}
                    if (savedRaw === null) return;
                    const saved = parseInt(savedRaw, 10);
                    try { sessionStorage.removeItem(scrollKey); } catch (_e) {}
                    if (!Number.isFinite(saved) || saved < 0) return;
                    const jump = () => window.scrollTo({ top: saved, left: 0, behavior: 'auto' });
                    jump();
                    requestAnimationFrame(jump);
                    setTimeout(jump, 60);
                    setTimeout(jump, 180);
                  };

                  const saveAnswers = () => {
                    if (!form) return;
                    try {
                      const entries = [];
                      for (const [name, value] of new FormData(form).entries()) {
                        if (name === 'csrf_token' || name === 'action') continue;
                        entries.push([name, value]);
                      }
                      localStorage.setItem(answersKey, JSON.stringify(entries));
                    } catch (_e) {}
                  };

                  const restoreAnswers = () => {
                    if (!form) return;
                    let entries = [];
                    try {
                      const raw = localStorage.getItem(answersKey);
                      if (!raw) return;
                      entries = JSON.parse(raw);
                      if (!Array.isArray(entries)) return;
                    } catch (_e) { return; }

                    const escape = (window.CSS && CSS.escape) ? CSS.escape : (s) => String(s).replace(/([^a-zA-Z0-9_-])/g, '\\$1');

                    for (const pair of entries) {
                      if (!Array.isArray(pair) || pair.length !== 2) continue;
                      const [name, value] = pair;
                      const nodes = form.querySelectorAll(`[name="${escape(name)}"]`);
                      nodes.forEach((node) => {
                        if (node.type === 'radio' || node.type === 'checkbox') {
                          if (String(node.value) === String(value)) node.checked = true;
                        } else {
                          if (!node.value) node.value = value;
                        }
                      });
                    }
                  };

                  const clearAnswers = () => {
                    try { localStorage.removeItem(answersKey); } catch (_e) {}
                  };

                  if (form) {
                    form.addEventListener('submit', () => {
                      saveScroll();
                      clearAnswers();
                    });
                    form.addEventListener('input', saveAnswers);
                    form.addEventListener('change', saveAnswers);
                  }

                  const onReady = () => {
                    restoreScroll();
                    if (hasServerFeedback) {
                      clearAnswers();
                    } else {
                      restoreAnswers();
                    }

                    const modalEl = document.getElementById('moduleQuizScoreModal');
                    if (modalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                      try { new window.bootstrap.Modal(modalEl).show(); } catch (_e) {}
                    }
                  };

                  if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    onReady();
                  } else {
                    document.addEventListener('DOMContentLoaded', onReady, { once: true });
                  }
                })();
              </script>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';

