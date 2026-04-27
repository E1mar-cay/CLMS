<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/exam_grading.php';

clms_require_roles(['student']);

/*
 * This page supports two flows so that refreshing the result page (or
 * navigating back to it later) does NOT lose the exam result:
 *
 *   POST: student just submitted the exam form. We finalise the attempt
 *         (grade + write responses + issue certificate on pass), then
 *         303-redirect to the GET view. Classic Post/Redirect/Get — the
 *         browser no longer has a POST in history, so a refresh just
 *         re-renders the stored result.
 *
 *   GET : render the stored summary for an already-finalised attempt.
 *         Data comes straight out of exam_attempts + certificates, so it
 *         survives refresh, bookmarking, and coming back from elsewhere.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    clms_redirect('student/dashboard.php');
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
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

    $result = clms_finalize_exam_attempt(
        $pdo,
        (int) $attemptId,
        (int) $courseId,
        (int) $_SESSION['user_id'],
        $rawResponses
    );

    if ($result['ok']) {
        $summary = $result['summary'];
        $certificateHash = $summary['certificate_hash'] !== null
            ? (string) $summary['certificate_hash']
            : null;
        $certificateJustIssued = (bool) $summary['certificate_just_issued'];

        if ($certificateJustIssued && $certificateHash !== null) {
            clms_notify_certificate_webhook(
                (string) ($_SESSION['email'] ?? ''),
                $certificateHash
            );
        }

        clms_redirect('student/grade_exam.php'
            . '?attempt_id=' . (int) $attemptId
            . '&course_id=' . (int) $courseId);
    }

    $error = $result['error'] ?? 'unknown';

    // If the attempt isn't in_progress any more it was already finalised
    // (usually because the student double-submitted, or the expiry guard
    // in take_exam.php finalised it first). Show the stored summary
    // instead of throwing the student back to the dashboard — that's
    // exactly the "refresh loses my result" bug we're fixing here.
    if ($error === 'attempt_not_active') {
        clms_redirect('student/grade_exam.php'
            . '?attempt_id=' . (int) $attemptId
            . '&course_id=' . (int) $courseId);
    }

    if ($error === 'attempt_not_found') {
        clms_redirect('student/dashboard.php');
    }

    // A real grading error (e.g. DB write failed) — don't loop back into
    // take_exam.php. Dashboard with a notice is the safe landing.
    clms_redirect('student/dashboard.php?notice=exam_error');
}

// ---------------------------------------------------------------------
// GET flow: render the stored summary.
// ---------------------------------------------------------------------
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT);

if (
    $courseId === false || $courseId === null || $courseId <= 0
    || $attemptId === false || $attemptId === null || $attemptId <= 0
) {
    clms_redirect('student/dashboard.php');
}

$loaded = clms_load_exam_attempt_summary(
    $pdo,
    (int) $attemptId,
    (int) $courseId,
    (int) $_SESSION['user_id']
);

if (!$loaded['ok']) {
    $err = $loaded['error'] ?? 'unknown';
    if ($err === 'attempt_still_in_progress') {
        // Somehow we got here for an unfinalised attempt — send the
        // student back to the exam form where the timer + autosave live.
        clms_redirect('student/take_exam.php?course_id=' . (int) $courseId);
    }
    clms_redirect('student/dashboard.php');
}

$summary = $loaded['summary'];
$totalPossiblePoints = (float) $summary['total_possible_points'];
$totalAwardedPoints = (float) $summary['total_awarded_points'];
$passingPercentage = (float) $summary['passing_percentage'];
$achievedPercentage = (float) $summary['achieved_percentage'];
$isPassed = (int) $summary['is_passed'];
$finalStatus = (string) $summary['status'];
$certificateHash = $summary['certificate_hash'] !== null ? (string) $summary['certificate_hash'] : null;

// The auto-finalise path in take_exam.php redirects here with auto=1 when
// an attempt was graded server-side because it ran past its deadline.
// That lets us keep the "Time's up" banner on refresh too.
$examAutoSubmittedOnExpiry = (filter_input(INPUT_GET, 'auto', FILTER_VALIDATE_INT) === 1);

$pageTitle = 'Exam Result | Criminology LMS';
$activeStudentPage = 'dashboard';

// Optional subtitle for the result hero — pull the course title so the
// card reads like "Crime Scene Investigation Fundamentals" under the hero.
$examCourseTitle = null;
try {
    $courseTitleStmt = $pdo->prepare('SELECT title FROM courses WHERE id = :id LIMIT 1');
    $courseTitleStmt->execute(['id' => (int) $courseId]);
    $ct = $courseTitleStmt->fetchColumn();
    if (is_string($ct) && $ct !== '') {
        $examCourseTitle = $ct;
    }
} catch (Throwable $e) {
    // non-fatal; hero just won't carry a course title
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <h4 class="fw-bold py-3 mb-3">Exam Result</h4>
<?php
require __DIR__ . '/includes/exam-result-card.php';
require_once __DIR__ . '/includes/layout-bottom.php';
