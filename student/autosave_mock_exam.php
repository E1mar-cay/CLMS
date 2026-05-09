<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function clms_mock_autosave_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    clms_require_roles(['student']);
} catch (Throwable $e) {
    clms_mock_autosave_respond(401, ['ok' => false, 'error' => 'not_authenticated']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clms_mock_autosave_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    clms_mock_autosave_respond(419, ['ok' => false, 'error' => 'csrf']);
}

$courseId  = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
$rawResponses = $_POST['responses'] ?? [];

if (
    $courseId === false || $courseId === null || $courseId <= 0
    || $attemptId === false || $attemptId === null || $attemptId <= 0
) {
    clms_mock_autosave_respond(400, ['ok' => false, 'error' => 'bad_request']);
}
if (!is_array($rawResponses)) {
    $rawResponses = [];
}

$attemptStmt = $pdo->prepare(
    'SELECT id FROM mock_exam_attempts
     WHERE id = :aid AND user_id = :uid AND course_id = :cid AND status = \'in_progress\'
     LIMIT 1'
);
$attemptStmt->execute([
    'aid' => (int) $attemptId,
    'uid' => (int) $_SESSION['user_id'],
    'cid' => (int) $courseId,
]);
if (!$attemptStmt->fetch()) {
    clms_mock_autosave_respond(403, ['ok' => false, 'error' => 'attempt_not_active']);
}

// Load valid question/answer IDs for this course's module questions
$qStmt = $pdo->prepare(
    'SELECT q.id AS question_id, q.question_type, a.id AS answer_id
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.course_id = :cid AND q.module_id IS NOT NULL'
);
$qStmt->execute(['cid' => (int) $courseId]);
$questionTypes  = [];
$validAnswerIds = [];
foreach ($qStmt->fetchAll() as $row) {
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

    if ($type === 'true_false' || $type === 'multiple_select') {
        $selected = filter_var($submitted['single'] ?? null, FILTER_VALIDATE_INT);
        if ($selected !== false && $selected !== null && isset($validAnswerIds[$qid][(int) $selected])) {
            $responseRows[] = ['question_id' => $qid, 'selected_answer_id' => (int) $selected, 'text_response' => null, 'submitted_sequence_position' => null];
        }
    } elseif ($type === 'sequencing') {
        $seq = $submitted['sequence'] ?? [];
        if (!is_array($seq)) {
            $seq = [];
        }
        foreach ($seq as $ansIdRaw => $posRaw) {
            $ansId = filter_var($ansIdRaw, FILTER_VALIDATE_INT);
            $pos   = filter_var($posRaw, FILTER_VALIDATE_INT);
            if ($ansId === false || $ansId === null || $pos === false || $pos === null || $pos <= 0) {
                continue;
            }
            if (!isset($validAnswerIds[$qid][(int) $ansId])) {
                continue;
            }
            $responseRows[] = ['question_id' => $qid, 'selected_answer_id' => (int) $ansId, 'text_response' => null, 'submitted_sequence_position' => (int) $pos];
        }
    } elseif ($type === 'fill_blank') {
        $text = trim((string) ($submitted['text'] ?? ''));
        if ($text !== '') {
            if (mb_strlen($text) > 65000) {
                $text = mb_substr($text, 0, 65000);
            }
            $responseRows[] = ['question_id' => $qid, 'selected_answer_id' => null, 'text_response' => $text, 'submitted_sequence_position' => null];
        }
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM mock_exam_responses WHERE attempt_id = :aid')->execute(['aid' => (int) $attemptId]);
    if ($responseRows !== []) {
        $ins = $pdo->prepare(
            'INSERT INTO mock_exam_responses (attempt_id, question_id, selected_answer_id, text_response, submitted_sequence_position)
             VALUES (:aid, :qid, :sel, :txt, :seq)'
        );
        foreach ($responseRows as $r) {
            $ins->execute(['aid' => (int) $attemptId, 'qid' => (int) $r['question_id'], 'sel' => $r['selected_answer_id'], 'txt' => $r['text_response'], 'seq' => $r['submitted_sequence_position']]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('autosave_mock_exam: ' . $e->getMessage());
    clms_mock_autosave_respond(500, ['ok' => false, 'error' => 'server_error']);
}

clms_mock_autosave_respond(200, ['ok' => true, 'saved_count' => count($responseRows)]);
