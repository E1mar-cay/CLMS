<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

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
            $courseFilterSql = ' AND ea.course_id = :course_id';
            $courseFilterParams['course_id'] = $courseIdInput;
            $courseLabel = 'course-' . $courseIdInput;
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
                . ' ORDER BY ea.attempted_at DESC';

            $attemptsStmt = $pdo->prepare($attemptsSql);
            $attemptsParams = array_merge([
                'start_date' => $startSql,
                'end_date' => $endSql,
            ], $courseFilterParams);
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
                . ' ORDER BY cert.issued_at DESC';

            $certStmt = $pdo->prepare($certSql);
            $certStmt->execute($certFilterParams);

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

$coursesStmt = $pdo->query('SELECT id, title FROM courses ORDER BY title ASC');
$courseOptions = $coursesStmt->fetchAll();

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Reports</h4>
                  <small class="text-muted">Select a date range and course, then download a CSV export of exam attempts and/or certificates.</small>
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
                        <select id="course_id" name="course_id" class="form-select">
                          <option value="0">All Courses</option>
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

                    <button type="submit" class="btn btn-primary">
                      <i class="bx bx-download me-1"></i>Download CSV
                    </button>
                  </form>
                </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
