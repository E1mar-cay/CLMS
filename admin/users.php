<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/user-approval.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/student-batch-schema.php';

clms_require_roles(['admin']);
clms_user_approval_ensure_schema($pdo);
clms_ensure_users_student_batch_column($pdo);

/**
 * @param array<string, mixed> $src
 * @return array{searchQuery: string, pendingOnly: bool, filterRole: string, filterAccount: string, filterBatch: string}
 */
function clms_users_normalize_filters_from_array(array $src, array $allowedRoles): array
{
    $searchQuery = trim((string) ($src['q'] ?? ''));
    $pendingRaw = $src['pending'] ?? null;
    $pendingOnly = $pendingRaw === '1' || $pendingRaw === 1 || $pendingRaw === true;
    $filterRole = trim((string) ($src['role'] ?? ''));
    if ($filterRole !== '' && !in_array($filterRole, $allowedRoles, true)) {
        $filterRole = '';
    }
    $filterAccount = trim((string) ($src['account'] ?? ''));
    if ($filterAccount !== '' && !in_array($filterAccount, ['active', 'disabled'], true)) {
        $filterAccount = '';
    }
    $filterBatchRaw = (string) ($src['batch'] ?? '');
    $filterBatch = '';
    if ($filterBatchRaw === '__none__') {
        $filterBatch = '__none__';
    } else {
        $filterBatchTrim = trim($filterBatchRaw);
        if ($filterBatchTrim !== '') {
            $batchLen = function_exists('mb_strlen')
                ? mb_strlen($filterBatchTrim, 'UTF-8')
                : strlen($filterBatchTrim);
            if ($batchLen <= 80) {
                $filterBatch = $filterBatchTrim;
            }
        }
    }

    return [
        'searchQuery' => $searchQuery,
        'pendingOnly' => $pendingOnly,
        'filterRole' => $filterRole,
        'filterAccount' => $filterAccount,
        'filterBatch' => $filterBatch,
    ];
}

/**
 * @return array{0: string, 1: array<string, mixed>}
 */
function clms_users_build_list_where_clause(
    string $searchQuery,
    bool $pendingOnly,
    string $filterRole,
    string $filterAccount,
    string $filterBatch
): array {
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
    if ($filterRole !== '') {
        $whereSql .= ' AND u.role = :f_role';
        $params['f_role'] = $filterRole;
    }
    if ($filterAccount === 'active') {
        $whereSql .= ' AND (COALESCE(u.account_is_disabled, 0) = 0)';
    } elseif ($filterAccount === 'disabled') {
        $whereSql .= ' AND u.account_is_disabled = 1';
    }
    if ($filterBatch !== '') {
        $whereSql .= " AND u.role = 'student'";
        if ($filterBatch === '__none__') {
            $whereSql .= " AND (u.student_batch IS NULL OR TRIM(COALESCE(u.student_batch, '')) = '')";
        } else {
            $whereSql .= ' AND LOWER(TRIM(COALESCE(u.student_batch, \'\'))) = :f_batch_lc';
            $params['f_batch_lc'] = function_exists('mb_strtolower')
                ? mb_strtolower($filterBatch, 'UTF-8')
                : strtolower($filterBatch);
        }
    }

    return [$whereSql, $params];
}

$pageTitle = 'User Management | Criminology LMS';
$activeAdminPage = 'users';
$errorMessage = '';
$isAjaxRequest = !empty($_GET['ajax']);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$page = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$perPage = 15;
$offset = ($page - 1) * $perPage;

$allowedRoles = ['student', 'instructor', 'admin'];
$__listF = clms_users_normalize_filters_from_array($_GET, $allowedRoles);
$searchQuery = $__listF['searchQuery'];
$pendingOnly = $__listF['pendingOnly'];
$filterRole = $__listF['filterRole'];
$filterAccount = $__listF['filterAccount'];
$filterBatch = $__listF['filterBatch'];

/** @return array<string, string> */
$clmsUsersListMergeRedirect = static function (array $flashParams) use ($searchQuery, $pendingOnly, $filterRole, $filterAccount, $filterBatch, $page): array {
    $redirectQuery = $flashParams;
    if ($searchQuery !== '') {
        $redirectQuery['q'] = $searchQuery;
    }
    if ($pendingOnly) {
        $redirectQuery['pending'] = '1';
    }
    if ($filterRole !== '') {
        $redirectQuery['role'] = $filterRole;
    }
    if ($filterAccount !== '') {
        $redirectQuery['account'] = $filterAccount;
    }
    if ($filterBatch !== '') {
        $redirectQuery['batch'] = $filterBatch;
    }
    if ($page > 1) {
        $redirectQuery['page'] = (string) $page;
    }
    return $redirectQuery;
};
$repopulateEdit = null;
$shouldOpenEditModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'bulk_approve_users') {
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
                    throw new RuntimeException('Select at least one user to approve.');
                }

                $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
                $approveStmt = $pdo->prepare(
                    "UPDATE users
                     SET account_approval_status = 'approved',
                         account_approved_at = NOW()
                     WHERE id IN ({$placeholders})
                       AND role = 'student'
                       AND account_approval_status = 'pending'"
                );
                $approveStmt->execute($selectedIds);
                $approvedCount = (int) $approveStmt->rowCount();
                if ($approvedCount < 1) {
                    throw new RuntimeException('No pending student accounts were approved.');
                }

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect([
                    'approved' => '1',
                    'approved_count' => (string) $approvedCount,
                ])));
            }

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

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect([
                    'deleted' => '1',
                    'deleted_count' => (string) $deletedCount,
                ])));
            }

            if ($action === 'bulk_update_disable_status') {
                $disableRaw = strtolower(trim((string) ($_POST['disable_account'] ?? '')));
                if (!in_array($disableRaw, ['0', '1'], true)) {
                    throw new RuntimeException('Invalid account status.');
                }
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
                    throw new RuntimeException('Select at least one user.');
                }
                if ($disableRaw === '1' && in_array($currentUserId, $selectedIds, true)) {
                    throw new RuntimeException('You cannot disable your own account while signed in.');
                }

                $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
                if ($disableRaw === '1') {
                    $activeAdminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND account_is_disabled = 0");
                    $activeAdminCount = (int) ($activeAdminCountStmt->fetch()['c'] ?? 0);
                    $selectedActiveAdminStmt = $pdo->prepare(
                        "SELECT COUNT(*) AS c
                         FROM users
                         WHERE id IN ({$placeholders})
                           AND role = 'admin'
                           AND account_is_disabled = 0"
                    );
                    $selectedActiveAdminStmt->execute($selectedIds);
                    $selectedActiveAdminCount = (int) ($selectedActiveAdminStmt->fetch()['c'] ?? 0);
                    if (($activeAdminCount - $selectedActiveAdminCount) < 1) {
                        throw new RuntimeException('Cannot disable the last active administrator account.');
                    }
                }

                $disableAtExpr = $disableRaw === '1' ? 'NOW()' : 'NULL';
                $bulkDisableStmt = $pdo->prepare(
                    "UPDATE users
                     SET account_is_disabled = ?,
                         account_disabled_at = {$disableAtExpr}
                     WHERE id IN ({$placeholders})"
                );
                $bulkDisableStmt->execute(array_merge([(int) $disableRaw], $selectedIds));
                $affectedCount = (int) $bulkDisableStmt->rowCount();
                if ($affectedCount < 1) {
                    throw new RuntimeException('No accounts were updated.');
                }

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect([
                    'bulk_disabled_saved' => '1',
                    'bulk_disabled_count' => (string) $affectedCount,
                    'bulk_disabled_state' => $disableRaw,
                ])));
            }

            if ($action === 'bulk_set_student_batch') {
                $clearBatch = isset($_POST['clear_batch']) && (string) $_POST['clear_batch'] === '1';
                $batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
                if (!$clearBatch && $batchLabel === '') {
                    throw new RuntimeException('Choose a batch / cohort label, or click "Remove batch" to clear labels from selected students.');
                }
                if (!$clearBatch && strlen($batchLabel) > 80) {
                    throw new RuntimeException('Batch label must be 80 characters or fewer.');
                }

                $batchParam = $clearBatch ? null : $batchLabel;
                $batchScope = (string) ($_POST['batch_apply_scope'] ?? 'selection');
                $selectedIds = [];

                if ($batchScope === 'all_filtered_students') {
                    $snapRaw = (string) ($_POST['batch_filter_snapshot'] ?? '');
                    $snap = json_decode($snapRaw, true);
                    if (!is_array($snap)) {
                        throw new RuntimeException('Invalid batch filter data. Please refresh and try again.');
                    }
                    $sf = clms_users_normalize_filters_from_array([
                        'q' => $snap['q'] ?? '',
                        'pending' => !empty($snap['pending']) ? '1' : '',
                        'role' => $snap['role'] ?? '',
                        'account' => $snap['account'] ?? '',
                        'batch' => $snap['batch'] ?? '',
                    ], $allowedRoles);
                    if (in_array($sf['filterRole'], ['instructor', 'admin'], true)) {
                        throw new RuntimeException('Cannot apply batch to all students while the role filter excludes students.');
                    }
                    [$wSnap, $pSnap] = clms_users_build_list_where_clause(
                        $sf['searchQuery'],
                        $sf['pendingOnly'],
                        $sf['filterRole'],
                        $sf['filterAccount'],
                        $sf['filterBatch']
                    );
                    if (!$sf['pendingOnly'] && $sf['filterRole'] !== 'student' && $sf['filterBatch'] === '') {
                        $wSnap .= " AND u.role = 'student'";
                    }
                    $listIdsStmt = $pdo->prepare("SELECT u.id FROM users u {$wSnap}");
                    foreach ($pSnap as $k => $v) {
                        $listIdsStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
                    }
                    $listIdsStmt->execute();
                    $selectedIds = array_values(array_filter(array_map(
                        static fn ($row): int => (int) ($row['id'] ?? 0),
                        $listIdsStmt->fetchAll()
                    ), static fn (int $id): bool => $id > 0));
                    if ($selectedIds === []) {
                        throw new RuntimeException('No students match the current filters.');
                    }
                } else {
                    $selectedRaw = $_POST['selected_user_ids'] ?? '[]';
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
                        throw new RuntimeException('Select at least one student.');
                    }
                }

                $batchUpdated = 0;
                $chunkSize = 400;
                for ($off = 0; $off < count($selectedIds); $off += $chunkSize) {
                    $chunk = array_slice($selectedIds, $off, $chunkSize);
                    if ($chunk === []) {
                        break;
                    }
                    $named = [];
                    $execParams = ['sb' => $batchParam];
                    foreach ($chunk as $i => $id) {
                        $k = 'bid' . $i;
                        $named[] = ':' . $k;
                        $execParams[$k] = $id;
                    }
                    $inList = implode(', ', $named);
                    $bulkBatchStmt = $pdo->prepare(
                        "UPDATE users
                         SET student_batch = :sb
                         WHERE id IN ({$inList})
                           AND role = 'student'"
                    );
                    $bulkBatchStmt->execute($execParams);
                    $batchUpdated += (int) $bulkBatchStmt->rowCount();
                }
                if ($batchUpdated < 1) {
                    throw new RuntimeException('No student accounts were updated. Batch applies to students only (not instructors or admins).');
                }

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect([
                    'bulk_batch_saved' => '1',
                    'bulk_batch_count' => (string) $batchUpdated,
                ])));
            }

            if ($action === 'update_disable_status') {
                $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                $disableRaw = strtolower(trim((string) ($_POST['disable_account'] ?? '')));
                if ($userId === false || $userId === null || $userId <= 0) {
                    throw new RuntimeException('Invalid user.');
                }
                if (!in_array($disableRaw, ['0', '1'], true)) {
                    throw new RuntimeException('Invalid account status.');
                }
                $userId = (int) $userId;
                if ($userId === $currentUserId && $disableRaw === '1') {
                    throw new RuntimeException('You cannot disable your own account while signed in.');
                }

                $existingStmt = $pdo->prepare('SELECT id, role, account_is_disabled FROM users WHERE id = :id LIMIT 1');
                $existingStmt->execute(['id' => $userId]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    throw new RuntimeException('User not found.');
                }
                if ((string) ($existing['role'] ?? '') === 'admin' && $disableRaw === '1') {
                    $activeAdminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND account_is_disabled = 0");
                    $activeAdminCount = (int) ($activeAdminCountStmt->fetch()['c'] ?? 0);
                    if ($activeAdminCount < 2) {
                        throw new RuntimeException('Cannot disable the last active administrator account.');
                    }
                }

                $disableAt = $disableRaw === '1' ? date('Y-m-d H:i:s') : null;
                $disableStmt = $pdo->prepare(
                    'UPDATE users
                     SET account_is_disabled = :is_disabled,
                         account_disabled_at = :disabled_at
                     WHERE id = :id'
                );
                $disableStmt->execute([
                    'is_disabled' => (int) $disableRaw,
                    'disabled_at' => $disableAt,
                    'id' => $userId,
                ]);

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect(['disabled_saved' => '1'])));
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
                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect(['deleted' => '1'])));
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

                clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect(['approval_saved' => '1'])));
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
            $studentBatch = trim((string) ($_POST['student_batch'] ?? ''));
            if (strlen($studentBatch) > 80) {
                throw new RuntimeException('Batch / cohort must be 80 characters or fewer.');
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

            $batchParam = $role === 'student'
                ? ($studentBatch === '' ? null : $studentBatch)
                : null;

            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('Could not update password.');
                }
                $upd = $pdo->prepare(
                    'UPDATE users SET first_name = :fn, last_name = :ln, email = :em, role = :role, student_batch = :sb, password_hash = :ph WHERE id = :id'
                );
                $upd->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'em' => $email,
                    'role' => $role,
                    'sb' => $batchParam,
                    'ph' => $hash,
                    'id' => (int) $userId,
                ]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE users SET first_name = :fn, last_name = :ln, email = :em, role = :role, student_batch = :sb WHERE id = :id'
                );
                $upd->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'em' => $email,
                    'role' => $role,
                    'sb' => $batchParam,
                    'id' => (int) $userId,
                ]);
            }

            if ((int) $userId === $currentUserId) {
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;
            }

            clms_redirect('admin/users.php?' . http_build_query($clmsUsersListMergeRedirect(['saved' => '1'])));
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
        'student_batch' => trim((string) ($_POST['student_batch'] ?? '')),
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
} elseif (!empty($_GET['approved'])) {
    $approvedCount = (int) ($_GET['approved_count'] ?? 0);
    $flashSuccess = $approvedCount > 1
        ? $approvedCount . ' student accounts approved.'
        : 'Student account approved.';
} elseif (!empty($_GET['disabled_saved'])) {
    $flashSuccess = 'Account status updated.';
} elseif (!empty($_GET['bulk_disabled_saved'])) {
    $bulkCount = (int) ($_GET['bulk_disabled_count'] ?? 0);
    $bulkState = (string) ($_GET['bulk_disabled_state'] ?? '1');
    if ($bulkState === '0') {
        $flashSuccess = $bulkCount > 1
            ? $bulkCount . ' accounts enabled.'
            : 'Account enabled.';
    } else {
        $flashSuccess = $bulkCount > 1
            ? $bulkCount . ' accounts disabled.'
            : 'Account disabled.';
    }
} elseif (!empty($_GET['bulk_batch_saved'])) {
    $batchCount = (int) ($_GET['bulk_batch_count'] ?? 0);
    $flashSuccess = $batchCount === 1
        ? 'Batch / cohort updated for 1 student.'
        : 'Batch / cohort updated for ' . $batchCount . ' students.';
} elseif (!empty($_GET['deleted'])) {
    $deletedCount = (int) ($_GET['deleted_count'] ?? 0);
    if ($deletedCount > 1) {
        $flashSuccess = $deletedCount . ' users deleted successfully.';
    } else {
        $flashSuccess = 'User deleted successfully.';
    }
}

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

[$whereSql, $params] = clms_users_build_list_where_clause(
    $searchQuery,
    $pendingOnly,
    $filterRole,
    $filterAccount,
    $filterBatch
);

$bulkAllStudentsCount = 0;
if (!in_array($filterRole, ['instructor', 'admin'], true)) {
    $wBulkCount = $whereSql;
    $pBulkCount = $params;
    if (!$pendingOnly && $filterRole !== 'student' && $filterBatch === '') {
        $wBulkCount .= " AND u.role = 'student'";
    }
    $bulkCountStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM users u {$wBulkCount}");
    foreach ($pBulkCount as $k => $v) {
        $bulkCountStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $bulkCountStmt->execute();
    $bulkAllStudentsCount = (int) ($bulkCountStmt->fetch()['c'] ?? 0);
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
    "SELECT u.id, u.first_name, u.last_name, u.email, u.role, COALESCE(u.student_batch, '') AS student_batch, u.account_approval_status, u.account_is_disabled, u.created_at
     FROM users u
     {$whereSql}
     ORDER BY
        CASE WHEN u.role = 'student' AND u.account_approval_status = 'pending' THEN 0 ELSE 1 END ASC,
        u.role ASC,
        u.last_name ASC,
        u.first_name ASC
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
if ($filterRole !== '') {
    $queryBase['role'] = $filterRole;
}
if ($filterAccount !== '') {
    $queryBase['account'] = $filterAccount;
}
if ($filterBatch !== '') {
    $queryBase['batch'] = $filterBatch;
}
$paginationBase = $queryBase;
if ($page > 1) {
    $queryBase['page'] = $page;
}

$pendingToggleParams = $paginationBase;
if ($pendingOnly) {
    unset($pendingToggleParams['pending']);
} else {
    $pendingToggleParams['pending'] = '1';
}
$pendingToggleUrl = $clmsWebBase . '/admin/users.php' . ($pendingToggleParams !== [] ? '?' . http_build_query($pendingToggleParams) : '');

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
                  <small class="text-muted">View accounts and update names, email, role, or password. Select multiple rows to set <strong>batch / cohort</strong> for students in one step.</small>
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
                          <div class="col-12 col-md-6" id="modal_edit_batch_wrap">
                            <label class="form-label" for="modal_edit_batch_select">Batch / cohort</label>
                            <select class="form-select" id="modal_edit_batch_select" autocomplete="off">
                              <option value="">(None)</option>
<?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                              <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                              <option value="__other__">Other…</option>
                            </select>
                            <div id="modal_edit_batch_other_wrap" class="d-none mt-2">
                              <label class="form-label" for="modal_edit_batch_other">New batch name</label>
                              <input type="text" class="form-control" id="modal_edit_batch_other" maxlength="80" autocomplete="off" placeholder="e.g. 2026-A" />
                            </div>
                            <input type="hidden" name="student_batch" id="modal_edit_batch" value="" />
                            <small class="text-muted">Students only. Pick an existing cohort or choose Other to type a new label.</small>
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

              <div class="modal fade" id="bulkBatchModal" tabindex="-1" aria-labelledby="bulkBatchModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="bulkBatchModalLabel">Set batch for students</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="clmsBulkBatchForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>">
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="bulk_set_student_batch" />
                        <input type="hidden" name="selected_user_ids" id="clms_bulk_batch_user_ids" value="[]" />
                        <input type="hidden" name="clear_batch" id="bulk_batch_clear_hidden" value="0" />
                        <input type="hidden" name="batch_apply_scope" id="bulk_batch_apply_scope" value="selection" />
                        <input type="hidden" name="batch_filter_snapshot" id="bulk_batch_filter_snapshot" value="" />
                        <p class="small text-muted" id="bulkBatchSelectionSummary">No students selected.</p>
                        <div class="mb-3" id="bulk_batch_scope_wrap">
                          <div class="form-check">
                            <input class="form-check-input" type="radio" name="batch_apply_scope_ui" id="bulk_batch_scope_selection" value="selection" checked />
                            <label class="form-check-label" for="bulk_batch_scope_selection">Selected students on this page <span class="text-muted" id="bulk_batch_scope_selection_count"></span></label>
                          </div>
                          <div class="form-check <?php echo $bulkAllStudentsCount < 1 ? 'd-none' : ''; ?>" id="bulk_batch_scope_all_wrap">
                            <input class="form-check-input" type="radio" name="batch_apply_scope_ui" id="bulk_batch_scope_all" value="all_filtered_students" <?php echo $bulkAllStudentsCount < 1 ? 'disabled' : ''; ?> />
                            <label class="form-check-label" for="bulk_batch_scope_all">All students matching current filters <span class="text-muted">(<?php echo (int) $bulkAllStudentsCount; ?>)</span></label>
                          </div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label" for="bulk_batch_label_select">Batch / cohort label</label>
                          <select class="form-select" id="bulk_batch_label_select" autocomplete="off">
                            <option value="">Choose a batch…</option>
<?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                            <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                            <option value="__other__">Other…</option>
                          </select>
                          <div id="bulk_batch_label_other_wrap" class="d-none mt-2">
                            <label class="form-label" for="bulk_batch_label_other">New batch name</label>
                            <input class="form-control" type="text" id="bulk_batch_label_other" maxlength="80" autocomplete="off" placeholder="e.g. 2026-A" />
                          </div>
                          <input type="hidden" name="batch_label" id="bulk_batch_label_hidden" value="" />
                          <small class="text-muted">Instructors and admins are never updated. Use the scope above to include students on other pages when filters allow.</small>
                        </div>
                        <p class="small text-muted mb-0">To remove labels, use the button below — no need to clear the batch picker.</p>
                      </div>
                      <div class="modal-footer flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-outline-danger ms-auto" id="bulk_batch_remove_btn">Remove batch</button>
                        <button type="submit" class="btn btn-primary">Apply label</button>
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
              <form id="clmsDisableUserForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="update_disable_status" />
                <input type="hidden" name="user_id" id="clms_disable_user_id" value="" />
                <input type="hidden" name="disable_account" id="clms_disable_account" value="" />
              </form>
              <form id="clmsBulkApproveUsersForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="bulk_approve_users" />
                <input type="hidden" name="selected_user_ids" id="clms_bulk_approve_user_ids" value="[]" />
              </form>
              <form id="clmsBulkDisableUsersForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="bulk_update_disable_status" />
                <input type="hidden" name="disable_account" id="clms_bulk_disable_state" value="1" />
                <input type="hidden" name="selected_user_ids" id="clms_bulk_disable_user_ids" value="[]" />
              </form>
              <form id="clmsBulkDeleteUsersForm" method="post" action="<?php echo htmlspecialchars($usersFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="bulk_delete_users" />
                <input type="hidden" name="selected_user_ids" id="clms_bulk_delete_user_ids" value="[]" />
              </form>

              <div class="card" id="clms-users-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h5 class="mb-0">All users</h5>
                  <div class="clms-users-toolbar">
                    <button type="button" class="btn btn-sm btn-outline-info" id="clms-bulk-batch-btn" disabled>
                      Set batch…
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="clms-bulk-approve-btn" disabled>
                      Approve Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clms-bulk-disable-btn" disabled>
                      Disable Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="clms-bulk-enable-btn" disabled>
                      Enable Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clms-bulk-delete-btn" disabled>
                      Delete Selected
                    </button>
                    <a
                      href="<?php echo htmlspecialchars($pendingToggleUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      class="btn btn-sm <?php echo $pendingOnly ? 'btn-warning' : 'btn-outline-warning'; ?>">
                      <?php echo $pendingOnly ? 'Pending Only: ON' : 'Pending Only'; ?>
                      <span class="badge bg-white text-warning ms-1"><?php echo $pendingApprovalCount; ?></span>
                    </a>
                    <form
                      method="get"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/users.php', ENT_QUOTES, 'UTF-8'); ?>"
                      class="clms-users-search-form"
                      id="clms-users-search-form"
                      role="search">
                    <div class="d-flex flex-wrap gap-2 align-items-center clms-users-filters">
                      <label class="visually-hidden" for="clms-users-filter-batch">Batch</label>
                      <select class="form-select form-select-sm clms-users-filter-select" name="batch" id="clms-users-filter-batch" title="Student batch / cohort">
                        <option value=""<?php echo $filterBatch === '' ? ' selected' : ''; ?>>All batches</option>
                        <option value="__none__"<?php echo $filterBatch === '__none__' ? ' selected' : ''; ?>>Unspecified batch</option>
<?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                        <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filterBatch === $batchOpt ? ' selected' : ''; ?>><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                      </select>
                      <label class="visually-hidden" for="clms-users-filter-role">Role</label>
                      <select class="form-select form-select-sm clms-users-filter-select" name="role" id="clms-users-filter-role" title="Account role">
                        <option value=""<?php echo $filterRole === '' ? ' selected' : ''; ?>>All roles</option>
                        <option value="student"<?php echo $filterRole === 'student' ? ' selected' : ''; ?>>Students</option>
                        <option value="instructor"<?php echo $filterRole === 'instructor' ? ' selected' : ''; ?>>Instructors</option>
                        <option value="admin"<?php echo $filterRole === 'admin' ? ' selected' : ''; ?>>Admins</option>
                      </select>
                      <label class="visually-hidden" for="clms-users-filter-account">Account status</label>
                      <select class="form-select form-select-sm clms-users-filter-select" name="account" id="clms-users-filter-account" title="Sign-in allowed">
                        <option value=""<?php echo $filterAccount === '' ? ' selected' : ''; ?>>All accounts</option>
                        <option value="active"<?php echo $filterAccount === 'active' ? ' selected' : ''; ?>>Active only</option>
                        <option value="disabled"<?php echo $filterAccount === 'disabled' ? ' selected' : ''; ?>>Disabled only</option>
                      </select>
                    </div>
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
                      .clms-users-toolbar {
                        display: flex;
                        flex-wrap: wrap;
                        gap: .5rem;
                        align-items: center;
                        justify-content: flex-end;
                      }
                      .clms-users-toolbar .btn {
                        white-space: nowrap;
                      }
                      .clms-users-search-form {
                        display: flex;
                        flex-wrap: wrap;
                        gap: .5rem;
                        align-items: center;
                      }
                      .clms-users-filters {
                        flex: 0 1 auto;
                      }
                      .clms-users-filter-select {
                        width: auto;
                        min-width: 7.5rem;
                        max-width: 12rem;
                      }
                      #clms-users-filter-batch {
                        min-width: 9.5rem;
                        max-width: 14rem;
                      }
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
                      @media (max-width: 991.98px) {
                        .clms-users-toolbar {
                          width: 100%;
                          justify-content: flex-start;
                        }
                        .clms-users-toolbar > .btn,
                        .clms-users-toolbar > a {
                          flex: 1 1 calc(50% - .5rem);
                          min-width: 160px;
                          text-align: center;
                        }
                        .clms-users-search-form {
                          flex: 1 1 100%;
                          width: 100%;
                        }
                        .clms-users-search-field {
                          min-width: 0;
                          flex: 1 1 auto;
                        }
                      }
                      @media (max-width: 575.98px) {
                        .clms-users-toolbar > .btn,
                        .clms-users-toolbar > a,
                        .clms-users-search-form .btn {
                          flex: 1 1 100%;
                          width: 100%;
                        }
                        .clms-users-search-form {
                          flex-direction: column;
                          align-items: stretch;
                        }
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
                  const bulkApproveForm = document.getElementById('clmsBulkApproveUsersForm');
                  const bulkApproveIdsInput = document.getElementById('clms_bulk_approve_user_ids');
                  const bulkApproveBtn = document.getElementById('clms-bulk-approve-btn');
                  const bulkDisableForm = document.getElementById('clmsBulkDisableUsersForm');
                  const bulkDisableIdsInput = document.getElementById('clms_bulk_disable_user_ids');
                  const bulkDisableStateInput = document.getElementById('clms_bulk_disable_state');
                  const bulkDisableBtn = document.getElementById('clms-bulk-disable-btn');
                  const bulkEnableBtn = document.getElementById('clms-bulk-enable-btn');
                  const bulkDeleteForm = document.getElementById('clmsBulkDeleteUsersForm');
                  const bulkDeleteIdsInput = document.getElementById('clms_bulk_delete_user_ids');
                  const bulkDeleteBtn = document.getElementById('clms-bulk-delete-btn');
                  const bulkBatchBtn = document.getElementById('clms-bulk-batch-btn');
                  const bulkBatchModalEl = document.getElementById('bulkBatchModal');
                  const bulkBatchForm = document.getElementById('clmsBulkBatchForm');
                  const bulkBatchIdsInput = document.getElementById('clms_bulk_batch_user_ids');
                  const bulkBatchLabelSelect = document.getElementById('bulk_batch_label_select');
                  const bulkBatchLabelOtherWrap = document.getElementById('bulk_batch_label_other_wrap');
                  const bulkBatchLabelOther = document.getElementById('bulk_batch_label_other');
                  const bulkBatchLabelHidden = document.getElementById('bulk_batch_label_hidden');
                  const bulkBatchApplyScope = document.getElementById('bulk_batch_apply_scope');
                  const bulkBatchFilterSnapshot = document.getElementById('bulk_batch_filter_snapshot');
                  const bulkBatchScopeSelection = document.getElementById('bulk_batch_scope_selection');
                  const bulkBatchScopeAll = document.getElementById('bulk_batch_scope_all');
                  const bulkBatchScopeSelectionCount = document.getElementById('bulk_batch_scope_selection_count');
                  const bulkBatchScopeAllWrap = document.getElementById('bulk_batch_scope_all_wrap');
                  const bulkBatchClearHidden = document.getElementById('bulk_batch_clear_hidden');
                  const bulkBatchRemoveBtn = document.getElementById('bulk_batch_remove_btn');
                  const bulkBatchSummaryEl = document.getElementById('bulkBatchSelectionSummary');
                  const bulkAllStudentsMatchCountInitial = <?php echo (int) $bulkAllStudentsCount; ?>;
                  const approvalForm = document.getElementById('clmsApproveUserForm');
                  const approvalUserIdInput = document.getElementById('clms_approval_user_id');
                  const approvalStatusInput = document.getElementById('clms_approval_status');
                  const disableForm = document.getElementById('clmsDisableUserForm');
                  const disableUserIdInput = document.getElementById('clms_disable_user_id');
                  const disableAccountInput = document.getElementById('clms_disable_account');
                  const usersForm = document.getElementById('clms-users-search-form');
                  const usersInput = document.getElementById('clms-users-search-input');
                  const usersPendingInput = document.getElementById('clms-users-pending-input');
                  const usersRoleSelect = document.getElementById('clms-users-filter-role');
                  const usersAccountSelect = document.getElementById('clms-users-filter-account');
                  const usersBatchSelect = document.getElementById('clms-users-filter-batch');
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
                  const submitDisableAction = (btn) => {
                    if (!btn || btn.disabled || !disableForm || !disableUserIdInput || !disableAccountInput) return;
                    const id = btn.getAttribute('data-user-id');
                    const disableValue = btn.getAttribute('data-disable-account');
                    if (!id || !disableValue) return;
                    disableUserIdInput.value = id;
                    disableAccountInput.value = disableValue;
                    disableForm.submit();
                  };

                  const getSelectedIds = () => {
                    if (!usersPartial) return [];
                    return [...usersPartial.querySelectorAll('.clms-user-select:checked')]
                      .map((cb) => parseInt(cb.value, 10))
                      .filter((id) => Number.isInteger(id) && id > 0);
                  };

                  const getStudentRowCheckboxes = () => {
                    if (!usersPartial) return [];
                    return [...usersPartial.querySelectorAll('tr[data-user-role="student"] .clms-user-select:not(:disabled)')];
                  };

                  const getSelectedStudentIdsForBatch = () => {
                    return getStudentRowCheckboxes()
                      .filter((cb) => cb.checked)
                      .map((cb) => parseInt(cb.value, 10))
                      .filter((id) => Number.isInteger(id) && id > 0);
                  };

                  const readBulkAllStudentsCount = () => {
                    const el = document.getElementById('clms-bulk-all-students-count');
                    if (el && el.getAttribute('data-count') !== null) {
                      const n = parseInt(el.getAttribute('data-count'), 10);
                      return Number.isFinite(n) ? n : 0;
                    }
                    return bulkAllStudentsMatchCountInitial;
                  };

                  const buildBatchFilterSnapshotJson = () =>
                    JSON.stringify({
                      q: usersInput ? usersInput.value.trim() : '',
                      pending: !!(usersPendingInput && usersPendingInput.value === '1'),
                      role: usersRoleSelect ? usersRoleSelect.value : '',
                      account: usersAccountSelect ? usersAccountSelect.value : '',
                      batch: usersBatchSelect ? usersBatchSelect.value : ''
                    });

                  const syncBulkSelectionUi = () => {
                    if (!usersPartial) return;
                    const selectedIds = getSelectedIds();
                    const studentCbs = getStudentRowCheckboxes();
                    const studentSelected = getSelectedStudentIdsForBatch();
                    const header = usersPartial.querySelector('.clms-user-select-all');
                    if (header) {
                      const allStudentsOnPage =
                        studentCbs.length > 0 && studentCbs.every((cb) => cb.checked);
                      const someStudentOnPage = studentCbs.some((cb) => cb.checked);
                      header.checked = allStudentsOnPage;
                      header.indeterminate = someStudentOnPage && !allStudentsOnPage;
                    }
                    const meta = usersPartial.querySelector('#clms-users-selection-meta');
                    if (meta) {
                      meta.textContent =
                        selectedIds.length +
                        ' selected' +
                        (studentSelected.length > 0 ? ' (' + studentSelected.length + ' student' + (studentSelected.length === 1 ? '' : 's') + ' for batch)' : '');
                    }
                    if (bulkDeleteBtn) {
                      bulkDeleteBtn.disabled = selectedIds.length === 0;
                      bulkDeleteBtn.textContent = selectedIds.length > 0
                        ? 'Delete Selected (' + selectedIds.length + ')'
                        : 'Delete Selected';
                    }
                    if (bulkApproveBtn) {
                      bulkApproveBtn.disabled = selectedIds.length === 0;
                      bulkApproveBtn.textContent = selectedIds.length > 0
                        ? 'Approve Selected (' + selectedIds.length + ')'
                        : 'Approve Selected';
                    }
                    if (bulkDisableBtn) {
                      bulkDisableBtn.disabled = selectedIds.length === 0;
                      bulkDisableBtn.textContent = selectedIds.length > 0
                        ? 'Disable Selected (' + selectedIds.length + ')'
                        : 'Disable Selected';
                    }
                    if (bulkEnableBtn) {
                      bulkEnableBtn.disabled = selectedIds.length === 0;
                      bulkEnableBtn.textContent = selectedIds.length > 0
                        ? 'Enable Selected (' + selectedIds.length + ')'
                        : 'Enable Selected';
                    }
                    if (bulkBatchBtn) {
                      const allN = readBulkAllStudentsCount();
                      bulkBatchBtn.disabled = studentSelected.length === 0 && allN === 0;
                      bulkBatchBtn.textContent =
                        studentSelected.length > 0
                          ? 'Set batch… (' + studentSelected.length + ')'
                          : allN > 0
                            ? 'Set batch… (' + allN + ' matching)'
                            : 'Set batch…';
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
                    const batchSelect = document.getElementById('modal_edit_batch_select');
                    const batchOtherWrap = document.getElementById('modal_edit_batch_other_wrap');
                    const batchOther = document.getElementById('modal_edit_batch_other');
                    const batchHidden = document.getElementById('modal_edit_batch');
                    const batchWrap = document.getElementById('modal_edit_batch_wrap');
                    const pwEl = document.getElementById('modal_edit_pw');
                    if (idEl && fnEl && lnEl && emEl && roleEl && pwEl) {
                      const applyEditBatchValue = (raw) => {
                        const v = (raw || '').trim();
                        if (!batchSelect || !batchHidden) return;
                        let matched = false;
                        for (let i = 0; i < batchSelect.options.length; i++) {
                          const opt = batchSelect.options[i];
                          if (opt.value !== '__other__' && opt.value === v) {
                            batchSelect.selectedIndex = i;
                            matched = true;
                            break;
                          }
                        }
                        if (!matched) {
                          batchSelect.value = '__other__';
                          if (batchOther) batchOther.value = v;
                        } else if (batchOther) batchOther.value = '';
                        if (batchOtherWrap) {
                          batchOtherWrap.classList.toggle('d-none', batchSelect.value !== '__other__');
                        }
                        batchHidden.value = batchSelect.value === '__other__' ? (batchOther ? batchOther.value.trim() : '') : batchSelect.value;
                      };

                      const syncEditBatchHidden = () => {
                        if (!batchSelect || !batchHidden) return;
                        batchHidden.value =
                          batchSelect.value === '__other__' ? (batchOther ? batchOther.value.trim() : '') : batchSelect.value;
                      };

                      const syncBatchVisibility = () => {
                        if (!batchWrap) return;
                        const show = roleEl.value === 'student';
                        batchWrap.classList.toggle('d-none', !show);
                      };
                      const fillFromDataset = (btn) => {
                        if (!btn) return;
                        idEl.value = btn.getAttribute('data-edit-id') || '';
                        fnEl.value = btn.getAttribute('data-edit-fn') || '';
                        lnEl.value = btn.getAttribute('data-edit-ln') || '';
                        emEl.value = btn.getAttribute('data-edit-em') || '';
                        const role = btn.getAttribute('data-edit-role') || 'student';
                        roleEl.value = role;
                        applyEditBatchValue(btn.getAttribute('data-edit-batch') || '');
                        pwEl.value = '';
                        syncBatchVisibility();
                      };

                      roleEl.addEventListener('change', syncBatchVisibility);
                      if (batchSelect) {
                        batchSelect.addEventListener('change', () => {
                          if (batchOtherWrap) {
                            batchOtherWrap.classList.toggle('d-none', batchSelect.value !== '__other__');
                          }
                          if (batchSelect.value !== '__other__' && batchOther) batchOther.value = '';
                          syncEditBatchHidden();
                        });
                      }
                      if (batchOther) {
                        batchOther.addEventListener('input', syncEditBatchHidden);
                      }
                      editForm.addEventListener('submit', () => {
                        syncEditBatchHidden();
                      });

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
                        applyEditBatchValue(repop.student_batch || '');
                        pwEl.value = '';
                        syncBatchVisibility();
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
                    let currentListPage = <?php echo max(1, (int) $page); ?>;

                    const readListFilters = () => ({
                      q: usersInput.value.trim(),
                      pending: !!(usersPendingInput && usersPendingInput.value === '1'),
                      role: usersRoleSelect ? usersRoleSelect.value : '',
                      account: usersAccountSelect ? usersAccountSelect.value : '',
                      batch: usersBatchSelect ? usersBatchSelect.value : ''
                    });

                    const buildUrl = (ajax, data) => {
                      const params = new URLSearchParams();
                      if (data.q) params.set('q', data.q);
                      if (data.pending) params.set('pending', '1');
                      if (data.role) params.set('role', data.role);
                      if (data.account) params.set('account', data.account);
                      if (data.batch) params.set('batch', data.batch);
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

                    const fetchAndSwap = async (patch = {}) => {
                      if (inFlight) inFlight.abort();
                      const controller = new AbortController();
                      inFlight = controller;
                      const s = readListFilters();
                      const data = {
                        q: patch.q !== undefined ? patch.q : s.q,
                        pending: patch.pending !== undefined ? patch.pending : s.pending,
                        role: patch.role !== undefined ? patch.role : s.role,
                        account: patch.account !== undefined ? patch.account : s.account,
                        batch: patch.batch !== undefined ? patch.batch : s.batch,
                        page: patch.page !== undefined ? patch.page : currentListPage
                      };
                      setBusy(true);
                      try {
                        const response = await fetch(buildUrl(true, data), {
                          signal: controller.signal,
                          credentials: 'same-origin'
                        });
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        const html = await response.text();
                        usersPartial.innerHTML = html;
                        currentListPage = data.page;
                        history.replaceState(null, '', buildUrl(false, data));
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
                      if (usersClearBtn) usersClearBtn.classList.toggle('d-none', q === '');
                      debounceId = setTimeout(() => fetchAndSwap({ q, page: 1 }), 250);
                    });

                    usersForm.addEventListener('submit', (event) => {
                      event.preventDefault();
                      clearTimeout(debounceId);
                      fetchAndSwap({ page: 1 });
                    });

                    if (usersRoleSelect) {
                      usersRoleSelect.addEventListener('change', () => {
                        if (usersRoleSelect.value !== 'student' && usersBatchSelect && usersBatchSelect.value) {
                          usersBatchSelect.value = '';
                        }
                        clearTimeout(debounceId);
                        fetchAndSwap({ page: 1 });
                      });
                    }
                    if (usersAccountSelect) {
                      usersAccountSelect.addEventListener('change', () => {
                        clearTimeout(debounceId);
                        fetchAndSwap({ page: 1 });
                      });
                    }
                    if (usersBatchSelect) {
                      usersBatchSelect.addEventListener('change', () => {
                        if (usersBatchSelect.value && usersRoleSelect && usersRoleSelect.value && usersRoleSelect.value !== 'student') {
                          usersRoleSelect.value = 'student';
                        }
                        clearTimeout(debounceId);
                        fetchAndSwap({ page: 1 });
                      });
                    }

                    if (usersClearBtn) {
                      usersClearBtn.addEventListener('click', () => {
                        usersInput.value = '';
                        usersClearBtn.classList.add('d-none');
                        clearTimeout(debounceId);
                        fetchAndSwap({ q: '', page: 1 });
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
                  if (bulkApproveBtn) {
                    bulkApproveBtn.addEventListener('click', () => {
                      const selectedIds = getSelectedIds();
                      if (selectedIds.length === 0) return;
                      if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') return;
                      ClmsNotify.confirm({
                        icon: 'question',
                        title: 'Approve selected accounts?',
                        text: 'Approve pending student accounts in the selected rows.',
                        confirmButtonText: 'Approve selected',
                        cancelButtonText: 'Cancel',
                      }).then((r) => {
                        if (!r.isConfirmed || !bulkApproveForm || !bulkApproveIdsInput) return;
                        bulkApproveIdsInput.value = JSON.stringify(selectedIds);
                        bulkApproveForm.submit();
                      });
                    });
                  }
                  if (bulkDisableBtn) {
                    bulkDisableBtn.addEventListener('click', () => {
                      const selectedIds = getSelectedIds();
                      if (selectedIds.length === 0) return;
                      if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') return;
                      ClmsNotify.confirm({
                        icon: 'warning',
                        title: 'Disable selected accounts?',
                        text: 'Selected users will no longer be able to sign in until re-enabled.',
                        danger: true,
                        confirmButtonText: 'Disable selected',
                        cancelButtonText: 'Cancel',
                      }).then((r) => {
                        if (!r.isConfirmed || !bulkDisableForm || !bulkDisableIdsInput || !bulkDisableStateInput) return;
                        bulkDisableStateInput.value = '1';
                        bulkDisableIdsInput.value = JSON.stringify(selectedIds);
                        bulkDisableForm.submit();
                      });
                    });
                  }
                  if (bulkEnableBtn) {
                    bulkEnableBtn.addEventListener('click', () => {
                      const selectedIds = getSelectedIds();
                      if (selectedIds.length === 0) return;
                      if (typeof ClmsNotify === 'undefined' || typeof Swal === 'undefined') return;
                      ClmsNotify.confirm({
                        icon: 'question',
                        title: 'Enable selected accounts?',
                        text: 'Selected users will be allowed to sign in again.',
                        confirmButtonText: 'Enable selected',
                        cancelButtonText: 'Cancel',
                      }).then((r) => {
                        if (!r.isConfirmed || !bulkDisableForm || !bulkDisableIdsInput || !bulkDisableStateInput) return;
                        bulkDisableStateInput.value = '0';
                        bulkDisableIdsInput.value = JSON.stringify(selectedIds);
                        bulkDisableForm.submit();
                      });
                    });
                  }

                  if (bulkBatchBtn && bulkBatchModalEl && bulkBatchForm && bulkBatchIdsInput) {
                    const syncBulkBatchLabelHidden = () => {
                      if (!bulkBatchLabelSelect || !bulkBatchLabelHidden) return;
                      bulkBatchLabelHidden.value =
                        bulkBatchLabelSelect.value === '__other__'
                          ? (bulkBatchLabelOther ? bulkBatchLabelOther.value.trim() : '')
                          : bulkBatchLabelSelect.value;
                    };

                    const refreshBulkBatchScopeUi = () => {
                      const studentIds = getSelectedStudentIdsForBatch();
                      const allN = readBulkAllStudentsCount();
                      if (bulkBatchScopeSelectionCount) {
                        bulkBatchScopeSelectionCount.textContent =
                          studentIds.length > 0 ? '(' + studentIds.length + ' on this page)' : '(none on this page)';
                      }
                      if (bulkBatchScopeAllWrap && bulkBatchScopeAll) {
                        bulkBatchScopeAllWrap.classList.toggle('d-none', allN < 1);
                        bulkBatchScopeAll.disabled = allN < 1;
                      }
                      if (bulkBatchScopeSelection && bulkBatchScopeAll) {
                        if (studentIds.length === 0 && allN > 0) {
                          bulkBatchScopeAll.checked = true;
                        } else {
                          bulkBatchScopeSelection.checked = true;
                        }
                      }
                    };

                    if (bulkBatchLabelSelect) {
                      bulkBatchLabelSelect.addEventListener('change', () => {
                        if (bulkBatchLabelOtherWrap) {
                          bulkBatchLabelOtherWrap.classList.toggle('d-none', bulkBatchLabelSelect.value !== '__other__');
                        }
                        if (bulkBatchLabelSelect.value !== '__other__' && bulkBatchLabelOther) {
                          bulkBatchLabelOther.value = '';
                        }
                        syncBulkBatchLabelHidden();
                      });
                    }
                    if (bulkBatchLabelOther) {
                      bulkBatchLabelOther.addEventListener('input', syncBulkBatchLabelHidden);
                    }

                    bulkBatchBtn.addEventListener('click', () => {
                      const studentIds = getSelectedStudentIdsForBatch();
                      const allN = readBulkAllStudentsCount();
                      if (studentIds.length === 0 && allN === 0) return;
                      bulkBatchIdsInput.value = JSON.stringify(studentIds);
                      if (bulkBatchClearHidden) bulkBatchClearHidden.value = '0';
                      if (bulkBatchFilterSnapshot) bulkBatchFilterSnapshot.value = buildBatchFilterSnapshotJson();
                      if (bulkBatchLabelSelect) bulkBatchLabelSelect.selectedIndex = 0;
                      if (bulkBatchLabelOtherWrap) bulkBatchLabelOtherWrap.classList.add('d-none');
                      if (bulkBatchLabelOther) bulkBatchLabelOther.value = '';
                      syncBulkBatchLabelHidden();
                      refreshBulkBatchScopeUi();
                      if (bulkBatchSummaryEl) {
                        bulkBatchSummaryEl.textContent =
                          studentIds.length > 0
                            ? 'Use the scope below to apply only to checked students on this page, or to every student matching your current filters (other pages included). Instructors and admins are never changed.'
                            : 'No students are checked on this page. Applying to all students matching your current filters (' +
                              allN +
                              '). Instructors and admins are never changed.';
                      }
                      if (window.bootstrap && bootstrap.Modal) {
                        bootstrap.Modal.getOrCreateInstance(bulkBatchModalEl).show();
                      }
                    });
                    bulkBatchForm.addEventListener('submit', (event) => {
                      const useAllFiltered =
                        bulkBatchScopeAll && bulkBatchScopeAll.checked && !bulkBatchScopeAll.disabled;
                      if (bulkBatchApplyScope) {
                        bulkBatchApplyScope.value = useAllFiltered ? 'all_filtered_students' : 'selection';
                      }
                      if (bulkBatchFilterSnapshot) {
                        bulkBatchFilterSnapshot.value = buildBatchFilterSnapshotJson();
                      }
                      syncBulkBatchLabelHidden();
                      const clearOn = bulkBatchClearHidden && bulkBatchClearHidden.value === '1';
                      if (!clearOn) {
                        const label = bulkBatchLabelHidden ? bulkBatchLabelHidden.value.trim() : '';
                        if (label === '') {
                          event.preventDefault();
                          if (typeof ClmsNotify !== 'undefined') {
                            ClmsNotify.error('Choose a batch label, type a new one under Other, or click "Remove batch".', 'Batch required');
                          } else {
                            alert('Choose a batch label or Remove batch.');
                          }
                          return;
                        }
                        if (label.length > 80) {
                          event.preventDefault();
                          if (typeof ClmsNotify !== 'undefined') {
                            ClmsNotify.error('Batch label must be 80 characters or fewer.', 'Too long');
                          }
                          return;
                        }
                      }
                      if (useAllFiltered) {
                        bulkBatchIdsInput.value = JSON.stringify([]);
                      } else {
                        const studentIds = getSelectedStudentIdsForBatch();
                        if (studentIds.length === 0) {
                          event.preventDefault();
                          if (typeof ClmsNotify !== 'undefined') {
                            ClmsNotify.error('Select at least one student on this page, or choose "all matching filters".', 'Nothing selected');
                          }
                          return;
                        }
                        bulkBatchIdsInput.value = JSON.stringify(studentIds);
                      }
                    });
                    if (bulkBatchRemoveBtn && bulkBatchClearHidden) {
                      bulkBatchRemoveBtn.addEventListener('click', () => {
                        const studentIds = getSelectedStudentIdsForBatch();
                        const allN = readBulkAllStudentsCount();
                        if (studentIds.length === 0 && allN === 0) return;
                        if (bulkBatchApplyScope) {
                          bulkBatchApplyScope.value =
                            bulkBatchScopeAll && bulkBatchScopeAll.checked && !bulkBatchScopeAll.disabled
                              ? 'all_filtered_students'
                              : 'selection';
                        }
                        if (bulkBatchFilterSnapshot) {
                          bulkBatchFilterSnapshot.value = buildBatchFilterSnapshotJson();
                        }
                        bulkBatchIdsInput.value =
                          bulkBatchApplyScope && bulkBatchApplyScope.value === 'all_filtered_students'
                            ? JSON.stringify([])
                            : JSON.stringify(studentIds);
                        if (bulkBatchLabelSelect) bulkBatchLabelSelect.selectedIndex = 0;
                        if (bulkBatchLabelOtherWrap) bulkBatchLabelOtherWrap.classList.add('d-none');
                        if (bulkBatchLabelOther) bulkBatchLabelOther.value = '';
                        if (bulkBatchLabelHidden) bulkBatchLabelHidden.value = '';
                        bulkBatchClearHidden.value = '1';
                        bulkBatchForm.submit();
                      });
                    }
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
                      const disableBtn = event.target.closest('.clms-disable-user-btn');
                      if (disableBtn) {
                        event.preventDefault();
                        submitDisableAction(disableBtn);
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
                      fetchAndSwap({ page: nextPage });
                    });

                    usersPartial.addEventListener('change', (event) => {
                      const master = event.target.closest('.clms-user-select-all');
                      if (master) {
                        getStudentRowCheckboxes().forEach((cb) => {
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
