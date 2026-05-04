<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/user-approval.php';
require_once dirname(__DIR__) . '/includes/student-batch-schema.php';

clms_require_roles(['admin', 'instructor']);
clms_user_approval_ensure_schema($pdo);
clms_ensure_users_student_batch_column($pdo);

$pageTitle = 'Students | Criminology LMS';
$activeAdminPage = 'students';

/* AJAX modes:
   - `?ajax=1` — list partial for #clms-students-partial (search / pagination).
   - `?ajax=1&progress=1&student_id=N` — modal body HTML for View Progress. */
$isListAjax = !empty($_GET['ajax']) && empty($_GET['progress']);
$isProgressAjax = !empty($_GET['ajax']) && isset($_GET['progress']) && (string) $_GET['progress'] === '1';

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$page = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$perPage = 10;
$offset = ($page - 1) * $perPage;

$studentsPageUrlStudentId = (int) filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);

$filterAccount = trim((string) ($_GET['account'] ?? ''));
if ($filterAccount !== '' && !in_array($filterAccount, ['active', 'disabled'], true)) {
    $filterAccount = '';
}
$filterApproval = strtolower(trim((string) ($_GET['approval'] ?? '')));
if ($filterApproval !== '' && !in_array($filterApproval, ['pending', 'approved', 'rejected'], true)) {
    $filterApproval = '';
}
$filterBatchRaw = (string) ($_GET['batch'] ?? '');
$filterBatch = '';
if ($filterBatchRaw === '__none__') {
    $filterBatch = '__none__';
} else {
    $filterBatchTrim = trim($filterBatchRaw);
    if ($filterBatchTrim !== '') {
        $blen = function_exists('mb_strlen')
            ? mb_strlen($filterBatchTrim, 'UTF-8')
            : strlen($filterBatchTrim);
        if ($blen <= 80) {
            $filterBatch = $filterBatchTrim;
        }
    }
}

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
if ($filterAccount === 'active') {
    $whereSql .= ' AND (COALESCE(u.account_is_disabled, 0) = 0)';
} elseif ($filterAccount === 'disabled') {
    $whereSql .= ' AND u.account_is_disabled = 1';
}
if ($filterApproval !== '') {
    $whereSql .= ' AND u.account_approval_status = :f_approval';
    $params['f_approval'] = $filterApproval;
}
if ($filterBatch !== '') {
    if ($filterBatch === '__none__') {
        $whereSql .= " AND (u.student_batch IS NULL OR TRIM(COALESCE(u.student_batch, '')) = '')";
    } else {
        $whereSql .= ' AND LOWER(TRIM(COALESCE(u.student_batch, \'\'))) = :f_batch_lc';
        $params['f_batch_lc'] = function_exists('mb_strtolower')
            ? mb_strtolower($filterBatch, 'UTF-8')
            : strtolower($filterBatch);
    }
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

$studentBatchFilterOptions = [];
try {
    $sbStmt = $pdo->query(
        "SELECT DISTINCT TRIM(student_batch) AS b
         FROM users
         WHERE role = 'student'
           AND TRIM(COALESCE(student_batch, '')) <> ''
         ORDER BY b ASC"
    );
    foreach ($sbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $b = (string) ($row['b'] ?? '');
        if ($b !== '') {
            $studentBatchFilterOptions[] = $b;
        }
    }
} catch (Throwable $e) {
    $studentBatchFilterOptions = [];
}

$queryBase = [];
if ($searchQuery !== '') {
    $queryBase['q'] = $searchQuery;
}
if ($filterAccount !== '') {
    $queryBase['account'] = $filterAccount;
}
if ($filterApproval !== '') {
    $queryBase['approval'] = $filterApproval;
}
if ($filterBatch !== '') {
    $queryBase['batch'] = $filterBatch;
}
$paginationBase = $queryBase;

if ($isProgressAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $progressStudentId = (int) filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
    $selectedStudent = null;
    $courseProgress = [];
    $recentAttempts = [];
    if ($progressStudentId <= 0) {
        http_response_code(400);
        echo '<p class="text-danger mb-0">Invalid student.</p>';
        return;
    }
    $selectedStmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, created_at
         FROM users
         WHERE id = :id AND role = 'student'
         LIMIT 1"
    );
    $selectedStmt->execute(['id' => $progressStudentId]);
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
            'user_id_progress' => $progressStudentId,
            'user_id_attempt' => $progressStudentId,
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
        $attemptsStmt->execute(['user_id' => $progressStudentId]);
        $recentAttempts = $attemptsStmt->fetchAll();
    }
    require __DIR__ . '/includes/students-progress-modal-body.php';
    return;
}

if ($isListAjax) {
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

              <div class="modal fade" id="clmsStudentProgressModal" tabindex="-1" aria-labelledby="clmsStudentProgressModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header">
                      <div class="pe-3">
                        <h5 class="modal-title" id="clmsStudentProgressModalLabel">Student progress</h5>
                        <small class="text-muted d-block d-none" id="clmsStudentProgressModalEmail"></small>
                      </div>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="clmsStudentProgressModalBody">
                      <div class="text-center py-4 text-muted" id="clmsStudentProgressModalPlaceholder">
                        <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                        <span class="visually-hidden">Loading…</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card" id="clms-students-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h5 class="mb-0">All Students</h5>
                  <form
                    method="get"
                    action="<?php echo htmlspecialchars($clmsWebBase . '/admin/students.php', ENT_QUOTES, 'UTF-8'); ?>"
                    class="d-flex flex-wrap gap-2 align-items-center clms-students-toolbar-form"
                    id="clms-students-search-form"
                    role="search">
                    <div class="d-flex flex-wrap gap-2 align-items-center clms-students-filters">
                      <label class="visually-hidden" for="clms-students-filter-batch">Batch</label>
                      <select class="form-select form-select-sm clms-students-filter-select" name="batch" id="clms-students-filter-batch" title="Batch / cohort">
                        <option value=""<?php echo $filterBatch === '' ? ' selected' : ''; ?>>All batches</option>
                        <option value="__none__"<?php echo $filterBatch === '__none__' ? ' selected' : ''; ?>>Unspecified batch</option>
<?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                        <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filterBatch === $batchOpt ? ' selected' : ''; ?>><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                      </select>
                      <label class="visually-hidden" for="clms-students-filter-account">Account</label>
                      <select class="form-select form-select-sm clms-students-filter-select" name="account" id="clms-students-filter-account" title="Account status">
                        <option value=""<?php echo $filterAccount === '' ? ' selected' : ''; ?>>All accounts</option>
                        <option value="active"<?php echo $filterAccount === 'active' ? ' selected' : ''; ?>>Active only</option>
                        <option value="disabled"<?php echo $filterAccount === 'disabled' ? ' selected' : ''; ?>>Disabled only</option>
                      </select>
                      <label class="visually-hidden" for="clms-students-filter-approval">Approval</label>
                      <select class="form-select form-select-sm clms-students-filter-select" name="approval" id="clms-students-filter-approval" title="Registration approval">
                        <option value=""<?php echo $filterApproval === '' ? ' selected' : ''; ?>>All approval states</option>
                        <option value="pending"<?php echo $filterApproval === 'pending' ? ' selected' : ''; ?>>Pending</option>
                        <option value="approved"<?php echo $filterApproval === 'approved' ? ' selected' : ''; ?>>Approved</option>
                        <option value="rejected"<?php echo $filterApproval === 'rejected' ? ' selected' : ''; ?>>Rejected</option>
                      </select>
                    </div>
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
                      .clms-students-toolbar-form {
                        justify-content: flex-end;
                      }
                      .clms-students-filter-select {
                        width: auto;
                        min-width: 7.5rem;
                        max-width: 12rem;
                      }
                      #clms-students-filter-batch {
                        min-width: 9.5rem;
                        max-width: 14rem;
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
                  const form = document.getElementById('clms-students-search-form');
                  const input = document.getElementById('clms-students-search-input');
                  const spinner = document.getElementById('clms-students-search-spinner');
                  const clearBtn = document.getElementById('clms-students-search-clear');
                  const partial = document.getElementById('clms-students-partial');
                  const batchSel = document.getElementById('clms-students-filter-batch');
                  const accountSel = document.getElementById('clms-students-filter-account');
                  const approvalSel = document.getElementById('clms-students-filter-approval');
                  const progressModalEl = document.getElementById('clmsStudentProgressModal');
                  const progressModalBody = document.getElementById('clmsStudentProgressModalBody');
                  const progressModalTitle = document.getElementById('clmsStudentProgressModalLabel');
                  const progressModalEmail = document.getElementById('clmsStudentProgressModalEmail');
                  if (!form || !input || !partial) return;

                  const endpoint = <?php echo json_encode($clmsWebBase . '/admin/students.php', JSON_UNESCAPED_SLASHES); ?>;
                  let currentPage = <?php echo (int) $page; ?>;
                  let debounceId = null;
                  let inFlight = null;
                  let progressInFlight = null;

                  const loadingProgressHtml =
                    '<div class="text-center py-4 text-muted" id="clmsStudentProgressModalPlaceholder">' +
                    '<div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>' +
                    '<span class="visually-hidden">Loading…</span></div>';

                  const readListFilters = () => ({
                    q: input.value.trim(),
                    batch: batchSel ? batchSel.value : '',
                    account: accountSel ? accountSel.value : '',
                    approval: approvalSel ? approvalSel.value : ''
                  });

                  const buildListFetchUrl = (f) => {
                    const params = new URLSearchParams();
                    if (f.q) params.set('q', f.q);
                    if (f.page > 1) params.set('page', String(f.page));
                    if (f.batch) params.set('batch', f.batch);
                    if (f.account) params.set('account', f.account);
                    if (f.approval) params.set('approval', f.approval);
                    params.set('ajax', '1');
                    return `${endpoint}?${params.toString()}`;
                  };

                  const buildPageUrl = (f) => {
                    const params = new URLSearchParams();
                    if (f.q) params.set('q', f.q);
                    if (f.page > 1) params.set('page', String(f.page));
                    if (f.batch) params.set('batch', f.batch);
                    if (f.account) params.set('account', f.account);
                    if (f.approval) params.set('approval', f.approval);
                    if (f.studentId) params.set('student_id', String(f.studentId));
                    const qs = params.toString();
                    return qs ? `${endpoint}?${qs}` : endpoint;
                  };

                  const buildProgressFetchUrl = (studentId) =>
                    `${endpoint}?ajax=1&progress=1&student_id=${encodeURIComponent(String(studentId))}`;

                  const applyProgressHeaderFromMeta = (bodyEl) => {
                    if (!progressModalTitle || !progressModalEmail) return;
                    const meta = bodyEl.querySelector('[data-clms-progress-name]');
                    if (meta) {
                      const n = meta.getAttribute('data-clms-progress-name') || '';
                      const e = meta.getAttribute('data-clms-progress-email') || '';
                      progressModalTitle.textContent = n || 'Student progress';
                      if (e) {
                        progressModalEmail.textContent = e;
                        progressModalEmail.classList.remove('d-none');
                      } else {
                        progressModalEmail.textContent = '';
                        progressModalEmail.classList.add('d-none');
                      }
                    }
                  };

                  const setBusy = (busy) => {
                    partial.setAttribute('aria-busy', busy ? 'true' : 'false');
                    partial.style.opacity = busy ? '0.55' : '';
                    if (spinner) spinner.classList.toggle('d-none', !busy);
                  };

                  const fetchAndSwap = async (patch = {}) => {
                    if (inFlight) inFlight.abort();
                    const controller = new AbortController();
                    inFlight = controller;
                    const f = { ...readListFilters(), page: currentPage, ...patch };
                    if (f.page < 1) f.page = 1;
                    setBusy(true);
                    try {
                      const response = await fetch(buildListFetchUrl(f), {
                        signal: controller.signal,
                        credentials: 'same-origin'
                      });
                      if (!response.ok) throw new Error(`HTTP ${response.status}`);
                      const html = await response.text();
                      partial.innerHTML = html;
                      currentPage = f.page;
                      history.replaceState(null, '', buildPageUrl({ ...f, studentId: 0 }));

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

                  const openProgressModal = async (studentId, nameHint, emailHint) => {
                    if (!progressModalEl || !progressModalBody || studentId < 1) return;
                    if (progressModalTitle) {
                      progressModalTitle.textContent = nameHint || 'Student progress';
                    }
                    if (progressModalEmail) {
                      if (emailHint) {
                        progressModalEmail.textContent = emailHint;
                        progressModalEmail.classList.remove('d-none');
                      } else {
                        progressModalEmail.textContent = '';
                        progressModalEmail.classList.add('d-none');
                      }
                    }
                    progressModalBody.innerHTML = loadingProgressHtml;
                    history.replaceState(
                      null,
                      '',
                      buildPageUrl({ ...readListFilters(), page: currentPage, studentId })
                    );
                    if (window.bootstrap && bootstrap.Modal) {
                      bootstrap.Modal.getOrCreateInstance(progressModalEl).show();
                    }
                    if (progressInFlight) progressInFlight.abort();
                    const controller = new AbortController();
                    progressInFlight = controller;
                    try {
                      const response = await fetch(buildProgressFetchUrl(studentId), {
                        signal: controller.signal,
                        credentials: 'same-origin'
                      });
                      if (!response.ok) throw new Error(`HTTP ${response.status}`);
                      const html = await response.text();
                      progressModalBody.innerHTML = html;
                      applyProgressHeaderFromMeta(progressModalBody);
                    } catch (err) {
                      if (err.name !== 'AbortError') {
                        progressModalBody.innerHTML =
                          '<p class="text-danger mb-0"><i class="bx bx-error-circle me-1"></i>Could not load progress. Please try again.</p>';
                      }
                    } finally {
                      if (progressInFlight === controller) progressInFlight = null;
                    }
                  };

                  if (progressModalEl) {
                    progressModalEl.addEventListener('hidden.bs.modal', () => {
                      if (progressInFlight) progressInFlight.abort();
                      progressModalBody.innerHTML = loadingProgressHtml;
                      history.replaceState(null, '', buildPageUrl({ ...readListFilters(), page: currentPage }));
                    });
                  }

                  input.addEventListener('input', () => {
                    clearTimeout(debounceId);
                    const q = input.value.trim();
                    if (clearBtn) clearBtn.classList.toggle('d-none', q === '');
                    debounceId = setTimeout(() => fetchAndSwap({ q, page: 1 }), 250);
                  });

                  form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    clearTimeout(debounceId);
                    fetchAndSwap({ page: 1 });
                  });

                  if (batchSel) {
                    batchSel.addEventListener('change', () => {
                      clearTimeout(debounceId);
                      fetchAndSwap({ page: 1 });
                    });
                  }
                  if (accountSel) {
                    accountSel.addEventListener('change', () => {
                      clearTimeout(debounceId);
                      fetchAndSwap({ page: 1 });
                    });
                  }
                  if (approvalSel) {
                    approvalSel.addEventListener('change', () => {
                      clearTimeout(debounceId);
                      fetchAndSwap({ page: 1 });
                    });
                  }

                  if (clearBtn) {
                    clearBtn.addEventListener('click', () => {
                      input.value = '';
                      clearBtn.classList.add('d-none');
                      clearTimeout(debounceId);
                      fetchAndSwap({ q: '', page: 1 });
                      input.focus();
                    });
                  }

                  partial.addEventListener('click', (event) => {
                    const progBtn = event.target.closest('.clms-student-progress-btn');
                    if (progBtn) {
                      event.preventDefault();
                      const sid = parseInt(progBtn.getAttribute('data-student-id'), 10) || 0;
                      const nm = progBtn.getAttribute('data-student-name') || '';
                      const em = progBtn.getAttribute('data-student-email') || '';
                      openProgressModal(sid, nm, em);
                      return;
                    }
                    const link = event.target.closest('a[data-students-page]');
                    if (!link) return;
                    const li = link.closest('.page-item');
                    if (li && li.classList.contains('disabled')) {
                      event.preventDefault();
                      return;
                    }
                    event.preventDefault();
                    const nextPage = parseInt(link.getAttribute('data-students-page'), 10) || 1;
                    fetchAndSwap({ page: nextPage });
                    window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
                  });

                  const initialStudentId = <?php echo (int) $studentsPageUrlStudentId; ?>;
                  if (initialStudentId > 0) {
                    openProgressModal(initialStudentId, '', '');
                  }
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
