<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['student']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ═══════════════════════════════════════════════════════════════════
   POST — grade and redirect (Post/Redirect/Get)
═══════════════════════════════════════════════════════════════════ */
if ($method === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        clms_redirect('student/dashboard.php');
    }

    $courseId  = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    $attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
    $rawResponses = $_POST['responses'] ?? [];

    if (
        $courseId === false || $courseId === null || $courseId <= 0
        || $attemptId === false || $attemptId === null || $attemptId <= 0
    ) {
        clms_redirect('student/dashboard.php');
    }
    if (!is_array($rawResponses)) {
        $rawResponses = [];
    }

    // Verify attempt ownership and status
    $attemptStmt = $pdo->prepare(
        'SELECT id, question_order FROM mock_exam_attempts
         WHERE id = :aid AND user_id = :uid AND course_id = :cid AND status = \'in_progress\'
         LIMIT 1'
    );
    $attemptStmt->execute(['aid' => (int) $attemptId, 'uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
    $attempt = $attemptStmt->fetch();

    if (!$attempt) {
        // Already graded — show stored result
        clms_redirect('student/grade_mock_exam.php?attempt_id=' . (int) $attemptId . '&course_id=' . (int) $courseId);
    }

    // Load questions with correct answers
    $qStmt = $pdo->prepare(
        'SELECT q.id, q.question_type, q.points,
                a.id AS answer_id, a.answer_text, a.is_correct, a.sequence_position
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id
         WHERE q.course_id = :cid AND q.module_id IS NOT NULL
         ORDER BY q.id ASC, a.id ASC'
    );
    $qStmt->execute(['cid' => (int) $courseId]);
    $questions = [];
    foreach ($qStmt->fetchAll() as $row) {
        $qId = (int) $row['id'];
        if (!isset($questions[$qId])) {
            $questions[$qId] = ['id' => $qId, 'question_type' => (string) $row['question_type'], 'points' => (float) $row['points'], 'answers' => []];
        }
        if ($row['answer_id'] !== null) {
            $questions[$qId]['answers'][] = [
                'id' => (int) $row['answer_id'],
                'answer_text' => (string) $row['answer_text'],
                'is_correct' => (bool) $row['is_correct'],
                'sequence_position' => $row['sequence_position'] !== null ? (int) $row['sequence_position'] : null,
            ];
        }
    }

    // If no POST responses, rebuild from autosave
    if ($rawResponses === []) {
        $savedStmt = $pdo->prepare(
            'SELECT question_id, selected_answer_id, text_response, submitted_sequence_position
             FROM mock_exam_responses WHERE attempt_id = :aid'
        );
        $savedStmt->execute(['aid' => (int) $attemptId]);
        foreach ($savedStmt->fetchAll() as $sr) {
            $qid  = (int) $sr['question_id'];
            $type = $questions[$qid]['question_type'] ?? null;
            if ($type === null) {
                continue;
            }
            if (!isset($rawResponses[$qid])) {
                $rawResponses[$qid] = [];
            }
            if ($type === 'true_false' || $type === 'multiple_select') {
                if ($sr['selected_answer_id'] !== null) {
                    $rawResponses[$qid]['single'] = (int) $sr['selected_answer_id'];
                }
            } elseif ($type === 'sequencing') {
                if ($sr['selected_answer_id'] !== null && $sr['submitted_sequence_position'] !== null) {
                    if (!isset($rawResponses[$qid]['sequence'])) {
                        $rawResponses[$qid]['sequence'] = [];
                    }
                    $rawResponses[$qid]['sequence'][(int) $sr['selected_answer_id']] = (int) $sr['submitted_sequence_position'];
                }
            } elseif ($type === 'fill_blank') {
                if ($sr['text_response'] !== null && $sr['text_response'] !== '') {
                    $rawResponses[$qid]['text'] = (string) $sr['text_response'];
                }
            }
        }
    }

    // Grade
    $totalPossible = 0.0;
    $totalAwarded  = 0.0;
    foreach ($questions as $q) {
        $qId      = (int) $q['id'];
        $type     = $q['question_type'];
        $points   = (float) $q['points'];
        $answers  = $q['answers'];
        $submitted = $rawResponses[(string) $qId] ?? $rawResponses[$qId] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }
        $totalPossible += $points;
        $awarded = 0.0;

        if ($type === 'true_false' || $type === 'multiple_select') {
            $selected = filter_var($submitted['single'] ?? null, FILTER_VALIDATE_INT);
            $correctIds = array_map(static fn(array $a): int => (int) $a['id'], array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
            if ($selected !== false && $selected !== null && in_array((int) $selected, $correctIds, true)) {
                $awarded = $points;
            }
        } elseif ($type === 'fill_blank') {
            $text = trim((string) ($submitted['text'] ?? ''));
            $correctAnswers = array_map(static fn(array $a): string => trim((string) $a['answer_text']), array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
            foreach ($correctAnswers as $correct) {
                if ($text !== '' && mb_strtolower($text) === mb_strtolower($correct)) {
                    $awarded = $points;
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
            foreach ($seq as $ansIdRaw => $posRaw) {
                $ansId = filter_var($ansIdRaw, FILTER_VALIDATE_INT);
                $pos   = filter_var($posRaw, FILTER_VALIDATE_INT);
                if ($ansId !== false && $ansId !== null && $pos !== false && $pos !== null && $pos > 0) {
                    $submittedMap[(int) $ansId] = (int) $pos;
                }
            }
            ksort($submittedMap);
            if ($submittedMap === $dbMap && $submittedMap !== []) {
                $awarded = $points;
            }
        }
        $totalAwarded += $awarded;
    }

    $percentage = $totalPossible > 0 ? ($totalAwarded / $totalPossible) * 100 : 0.0;
    $isPassed   = $percentage >= 70.0 ? 1 : 0;

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE mock_exam_attempts
             SET status = \'completed\', total_score = :score, total_possible = :possible,
                 percentage = :pct, is_passed = :passed, completed_at = NOW()
             WHERE id = :aid AND user_id = :uid'
        )->execute([
            'score'   => number_format($totalAwarded, 2, '.', ''),
            'possible' => number_format($totalPossible, 2, '.', ''),
            'pct'     => number_format($percentage, 2, '.', ''),
            'passed'  => $isPassed,
            'aid'     => (int) $attemptId,
            'uid'     => (int) $_SESSION['user_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('grade_mock_exam: ' . $e->getMessage());
        clms_redirect('student/dashboard.php?notice=exam_error');
    }

    clms_redirect('student/grade_mock_exam.php?attempt_id=' . (int) $attemptId . '&course_id=' . (int) $courseId);
}

/* ═══════════════════════════════════════════════════════════════════
   GET — render stored result
═══════════════════════════════════════════════════════════════════ */
$courseId  = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT);
$autoSubmitted = (filter_input(INPUT_GET, 'auto', FILTER_VALIDATE_INT) === 1);

if (
    $courseId === false || $courseId === null || $courseId <= 0
    || $attemptId === false || $attemptId === null || $attemptId <= 0
) {
    clms_redirect('student/dashboard.php');
}

// If still in_progress (auto-finalize path), grade it now
$attemptStmt = $pdo->prepare(
    'SELECT id, status, total_score, total_possible, percentage, is_passed, question_order
     FROM mock_exam_attempts
     WHERE id = :aid AND user_id = :uid AND course_id = :cid LIMIT 1'
);
$attemptStmt->execute(['aid' => (int) $attemptId, 'uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
$attempt = $attemptStmt->fetch();

if (!$attempt) {
    clms_redirect('student/dashboard.php');
}

if ((string) $attempt['status'] === 'in_progress') {
    // Auto-finalize via POST redirect
    clms_redirect('student/grade_mock_exam.php?attempt_id=' . (int) $attemptId . '&course_id=' . (int) $courseId . '&auto=1');
}

$totalAwarded  = (float) $attempt['total_score'];
$totalPossible = (float) $attempt['total_possible'];
$percentage    = (float) $attempt['percentage'];
$isPassed      = (bool) $attempt['is_passed'];

// Load questions in the order they were shown, with correct answers for feedback
$qStmt = $pdo->prepare(
    'SELECT q.id, q.question_text, q.question_type, q.points,
            a.id AS answer_id, a.answer_text, a.is_correct, a.sequence_position
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.course_id = :cid AND q.module_id IS NOT NULL
     ORDER BY q.id ASC, a.id ASC'
);
$qStmt->execute(['cid' => (int) $courseId]);
$allQuestions = [];
foreach ($qStmt->fetchAll() as $row) {
    $qId = (int) $row['id'];
    if (!isset($allQuestions[$qId])) {
        $allQuestions[$qId] = ['id' => $qId, 'question_text' => (string) $row['question_text'], 'question_type' => (string) $row['question_type'], 'points' => (float) $row['points'], 'answers' => []];
    }
    if ($row['answer_id'] !== null) {
        $allQuestions[$qId]['answers'][] = [
            'id' => (int) $row['answer_id'],
            'answer_text' => (string) $row['answer_text'],
            'is_correct' => (bool) $row['is_correct'],
            'sequence_position' => $row['sequence_position'] !== null ? (int) $row['sequence_position'] : null,
        ];
    }
}

// Restore original question order
$orderedIds = [];
$qOrderRaw = (string) ($attempt['question_order'] ?? '');
if ($qOrderRaw !== '') {
    $decoded = json_decode($qOrderRaw, true);
    if (is_array($decoded)) {
        $orderedIds = array_map('intval', $decoded);
    }
}
if ($orderedIds === []) {
    $orderedIds = array_keys($allQuestions);
}
$orderedIds = array_values(array_filter($orderedIds, static fn(int $id): bool => isset($allQuestions[$id])));

// Load student's submitted responses
$respStmt = $pdo->prepare(
    'SELECT question_id, selected_answer_id, text_response, submitted_sequence_position
     FROM mock_exam_responses WHERE attempt_id = :aid'
);
$respStmt->execute(['aid' => (int) $attemptId]);
$submittedSelectedIds = [];
$submittedTexts       = [];
$submittedSequence    = [];
foreach ($respStmt->fetchAll() as $sr) {
    $qid = (int) $sr['question_id'];
    if ($sr['selected_answer_id'] !== null && $sr['submitted_sequence_position'] !== null) {
        $submittedSequence[$qid][(int) $sr['selected_answer_id']] = (int) $sr['submitted_sequence_position'];
    } elseif ($sr['selected_answer_id'] !== null) {
        $submittedSelectedIds[$qid][] = (int) $sr['selected_answer_id'];
    } elseif ($sr['text_response'] !== null) {
        $submittedTexts[$qid] = (string) $sr['text_response'];
    }
}

$courseStmt = $pdo->prepare('SELECT title FROM courses WHERE id = :id LIMIT 1');
$courseStmt->execute(['id' => (int) $courseId]);
$courseTitle = (string) ($courseStmt->fetchColumn() ?: '');

$pageTitle         = 'Mock Exam Result | Criminology LMS';
$activeStudentPage = 'dashboard';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <h4 class="fw-bold py-3 mb-3">Mock Exam Result</h4>

<?php if ($autoSubmitted) : ?>
              <div class="alert alert-warning mb-3" role="alert">
                <i class="bx bx-time me-1"></i><strong>Time's up!</strong> Your mock exam was automatically submitted.
              </div>
<?php endif; ?>

              <div class="card mb-4">
                <div class="card-body text-center py-5">
                  <?php if ($isPassed) : ?>
                    <i class="bx bx-check-circle text-success" style="font-size: 4rem;"></i>
                    <h4 class="mt-2 mb-1 text-success">Mock Exam Passed!</h4>
                    <p class="text-muted mb-3">You've unlocked the <strong>Final Exam</strong>. Good luck!</p>
                  <?php else : ?>
                    <i class="bx bx-error-circle text-warning" style="font-size: 4rem;"></i>
                    <h4 class="mt-2 mb-1 text-warning">Not Quite</h4>
                    <p class="text-muted mb-3">You need at least <strong>70%</strong> to unlock the final exam. Review the feedback below and try again.</p>
                  <?php endif; ?>

                  <div class="display-4 fw-bold my-3 <?php echo $isPassed ? 'text-success' : 'text-warning'; ?>">
                    <?php echo number_format($percentage, 1); ?>%
                  </div>
                  <p class="mb-1">
                    Score: <strong><?php echo number_format($totalAwarded, 2); ?> / <?php echo number_format($totalPossible, 2); ?></strong> points
                  </p>
                  <p class="text-muted small mb-4"><?php echo htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8'); ?></p>

                  <div class="d-flex flex-wrap justify-content-center gap-2">
                    <?php if ($isPassed) : ?>
                      <a class="btn btn-success" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_exam.php?course_id=' . (int) $courseId, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-certification me-1"></i>Take Final Exam
                      </a>
                    <?php else : ?>
                      <a class="btn btn-warning text-white" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_mock_exam.php?course_id=' . (int) $courseId, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-refresh me-1"></i>Retake Mock Exam
                      </a>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bx bx-grid-alt me-1"></i>Dashboard
                    </a>
                  </div>
                </div>
              </div>

              <h5 class="fw-semibold mb-3">Question Review</h5>

<?php foreach ($orderedIds as $index => $qId) :
    $q = $allQuestions[$qId];
    $type = $q['question_type'];
    $answers = $q['answers'];
    $selIds  = $submittedSelectedIds[$qId] ?? [];
    $selText = $submittedTexts[$qId] ?? '';
    $selSeq  = $submittedSequence[$qId] ?? [];

    // Determine correctness for feedback
    $awarded = 0.0;
    if ($type === 'true_false' || $type === 'multiple_select') {
        $correctIds = array_map(static fn(array $a): int => (int) $a['id'], array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
        if ($selIds !== [] && in_array($selIds[0], $correctIds, true)) {
            $awarded = $q['points'];
        }
    } elseif ($type === 'fill_blank') {
        $correctAnswers = array_map(static fn(array $a): string => trim((string) $a['answer_text']), array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
        foreach ($correctAnswers as $correct) {
            if ($selText !== '' && mb_strtolower($selText) === mb_strtolower($correct)) {
                $awarded = $q['points'];
                break;
            }
        }
    } elseif ($type === 'sequencing') {
        $dbMap = [];
        foreach ($answers as $a) {
            if ($a['sequence_position'] !== null) {
                $dbMap[(int) $a['id']] = (int) $a['sequence_position'];
            }
        }
        ksort($dbMap);
        $subMap = $selSeq;
        ksort($subMap);
        if ($subMap === $dbMap && $subMap !== []) {
            $awarded = $q['points'];
        }
    }
    $isCorrect = $awarded >= $q['points'] && $q['points'] > 0;
?>
              <div class="card mb-3 border-start border-3 <?php echo $isCorrect ? 'border-success' : 'border-danger'; ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <strong>Question <?php echo $index + 1; ?></strong>
                  <div>
                    <span class="badge bg-label-primary"><?php echo number_format((float) $q['points'], 2); ?> pts</span>
                    <span class="badge <?php echo $isCorrect ? 'bg-label-success' : 'bg-label-danger'; ?> ms-1">
                      <?php echo $isCorrect ? 'Correct' : 'Incorrect'; ?>
                    </span>
                  </div>
                </div>
                <div class="card-body">
                  <p class="mb-3"><?php echo nl2br(htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8')); ?></p>

<?php if ($type === 'true_false' || $type === 'multiple_select') :
    $correctIds = array_map(static fn(array $a): int => (int) $a['id'], array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
?>
<?php foreach ($answers as $ans) :
    $wasSelected = in_array((int) $ans['id'], $selIds, true);
    $isCorrectAns = in_array((int) $ans['id'], $correctIds, true);
    $labelClass = '';
    if ($wasSelected && $isCorrectAns) {
        $labelClass = 'text-success fw-semibold';
    } elseif ($wasSelected && !$isCorrectAns) {
        $labelClass = 'text-danger';
    } elseif ($isCorrectAns) {
        $labelClass = 'text-success';
    }
?>
                  <div class="d-flex align-items-center gap-2 mb-2">
                    <?php if ($wasSelected && $isCorrectAns) : ?><i class="bx bx-check-circle text-success"></i>
                    <?php elseif ($wasSelected && !$isCorrectAns) : ?><i class="bx bx-x-circle text-danger"></i>
                    <?php elseif ($isCorrectAns) : ?><i class="bx bx-check text-success"></i>
                    <?php else : ?><i class="bx bx-minus text-muted"></i><?php endif; ?>
                    <span class="<?php echo $labelClass; ?>">
                      <?php echo htmlspecialchars((string) $ans['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                      <?php if ($isCorrectAns) : ?><small class="text-success ms-1">(correct)</small><?php endif; ?>
                    </span>
                  </div>
<?php endforeach; ?>

<?php elseif ($type === 'fill_blank') :
    $correctAnswers = array_map(static fn(array $a): string => trim((string) $a['answer_text']), array_values(array_filter($answers, static fn(array $a): bool => (bool) $a['is_correct'])));
?>
                  <p class="mb-1">Your answer: <strong class="<?php echo $isCorrect ? 'text-success' : 'text-danger'; ?>"><?php echo $selText !== '' ? htmlspecialchars($selText, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">No answer</em>'; ?></strong></p>
                  <p class="mb-0 text-success">Correct answer: <strong><?php echo htmlspecialchars($correctAnswers[0] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>

<?php elseif ($type === 'sequencing') :
    $dbMap = [];
    foreach ($answers as $a) {
        if ($a['sequence_position'] !== null) {
            $dbMap[(int) $a['id']] = (int) $a['sequence_position'];
        }
    }
    // Build answer text lookup
    $ansTextById = [];
    foreach ($answers as $a) {
        $ansTextById[(int) $a['id']] = (string) $a['answer_text'];
    }
    asort($dbMap); // sort by correct position
?>
                  <div class="row g-2">
                    <div class="col-md-6">
                      <small class="text-muted d-block mb-1">Your order:</small>
                      <?php
                        $yourOrder = $selSeq;
                        asort($yourOrder);
                        $pos = 1;
                        foreach ($yourOrder as $ansId => $submittedPos) :
                      ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                          <span class="badge bg-label-secondary"><?php echo $submittedPos; ?></span>
                          <span><?php echo htmlspecialchars($ansTextById[$ansId] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                      <?php endforeach; ?>
                      <?php if ($yourOrder === []) : ?><em class="text-muted small">No answer</em><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                      <small class="text-success d-block mb-1">Correct order:</small>
                      <?php foreach ($dbMap as $ansId => $correctPos) : ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                          <span class="badge bg-label-success"><?php echo $correctPos; ?></span>
                          <span><?php echo htmlspecialchars($ansTextById[$ansId] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
<?php endif; ?>
                </div>
              </div>
<?php endforeach; ?>

              <div class="d-flex flex-wrap gap-2 mt-4 mb-4">
                <?php if ($isPassed) : ?>
                  <a class="btn btn-success" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_exam.php?course_id=' . (int) $courseId, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx bx-certification me-1"></i>Take Final Exam
                  </a>
                <?php else : ?>
                  <a class="btn btn-warning text-white" href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_mock_exam.php?course_id=' . (int) $courseId, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx bx-refresh me-1"></i>Retake Mock Exam
                  </a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                  <i class="bx bx-grid-alt me-1"></i>Dashboard
                </a>
              </div>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';
