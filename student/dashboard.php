<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['student']);

foreach (['level' => "VARCHAR(20) NULL", 'thumbnail_url' => 'VARCHAR(500) NULL'] as $columnName => $columnSpec) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM courses LIKE '" . $columnName . "'")->fetch();
        if (!$check) {
            $pdo->exec('ALTER TABLE courses ADD COLUMN ' . $columnName . ' ' . $columnSpec);
        }
    } catch (Throwable $e) {
        error_log('courses.' . $columnName . ' migration failed: ' . $e->getMessage());
    }
}

$pageTitle = 'Student Dashboard | Criminology LMS';
$loadStudentDashboardCss = true;
$activeStudentPage = 'dashboard';
$successMessage = '';
$errorMessage = '';
$userId = (int) $_SESSION['user_id'];
$notice = (string) ($_GET['notice'] ?? '');

$studentName = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
if ($studentName === '') {
    $studentName = (string) ($_SESSION['email'] ?? 'Student');
}
$firstNameOnly = trim((string) ($_SESSION['first_name'] ?? ''));
if ($firstNameOnly === '') {
    $firstNameOnly = $studentName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $enrollCourseId = filter_input(INPUT_POST, 'enroll_course_id', FILTER_VALIDATE_INT);
        if ($enrollCourseId === false || $enrollCourseId === null || $enrollCourseId <= 0) {
            $errorMessage = 'Invalid course selected.';
        } else {
            try {
                $courseCheckStmt = $pdo->prepare(
                    'SELECT id
                     FROM courses
                     WHERE id = :course_id AND is_published = 1
                     LIMIT 1'
                );
                $courseCheckStmt->execute(['course_id' => (int) $enrollCourseId]);
                if (!$courseCheckStmt->fetch()) {
                    throw new RuntimeException('Course is not available for enrollment.');
                }

                $moduleStmt = $pdo->prepare(
                    'SELECT id
                     FROM modules
                     WHERE course_id = :course_id
                     ORDER BY sequence_order ASC, id ASC
                     LIMIT 1'
                );
                $moduleStmt->execute(['course_id' => (int) $enrollCourseId]);
                $firstModule = $moduleStmt->fetch();
                if (!$firstModule) {
                    throw new RuntimeException('Cannot enroll in a course without modules.');
                }

                $insertProgressStmt = $pdo->prepare(
                    'INSERT INTO user_progress (user_id, module_id, is_completed, last_watched_second)
                     VALUES (:user_id, :module_id, 0, 0)
                     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
                );
                $insertProgressStmt->execute([
                    'user_id' => $userId,
                    'module_id' => (int) $firstModule['id'],
                ]);

                clms_redirect('student/view_module.php?module_id=' . (int) $firstModule['id']);
            } catch (Throwable $e) {
                $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to enroll right now.';
                if (!($e instanceof RuntimeException)) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}

$coursesStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.title,
        c.description,
        c.level,
        c.thumbnail_url,
        (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS total_modules,
        (SELECT COALESCE(SUM(m.duration_minutes), 0) FROM modules m WHERE m.course_id = c.id) AS total_duration_minutes,
        (SELECT COUNT(*) FROM questions q WHERE q.course_id = c.id) AS question_count,
        (SELECT COUNT(DISTINCT up.user_id)
            FROM user_progress up
            INNER JOIN modules mm ON mm.id = up.module_id
            WHERE mm.course_id = c.id) AS learner_count,
        (SELECT COUNT(*) FROM certificates cert WHERE cert.course_id = c.id) AS graduate_count,
        (SELECT COUNT(*)
            FROM user_progress up2
            INNER JOIN modules mm2 ON mm2.id = up2.module_id
            WHERE mm2.course_id = c.id
              AND up2.user_id = :user_id_completed
              AND up2.is_completed = 1) AS completed_modules,
        (SELECT ea.id
            FROM exam_attempts ea
            WHERE ea.course_id = c.id
              AND ea.user_id = :user_id_passed_attempt
              AND ea.status IN ('completed', 'pending_manual_grade')
              AND ea.is_passed = 1
            ORDER BY ea.id DESC
            LIMIT 1) AS latest_passed_attempt_id,
        (SELECT ea.id
            FROM exam_attempts ea
            WHERE ea.course_id = c.id
              AND ea.user_id = :user_id_failed_attempt
              AND ea.status IN ('completed', 'pending_manual_grade')
              AND ea.is_passed = 0
            ORDER BY ea.id DESC
            LIMIT 1) AS latest_failed_attempt_id
     FROM courses c
     WHERE c.is_published = 1
     ORDER BY c.title ASC"
);
$coursesStmt->execute([
    'user_id_completed' => $userId,
    'user_id_passed_attempt' => $userId,
    'user_id_failed_attempt' => $userId,
]);
$allCourses = $coursesStmt->fetchAll();

$enrolledCourseStmt = $pdo->prepare(
    'SELECT DISTINCT c.id
     FROM courses c
     INNER JOIN modules m ON m.course_id = c.id
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id_progress
     LEFT JOIN exam_attempts ea ON ea.course_id = c.id AND ea.user_id = :user_id_attempt
     LEFT JOIN certificates cert ON cert.course_id = c.id AND cert.user_id = :user_id_certificate
     WHERE c.is_published = 1
       AND (up.id IS NOT NULL OR ea.id IS NOT NULL OR cert.id IS NOT NULL)'
);
$enrolledCourseStmt->execute([
    'user_id_progress' => $userId,
    'user_id_attempt' => $userId,
    'user_id_certificate' => $userId,
]);
$enrolledIds = array_map(static fn (array $row): int => (int) $row['id'], $enrolledCourseStmt->fetchAll());
$enrolledIdSet = array_fill_keys($enrolledIds, true);

$formatDuration = static function (int $totalMinutes): string {
    if ($totalMinutes <= 0) {
        return 'Self-paced';
    }
    if ($totalMinutes < 60) {
        return $totalMinutes . ' min';
    }
    $hours = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;
    if ($minutes === 0) {
        return $hours . ($hours === 1 ? ' hr' : ' hrs');
    }
    return $hours . ' hr ' . $minutes . ' min';
};

$levelStyleFor = static function (?string $raw): array {
    $key = strtolower(trim((string) $raw));
    switch ($key) {
        case 'advanced':
            return ['label' => 'Advanced Level', 'class' => 'bg-label-primary'];
        case 'intermediate':
            return ['label' => 'Intermediate Level', 'class' => 'bg-label-success'];
        case 'beginner':
            return ['label' => 'Beginner Level', 'class' => 'bg-label-info'];
        default:
            return ['label' => 'All Levels', 'class' => 'bg-label-secondary'];
    }
};

$placeholderGradients = [
    'linear-gradient(135deg, #0a1736 0%, #0f204b 100%)',
    'linear-gradient(135deg, #0f204b 0%, #1a2f6b 100%)',
    'linear-gradient(135deg, #1a2f6b 0%, #243d82 100%)',
    'linear-gradient(135deg, #12295b 0%, #0f204b 100%)',
    'linear-gradient(135deg, #0f204b 0%, #2b467f 100%)',
    'linear-gradient(135deg, #0a1736 0%, #1a2f6b 100%)',
];

$resolveThumbnailUrl = static function (?string $rawPath) use ($clmsWebBase): string {
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return rtrim((string) $clmsWebBase, '/') . $path;
    }
    return rtrim((string) $clmsWebBase, '/') . '/' . ltrim($path, '/');
};

$courseCards = [];
foreach ($allCourses as $course) {
    $courseId = (int) $course['id'];
    $totalModules = (int) $course['total_modules'];
    $completedModules = (int) $course['completed_modules'];
    $progressPercent = $totalModules > 0 ? (int) round(($completedModules / $totalModules) * 100) : 0;

    $course['is_enrolled'] = isset($enrolledIdSet[$courseId]);
    $course['progress_percent'] = $progressPercent;
    $course['progress_text'] = $completedModules . ' of ' . $totalModules . ' modules completed';
    $course['duration_display'] = $formatDuration((int) $course['total_duration_minutes']);
    $course['level_style'] = $levelStyleFor($course['level'] ?? null);
    $course['placeholder_gradient'] = $placeholderGradients[$courseId % count($placeholderGradients)];

    // Exam completion state. We distinguish two possible "finished"
    // outcomes so the catalog card can label them correctly:
    //   * Passed  → green "Completed"
    //   * Failed  → warning "Retake Final Exam"
    // In both cases we additionally require every current module to be
    // done, because instructors can add new modules after an attempt and
    // the student should go finish them before we light up a finished
    // state on the card.
    $latestPassedId = isset($course['latest_passed_attempt_id'])
        ? (int) $course['latest_passed_attempt_id']
        : null;
    $latestFailedId = isset($course['latest_failed_attempt_id'])
        ? (int) $course['latest_failed_attempt_id']
        : null;
    if ($latestPassedId !== null && $latestPassedId <= 0) {
        $latestPassedId = null;
    }
    if ($latestFailedId !== null && $latestFailedId <= 0) {
        $latestFailedId = null;
    }

    $allModulesDone = $totalModules > 0 && $completedModules >= $totalModules;

    $course['exam_is_passed'] = $latestPassedId !== null && $allModulesDone;
    $course['exam_is_failed'] = !$course['exam_is_passed']
        && $latestFailedId !== null
        && $allModulesDone;
    $course['exam_is_finished'] = $course['exam_is_passed'] || $course['exam_is_failed'];

    // Pick the most relevant attempt to link the result button to.
    $course['latest_finished_attempt_id'] = $latestPassedId ?? $latestFailedId;

    $courseCards[] = $course;
}

$enrolledCoursesList = array_values(array_filter($courseCards, static fn (array $c): bool => (bool) $c['is_enrolled']));

$inProgressCourses = array_values(array_filter(
    $enrolledCoursesList,
    static fn (array $c): bool => (int) $c['progress_percent'] < 100
));
usort(
    $inProgressCourses,
    static fn (array $a, array $b): int => (int) $b['progress_percent'] <=> (int) $a['progress_percent']
);
$inProgressCourses = array_slice($inProgressCourses, 0, 3);

$certificatesStmt = $pdo->prepare(
    'SELECT cert.certificate_hash, cert.issued_at, c.title AS course_title, c.level
     FROM certificates cert
     INNER JOIN courses c ON c.id = cert.course_id
     WHERE cert.user_id = :user_id
     ORDER BY cert.issued_at DESC'
);
$certificatesStmt->execute(['user_id' => $userId]);
$certificates = $certificatesStmt->fetchAll();

$statsStmt = $pdo->prepare(
    "SELECT
        COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.module_id END) AS modules_completed,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COUNT(DISTINCT CASE WHEN ea.status = 'completed' THEN ea.id END) AS attempts_completed,
        COUNT(DISTINCT CASE WHEN ea.status = 'completed' AND ea.is_passed = 1 THEN ea.id END) AS attempts_passed
     FROM users u
     LEFT JOIN user_progress up ON up.user_id = u.id
     LEFT JOIN exam_attempts ea ON ea.user_id = u.id
     WHERE u.id = :user_id"
);
$statsStmt->execute(['user_id' => $userId]);
$statsRow = $statsStmt->fetch() ?: [];
$modulesCompleted = (int) ($statsRow['modules_completed'] ?? 0);
$avgScore = (float) ($statsRow['avg_score'] ?? 0.0);
$attemptsCompleted = (int) ($statsRow['attempts_completed'] ?? 0);
$attemptsPassed = (int) ($statsRow['attempts_passed'] ?? 0);
$certificateCount = count($certificates);
$enrolledCount = count($enrolledCoursesList);

$activeAnnouncements = [];
try {
    $announcementsStmt = $pdo->query(
        'SELECT id, title, body, created_at
         FROM announcements
         WHERE is_active = 1
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $activeAnnouncements = $announcementsStmt->fetchAll();
} catch (Throwable $e) {
    $activeAnnouncements = [];
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">

              <div class="clms-hero p-4 p-md-4 mb-4">
                <div class="row g-3 align-items-center clms-hero-content">
                  <div class="col-md-8">
                    <small class="text-white-50 d-block mb-1">Welcome back</small>
                    <h4 class="fw-bold mb-2"><?php echo htmlspecialchars($firstNameOnly, ENT_QUOTES, 'UTF-8'); ?>, ready to level up?</h4>
                    <p class="mb-3 text-white-50" style="max-width: 640px;">
                      Pick up where you left off, explore new courses, and earn certificates as you go. Every finished module brings you closer to the board exam.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
<?php if ($inProgressCourses !== []) :
    $resumeCourse = $inProgressCourses[0];
    $resumeHref = $clmsWebBase . '/student/view_module.php?course_id=' . (int) $resumeCourse['id'];
?>
                      <a href="<?php echo htmlspecialchars($resumeHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light">
                        <i class="bx bx-play-circle me-1"></i>Resume <?php echo htmlspecialchars(mb_substr((string) $resumeCourse['title'], 0, 30), ENT_QUOTES, 'UTF-8'); ?>
                      </a>
<?php endif; ?>
                      <a href="#catalogSection" class="btn btn-outline-light">
                        <i class="bx bx-compass me-1"></i>Browse catalog
                      </a>
                    </div>
                  </div>
                  <div class="col-md-4 d-none d-md-block text-center">
                    <i class="bx bx-book-reader" style="font-size: 8rem; opacity: .35;"></i>
                  </div>
                </div>
              </div>

<?php if ($successMessage !== '') : ?>
              <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($errorMessage !== '') : ?>
              <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($notice === 'no_exam_questions') : ?>
              <div class="alert alert-warning" role="alert">This course does not have exam questions yet. Please contact your instructor.</div>
<?php endif; ?>
<?php if ($notice === 'modules_incomplete') : ?>
              <div class="alert alert-warning" role="alert">You must complete every module assessment before taking the final exam.</div>
<?php endif; ?>

              <div class="row g-4 mb-4">
                <div class="col-6 col-md-3">
                  <div class="card clms-stat-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                      <span class="clms-stat-icon bg-label-primary"><i class="bx bx-book-content"></i></span>
                      <div>
                        <small class="text-muted d-block">Enrolled</small>
                        <h4 class="mb-0"><?php echo $enrolledCount; ?></h4>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="card clms-stat-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                      <span class="clms-stat-icon bg-label-success"><i class="bx bx-check-circle"></i></span>
                      <div>
                        <small class="text-muted d-block">Modules Done</small>
                        <h4 class="mb-0"><?php echo $modulesCompleted; ?></h4>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="card clms-stat-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                      <span class="clms-stat-icon bg-label-warning"><i class="bx bx-award"></i></span>
                      <div>
                        <small class="text-muted d-block">Certificates</small>
                        <h4 class="mb-0"><?php echo $certificateCount; ?></h4>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="card clms-stat-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                      <span class="clms-stat-icon bg-label-info"><i class="bx bx-trophy"></i></span>
                      <div>
                        <small class="text-muted d-block">Avg. Score</small>
                        <h4 class="mb-0"><?php echo $attemptsCompleted > 0 ? number_format($avgScore, 1) . '%' : '—'; ?></h4>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

<?php if ($activeAnnouncements !== []) : ?>
              <div class="card mb-4 border-start border-primary border-3">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-3">
                    <i class="bx bx-bell text-primary me-2"></i>
                    <h6 class="mb-0">Announcements</h6>
                    <span class="badge bg-label-primary ms-2"><?php echo count($activeAnnouncements); ?></span>
                  </div>
<?php $announcementCount = count($activeAnnouncements); ?>
<?php foreach ($activeAnnouncements as $index => $announcement) : ?>
                  <div class="<?php echo $index < $announcementCount - 1 ? 'pb-3 mb-3 border-bottom' : ''; ?>"
                       data-search-item
                       data-search-text="<?php echo htmlspecialchars((string) $announcement['title'] . ' ' . (string) $announcement['body'], ENT_QUOTES, 'UTF-8'); ?>">
                    <h6 class="mb-1"><?php echo htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8'); ?></h6>
                    <small class="text-muted d-block mb-2">
                      <?php echo htmlspecialchars((string) date('F j, Y · g:i A', strtotime((string) $announcement['created_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
                    </small>
                    <p class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string) $announcement['body'], ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
<?php endforeach; ?>
                </div>
              </div>
<?php endif; ?>

<?php if ($inProgressCourses !== []) : ?>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-semibold mb-0">
                  <i class="bx bx-play-circle text-primary me-1"></i>Continue Learning
                </h5>
                <small class="text-muted">Jump right back in</small>
              </div>
              <div class="row g-3 mb-4">
<?php foreach ($inProgressCourses as $course) :
    $courseId = (int) $course['id'];
    $thumbnail = $resolveThumbnailUrl((string) ($course['thumbnail_url'] ?? ''));
    $mediaStyle = $thumbnail !== ''
        ? 'background-image: url(' . "'" . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') . "'" . ');'
        : 'background: ' . $course['placeholder_gradient'] . ';';
    $resumeHref = $clmsWebBase . '/student/view_module.php?course_id=' . $courseId;
    $progressPercent = (int) $course['progress_percent'];
    $levelStyle = $course['level_style'];
?>
                <div class="col-md-6 col-xl-4">
                  <div class="clms-continue-card">
                    <div class="clms-continue-media" style="<?php echo $mediaStyle; ?>">
                      <span class="badge <?php echo htmlspecialchars((string) $levelStyle['class'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) $levelStyle['label'], ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </div>
                    <div class="p-3">
                      <h6 class="fw-semibold mb-1 text-truncate" title="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                      </h6>
                      <small class="text-muted d-block mb-2">
                        <?php echo htmlspecialchars((string) $course['progress_text'], ENT_QUOTES, 'UTF-8'); ?>
                      </small>
                      <div class="d-flex align-items-center gap-2 mb-3">
                        <div class="progress flex-grow-1" style="height: 6px;">
                          <div class="progress-bar bg-primary"
                               role="progressbar"
                               style="width: <?php echo $progressPercent; ?>%;"
                               aria-valuenow="<?php echo $progressPercent; ?>"
                               aria-valuemin="0"
                               aria-valuemax="100"></div>
                        </div>
                        <small class="fw-semibold"><?php echo $progressPercent; ?>%</small>
                      </div>
                      <a class="btn btn-sm btn-primary w-100" href="<?php echo htmlspecialchars($resumeHref, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-play me-1"></i>Resume
                      </a>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php endif; ?>

              <div id="catalogSection" class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h5 class="fw-semibold mb-0">
                  <i class="bx bx-book-open text-primary me-1"></i>Course Catalog
                </h5>
                <div class="d-flex flex-wrap gap-2" id="clmsCatalogFilters" role="group" aria-label="Catalog filter">
                  <button type="button" class="btn btn-sm btn-primary clms-filter-chip" data-filter="all">All</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary clms-filter-chip" data-filter="enrolled">Enrolled</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary clms-filter-chip" data-filter="beginner">Beginner</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary clms-filter-chip" data-filter="intermediate">Intermediate</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary clms-filter-chip" data-filter="advanced">Advanced</button>
                </div>
              </div>
<?php if ($courseCards === []) : ?>
              <div class="alert alert-info mb-4" role="alert">No published courses available yet. Please check back later.</div>
<?php else : ?>
              <div class="row g-4 mb-4" id="clmsCatalogGrid">
<?php foreach ($courseCards as $course) : ?>
<?php
  $courseId = (int) $course['id'];
  $title = (string) $course['title'];
  $description = (string) ($course['description'] ?? '');
  $thumbnail = $resolveThumbnailUrl((string) ($course['thumbnail_url'] ?? ''));
  $levelStyle = $course['level_style'];
  $levelKey = strtolower(trim((string) ($course['level'] ?? 'all')));
  if (!in_array($levelKey, ['beginner', 'intermediate', 'advanced'], true)) {
      $levelKey = 'other';
  }
  $isEnrolled = (bool) $course['is_enrolled'];
  $progressPercent = (int) $course['progress_percent'];
  $examIsPassed = (bool) ($course['exam_is_passed'] ?? false);
  $examIsFailed = (bool) ($course['exam_is_failed'] ?? false);
  $latestAttemptId = $course['latest_finished_attempt_id'] ?? null;
  $mediaStyle = $thumbnail !== ''
      ? 'background-image: url(' . "'" . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') . "'" . ');'
      : 'background: ' . $course['placeholder_gradient'] . ';';
  $startHref = $clmsWebBase . '/student/view_module.php?course_id=' . $courseId;
  $resultHref = $latestAttemptId !== null
      ? $clmsWebBase . '/student/grade_exam.php?attempt_id=' . (int) $latestAttemptId . '&course_id=' . $courseId
      : null;
  $modalId = 'courseInfoModal-' . $courseId;
?>
                <div
                  class="col-md-6 col-lg-4 col-xl-3 clms-catalog-item"
                  data-search-item
                  data-search-text="<?php echo htmlspecialchars($title . ' ' . $description . ' ' . ($course['level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                  data-level="<?php echo htmlspecialchars($levelKey, ENT_QUOTES, 'UTF-8'); ?>"
                  data-enrolled="<?php echo $isEnrolled ? '1' : '0'; ?>">
                  <div class="clms-course-card h-100 d-flex flex-column">
                    <div class="clms-course-media <?php echo $thumbnail === '' ? 'clms-course-placeholder' : ''; ?>" style="<?php echo $mediaStyle; ?>">
                      <span class="clms-course-level-pill" data-level="<?php echo htmlspecialchars($levelKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="dot"></span>
                        <?php echo htmlspecialchars((string) $levelStyle['label'], ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                      <span class="clms-cert-badge">
                        <i class="bx bx-award"></i>Certificate
                      </span>
<?php if ($thumbnail === '') : ?>
                      <span><?php echo htmlspecialchars(mb_strtoupper(mb_substr($title, 0, 2)), ENT_QUOTES, 'UTF-8'); ?></span>
<?php endif; ?>
                    </div>

                    <div class="card-body d-flex flex-column">
                      <h6 class="clms-course-title" title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                      </h6>

                      <div class="clms-course-graduates">
                        <i class="bx bx-check-shield"></i>
                        <span><strong><?php echo number_format((int) $course['graduate_count']); ?></strong> graduate<?php echo (int) $course['graduate_count'] === 1 ? '' : 's'; ?></span>
                      </div>

                      <div class="clms-course-meta">
                        <span class="clms-meta-chip">
                          <i class="bx bx-time-five"></i>
                          <?php echo htmlspecialchars((string) $course['duration_display'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="clms-meta-chip">
                          <i class="bx bx-group"></i>
                          <?php echo number_format((int) $course['learner_count']); ?> learners
                        </span>
                      </div>

<?php if ($isEnrolled && $progressPercent > 0) : ?>
                      <div class="clms-course-progress-wrap">
                        <div class="progress-label">
                          <span class="text-muted"><?php echo htmlspecialchars((string) $course['progress_text'], ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="fw-semibold"><?php echo $progressPercent; ?>%</span>
                        </div>
                        <div class="progress clms-course-progress">
                          <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $progressPercent; ?>%;" aria-valuenow="<?php echo $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                      </div>
<?php endif; ?>

                      <div class="clms-course-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-bs-toggle="modal" data-bs-target="#<?php echo htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>">
                          More Info
                        </button>
<?php if ($isEnrolled) : ?>
<?php if ($examIsPassed && $resultHref !== null) : ?>
                        <a class="btn btn-sm btn-success flex-fill" href="<?php echo htmlspecialchars($resultHref, ENT_QUOTES, 'UTF-8'); ?>">
                          <i class="bx bx-check-circle me-1"></i>Completed
                        </a>
<?php elseif ($examIsFailed) : ?>
                        <a class="btn btn-sm btn-warning flex-fill text-white" href="<?php echo htmlspecialchars($startHref, ENT_QUOTES, 'UTF-8'); ?>">
                          <i class="bx bx-refresh me-1"></i>Retake Final Exam
                        </a>
<?php else : ?>
                        <a class="btn btn-sm btn-primary flex-fill" href="<?php echo htmlspecialchars($startHref, ENT_QUOTES, 'UTF-8'); ?>">
                          <i class="bx <?php echo $progressPercent > 0 ? 'bx-play' : 'bx-rocket'; ?> me-1"></i><?php echo $progressPercent > 0 ? 'Continue' : 'Start Learning'; ?>
                        </a>
<?php endif; ?>
<?php else : ?>
                        <form method="post" class="flex-fill m-0" action="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                          <input type="hidden" name="enroll_course_id" value="<?php echo $courseId; ?>" />
                          <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="bx bx-rocket me-1"></i>Start Learning
                          </button>
                        </form>
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="modal fade" id="<?php echo htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                          <span class="badge <?php echo htmlspecialchars((string) $levelStyle['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $levelStyle['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="badge bg-label-secondary"><i class="bx bx-book-open me-1"></i><?php echo (int) $course['total_modules']; ?> modules</span>
                          <span class="badge bg-label-secondary"><i class="bx bx-time-five me-1"></i><?php echo htmlspecialchars((string) $course['duration_display'], ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="badge bg-label-secondary"><i class="bx bx-group me-1"></i><?php echo number_format((int) $course['learner_count']); ?> learners</span>
<?php if ((int) $course['question_count'] > 0) : ?>
                          <span class="badge bg-label-warning"><i class="bx bx-certification me-1"></i>Final Exam</span>
<?php endif; ?>
                        </div>
<?php if (trim($description) !== '') : ?>
                        <p style="white-space: pre-wrap;"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
<?php else : ?>
                        <p class="text-muted">No description provided yet.</p>
<?php endif; ?>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
<?php if ($isEnrolled) : ?>
<?php if ($examIsFinished && $resultHref !== null) : ?>
                        <a class="btn btn-success" href="<?php echo htmlspecialchars($resultHref, ENT_QUOTES, 'UTF-8'); ?>">
                          <i class="bx bx-check-circle me-1"></i>Completed
                        </a>
<?php else : ?>
                        <a class="btn btn-success" href="<?php echo htmlspecialchars($startHref, ENT_QUOTES, 'UTF-8'); ?>">
                          <?php echo $progressPercent > 0 ? 'Continue Learning' : 'Start Learning'; ?>
                        </a>
<?php endif; ?>
<?php else : ?>
                        <form method="post" class="m-0" action="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                          <input type="hidden" name="enroll_course_id" value="<?php echo $courseId; ?>" />
                          <button type="submit" class="btn btn-success">Start Learning</button>
                        </form>
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php endif; ?>

              <div class="d-flex justify-content-between align-items-center mb-3 mt-2">
                <h5 class="fw-semibold mb-0">
                  <i class="bx bx-award text-warning me-1"></i>My Certificates
                </h5>
<?php if ($certificateCount > 0) : ?>
                <small class="text-muted"><?php echo $certificateCount; ?> earned</small>
<?php endif; ?>
              </div>
<?php if ($certificates === []) : ?>
              <div class="card">
                <div class="card-body text-center py-5 text-muted">
                  <i class="bx bx-trophy display-4 d-block mb-2"></i>
                  <p class="mb-0">No certificates yet. Complete a course and pass the final exam to earn one.</p>
                </div>
              </div>
<?php else : ?>
              <div class="row g-3">
<?php foreach ($certificates as $certificate) :
    $certHash = (string) $certificate['certificate_hash'];
    $certTitle = (string) $certificate['course_title'];
    $certIssued = (string) date('F j, Y', strtotime((string) $certificate['issued_at']) ?: time());
    $certDownloadHref = $clmsWebBase . '/student/download_certificate.php?hash=' . urlencode($certHash);
    $certTemplateSrc = $clmsWebBase . '/public/assets/images/blank_cert_template.jpg';
?>
                <div class="col-md-6 col-xl-4">
                  <div class="clms-cert-card">
                    <a
                      class="clms-cert-preview"
                      href="<?php echo htmlspecialchars($certDownloadHref, ENT_QUOTES, 'UTF-8'); ?>"
                      title="Download certificate">
                      <img
                        src="<?php echo htmlspecialchars($certTemplateSrc, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Certificate preview for <?php echo htmlspecialchars($certTitle, ENT_QUOTES, 'UTF-8'); ?>"
                        loading="lazy" />
                      <span class="clms-cert-preview-overlay">
                        <span class="clms-cert-preview-badge">
                          <i class="bx bx-download"></i> View / Download
                        </span>
                      </span>
                    </a>
                    <div class="clms-cert-body">
                      <div class="d-flex align-items-start gap-2">
                        <span class="clms-cert-icon">
                          <i class="bx bx-award"></i>
                        </span>
                        <div class="flex-grow-1 min-width-0">
                          <div class="fw-semibold text-truncate" title="<?php echo htmlspecialchars($certTitle, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($certTitle, ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                          <small class="text-muted d-block">
                            Issued <?php echo htmlspecialchars($certIssued, ENT_QUOTES, 'UTF-8'); ?>
                          </small>
                        </div>
                      </div>
                      <a
                        class="btn btn-sm btn-warning mt-3 w-100"
                        href="<?php echo htmlspecialchars($certDownloadHref, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-download me-1"></i>Download
                      </a>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php endif; ?>
              </div>

              <script>
                (() => {
                  const filters = document.querySelectorAll('#clmsCatalogFilters .clms-filter-chip');
                  const items = document.querySelectorAll('.clms-catalog-item');
                  if (!filters.length || !items.length) return;

                  const applyFilter = (filter) => {
                    items.forEach((el) => {
                      const level = (el.getAttribute('data-level') || '').toLowerCase();
                      const enrolled = el.getAttribute('data-enrolled') === '1';
                      let show = true;
                      if (filter === 'enrolled') show = enrolled;
                      else if (filter === 'beginner' || filter === 'intermediate' || filter === 'advanced') show = level === filter;
                      el.style.display = show ? '' : 'none';
                    });
                  };

                  filters.forEach((chip) => {
                    chip.addEventListener('click', () => {
                      filters.forEach((c) => {
                        c.classList.remove('btn-primary');
                        c.classList.add('btn-outline-secondary');
                      });
                      chip.classList.remove('btn-outline-secondary');
                      chip.classList.add('btn-primary');
                      applyFilter(chip.getAttribute('data-filter') || 'all');
                    });
                  });
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
