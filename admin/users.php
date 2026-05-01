<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/user-approval.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);
clms_user_approval_ensure_schema($pdo);

$pageTitle = 'User Management | Criminology LMS';
$activeAdminPage = 'users';
$errorMessage = '';
$isAjaxRequest = !empty($_GET['ajax']);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$pendingOnly = isset($_GET['pending']) && $_GET['pending'] === '1';
$page = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$perPage = 15;
$offset = ($page - 1) * $perPage;

$allowedRoles = ['student', 'instructor', 'admin'];
$repopulateEdit = null;
$shouldOpenEditModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'bulk_delete_users') {
                $selectedRaw = $_POST['selected_user_ids'] ?? '[]';
                $selectedIds = [];
                if (is_string($selectedRaw)) {
                    $decoded = json_decode($selectedRaw, true);
                    if (is_array($decoded)) {
                        $selectedIds = $decoded;
                    }
                } elseif (is_array($selectedRaw)) {
                    $selectedIds = $selectedRaw;
                }
                $selectedIds = array_values(array_unique(array_filter(array_map(
                    static fn ($v): int => (int) $v,
                    $selectedIds
                ), static fn (int $id): bool => $id > 0)));

                if ($selectedIds === []) {
                    throw new RuntimeException('Select at least one user to delete.');
                }
                if (in_array($currentUserId, $selectedIds, true)) {
                    throw new RuntimeException('You cannot bulk-delete your own account while signed in.');
                }

                $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
                $fetchStmt = $pdo->prepare("SELECT id, role FROM users WHERE id IN ({$placeholders})");
                $fetchStmt->execute($selectedIds);
                $rows = $fetchStmt->fetchAll();
                if (count($rows) !== count($selectedIds)) {
                    throw new RuntimeException('One or more selected users no longer exist. Please refresh and try again.');
                }

                $adminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
                $adminCount = (int) ($adminCountStmt->fetch()['c'] ?? 0);
                $selectedAdminCount = 0;
                foreach ($rows as $row) {
                    if ((string) ($row['role'] ?? '') === 'admin') {
                        $selectedAdminCount++;
                    }
                }
                if (($adminCount - $selectedAdminCount) < 1) {
                    throw new RuntimeException('Cannot delete the last administrator account.');
                }

                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
                $deleteStmt->execute($selectedIds);
                $deletedCount = (int) $deleteStmt->rowCount();
                if ($deletedCount < 1) {
                    throw new RuntimeException('No users were deleted.');
                }

                $redirectQuery = ['deleted' => '1', 'deleted_count' => (string) $deletedCount];
                if ($searchQuery !== '') {
                    $redirectQuery['q'] = $searchQuery;
                }
                if ($pendingOnly) {
                    $redirectQuery['pending'] = '1';
                }
                if ($page > 1) {
                    $redirectQuery['page'] = (string) $page;
                }
                clms_redirect('admin/users.php?' . http_build_query($redirectQuery));
            }

            if ($action === 'delete_user') {
                $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                if ($userId === false || $userId === null || $userId <= 0) {
                    throw new RuntimeException('Invalid user.');
                }
                $userId = (int) $userId;
                if ($userId === $currentUserId) {
                    throw new RuntimeException('You cannot delete your own account while signed in.');
                }
                $existingStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
                $existingStmt->execute(['id' => $userId]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    throw new RuntimeException('User not found.');
                }
                if ((string) $existing['role'] === 'admin') {
                    $adminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
                    $adminCount = (int) ($adminCountStmt->fetch()['c'] ?? 0);
                    if ($adminCount < 2) {
                        throw new RuntimeException('Cannot delete the last administrator account.');
                    }
                }
                $delStmt = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
                $delStmt->execute(['id' => $userId]);
                if ($delStmt->rowCount() < 1) {
                    throw new RuntimeException('User could not be deleted.');
                }
                $redirectQuery = ['deleted' => '1'];
                if ($searchQuery !== '') {
                    $redirectQuery['q'] = $searchQuery;
                }
                if ($pendingOnly) {
                    $redirectQuery['pending'] = '1';
                }
                if ($page > 1) {
                    $redirectQuery['page'] = (string) $page;
                }
                clms_redirect('admin/users.php?' . http_build_query($redirectQuery));
            }

            if ($action === 'update_approval_status') {
                $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                $approvalStatusRaw = strtolower(trim((string) ($_POST['approval_status'] ?? '')));
                if ($userId === false || $userId === null || $userId <= 0) {
                    throw new RuntimeException('Invalid user.');
                }
                if (!in_array($approvalStatusRaw, ['pending', 'approved', 'rejected'], true)) {
                    throw new RuntimeException('Invalid approval status.');
                }
                $approvalStatus = $approvalStatusRaw;

                $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
                $userStmt->execute(['id' => (int) $userId]);
                $existing = $userStmt->fetch();
                if (!$existing) {
                    throw new RuntimeException('User not found.');
                }
                if ((string) ($existing['role'] ?? '') !== 'student') {
                    throw new RuntimeException('Only student accounts require approval.');
                }

                $approveAt = $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null;
                $updApproval = $pdo->prepare(
                    'UPDATE users
                     SET account_approval_status = :approval_status,
                         account_approved_at = :account_approved_at
                     WHERE id = :id'
                );
                $updApproval->execute([
                    'approval_status' => $approvalStatus,
                    'account_approved_at' => $approveAt,
                    'id' => (int) $userId,
                ]);

                $redirectQuery = ['approval_saved' => '1'];
                if ($searchQuery !== '') {
                    $redirectQuery['q'] = $searchQuery;
                }
                if ($pendingOnly) {
                    $redirectQuery['pending'] = '1';
                }
                if ($page > 1) {
                    $redirectQuery['page'] = (string) $page;
                }
                clms_redirect('admin/users.php?' . http_build_query($redirectQuery));
            }

            if ($action !== 'update_user') {
                throw new RuntimeException('Unknown action.');
            }
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = trim((string) ($_POST['role'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');

            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('First and last name are required.');
            }
            if (strlen($firstName) > 50 || strlen($lastName) > 50) {
                throw new RuntimeException('Name fields must be 50 characters or fewer.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if (strlen($email) > 100) {
                throw new RuntimeException('Email must be 100 characters or fewer.');
            }
            if (!in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Invalid role.');
            }
            if ($newPassword !== '' && strlen($newPassword) < 8) {
                throw new RuntimeException('New password must be at least 8 characters or left blank.');
            }

            $existingStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
            $existingStmt->execute(['id' => (int) $userId]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new RuntimeException('User not found.');
            }
            $oldRole = (string) $existing['role'];

            if ($oldRole === 'admin' && $role !== 'admin') {
                $adminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
                $adminCount = (int) ($adminCountStmt->fetch()['c'] ?? 0);
                if ($adminCount < 2) {
                    throw new RuntimeException('Cannot remove the last administrator account.');
                }
            }

            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $dupStmt->execute(['email' => $email, 'id' => (int) $userId]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('Another account already uses this email.');
            }

            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('Could not update password.');
                }
                $upd = $pdo->prepare(
                    'UPDATE users SET first_name = :fn, last_name = :ln, email = :em, role = :role, password_hash = :ph WHERE id = :id'
                );
                $upd->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'em' => $email,
                    'role' => $role,
                    'ph' => $hash,
                    'id' => (int) $userId,
                ]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE users SET first_name = :fn, last_name = :ln, email = :em, role = :role WHERE id = :id'
                );
                $upd->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'em' => $email,
                    'role' => $role,
                    'id' => (int) $userId,
                ]);
            }

            if ((int) $userId === $currentUserId) {
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;
            }

            $redirectQuery = ['saved' => '1'];
            if ($searchQuery !== '') {
                $redirectQuery['q'] = $searchQuery;
            }
            if ($pendingOnly) {
                $redirectQuery['pending'] = '1';
            }
            if ($page > 1) {
                $redirectQuery['page'] = (string) $page;
            }
            clms_redirect('admin/users.php?' . http_build_query($redirectQuery));
        } catch (PDOException $e) {
            if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1451) {
                $errorMessage = 'This user cannot be deleted because related records still reference the account.';
            } else {
                error_log($e->getMessage());
                $errorMessage = 'Could not complete the request. Please try again.';
            }
        } catch (RuntimeException $e) {
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $errorMessage = 'Could not save changes. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_user' && $errorMessage !== '') {
    $shouldOpenEditModal = true;
    $failUid = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $repopulateEdit = [
        'id' => $failUid !== false && $failUid !== null ? (int) $failUid : 0,
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'role' => trim((string) ($_POST['role'] ?? 'student')),
    ];
    if (!in_array($repopulateEdit['role'], $allowedRoles, true)) {
        $repopulateEdit['role'] = 'student';
    }
}

$flashSuccess = '';
if (!empty($_GET['saved'])) {
    $flashSuccess = 'User updated successfully.';
} elseif (!empty($_GET['approval_saved'])) {
    $flashSuccess = 'Student approval status updated.';
} elseif (!empty($_GET['deleted'])) {
    $deletedCount = (int) ($_GET['deleted_count'] ?? 0);
    if ($deletedCount > 1) {
        $flashSuccess = $deletedCount . ' users deleted successfully.';
    } else {
        $flashSuccess = 'User deleted successfully.';
    }
}

$whereSql = 'WHERE 1=1';
$params = [];
if ($searchQuery !== '') {
    $whereSql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR CONCAT(u.first_name, " ", u.last_name) LIKE :s4)';
    $like = '%' . $searchQuery . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}
if ($pendingOnly) {
    $whereSql .= " AND u.role = 'student' AND u.account_approval_status = :pending_status";
    $params['pending_status'] = 'pending';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total_rows FROM users u {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['total_rows'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listStmt = $pdo->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.account_approval_status, u.created_at
     FROM users u
     {$whereSql}
     ORDER BY u.role ASC, u.last_name ASC, u.first_name ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$userRows = $listStmt->fetchAll();

$totalAdminCount = (int) ($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch()['c'] ?? 0);
$pendingApprovalCount = (int) ($pdo->query(
    "SELECT COUNT(*) AS c
     FROM users
     WHERE role = 'student' AND account_approval_status = 'pending'"
)->fetch()['c'] ?? 0);

$queryBase = [];
if ($searchQuery !== '') {
    $queryBase['q'] = $searchQuery;
}
if ($pendingOnly) {
    $queryBase['pending'] = '1';
}
$paginationBase = $queryBase;
if ($page > 1) {
    $queryBase['page'] = $page;
}

$formActionQuery = $queryBase;
$usersFormAction = $clmsWebBase . '/admin/users.php' . ($formActionQuery !== [] ? '?' . http_build_query($formActionQuery) : '');

if ($isAjaxRequest) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    require __DIR__ . '/includes/users-list-partial.php';
    return;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">User management</h4>
                  <small class="text-muted">View accounts and update names, email, role, or password.</small>
                </div>
              </div>

              <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="editUserModalLabel">Edit user</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editUserForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="update_user" />
                        <input type="hidden" id="modal_edit_user_id" name="user_id" value="" />
                        <div class="row g-3">
                          <div class="col-md-6">
                            <label class="form-label" for="modal_edit_fn">First name</label>
                            <input class="form-control" id="modal_edit_fn" name="first_name" maxlength="50" required />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="modal_edit_ln">Last name</label>
                            <input class="form-control" id="modal_edit_ln" name="last_name" maxlength="50" required />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="modal_edit_em">Email</label>
                            <input class="form-control" id="modal_edit_em" name="email" type="email" maxlength="100" required />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="modal_edit_role">Role</label>
                            <select class="form-select" id="modal_edit_role" name="role" required>
<?php foreach ($allowedRoles as $r) : ?>
                              <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="modal_edit_pw">New password</label>
                            <input class="form-control" id="modal_edit_pw" name="new_password" type="password" minlength="8" autocomplete="new-password" />
                            <small class="text-muted">Leave blank to keep the current password.</small>
                          </div>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <form id="clmsDeleteUserForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="delete_user" />
                <input type="hidden" name="user_id" id="clms_delete_user_id" value="" />
              </form>
              <form id="clmsApproveUserForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="update_approval_status" />
                <input type="hidden" name="user_id" id="clms_approval_user_id" value="" />
                <input type="hidden" name="approval_status" id="clms_approval_status" value="" />
              </form>
              <form id="clmsBulkDeleteUsersForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="bulk_delete_users" />
                <input type="hidden" name="selected_user_ids" id="clms_bulk_delete_user_ids" value="[]" />
              </form>

              <div class="card" id="clms-users-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h5 class="mb-0">All users</h5>
                  <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clms-bulk-delete-btn" disabled>
                      Delete Selected
                    </button>
                    <a
                      href="<?php echo htmlspecialchars($clmsWebBase . '/admin/users.php' . ($searchQuery !== '' ? '?q=' . rawurlencode($searchQuery) : ''), ENT_QUOTES, 'UTF-8'); ?>"
                      class="btn btn-sm <?php echo $pendingOnly ? 'btn-warning' : 'btn-outline-warning'; ?>">
                      <?php echo $pendingOnly ? 'Pending Only: ON' : 'Pending Only'; ?>
                      <span class="badge bg-white text-warning ms-1"><?php echo $pendingApprovalCount; ?></span>
                    </a>
                    <form
                      method="get"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/users.php', ENT_QUOTES, 'UTF-8'); ?>"
                      class="d-flex gap-2 align-items-center"
                      id="clms-users-search-form"
                      role="search">
                    <div class="clms-users-search-field">
                      <i class="bx bx-search clms-users-search-icon"></i>
                      <input
                        type="search"
                        class="form-control form-control-sm clms-users-search-input"
                        id="clms-users-search-input"
                        name="q"
                        value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Search by name or email..."
                        autocomplete="off" />
                      <input type="hidden" name="pending" value="<?php echo $pendingOnly ? '1' : '0'; ?>" id="clms-users-pending-input" />
                      <span
                        class="spinner-border spinner-border-sm text-primary clms-users-search-spinner d-none"
                        id="clms-users-search-spinner"
                        role="status"
                        aria-hidden="true"></span>
                    </div>
                    <style>
                      .clms-users-search-field {
                        position: relative;
                        min-width: 260px;
                      }
                      .clms-users-search-icon {
                        position: absolute;
                        left: .75rem;
                        top: 50%;
                        transform: translateY(-50%);
                        color: #8592a3;
                        font-size: 1rem;
                        pointer-events: none;
                        z-index: 2;
                      }
                      .clms-users-search-field .clms-users-search-input.form-control {
                        padding: .35rem 2.25rem !important;
                        position: relative;
                        z-index: 1;
                      }
                      .clms-users-search-input::-webkit-search-cancel-button {
                        -webkit-appearance: none;
                      }
                      .clms-users-search-spinner {
                        position: absolute;
                        right: .6rem;
                        top: 50%;
                        transform: translateY(-50%);
                      }
                    </style>
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm <?php echo $searchQuery === '' ? 'd-none' : ''; ?>"
                      id="clms-users-search-clear">
                      Clear
                    </button>
                    <noscript>
                      <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    </noscript>
                    </form>
                  </div>
                </div>
                <div class="card-body position-relative p-0" id="clms-users-partial" aria-live="polite" aria-busy="false">
<?php require __DIR__ . '/includes/users-list-partial.php'; ?>
                </div>
              </div>

              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  if (typeof ClmsNotify !== 'undefined') {
                    ClmsNotify.fromFlash(
                      <?php echo json_encode($flashSuccess, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                      <?php echo json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                    );
                  }

                  const deleteForm = document.getElementById('clmsDeleteUserForm');
                  const deleteIdInput = document.getElementById('clms_delete_user_id');
                  const bulkDeleteForm = document.getElementById('clmsBulkDeleteUsersForm');
                  const bulkDeleteIdsInput = document.getElementById('clms_bulk_delete_user_ids');
                  const bulkDeleteBtn = document.getElementById('clms-bulk-delete-btn');
                  const approvalForm = document.getElementById('clmsApproveUserForm');
                  const approvalUserIdInput = document.getElementById('clms_approval_user_id');
                  const approvalStatusInput = document.getElementById('clms_approval_status');
                  const usersForm = document.getElementById('clms-users-search-form');
                  const usersInput = document.getElementById('clms-users-search-input');
                  const usersPendingInput = document.getElementById('clms-users-pending-input');
                  const usersSpinner = document.getElementById('clms-users-search-spinner');
                  const usersClearBtn = document.getElementById('clms-users-search-clear');
                  const usersPartial = document.getElementById('clms-users-partial');

                  const confirmDeleteButton = (btn) => {
                    if (!btn || btn.disabled) return;
                    if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') return;
                    const id = btn.getAttribute('data-user-id');
                    const name = btn.getAttribute('data-display-name') || 'this user';
                    const email = btn.getAttribute('data-user-email') || '';
                    const detail = email ? name + ' (' + email + ')' : name;
                    ClmsNotify.confirm({
                      icon: 'warning',
                      title: 'Delete user?',
                      text: 'Remove ' + detail + ' permanently. Related progress and activity may be deleted with the account.',
                      danger: true,
                      confirmButtonText: 'Delete',
                      cancelButtonText: 'Cancel',
                    }).then((r) => {
                      if (!r.isConfirmed || !deleteForm || !deleteIdInput || !id) return;
                      deleteIdInput.value = id;
                      deleteForm.submit();
                    });
                  };
                  const submitApprovalAction = (btn) => {
                    if (!btn || btn.disabled || !approvalForm || !approvalUserIdInput || !approvalStatusInput) return;
                    const id = btn.getAttribute('data-user-id');
                    const status = btn.getAttribute('data-approval-status');
                    if (!id || !status) return;
                    approvalUserIdInput.value = id;
                    approvalStatusInput.value = status;
                    approvalForm.submit();
                  };

                  const getSelectedIds = () => {
                    if (!usersPartial) return [];
                    return [...usersPartial.querySelectorAll('.clms-user-select:checked')]
                      .map((cb) => parseInt(cb.value, 10))
                      .filter((id) => Number.isInteger(id) && id > 0);
                  };

                  const syncBulkSelectionUi = () => {
                    if (!usersPartial) return;
                    const selectedIds = getSelectedIds();
                    const selectable = [...usersPartial.querySelectorAll('.clms-user-select:not(:disabled)')];
                    const header = usersPartial.querySelector('.clms-user-select-all');
                    if (header) {
                      header.checked = selectable.length > 0 && selectedIds.length === selectable.length;
                      header.indeterminate = selectedIds.length > 0 && selectedIds.length < selectable.length;
                    }
                    const meta = usersPartial.querySelector('#clms-users-selection-meta');
                    if (meta) {
                      meta.textContent = selectedIds.length + ' selected';
                    }
                    if (bulkDeleteBtn) {
                      bulkDeleteBtn.disabled = selectedIds.length === 0;
                      bulkDeleteBtn.textContent = selectedIds.length > 0
                        ? 'Delete Selected (' + selectedIds.length + ')'
                        : 'Delete Selected';
                    }
                  };

                  const modalEl = document.getElementById('editUserModal');
                  const editForm = document.getElementById('editUserForm');
                  if (modalEl && editForm) {
                    const idEl = document.getElementById('modal_edit_user_id');
                    const fnEl = document.getElementById('modal_edit_fn');
                    const lnEl = document.getElementById('modal_edit_ln');
                    const emEl = document.getElementById('modal_edit_em');
                    const roleEl = document.getElementById('modal_edit_role');
                    const pwEl = document.getElementById('modal_edit_pw');
                    if (idEl && fnEl && lnEl && emEl && roleEl && pwEl) {
                      const fillFromDataset = (btn) => {
                        if (!btn) return;
                        idEl.value = btn.getAttribute('data-edit-id') || '';
                        fnEl.value = btn.getAttribute('data-edit-fn') || '';
                        lnEl.value = btn.getAttribute('data-edit-ln') || '';
                        emEl.value = btn.getAttribute('data-edit-em') || '';
                        const role = btn.getAttribute('data-edit-role') || 'student';
                        roleEl.value = role;
                        pwEl.value = '';
                      };

                      modalEl.addEventListener('show.bs.modal', (ev) => {
                        const btn = ev.relatedTarget;
                        if (btn && btn.classList && btn.classList.contains('clms-edit-user-btn')) {
                          fillFromDataset(btn);
                        }
                      });

                      const repop = <?php echo json_encode($repopulateEdit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                      const shouldOpen = <?php echo $shouldOpenEditModal ? 'true' : 'false'; ?>;
                      if (shouldOpen && repop && repop.id) {
                        idEl.value = String(repop.id);
                        fnEl.value = repop.first_name || '';
                        lnEl.value = repop.last_name || '';
                        emEl.value = repop.email || '';
                        roleEl.value = repop.role || 'student';
                        pwEl.value = '';
                        if (window.bootstrap && bootstrap.Modal) {
                          bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        }
                      }
                    }
                  }

                  if (usersForm && usersInput && usersPartial) {
                    const endpoint = <?php echo json_encode($clmsWebBase . '/admin/users.php', JSON_UNESCAPED_SLASHES); ?>;
                    let debounceId = null;
                    let inFlight = null;

                    const buildUrl = (ajax, data) => {
                      const params = new URLSearchParams();
                      if (data.q) params.set('q', data.q);
                      if (data.pending) params.set('pending', '1');
                      if (data.page && data.page > 1) params.set('page', String(data.page));
                      if (ajax) params.set('ajax', '1');
                      const qs = params.toString();
                      return qs ? `${endpoint}?${qs}` : endpoint;
                    };

                    const setBusy = (busy) => {
                      usersPartial.setAttribute('aria-busy', busy ? 'true' : 'false');
                      usersPartial.style.opacity = busy ? '0.55' : '';
                      if (usersSpinner) usersSpinner.classList.toggle('d-none', !busy);
                    };

                    const fetchAndSwap = async ({ q, page, pending }) => {
                      if (inFlight) inFlight.abort();
                      const controller = new AbortController();
                      inFlight = controller;
                      setBusy(true);
                      try {
                        const response = await fetch(buildUrl(true, { q, page, pending }), {
                          signal: controller.signal,
                          credentials: 'same-origin'
                        });
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        const html = await response.text();
                        usersPartial.innerHTML = html;
                        history.replaceState(null, '', buildUrl(false, { q, page, pending }));
                        syncBulkSelectionUi();
                      } catch (err) {
                        if (err.name !== 'AbortError') {
                          console.error('Users search failed:', err);
                          usersPartial.innerHTML =
                            '<p class="text-danger mb-0 px-3 py-3"><i class="bx bx-error-circle me-1"></i>Could not load results. Please try again.</p>';
                        }
                      } finally {
                        if (inFlight === controller) inFlight = null;
                        setBusy(false);
                      }
                    };

                    usersInput.addEventListener('input', () => {
                      clearTimeout(debounceId);
                      const q = usersInput.value.trim();
                      const pending = usersPendingInput && usersPendingInput.value === '1';
                      if (usersClearBtn) usersClearBtn.classList.toggle('d-none', q === '');
                      debounceId = setTimeout(() => fetchAndSwap({ q, page: 1, pending }), 250);
                    });

                    usersForm.addEventListener('submit', (event) => {
                      event.preventDefault();
                      clearTimeout(debounceId);
                      fetchAndSwap({
                        q: usersInput.value.trim(),
                        page: 1,
                        pending: usersPendingInput && usersPendingInput.value === '1'
                      });
                    });

                    if (usersClearBtn) {
                      usersClearBtn.addEventListener('click', () => {
                        usersInput.value = '';
                        usersClearBtn.classList.add('d-none');
                        clearTimeout(debounceId);
                        fetchAndSwap({
                          q: '',
                          page: 1,
                          pending: usersPendingInput && usersPendingInput.value === '1'
                        });
                        usersInput.focus();
                      });
                    }

                  if (bulkDeleteBtn) {
                    bulkDeleteBtn.addEventListener('click', () => {
                      const selectedIds = getSelectedIds();
                      if (selectedIds.length === 0) return;
                      if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') return;
                      ClmsNotify.confirm({
                        icon: 'warning',
                        title: 'Delete selected users?',
                        text: 'Permanently delete ' + selectedIds.length + ' selected account(s). This cannot be undone.',
                        danger: true,
                        confirmButtonText: 'Delete selected',
                        cancelButtonText: 'Cancel',
                      }).then((r) => {
                        if (!r.isConfirmed || !bulkDeleteForm || !bulkDeleteIdsInput) return;
                        bulkDeleteIdsInput.value = JSON.stringify(selectedIds);
                        bulkDeleteForm.submit();
                      });
                    });
                  }

                    usersPartial.addEventListener('click', (event) => {
                      const deleteBtn = event.target.closest('.clms-delete-user-btn');
                      if (deleteBtn) {
                        event.preventDefault();
                        confirmDeleteButton(deleteBtn);
                        return;
                      }
                      const approvalBtn = event.target.closest('.clms-approval-btn');
                      if (approvalBtn) {
                        event.preventDefault();
                        submitApprovalAction(approvalBtn);
                        return;
                      }

                      const pageLink = event.target.closest('a[data-users-page]');
                      if (!pageLink) return;
                      const item = pageLink.closest('.page-item');
                      if (item && item.classList.contains('disabled')) {
                        event.preventDefault();
                        return;
                      }
                      event.preventDefault();
                      const nextPage = parseInt(pageLink.getAttribute('data-users-page'), 10) || 1;
                      fetchAndSwap({
                        q: usersInput.value.trim(),
                        page: nextPage,
                        pending: usersPendingInput && usersPendingInput.value === '1'
                      });
                    });

                    usersPartial.addEventListener('change', (event) => {
                      const master = event.target.closest('.clms-user-select-all');
                      if (master) {
                        usersPartial.querySelectorAll('.clms-user-select:not(:disabled)').forEach((cb) => {
                          cb.checked = master.checked;
                        });
                        syncBulkSelectionUi();
                        return;
                      }
                      if (event.target.closest('.clms-user-select')) {
                        syncBulkSelectionUi();
                      }
                    });

                    syncBulkSelectionUi();
                  }
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
