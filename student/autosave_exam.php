<?php

declare(strict_types=1);

/**
 * Autosave endpoint for an in-progress exam attempt.
 *
 * Accepts the same POST shape as grade_exam.php (responses[<question_id>][...])
 * but never changes the attempt status and never runs grading. It simply
 * persists the student's current answers so a crash, refresh, or close
 * won't lose their work.
 *
 * Returns JSON. No HTML layout is emitted.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

/**
 * @param int $status
 * @param array<string,mixed> $payload
 */
function clms_autosave_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    clms_require_roles(['student']);
} catch (Throwable $e) {
    clms_autosave_respond(401, ['ok' => false, 'error' => 'not_authenticated']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clms_autosave_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    clms_autosave_respond(419, ['ok' => false, 'error' => 'csrf']);
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
$rawResponses = $_POST['responses'] ?? [];

if (
    $courseId === false || $courseId === null || $courseId <= 0
    || $attemptId === false || $attemptId === null || $attemptId <= 0
) {
    clms_autosave_respond(400, ['ok' => false, 'error' => 'bad_request']);
}
if (!is_array($rawResponses)) {
    $rawResponses = [];
}

/*
 * Authorisation: only the owning student, only for the right course, and
 * only while the attempt is still in_progress. Otherwise the autosave is
 * a no-op from the client's perspective.
 */
$attemptStmt = $pdo->prepare(
    'SELECT id
     FROM exam_attempts
     WHERE id = :attempt_id
       AND user_id = :user_id
       AND course_id = :course_id
       AND status = :status
     LIMIT 1'
);
$attemptStmt->execute([
    'attempt_id' => (int) $attemptId,
    'user_id' => (int) $_SESSION['user_id'],
    'course_id' => (int) $courseId,
    'status' => 'in_progress',
]);
if (!$attemptStmt->fetch()) {
    clms_autosave_respond(403, ['ok' => false, 'error' => 'attempt_not_active']);
}

/*
 * Pull the course's question set so we can validate answer IDs and reject
 * garbage/tampered input rather than blindly inserting whatever came in.
 */
$questionsStmt = $pdo->prepare(
    'SELECT q.id AS question_id, q.question_type, a.id AS answer_id
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.course_id = :course_id'
);
$questionsStmt->execute(['course_id' => (int) $courseId]);
$rows = $questionsStmt->fetchAll();

$questionTypes = [];   // qid => type
$validAnswerIds = [];  // qid => [ans_id => true]
foreach ($rows as $row) {
    $qid = (int) $row['question_id'];
    if (!isset($questionTypes[$qid])) {
        $questionTypes[$qid] = (string) $row['question_type'];
        $validAnswerIds[$qid] = [];
    }
    if ($row['answer_id'] !== null) {
        $validAnswerIds[$qid][(int) $row['answer_id']] = true;
    }
}

$responseRows = [];

foreach ($questionTypes as $qid => $type) {
    $submitted = $rawResponses[(string) $qid] ?? $rawResponses[$qid] ?? [];
    if (!is_array($submitted)) {
        $submitted = [];
    }

    if ($type === 'single_choice' || $type === 'true_false') {
        $selected = filter_var($submitted['single'] ?? null, FILTER_VALIDATE_INT);
        if ($selected !== false && $selected !== null && isset($validAnswerIds[$qid][(int) $selected])) {
            $responseRows[] = [
                'question_id' => $qid,
                'selected_answer_id' => (int) $selected,
                'text_response' => null,
                'submitted_sequence_position' => null,
            ];
        }
    } elseif ($type === 'multiple_select') {
        $submittedMulti = $submitted['multiple'] ?? [];
        if (!is_array($submittedMulti)) {
            $submittedMulti = [];
        }
        $seen = [];
        foreach ($submittedMulti as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id === null) {
                continue;
            }
            $id = (int) $id;
            if (!isset($validAnswerIds[$qid][$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $responseRows[] = [
                'question_id' => $qid,
                'selected_answer_id' => $id,
                'text_response' => null,
                'submitted_sequence_position' => null,
            ];
        }
    } elseif ($type === 'sequencing') {
        $submittedSeq = $submitted['sequence'] ?? [];
        if (!is_array($submittedSeq)) {
            $submittedSeq = [];
        }
        foreach ($submittedSeq as $answerIdRaw => $posRaw) {
            $answerId = filter_var($answerIdRaw, FILTER_VALIDATE_INT);
            $position = filter_var($posRaw, FILTER_VALIDATE_INT);
            if (
                $answerId === false || $answerId === null
                || $position === false || $position === null
                || $position <= 0
            ) {
                continue;
            }
            $answerId = (int) $answerId;
            if (!isset($validAnswerIds[$qid][$answerId])) {
                continue;
            }
            $responseRows[] = [
                'question_id' => $qid,
                'selected_answer_id' => $answerId,
                'text_response' => null,
                'submitted_sequence_position' => (int) $position,
            ];
        }
    } elseif ($type === 'fill_blank' || $type === 'essay') {
        $text = trim((string) ($submitted['text'] ?? ''));
        if ($text !== '') {
            // Guard against payloads larger than TEXT can hold.
            if (mb_strlen($text) > 65000) {
                $text = mb_substr($text, 0, 65000);
            }
            $responseRows[] = [
                'question_id' => $qid,
                'selected_answer_id' => null,
                'text_response' => $text,
                'submitted_sequence_position' => null,
            ];
        }
    }
}

/*
 * Replace-in-place: the simplest correct strategy for an autosave that
 * also mirrors what grade_exam.php does at final submit. is_graded=0 and
 * points_awarded=0 make it explicit that these rows are drafts.
 */
try {
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM student_responses WHERE attempt_id = :attempt_id');
    $deleteStmt->execute(['attempt_id' => (int) $attemptId]);

    if ($responseRows !== []) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO student_responses
                (attempt_id, question_id, selected_answer_id, text_response, submitted_sequence_position, is_graded, points_awarded)
             VALUES
                (:attempt_id, :question_id, :selected_answer_id, :text_response, :submitted_sequence_position, 0, 0.00)'
        );
        foreach ($responseRows as $r) {
            $insertStmt->execute([
                'attempt_id' => (int) $attemptId,
                'question_id' => (int) $r['question_id'],
                'selected_answer_id' => $r['selected_answer_id'],
                'text_response' => $r['text_response'],
                'submitted_sequence_position' => $r['submitted_sequence_position'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('autosave_exam: ' . $e->getMessage());
    clms_autosave_respond(500, ['ok' => false, 'error' => 'server_error']);
}

clms_autosave_respond(200, [
    'ok' => true,
    'saved_count' => count($responseRows),
    'saved_at' => date('c'),
]);
