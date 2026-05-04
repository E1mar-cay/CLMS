<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Admin Dashboard | Criminology LMS';
$activeAdminPage = 'dashboard';
$adminName = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
if ($adminName === '') {
    $adminName = (string) ($_SESSION['email'] ?? 'Admin');
}

$activeStudentsStmt = $pdo->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
$activeStudents = (int) ($activeStudentsStmt->fetch()['total'] ?? 0);

$modulesAvailableStmt = $pdo->query('SELECT COUNT(*) AS total FROM modules');
$modulesAvailable = (int) ($modulesAvailableStmt->fetch()['total'] ?? 0);

$tasksCompletedStmt = $pdo->query(
    "SELECT COUNT(*) AS total
     FROM exam_attempts
     WHERE status = 'completed'
       AND YEARWEEK(completed_at, 1) = YEARWEEK(NOW(), 1)"
);
$tasksCompletedThisWeek = (int) ($tasksCompletedStmt->fetch()['total'] ?? 0);

$avgPassStmt = $pdo->query(
    "SELECT COALESCE(AVG(CASE WHEN is_passed = 1 THEN 100 ELSE 0 END), 0) AS avg_pass
     FROM exam_attempts
     WHERE status = 'completed'"
);
$avgPassRate = (float) ($avgPassStmt->fetch()['avg_pass'] ?? 0.0);

$globalTotalModulesStmt = $pdo->query('SELECT COUNT(*) AS total FROM modules');
$globalTotalModules = (int) ($globalTotalModulesStmt->fetch()['total'] ?? 0);

$topStudentsStmt = $pdo->query(
    "SELECT
        u.id,
        u.first_name,
        u.last_name,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.module_id END) AS modules_done,
        CASE
            WHEN COUNT(CASE WHEN ea.status IN ('completed','pending_manual_grade') THEN 1 END) > 0 THEN 'On Track'
            ELSE 'Review'
        END AS status_label
     FROM users u
     LEFT JOIN exam_attempts ea ON ea.user_id = u.id
     LEFT JOIN user_progress up ON up.user_id = u.id
     WHERE u.role = 'student'
     GROUP BY u.id, u.first_name, u.last_name
     ORDER BY avg_score DESC, modules_done DESC
     LIMIT 5"
);
$topStudents = $topStudentsStmt->fetchAll();

$moduleCompletionStmt = $pdo->query(
    "SELECT
        m.id,
        m.title,
        COALESCE(SUM(CASE WHEN up.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_count
     FROM modules m
     LEFT JOIN user_progress up ON up.module_id = m.id
     GROUP BY m.id, m.title
     ORDER BY completed_count DESC, m.id ASC
     LIMIT 6"
);
$moduleCompletionRows = $moduleCompletionStmt->fetchAll();
$moduleCompletionRows = array_map(
    static function (array $row) use ($activeStudents): array {
        $completed = (int) $row['completed_count'];
        $rate = $activeStudents > 0 ? round(($completed / $activeStudents) * 100) : 0;
        $row['completion_rate'] = (int) $rate;
        return $row;
    },
    $moduleCompletionRows
);

$upcomingExamStmt = $pdo->query(
    "SELECT
        ea.id,
        ea.attempted_at,
        ea.deadline_at,
        u.first_name,
        u.last_name,
        c.title AS course_title,
        COALESCE(c.final_exam_duration_minutes, 45) AS final_exam_duration_minutes
     FROM exam_attempts ea
     INNER JOIN users u ON u.id = ea.user_id
     INNER JOIN courses c ON c.id = ea.course_id
     WHERE ea.status = 'in_progress'
     ORDER BY ea.attempted_at DESC
     LIMIT 5"
);
$upcomingExams = $upcomingExamStmt->fetchAll();

$recentActivityStmt = $pdo->query(
    "SELECT
        ea.attempted_at AS activity_time,
        u.first_name,
        u.last_name,
        c.title AS course_title,
        ea.status
     FROM exam_attempts ea
     INNER JOIN users u ON u.id = ea.user_id
     INNER JOIN courses c ON c.id = ea.course_id
     ORDER BY ea.attempted_at DESC
     LIMIT 6"
);
$recentActivities = $recentActivityStmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'summary') {
    if ((string) ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Export is available to administrators only.';
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clms_system_summary_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    fputcsv($out, ['CLMS — System summary export']);
    fputcsv($out, ['Generated (server)', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    fputcsv($out, ['=== Executive metrics ===']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Students (accounts)', $activeStudents]);
    require_once dirname(__DIR__) . '/includes/student-batch-schema.php';
    clms_ensure_users_student_batch_column($pdo);

    $instructorCount = (int) ($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'instructor'")->fetch()['c'] ?? 0);
    $adminCount = (int) ($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch()['c'] ?? 0);
    fputcsv($out, ['Instructors', $instructorCount]);
    fputcsv($out, ['Administrators', $adminCount]);
    fputcsv($out, ['Modules (rows)', $modulesAvailable]);
    fputcsv($out, ['Courses', (int) ($pdo->query('SELECT COUNT(*) AS c FROM courses')->fetch()['c'] ?? 0)]);
    fputcsv($out, ['Final exam attempts completed (all time)', (int) ($pdo->query("SELECT COUNT(*) AS c FROM exam_attempts WHERE status = 'completed'")->fetch()['c'] ?? 0)]);
    fputcsv($out, ['Final exam attempts completed (this week)', $tasksCompletedThisWeek]);
    fputcsv($out, ['Avg. pass rate completed exams (%)', number_format($avgPassRate, 2)]);
    $certTotal = (int) ($pdo->query('SELECT COUNT(*) AS c FROM certificates')->fetch()['c'] ?? 0);
    fputcsv($out, ['Certificates issued (all time)', $certTotal]);
    $mqaTotal = (int) ($pdo->query('SELECT COUNT(*) AS c FROM module_quiz_attempts')->fetch()['c'] ?? 0);
    fputcsv($out, ['Module quiz attempts (all time)', $mqaTotal]);
    fputcsv($out, []);

    fputcsv($out, ['=== Courses (master list) ===']);
    fputcsv($out, ['ID', 'Title', 'Published (1=yes)', 'Module count', 'Final exam questions']);
    $courseExportStmt = $pdo->query(
        "SELECT c.id, c.title, c.is_published,
                COUNT(DISTINCT m.id) AS module_cnt,
                (SELECT COUNT(*) FROM questions q WHERE q.course_id = c.id AND q.module_id IS NULL) AS final_q
         FROM courses c
         LEFT JOIN modules m ON m.course_id = c.id
         GROUP BY c.id, c.title, c.is_published
         ORDER BY c.title ASC"
    );
    while ($cr = $courseExportStmt->fetch()) {
        fputcsv($out, [
            (int) $cr['id'],
            (string) $cr['title'],
            (int) $cr['is_published'],
            (int) $cr['module_cnt'],
            (int) $cr['final_q'],
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== Top students (dashboard metric, up to 5) ===']);
    fputcsv($out, ['Name', 'Average score (%)', 'Modules done', 'Status']);
    foreach ($topStudents as $student) {
        fputcsv($out, [
            trim((string) $student['first_name'] . ' ' . (string) $student['last_name']),
            number_format((float) $student['avg_score'], 2),
            (int) $student['modules_done'] . '/' . $globalTotalModules,
            (string) $student['status_label'],
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== Extended top learners (module quiz focus) ===']);
    fputcsv($out, ['Rank', 'Name', 'Email', 'Avg quiz %', 'Quiz attempts', 'Distinct modules tried']);
    $extTop = $pdo->query(
        "SELECT u.first_name, u.last_name, u.email,
                ROUND(AVG(mqa.percentage), 2) AS avg_pct,
                COUNT(mqa.id) AS attempts,
                COUNT(DISTINCT mqa.module_id) AS modules_tried
         FROM users u
         INNER JOIN module_quiz_attempts mqa ON mqa.user_id = u.id
         WHERE u.role = 'student'
         GROUP BY u.id, u.first_name, u.last_name, u.email
         ORDER BY avg_pct DESC, attempts DESC
         LIMIT 25"
    );
    $rank = 0;
    while ($er = $extTop->fetch()) {
        $rank++;
        fputcsv($out, [
            $rank,
            trim((string) $er['first_name'] . ' ' . (string) $er['last_name']),
            (string) $er['email'],
            (string) $er['avg_pct'],
            (int) $er['attempts'],
            (int) $er['modules_tried'],
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== Students by batch (cohort label) ===']);
    fputcsv($out, ['Batch label', 'Student count']);
    $batchAgg = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(student_batch), ''), '(Unspecified)') AS batch_label, COUNT(*) AS cnt
         FROM users
         WHERE role = 'student'
         GROUP BY COALESCE(NULLIF(TRIM(student_batch), ''), '(Unspecified)')
         ORDER BY cnt DESC, batch_label ASC"
    );
    while ($br = $batchAgg->fetch()) {
        fputcsv($out, [(string) $br['batch_label'], (int) $br['cnt']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== Module completion snapshot (dashboard widget) ===']);
    fputcsv($out, ['Module', 'Completed count', 'Completion % of students']);
    foreach ($moduleCompletionRows as $moduleRow) {
        fputcsv($out, [
            (string) $moduleRow['title'],
            (int) $moduleRow['completed_count'],
            (int) $moduleRow['completion_rate'],
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== In-progress final exams ===']);
    fputcsv($out, ['Student', 'Course', 'Attempted at']);
    foreach ($upcomingExams as $exam) {
        fputcsv($out, [
            trim((string) $exam['first_name'] . ' ' . (string) $exam['last_name']),
            (string) $exam['course_title'],
            (string) $exam['attempted_at'],
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== Recent exam activity (last 20) ===']);
    fputcsv($out, ['When', 'Student', 'Course', 'Status']);
    $recentEx = $pdo->query(
        "SELECT ea.attempted_at, u.first_name, u.last_name, c.title AS course_title, ea.status
         FROM exam_attempts ea
         INNER JOIN users u ON u.id = ea.user_id
         INNER JOIN courses c ON c.id = ea.course_id
         ORDER BY ea.attempted_at DESC
         LIMIT 20"
    );
    while ($rx = $recentEx->fetch()) {
        fputcsv($out, [
            (string) $rx['attempted_at'],
            trim((string) $rx['first_name'] . ' ' . (string) $rx['last_name']),
            (string) $rx['course_title'],
            (string) $rx['status'],
        ]);
    }

    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-3 mb-3">
                <div>
                  <h4 class="fw-bold mb-1">Welcome back, <?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></h4>
                  <small class="text-muted">Review training progress and learner performance.</small>
                </div>
                <div class="d-flex gap-2">
<?php if ((string) ($_SESSION['role'] ?? '') === 'admin') : ?>
                  <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/dashboard.php?export=summary', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">Export Report</a>
<?php endif; ?>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body">
                      <small class="text-muted d-block mb-1">Active Students</small>
                      <h3 class="mb-1"><?php echo $activeStudents; ?></h3>
                      <small class="text-success">+<?php echo max(1, (int) round($activeStudents * 0.08)); ?> this month</small>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body">
                      <small class="text-muted d-block mb-1">Modules Available</small>
                      <h3 class="mb-1"><?php echo $modulesAvailable; ?></h3>
                      <small class="text-success">+<?php echo max(1, (int) round($modulesAvailable * 0.12)); ?> updated this week</small>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body">
                      <small class="text-muted d-block mb-1">Tasks Completed</small>
                      <h3 class="mb-1"><?php echo $tasksCompletedThisWeek; ?></h3>
                      <small class="text-success">This week</small>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body">
                      <small class="text-muted d-block mb-1">Avg. Pass Rate</small>
                      <h3 class="mb-1"><?php echo number_format($avgPassRate, 2); ?>%</h3>
                      <small class="text-success">Across completed attempts</small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-4">
                <div class="col-xl-8">
                  <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="mb-0">Top Performing Students</h5>
                    </div>
                    <div class="card-body">
<?php if ($topStudents === []) : ?>
                      <p class="mb-0">No student records available yet.</p>
<?php else : ?>
                      <div class="table-responsive text-nowrap">
                        <table class="table">
                          <thead>
                            <tr>
                              <th>#</th>
                              <th>Student Name</th>
                              <th>Avg. Score</th>
                              <th>Modules Done</th>
                              <th>Status</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($topStudents as $idx => $student) : ?>
                            <tr>
                              <td><?php echo $idx + 1; ?></td>
                              <td><?php echo htmlspecialchars(trim((string) $student['first_name'] . ' ' . (string) $student['last_name']), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td><?php echo number_format((float) $student['avg_score'], 2); ?>%</td>
                              <td><?php echo (int) $student['modules_done']; ?>/<?php echo $globalTotalModules; ?></td>
                              <td>
<?php $isOnTrack = (string) $student['status_label'] === 'On Track'; ?>
                                <span class="badge <?php echo $isOnTrack ? 'bg-label-success' : 'bg-label-warning'; ?>">
                                  <?php echo htmlspecialchars((string) $student['status_label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card">
                    <h5 class="card-header">Module Completion Rate</h5>
                    <div class="card-body">
<?php if ($moduleCompletionRows === []) : ?>
                      <p class="mb-0">No module data available yet.</p>
<?php else : ?>
<?php foreach ($moduleCompletionRows as $moduleRow) : ?>
                      <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                          <small><?php echo htmlspecialchars((string) $moduleRow['title'], ENT_QUOTES, 'UTF-8'); ?></small>
                          <small><?php echo (int) $moduleRow['completion_rate']; ?>%</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                          <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int) $moduleRow['completion_rate']; ?>%;" aria-valuenow="<?php echo (int) $moduleRow['completion_rate']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                      </div>
<?php endforeach; ?>
<?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card mb-4">
                    <h5 class="card-header">Students Who Are Taking Exam</h5>
                    <div class="card-body">
<?php if ($upcomingExams === []) : ?>
                      <p class="mb-0">No active exam attempts.</p>
<?php else : ?>
                      <div class="d-flex flex-column gap-3">
<?php foreach ($upcomingExams as $exam) : ?>
<?php
  $attemptedTs = strtotime((string) $exam['attempted_at']) ?: time();
  $deadlineTs = null;
  if (!empty($exam['deadline_at'])) {
      $deadlineParsed = strtotime((string) $exam['deadline_at']);
      if ($deadlineParsed !== false) {
          $deadlineTs = $deadlineParsed;
      }
  }
  if ($deadlineTs === null) {
      $durationMinutes = (int) ($exam['final_exam_duration_minutes'] ?? 45);
      if ($durationMinutes < 1) {
          $durationMinutes = 45;
      }
      $deadlineTs = $attemptedTs + ($durationMinutes * 60);
  }
  $remainingSeconds = $deadlineTs - time();
  if ($remainingSeconds > 0) {
      $hours = intdiv($remainingSeconds, 3600);
      $minutes = intdiv($remainingSeconds % 3600, 60);
      $seconds = $remainingSeconds % 60;
      if ($hours > 0) {
          $remainingLabel = 'Ends in ' . $hours . 'h ' . $minutes . 'm';
      } else {
          $remainingLabel = 'Ends in ' . $minutes . 'm ' . $seconds . 's';
      }
      $remainingClass = 'text-warning';
  } else {
      $remainingLabel = 'Overtime';
      $remainingClass = 'text-danger';
  }
?>
                        <div class="clms-list-item">
                          <div class="fw-semibold"><?php echo htmlspecialchars(trim((string) $exam['first_name'] . ' ' . (string) $exam['last_name']), ENT_QUOTES, 'UTF-8'); ?></div>
                          <small class="text-muted d-block"><?php echo htmlspecialchars((string) $exam['course_title'], ENT_QUOTES, 'UTF-8'); ?></small>
                          <small class="<?php echo $remainingClass; ?>"><?php echo htmlspecialchars($remainingLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
<?php endforeach; ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card">
                    <h5 class="card-header">Recent Activity</h5>
                    <div class="card-body">
<?php if ($recentActivities === []) : ?>
                      <p class="mb-0">No recent activity.</p>
<?php else : ?>
                      <ul class="list-unstyled mb-0">
<?php foreach ($recentActivities as $activity) : ?>
                        <li class="mb-3">
                          <div class="fw-semibold"><?php echo htmlspecialchars(trim((string) $activity['first_name'] . ' ' . (string) $activity['last_name']), ENT_QUOTES, 'UTF-8'); ?></div>
                          <small class="text-muted d-block"><?php echo htmlspecialchars((string) $activity['course_title'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $activity['status'])), ENT_QUOTES, 'UTF-8'); ?></small>
                          <small class="text-muted"><?php echo htmlspecialchars((string) date('M j, g:i A', strtotime((string) $activity['activity_time']) ?: time()), ENT_QUOTES, 'UTF-8'); ?></small>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
