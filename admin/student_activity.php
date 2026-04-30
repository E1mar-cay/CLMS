<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Student Activity | Criminology LMS';
$activeAdminPage = 'student_activity';

$coursesStmt = $pdo->query(
    "SELECT id, title
     FROM courses
     WHERE is_published = 1
     ORDER BY title ASC"
);
$courses = $coursesStmt->fetchAll();

$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (($selectedCourseId === false || $selectedCourseId === null || $selectedCourseId <= 0) && $courses !== []) {
    $selectedCourseId = (int) $courses[0]['id'];
}

$selectedCourseTitle = null;
foreach ($courses as $course) {
    if ((int) $course['id'] === (int) $selectedCourseId) {
        $selectedCourseTitle = (string) $course['title'];
        break;
    }
}

$totalModules = 0;
$activityRows = [];
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$overallFilter = strtolower(trim((string) ($_GET['overall'] ?? 'all')));
$examFilter = strtolower(trim((string) ($_GET['exam'] ?? 'all')));
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page === false || $page === null || $page < 1) ? 1 : (int) $page;
$perPage = 10;

$allowedOverallFilters = ['all', 'not_started', 'in_progress', 'done'];
if (!in_array($overallFilter, $allowedOverallFilters, true)) {
    $overallFilter = 'all';
}
$allowedExamFilters = ['all', 'not_started', 'in_progress', 'passed', 'failed'];
if (!in_array($examFilter, $allowedExamFilters, true)) {
    $examFilter = 'all';
}

if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0) {
    $totalModulesStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM modules WHERE course_id = :course_id');
    $totalModulesStmt->execute(['course_id' => (int) $selectedCourseId]);
    $totalModules = (int) ($totalModulesStmt->fetch()['total'] ?? 0);

    $studentsStmt = $pdo->prepare(
        "SELECT
            u.id,
            u.first_name,
            u.last_name,
            EXISTS(
                SELECT 1 FROM certificates cert
                WHERE cert.user_id = u.id
                  AND cert.course_id = :course_id_cert
            ) AS has_certificate,
            (SELECT COUNT(*)
             FROM user_progress up
             INNER JOIN modules m ON m.id = up.module_id
             WHERE up.user_id = u.id
               AND m.course_id = :course_id_video_started
               AND up.last_watched_second > 0) AS video_started_count,
            (SELECT COUNT(*)
             FROM user_progress up
             INNER JOIN modules m ON m.id = up.module_id
             WHERE up.user_id = u.id
               AND m.course_id = :course_id_video_completed
               AND (up.video_completed = 1 OR up.is_completed = 1)) AS video_completed_count,
            (SELECT COUNT(DISTINCT mqa.module_id)
             FROM module_quiz_attempts mqa
             INNER JOIN modules m ON m.id = mqa.module_id
             WHERE mqa.user_id = u.id
               AND m.course_id = :course_id_quiz_attempted) AS quiz_attempted_modules,
            (SELECT COUNT(DISTINCT mqa.module_id)
             FROM module_quiz_attempts mqa
             INNER JOIN modules m ON m.id = mqa.module_id
             WHERE mqa.user_id = u.id
               AND m.course_id = :course_id_quiz_passed
               AND mqa.percentage >= 70.0) AS quiz_passed_modules,
            (SELECT ea.status
             FROM exam_attempts ea
             WHERE ea.user_id = u.id
               AND ea.course_id = :course_id_exam_status
             ORDER BY ea.id DESC
             LIMIT 1) AS latest_exam_status,
            (SELECT ea.is_passed
             FROM exam_attempts ea
             WHERE ea.user_id = u.id
               AND ea.course_id = :course_id_exam_passed
             ORDER BY ea.id DESC
             LIMIT 1) AS latest_exam_passed,
            (SELECT ea.total_score
             FROM exam_attempts ea
             WHERE ea.user_id = u.id
               AND ea.course_id = :course_id_exam_score
             ORDER BY ea.id DESC
             LIMIT 1) AS latest_exam_score,
            GREATEST(
                COALESCE((
                    SELECT UNIX_TIMESTAMP(MAX(COALESCE(ea.completed_at, ea.attempted_at)))
                    FROM exam_attempts ea
                    WHERE ea.user_id = u.id
                      AND ea.course_id = :course_id_activity_exam
                ), 0),
                COALESCE((
                    SELECT UNIX_TIMESTAMP(MAX(mqa.attempted_at))
                    FROM module_quiz_attempts mqa
                    INNER JOIN modules m ON m.id = mqa.module_id
                    WHERE mqa.user_id = u.id
                      AND m.course_id = :course_id_activity_quiz
                ), 0),
                COALESCE((
                    SELECT UNIX_TIMESTAMP(MAX(up.completed_at))
                    FROM user_progress up
                    INNER JOIN modules m ON m.id = up.module_id
                    WHERE up.user_id = u.id
                      AND m.course_id = :course_id_activity_video
                ), 0)
            ) AS last_activity_unix
         FROM users u
         WHERE u.role = 'student'
           AND (
             EXISTS(SELECT 1 FROM user_progress up2 INNER JOIN modules m2 ON m2.id = up2.module_id WHERE up2.user_id = u.id AND m2.course_id = :course_id_exists_progress)
             OR EXISTS(SELECT 1 FROM exam_attempts ea2 WHERE ea2.user_id = u.id AND ea2.course_id = :course_id_exists_exam)
             OR EXISTS(SELECT 1 FROM certificates cert2 WHERE cert2.user_id = u.id AND cert2.course_id = :course_id_exists_cert)
           )
         ORDER BY COALESCE(last_name, ''), COALESCE(first_name, '')"
    );
    $studentsStmt->execute([
        'course_id_cert' => (int) $selectedCourseId,
        'course_id_video_started' => (int) $selectedCourseId,
        'course_id_video_completed' => (int) $selectedCourseId,
        'course_id_quiz_attempted' => (int) $selectedCourseId,
        'course_id_quiz_passed' => (int) $selectedCourseId,
        'course_id_exam_status' => (int) $selectedCourseId,
        'course_id_exam_passed' => (int) $selectedCourseId,
        'course_id_exam_score' => (int) $selectedCourseId,
        'course_id_activity_exam' => (int) $selectedCourseId,
        'course_id_activity_quiz' => (int) $selectedCourseId,
        'course_id_activity_video' => (int) $selectedCourseId,
        'course_id_exists_progress' => (int) $selectedCourseId,
        'course_id_exists_exam' => (int) $selectedCourseId,
        'course_id_exists_cert' => (int) $selectedCourseId,
    ]);
    $rows = $studentsStmt->fetchAll();

    foreach ($rows as $row) {
        $videoStarted = (int) ($row['video_started_count'] ?? 0);
        $videoCompleted = (int) ($row['video_completed_count'] ?? 0);
        $quizAttempted = (int) ($row['quiz_attempted_modules'] ?? 0);
        $quizPassed = (int) ($row['quiz_passed_modules'] ?? 0);
        $examStatus = (string) ($row['latest_exam_status'] ?? '');
        $examPassed = isset($row['latest_exam_passed']) ? (int) $row['latest_exam_passed'] : null;
        $hasCertificate = (int) ($row['has_certificate'] ?? 0) === 1;

        if ($videoCompleted >= $totalModules && $totalModules > 0) {
            $videoLabel = 'Videos Completed';
            $videoBadge = 'bg-label-success';
        } elseif ($videoStarted > 0) {
            $videoLabel = 'Watching Videos';
            $videoBadge = 'bg-label-info';
        } else {
            $videoLabel = 'Not Started';
            $videoBadge = 'bg-label-secondary';
        }

        if ($quizPassed >= $totalModules && $totalModules > 0) {
            $assessmentLabel = 'Assessments Done';
            $assessmentBadge = 'bg-label-success';
        } elseif ($quizAttempted > 0) {
            $assessmentLabel = 'Answering Assessments';
            $assessmentBadge = 'bg-label-primary';
        } else {
            $assessmentLabel = 'Not Started';
            $assessmentBadge = 'bg-label-secondary';
        }

        if ($examStatus === 'in_progress') {
            $examLabel = 'Taking Final Exam';
            $examBadge = 'bg-label-info';
        } elseif (($examStatus === 'completed' || $examStatus === 'pending_manual_grade') && $examPassed === 1) {
            $examLabel = 'Final Exam Passed';
            $examBadge = 'bg-label-success';
        } elseif (($examStatus === 'completed' || $examStatus === 'pending_manual_grade') && $examPassed === 0) {
            $examLabel = 'Final Exam Failed';
            $examBadge = 'bg-label-danger';
        } else {
            $examLabel = 'Not Started';
            $examBadge = 'bg-label-secondary';
        }

        if ($hasCertificate || ($videoCompleted >= $totalModules && $quizPassed >= $totalModules && $examPassed === 1 && $totalModules > 0)) {
            $overallLabel = 'Done';
            $overallBadge = 'bg-label-success';
        } elseif ($examStatus === 'in_progress' || $quizAttempted > 0 || $videoStarted > 0) {
            $overallLabel = 'In Progress';
            $overallBadge = 'bg-label-info';
        } else {
            $overallLabel = 'Not Started';
            $overallBadge = 'bg-label-secondary';
        }

        $activityRows[] = [
            'student_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?: 'Student',
            'video_label' => $videoLabel,
            'video_badge' => $videoBadge,
            'video_progress' => $totalModules > 0 ? ($videoCompleted . '/' . $totalModules) : '0/0',
            'assessment_label' => $assessmentLabel,
            'assessment_badge' => $assessmentBadge,
            'assessment_progress' => $totalModules > 0 ? ($quizPassed . '/' . $totalModules) : '0/0',
            'exam_label' => $examLabel,
            'exam_badge' => $examBadge,
            'overall_label' => $overallLabel,
            'overall_badge' => $overallBadge,
            'last_activity_unix' => (int) ($row['last_activity_unix'] ?? 0),
        ];
    }
}

if ($activityRows !== []) {
    $needle = mb_strtolower($searchQuery);
    $activityRows = array_values(array_filter(
        $activityRows,
        static function (array $row) use ($needle, $overallFilter, $examFilter): bool {
            if ($needle !== '' && mb_strpos(mb_strtolower((string) $row['student_name']), $needle) === false) {
                return false;
            }

            $overallKey = strtolower(str_replace(' ', '_', (string) $row['overall_label']));
            if ($overallFilter !== 'all' && $overallKey !== $overallFilter) {
                return false;
            }

            $examLabel = (string) $row['exam_label'];
            $examKey = 'not_started';
            if ($examLabel === 'Taking Final Exam') {
                $examKey = 'in_progress';
            } elseif ($examLabel === 'Final Exam Passed') {
                $examKey = 'passed';
            } elseif ($examLabel === 'Final Exam Failed') {
                $examKey = 'failed';
            }
            if ($examFilter !== 'all' && $examKey !== $examFilter) {
                return false;
            }

            return true;
        }
    ));
}

if ($activityRows !== []) {
    usort(
        $activityRows,
        static function (array $left, array $right): int {
            $leftPriority = ((string) ($left['exam_label'] ?? '') === 'Taking Final Exam') ? 0 : 1;
            $rightPriority = ((string) ($right['exam_label'] ?? '') === 'Taking Final Exam') ? 0 : 1;
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }
            return ((int) ($right['last_activity_unix'] ?? 0)) <=> ((int) ($left['last_activity_unix'] ?? 0));
        }
    );
}

$filteredTotal = count($activityRows);
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$visibleRows = array_slice($activityRows, $offset, $perPage);
$paginationBase = [
    'course_id' => (int) $selectedCourseId,
];
if ($searchQuery !== '') {
    $paginationBase['q'] = $searchQuery;
}
if ($overallFilter !== 'all') {
    $paginationBase['overall'] = $overallFilter;
}
if ($examFilter !== 'all') {
    $paginationBase['exam'] = $examFilter;
}

if ((string) ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'course_title' => $selectedCourseTitle,
        'student_count' => $filteredTotal,
        'rows' => $visibleRows,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'per_page' => $perPage,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="clms-dashboard">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-3 mb-3">
                  <div>
                    <h4 class="fw-bold mb-1">Student Activity Monitor</h4>
                    <small class="text-muted">Track who is watching videos, taking assessments, final exam progress, and completion status.</small>
                  </div>
                </div>

                <div class="card mb-4">
                  <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                      <div class="col-md-6 col-lg-4">
                        <label for="course_id" class="form-label">Course</label>
                        <select id="course_id" name="course_id" class="form-select" onchange="this.form.submit()">
<?php foreach ($courses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-6 col-lg-3">
                        <label for="q" class="form-label">Student</label>
                        <input id="q" name="q" class="form-control" type="text" placeholder="Search name..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" />
                      </div>
                      <div class="col-md-6 col-lg-2">
                        <label for="overall" class="form-label">Overall</label>
                        <select id="overall" name="overall" class="form-select">
                          <option value="all" <?php echo $overallFilter === 'all' ? 'selected' : ''; ?>>All</option>
                          <option value="not_started" <?php echo $overallFilter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                          <option value="in_progress" <?php echo $overallFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                          <option value="done" <?php echo $overallFilter === 'done' ? 'selected' : ''; ?>>Done</option>
                        </select>
                      </div>
                      <div class="col-md-6 col-lg-2">
                        <label for="exam" class="form-label">Final Exam</label>
                        <select id="exam" name="exam" class="form-select">
                          <option value="all" <?php echo $examFilter === 'all' ? 'selected' : ''; ?>>All</option>
                          <option value="not_started" <?php echo $examFilter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                          <option value="in_progress" <?php echo $examFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                          <option value="passed" <?php echo $examFilter === 'passed' ? 'selected' : ''; ?>>Passed</option>
                          <option value="failed" <?php echo $examFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                      </div>
                      <div class="col-md-6 col-lg-1 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary w-100">Go</button>
                      </div>
                    </form>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars((string) ($selectedCourseTitle ?? 'Selected Course'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <span class="badge bg-label-primary" id="activityStudentCount"><?php echo $filteredTotal; ?> students</span>
                  </div>
                  <div class="card-body">
<?php if ($courses === []) : ?>
                    <p class="mb-0">No published courses found.</p>
<?php elseif ($visibleRows === []) : ?>
                    <p class="mb-0" id="activityEmptyState">No student activity found for this course yet.</p>
<?php else : ?>
                    <p class="mb-0 d-none" id="activityEmptyState">No student activity found for this course yet.</p>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Student</th>
                            <th class="text-center">Watching Videos</th>
                            <th class="text-center">Assessments</th>
                            <th class="text-center">Final Exam</th>
                            <th class="text-center">Overall</th>
                            <th class="text-center">Last Activity</th>
                          </tr>
                        </thead>
                        <tbody id="activityTableBody">
<?php foreach ($visibleRows as $row) : ?>
                          <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars((string) $row['student_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center">
                              <span class="badge <?php echo htmlspecialchars((string) $row['video_badge'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['video_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                              <small class="text-muted d-block"><?php echo htmlspecialchars((string) $row['video_progress'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="text-center">
                              <span class="badge <?php echo htmlspecialchars((string) $row['assessment_badge'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['assessment_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                              <small class="text-muted d-block"><?php echo htmlspecialchars((string) $row['assessment_progress'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="text-center">
                              <span class="badge <?php echo htmlspecialchars((string) $row['exam_badge'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['exam_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="text-center">
                              <span class="badge <?php echo htmlspecialchars((string) $row['overall_badge'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['overall_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="text-center">
<?php if ((int) $row['last_activity_unix'] > 0) : ?>
                              <small><?php echo htmlspecialchars((string) date('M j, g:i A', (int) $row['last_activity_unix']), ENT_QUOTES, 'UTF-8'); ?></small>
<?php else : ?>
                              <small class="text-muted">-</small>
<?php endif; ?>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php if ($totalPages > 1) : ?>
                    <nav aria-label="Student activity pagination" class="mt-3">
                      <ul class="pagination pagination-sm mb-0 justify-content-end">
<?php
  $prevParams = $paginationBase;
  if ($page > 2) {
      $prevParams['page'] = $page - 1;
  }
  $prevUrl = $clmsWebBase . '/admin/student_activity.php' . ($prevParams !== [] ? '?' . http_build_query($prevParams) : '');
?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                          <a class="page-link" href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                        </li>
<?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++) : ?>
<?php
  $pageParams = $paginationBase;
  if ($pageNumber > 1) {
      $pageParams['page'] = $pageNumber;
  }
  $pageUrl = $clmsWebBase . '/admin/student_activity.php' . ($pageParams !== [] ? '?' . http_build_query($pageParams) : '');
?>
                        <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                          <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $pageNumber; ?></a>
                        </li>
<?php endfor; ?>
<?php
  $nextParams = $paginationBase;
  $nextParams['page'] = min($totalPages, $page + 1);
  $nextUrl = $clmsWebBase . '/admin/student_activity.php?' . http_build_query($nextParams);
?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                          <a class="page-link" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                        </li>
                      </ul>
                    </nav>
<?php endif; ?>
<?php endif; ?>
                  </div>
                </div>
              </div>

              <script>
                (() => {
                  const bodyEl = document.getElementById('activityTableBody');
                  const emptyEl = document.getElementById('activityEmptyState');
                  const countEl = document.getElementById('activityStudentCount');
                  if (!bodyEl || !emptyEl || !countEl) return;

                  const escapeHtml = (value) => String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');

                  const buildRow = (row) => {
                    const lastActivity = Number(row.last_activity_unix) > 0
                      ? new Date(Number(row.last_activity_unix) * 1000).toLocaleString()
                      : '-';
                    return `
                      <tr>
                        <td class="fw-semibold">${escapeHtml(row.student_name)}</td>
                        <td class="text-center">
                          <span class="badge ${escapeHtml(row.video_badge)}">${escapeHtml(row.video_label)}</span>
                          <small class="text-muted d-block">${escapeHtml(row.video_progress)}</small>
                        </td>
                        <td class="text-center">
                          <span class="badge ${escapeHtml(row.assessment_badge)}">${escapeHtml(row.assessment_label)}</span>
                          <small class="text-muted d-block">${escapeHtml(row.assessment_progress)}</small>
                        </td>
                        <td class="text-center">
                          <span class="badge ${escapeHtml(row.exam_badge)}">${escapeHtml(row.exam_label)}</span>
                        </td>
                        <td class="text-center">
                          <span class="badge ${escapeHtml(row.overall_badge)}">${escapeHtml(row.overall_label)}</span>
                        </td>
                        <td class="text-center"><small>${escapeHtml(lastActivity)}</small></td>
                      </tr>
                    `;
                  };

                  const poll = async () => {
                    try {
                      const url = new URL(window.location.href);
                      url.searchParams.set('ajax', '1');
                      const res = await fetch(url.toString(), { cache: 'no-store' });
                      if (!res.ok) return;
                      const payload = await res.json();
                      if (!payload || payload.ok !== true || !Array.isArray(payload.rows)) return;

                      countEl.textContent = `${payload.student_count} students`;
                      if (payload.rows.length === 0) {
                        bodyEl.innerHTML = '';
                        emptyEl.classList.remove('d-none');
                      } else {
                        bodyEl.innerHTML = payload.rows.map(buildRow).join('');
                        emptyEl.classList.add('d-none');
                      }
                    } catch (err) {
                      // Silent fail for transient network/server hiccups.
                    }
                  };

                  setInterval(() => {
                    if (!document.hidden) {
                      poll();
                    }
                  }, 5000);
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';

