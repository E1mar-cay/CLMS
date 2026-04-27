<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Students | Criminology LMS';
$activeAdminPage = 'students';

/* AJAX partial mode: when `?ajax=1` is passed we skip the page chrome and
   render only the students-list card body (rows + pagination). This is what
   the real-time search JS fetches to swap into the DOM without a reload. */
$isAjaxRequest = !empty($_GET['ajax']);

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$page = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$perPage = 10;
$offset = ($page - 1) * $perPage;

$selectedStudentId = (int) filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);

$whereSql = "WHERE u.role = 'student'";
$params = [];
if ($searchQuery !== '') {
    $whereSql .= ' AND (u.first_name LIKE :search_first OR u.last_name LIKE :search_last OR u.email LIKE :search_email OR CONCAT(u.first_name, " ", u.last_name) LIKE :search_full)';
    $likeValue = '%' . $searchQuery . '%';
    $params['search_first'] = $likeValue;
    $params['search_last'] = $likeValue;
    $params['search_email'] = $likeValue;
    $params['search_full'] = $likeValue;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_rows
     FROM users u
     {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['total_rows'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$studentStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.created_at,
        COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.module_id END) AS modules_completed,
        COUNT(DISTINCT ea.id) AS attempts_total,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COUNT(DISTINCT cert.id) AS certificates_count
     FROM users u
     LEFT JOIN user_progress up ON up.user_id = u.id
     LEFT JOIN exam_attempts ea ON ea.user_id = u.id
     LEFT JOIN certificates cert ON cert.user_id = u.id
     {$whereSql}
     GROUP BY u.id, u.first_name, u.last_name, u.email, u.created_at
     ORDER BY u.last_name ASC, u.first_name ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $studentStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$studentStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$studentStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$studentStmt->execute();
$students = $studentStmt->fetchAll();

$totalModulesStmt = $pdo->query('SELECT COUNT(*) AS total FROM modules');
$totalModulesOverall = (int) ($totalModulesStmt->fetch()['total'] ?? 0);

$selectedStudent = null;
$courseProgress = [];
$recentAttempts = [];
if ($selectedStudentId > 0) {
    $selectedStmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, created_at
         FROM users
         WHERE id = :id AND role = 'student'
         LIMIT 1"
    );
    $selectedStmt->execute(['id' => $selectedStudentId]);
    $selectedStudent = $selectedStmt->fetch() ?: null;

    if ($selectedStudent !== null) {
        $progressStmt = $pdo->prepare(
            "SELECT
                c.id AS course_id,
                c.title AS course_title,
                COUNT(DISTINCT m.id) AS total_modules,
                COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.module_id END) AS completed_modules,
                MAX(ea.total_score) AS best_score,
                MAX(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END) AS has_passed
             FROM courses c
             INNER JOIN modules m ON m.course_id = c.id
             LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id_progress
             LEFT JOIN exam_attempts ea ON ea.course_id = c.id AND ea.user_id = :user_id_attempt
             GROUP BY c.id, c.title
             ORDER BY c.title ASC"
        );
        $progressStmt->execute([
            'user_id_progress' => $selectedStudentId,
            'user_id_attempt' => $selectedStudentId,
        ]);
        $courseProgress = $progressStmt->fetchAll();

        $attemptsStmt = $pdo->prepare(
            "SELECT ea.id, ea.status, ea.total_score, ea.is_passed, ea.attempted_at, ea.completed_at, c.title AS course_title
             FROM exam_attempts ea
             INNER JOIN courses c ON c.id = ea.course_id
             WHERE ea.user_id = :user_id
             ORDER BY ea.attempted_at DESC
             LIMIT 5"
        );
        $attemptsStmt->execute(['user_id' => $selectedStudentId]);
        $recentAttempts = $attemptsStmt->fetchAll();
    }
}

$queryBase = [];
if ($searchQuery !== '') {
    $queryBase['q'] = $searchQuery;
}
$paginationBase = $queryBase;

if ($isAjaxRequest) {
    /* Partial response: just the replaceable card body. No layout, no
       selected-student card, no headers — the client swaps this into
       #clms-students-partial. */
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    require __DIR__ . '/includes/students-list-partial.php';
    return;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Students</h4>
                  <small class="text-muted">Browse enrolled learners and review their progress.</small>
                </div>
              </div>

<?php if ($selectedStudent !== null) : ?>
              <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    <h5 class="mb-0">
                      <?php echo htmlspecialchars(trim((string) $selectedStudent['first_name'] . ' ' . (string) $selectedStudent['last_name']), ENT_QUOTES, 'UTF-8'); ?>
                    </h5>
                    <small class="text-muted"><?php echo htmlspecialchars((string) $selectedStudent['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                  </div>
                  <a
                    href="<?php echo htmlspecialchars($clmsWebBase . '/admin/students.php' . ($queryBase !== [] ? '?' . http_build_query($queryBase) : ''), ENT_QUOTES, 'UTF-8'); ?>"
                    class="btn btn-outline-secondary btn-sm">
                    <i class="bx bx-x"></i> Close
                  </a>
                </div>
                <div class="card-body">
                  <h6 class="mb-3">Course Progress</h6>
<?php if ($courseProgress === []) : ?>
                  <p class="text-muted mb-4">No course progress yet.</p>
<?php else : ?>
                  <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>Course</th>
                          <th>Modules</th>
                          <th>Completion</th>
                          <th>Best Score</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($courseProgress as $progressRow) : ?>
<?php
    $totalModules = (int) $progressRow['total_modules'];
    $completedModules = (int) $progressRow['completed_modules'];
    $percent = $totalModules > 0 ? (int) round(($completedModules / $totalModules) * 100) : 0;
    $bestScore = $progressRow['best_score'] !== null ? (float) $progressRow['best_score'] : null;
    $hasPassed = (int) $progressRow['has_passed'] === 1;
?>
                        <tr>
                          <td><?php echo htmlspecialchars((string) $progressRow['course_title'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><?php echo $completedModules; ?> / <?php echo $totalModules; ?></td>
                          <td style="min-width: 160px;">
                            <div class="progress" style="height: 6px;">
                              <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted"><?php echo $percent; ?>%</small>
                          </td>
                          <td><?php echo $bestScore !== null ? number_format($bestScore, 2) . '%' : '—'; ?></td>
                          <td>
                            <span class="badge <?php echo $hasPassed ? 'bg-label-success' : 'bg-label-warning'; ?>">
                              <?php echo $hasPassed ? 'Passed' : 'In Progress'; ?>
                            </span>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>

                  <h6 class="mb-3">Recent Exam Attempts</h6>
<?php if ($recentAttempts === []) : ?>
                  <p class="text-muted mb-0">No exam attempts recorded.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>Course</th>
                          <th>Status</th>
                          <th>Score</th>
                          <th>Started</th>
                          <th>Completed</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($recentAttempts as $attempt) : ?>
                        <tr>
                          <td><?php echo htmlspecialchars((string) $attempt['course_title'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
                            <span class="badge bg-label-<?php echo (string) $attempt['status'] === 'completed' ? 'success' : ((string) $attempt['status'] === 'in_progress' ? 'info' : 'warning'); ?>">
                              <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $attempt['status'])), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                          </td>
                          <td>
<?php if ($attempt['total_score'] !== null) : ?>
                            <?php echo number_format((float) $attempt['total_score'], 2); ?>%
                            <?php if ((int) $attempt['is_passed'] === 1) : ?>
                              <i class="bx bx-check-circle text-success"></i>
                            <?php endif; ?>
<?php else : ?>
                            —
<?php endif; ?>
                          </td>
                          <td><?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) $attempt['attempted_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
<?php if ($attempt['completed_at'] !== null) : ?>
                            <?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) $attempt['completed_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
<?php else : ?>
                            —
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
<?php endif; ?>

              <div class="card" id="clms-students-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h5 class="mb-0">All Students</h5>
                  <form
                    method="get"
                    action="<?php echo htmlspecialchars($clmsWebBase . '/admin/students.php', ENT_QUOTES, 'UTF-8'); ?>"
                    class="d-flex gap-2 align-items-center"
                    id="clms-students-search-form"
                    role="search">
                    <div class="clms-students-search-field">
                      <i class="bx bx-search clms-students-search-icon"></i>
                      <input
                        type="search"
                        class="form-control form-control-sm clms-students-search-input"
                        id="clms-students-search-input"
                        name="q"
                        value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Search by name or email..."
                        autocomplete="off" />
                      <span
                        class="spinner-border spinner-border-sm text-primary clms-students-search-spinner d-none"
                        id="clms-students-search-spinner"
                        role="status"
                        aria-hidden="true"></span>
                    </div>
                    <style>
                      .clms-students-search-field {
                        position: relative;
                        min-width: 260px;
                      }
                      .clms-students-search-icon {
                        position: absolute;
                        left: .75rem;
                        top: 50%;
                        transform: translateY(-50%);
                        color: #8592a3;
                        font-size: 1rem;
                        pointer-events: none;
                        z-index: 2;
                      }
                      /* Compound selector + `!important` so Sneat's
                         `.form-control { padding: …shorthand }` can't reset
                         the asymmetric padding we need for the icon/spinner. */
                      .clms-students-search-field .clms-students-search-input.form-control {
                        padding: .35rem 2.25rem !important;
                        position: relative;
                        z-index: 1;
                      }
                      /* Chromium shows its own cancel "x" — we hide it so it
                         doesn't collide with our spinner on the right. */
                      .clms-students-search-input::-webkit-search-cancel-button {
                        -webkit-appearance: none;
                      }
                      .clms-students-search-spinner {
                        position: absolute;
                        right: .6rem;
                        top: 50%;
                        transform: translateY(-50%);
                      }
                    </style>
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm <?php echo $searchQuery === '' ? 'd-none' : ''; ?>"
                      id="clms-students-search-clear">
                      Clear
                    </button>
                    <noscript>
                      <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    </noscript>
                  </form>
                </div>
                <div class="card-body position-relative" id="clms-students-partial" aria-live="polite" aria-busy="false">
<?php require __DIR__ . '/includes/students-list-partial.php'; ?>
                </div>
              </div>

              <script>
                (() => {
                  const form      = document.getElementById('clms-students-search-form');
                  const input     = document.getElementById('clms-students-search-input');
                  const spinner   = document.getElementById('clms-students-search-spinner');
                  const clearBtn  = document.getElementById('clms-students-search-clear');
                  const partial   = document.getElementById('clms-students-partial');
                  if (!form || !input || !partial) return;

                  const endpoint = <?php echo json_encode($clmsWebBase . '/admin/students.php', JSON_UNESCAPED_SLASHES); ?>;
                  let currentPage = <?php echo (int) $page; ?>;
                  let debounceId  = null;
                  let inFlight    = null;

                  /* Build the URL for both the AJAX fetch and the address-bar
                     history update. They differ only by the `ajax=1` flag. */
                  const buildUrl = (ajax, { q, page, studentId }) => {
                    const params = new URLSearchParams();
                    if (q) params.set('q', q);
                    if (page && page > 1) params.set('page', String(page));
                    if (studentId) params.set('student_id', String(studentId));
                    if (ajax) params.set('ajax', '1');
                    const qs = params.toString();
                    return qs ? `${endpoint}?${qs}` : endpoint;
                  };

                  const setBusy = (busy) => {
                    partial.setAttribute('aria-busy', busy ? 'true' : 'false');
                    partial.style.opacity = busy ? '0.55' : '';
                    if (spinner) spinner.classList.toggle('d-none', !busy);
                  };

                  const fetchAndSwap = async ({ q, page }) => {
                    if (inFlight) inFlight.abort();
                    const controller = new AbortController();
                    inFlight = controller;
                    setBusy(true);
                    try {
                      const response = await fetch(
                        buildUrl(true, { q, page }),
                        { signal: controller.signal, credentials: 'same-origin' }
                      );
                      if (!response.ok) throw new Error(`HTTP ${response.status}`);
                      const html = await response.text();
                      partial.innerHTML = html;
                      currentPage = page || 1;

                      /* Keep the address bar in sync so refresh / share still
                         land on the same filtered view. */
                      history.replaceState(null, '', buildUrl(false, { q, page }));

                      /* Let the navbar search (data-search-item) re-apply any
                         active client-side filter against the new rows. */
                      const navbarSearch = document.getElementById('clmsNavbarSearch');
                      if (navbarSearch && navbarSearch.value) {
                        navbarSearch.dispatchEvent(new Event('input'));
                      }
                    } catch (err) {
                      if (err.name !== 'AbortError') {
                        console.error('Students search failed:', err);
                        partial.innerHTML =
                          '<p class="text-danger mb-0"><i class="bx bx-error-circle me-1"></i>' +
                          'Could not load results. Please try again.</p>';
                      }
                    } finally {
                      if (inFlight === controller) inFlight = null;
                      setBusy(false);
                    }
                  };

                  /* Debounced input: 250ms feels responsive without hammering
                     the DB on every keystroke. Each new keystroke resets the
                     page to 1 (starting a new search should always land on
                     the first page). */
                  input.addEventListener('input', () => {
                    clearTimeout(debounceId);
                    const q = input.value.trim();
                    clearBtn.classList.toggle('d-none', q === '');
                    debounceId = setTimeout(() => fetchAndSwap({ q, page: 1 }), 250);
                  });

                  /* Enter on the input should just flush the pending request
                     immediately rather than submit the form (full reload). */
                  form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    clearTimeout(debounceId);
                    fetchAndSwap({ q: input.value.trim(), page: 1 });
                  });

                  clearBtn.addEventListener('click', () => {
                    input.value = '';
                    clearBtn.classList.add('d-none');
                    clearTimeout(debounceId);
                    fetchAndSwap({ q: '', page: 1 });
                    input.focus();
                  });

                  /* Delegate pagination clicks so links inserted by the AJAX
                     swap keep working without re-binding on every render. */
                  partial.addEventListener('click', (event) => {
                    const link = event.target.closest('a[data-students-page]');
                    if (!link) return;
                    const li = link.closest('.page-item');
                    if (li && li.classList.contains('disabled')) {
                      event.preventDefault();
                      return;
                    }
                    event.preventDefault();
                    const nextPage = parseInt(link.getAttribute('data-students-page'), 10) || 1;
                    fetchAndSwap({ q: input.value.trim(), page: nextPage });
                    window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
                  });
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
