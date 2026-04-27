<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Announcements | Criminology LMS';
$activeAdminPage = 'announcements';
$errorMessage = '';
$successMessage = '';

try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active_created (is_active, created_at),
            CONSTRAINT fk_announcement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );
} catch (Throwable $e) {
    error_log('Announcements table init failed: ' . $e->getMessage());
}

$editId = (int) filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
$formTitle = '';
$formBody = '';
$formIsActive = 1;
$formAction = 'create';
$formAnnouncementId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            if ($action === 'create' || $action === 'update') {
                $titleInput = trim((string) ($_POST['title'] ?? ''));
                $bodyInput = trim((string) ($_POST['body'] ?? ''));
                $isActiveInput = isset($_POST['is_active']) ? 1 : 0;
                $formTitle = $titleInput;
                $formBody = $bodyInput;
                $formIsActive = $isActiveInput;
                $formAction = $action;
                $formAnnouncementId = (int) filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);

                if ($titleInput === '' || mb_strlen($titleInput) > 255) {
                    throw new RuntimeException('Title is required and must be 255 characters or fewer.');
                }
                if ($bodyInput === '') {
                    throw new RuntimeException('Body is required.');
                }

                if ($action === 'create') {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO announcements (title, body, is_active, created_by)
                         VALUES (:title, :body, :is_active, :created_by)'
                    );
                    $insertStmt->execute([
                        'title' => $titleInput,
                        'body' => $bodyInput,
                        'is_active' => $isActiveInput,
                        'created_by' => $currentUserId > 0 ? $currentUserId : null,
                    ]);
                    $successMessage = 'Announcement created successfully.';
                    $formTitle = '';
                    $formBody = '';
                    $formIsActive = 1;
                    $formAction = 'create';
                    $formAnnouncementId = 0;
                } else {
                    $announcementId = (int) filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
                    if ($announcementId <= 0) {
                        throw new RuntimeException('Invalid announcement id.');
                    }

                    $updateStmt = $pdo->prepare(
                        'UPDATE announcements
                         SET title = :title,
                             body = :body,
                             is_active = :is_active
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        'title' => $titleInput,
                        'body' => $bodyInput,
                        'is_active' => $isActiveInput,
                        'id' => $announcementId,
                    ]);
                    $successMessage = 'Announcement updated successfully.';
                    clms_redirect('admin/announcements.php?flash=updated');
                }
            } elseif ($action === 'delete') {
                $announcementId = (int) filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
                if ($announcementId <= 0) {
                    throw new RuntimeException('Invalid announcement id.');
                }
                $deleteStmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
                $deleteStmt->execute(['id' => $announcementId]);
                $successMessage = 'Announcement deleted successfully.';
            } elseif ($action === 'toggle') {
                $announcementId = (int) filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
                if ($announcementId <= 0) {
                    throw new RuntimeException('Invalid announcement id.');
                }
                $toggleStmt = $pdo->prepare(
                    'UPDATE announcements SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id'
                );
                $toggleStmt->execute(['id' => $announcementId]);
                $successMessage = 'Announcement status updated.';
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Operation failed. Please try again.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

$flashCode = (string) ($_GET['flash'] ?? '');
if ($successMessage === '' && $flashCode !== '') {
    $flashMap = [
        'updated' => 'Announcement updated successfully.',
    ];
    if (isset($flashMap[$flashCode])) {
        $successMessage = $flashMap[$flashCode];
    }
}

if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, title, body, is_active FROM announcements WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch();
    if ($editRow) {
        $formTitle = (string) $editRow['title'];
        $formBody = (string) $editRow['body'];
        $formIsActive = (int) $editRow['is_active'];
        $formAction = 'update';
        $formAnnouncementId = (int) $editRow['id'];
    } else {
        $editId = 0;
    }
}

$listStmt = $pdo->query(
    "SELECT a.id, a.title, a.body, a.is_active, a.created_at, a.updated_at,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS created_by_name
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC"
);
$announcements = $listStmt->fetchAll();

require_once __DIR__ . '/includes/layout-top.php';
?>
<?php
$isEditMode = $editId > 0;
// Auto-open the form modal when editing a row or when a submission
// failed server-side validation — preserves the half-typed content.
$shouldAutoOpenModal = $isEditMode || ($errorMessage !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Announcements</h4>
                  <small class="text-muted">Post global alerts that appear on every student's dashboard.</small>
                </div>
                <button
                  id="newAnnouncementBtn"
                  type="button"
                  class="btn btn-primary btn-sm"
                  data-bs-toggle="modal"
                  data-bs-target="#announcementFormModal">
                  <i class="bx bx-plus me-1"></i>New Announcement
                </button>
              </div>

              <div class="card">
                <h5 class="card-header">All Announcements</h5>
                <div class="card-body">
<?php if ($announcements === []) : ?>
                  <p class="mb-0 text-muted">No announcements posted yet. Click <strong>New Announcement</strong> to create the first one.</p>
<?php else : ?>
                  <div class="d-flex flex-column gap-3">
<?php foreach ($announcements as $announcement) : ?>
<?php $isActive = (int) $announcement['is_active'] === 1; ?>
                    <div
                      class="border rounded p-3"
                      data-search-item
                      data-search-text="<?php echo htmlspecialchars(((string) $announcement['title']) . ' ' . ((string) $announcement['body']) . ' ' . ($isActive ? 'active' : 'inactive'), ENT_QUOTES, 'UTF-8'); ?>">
                      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                          <h6 class="mb-1"><?php echo htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8'); ?></h6>
                          <small class="text-muted">
                            <?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) $announcement['created_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
<?php if (trim((string) ($announcement['created_by_name'] ?? '')) !== '') : ?>
                            · by <?php echo htmlspecialchars((string) $announcement['created_by_name'], ENT_QUOTES, 'UTF-8'); ?>
<?php endif; ?>
                          </small>
                        </div>
                        <span class="badge <?php echo $isActive ? 'bg-label-success' : 'bg-label-secondary'; ?>">
                          <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                      </div>
                      <p class="mb-3" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string) $announcement['body'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <div class="d-flex flex-wrap gap-2">
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-primary js-edit-announcement-btn"
                          data-announcement-id="<?php echo (int) $announcement['id']; ?>"
                          data-announcement-title="<?php echo htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8'); ?>"
                          data-announcement-body="<?php echo htmlspecialchars((string) $announcement['body'], ENT_QUOTES, 'UTF-8'); ?>"
                          data-announcement-active="<?php echo $isActive ? '1' : '0'; ?>">
                          <i class="bx bx-edit-alt"></i> Edit
                        </button>
                        <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/announcements.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                          <input type="hidden" name="action" value="toggle" />
                          <input type="hidden" name="announcement_id" value="<?php echo (int) $announcement['id']; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bx <?php echo $isActive ? 'bx-hide' : 'bx-show'; ?>"></i>
                            <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                          </button>
                        </form>
                        <form
                          method="post"
                          action="<?php echo htmlspecialchars($clmsWebBase . '/admin/announcements.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="d-inline js-delete-announcement-form"
                          data-announcement-title="<?php echo htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                          <input type="hidden" name="action" value="delete" />
                          <input type="hidden" name="announcement_id" value="<?php echo (int) $announcement['id']; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bx bx-trash"></i> Delete
                          </button>
                        </form>
                      </div>
                    </div>
<?php endforeach; ?>
                  </div>
<?php endif; ?>
                </div>
              </div>

              <!-- Announcement create / edit modal -->
              <div
                class="modal fade"
                id="announcementFormModal"
                tabindex="-1"
                aria-labelledby="announcementFormModalLabel"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <form
                      method="post"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/announcements.php', ENT_QUOTES, 'UTF-8'); ?>"
                      id="announcementForm"
                      data-mode="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                      <div class="modal-header">
                        <h5 class="modal-title" id="announcementFormModalLabel">
                          <i class="bx <?php echo $formAction === 'update' ? 'bx-edit-alt' : 'bx-broadcast'; ?> me-1"></i>
                          <?php echo $formAction === 'update' ? 'Edit Announcement' : 'New Announcement'; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" id="announcementActionInput" value="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="announcement_id" id="announcementIdInput" value="<?php echo (int) $formAnnouncementId; ?>" />
                        <div class="mb-3">
                          <label for="title" class="form-label">Title</label>
                          <input
                            type="text"
                            id="title"
                            name="title"
                            maxlength="255"
                            class="form-control"
                            placeholder="e.g., Criminology Board Exam Schedule"
                            value="<?php echo htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8'); ?>"
                            required />
                        </div>
                        <div class="mb-3">
                          <label for="body" class="form-label">Message</label>
                          <textarea
                            id="body"
                            name="body"
                            rows="6"
                            class="form-control"
                            placeholder="Write the announcement details students should see..."
                            required><?php echo htmlspecialchars($formBody, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="form-check form-switch">
                          <input
                            class="form-check-input"
                            type="checkbox"
                            id="is_active"
                            name="is_active"
                            value="1"
                            <?php echo $formIsActive === 1 ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="is_active">Active (visible to students)</label>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                          <i class="bx <?php echo $formAction === 'update' ? 'bx-save' : 'bx-send'; ?> me-1"></i>
                          <span id="announcementSubmitText"><?php echo $formAction === 'update' ? 'Update Announcement' : 'Post Announcement'; ?></span>
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  if (typeof Swal === 'undefined') return;

                  ClmsNotify.fromFlash(
                    <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                    <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                  );

                  // Re-open the form modal when editing or after a server
                  // validation error, so the admin doesn't lose context.
                  const shouldAutoOpenModal = <?php echo $shouldAutoOpenModal ? 'true' : 'false'; ?>;
                  const announcementFormModalEl = document.getElementById('announcementFormModal');
                  if (shouldAutoOpenModal && announcementFormModalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(announcementFormModalEl).show();
                  }

                  const announcementForm = document.getElementById('announcementForm');
                  const announcementModalTitle = document.getElementById('announcementFormModalLabel');
                  const announcementActionInput = document.getElementById('announcementActionInput');
                  const announcementIdInput = document.getElementById('announcementIdInput');
                  const announcementTitleInput = document.getElementById('title');
                  const announcementBodyInput = document.getElementById('body');
                  const announcementActiveInput = document.getElementById('is_active');
                  const announcementSubmitText = document.getElementById('announcementSubmitText');
                  const newAnnouncementBtn = document.getElementById('newAnnouncementBtn');
                  const editButtons = document.querySelectorAll('.js-edit-announcement-btn');

                  const setCreateMode = () => {
                    if (!announcementForm || !announcementActionInput || !announcementIdInput || !announcementModalTitle || !announcementSubmitText || !announcementActiveInput) return;
                    announcementForm.dataset.mode = 'create';
                    announcementActionInput.value = 'create';
                    announcementIdInput.value = '0';
                    announcementModalTitle.innerHTML = '<i class="bx bx-broadcast me-1"></i>New Announcement';
                    announcementSubmitText.textContent = 'Post Announcement';
                    if (announcementTitleInput) announcementTitleInput.value = '';
                    if (announcementBodyInput) announcementBodyInput.value = '';
                    announcementActiveInput.checked = true;
                  };

                  const setEditMode = (data) => {
                    if (!announcementForm || !announcementActionInput || !announcementIdInput || !announcementModalTitle || !announcementSubmitText || !announcementActiveInput) return;
                    announcementForm.dataset.mode = 'update';
                    announcementActionInput.value = 'update';
                    announcementIdInput.value = data.id;
                    announcementModalTitle.innerHTML = '<i class="bx bx-edit-alt me-1"></i>Edit Announcement';
                    announcementSubmitText.textContent = 'Update Announcement';
                    if (announcementTitleInput) announcementTitleInput.value = data.title;
                    if (announcementBodyInput) announcementBodyInput.value = data.body;
                    announcementActiveInput.checked = data.isActive;
                  };

                  if (newAnnouncementBtn) {
                    newAnnouncementBtn.addEventListener('click', () => {
                      setCreateMode();
                    });
                  }
                  editButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                      const data = {
                        id: btn.dataset.announcementId || '0',
                        title: btn.dataset.announcementTitle || '',
                        body: btn.dataset.announcementBody || '',
                        isActive: btn.dataset.announcementActive === '1',
                      };
                      setEditMode(data);
                      if (announcementFormModalEl && window.bootstrap && bootstrap.Modal) {
                        bootstrap.Modal.getOrCreateInstance(announcementFormModalEl).show();
                      }
                    });
                  });

                  if (announcementForm) {
                    announcementForm.addEventListener('submit', (event) => {
                      if (announcementForm.dataset.confirmed === '1') return;
                      event.preventDefault();
                      const mode = announcementForm.dataset.mode;
                      Swal.fire({
                        title: mode === 'update' ? 'Update announcement?' : 'Post this announcement?',
                        text: mode === 'update'
                          ? 'The updated message will be visible to all students immediately.'
                          : 'It will be published to every student dashboard immediately.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: mode === 'update' ? 'Yes, update' : 'Yes, post it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          announcementForm.dataset.confirmed = '1';
                          announcementForm.submit();
                        }
                      });
                    });
                  }

                  document.querySelectorAll('.js-delete-announcement-form').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                      if (form.dataset.confirmed === '1') return;
                      event.preventDefault();
                      const title = form.dataset.announcementTitle || 'this announcement';
                      Swal.fire({
                        title: 'Delete "' + title + '"?',
                        text: 'This announcement will be removed from all student dashboards. This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          form.dataset.confirmed = '1';
                          form.submit();
                        }
                      });
                    });
                  });
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
