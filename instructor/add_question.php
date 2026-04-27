<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['instructor']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS course_instructors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        instructor_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_course_instructor (course_id, instructor_user_id),
        CONSTRAINT fk_course_instructors_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_course_instructors_user FOREIGN KEY (instructor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

try {
    $moduleColumnCheck = $pdo->query("SHOW COLUMNS FROM questions LIKE 'module_id'")->fetch();
    if (!$moduleColumnCheck) {
        $pdo->exec('ALTER TABLE questions ADD COLUMN module_id INT NULL AFTER course_id');
        $pdo->exec('ALTER TABLE questions ADD INDEX idx_questions_module (module_id)');
    }
} catch (Throwable $e) {
    error_log('questions.module_id migration failed: ' . $e->getMessage());
}

$instructorId = (int) $_SESSION['user_id'];
$pageTitle = 'Add Question | Instructor';
$activeInstructorPage = 'manage_content';
$errorMessage = '';
$successMessage = '';
$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$editQuestionId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$deleteQuestionId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
$editQuestion = null;

$assignedCoursesStmt = $pdo->prepare(
    "SELECT c.id, c.title
     FROM course_instructors ci
     INNER JOIN courses c ON c.id = ci.course_id
     WHERE ci.instructor_user_id = :instructor_id
     ORDER BY c.title ASC"
);
$assignedCoursesStmt->execute(['instructor_id' => $instructorId]);
$assignedCourses = $assignedCoursesStmt->fetchAll();
$assignedSet = array_fill_keys(array_map(static fn (array $r): int => (int) $r['id'], $assignedCourses), true);

if ($deleteQuestionId !== false && $deleteQuestionId !== null && $deleteQuestionId > 0) {
    try {
        $verifyStmt = $pdo->prepare(
            "SELECT q.id
             FROM questions q
             INNER JOIN course_instructors ci ON ci.course_id = q.course_id
             WHERE q.id = :question_id
               AND ci.instructor_user_id = :instructor_id
             LIMIT 1"
        );
        $verifyStmt->execute([
            'question_id' => (int) $deleteQuestionId,
            'instructor_id' => $instructorId,
        ]);
        if (!$verifyStmt->fetch()) {
            throw new RuntimeException('Question is outside your scope.');
        }

        $deleteStmt = $pdo->prepare(
            'DELETE q, a
             FROM questions q
             LEFT JOIN answers a ON a.question_id = q.id
             WHERE q.id = :question_id'
        );
        $deleteStmt->execute(['question_id' => (int) $deleteQuestionId]);
        $successMessage = 'Question deleted successfully.';
    } catch (Throwable $e) {
        $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to delete question.';
        if (!($e instanceof RuntimeException)) {
            error_log($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token.';
    } elseif (($_POST['action'] ?? '') === 'save_video') {
        try {
            $videoCourseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
            $moduleTitle = trim((string) ($_POST['module_title'] ?? ''));
            $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
            $durationInput = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT);
            $sequenceInput = filter_input(INPUT_POST, 'sequence_order', FILTER_VALIDATE_INT);
            $moduleIdInput = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);

            if ($videoCourseId === false || $videoCourseId === null || $videoCourseId <= 0 || !isset($assignedSet[(int) $videoCourseId])) {
                throw new RuntimeException('You can only modify assigned courses.');
            }
            if ($moduleTitle === '' || $videoUrl === '') {
                throw new RuntimeException('Module title and video URL are required.');
            }
            if ($durationInput === false || $durationInput === null || $durationInput < 0
                || $sequenceInput === false || $sequenceInput === null || $sequenceInput < 1) {
                throw new RuntimeException('Invalid duration or sequence order.');
            }

            if (filter_var($videoUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Video URL must be a valid http(s) link.');
            }
            $isSupportedVideoUrl = false;
            if (preg_match('#^https?://(?:www\.|m\.)?youtube\.com/watch#i', $videoUrl)) {
                $parsedQuery = [];
                parse_str((string) (parse_url($videoUrl, PHP_URL_QUERY) ?? ''), $parsedQuery);
                $candidateId = (string) ($parsedQuery['v'] ?? '');
                $isSupportedVideoUrl = $candidateId !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $candidateId) === 1;
            } elseif (preg_match('#^https?://youtu\.be/[A-Za-z0-9_-]+#i', $videoUrl)
                || preg_match('#^https?://(?:www\.)?youtube\.com/(?:embed|shorts|v)/[A-Za-z0-9_-]+#i', $videoUrl)
                || preg_match('#^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?\d+#i', $videoUrl)
                || preg_match('#\.(?:mp4|webm|ogg|m4v|mov)(?:$|\?)#i', $videoUrl)) {
                $isSupportedVideoUrl = true;
            }
            if (!$isSupportedVideoUrl) {
                throw new RuntimeException('Unsupported video URL. Paste a YouTube video watch link (https://www.youtube.com/watch?v=...), a youtu.be short link, a Vimeo video link, or a direct .mp4/.webm file URL. Search, playlist, and channel pages are not embeddable.');
            }

            if ($moduleIdInput !== false && $moduleIdInput !== null && $moduleIdInput > 0) {
                $scopeCheckStmt = $pdo->prepare(
                    'SELECT course_id FROM modules WHERE id = :module_id LIMIT 1'
                );
                $scopeCheckStmt->execute(['module_id' => (int) $moduleIdInput]);
                $existingModule = $scopeCheckStmt->fetch();
                if (!$existingModule) {
                    throw new RuntimeException('Module not found.');
                }
                $existingCourseId = (int) $existingModule['course_id'];
                if (!isset($assignedSet[$existingCourseId])) {
                    throw new RuntimeException('Module is not within your assigned course scope.');
                }

                $updateModuleStmt = $pdo->prepare(
                    'UPDATE modules
                     SET course_id = :course_id,
                         title = :title,
                         video_url = :video_url,
                         duration_minutes = :duration_minutes,
                         sequence_order = :sequence_order
                     WHERE id = :module_id'
                );
                $updateModuleStmt->execute([
                    'course_id' => (int) $videoCourseId,
                    'title' => $moduleTitle,
                    'video_url' => $videoUrl,
                    'duration_minutes' => (int) $durationInput,
                    'sequence_order' => (int) $sequenceInput,
                    'module_id' => (int) $moduleIdInput,
                ]);
                $successMessage = 'Module video content updated.';
            } else {
                $insertModuleStmt = $pdo->prepare(
                    'INSERT INTO modules (course_id, title, video_url, duration_minutes, sequence_order)
                     VALUES (:course_id, :title, :video_url, :duration_minutes, :sequence_order)'
                );
                $insertModuleStmt->execute([
                    'course_id' => (int) $videoCourseId,
                    'title' => $moduleTitle,
                    'video_url' => $videoUrl,
                    'duration_minutes' => (int) $durationInput,
                    'sequence_order' => (int) $sequenceInput,
                ]);
                $successMessage = 'Module video content added.';
            }
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save module video.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_video') {
        try {
            $deleteModuleId = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
            if ($deleteModuleId === false || $deleteModuleId === null || $deleteModuleId <= 0) {
                throw new RuntimeException('Invalid module id.');
            }

            $scopeStmt = $pdo->prepare(
                'SELECT m.id, m.course_id, m.title
                 FROM modules m
                 WHERE m.id = :module_id
                 LIMIT 1'
            );
            $scopeStmt->execute(['module_id' => (int) $deleteModuleId]);
            $moduleRow = $scopeStmt->fetch();
            if (!$moduleRow || !isset($assignedSet[(int) $moduleRow['course_id']])) {
                throw new RuntimeException('Module is not within your assigned course scope.');
            }

            $pdo->beginTransaction();
            try {
                $deleteQuizAttemptsStmt = $pdo->prepare(
                    'DELETE FROM module_quiz_attempts WHERE module_id = :module_id'
                );
                try {
                    $deleteQuizAttemptsStmt->execute(['module_id' => (int) $deleteModuleId]);
                } catch (PDOException $quizException) {
                    if (!isset($quizException->errorInfo[1]) || (int) $quizException->errorInfo[1] !== 1146) {
                        throw $quizException;
                    }
                }

                $deleteAnswersStmt = $pdo->prepare(
                    'DELETE a FROM answers a
                     INNER JOIN questions q ON q.id = a.question_id
                     WHERE q.module_id = :module_id'
                );
                $deleteAnswersStmt->execute(['module_id' => (int) $deleteModuleId]);

                $deleteQuestionsStmt = $pdo->prepare(
                    'DELETE FROM questions WHERE module_id = :module_id'
                );
                $deleteQuestionsStmt->execute(['module_id' => (int) $deleteModuleId]);

                $deleteProgressStmt = $pdo->prepare(
                    'DELETE FROM user_progress WHERE module_id = :module_id'
                );
                $deleteProgressStmt->execute(['module_id' => (int) $deleteModuleId]);

                $deleteModuleStmt = $pdo->prepare(
                    'DELETE FROM modules WHERE id = :module_id'
                );
                $deleteModuleStmt->execute(['module_id' => (int) $deleteModuleId]);

                $pdo->commit();
            } catch (Throwable $deleteException) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $deleteException;
            }

            $successMessage = 'Module video and all related content deleted.';
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to delete module video.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    } else {
        try {
            $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
            $questionText = trim((string) ($_POST['question_text'] ?? ''));
            $questionType = strtolower(trim((string) ($_POST['question_type'] ?? '')));
            $pointsRaw = trim((string) ($_POST['points'] ?? '1'));
            $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
            $rawModuleId = $_POST['module_id'] ?? '';
            $moduleIdForQuestion = null;
            if ($rawModuleId !== '' && $rawModuleId !== null) {
                $parsedModuleId = filter_var($rawModuleId, FILTER_VALIDATE_INT);
                if ($parsedModuleId !== false && $parsedModuleId !== null && $parsedModuleId > 0) {
                    $moduleIdForQuestion = (int) $parsedModuleId;
                }
            }
            $allowedQuestionTypes = ['true_false', 'multiple_select', 'fill_blank', 'sequencing'];

            if ($courseId === false || $courseId === null || $courseId <= 0 || !isset($assignedSet[(int) $courseId])) {
                throw new RuntimeException('You can only add questions to your assigned courses.');
            }

            if ($moduleIdForQuestion !== null) {
                $moduleScopeStmt = $pdo->prepare(
                    'SELECT 1 FROM modules WHERE id = :module_id AND course_id = :course_id LIMIT 1'
                );
                $moduleScopeStmt->execute([
                    'module_id' => $moduleIdForQuestion,
                    'course_id' => (int) $courseId,
                ]);
                if (!$moduleScopeStmt->fetch()) {
                    throw new RuntimeException('Selected module does not belong to that course.');
                }
            }
            if ($questionText === '' || !in_array($questionType, $allowedQuestionTypes, true) || !is_numeric($pointsRaw)) {
                throw new RuntimeException('Invalid question input.');
            }
            $points = (float) $pointsRaw;
            if ($points < 0) {
                throw new RuntimeException('Points must be zero or higher.');
            }

            $answerRows = [];
            if ($questionType === 'multiple_select') {
                $optionA = trim((string) ($_POST['option_a'] ?? ''));
                $optionB = trim((string) ($_POST['option_b'] ?? ''));
                $optionC = trim((string) ($_POST['option_c'] ?? ''));
                $optionD = trim((string) ($_POST['option_d'] ?? ''));
                $correctOption = strtoupper(trim((string) ($_POST['correct_option'] ?? '')));

                if ($optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
                    throw new RuntimeException('Please fill Option A, B, C, and D.');
                }
                if (!in_array($correctOption, ['A', 'B', 'C', 'D'], true)) {
                    throw new RuntimeException('Please select a valid Correct Answer.');
                }

                $optionMap = [
                    'A' => $optionA,
                    'B' => $optionB,
                    'C' => $optionC,
                    'D' => $optionD,
                ];
                foreach ($optionMap as $letter => $optionText) {
                    $answerRows[] = [
                        'answer_text' => $optionText,
                        'is_correct' => $letter === $correctOption ? 1 : 0,
                        'sequence_position' => null,
                    ];
                }
            } elseif ($questionType === 'true_false') {
                $trueFalseCorrect = strtolower(trim((string) ($_POST['true_false_correct'] ?? '')));
                if (!in_array($trueFalseCorrect, ['true', 'false'], true)) {
                    throw new RuntimeException('Please select the Correct Answer for True / False.');
                }
                $answerRows[] = [
                    'answer_text' => 'True',
                    'is_correct' => $trueFalseCorrect === 'true' ? 1 : 0,
                    'sequence_position' => null,
                ];
                $answerRows[] = [
                    'answer_text' => 'False',
                    'is_correct' => $trueFalseCorrect === 'false' ? 1 : 0,
                    'sequence_position' => null,
                ];
            } elseif ($questionType === 'fill_blank') {
                $exact = trim((string) ($_POST['fill_blank_exact'] ?? ''));
                $alternativesInput = $_POST['fill_blank_alternatives'] ?? [];
                if ($exact === '') {
                    throw new RuntimeException('Please provide the Exact correct answer.');
                }
                $answerRows[] = [
                    'answer_text' => $exact,
                    'is_correct' => 1,
                    'sequence_position' => null,
                ];
                $alternatives = [];
                if (is_array($alternativesInput)) {
                    $alternatives = array_map(static fn ($alt): string => trim((string) $alt), $alternativesInput);
                } else {
                    $alternativesRaw = trim((string) $alternativesInput);
                    if ($alternativesRaw !== '') {
                        $alternatives = preg_split('/[\r\n,]+/', $alternativesRaw) ?: [];
                    }
                }
                foreach ($alternatives as $alt) {
                        $alt = trim((string) $alt);
                        if ($alt === '' || mb_strtolower($alt) === mb_strtolower($exact)) {
                            continue;
                        }
                        $answerRows[] = [
                            'answer_text' => $alt,
                            'is_correct' => 1,
                            'sequence_position' => null,
                        ];
                }
            } elseif ($questionType === 'sequencing') {
                $sequencingItemsInput = $_POST['sequencing_items'] ?? [];
                $sequencingItems = [];
                if (is_array($sequencingItemsInput)) {
                    $sequencingItems = array_map(static fn ($item): string => trim((string) $item), $sequencingItemsInput);
                }
                $sequencingItems = array_values(array_filter($sequencingItems, static fn (string $item): bool => $item !== ''));
                if (count($sequencingItems) < 2) {
                    throw new RuntimeException('Please provide at least two sequencing items.');
                }
                foreach ($sequencingItems as $index => $itemText) {
                    $answerRows[] = [
                        'answer_text' => $itemText,
                        'is_correct' => 1,
                        'sequence_position' => $index + 1,
                    ];
                }
            } else {
                for ($i = 1; $i <= 6; $i++) {
                    $answerText = trim((string) ($_POST['answer_' . $i . '_text'] ?? ''));
                    if ($answerText === '') {
                        continue;
                    }
                    $seqRaw = trim((string) ($_POST['answer_' . $i . '_sequence'] ?? ''));
                    $seq = null;
                    if ($seqRaw !== '') {
                        $seqVal = filter_var($seqRaw, FILTER_VALIDATE_INT);
                        if ($seqVal === false || (int) $seqVal < 1) {
                            throw new RuntimeException('Invalid sequence value in answer ' . $i . '.');
                        }
                        $seq = (int) $seqVal;
                    }
                    $answerRows[] = [
                        'answer_text' => $answerText,
                        'is_correct' => isset($_POST['answer_' . $i . '_correct']) ? 1 : 0,
                        'sequence_position' => $seq,
                    ];
                }
            }

            if ($answerRows === []) {
                throw new RuntimeException('Provide at least one answer.');
            }
            if (($questionType === 'true_false' || $questionType === 'multiple_select') && array_sum(array_column($answerRows, 'is_correct')) !== 1) {
                throw new RuntimeException('Single choice/true-false need exactly one correct answer.');
            }

            $pdo->beginTransaction();
            if ($questionId !== false && $questionId !== null && $questionId > 0) {
                $verifyStmt = $pdo->prepare(
                    "SELECT q.id
                     FROM questions q
                     INNER JOIN course_instructors ci ON ci.course_id = q.course_id
                     WHERE q.id = :question_id
                       AND ci.instructor_user_id = :instructor_id
                     LIMIT 1"
                );
                $verifyStmt->execute([
                    'question_id' => (int) $questionId,
                    'instructor_id' => $instructorId,
                ]);
                if (!$verifyStmt->fetch()) {
                    throw new RuntimeException('Question is outside your scope.');
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE questions
                     SET course_id = :course_id,
                         module_id = :module_id,
                         question_text = :question_text,
                         question_type = :question_type,
                         points = :points
                     WHERE id = :question_id'
                );
                $updateStmt->execute([
                    'course_id' => (int) $courseId,
                    'module_id' => $moduleIdForQuestion,
                    'question_text' => $questionText,
                    'question_type' => $questionType,
                    'points' => number_format($points, 2, '.', ''),
                    'question_id' => (int) $questionId,
                ]);

                $deleteAnswersStmt = $pdo->prepare('DELETE FROM answers WHERE question_id = :question_id');
                $deleteAnswersStmt->execute(['question_id' => (int) $questionId]);
                $questionId = (int) $questionId;
            } else {
                $questionStmt = $pdo->prepare(
                    'INSERT INTO questions (course_id, module_id, question_text, question_type, points)
                     VALUES (:course_id, :module_id, :question_text, :question_type, :points)'
                );
                $questionStmt->execute([
                    'course_id' => (int) $courseId,
                    'module_id' => $moduleIdForQuestion,
                    'question_text' => $questionText,
                    'question_type' => $questionType,
                    'points' => number_format($points, 2, '.', ''),
                ]);
                $questionId = (int) $pdo->lastInsertId();
            }

            if ($answerRows !== []) {
                $answerStmt = $pdo->prepare(
                    'INSERT INTO answers (question_id, answer_text, is_correct, sequence_position)
                     VALUES (:question_id, :answer_text, :is_correct, :sequence_position)'
                );
                foreach ($answerRows as $row) {
                    $answerStmt->execute([
                        'question_id' => $questionId,
                        'answer_text' => $row['answer_text'],
                        'is_correct' => $row['is_correct'],
                        'sequence_position' => $row['sequence_position'],
                    ]);
                }
            }

            $pdo->commit();
            $successMessage = $questionId > 0 && $questionId === (int) ($_POST['question_id'] ?? 0)
                ? 'Question updated successfully.'
                : 'Question added successfully.';
            $selectedCourseId = (int) $courseId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to add question.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0 && $editQuestionId !== false && $editQuestionId !== null && $editQuestionId > 0) {
    if (isset($assignedSet[(int) $selectedCourseId])) {
        $editStmt = $pdo->prepare(
            'SELECT q.id, q.course_id, q.module_id, q.question_text, q.question_type, q.points
             FROM questions q
             INNER JOIN course_instructors ci ON ci.course_id = q.course_id
             WHERE q.id = :question_id
               AND q.course_id = :course_id
               AND ci.instructor_user_id = :instructor_id
             LIMIT 1'
        );
        $editStmt->execute([
            'question_id' => (int) $editQuestionId,
            'course_id' => (int) $selectedCourseId,
            'instructor_id' => $instructorId,
        ]);
        $editQuestion = $editStmt->fetch();

        if ($editQuestion) {
            $answersStmt = $pdo->prepare(
                'SELECT answer_text, is_correct, sequence_position
                 FROM answers
                 WHERE question_id = :question_id
                 ORDER BY id ASC'
            );
            $answersStmt->execute(['question_id' => (int) $editQuestionId]);
            $editQuestion['answers'] = $answersStmt->fetchAll();
        }
    }
}

$courseQuestions = [];
$courseModulesForPicker = [];
if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0 && isset($assignedSet[(int) $selectedCourseId])) {
    $questionListStmt = $pdo->prepare(
        'SELECT q.id, q.module_id, q.question_text, q.question_type, q.points, m.title AS module_title
         FROM questions q
         INNER JOIN course_instructors ci ON ci.course_id = q.course_id
         LEFT JOIN modules m ON m.id = q.module_id
         WHERE q.course_id = :course_id
           AND ci.instructor_user_id = :instructor_id
         ORDER BY q.id DESC'
    );
    $questionListStmt->execute([
        'course_id' => (int) $selectedCourseId,
        'instructor_id' => $instructorId,
    ]);
    $courseQuestions = $questionListStmt->fetchAll();

    $courseModulesStmt = $pdo->prepare(
        'SELECT id, title, sequence_order
         FROM modules
         WHERE course_id = :course_id
         ORDER BY sequence_order ASC, id ASC'
    );
    $courseModulesStmt->execute(['course_id' => (int) $selectedCourseId]);
    $courseModulesForPicker = $courseModulesStmt->fetchAll();
}

$questionCount = count($courseQuestions);

// Module list is scoped to whichever course the instructor has picked in
// the "Course:" dropdown at the top. If nothing is picked we don't run
// the query at all and the card shows a "pick a course" prompt — this is
// the fix for the old behaviour that always listed every module across
// every assigned course.
$instructorModules = [];
$selectedCourseTitle = null;
$hasSelectedCourse = $selectedCourseId !== false
    && $selectedCourseId !== null
    && $selectedCourseId > 0
    && isset($assignedSet[(int) $selectedCourseId]);

if ($hasSelectedCourse) {
    $modulesStmt = $pdo->prepare(
        "SELECT m.id, m.course_id, c.title AS course_title, m.title, m.video_url, m.duration_minutes, m.sequence_order
         FROM modules m
         INNER JOIN courses c ON c.id = m.course_id
         INNER JOIN course_instructors ci ON ci.course_id = c.id
         WHERE ci.instructor_user_id = :instructor_id
           AND m.course_id = :course_id
         ORDER BY m.sequence_order ASC, m.id ASC"
    );
    $modulesStmt->execute([
        'instructor_id' => $instructorId,
        'course_id' => (int) $selectedCourseId,
    ]);
    $instructorModules = $modulesStmt->fetchAll();

    foreach ($assignedCourses as $assignedCourseRow) {
        if ((int) $assignedCourseRow['id'] === (int) $selectedCourseId) {
            $selectedCourseTitle = (string) $assignedCourseRow['title'];
            break;
        }
    }
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="bg-white p-3 mb-3 shadow-sm rounded">
                <div class="row align-items-center g-2">
                  <div class="col-md-5">
                    <h4 class="fw-bold mb-0">Question Builder</h4>
                  </div>
                  <div class="col-md-7">
                    <div class="d-flex gap-2 align-items-center justify-content-md-end flex-wrap">
                      <form method="get" class="d-flex gap-2 align-items-center mb-0">
                        <label class="form-label mb-0" for="course_picker"><strong>Course:</strong></label>
                        <select id="course_picker" class="form-select" name="course_id" onchange="this.form.submit()" required>
                          <option value="">-- Select Course --</option>
<?php foreach ($assignedCourses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                        <span class="badge bg-info fs-6">Questions: <?php echo $questionCount; ?></span>
                      </form>
<?php if ($assignedCourses !== []) : ?>
                      <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moduleVideoModal" data-action="add">
                        <i class="bx bx-video-plus me-1"></i> Add Module Video
                      </button>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <noscript>
<?php if ($successMessage !== '') : ?>
                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($errorMessage !== '') : ?>
                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
              </noscript>

              <div class="card">
                <div class="card-body">
<?php if ($assignedCourses === []) : ?>
                  <p class="mb-0">You currently have no assigned courses.</p>
<?php elseif (!$selectedCourseId) : ?>
                  <p class="mb-0">Select one of your assigned courses to start building questions.</p>
<?php else : ?>
                  <form id="singleQuestionForm" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $selectedCourseId, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($editQuestion) : ?>
                    <input type="hidden" name="question_id" value="<?php echo (int) $editQuestion['id']; ?>" />
<?php endif; ?>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Assigned Course</label>
                        <select class="form-select" name="course_id" required>
                          <option value="">Select course</option>
<?php foreach ($assignedCourses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Attach to Module <small class="text-muted">(optional — leave blank for the course-level final exam)</small></label>
                        <select class="form-select" name="module_id">
                          <option value="">Course-level (Final Exam)</option>
<?php
$currentModuleIdValue = isset($editQuestion['module_id']) && $editQuestion['module_id'] !== null
    ? (int) $editQuestion['module_id']
    : 0;
?>
<?php foreach ($courseModulesForPicker as $modOption) : ?>
                          <option value="<?php echo (int) $modOption['id']; ?>" <?php echo $currentModuleIdValue === (int) $modOption['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars('Module ' . (int) $modOption['sequence_order'] . ': ' . (string) $modOption['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Question Type</label>
                        <select class="form-select" id="questionTypeSelect" name="question_type" required>
<?php $currentType = (string) ($editQuestion['question_type'] ?? 'multiple_select'); ?>
                          <option value="multiple_select" <?php echo $currentType === 'multiple_select' ? 'selected' : ''; ?>>Multiple Choice (A/B/C/D)</option>
                          <option value="true_false" <?php echo $currentType === 'true_false' ? 'selected' : ''; ?>>True / False</option>
                          <option value="fill_blank" <?php echo $currentType === 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option>
                          <option value="sequencing" <?php echo $currentType === 'sequencing' ? 'selected' : ''; ?>>Sequencing</option>
                        </select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Points</label>
                        <input class="form-control" type="number" name="points" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($editQuestion['points'] ?? '1.00'), ENT_QUOTES, 'UTF-8'); ?>" required />
                      </div>
                      <div class="col-12">
                        <label class="form-label">Question Text</label>
                        <textarea class="form-control" name="question_text" rows="3" required><?php echo htmlspecialchars((string) ($editQuestion['question_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      </div>
                    </div>
                    <hr class="my-4" />
<?php
  $editAnswers = $editQuestion['answers'] ?? [];
  $fillBlankCorrectValue = '';
  $fillBlankAlternatives = [];
  foreach ($editAnswers as $answerRow) {
      if ((int) ($answerRow['is_correct'] ?? 0) !== 1) {
          continue;
      }
      $answerText = trim((string) ($answerRow['answer_text'] ?? ''));
      if ($answerText === '') {
          continue;
      }
      if ($fillBlankCorrectValue === '') {
          $fillBlankCorrectValue = $answerText;
      } else {
          $fillBlankAlternatives[] = $answerText;
      }
  }
?>
                    <div id="fillBlankBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_fb" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                        <div class="col-12">
                          <label class="form-label">Correct Answer *</label>
                          <input class="form-control" type="text" name="fill_blank_exact" value="<?php echo htmlspecialchars($fillBlankCorrectValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Exact correct answer" />
                        </div>
                        <div class="col-12">
                          <label class="form-label">Accepted Alternatives (optional)</label>
                          <div id="fillBlankAlternativesWrap">
<?php if ($fillBlankAlternatives === []) : ?>
                            <div class="input-group mb-2 fill-blank-alt-row">
                              <input class="form-control" type="text" name="fill_blank_alternatives[]" placeholder="Alternative answer" />
                              <button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>
                            </div>
<?php else : ?>
<?php foreach ($fillBlankAlternatives as $altValue) : ?>
                            <div class="input-group mb-2 fill-blank-alt-row">
                              <input class="form-control" type="text" name="fill_blank_alternatives[]" value="<?php echo htmlspecialchars((string) $altValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Alternative answer" />
                              <button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                          </div>
                          <button class="btn btn-outline-primary btn-sm mt-1" id="fillBlankAddAlternativeBtn" type="button">Add Alternative</button>
                          <small class="text-muted">Other acceptable spellings or phrasings (e.g. abbreviations, case variants).</small>
                        </div>
                      </div>
                    </div>

<?php
  $optionAValue = (string) ($editAnswers[0]['answer_text'] ?? '');
  $optionBValue = (string) ($editAnswers[1]['answer_text'] ?? '');
  $optionCValue = (string) ($editAnswers[2]['answer_text'] ?? '');
  $optionDValue = (string) ($editAnswers[3]['answer_text'] ?? '');
  $correctOptionValue = '';
  foreach ([0 => 'A', 1 => 'B', 2 => 'C', 3 => 'D'] as $idx => $letter) {
      if ((int) ($editAnswers[$idx]['is_correct'] ?? 0) === 1) {
          $correctOptionValue = $letter;
          break;
      }
  }
?>
                    <div id="multipleSelectBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                      </div>
                      <p class="text-muted mb-3">Answer Options *</p>
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="form-label">Option A *</label>
                          <input class="form-control" type="text" name="option_a" value="<?php echo htmlspecialchars($optionAValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option B *</label>
                          <input class="form-control" type="text" name="option_b" value="<?php echo htmlspecialchars($optionBValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option C *</label>
                          <input class="form-control" type="text" name="option_c" value="<?php echo htmlspecialchars($optionCValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option D *</label>
                          <input class="form-control" type="text" name="option_d" value="<?php echo htmlspecialchars($optionDValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Correct Answer *</label>
                          <select class="form-select" name="correct_option">
                            <option value="">Select answer</option>
                            <option value="A" <?php echo $correctOptionValue === 'A' ? 'selected' : ''; ?>>Option A</option>
                            <option value="B" <?php echo $correctOptionValue === 'B' ? 'selected' : ''; ?>>Option B</option>
                            <option value="C" <?php echo $correctOptionValue === 'C' ? 'selected' : ''; ?>>Option C</option>
                            <option value="D" <?php echo $correctOptionValue === 'D' ? 'selected' : ''; ?>>Option D</option>
                          </select>
                        </div>
                      </div>
                    </div>

<?php
  $trueFalseCorrectValue = 'true';
  foreach ($editAnswers as $answerRow) {
      $answerTextRaw = strtolower(trim((string) ($answerRow['answer_text'] ?? '')));
      if ((int) ($answerRow['is_correct'] ?? 0) === 1) {
          if ($answerTextRaw === 'false') {
              $trueFalseCorrectValue = 'false';
          } elseif ($answerTextRaw === 'true') {
              $trueFalseCorrectValue = 'true';
          }
      }
  }
?>
                    <div id="trueFalseBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_tf" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                      </div>
                      <div class="mb-2">
                        <label class="form-label d-block">Correct Answer *</label>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="true_false_correct" id="tf_true_instructor" value="true" <?php echo $trueFalseCorrectValue === 'true' ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="tf_true_instructor">True</label>
                        </div>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="true_false_correct" id="tf_false_instructor" value="false" <?php echo $trueFalseCorrectValue === 'false' ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="tf_false_instructor">False</label>
                        </div>
                      </div>
                    </div>

<?php
  $sequencingItems = [];
  foreach ($editAnswers as $answerRow) {
      $itemText = trim((string) ($answerRow['answer_text'] ?? ''));
      if ($itemText === '') {
          continue;
      }
      $sequencingItems[] = [
          'text' => $itemText,
          'position' => (int) ($answerRow['sequence_position'] ?? 0),
      ];
  }
  usort($sequencingItems, static function (array $left, array $right): int {
      $leftPos = $left['position'] > 0 ? $left['position'] : 999999;
      $rightPos = $right['position'] > 0 ? $right['position'] : 999999;
      return $leftPos <=> $rightPos;
  });
?>
                    <div id="sequencingBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_sq" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                        <div class="col-12">
                          <label class="form-label d-block">Sequence Items *</label>
                          <p class="text-muted mb-2">Drag and drop items to set the correct order.</p>
                          <div id="sequencingItemsWrap" class="d-flex flex-column gap-2">
<?php if ($sequencingItems === []) : ?>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
<?php else : ?>
<?php foreach ($sequencingItems as $item) : ?>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" value="<?php echo htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                          </div>
                          <button class="btn btn-outline-primary btn-sm mt-2" id="sequencingAddItemBtn" type="button">Add Item</button>
                        </div>
                      </div>
                    </div>

                    <div id="answersBlock">
<?php for ($i = 1; $i <= 6; $i++) : ?>
<?php
  $answerText = (string) ($editQuestion['answers'][$i - 1]['answer_text'] ?? '');
  $isCorrect = (int) ($editQuestion['answers'][$i - 1]['is_correct'] ?? 0) === 1;
  $sequencePosition = $editQuestion['answers'][$i - 1]['sequence_position'] ?? '';
?>
                    <div class="row g-2 mb-2 answer-row">
                      <div class="col-md-7"><input class="form-control" type="text" name="answer_<?php echo $i; ?>_text" placeholder="Answer <?php echo $i; ?>" value="<?php echo htmlspecialchars($answerText, ENT_QUOTES, 'UTF-8'); ?>" /></div>
                      <div class="col-md-2 sequence-col"><input class="form-control sequence-input" type="number" min="1" name="answer_<?php echo $i; ?>_sequence" placeholder="Seq" value="<?php echo htmlspecialchars((string) $sequencePosition, ENT_QUOTES, 'UTF-8'); ?>" /></div>
                      <div class="col-md-3 d-flex align-items-center">
                        <div class="form-check">
                          <input class="form-check-input correct-input" type="checkbox" name="answer_<?php echo $i; ?>_correct" id="instructor_ans_<?php echo $i; ?>" <?php echo $isCorrect ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="instructor_ans_<?php echo $i; ?>">Correct</label>
                        </div>
                      </div>
                    </div>
<?php endfor; ?>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                      <button class="btn btn-primary" type="submit"><?php echo $editQuestion ? 'Update Question' : 'Save Question'; ?></button>
<?php if ($editQuestion) : ?>
                      <a class="btn btn-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $selectedCourseId, ENT_QUOTES, 'UTF-8'); ?>">Cancel Edit</a>
<?php endif; ?>
                    </div>
                  </form>
<?php endif; ?>
                </div>
              </div>

<?php if ($selectedCourseId && isset($assignedSet[(int) $selectedCourseId])) : ?>
              <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Question List</h5>
                  <span class="badge bg-info"><?php echo $questionCount; ?> question(s)</span>
                </div>
                <div class="card-body">
<?php if ($courseQuestions === []) : ?>
                  <p class="mb-0">No questions yet for this course.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Attached To</th>
                          <th>Question</th>
                          <th>Type</th>
                          <th>Points</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($courseQuestions as $q) : ?>
<?php $typeLabel = clms_question_type_label((string) $q['question_type']); ?>
                        <tr
                          data-search-item
                          data-search-text="<?php echo htmlspecialchars(((string) $q['question_text']) . ' ' . $typeLabel . ' ' . ($q['module_id'] !== null ? (string) $q['module_title'] : 'Final Exam'), ENT_QUOTES, 'UTF-8'); ?>">
                          <td><?php echo (int) $q['id']; ?></td>
                          <td>
<?php if ($q['module_id'] !== null) : ?>
                            <span class="badge bg-label-info"><?php echo htmlspecialchars((string) $q['module_title'], ENT_QUOTES, 'UTF-8'); ?></span>
<?php else : ?>
                            <span class="badge bg-label-secondary">Final Exam</span>
<?php endif; ?>
                          </td>
                          <td><?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><span class="badge bg-label-primary"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                          <td><?php echo number_format((float) $q['points'], 2); ?></td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <a
                                class="btn btn-sm btn-warning edit-question-btn"
                                href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $selectedCourseId . '&edit=' . (int) $q['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-question-id="<?php echo (int) $q['id']; ?>"
                                data-question-text="<?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="bx bx-edit-alt"></i> Edit
                              </a>
                              <a
                                class="btn btn-sm btn-danger delete-question-btn"
                                href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $selectedCourseId . '&delete=' . (int) $q['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-question-text="<?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="bx bx-trash"></i> Delete
                              </a>
                            </div>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
              </div>
<?php endif; ?>

<?php if ($assignedCourses !== []) : ?>
              <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div class="min-width-0">
                    <h5 class="mb-0">Module Videos in My Scope</h5>
<?php if ($hasSelectedCourse && $selectedCourseTitle !== null) : ?>
                    <small class="text-muted d-block mt-1">
                      Showing modules for <strong><?php echo htmlspecialchars($selectedCourseTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </small>
<?php endif; ?>
                  </div>
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#moduleVideoModal" data-action="add">
                    <i class="bx bx-plus me-1"></i> Add Module Video
                  </button>
                </div>
                <div class="card-body">
<?php if (!$hasSelectedCourse) : ?>
                  <p class="mb-0 text-muted">Select one of your assigned courses above to see its module videos.</p>
<?php elseif ($instructorModules === []) : ?>
                  <p class="mb-0">No modules yet for this course. Click <strong>Add Module Video</strong> to create the first one.</p>
<?php else : ?>
                  <div class="table-responsive text-nowrap">
                    <table class="table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Module</th>
                          <th>Video URL</th>
                          <th>Duration</th>
                          <th>Order</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($instructorModules as $mod) : ?>
                        <tr>
                          <td><?php echo (int) $mod['id']; ?></td>
                          <td><?php echo htmlspecialchars((string) $mod['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="text-truncate" style="max-width: 280px;"><?php echo htmlspecialchars((string) $mod['video_url'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><?php echo (int) $mod['duration_minutes']; ?> min</td>
                          <td><?php echo (int) $mod['sequence_order']; ?></td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button type="button"
                                class="btn btn-sm btn-warning module-video-edit-btn"
                                data-bs-toggle="modal"
                                data-bs-target="#moduleVideoModal"
                                data-action="edit"
                                data-module-id="<?php echo (int) $mod['id']; ?>"
                                data-course-id="<?php echo (int) $mod['course_id']; ?>"
                                data-module-title="<?php echo htmlspecialchars((string) $mod['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-video-url="<?php echo htmlspecialchars((string) $mod['video_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-duration="<?php echo (int) $mod['duration_minutes']; ?>"
                                data-sequence="<?php echo (int) $mod['sequence_order']; ?>">
                                <i class="bx bx-edit-alt"></i> Edit
                              </button>
                              <form method="post"
                                action="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php' . ($selectedCourseId ? '?course_id=' . (int) $selectedCourseId : ''), ENT_QUOTES, 'UTF-8'); ?>"
                                class="d-inline js-delete-module-form"
                                data-module-title="<?php echo htmlspecialchars((string) $mod['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-course-title="<?php echo htmlspecialchars((string) $mod['course_title'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="delete_video" />
                                <input type="hidden" name="module_id" value="<?php echo (int) $mod['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-danger">
                                  <i class="bx bx-trash"></i> Delete
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
              </div>

              <div class="modal fade" id="moduleVideoModal" tabindex="-1" aria-labelledby="moduleVideoModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                  <div class="modal-content">
                    <form id="moduleVideoForm" method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php' . ($selectedCourseId ? '?course_id=' . (int) $selectedCourseId : ''), ENT_QUOTES, 'UTF-8'); ?>" data-mode="add">
                      <div class="modal-header">
                        <h5 class="modal-title" id="moduleVideoModalLabel">Add Module Video</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="save_video" />
                        <input type="hidden" name="module_id" id="module_video_id" value="" />

                        <div class="mb-3">
                          <label class="form-label" for="module_video_course_id">Course</label>
                          <select class="form-select" id="module_video_course_id" name="course_id" required>
                            <option value="">Select assigned course</option>
<?php foreach ($assignedCourses as $course) : ?>
                            <option value="<?php echo (int) $course['id']; ?>"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                          </select>
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="module_video_title">Module Title</label>
                          <input class="form-control" type="text" id="module_video_title" name="module_title" required />
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="module_video_url">Video URL</label>
                          <input class="form-control" type="url" id="module_video_url" name="video_url" required
                            placeholder="https://www.youtube.com/watch?v=VIDEO_ID" />
                          <small class="text-muted d-block mt-1">
                            Accepted: YouTube <code>watch?v=</code>, <code>youtu.be</code>, <code>/shorts/</code>, Vimeo, or direct <code>.mp4/.webm</code> files. YouTube search, playlist, and channel pages are not supported.
                          </small>
                        </div>
                        <div class="row">
                          <div class="col-md-6 mb-3">
                            <label class="form-label" for="module_video_duration">Duration (minutes)</label>
                            <input class="form-control" type="number" min="0" id="module_video_duration" name="duration_minutes" value="0" required />
                          </div>
                          <div class="col-md-6 mb-3">
                            <label class="form-label" for="module_video_sequence">Sequence Order</label>
                            <input class="form-control" type="number" min="1" id="module_video_sequence" name="sequence_order" value="1" required />
                          </div>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Module</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
<?php endif; ?>
              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  const typeSelect = document.getElementById('questionTypeSelect');
                  const multipleSelectBlock = document.getElementById('multipleSelectBlock');
                  const trueFalseBlock = document.getElementById('trueFalseBlock');
                  const fillBlankBlock = document.getElementById('fillBlankBlock');
                  const sequencingBlock = document.getElementById('sequencingBlock');
                  const answersBlock = document.getElementById('answersBlock');
                  if (!typeSelect || !answersBlock || !multipleSelectBlock || !trueFalseBlock || !fillBlankBlock || !sequencingBlock) return;

                  const rows = [...answersBlock.querySelectorAll('.answer-row')];
                  const correctInputs = [...answersBlock.querySelectorAll('.correct-input')];
                  const sequenceCols = [...answersBlock.querySelectorAll('.sequence-col')];
                  const sequenceInputs = [...answersBlock.querySelectorAll('.sequence-input')];

                  const syncUi = () => {
                    const type = typeSelect.value;
                    const isMultipleSelect = type === 'multiple_select';
                    const isTrueFalse = type === 'true_false';
                    const isFillBlank = type === 'fill_blank';
                    const isSequencing = type === 'sequencing';

                    multipleSelectBlock.style.display = isMultipleSelect ? '' : 'none';
                    trueFalseBlock.style.display = isTrueFalse ? '' : 'none';
                    fillBlankBlock.style.display = isFillBlank ? '' : 'none';
                    sequencingBlock.style.display = isSequencing ? '' : 'none';
                    answersBlock.style.display = (isMultipleSelect || isTrueFalse || isFillBlank || isSequencing) ? 'none' : '';
                    rows.forEach((row) => {
                      row.style.display = '';
                    });
                    sequenceCols.forEach((col) => {
                      col.style.display = isSequencing ? '' : 'none';
                    });
                    sequenceInputs.forEach((input) => {
                      if (!isSequencing) input.value = '';
                    });
                  };

                  correctInputs.forEach((input) => {
                    input.addEventListener('change', () => {
                      const type = typeSelect.value;
                      const isSingle = type === 'true_false' || type === 'multiple_select';
                      if (!isSingle || !input.checked) return;
                      correctInputs.forEach((other) => {
                        if (other !== input) other.checked = false;
                      });
                    });
                  });

                  syncUi();
                  typeSelect.addEventListener('change', syncUi);
                })();

                (() => {
                  const wrap = document.getElementById('sequencingItemsWrap');
                  const addBtn = document.getElementById('sequencingAddItemBtn');
                  if (!wrap || !addBtn) return;

                  const createRow = () => {
                    const row = document.createElement('div');
                    row.className = 'input-group sequencing-item-row';
                    row.setAttribute('draggable', 'true');
                    row.innerHTML = '<span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span><input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" /><button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>';
                    return row;
                  };

                  addBtn.addEventListener('click', () => {
                    wrap.appendChild(createRow());
                  });

                  wrap.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement) || !target.classList.contains('sequencing-delete-item')) return;
                    const rows = wrap.querySelectorAll('.sequencing-item-row');
                    if (rows.length <= 2) {
                      const input = target.closest('.sequencing-item-row')?.querySelector('.sequencing-item-input');
                      if (input instanceof HTMLInputElement) input.value = '';
                      return;
                    }
                    target.closest('.sequencing-item-row')?.remove();
                  });

                  let draggingRow = null;
                  wrap.addEventListener('dragstart', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const row = target.closest('.sequencing-item-row');
                    if (!(row instanceof HTMLElement)) return;
                    draggingRow = row;
                    row.classList.add('opacity-50');
                  });

                  wrap.addEventListener('dragend', () => {
                    if (draggingRow instanceof HTMLElement) draggingRow.classList.remove('opacity-50');
                    draggingRow = null;
                  });

                  wrap.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    const target = event.target;
                    if (!(target instanceof HTMLElement) || !(draggingRow instanceof HTMLElement)) return;
                    const row = target.closest('.sequencing-item-row');
                    if (!(row instanceof HTMLElement) || row === draggingRow) return;
                    const rect = row.getBoundingClientRect();
                    const shouldInsertBefore = event.clientY < rect.top + rect.height / 2;
                    if (shouldInsertBefore) {
                      wrap.insertBefore(draggingRow, row);
                    } else {
                      wrap.insertBefore(draggingRow, row.nextSibling);
                    }
                  });
                })();

                (() => {
                  const addBtn = document.getElementById('fillBlankAddAlternativeBtn');
                  const wrap = document.getElementById('fillBlankAlternativesWrap');
                  if (!addBtn || !wrap) return;

                  const removeRow = (button) => {
                    const rows = wrap.querySelectorAll('.fill-blank-alt-row');
                    if (rows.length <= 1) {
                      const input = rows[0]?.querySelector('input[name="fill_blank_alternatives[]"]');
                      if (input) input.value = '';
                      return;
                    }
                    button.closest('.fill-blank-alt-row')?.remove();
                  };

                  wrap.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    if (!target.classList.contains('fill-blank-remove-alt')) return;
                    removeRow(target);
                  });

                  addBtn.addEventListener('click', () => {
                    const row = document.createElement('div');
                    row.className = 'input-group mb-2 fill-blank-alt-row';
                    row.innerHTML = '<input class="form-control" type="text" name="fill_blank_alternatives[]" placeholder="Alternative answer" /><button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>';
                    wrap.appendChild(row);
                  });
                })();

                (() => {
                  const modalEl = document.getElementById('moduleVideoModal');
                  if (!modalEl) return;
                  const titleEl = document.getElementById('moduleVideoModalLabel');
                  const idInput = document.getElementById('module_video_id');
                  const courseInput = document.getElementById('module_video_course_id');
                  const moduleTitleInput = document.getElementById('module_video_title');
                  const videoUrlInput = document.getElementById('module_video_url');
                  const durationInput = document.getElementById('module_video_duration');
                  const sequenceInput = document.getElementById('module_video_sequence');

                  const formEl = document.getElementById('moduleVideoForm');

                  modalEl.addEventListener('show.bs.modal', (event) => {
                    const trigger = event.relatedTarget;
                    const action = trigger && trigger.getAttribute ? trigger.getAttribute('data-action') : 'add';

                    if (action === 'edit' && trigger) {
                      if (titleEl) titleEl.textContent = 'Update Module Video';
                      if (idInput) idInput.value = trigger.getAttribute('data-module-id') || '';
                      if (courseInput) courseInput.value = trigger.getAttribute('data-course-id') || '';
                      if (moduleTitleInput) moduleTitleInput.value = trigger.getAttribute('data-module-title') || '';
                      if (videoUrlInput) videoUrlInput.value = trigger.getAttribute('data-video-url') || '';
                      if (durationInput) durationInput.value = trigger.getAttribute('data-duration') || '0';
                      if (sequenceInput) sequenceInput.value = trigger.getAttribute('data-sequence') || '1';
                      if (formEl) formEl.dataset.mode = 'edit';
                    } else {
                      if (titleEl) titleEl.textContent = 'Add Module Video';
                      if (idInput) idInput.value = '';
                      if (courseInput) courseInput.value = '';
                      if (moduleTitleInput) moduleTitleInput.value = '';
                      if (videoUrlInput) videoUrlInput.value = '';
                      if (durationInput) durationInput.value = '0';
                      if (sequenceInput) sequenceInput.value = '1';
                      if (formEl) formEl.dataset.mode = 'add';
                    }
                    if (formEl) formEl.dataset.confirmed = '0';
                  });
                })();

                (() => {
                  if (typeof Swal === 'undefined') return;

                  ClmsNotify.fromFlash(
                    <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                    <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                  );

                  const moduleForm = document.getElementById('moduleVideoForm');
                  const moduleModalEl = document.getElementById('moduleVideoModal');

                  const hideModuleModal = () => {
                    if (!moduleModalEl) return;
                    moduleModalEl.classList.remove('show');
                    moduleModalEl.style.display = 'none';
                    moduleModalEl.setAttribute('aria-hidden', 'true');
                    moduleModalEl.removeAttribute('aria-modal');
                    moduleModalEl.removeAttribute('role');
                    if (typeof bootstrap !== 'undefined') {
                      const instance = bootstrap.Modal.getInstance(moduleModalEl);
                      if (instance) {
                        try { instance.dispose(); } catch (e) { /* ignore */ }
                      }
                    }
                    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                  };

                  if (moduleForm) {
                    moduleForm.addEventListener('submit', () => {
                      hideModuleModal();
                    });
                  }

                  document.querySelectorAll('.js-delete-module-form').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                      if (form.dataset.confirmed === '1') return;
                      event.preventDefault();
                      const moduleTitle = form.dataset.moduleTitle || 'this module';
                      const courseTitle = form.dataset.courseTitle || '';
                      Swal.fire({
                        title: 'Delete "' + moduleTitle + '"?',
                        html: (courseTitle ? '<div class="mb-2 text-muted small">From course: <strong>' + courseTitle + '</strong></div>' : '')
                          + 'All of this module\'s <strong>questions, answers, quiz attempts, and student progress</strong> will be permanently removed. This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          form.dataset.confirmed = '1';
                          form.submit();
                        }
                      });
                    });
                  });
                })();

                (() => {
                  if (typeof Swal === 'undefined') return;

                  const truncate = (text, max = 120) => {
                    if (!text) return '';
                    return text.length > max ? text.slice(0, max - 1) + '…' : text;
                  };

                  document.querySelectorAll('.delete-question-btn').forEach((button) => {
                    button.addEventListener('click', (event) => {
                      event.preventDefault();
                      const deleteUrl = button.getAttribute('href');
                      if (!deleteUrl) return;
                      const questionText = button.getAttribute('data-question-text') || '';

                      Swal.fire({
                        title: 'Delete this question?',
                        html: (questionText ? '<div class="mb-2 text-muted fst-italic">"' + truncate(questionText) + '"</div>' : '')
                          + 'The question, all of its answer choices, and any student responses will be permanently removed. This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          window.location.href = deleteUrl;
                        }
                      });
                    });
                  });

                  document.querySelectorAll('.edit-question-btn').forEach((button) => {
                    button.addEventListener('click', (event) => {
                      event.preventDefault();
                      const editUrl = button.getAttribute('href');
                      if (!editUrl) return;
                      const questionText = button.getAttribute('data-question-text') || '';

                      Swal.fire({
                        title: 'Edit this question?',
                        html: (questionText ? '<div class="mb-2 text-muted fst-italic">"' + truncate(questionText) + '"</div>' : '')
                          + 'The Question Builder form will load this question so you can update it.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, load editor',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          window.location.href = editUrl;
                        }
                      });
                    });
                  });
                })();
              </script>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';
