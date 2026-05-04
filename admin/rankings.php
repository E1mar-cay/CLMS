<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/student-batch-schema.php';

clms_ensure_users_student_batch_column($pdo);

clms_require_roles(['admin', 'instructor']);

require_once __DIR__ . '/includes/pagination.php';

$pageTitle = 'Course Rankings | Criminology LMS';
$activeAdminPage = 'rankings';

$range = (string) ($_GET['range'] ?? 'this_semester');
$allowedRanges = ['all', 'this_month', 'this_semester'];
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

$dateFrom = null;
$dateTo = null;

if ($range === 'this_month') {
    $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
    $dateTo = $today->modify('last day of this month')->format('Y-m-d');
} elseif ($range === 'this_semester') {
    $dateFrom = $semesterStart->format('Y-m-d');
    $dateTo = $semesterEnd->format('Y-m-d');
}

$batchFilter = trim((string) ($_GET['batch'] ?? ''));
$overallRankMode = (string) ($_GET['overall_rank'] ?? 'composite');
$allowedOverallRank = ['composite', 'by_module', 'by_final'];
if (!in_array($overallRankMode, $allowedOverallRank, true)) {
    $overallRankMode = 'composite';
}

$assessmentRankMode = (string) ($_GET['assessment_rank'] ?? 'all');
$allowedAssessmentRank = ['all', 'top10', 'bottom10'];
if (!in_array($assessmentRankMode, $allowedAssessmentRank, true)) {
    $assessmentRankMode = 'all';
}

$orderSql = 'ORDER BY overall_score DESC, fe.final_exam_score DESC, ms.module_avg_score DESC, u.last_name ASC, u.first_name ASC';
if ($overallRankMode === 'by_module') {
    $orderSql = 'ORDER BY ms.module_avg_score DESC, overall_score DESC, u.last_name ASC, u.first_name ASC';
} elseif ($overallRankMode === 'by_final') {
    $orderSql = 'ORDER BY fe.final_exam_score IS NULL, fe.final_exam_score DESC, overall_score DESC, u.last_name ASC, u.first_name ASC';
}

$courseStmt = $pdo->query(
    'SELECT id, title
     FROM courses
     ORDER BY title ASC'
);
$courses = $courseStmt->fetchAll();

$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (($selectedCourseId === false || $selectedCourseId === null || $selectedCourseId <= 0) && $courses !== []) {
    $selectedCourseId = (int) $courses[0]['id'];
}

$rankingRows = [];
$moduleBreakdownByUser = [];
$selectedCourseTitle = null;
$finalExamTotalQuestions = 0;
$rankingBatchOptions = [];

if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0) {
    foreach ($courses as $course) {
        if ((int) $course['id'] === (int) $selectedCourseId) {
            $selectedCourseTitle = (string) $course['title'];
            break;
        }
    }

    $rankingSql =
        "SELECT
            u.id AS user_id,
            u.first_name,
            u.last_name,
            COALESCE(TRIM(u.student_batch), '') AS student_batch,
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
         {$orderSql}";
    $rankingStmt = $pdo->prepare($rankingSql);
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

    if ($batchFilter !== '') {
        $rankingRows = array_values(array_filter(
            $rankingRows,
            static function (array $r) use ($batchFilter): bool {
                $b = trim((string) ($r['student_batch'] ?? ''));
                if ($batchFilter === '__none__') {
                    return $b === '';
                }

                return strcasecmp($b, $batchFilter) === 0;
            }
        ));
    }

    if ($assessmentRankMode === 'top10') {
        usort(
            $rankingRows,
            static function (array $a, array $b): int {
                return ((float) ($b['module_avg_score'] ?? 0)) <=> ((float) ($a['module_avg_score'] ?? 0));
            }
        );
        $rankingRows = array_slice($rankingRows, 0, 10);
    } elseif ($assessmentRankMode === 'bottom10') {
        usort(
            $rankingRows,
            static function (array $a, array $b): int {
                return ((float) ($a['module_avg_score'] ?? 0)) <=> ((float) ($b['module_avg_score'] ?? 0));
            }
        );
        $rankingRows = array_slice($rankingRows, 0, 10);
    }

    $rbStmt = $pdo->prepare(
        "SELECT DISTINCT TRIM(COALESCE(u.student_batch, '')) AS b
         FROM users u
         WHERE u.role = 'student'
           AND (
             EXISTS(
                 SELECT 1 FROM module_quiz_attempts mqa
                 INNER JOIN modules m ON m.id = mqa.module_id
                 WHERE mqa.user_id = u.id AND m.course_id = :cid1
             )
             OR EXISTS(SELECT 1 FROM exam_attempts ea WHERE ea.user_id = u.id AND ea.course_id = :cid2)
           )
         ORDER BY b ASC"
    );
    $rbStmt->execute(['cid1' => (int) $selectedCourseId, 'cid2' => (int) $selectedCourseId]);
    while ($brow = $rbStmt->fetch()) {
        $rankingBatchOptions[] = (string) $brow['b'];
    }

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

if ((string) ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="admin_course_rankings.csv"');
    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fputcsv($out, [
            'Course',
            (string) ($selectedCourseTitle ?? ''),
            'Period',
            $range,
            'Date window',
            (string) ($dateFrom ?? 'all') . ' to ' . (string) ($dateTo ?? 'all'),
            'Batch filter',
            $batchFilter === '' ? 'all' : $batchFilter,
            'Overall ranking mode',
            $overallRankMode,
            'Course assessment ranking',
            $assessmentRankMode,
        ]);
        fputcsv($out, ['Rank', 'Student', 'Batch', 'Overall Rank Score', 'Assessments', 'Final Exam', 'Scores Per Module']);
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
                trim((string) ($row['student_batch'] ?? '')),
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

$rankPerPage = 20;
$rankPage = (int) filter_input(INPUT_GET, 'rank_page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$totalRankRows = count($rankingRows);
$totalRankPages = max(1, (int) ceil($totalRankRows / $rankPerPage));
if ($rankPage > $totalRankPages) {
    $rankPage = $totalRankPages;
}
$rankOffset = ($rankPage - 1) * $rankPerPage;
$rankingRowsPaged = $totalRankRows === 0 ? [] : array_slice($rankingRows, $rankOffset, $rankPerPage);

$rankPaginationBase = [
    'course_id' => (int) $selectedCourseId,
    'range' => $range,
    'overall_rank' => $overallRankMode,
    'assessment_rank' => $assessmentRankMode,
];
if ($batchFilter !== '') {
    $rankPaginationBase['batch'] = $batchFilter;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-3 mb-3">
                  <div>
                    <h4 class="fw-bold mb-1">Course Rankings</h4>
                    <small class="text-muted">Rank students by raw module and final exam scores.</small>
                  </div>
                </div>

                <div class="card mb-4">
                  <div class="card-body">
                    <form method="get" class="row g-3 align-items-end">
                      <div class="col-12 col-xl-3">
                        <label for="course_id" class="form-label">Course</label>
                        <select id="course_id" name="course_id" class="form-select" onchange="this.form.submit()">
<?php foreach ($courses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="range" class="form-label">Period</label>
                        <select id="range" name="range" class="form-select">
                          <option value="this_semester" <?php echo $range === 'this_semester' ? 'selected' : ''; ?>>This Semester</option>
                          <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                          <option value="all" <?php echo $range === 'all' ? 'selected' : ''; ?>>All Time</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="batch" class="form-label">Batch No.</label>
                        <select id="batch" name="batch" class="form-select">
                          <option value="">All batches</option>
                          <option value="__none__" <?php echo $batchFilter === '__none__' ? 'selected' : ''; ?>>Unspecified</option>
<?php foreach ($rankingBatchOptions as $rb) :
    if ($rb === '') {
        continue;
    }
    ?>
                          <option value="<?php echo htmlspecialchars($rb, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($batchFilter !== '' && $batchFilter !== '__none__' && strcasecmp($batchFilter, $rb) === 0) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rb, ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-2">
                        <label for="assessment_rank" class="form-label">Course assessment ranking</label>
                        <select id="assessment_rank" name="assessment_rank" class="form-select">
                          <option value="all" <?php echo $assessmentRankMode === 'all' ? 'selected' : ''; ?>>All reviewees</option>
                          <option value="top10" <?php echo $assessmentRankMode === 'top10' ? 'selected' : ''; ?>>Top 10 (by module avg.)</option>
                          <option value="bottom10" <?php echo $assessmentRankMode === 'bottom10' ? 'selected' : ''; ?>>Bottom 10 (by module avg.)</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 col-xl-3">
                        <label for="overall_rank" class="form-label">Overall ranking</label>
                        <select id="overall_rank" name="overall_rank" class="form-select">
                          <option value="composite" <?php echo $overallRankMode === 'composite' ? 'selected' : ''; ?>>Composite score</option>
                          <option value="by_module" <?php echo $overallRankMode === 'by_module' ? 'selected' : ''; ?>>Prioritize module assessments</option>
                          <option value="by_final" <?php echo $overallRankMode === 'by_final' ? 'selected' : ''; ?>>Prioritize final exam</option>
                        </select>
                      </div>
                      <div class="col-12 col-xl-12">
                        <div class="d-flex flex-wrap gap-2">
                          <button type="submit" class="btn btn-primary">Apply filters</button>
<?php
    $exportRankParams = [
        'course_id' => (int) $selectedCourseId,
        'range' => $range,
        'overall_rank' => $overallRankMode,
        'assessment_rank' => $assessmentRankMode,
        'export' => 'csv',
    ];
    if ($batchFilter !== '') {
        $exportRankParams['batch'] = $batchFilter;
    }
    $exportRankUrl = $clmsWebBase . '/admin/rankings.php?' . http_build_query($exportRankParams);
?>
                          <a class="btn btn-outline-secondary text-nowrap" href="<?php echo htmlspecialchars($exportRankUrl, ENT_QUOTES, 'UTF-8'); ?>">Export CSV</a>
                        </div>
                        <small class="text-muted d-block mt-2">Period still limits which attempts count toward scores. Batch and assessment filters apply after ranking is built.</small>
                      </div>
                    </form>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0"><?php echo htmlspecialchars((string) ($selectedCourseTitle ?? 'Selected Course'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-1 gap-sm-2 text-sm-end">
                      <span class="badge bg-label-primary"><?php echo (int) $totalRankRows; ?> ranked students</span>
<?php if ($totalRankRows > 0) : ?>
                      <small class="text-muted">Showing <?php echo (int) ($rankOffset + 1); ?>&ndash;<?php echo (int) min($rankOffset + $rankPerPage, $totalRankRows); ?> of <?php echo (int) $totalRankRows; ?></small>
<?php endif; ?>
                    </div>
                  </div>
                  <div class="card-body">
<?php if ($courses === []) : ?>
                    <p class="mb-0">No courses found.</p>
<?php elseif ($rankingRows === []) : ?>
                    <p class="mb-0">No module or final exam attempts found for this course yet.</p>
<?php else : ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Batch</th>
                            <th class="text-center">Overall Rank Score</th>
                            <th class="text-center">Assessments</th>
                            <th class="text-center">Final Exam</th>
                            <th>Scores Per Module</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($rankingRowsPaged as $idx => $row) :
    $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    if ($fullName === '') {
        $fullName = 'Student';
    }
    $uid = (int) $row['user_id'];
    $moduleEntries = $moduleBreakdownByUser[$uid] ?? [];
    $rankOrdinal = $rankOffset + $idx + 1;
    $rankClass = $rankOrdinal === 1 ? 'table-warning' : ($rankOrdinal === 2 ? 'table-light' : ($rankOrdinal === 3 ? 'table-info' : ''));
    $rankBadgeClass = $rankOrdinal === 1 ? 'bg-label-warning' : ($rankOrdinal === 2 ? 'bg-label-secondary' : ($rankOrdinal === 3 ? 'bg-label-info' : 'bg-label-primary'));
    $rankLabel = $rankOrdinal === 1 ? 'Gold' : ($rankOrdinal === 2 ? 'Silver' : ($rankOrdinal === 3 ? 'Bronze' : 'Rank'));
?>
                          <tr class="<?php echo $rankClass; ?>">
                            <td>
                              <span class="badge <?php echo $rankBadgeClass; ?>"><?php echo htmlspecialchars($rankLabel . ' #' . $rankOrdinal, ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><small class="text-muted"><?php $sb = trim((string) ($row['student_batch'] ?? '')); echo $sb !== '' ? htmlspecialchars($sb, ENT_QUOTES, 'UTF-8') : '—'; ?></small></td>
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
<?php
    clms_admin_pagination_render(
        $clmsWebBase,
        '/admin/rankings.php',
        $rankPaginationBase,
        $rankPage,
        $totalRankPages,
        'Rankings pagination',
        'rank_page',
        null,
        'mt-3'
    );
?>
<?php endif; ?>
                  </div>
                </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';

