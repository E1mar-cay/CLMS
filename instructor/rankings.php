<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['instructor']);

$pageTitle = 'Course Rankings | Criminology LMS';
$activeInstructorPage = 'rankings';
$instructorId = (int) $_SESSION['user_id'];

$range = (string) ($_GET['range'] ?? 'this_semester');
$allowedRanges = ['all', 'this_month', 'this_semester', 'custom'];
if (!in_array($range, $allowedRanges, true)) {
    $range = 'this_semester';
}

$today = new DateTimeImmutable('today');
$month = (int) $today->format('n');
$year = (int) $today->format('Y');
$semesterStart = $month <= 6
    ? (new DateTimeImmutable($year . '-01-01'))
    : (new DateTimeImmutable($year . '-07-01'));
$semesterEnd = $month <= 6
    ? (new DateTimeImmutable($year . '-06-30'))
    : (new DateTimeImmutable($year . '-12-31'));

$dateFromInput = trim((string) ($_GET['date_from'] ?? ''));
$dateToInput = trim((string) ($_GET['date_to'] ?? ''));
$dateFrom = null;
$dateTo = null;

if ($range === 'this_month') {
    $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
    $dateTo = $today->modify('last day of this month')->format('Y-m-d');
} elseif ($range === 'this_semester') {
    $dateFrom = $semesterStart->format('Y-m-d');
    $dateTo = $semesterEnd->format('Y-m-d');
} elseif ($range === 'custom') {
    $fromTmp = DateTimeImmutable::createFromFormat('Y-m-d', $dateFromInput) ?: null;
    $toTmp = DateTimeImmutable::createFromFormat('Y-m-d', $dateToInput) ?: null;
    if ($fromTmp && $toTmp) {
        if ($fromTmp <= $toTmp) {
            $dateFrom = $fromTmp->format('Y-m-d');
            $dateTo = $toTmp->format('Y-m-d');
        } else {
            $dateFrom = $toTmp->format('Y-m-d');
            $dateTo = $fromTmp->format('Y-m-d');
        }
    }
}

$courseStmt = $pdo->prepare(
    "SELECT c.id, c.title
     FROM courses c
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     WHERE c.is_published = 1
       AND (
         ci.instructor_user_id IS NOT NULL
         OR NOT EXISTS (
           SELECT 1
           FROM course_instructors ci2
           WHERE ci2.course_id = c.id
         )
       )
     ORDER BY c.title ASC"
);
$courseStmt->execute(['instructor_id' => $instructorId]);
$courses = $courseStmt->fetchAll();

$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (($selectedCourseId === false || $selectedCourseId === null || $selectedCourseId <= 0) && $courses !== []) {
    $selectedCourseId = (int) $courses[0]['id'];
}

$selectedCourseInScope = false;
$selectedCourseTitle = null;
foreach ($courses as $course) {
    if ((int) $course['id'] === (int) $selectedCourseId) {
        $selectedCourseInScope = true;
        $selectedCourseTitle = (string) $course['title'];
        break;
    }
}

$rankingRows = [];
$moduleBreakdownByUser = [];
$finalExamTotalQuestions = 0;

if ($selectedCourseInScope) {
    $rankingStmt = $pdo->prepare(
        "SELECT
            u.id AS user_id,
            u.first_name,
            u.last_name,
            COALESCE(ms.module_avg_score, 0.00) AS module_avg_score,
            COALESCE(ms.module_count, 0) AS module_count,
            COALESCE(ms.module_correct_total, 0.00) AS module_correct_total,
            COALESCE(ms.module_question_total, 0) AS module_question_total,
            fe.final_exam_score,
            CASE
                WHEN fe.final_exam_score IS NOT NULL AND ms.module_count > 0
                    THEN ROUND((ms.module_avg_score + fe.final_exam_score) / 2, 2)
                WHEN fe.final_exam_score IS NOT NULL
                    THEN ROUND(fe.final_exam_score, 2)
                ELSE ROUND(COALESCE(ms.module_avg_score, 0.00), 2)
            END AS overall_score
         FROM users u
         INNER JOIN (
            SELECT participant.user_id
            FROM (
                SELECT mb.user_id
                FROM (
                    SELECT user_id, module_id, MAX(percentage) AS best_percentage
                    FROM module_quiz_attempts
                    WHERE (:m_range_from_a IS NULL OR DATE(attempted_at) >= :m_range_from_b)
                      AND (:m_range_to_a IS NULL OR DATE(attempted_at) <= :m_range_to_b)
                    GROUP BY user_id, module_id
                ) mb
                INNER JOIN modules m ON m.id = mb.module_id
                WHERE m.course_id = :course_id_modules
                UNION
                SELECT ea.user_id
                FROM exam_attempts ea
                WHERE ea.course_id = :course_id_exam
                  AND ea.status = 'completed'
                  AND (:e_range_from_a IS NULL OR DATE(COALESCE(ea.completed_at, ea.attempted_at)) >= :e_range_from_b)
                  AND (:e_range_to_a IS NULL OR DATE(COALESCE(ea.completed_at, ea.attempted_at)) <= :e_range_to_b)
            ) participant
         ) p ON p.user_id = u.id
         LEFT JOIN (
            SELECT
                mb.user_id,
                COUNT(*) AS module_count,
                ROUND(SUM(mb.best_score), 2) AS module_correct_total,
                COALESCE(SUM(mq.question_total), 0) AS module_question_total,
                ROUND(AVG(mb.best_score), 2) AS module_avg_score
            FROM (
                SELECT user_id, module_id, MAX(score) AS best_score
                FROM module_quiz_attempts
                WHERE (:ms_range_from_a IS NULL OR DATE(attempted_at) >= :ms_range_from_b)
                  AND (:ms_range_to_a IS NULL OR DATE(attempted_at) <= :ms_range_to_b)
                GROUP BY user_id, module_id
            ) mb
            INNER JOIN modules m ON m.id = mb.module_id
            LEFT JOIN (
                SELECT module_id, COUNT(*) AS question_total
                FROM questions
                WHERE module_id IS NOT NULL
                GROUP BY module_id
            ) mq ON mq.module_id = m.id
            WHERE m.course_id = :course_id_module_avg
            GROUP BY mb.user_id
         ) ms ON ms.user_id = u.id
         LEFT JOIN (
            SELECT
                user_id,
                ROUND(MAX(total_score), 2) AS final_exam_score
            FROM exam_attempts
            WHERE course_id = :course_id_final
              AND status = 'completed'
              AND (:fe_range_from_a IS NULL OR DATE(COALESCE(completed_at, attempted_at)) >= :fe_range_from_b)
              AND (:fe_range_to_a IS NULL OR DATE(COALESCE(completed_at, attempted_at)) <= :fe_range_to_b)
            GROUP BY user_id
         ) fe ON fe.user_id = u.id
         WHERE u.role = 'student'
         ORDER BY overall_score DESC, fe.final_exam_score DESC, ms.module_avg_score DESC, u.last_name ASC, u.first_name ASC"
    );
    $rankingStmt->execute([
        'course_id_modules' => (int) $selectedCourseId,
        'course_id_exam' => (int) $selectedCourseId,
        'course_id_module_avg' => (int) $selectedCourseId,
        'course_id_final' => (int) $selectedCourseId,
        'm_range_from_a' => $dateFrom,
        'm_range_from_b' => $dateFrom,
        'm_range_to_a' => $dateTo,
        'm_range_to_b' => $dateTo,
        'e_range_from_a' => $dateFrom,
        'e_range_from_b' => $dateFrom,
        'e_range_to_a' => $dateTo,
        'e_range_to_b' => $dateTo,
        'ms_range_from_a' => $dateFrom,
        'ms_range_from_b' => $dateFrom,
        'ms_range_to_a' => $dateTo,
        'ms_range_to_b' => $dateTo,
        'fe_range_from_a' => $dateFrom,
        'fe_range_from_b' => $dateFrom,
        'fe_range_to_a' => $dateTo,
        'fe_range_to_b' => $dateTo,
    ]);
    $rankingRows = $rankingStmt->fetchAll();

    $moduleBreakdownStmt = $pdo->prepare(
        "SELECT
            mb.user_id,
            m.title AS module_title,
            ROUND(mb.best_score, 2) AS module_score,
            COALESCE(mq.question_total, 0) AS module_question_total
         FROM (
            SELECT user_id, module_id, MAX(score) AS best_score
            FROM module_quiz_attempts
            WHERE (:mb_range_from_a IS NULL OR DATE(attempted_at) >= :mb_range_from_b)
              AND (:mb_range_to_a IS NULL OR DATE(attempted_at) <= :mb_range_to_b)
            GROUP BY user_id, module_id
         ) mb
         INNER JOIN modules m ON m.id = mb.module_id
         LEFT JOIN (
            SELECT module_id, COUNT(*) AS question_total
            FROM questions
            WHERE module_id IS NOT NULL
            GROUP BY module_id
         ) mq ON mq.module_id = m.id
         WHERE m.course_id = :course_id
         ORDER BY m.sequence_order ASC, m.id ASC"
    );
    $moduleBreakdownStmt->execute([
        'course_id' => (int) $selectedCourseId,
        'mb_range_from_a' => $dateFrom,
        'mb_range_from_b' => $dateFrom,
        'mb_range_to_a' => $dateTo,
        'mb_range_to_b' => $dateTo,
    ]);
    $moduleRows = $moduleBreakdownStmt->fetchAll();

    foreach ($moduleRows as $moduleRow) {
        $uid = (int) $moduleRow['user_id'];
        if (!isset($moduleBreakdownByUser[$uid])) {
            $moduleBreakdownByUser[$uid] = [];
        }
        $moduleBreakdownByUser[$uid][] = [
            'module_title' => (string) $moduleRow['module_title'],
            'module_score' => (float) $moduleRow['module_score'],
            'module_question_total' => (int) $moduleRow['module_question_total'],
        ];
    }

    $finalExamTotalStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_questions
         FROM questions
         WHERE course_id = :course_id'
    );
    $finalExamTotalStmt->execute(['course_id' => (int) $selectedCourseId]);
    $finalExamTotalQuestions = (int) ($finalExamTotalStmt->fetch()['total_questions'] ?? 0);
}

if ((string) ($_GET['export'] ?? '') === 'csv' && $selectedCourseInScope) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="instructor_course_rankings.csv"');
    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fputcsv($out, ['Course', (string) ($selectedCourseTitle ?? ''), 'Range', $range, 'From', (string) ($dateFrom ?? ''), 'To', (string) ($dateTo ?? '')]);
        fputcsv($out, ['Rank', 'Student', 'Overall Rank Score', 'Assessments', 'Final Exam', 'Scores Per Module']);
        foreach ($rankingRows as $idx => $row) {
            $uid = (int) $row['user_id'];
            $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($fullName === '') {
                $fullName = 'Student';
            }
            $moduleEntries = $moduleBreakdownByUser[$uid] ?? [];
            $moduleText = [];
            foreach ($moduleEntries as $entry) {
                $moduleText[] = (string) $entry['module_title'] . ': ' . (int) round((float) $entry['module_score']) . '/' . (int) $entry['module_question_total'];
            }
            fputcsv($out, [
                $idx + 1,
                $fullName,
                number_format((float) $row['overall_score'], 2),
                (int) round((float) $row['module_correct_total']) . '/' . (int) $row['module_question_total'],
                $row['final_exam_score'] !== null ? (int) round((float) $row['final_exam_score']) . '/' . $finalExamTotalQuestions : '-',
                implode(' | ', $moduleText),
            ]);
        }
        fclose($out);
    }
    exit;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-3 mb-3">
                  <div>
                    <h4 class="fw-bold mb-1">Course Rankings</h4>
                    <small class="text-muted">Track student rankings using raw module and final exam scores.</small>
                  </div>
                </div>

                <div class="card mb-4">
                  <div class="card-body">
                    <form method="get" class="row g-3 align-items-end">
                      <div class="col-12 col-xl-4">
                        <label for="course_id" class="form-label">Course in your scope</label>
                        <select id="course_id" name="course_id" class="form-select" onchange="this.form.submit()">
<?php foreach ($courses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="range" class="form-label">Date Range</label>
                        <select id="range" name="range" class="form-select" onchange="this.form.submit()">
                          <option value="this_semester" <?php echo $range === 'this_semester' ? 'selected' : ''; ?>>This Semester</option>
                          <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                          <option value="all" <?php echo $range === 'all' ? 'selected' : ''; ?>>All Time</option>
                          <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="date_from" class="form-label">From</label>
                        <input id="date_from" type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8'); ?>" />
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="date_to" class="form-label">To</label>
                        <input id="date_to" type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8'); ?>" />
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <span class="form-label d-none d-md-block" aria-hidden="true">&nbsp;</span>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                          <button type="submit" class="btn btn-outline-primary flex-grow-1">Apply</button>
                          <a class="btn btn-outline-secondary flex-grow-1 text-nowrap"
                             href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/rankings.php?course_id=' . (int) $selectedCourseId . '&range=' . urlencode($range) . '&date_from=' . urlencode($dateFromInput) . '&date_to=' . urlencode($dateToInput) . '&export=csv', ENT_QUOTES, 'UTF-8'); ?>">
                            Export CSV
                          </a>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars((string) ($selectedCourseTitle ?? 'Selected Course'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <span class="badge bg-label-primary"><?php echo count($rankingRows); ?> ranked students</span>
                  </div>
                  <div class="card-body">
<?php if ($courses === []) : ?>
                    <p class="mb-0">No courses assigned to you yet.</p>
<?php elseif (!$selectedCourseInScope) : ?>
                    <p class="mb-0">Selected course is outside your scope.</p>
<?php elseif ($rankingRows === []) : ?>
                    <p class="mb-0">No module or final exam attempts found for this course yet.</p>
<?php else : ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th class="text-center">Overall Rank Score</th>
                            <th class="text-center">Assessments</th>
                            <th class="text-center">Final Exam</th>
                            <th>Scores Per Module</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($rankingRows as $idx => $row) :
    $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    if ($fullName === '') {
        $fullName = 'Student';
    }
    $uid = (int) $row['user_id'];
    $moduleEntries = $moduleBreakdownByUser[$uid] ?? [];
    $rankClass = $idx === 0 ? 'table-warning' : ($idx === 1 ? 'table-light' : ($idx === 2 ? 'table-info' : ''));
    $rankBadgeClass = $idx === 0 ? 'bg-label-warning' : ($idx === 1 ? 'bg-label-secondary' : ($idx === 2 ? 'bg-label-info' : 'bg-label-primary'));
    $rankLabel = $idx === 0 ? 'Gold' : ($idx === 1 ? 'Silver' : ($idx === 2 ? 'Bronze' : 'Rank'));
?>
                          <tr class="<?php echo $rankClass; ?>">
                            <td>
                              <span class="badge <?php echo $rankBadgeClass; ?>"><?php echo htmlspecialchars($rankLabel . ' #' . ($idx + 1), ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center fw-semibold"><?php echo number_format((float) $row['overall_score'], 2); ?></td>
                            <td class="text-center">
                              <?php echo (int) round((float) $row['module_correct_total']); ?> / <?php echo (int) $row['module_question_total']; ?>
                            </td>
                            <td class="text-center">
                              <?php echo $row['final_exam_score'] !== null ? (int) round((float) $row['final_exam_score']) . ' / ' . $finalExamTotalQuestions : '-'; ?>
                            </td>
                            <td>
<?php if ($moduleEntries === []) : ?>
                              <span class="text-muted">No module attempts yet.</span>
<?php else : ?>
                              <div class="d-flex flex-column gap-1">
<?php foreach ($moduleEntries as $entry) : ?>
                                <small>
                                  <span class="fw-semibold"><?php echo htmlspecialchars((string) $entry['module_title'], ENT_QUOTES, 'UTF-8'); ?>:</span>
                                  <?php echo (int) round((float) $entry['module_score']); ?> / <?php echo (int) $entry['module_question_total']; ?>
                                </small>
<?php endforeach; ?>
                              </div>
<?php endif; ?>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>
                  </div>
                </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';

