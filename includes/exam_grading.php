<?php

declare(strict_types=1);

/**
 * Shared exam grading pipeline.
 *
 * Used by:
 *   - student/grade_exam.php  (manual submit; passes $rawResponses from $_POST)
 *   - student/take_exam.php   (server-side auto-finalize for expired attempts;
 *                              passes null so we grade whatever was autosaved)
 *
 * The function grades, writes student_responses, flips the attempt to
 * `completed`, and issues a certificate on pass. Essay responses are
 * stored with is_graded = 0 so instructors can still review them later
 * to bump a student's score, but the attempt itself finalises
 * immediately — there is no "awaiting review" limbo state. It never
 * emits HTML — callers render the result.
 */

/**
 * @param array|null $rawResponses POST-shaped responses (responses[qid][single],
 *                                 responses[qid][multiple][], responses[qid][text],
 *                                 responses[qid][sequence][answer_id])
 *                                 or null to rebuild from already-saved autosave rows.
 *
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   summary?:array<string,mixed>
 * }
 */
function clms_finalize_exam_attempt(
    PDO $pdo,
    int $attemptId,
    int $courseId,
    int $userId,
    ?array $rawResponses = null
): array {
    $attemptStmt = $pdo->prepare(
        'SELECT ea.id, ea.status, c.passing_score_percentage
         FROM exam_attempts ea
         INNER JOIN courses c ON c.id = ea.course_id
         WHERE ea.id = :attempt_id
           AND ea.user_id = :user_id
           AND ea.course_id = :course_id
         LIMIT 1'
    );
    $attemptStmt->execute([
        'attempt_id' => $attemptId,
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);
    $attempt = $attemptStmt->fetch();
    if (!$attempt) {
        return ['ok' => false, 'error' => 'attempt_not_found'];
    }
    if ((string) $attempt['status'] !== 'in_progress') {
        return ['ok' => false, 'error' => 'attempt_not_active'];
    }

    $questionsStmt = $pdo->prepare(
        'SELECT q.id, q.question_type, q.points,
                a.id AS answer_id, a.answer_text, a.is_correct, a.sequence_position
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id
         WHERE q.course_id = :course_id
         ORDER BY q.id ASC, a.id ASC'
    );
    $questionsStmt->execute(['course_id' => $courseId]);
    $rows = $questionsStmt->fetchAll();
    if ($rows === []) {
        return ['ok' => false, 'error' => 'no_questions'];
    }

    $questions = [];
    foreach ($rows as $row) {
        $qId = (int) $row['id'];
        if (!isset($questions[$qId])) {
            $questions[$qId] = [
                'id' => $qId,
                'question_type' => (string) $row['question_type'],
                'points' => (float) $row['points'],
                'answers' => [],
            ];
        }
        if ($row['answer_id'] !== null) {
            $questions[$qId]['answers'][] = [
                'id' => (int) $row['answer_id'],
                'answer_text' => (string) $row['answer_text'],
                'is_correct' => (bool) $row['is_correct'],
                'sequence_position' => $row['sequence_position'] === null ? null : (int) $row['sequence_position'],
            ];
        }
    }

    if ($rawResponses === null) {
        $rawResponses = clms_exam_rebuild_raw_from_autosave($pdo, $attemptId, $questions);
    }
    if (!is_array($rawResponses)) {
        $rawResponses = [];
    }

    $totalPossiblePoints = 0.0;
    $totalAwardedPoints = 0.0;
    $responseRows = [];

    foreach ($questions as $question) {
        $qId = (int) $question['id'];
        $type = $question['question_type'];
        $points = (float) $question['points'];
        $answers = $question['answers'];
        $submitted = $rawResponses[(string) $qId] ?? $rawResponses[$qId] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $totalPossiblePoints += $points;
        $awarded = 0.0;

        if ($type === 'single_choice' || $type === 'true_false') {
            $selected = filter_var($submitted['single'] ?? null, FILTER_VALIDATE_INT);
            $correctIds = array_map(
                static fn (array $answer): int => (int) $answer['id'],
                array_values(array_filter($answers, static fn (array $answer): bool => (bool) $answer['is_correct']))
            );
            if ($selected !== false && $selected !== null && in_array((int) $selected, $correctIds, true) && count($correctIds) === 1) {
                $awarded = $points;
            }
            if ($selected !== false && $selected !== null) {
                $responseRows[] = [
                    'question_id' => $qId,
                    'selected_answer_id' => (int) $selected,
                    'text_response' => null,
                    'submitted_sequence_position' => null,
                    'is_graded' => 1,
                    'points_awarded' => $awarded,
                ];
            }
        } elseif ($type === 'multiple_select') {
            $submittedMulti = $submitted['multiple'] ?? [];
            if (!is_array($submittedMulti)) {
                $submittedMulti = [];
            }
            $submittedIds = [];
            foreach ($submittedMulti as $value) {
                $id = filter_var($value, FILTER_VALIDATE_INT);
                if ($id !== false && $id !== null) {
                    $submittedIds[] = (int) $id;
                }
            }
            $submittedIds = array_values(array_unique($submittedIds));
            sort($submittedIds);

            $correctIds = array_map(
                static fn (array $answer): int => (int) $answer['id'],
                array_values(array_filter($answers, static fn (array $answer): bool => (bool) $answer['is_correct']))
            );
            sort($correctIds);

            if ($submittedIds === $correctIds && $submittedIds !== []) {
                $awarded = $points;
            }

            $isFirst = true;
            foreach ($submittedIds as $selectedId) {
                $responseRows[] = [
                    'question_id' => $qId,
                    'selected_answer_id' => $selectedId,
                    'text_response' => null,
                    'submitted_sequence_position' => null,
                    'is_graded' => 1,
                    'points_awarded' => $isFirst ? $awarded : 0.0,
                ];
                $isFirst = false;
            }
        } elseif ($type === 'sequencing') {
            $submittedSeq = $submitted['sequence'] ?? [];
            if (!is_array($submittedSeq)) {
                $submittedSeq = [];
            }

            $dbMap = [];
            foreach ($answers as $answer) {
                if ($answer['sequence_position'] !== null) {
                    $dbMap[(int) $answer['id']] = (int) $answer['sequence_position'];
                }
            }
            ksort($dbMap);

            $submittedMap = [];
            foreach ($submittedSeq as $answerIdRaw => $posRaw) {
                $answerId = filter_var($answerIdRaw, FILTER_VALIDATE_INT);
                $position = filter_var($posRaw, FILTER_VALIDATE_INT);
                if (
                    $answerId !== false && $answerId !== null
                    && $position !== false && $position !== null
                    && $position > 0
                ) {
                    $submittedMap[(int) $answerId] = (int) $position;
                }
            }
            ksort($submittedMap);

            if ($submittedMap === $dbMap && $submittedMap !== []) {
                $awarded = $points;
            }

            $isFirst = true;
            foreach ($submittedMap as $selectedAnswerId => $position) {
                $responseRows[] = [
                    'question_id' => $qId,
                    'selected_answer_id' => $selectedAnswerId,
                    'text_response' => null,
                    'submitted_sequence_position' => $position,
                    'is_graded' => 1,
                    'points_awarded' => $isFirst ? $awarded : 0.0,
                ];
                $isFirst = false;
            }
        } elseif ($type === 'fill_blank') {
            $text = trim((string) ($submitted['text'] ?? ''));
            $correctAnswers = array_map(
                static fn (array $answer): string => trim((string) $answer['answer_text']),
                array_values(array_filter($answers, static fn (array $answer): bool => (bool) $answer['is_correct']))
            );

            foreach ($correctAnswers as $correct) {
                if ($text !== '' && mb_strtolower($text) === mb_strtolower($correct)) {
                    $awarded = $points;
                    break;
                }
            }

            if ($text !== '') {
                $responseRows[] = [
                    'question_id' => $qId,
                    'selected_answer_id' => null,
                    'text_response' => $text,
                    'submitted_sequence_position' => null,
                    'is_graded' => 1,
                    'points_awarded' => $awarded,
                ];
            }
        } elseif ($type === 'essay') {
            // Essay answers are stored for the instructor to optionally
            // review later, but they no longer block the attempt from
            // completing. The attempt finalises as `completed` right
            // away using the auto-graded score.
            $text = trim((string) ($submitted['text'] ?? ''));
            if ($text !== '') {
                $responseRows[] = [
                    'question_id' => $qId,
                    'selected_answer_id' => null,
                    'text_response' => $text,
                    'submitted_sequence_position' => null,
                    'is_graded' => 0,
                    'points_awarded' => 0.0,
                ];
            }
        }

        $totalAwardedPoints += $awarded;
    }

    $passingPercentage = (float) $attempt['passing_score_percentage'];
    $achievedPercentage = $totalPossiblePoints > 0 ? ($totalAwardedPoints / $totalPossiblePoints) * 100 : 0;
    $isPassed = $achievedPercentage >= $passingPercentage ? 1 : 0;
    $finalStatus = 'completed';
    // Certificate is issued whenever the student clears the passing
    // threshold, independent of whether the exam contained essays.
    $shouldIssueCertificate = (bool) $isPassed;
    $certificateHash = null;
    $certificateJustIssued = false;
    $gradingCommitted = false;

    try {
        $pdo->beginTransaction();

        $deleteStmt = $pdo->prepare('DELETE FROM student_responses WHERE attempt_id = :attempt_id');
        $deleteStmt->execute(['attempt_id' => $attemptId]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO student_responses
                (attempt_id, question_id, selected_answer_id, text_response, submitted_sequence_position, is_graded, points_awarded)
             VALUES
                (:attempt_id, :question_id, :selected_answer_id, :text_response, :submitted_sequence_position, :is_graded, :points_awarded)'
        );

        foreach ($responseRows as $response) {
            $insertStmt->execute([
                'attempt_id' => $attemptId,
                'question_id' => (int) $response['question_id'],
                'selected_answer_id' => $response['selected_answer_id'],
                'text_response' => $response['text_response'],
                'submitted_sequence_position' => $response['submitted_sequence_position'],
                'is_graded' => (int) $response['is_graded'],
                'points_awarded' => number_format((float) $response['points_awarded'], 2, '.', ''),
            ]);
        }

        $attemptUpdateStmt = $pdo->prepare(
            'UPDATE exam_attempts
             SET status = :status,
                 total_score = :total_score,
                 is_passed = :is_passed,
                 completed_at = NOW()
             WHERE id = :attempt_id
               AND user_id = :user_id'
        );
        $attemptUpdateStmt->execute([
            'status' => $finalStatus,
            'total_score' => number_format($totalAwardedPoints, 2, '.', ''),
            'is_passed' => $isPassed,
            'attempt_id' => $attemptId,
            'user_id' => $userId,
        ]);

        if ($shouldIssueCertificate) {
            $selectCertificateStmt = $pdo->prepare(
                'SELECT certificate_hash
                 FROM certificates
                 WHERE user_id = :user_id AND course_id = :course_id
                 LIMIT 1'
            );
            $selectCertificateStmt->execute([
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            $existingCertificate = $selectCertificateStmt->fetch();

            if ($existingCertificate && isset($existingCertificate['certificate_hash'])) {
                $certificateHash = (string) $existingCertificate['certificate_hash'];
            } else {
                $insertCertificateStmt = $pdo->prepare(
                    'INSERT INTO certificates (user_id, course_id, certificate_hash)
                     VALUES (:user_id, :course_id, :certificate_hash)'
                );

                $inserted = false;
                for ($i = 0; $i < 5; $i++) {
                    $generatedHash = bin2hex(random_bytes(32));
                    try {
                        $insertCertificateStmt->execute([
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'certificate_hash' => $generatedHash,
                        ]);
                        $certificateHash = $generatedHash;
                        $certificateJustIssued = true;
                        $inserted = true;
                        break;
                    } catch (PDOException $exception) {
                        if (isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
                            $selectCertificateStmt->execute([
                                'user_id' => $userId,
                                'course_id' => $courseId,
                            ]);
                            $existingByCourse = $selectCertificateStmt->fetch();
                            if ($existingByCourse && isset($existingByCourse['certificate_hash'])) {
                                $certificateHash = (string) $existingByCourse['certificate_hash'];
                                $inserted = true;
                                break;
                            }
                            continue;
                        }
                        throw $exception;
                    }
                }

                if (!$inserted && $certificateHash === null) {
                    throw new RuntimeException('Unable to generate certificate hash.');
                }
            }
        }

        $pdo->commit();
        $gradingCommitted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('clms_finalize_exam_attempt: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'grading_failed'];
    }

    if ($gradingCommitted) {
        require_once __DIR__ . '/audit-log.php';
        clms_audit_log(
            $pdo,
            'exam_attempt_finalized',
            'exam_attempt',
            $attemptId,
            [
                'course_id' => $courseId,
                'total_awarded_points' => $totalAwardedPoints,
                'total_possible_points' => $totalPossiblePoints,
                'is_passed' => (int) $isPassed,
            ],
            $userId
        );
    }

    return [
        'ok' => true,
        'summary' => [
            'attempt_id' => $attemptId,
            'course_id' => $courseId,
            'total_possible_points' => $totalPossiblePoints,
            'total_awarded_points' => $totalAwardedPoints,
            'passing_percentage' => $passingPercentage,
            'achieved_percentage' => $achievedPercentage,
            'is_passed' => (int) $isPassed,
            'status' => $finalStatus,
            'has_essay' => false,
            'certificate_hash' => $certificateHash,
            'certificate_just_issued' => $certificateJustIssued,
        ],
    ];
}

/**
 * Load the summary of an already-finalised attempt from what's stored in
 * the database. Used when the student revisits / refreshes the result page
 * (grade_exam.php?attempt_id=...) after the POST grading step already
 * ran — we must NOT re-grade, we just show what was saved.
 *
 * Returns the same shape as clms_finalize_exam_attempt()['summary'] so the
 * result card partial can be rendered uniformly.
 *
 * @return array{ok:bool,error?:string,summary?:array<string,mixed>}
 */
function clms_load_exam_attempt_summary(
    PDO $pdo,
    int $attemptId,
    int $courseId,
    int $userId
): array {
    $stmt = $pdo->prepare(
        'SELECT ea.id,
                ea.status,
                ea.total_score,
                ea.is_passed,
                c.passing_score_percentage
         FROM exam_attempts ea
         INNER JOIN courses c ON c.id = ea.course_id
         WHERE ea.id = :attempt_id
           AND ea.user_id = :user_id
           AND ea.course_id = :course_id
         LIMIT 1'
    );
    $stmt->execute([
        'attempt_id' => $attemptId,
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);
    $attempt = $stmt->fetch();
    if (!$attempt) {
        return ['ok' => false, 'error' => 'attempt_not_found'];
    }

    $status = (string) $attempt['status'];
    if ($status === 'in_progress') {
        // Refusing to render a summary for an unfinalised attempt — the
        // caller should send the student back to take_exam.php instead.
        return ['ok' => false, 'error' => 'attempt_still_in_progress'];
    }

    // Recompute the total possible points from the current question bank
    // so the "X / Y" stat is accurate even if points per question changed
    // since submission. (total_score is frozen at grading time, so the
    // ratio matches what the student saw on submit.)
    $possibleStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0) AS total_possible
         FROM questions
         WHERE course_id = :course_id'
    );
    $possibleStmt->execute(['course_id' => $courseId]);
    $totalPossiblePoints = (float) ($possibleStmt->fetchColumn() ?: 0);

    $totalAwardedPoints = (float) $attempt['total_score'];
    $passingPercentage = (float) $attempt['passing_score_percentage'];
    $achievedPercentage = $totalPossiblePoints > 0
        ? ($totalAwardedPoints / $totalPossiblePoints) * 100
        : 0.0;

    $certificateHash = null;
    try {
        $certStmt = $pdo->prepare(
            'SELECT certificate_hash
             FROM certificates
             WHERE user_id = :user_id AND course_id = :course_id
             LIMIT 1'
        );
        $certStmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $certRow = $certStmt->fetch();
        if ($certRow && isset($certRow['certificate_hash'])) {
            $certificateHash = (string) $certRow['certificate_hash'];
        }
    } catch (Throwable $e) {
        // Missing certificates table / transient error: non-fatal — the
        // card just won't show the download block.
        error_log('clms_load_exam_attempt_summary: certificate lookup failed: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'summary' => [
            'attempt_id' => $attemptId,
            'course_id' => $courseId,
            'total_possible_points' => $totalPossiblePoints,
            'total_awarded_points' => $totalAwardedPoints,
            'passing_percentage' => $passingPercentage,
            'achieved_percentage' => $achievedPercentage,
            'is_passed' => (int) $attempt['is_passed'],
            // Legacy attempts stored as `pending_manual_grade` are now
            // surfaced as completed — the UI no longer has an awaiting
            // review state and should just show pass/fail based on the
            // recorded is_passed flag.
            'status' => $status === 'pending_manual_grade' ? 'completed' : $status,
            'has_essay' => false,
            'certificate_hash' => $certificateHash,
            'certificate_just_issued' => false,
        ],
    ];
}

/**
 * Build a POST-shaped $rawResponses array from whatever the autosave has
 * already persisted. Used when finalising an expired attempt without a
 * fresh form submit — the server grades exactly what's on disk.
 *
 * @param array<int,array{id:int,question_type:string,points:float,answers:array}> $questions
 * @return array<int|string,array<string,mixed>>
 */
function clms_exam_rebuild_raw_from_autosave(PDO $pdo, int $attemptId, array $questions): array
{
    $stmt = $pdo->prepare(
        'SELECT question_id, selected_answer_id, text_response, submitted_sequence_position
         FROM student_responses
         WHERE attempt_id = :attempt_id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $rows = $stmt->fetchAll();

    $raw = [];
    foreach ($rows as $row) {
        $qid = (int) $row['question_id'];
        $type = $questions[$qid]['question_type'] ?? null;
        if ($type === null) {
            continue;
        }

        if (!isset($raw[$qid])) {
            $raw[$qid] = [];
        }

        if ($type === 'single_choice' || $type === 'true_false') {
            if ($row['selected_answer_id'] !== null) {
                $raw[$qid]['single'] = (int) $row['selected_answer_id'];
            }
        } elseif ($type === 'multiple_select') {
            if ($row['selected_answer_id'] !== null) {
                if (!isset($raw[$qid]['multiple']) || !is_array($raw[$qid]['multiple'])) {
                    $raw[$qid]['multiple'] = [];
                }
                $raw[$qid]['multiple'][] = (int) $row['selected_answer_id'];
            }
        } elseif ($type === 'sequencing') {
            if ($row['selected_answer_id'] !== null && $row['submitted_sequence_position'] !== null) {
                if (!isset($raw[$qid]['sequence']) || !is_array($raw[$qid]['sequence'])) {
                    $raw[$qid]['sequence'] = [];
                }
                $raw[$qid]['sequence'][(int) $row['selected_answer_id']] = (int) $row['submitted_sequence_position'];
            }
        } elseif ($type === 'fill_blank' || $type === 'essay') {
            if ($row['text_response'] !== null && $row['text_response'] !== '') {
                $raw[$qid]['text'] = (string) $row['text_response'];
            }
        }
    }

    return $raw;
}

/**
 * Small helper to fire the n8n webhook after a certificate is freshly issued.
 * Non-fatal: errors are logged and swallowed.
 */
function clms_notify_certificate_webhook(string $studentEmail, string $certificateHash): void
{
    $webhookUrl = trim((string) (getenv('CLMS_N8N_WEBHOOK_URL') ?: ''));
    $webhookToken = trim((string) (getenv('CLMS_N8N_WEBHOOK_TOKEN') ?: ''));
    if ($webhookUrl === '' || !function_exists('curl_init')) {
        return;
    }

    $payload = json_encode([
        'student_email' => $studentEmail,
        'certificate_hash' => $certificateHash,
    ]);
    if ($payload === false) {
        return;
    }

    $ch = curl_init($webhookUrl);
    if ($ch === false) {
        return;
    }

    $headers = ['Content-Type: application/json'];
    if ($webhookToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $webhookToken;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
