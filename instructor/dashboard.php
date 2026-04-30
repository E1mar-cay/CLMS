<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['instructor']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS course_instructors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        instructor_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_course_instructor (course_id, instructor_user_id),
        CONSTRAINT fk_course_instructors_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_course_instructors_user FOREIGN KEY (instructor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

$instructorId = (int) $_SESSION['user_id'];
$courseScopePage = (int) filter_input(INPUT_GET, 'course_page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$courseScopePerPage = 5;
$courseScopeOffset = ($courseScopePage - 1) * $courseScopePerPage;

$instructorName = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
if ($instructorName === '') {
    $instructorName = (string) ($_SESSION['email'] ?? 'Instructor');
}

$metricsStmt = $pdo->prepare(
    "SELECT
        COUNT(DISTINCT c.id) AS assigned_courses,
        COUNT(DISTINCT m.id) AS total_modules,
        COUNT(DISTINCT q.id) AS total_questions,
        COUNT(DISTINCT CASE WHEN ea.status = 'completed' AND ea.is_passed = 1 THEN ea.id END) AS attempts_passed,
        COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.id END) AS completed_module_records
     FROM course_instructors ci
     INNER JOIN courses c ON c.id = ci.course_id
     LEFT JOIN modules m ON m.course_id = c.id
     LEFT JOIN questions q ON q.course_id = c.id
     LEFT JOIN user_progress up ON up.module_id = m.id
     LEFT JOIN exam_attempts ea ON ea.course_id = c.id
     WHERE ci.instructor_user_id = :instructor_id"
);
$metricsStmt->execute(['instructor_id' => $instructorId]);
$metrics = $metricsStmt->fetch() ?: [];

$assignedCourses = (int) ($metrics['assigned_courses'] ?? 0);
$totalModules = (int) ($metrics['total_modules'] ?? 0);
$totalQuestions = (int) ($metrics['total_questions'] ?? 0);
$attemptsPassed = (int) ($metrics['attempts_passed'] ?? 0);
$completedModuleRecords = (int) ($metrics['completed_module_records'] ?? 0);

$courseScopeCountStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total
     FROM courses c
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     WHERE ci.instructor_user_id IS NOT NULL
        OR NOT EXISTS (
            SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id
        )"
);
$courseScopeCountStmt->execute(['instructor_id' => $instructorId]);
$courseScopeTotalRows = (int) ($courseScopeCountStmt->fetch()['total'] ?? 0);
$courseScopeTotalPages = max(1, (int) ceil($courseScopeTotalRows / $courseScopePerPage));
if ($courseScopePage > $courseScopeTotalPages) {
    $courseScopePage = $courseScopeTotalPages;
    $courseScopeOffset = ($courseScopePage - 1) * $courseScopePerPage;
}

$courseSummaryStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.title,
        c.level,
        COUNT(DISTINCT m.id) AS module_count,
        COUNT(DISTINCT q.id) AS question_count,
        COUNT(DISTINCT CASE WHEN ea.status = 'completed' THEN ea.user_id END) AS active_students,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN (CASE WHEN ea.is_passed = 1 THEN 100 ELSE 0 END) END), 0) AS pass_rate
     FROM courses c
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     LEFT JOIN modules m ON m.course_id = c.id
     LEFT JOIN questions q ON q.course_id = c.id
     LEFT JOIN exam_attempts ea ON ea.course_id = c.id
     WHERE ci.instructor_user_id IS NOT NULL
        OR NOT EXISTS (
            SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id
        )
     GROUP BY c.id, c.title, c.level
     ORDER BY c.title ASC
     LIMIT :limit OFFSET :offset"
);
$courseSummaryStmt->bindValue(':instructor_id', $instructorId, PDO::PARAM_INT);
$courseSummaryStmt->bindValue(':limit', $courseScopePerPage, PDO::PARAM_INT);
$courseSummaryStmt->bindValue(':offset', $courseScopeOffset, PDO::PARAM_INT);
$courseSummaryStmt->execute();
$courseSummaries = $courseSummaryStmt->fetchAll();

$recentActivityStmt = $pdo->prepare(
    "SELECT
        ea.attempted_at AS activity_time,
        ea.status,
        u.first_name,
        u.last_name,
        c.title AS course_title
     FROM exam_attempts ea
     INNER JOIN courses c ON c.id = ea.course_id
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     INNER JOIN users u ON u.id = ea.user_id
     WHERE ci.instructor_user_id IS NOT NULL
        OR NOT EXISTS (
            SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id
        )
     ORDER BY ea.attempted_at DESC
     LIMIT 6"
);
$recentActivityStmt->execute(['instructor_id' => $instructorId]);
$recentActivities = $recentActivityStmt->fetchAll();

$upcomingExamStmt = $pdo->prepare(
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
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     WHERE ea.status = 'in_progress'
       AND (
         ci.instructor_user_id IS NOT NULL
         OR NOT EXISTS (
           SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id
         )
       )
     ORDER BY ea.attempted_at DESC
     LIMIT 5"
);
$upcomingExamStmt->execute(['instructor_id' => $instructorId]);
$upcomingExams = $upcomingExamStmt->fetchAll();

$topStudentsStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.first_name,
        u.last_name,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COUNT(DISTINCT CASE WHEN ea.status = 'completed' THEN ea.id END) AS attempts_completed,
        SUM(CASE WHEN ea.status = 'completed' AND ea.is_passed = 1 THEN 1 ELSE 0 END) AS attempts_passed
     FROM exam_attempts ea
     INNER JOIN courses c ON c.id = ea.course_id
     LEFT JOIN course_instructors ci
       ON ci.course_id = c.id
      AND ci.instructor_user_id = :instructor_id
     INNER JOIN users u ON u.id = ea.user_id
     WHERE u.role = 'student'
       AND (
         ci.instructor_user_id IS NOT NULL
         OR NOT EXISTS (
           SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id
         )
       )
     GROUP BY u.id, u.first_name, u.last_name
     HAVING attempts_completed > 0
     ORDER BY avg_score DESC, attempts_completed DESC
     LIMIT 5"
);
$topStudentsStmt->execute(['instructor_id' => $instructorId]);
$topStudents = $topStudentsStmt->fetchAll();

$questionTypeStmt = $pdo->prepare(
    "SELECT q.question_type, COUNT(*) AS total
     FROM questions q
     INNER JOIN course_instructors ci ON ci.course_id = q.course_id AND ci.instructor_user_id = :instructor_id
     GROUP BY q.question_type
     ORDER BY total DESC"
);
$questionTypeStmt->execute(['instructor_id' => $instructorId]);
$questionTypeRows = $questionTypeStmt->fetchAll();

$questionTypeTotal = 0;
foreach ($questionTypeRows as $row) {
    $questionTypeTotal += (int) $row['total'];
}

$latestAnnouncement = null;
try {
    $latestAnnouncementStmt = $pdo->query(
        "SELECT title, body, created_at
         FROM announcements
         WHERE is_active = 1
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $latestAnnouncement = $latestAnnouncementStmt->fetch() ?: null;
} catch (Throwable $e) {
    $latestAnnouncement = null;
}

$pageTitle = 'Instructor Dashboard | Criminology LMS';
$activeInstructorPage = 'dashboard';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-3 mb-3">
                <div>
                  <h4 class="fw-bold mb-1">Welcome back, <?php echo htmlspecialchars($instructorName, ENT_QUOTES, 'UTF-8'); ?>!</h4>
                  <small class="text-muted">Here's an overview of your assigned courses and student activity.</small>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                  <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm">
                    <i class="bx bx-plus-circle me-1"></i>Add Question
                  </a>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body d-flex align-items-start justify-content-between">
                      <div>
                        <small class="text-muted d-block mb-1">Assigned Courses</small>
                        <h3 class="mb-0"><?php echo $assignedCourses; ?></h3>
                        <small class="text-muted">In your scope</small>
                      </div>
                      <div class="avatar avatar-sm bg-label-primary rounded d-inline-flex align-items-center justify-content-center">
                        <i class="bx bx-book-content fs-4"></i>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body d-flex align-items-start justify-content-between">
                      <div>
                        <small class="text-muted d-block mb-1">Total Modules</small>
                        <h3 class="mb-0"><?php echo $totalModules; ?></h3>
                        <small class="text-muted"><?php echo $completedModuleRecords; ?> completions</small>
                      </div>
                      <div class="avatar avatar-sm bg-label-info rounded d-inline-flex align-items-center justify-content-center">
                        <i class="bx bx-layer fs-4"></i>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body d-flex align-items-start justify-content-between">
                      <div>
                        <small class="text-muted d-block mb-1">Total Questions</small>
                        <h3 class="mb-0"><?php echo $totalQuestions; ?></h3>
                        <small class="text-muted">Across all courses</small>
                      </div>
                      <div class="avatar avatar-sm bg-label-success rounded d-inline-flex align-items-center justify-content-center">
                        <i class="bx bx-help-circle fs-4"></i>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-xl-3">
                  <div class="card h-100 clms-metric-card">
                    <div class="card-body d-flex align-items-start justify-content-between">
                      <div>
                        <small class="text-muted d-block mb-1">Attempts Passed</small>
                        <h3 class="mb-0"><?php echo $attemptsPassed; ?></h3>
                        <small class="text-muted">Across your courses</small>
                      </div>
                      <div class="avatar avatar-sm bg-label-warning rounded d-inline-flex align-items-center justify-content-center">
                        <i class="bx bx-trophy fs-4"></i>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-4 clms-highlight" id="announcements">
                <div class="card-body">
<?php if (is_array($latestAnnouncement)) : ?>
                  <h6 class="text-white mb-1"><?php echo htmlspecialchars((string) ($latestAnnouncement['title'] ?? 'Announcement'), ENT_QUOTES, 'UTF-8'); ?></h6>
                  <p class="mb-0"><?php echo nl2br(htmlspecialchars((string) ($latestAnnouncement['body'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                  <small class="d-block mt-2 opacity-75">
                    Posted <?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) ($latestAnnouncement['created_at'] ?? '')) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
                  </small>
<?php else : ?>
                  <h6 class="text-white mb-1">Announcement</h6>
                  <p class="mb-0">No active announcements at the moment.</p>
<?php endif; ?>
                </div>
              </div>

              <div class="row g-4">
                <div class="col-xl-8">
                  <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="mb-0">My Course Scope</h5>
                      <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bx bx-cog me-1"></i>Manage Content
                      </a>
                    </div>
                    <div class="card-body">
<?php if ($courseSummaries === []) : ?>
                      <div class="text-center py-4 text-muted">
                        <i class="bx bx-book-open display-6 d-block mb-2"></i>
                        <p class="mb-0">No courses assigned yet. Contact an administrator to be enrolled as an instructor.</p>
                      </div>
<?php else : ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Course</th>
                              <th class="text-center">Modules</th>
                              <th class="text-center">Questions</th>
                              <th class="text-center">Students</th>
                              <th>Pass Rate</th>
                              <th class="text-end">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($courseSummaries as $course) :
    $level = strtolower((string) ($course['level'] ?? ''));
    $levelClass = match ($level) {
        'beginner' => 'bg-label-success',
        'intermediate' => 'bg-label-warning',
        'advanced' => 'bg-label-danger',
        default => 'bg-label-secondary',
    };
    $passRate = (float) ($course['pass_rate'] ?? 0.0);
?>
                            <tr>
                              <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php if ($level !== '') : ?>
                                <span class="badge <?php echo $levelClass; ?> mt-1">
                                  <?php echo htmlspecialchars(ucfirst($level), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
<?php endif; ?>
                              </td>
                              <td class="text-center"><?php echo (int) $course['module_count']; ?></td>
                              <td class="text-center"><?php echo (int) $course['question_count']; ?></td>
                              <td class="text-center"><?php echo (int) $course['active_students']; ?></td>
                              <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                  <div class="progress flex-grow-1" style="height: 6px;">
                                    <div class="progress-bar bg-primary"
                                         role="progressbar"
                                         style="width: <?php echo (int) round($passRate); ?>%;"
                                         aria-valuenow="<?php echo (int) round($passRate); ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100"></div>
                                  </div>
                                  <small class="text-muted"><?php echo number_format($passRate, 0); ?>%</small>
                                </div>
                              </td>
                              <td class="text-end">
                                <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                   class="btn btn-sm btn-icon btn-outline-secondary"
                                   title="Manage content">
                                  <i class="bx bx-cog"></i>
                                </a>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
<?php endif; ?>
                    </div>
<?php if ($courseScopeTotalPages > 1) : ?>
                    <div class="card-footer bg-transparent">
                      <nav aria-label="Course scope pagination">
                        <ul class="pagination pagination-sm mb-0 justify-content-end">
<?php
  $prevParams = [];
  if ($courseScopePage > 2) {
      $prevParams['course_page'] = $courseScopePage - 1;
  }
  $prevUrl = $clmsWebBase . '/instructor/dashboard.php' . ($prevParams !== [] ? '?' . http_build_query($prevParams) : '');
?>
                          <li class="page-item <?php echo $courseScopePage <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                          </li>
<?php for ($pageNumber = 1; $pageNumber <= $courseScopeTotalPages; $pageNumber++) : ?>
<?php
  $pageParams = [];
  if ($pageNumber > 1) {
      $pageParams['course_page'] = $pageNumber;
  }
  $pageUrl = $clmsWebBase . '/instructor/dashboard.php' . ($pageParams !== [] ? '?' . http_build_query($pageParams) : '');
?>
                          <li class="page-item <?php echo $pageNumber === $courseScopePage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $pageNumber; ?></a>
                          </li>
<?php endfor; ?>
<?php
  $nextParams = ['course_page' => min($courseScopeTotalPages, $courseScopePage + 1)];
  $nextUrl = $clmsWebBase . '/instructor/dashboard.php?' . http_build_query($nextParams);
?>
                          <li class="page-item <?php echo $courseScopePage >= $courseScopeTotalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                          </li>
                        </ul>
                      </nav>
                    </div>
<?php endif; ?>
                  </div>

                  <div class="card">
                    <h5 class="card-header">Top Performing Students</h5>
                    <div class="card-body">
<?php if ($topStudents === []) : ?>
                      <div class="text-center py-4 text-muted">
                        <i class="bx bx-user-pin display-6 d-block mb-2"></i>
                        <p class="mb-0">No completed exam attempts yet in your courses.</p>
                      </div>
<?php else : ?>
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>#</th>
                              <th>Student</th>
                              <th class="text-center">Attempts</th>
                              <th class="text-center">Passed</th>
                              <th>Avg. Score</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($topStudents as $idx => $student) :
    $fullName = trim((string) $student['first_name'] . ' ' . (string) $student['last_name']);
    if ($fullName === '') $fullName = 'Student';
    $avgScore = (float) ($student['avg_score'] ?? 0.0);
    $parts = preg_split('/\s+/', $fullName) ?: [];
    $initials = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = 'S';
?>
                            <tr>
                              <td><?php echo $idx + 1; ?></td>
                              <td>
                                <div class="d-flex align-items-center gap-2">
                                  <div class="rounded bg-label-primary d-flex align-items-center justify-content-center"
                                       style="width: 34px; height: 34px; font-weight: 600; font-size: .85rem;">
                                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                                  </div>
                                  <div class="fw-semibold"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                              </td>
                              <td class="text-center"><?php echo (int) $student['attempts_completed']; ?></td>
                              <td class="text-center"><?php echo (int) $student['attempts_passed']; ?></td>
                              <td style="min-width: 160px;">
                                <div class="d-flex align-items-center gap-2">
                                  <div class="progress flex-grow-1" style="height: 6px;">
                                    <div class="progress-bar bg-success"
                                         role="progressbar"
                                         style="width: <?php echo (int) round($avgScore); ?>%;"
                                         aria-valuenow="<?php echo (int) round($avgScore); ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100"></div>
                                  </div>
                                  <small class="fw-semibold"><?php echo number_format($avgScore, 1); ?>%</small>
                                </div>
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

                <div class="col-xl-4">
                  <div class="card mb-4">
                    <h5 class="card-header d-flex justify-content-between align-items-center">
                      <span>Students Who Are Taking Exam</span>
                    </h5>
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

                  <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="mb-0">Question Mix</h5>
<?php if ($questionTypeTotal > 0) : ?>
                      <span class="badge bg-label-primary"><?php echo $questionTypeTotal; ?> total</span>
<?php endif; ?>
                    </div>
                    <div class="card-body">
<?php if ($questionTypeRows === []) : ?>
                      <div class="text-center py-3 text-muted">
                        <i class="bx bx-help-circle display-6 d-block mb-2"></i>
                        <p class="mb-0 small">You haven't added questions yet.</p>
                        <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php', ENT_QUOTES, 'UTF-8'); ?>"
                           class="btn btn-sm btn-primary mt-2">
                          <i class="bx bx-plus me-1"></i>Add your first
                        </a>
                      </div>
<?php else :
    $colorCycle = ['bg-primary', 'bg-success', 'bg-info', 'bg-warning', 'bg-danger', 'bg-secondary'];
?>
<?php foreach ($questionTypeRows as $idx => $qt) :
    $count = (int) $qt['total'];
    $pct = $questionTypeTotal > 0 ? round(($count / $questionTypeTotal) * 100) : 0;
    $label = clms_question_type_label((string) $qt['question_type']);
    $color = $colorCycle[$idx % count($colorCycle)];
?>
                      <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                          <small class="fw-semibold"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></small>
                          <small class="text-muted"><?php echo $count; ?> (<?php echo (int) $pct; ?>%)</small>
                        </div>
                        <div class="progress" style="height: 6px;">
                          <div class="progress-bar <?php echo $color; ?>"
                               role="progressbar"
                               style="width: <?php echo (int) $pct; ?>%;"
                               aria-valuenow="<?php echo (int) $pct; ?>"
                               aria-valuemin="0"
                               aria-valuemax="100"></div>
                        </div>
                      </div>
<?php endforeach; ?>
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

              <script>
                (() => {
                  const REFRESH_MS = 30000;
                  let timerId = null;

                  const scheduleRefresh = () => {
                    if (timerId !== null) {
                      clearTimeout(timerId);
                    }
                    if (document.hidden) {
                      return;
                    }
                    timerId = window.setTimeout(() => {
                      window.location.reload();
                    }, REFRESH_MS);
                  };

                  document.addEventListener('visibilitychange', scheduleRefresh);
                  scheduleRefresh();
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
