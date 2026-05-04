<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$sessionRole = (string) ($_SESSION['role'] ?? '');
$reportsUserId = (int) ($_SESSION['user_id'] ?? 0);

/**
 * Admin: any course. Instructor: assigned / legacy-unscoped courses only.
 */
$clms_reports_course_allowed = static function (PDO $pdo, string $role, int $userId, int $courseId): bool {
    if ($courseId <= 0) {
        return false;
    }
    if ($role === 'admin') {
        $st = $pdo->prepare('SELECT 1 FROM courses WHERE id = :id LIMIT 1');
        $st->execute(['id' => $courseId]);

        return (bool) $st->fetch();
    }
    $st = $pdo->prepare(
        "SELECT 1 AS ok
         FROM courses c
         LEFT JOIN course_instructors ci
           ON ci.course_id = c.id AND ci.instructor_user_id = :uid
         WHERE c.id = :cid
           AND (
             ci.instructor_user_id IS NOT NULL
             OR NOT EXISTS (SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id)
           )
         LIMIT 1"
    );
    $st->execute(['uid' => $userId, 'cid' => $courseId]);

    return (bool) $st->fetch();
};

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && (string) ($_GET['action'] ?? '') === 'print_questionnaire'
) {
    $printCourseId = (int) ($_GET['course_id'] ?? 0);
    if (!$clms_reports_course_allowed($pdo, $sessionRole, $reportsUserId, $printCourseId)) {
        http_response_code(403);
        echo 'Forbidden or invalid course.';
        exit;
    }
    $courseTitleStmt = $pdo->prepare('SELECT id, title FROM courses WHERE id = :id LIMIT 1');
    $courseTitleStmt->execute(['id' => $printCourseId]);
    $courseRow = $courseTitleStmt->fetch();
    if (!$courseRow) {
        http_response_code(404);
        echo 'Course not found.';
        exit;
    }
    require_once __DIR__ . '/includes/questionnaire-print.php';
    exit;
}

$pageTitle = 'Reports | Criminology LMS';
$activeAdminPage = 'reports';

$errorMessage = '';
$defaultEndDate = date('Y-m-d');
$defaultStartDate = date('Y-m-d', strtotime('-30 days'));

$startDateInput = trim((string) ($_POST['start_date'] ?? ''));
$endDateInput = trim((string) ($_POST['end_date'] ?? ''));
$courseIdInput = (int) ($_POST['course_id'] ?? 0);
$reportTypeInput = (string) ($_POST['report_type'] ?? 'exam_attempts');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    try {
        if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid request token. Please refresh and try again.');
        }

        if ($startDateInput === '' || $endDateInput === '') {
            throw new RuntimeException('Start date and end date are required.');
        }

        $startTs = strtotime($startDateInput . ' 00:00:00');
        $endTs = strtotime($endDateInput . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            throw new RuntimeException('Invalid date format. Use YYYY-MM-DD.');
        }
        if ($startTs > $endTs) {
            throw new RuntimeException('Start date cannot be after end date.');
        }

        $startSql = date('Y-m-d H:i:s', $startTs);
        $endSql = date('Y-m-d H:i:s', $endTs);

        $allowedReportTypes = ['exam_attempts', 'certificates', 'combined'];
        if (!in_array($reportTypeInput, $allowedReportTypes, true)) {
            throw new RuntimeException('Invalid report type.');
        }

        $courseFilterSql = '';
        $courseFilterParams = [];
        $courseLabel = 'all_courses';
        if ($courseIdInput > 0) {
            $courseCheckStmt = $pdo->prepare('SELECT id, title FROM courses WHERE id = :id LIMIT 1');
            $courseCheckStmt->execute(['id' => $courseIdInput]);
            $courseRow = $courseCheckStmt->fetch();
            if (!$courseRow) {
                throw new RuntimeException('Selected course does not exist.');
            }
            if (!$clms_reports_course_allowed($pdo, $sessionRole, $reportsUserId, $courseIdInput)) {
                throw new RuntimeException('You do not have access to that course.');
            }
            $courseFilterSql = ' AND ea.course_id = :course_id';
            $courseFilterParams['course_id'] = $courseIdInput;
            $courseLabel = 'course-' . $courseIdInput;
        }

        $instructorCourseIds = [];
        if ($sessionRole === 'instructor') {
            $icStmt = $pdo->prepare(
                "SELECT DISTINCT c.id
                 FROM courses c
                 LEFT JOIN course_instructors ci ON ci.course_id = c.id AND ci.instructor_user_id = :uid
                 WHERE ci.instructor_user_id IS NOT NULL
                    OR NOT EXISTS (SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id)
                 ORDER BY c.id ASC"
            );
            $icStmt->execute(['uid' => $reportsUserId]);
            $instructorCourseIds = array_map(static fn (array $r): int => (int) $r['id'], $icStmt->fetchAll());
            if ($courseIdInput === 0 && $instructorCourseIds === []) {
                throw new RuntimeException('No courses in your scope to report on.');
            }
        }

        $scopeInSqlExam = '';
        $scopeParamsExam = [];
        if ($sessionRole === 'instructor' && $courseIdInput === 0 && $instructorCourseIds !== []) {
            foreach ($instructorCourseIds as $i => $cid) {
                $key = 'scope_cid_' . $i;
                $scopeInSqlExam .= ($scopeInSqlExam === '' ? '' : ',') . ':' . $key;
                $scopeParamsExam[$key] = $cid;
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fileDateSegment = date('Ymd', $startTs) . '-' . date('Ymd', $endTs);
        $filename = sprintf('clms_%s_%s_%s.csv', $reportTypeInput, $courseLabel, $fileDateSegment);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to open output stream.');
        }

        fputs($output, "\xEF\xBB\xBF");

        if ($reportTypeInput === 'exam_attempts' || $reportTypeInput === 'combined') {
            fputcsv($output, ['=== EXAM ATTEMPTS ===']);
            fputcsv($output, [
                'Attempt ID',
                'Student Name',
                'Student Email',
                'Course Title',
                'Status',
                'Total Score',
                'Passed',
                'Attempted At',
                'Completed At',
            ]);

            $attemptsSql =
                "SELECT
                    ea.id AS attempt_id,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS student_name,
                    u.email,
                    c.title AS course_title,
                    ea.status,
                    ea.total_score,
                    ea.is_passed,
                    ea.attempted_at,
                    ea.completed_at
                 FROM exam_attempts ea
                 INNER JOIN users u ON u.id = ea.user_id
                 INNER JOIN courses c ON c.id = ea.course_id
                 WHERE ea.attempted_at BETWEEN :start_date AND :end_date"
                . $courseFilterSql
                . (
                    $scopeInSqlExam !== ''
                        ? ' AND ea.course_id IN (' . $scopeInSqlExam . ')'
                        : ''
                )
                . ' ORDER BY ea.attempted_at DESC';

            $attemptsStmt = $pdo->prepare($attemptsSql);
            $attemptsParams = array_merge([
                'start_date' => $startSql,
                'end_date' => $endSql,
            ], $courseFilterParams, $scopeParamsExam);
            $attemptsStmt->execute($attemptsParams);

            while ($row = $attemptsStmt->fetch()) {
                fputcsv($output, [
                    (int) $row['attempt_id'],
                    (string) $row['student_name'],
                    (string) $row['email'],
                    (string) $row['course_title'],
                    (string) $row['status'],
                    $row['total_score'] !== null ? number_format((float) $row['total_score'], 2, '.', '') : '',
                    (int) $row['is_passed'] === 1 ? 'Yes' : 'No',
                    (string) $row['attempted_at'],
                    $row['completed_at'] !== null ? (string) $row['completed_at'] : '',
                ]);
            }

            if ($reportTypeInput === 'combined') {
                fputcsv($output, []);
            }
        }

        if ($reportTypeInput === 'certificates' || $reportTypeInput === 'combined') {
            fputcsv($output, ['=== CERTIFICATES ISSUED ===']);
            fputcsv($output, [
                'Certificate ID',
                'Student Name',
                'Student Email',
                'Course Title',
                'Certificate Hash',
                'Issued At',
            ]);

            $certFilterSql = '';
            $certFilterParams = [
                'start_date' => $startSql,
                'end_date' => $endSql,
            ];
            if ($courseIdInput > 0) {
                $certFilterSql = ' AND cert.course_id = :course_id';
                $certFilterParams['course_id'] = $courseIdInput;
            }
            $scopeInSqlCert = '';
            $scopeParamsCert = [];
            if ($sessionRole === 'instructor' && $courseIdInput === 0 && $instructorCourseIds !== []) {
                foreach ($instructorCourseIds as $i => $cid) {
                    $key = 'scope_cert_' . $i;
                    $scopeInSqlCert .= ($scopeInSqlCert === '' ? '' : ',') . ':' . $key;
                    $scopeParamsCert[$key] = $cid;
                }
            }

            $certSql =
                "SELECT
                    cert.id AS certificate_id,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS student_name,
                    u.email,
                    c.title AS course_title,
                    cert.certificate_hash,
                    cert.issued_at
                 FROM certificates cert
                 INNER JOIN users u ON u.id = cert.user_id
                 INNER JOIN courses c ON c.id = cert.course_id
                 WHERE cert.issued_at BETWEEN :start_date AND :end_date"
                . $certFilterSql
                . (
                    $scopeInSqlCert !== ''
                        ? ' AND cert.course_id IN (' . $scopeInSqlCert . ')'
                        : ''
                )
                . ' ORDER BY cert.issued_at DESC';

            $certStmt = $pdo->prepare($certSql);
            $certStmt->execute(array_merge($certFilterParams, $scopeParamsCert));

            while ($row = $certStmt->fetch()) {
                fputcsv($output, [
                    (int) $row['certificate_id'],
                    (string) $row['student_name'],
                    (string) $row['email'],
                    (string) $row['course_title'],
                    (string) $row['certificate_hash'],
                    (string) $row['issued_at'],
                ]);
            }
        }

        fclose($output);
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Failed to generate report. Please try again.';
        if (!($e instanceof RuntimeException)) {
            error_log($e->getMessage());
        }
    }
}

if ($startDateInput === '') {
    $startDateInput = $defaultStartDate;
}
if ($endDateInput === '') {
    $endDateInput = $defaultEndDate;
}

if ($sessionRole === 'instructor') {
    $coursesStmt = $pdo->prepare(
        "SELECT DISTINCT c.id, c.title
         FROM courses c
         LEFT JOIN course_instructors ci ON ci.course_id = c.id AND ci.instructor_user_id = :uid
         WHERE ci.instructor_user_id IS NOT NULL
            OR NOT EXISTS (SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id)
         ORDER BY c.title ASC"
    );
    $coursesStmt->execute(['uid' => $reportsUserId]);
} else {
    $coursesStmt = $pdo->query('SELECT id, title FROM courses ORDER BY title ASC');
}
$courseOptions = $coursesStmt->fetchAll();

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Reports</h4>
                  <small class="text-muted">Select a date range and course, then download a CSV export or print the course questionnaire.</small>
                </div>
              </div>

<?php if ($errorMessage !== '') : ?>
              <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

              <div class="card">
                <h5 class="card-header">Generate Report</h5>
                <div class="card-body">
                  <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/reports.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" value="generate" />

                    <div class="row g-3 mb-4">
                      <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="start_date">Start Date</label>
                        <input
                          type="date"
                          id="start_date"
                          name="start_date"
                          class="form-control"
                          value="<?php echo htmlspecialchars($startDateInput, ENT_QUOTES, 'UTF-8'); ?>"
                          required />
                      </div>
                      <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="end_date">End Date</label>
                        <input
                          type="date"
                          id="end_date"
                          name="end_date"
                          class="form-control"
                          value="<?php echo htmlspecialchars($endDateInput, ENT_QUOTES, 'UTF-8'); ?>"
                          required />
                      </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="course_id">Course</label>
                        <select id="course_id" name="course_id" class="form-select" title="Choose one course to print the questionnaire">
                          <option value="0"><?php echo $sessionRole === 'instructor' ? 'All my courses' : 'All Courses'; ?></option>
<?php foreach ($courseOptions as $courseOption) : ?>
                          <option
                            value="<?php echo (int) $courseOption['id']; ?>"
                            <?php echo (int) $courseOption['id'] === $courseIdInput ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $courseOption['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-6 col-lg-2">
                        <label class="form-label" for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" class="form-select">
                          <option value="exam_attempts" <?php echo $reportTypeInput === 'exam_attempts' ? 'selected' : ''; ?>>Exam Attempts</option>
                          <option value="certificates" <?php echo $reportTypeInput === 'certificates' ? 'selected' : ''; ?>>Certificates</option>
                          <option value="combined" <?php echo $reportTypeInput === 'combined' ? 'selected' : ''; ?>>Combined</option>
                        </select>
                      </div>
                    </div>

                    <div class="alert alert-info mb-4" role="alert">
                      <i class="bx bx-info-circle me-1"></i>
                      Submitting this form will force a CSV file download. No results are shown on screen.
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                      <button type="submit" class="btn btn-primary">
                        <i class="bx bx-download me-1"></i>Download CSV
                      </button>
                      <button type="button" class="btn btn-outline-secondary" id="clmsPrintQuestionnaireBtn">
                        <i class="bx bx-printer me-1"></i>Print questionnaire
                      </button>
                    </div>
                    <p class="text-muted small mt-2 mb-0">
                      <strong>Print questionnaire</strong> opens the full question bank for one course (modules + final exam) in a new tab — choose a course above first (not &quot;All&quot;).
                    </p>
                  </form>
                  <script>
                    (function () {
                      var btn = document.getElementById('clmsPrintQuestionnaireBtn');
                      var sel = document.getElementById('course_id');
                      var base = <?php echo json_encode($clmsWebBase . '/admin/reports.php', JSON_UNESCAPED_SLASHES); ?>;
                      if (!btn || !sel) return;
                      btn.addEventListener('click', function () {
                        var id = parseInt(sel.value, 10) || 0;
                        if (id <= 0) {
                          alert('Please choose a specific course before printing the questionnaire.');
                          return;
                        }
                        window.open(base + '?action=print_questionnaire&course_id=' + id, '_blank', 'noopener,noreferrer');
                      });
                    })();
                  </script>
                </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
