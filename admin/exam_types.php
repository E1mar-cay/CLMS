<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/clms-exam-types-schema.php';

clms_require_roles(['admin']);

clms_ensure_exam_types_schema($pdo);

$pageTitle = 'Exam Types | Criminology LMS';
$activeAdminPage = 'exam_types';
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create' || $action === 'update') {
                $nameInput = trim((string) ($_POST['name'] ?? ''));
                $descInput = trim((string) ($_POST['description'] ?? ''));
                $sortInput = filter_input(INPUT_POST, 'sort_order', FILTER_VALIDATE_INT);
                $isActiveInput = isset($_POST['is_active'])
                    ? 1
                    : ($action === 'create' ? 1 : 0);

                if ($nameInput === '' || mb_strlen($nameInput) > 160) {
                    throw new RuntimeException('Name is required (160 characters max).');
                }
                if (mb_strlen($descInput) > 500) {
                    throw new RuntimeException('Description must be 500 characters or fewer.');
                }
                $sortOrder = $sortInput !== false && $sortInput !== null ? (int) $sortInput : 0;

                if ($action === 'create') {
                    $ins = $pdo->prepare(
                        'INSERT INTO exam_types (name, description, sort_order, is_active)
                         VALUES (:name, :description, :sort_order, :is_active)'
                    );
                    $ins->execute([
                        'name' => $nameInput,
                        'description' => $descInput === '' ? null : $descInput,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActiveInput,
                    ]);
                    $successMessage = 'Exam type created.';
                } else {
                    $tid = (int) filter_input(INPUT_POST, 'exam_type_id', FILTER_VALIDATE_INT);
                    if ($tid <= 0) {
                        throw new RuntimeException('Invalid exam type id.');
                    }
                    $upd = $pdo->prepare(
                        'UPDATE exam_types
                         SET name = :name,
                             description = :description,
                             sort_order = :sort_order,
                             is_active = :is_active
                         WHERE id = :id'
                    );
                    $upd->execute([
                        'name' => $nameInput,
                        'description' => $descInput === '' ? null : $descInput,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActiveInput,
                        'id' => $tid,
                    ]);
                    $successMessage = 'Exam type updated.';
                    clms_redirect('admin/exam_types.php?flash=updated');
                }
            } elseif ($action === 'delete') {
                $tid = (int) filter_input(INPUT_POST, 'exam_type_id', FILTER_VALIDATE_INT);
                if ($tid <= 0) {
                    throw new RuntimeException('Invalid exam type id.');
                }
                $pdo->prepare('UPDATE questions SET exam_type_id = NULL WHERE exam_type_id = :id')->execute(['id' => $tid]);
                $pdo->prepare('UPDATE exam_attempts SET exam_type_id = NULL WHERE exam_type_id = :id')->execute(['id' => $tid]);
                $del = $pdo->prepare('DELETE FROM exam_types WHERE id = :id');
                $del->execute(['id' => $tid]);
                $successMessage = 'Exam type deleted.';
            } elseif ($action === 'toggle') {
                $tid = (int) filter_input(INPUT_POST, 'exam_type_id', FILTER_VALIDATE_INT);
                if ($tid <= 0) {
                    throw new RuntimeException('Invalid exam type id.');
                }
                $tog = $pdo->prepare('UPDATE exam_types SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
                $tog->execute(['id' => $tid]);
                $successMessage = 'Status updated.';
            }
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save exam type.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

if (($flash = (string) ($_GET['flash'] ?? '')) === 'updated') {
    $successMessage = $successMessage !== '' ? $successMessage : 'Exam type updated.';
}

$listStmt = $pdo->query(
    'SELECT et.id, et.name, et.description, et.sort_order, et.is_active,
            (SELECT COUNT(*) FROM questions q WHERE q.exam_type_id = et.id) AS question_count
     FROM exam_types et
     ORDER BY et.sort_order ASC, et.name ASC'
);
$examTypes = $listStmt ? $listStmt->fetchAll() : [];

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0"><i class="bx bx-plus-circle me-1"></i>Add exam type</h5>
  </div>
  <div class="card-body">
    <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/exam_types.php', ENT_QUOTES, 'UTF-8'); ?>" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <input type="hidden" name="action" value="create" />

      <div class="col-md-6">
        <label class="form-label" for="et_name">Name <span class="text-danger">*</span></label>
        <input class="form-control" id="et_name" name="name" required maxlength="160" value="" />
        <small class="text-muted">Shown to instructors when attaching questions (e.g. “Midterm”, “Oral board”).</small>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="et_sort">Sort order</label>
        <input class="form-control" id="et_sort" name="sort_order" type="number" value="0" />
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="is_active" id="et_active" value="1" checked />
          <label class="form-check-label" for="et_active">Active (available to instructors)</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label" for="et_desc">Description <small class="text-muted">(optional)</small></label>
        <input class="form-control" id="et_desc" name="description" maxlength="500" value="" />
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">
          <i class="bx bx-plus me-1"></i>Create exam type
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h5 class="mb-0">All exam types</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th>Order</th>
            <th>Name</th>
            <th>Questions</th>
            <th>Status</th>
            <th class="text-end" style="width: 1%;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($examTypes === []) : ?>
            <tr>
              <td colspan="5" class="text-muted text-center py-4">No exam types yet. Create one above.</td>
            </tr>
          <?php else : ?>
            <?php foreach ($examTypes as $et) : ?>
              <?php
                $etJson = json_encode(
                    [
                        'id' => (int) $et['id'],
                        'name' => (string) $et['name'],
                        'description' => (string) ($et['description'] ?? ''),
                        'sort_order' => (int) ($et['sort_order'] ?? 0),
                        'is_active' => (int) ($et['is_active'] ?? 0) === 1,
                    ],
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
                );
                $etJsonAttr = htmlspecialchars((string) $etJson, ENT_QUOTES, 'UTF-8');
                $isActiveRow = (int) ($et['is_active'] ?? 0) === 1;
              ?>
              <tr>
                <td><?php echo (int) $et['sort_order']; ?></td>
                <td>
                  <strong><?php echo htmlspecialchars((string) $et['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <?php if (trim((string) ($et['description'] ?? '')) !== '') : ?>
                    <div class="small text-muted"><?php echo htmlspecialchars((string) $et['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php endif; ?>
                </td>
                <td><?php echo (int) ($et['question_count'] ?? 0); ?></td>
                <td>
                  <?php if ($isActiveRow) : ?>
                    <span class="badge bg-label-success">Active</span>
                  <?php else : ?>
                    <span class="badge bg-label-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <div class="d-inline-flex align-items-center gap-1 clms-actions-group flex-nowrap justify-content-end" role="group" aria-label="Actions">
                    <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/exam_types.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-clms-exam-type-toggle-form">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="action" value="toggle" />
                      <input type="hidden" name="exam_type_id" value="<?php echo (int) $et['id']; ?>" />
                      <button
                        type="submit"
                        class="btn btn-sm <?php echo $isActiveRow ? 'btn-outline-secondary' : 'btn-outline-success'; ?> clms-action-btn"
                        title="<?php echo $isActiveRow ? 'Deactivate' : 'Activate'; ?>"
                        aria-label="<?php echo $isActiveRow ? 'Deactivate' : 'Activate'; ?>"
                        data-exam-type-name="<?php echo htmlspecialchars((string) $et['name'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-will-be-active="<?php echo $isActiveRow ? '0' : '1'; ?>">
                        <i class="bx <?php echo $isActiveRow ? 'bx-block' : 'bx-check-circle'; ?>"></i>
                      </button>
                    </form>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary clms-action-btn js-exam-type-edit"
                      title="Edit"
                      aria-label="Edit"
                      data-exam-type="<?php echo $etJsonAttr; ?>">
                      <i class="bx bx-pencil"></i>
                    </button>
                    <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/exam_types.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-clms-exam-type-delete-form">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="action" value="delete" />
                      <input type="hidden" name="exam_type_id" value="<?php echo (int) $et['id']; ?>" />
                      <button
                        type="submit"
                        class="btn btn-sm btn-outline-danger clms-action-btn"
                        title="Delete"
                        aria-label="Delete"
                        data-exam-type-name="<?php echo htmlspecialchars((string) $et['name'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-question-count="<?php echo (int) ($et['question_count'] ?? 0); ?>">
                        <i class="bx bx-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-body border-top">
    <p class="small text-muted mb-0">
      Instructors attach bank questions to a type from <strong>Add Question</strong> (same menu as modules / final exam).
      Students open typed exams from the course catalog when the course has questions for that type.
      The <strong>course final exam</strong> still uses only questions marked as course-level (not module, not a custom type).
    </p>
  </div>
</div>

<!-- Edit exam type (modal) -->
<div class="modal fade" id="clmsExamTypeEditModal" tabindex="-1" aria-labelledby="clmsExamTypeEditModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/exam_types.php', ENT_QUOTES, 'UTF-8'); ?>" id="clmsExamTypeEditForm">
        <div class="modal-header">
          <h5 class="modal-title" id="clmsExamTypeEditModalLabel">
            <i class="bx bx-pencil me-1"></i>Edit exam type
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="update" />
          <input type="hidden" name="exam_type_id" id="clms_exam_type_edit_id" value="" />

          <div class="mb-3">
            <label class="form-label" for="clms_exam_type_edit_name">Name <span class="text-danger">*</span></label>
            <input class="form-control" id="clms_exam_type_edit_name" name="name" required maxlength="160" />
          </div>
          <div class="mb-3">
            <label class="form-label" for="clms_exam_type_edit_sort">Sort order</label>
            <input class="form-control" id="clms_exam_type_edit_sort" name="sort_order" type="number" value="0" />
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="clms_exam_type_edit_active" value="1" />
            <label class="form-check-label" for="clms_exam_type_edit_active">Active (available to instructors)</label>
          </div>
          <div class="mb-0">
            <label class="form-label" for="clms_exam_type_edit_desc">Description <small class="text-muted">(optional)</small></label>
            <input class="form-control" id="clms_exam_type_edit_desc" name="description" maxlength="500" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bx bx-save me-1"></i>Save changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  (() => {
    if (typeof ClmsNotify !== 'undefined') {
      ClmsNotify.fromFlash(
        <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
        <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
      );
    }

    const modalEl = document.getElementById('clmsExamTypeEditModal');
    const formEl = document.getElementById('clmsExamTypeEditForm');
    const idInput = document.getElementById('clms_exam_type_edit_id');
    const nameInput = document.getElementById('clms_exam_type_edit_name');
    const sortInput = document.getElementById('clms_exam_type_edit_sort');
    const activeInput = document.getElementById('clms_exam_type_edit_active');
    const descInput = document.getElementById('clms_exam_type_edit_desc');

    const openEditModal = (data) => {
      if (!formEl || !idInput || !nameInput || !sortInput || !activeInput || !descInput) return;
      idInput.value = String(data.id);
      nameInput.value = data.name || '';
      sortInput.value = String(data.sort_order ?? 0);
      descInput.value = data.description || '';
      activeInput.checked = !!data.is_active;
      if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    };

    document.querySelectorAll('.js-exam-type-edit').forEach((btn) => {
      btn.addEventListener('click', () => {
        const raw = btn.getAttribute('data-exam-type');
        if (!raw) return;
        try {
          const data = JSON.parse(raw);
          openEditModal(data);
        } catch (e) {
          if (typeof ClmsNotify !== 'undefined') {
            ClmsNotify.error('Could not read exam type data. Please refresh the page.');
          }
        }
      });
    });

    document.querySelectorAll('.js-clms-exam-type-toggle-form').forEach((form) => {
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        const name = btn ? btn.getAttribute('data-exam-type-name') || 'this exam type' : 'this exam type';
        const willBeActive = btn && btn.getAttribute('data-will-be-active') === '1';
        const actionLabel = willBeActive ? 'activate' : 'deactivate';
        if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') {
          if (window.confirm('Toggle status for “' + name + '”?')) form.submit();
          return;
        }
        ClmsNotify.confirm({
          icon: 'question',
          title: willBeActive ? 'Activate exam type?' : 'Deactivate exam type?',
          text: 'Are you sure you want to ' + actionLabel + ' “' + name + '”? Instructors only see active types in the attach list (inactive types appear disabled).',
          confirmButtonText: willBeActive ? 'Activate' : 'Deactivate',
          cancelButtonText: 'Cancel',
        }).then((r) => {
          if (r.isConfirmed) form.submit();
        });
      });
    });

    document.querySelectorAll('.js-clms-exam-type-delete-form').forEach((form) => {
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        const name = btn ? btn.getAttribute('data-exam-type-name') || 'this exam type' : 'this exam type';
        const qCount = btn ? parseInt(btn.getAttribute('data-question-count') || '0', 10) : 0;
        const detail =
          qCount > 0
            ? 'Questions using “' + name + '” will be detached (they move to the course-level final pool until reassigned).'
            : 'This will remove “' + name + '” from the list.';
        if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') {
          if (window.confirm('Delete ' + name + '?')) form.submit();
          return;
        }
        ClmsNotify.confirm({
          icon: 'warning',
          title: 'Delete exam type?',
          text: detail,
          danger: true,
          confirmButtonText: 'Delete',
          cancelButtonText: 'Cancel',
        }).then((r) => {
          if (r.isConfirmed) form.submit();
        });
      });
    });
  })();
</script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
