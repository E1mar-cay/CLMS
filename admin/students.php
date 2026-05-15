<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/user-approval.php';
require_once dirname(__DIR__) . '/includes/student-batch-schema.php';

if (!isset($clmsWebBase)) {
  require dirname(__DIR__) . '/includes/sneat-paths.php';
}

clms_require_roles(['admin', 'instructor']);
clms_user_approval_ensure_schema($pdo);
clms_ensure_users_student_batch_column($pdo);
clms_ensure_users_archive_columns($pdo);

$pageTitle = 'Students | Criminology LMS';
$activeAdminPage = 'students';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$flashSuccess = '';
$errorMessage = '';

/**
 * Decode a `selected_user_ids` POST field, accepting both JSON-string
 * (from our JS forms) and raw array shapes. Returns a clean list of
 * positive ints with duplicates removed — the same contract used on
 * admin/users.php so the two pages share a consistent expectation.
 *
 * @return int[]
 */
$clmsStudentsDecodeSelectedIds = static function ($raw): array {
  $ids = [];
  if (is_string($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      $ids = $decoded;
    }
  } elseif (is_array($raw)) {
    $ids = $raw;
  }
  return array_values(array_unique(array_filter(
    array_map(static fn($v): int => (int) $v, $ids),
    static fn(int $id): bool => $id > 0
  )));
};

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
/*
 * Archive filter:
 *   ''         → only non-archived students (default)
 *   'archived' → only archived students
 *   'all'      → both
 */
$filterArchive = strtolower(trim((string) ($_GET['archive'] ?? '')));
if (!in_array($filterArchive, ['', 'archived', 'all'], true)) {
  $filterArchive = '';
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

/*
 * "Active only" (account) excludes disabled rows. Pending / rejected
 * registrations are often disabled until an admin acts — combining
 * both filters hid everyone. Drop the account filter in that case.
 */
if ($filterAccount === 'active' && in_array($filterApproval, ['pending', 'rejected'], true)) {
  $filterAccount = '';
}

$whereSql = "WHERE u.role = 'student'";
$params = [];
if ($searchQuery !== '') {
  $normQ = function_exists('mb_strtolower')
    ? mb_strtolower($searchQuery, 'UTF-8')
    : strtolower($searchQuery);
  $likeLower = '%' . $normQ . '%';
  $whereSql .= ' AND (
        LOWER(u.first_name) LIKE :search_first
        OR LOWER(u.last_name) LIKE :search_last
        OR LOWER(u.email) LIKE :search_email
        OR LOWER(CONCAT(u.first_name, " ", u.last_name)) LIKE :search_full
    )';
  $params['search_first'] = $likeLower;
  $params['search_last'] = $likeLower;
  $params['search_email'] = $likeLower;
  $params['search_full'] = $likeLower;
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
/*
 * Hide archived students from the default list. Admin can opt into
 * "archived only" or "all including archived" via the Archive filter.
 */
if ($filterArchive === 'archived') {
  $whereSql .= ' AND COALESCE(u.account_is_archived, 0) = 1';
} elseif ($filterArchive !== 'all') {
  $whereSql .= ' AND COALESCE(u.account_is_archived, 0) = 0';
}

/**
 * Layers flash flags on top of the current filter set so the redirect
 * back to the list preserves the admin's context.
 *
 * @return array<string, string>
 */
$clmsStudentsBuildRedirectQuery = static function (array $flashParams) use ($searchQuery, $filterAccount, $filterApproval, $filterBatch, $filterArchive, $page): array {
  $redirectQuery = $flashParams;
  if ($searchQuery !== '') {
    $redirectQuery['q'] = $searchQuery;
  }
  if ($filterAccount !== '') {
    $redirectQuery['account'] = $filterAccount;
  }
  if ($filterApproval !== '') {
    $redirectQuery['approval'] = $filterApproval;
  }
  if ($filterBatch !== '') {
    $redirectQuery['batch'] = $filterBatch;
  }
  if ($filterArchive !== '') {
    $redirectQuery['archive'] = $filterArchive;
  }
  if ($page > 1) {
    $redirectQuery['page'] = (string) $page;
  }
  return $redirectQuery;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    $errorMessage = 'Invalid request token. Please refresh and try again.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    try {
      if ($action === 'bulk_approve_students') {
        $selectedIds = $clmsStudentsDecodeSelectedIds($_POST['selected_user_ids'] ?? '[]');
        if ($selectedIds === []) {
          throw new RuntimeException('Select at least one student to approve.');
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
          throw new RuntimeException('No pending student accounts were approved. (Already approved or already rejected accounts are not changed.)');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'approved' => '1',
          'approved_count' => (string) $approvedCount,
        ])));
      }

      if ($action === 'bulk_update_disable_status') {
        $disableRaw = strtolower(trim((string) ($_POST['disable_account'] ?? '')));
        if (!in_array($disableRaw, ['0', '1'], true)) {
          throw new RuntimeException('Invalid account status.');
        }
        $selectedIds = $clmsStudentsDecodeSelectedIds($_POST['selected_user_ids'] ?? '[]');
        if ($selectedIds === []) {
          throw new RuntimeException('Select at least one student.');
        }

        $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
        $disableAtExpr = $disableRaw === '1' ? 'NOW()' : 'NULL';
        /*
                 * Scope is locked to students — even if someone slips
                 * an instructor / admin id into the form, the role
                 * filter here drops them.
                 */
        $bulkDisableStmt = $pdo->prepare(
          "UPDATE users
                        SET account_is_disabled = ?,
                            account_disabled_at = {$disableAtExpr}
                      WHERE id IN ({$placeholders})
                        AND role = 'student'"
        );
        $bulkDisableStmt->execute(array_merge([(int) $disableRaw], $selectedIds));
        $affectedCount = (int) $bulkDisableStmt->rowCount();
        if ($affectedCount < 1) {
          throw new RuntimeException('No student accounts were updated.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'bulk_disabled_saved' => '1',
          'bulk_disabled_count' => (string) $affectedCount,
          'bulk_disabled_state' => $disableRaw,
        ])));
      }

      if ($action === 'bulk_delete_students') {
        $selectedIds = $clmsStudentsDecodeSelectedIds($_POST['selected_user_ids'] ?? '[]');
        if ($selectedIds === []) {
          throw new RuntimeException('Select at least one student to delete.');
        }
        $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
        $deleteStmt = $pdo->prepare(
          "DELETE FROM users WHERE id IN ({$placeholders}) AND role = 'student'"
        );
        $deleteStmt->execute($selectedIds);
        $deletedCount = (int) $deleteStmt->rowCount();
        if ($deletedCount < 1) {
          throw new RuntimeException('No student accounts were deleted.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'deleted' => '1',
          'deleted_count' => (string) $deletedCount,
        ])));
      }

      if ($action === 'archive_batch') {
        /*
                 * Soft-delete an entire batch: every student currently
                 * carrying the supplied label gets archived. Their data
                 * (progress, attempts, certificates) is preserved; they
                 * just stop appearing in the default students list and
                 * stop showing up in cohort dropdowns until the admin
                 * restores them.
                 *
                 * To match the Batch filter semantics we also accept the
                 * synthetic `__none__` label, which targets students
                 * with no batch set.
                 */
        $batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
        if ($batchLabel === '') {
          throw new RuntimeException('Pick a batch / cohort to archive.');
        }
        if ($batchLabel !== '__none__' && mb_strlen($batchLabel) > 80) {
          throw new RuntimeException('Batch label is too long.');
        }

        /*
                 * Archiving a batch also force-disables the affected
                 * accounts so they can't sign in while archived. We
                 * stamp `account_disabled_at` only on rows that weren't
                 * already disabled, so a later restore can decide to
                 * re-enable them cleanly without overwriting a prior
                 * disable timestamp set for unrelated reasons.
                 */
        if ($batchLabel === '__none__') {
          $archiveStmt = $pdo->prepare(
            "UPDATE users
                            SET account_is_archived = 1,
                                account_archived_at = NOW(),
                                account_disabled_at  = CASE
                                    WHEN COALESCE(account_is_disabled, 0) = 1
                                        THEN account_disabled_at
                                    ELSE NOW()
                                END,
                                account_is_disabled  = 1
                          WHERE role = 'student'
                            AND COALESCE(account_is_archived, 0) = 0
                            AND (student_batch IS NULL OR TRIM(COALESCE(student_batch, '')) = '')"
          );
          $archiveStmt->execute();
        } else {
          $archiveStmt = $pdo->prepare(
            "UPDATE users
                            SET account_is_archived = 1,
                                account_archived_at = NOW(),
                                account_disabled_at  = CASE
                                    WHEN COALESCE(account_is_disabled, 0) = 1
                                        THEN account_disabled_at
                                    ELSE NOW()
                                END,
                                account_is_disabled  = 1
                          WHERE role = 'student'
                            AND COALESCE(account_is_archived, 0) = 0
                            AND LOWER(TRIM(COALESCE(student_batch, ''))) = :lbl_lc"
          );
          $archiveStmt->execute([
            'lbl_lc' => function_exists('mb_strtolower')
              ? mb_strtolower($batchLabel, 'UTF-8')
              : strtolower($batchLabel),
          ]);
        }
        $archivedCount = (int) $archiveStmt->rowCount();
        if ($archivedCount < 1) {
          throw new RuntimeException('No active students were found in that batch.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'batch_archived' => '1',
          'batch_archived_count' => (string) $archivedCount,
          'batch_archived_label' => $batchLabel,
        ])));
      }

      if ($action === 'unarchive_student') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if ($userId === false || $userId === null || $userId <= 0) {
          throw new RuntimeException('Invalid student.');
        }
        $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => (int) $userId]);
        $existing = $userStmt->fetch();
        if (!$existing) {
          throw new RuntimeException('Student not found.');
        }
        if ((string) ($existing['role'] ?? '') !== 'student') {
          throw new RuntimeException('Only student accounts can be restored here.');
        }
        /*
                 * Restoring also re-enables the account so the student
                 * can sign in immediately. Archive force-disabled them
                 * as a pair; the restore mirrors that pairing.
                 */
        $upd = $pdo->prepare(
          'UPDATE users
                        SET account_is_archived = 0,
                            account_archived_at = NULL,
                            account_is_disabled = 0,
                            account_disabled_at = NULL
                      WHERE id = :id'
        );
        $upd->execute(['id' => (int) $userId]);
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'single_restored' => '1',
        ])));
      }

      if ($action === 'bulk_unarchive_students') {
        /*
                 * Bulk-restore previously archived students. We scope
                 * strictly to role='student' and to currently-archived
                 * rows so the action is idempotent and can't be used to
                 * touch other roles by manipulating the hidden ID list.
                 */
        $selectedIds = $clmsStudentsDecodeSelectedIds($_POST['selected_user_ids'] ?? '[]');
        if ($selectedIds === []) {
          throw new RuntimeException('Select at least one archived student to restore.');
        }
        $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
        $restoreStmt = $pdo->prepare(
          "UPDATE users
                        SET account_is_archived = 0,
                            account_archived_at = NULL,
                            account_is_disabled = 0,
                            account_disabled_at = NULL
                      WHERE id IN ({$placeholders})
                        AND role = 'student'
                        AND COALESCE(account_is_archived, 0) = 1"
        );
        $restoreStmt->execute($selectedIds);
        $restoredCount = (int) $restoreStmt->rowCount();
        if ($restoredCount < 1) {
          throw new RuntimeException('No archived student accounts matched the selection.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'bulk_unarchived' => '1',
          'bulk_unarchived_count' => (string) $restoredCount,
        ])));
      }

      if ($action === 'update_approval_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $approvalStatusRaw = strtolower(trim((string) ($_POST['approval_status'] ?? '')));
        if ($userId === false || $userId === null || $userId <= 0) {
          throw new RuntimeException('Invalid student.');
        }
        if (!in_array($approvalStatusRaw, ['pending', 'approved', 'rejected'], true)) {
          throw new RuntimeException('Invalid approval status.');
        }
        $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => (int) $userId]);
        $existing = $userStmt->fetch();
        if (!$existing) {
          throw new RuntimeException('Student not found.');
        }
        if ((string) ($existing['role'] ?? '') !== 'student') {
          throw new RuntimeException('Only student accounts can be approved here.');
        }
        $approveAt = $approvalStatusRaw === 'approved' ? date('Y-m-d H:i:s') : null;
        $upd = $pdo->prepare(
          'UPDATE users
                        SET account_approval_status = :s,
                            account_approved_at = :a
                      WHERE id = :id'
        );
        $upd->execute([
          's' => $approvalStatusRaw,
          'a' => $approveAt,
          'id' => (int) $userId,
        ]);
        $verb = $approvalStatusRaw === 'approved'
          ? 'Approved'
          : ($approvalStatusRaw === 'rejected' ? 'Rejected' : 'Reset to pending');
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'single_approval_saved' => '1',
          'single_approval_verb' => $verb,
        ])));
      }

      if ($action === 'update_disable_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $disableRaw = strtolower(trim((string) ($_POST['disable_account'] ?? '')));
        if ($userId === false || $userId === null || $userId <= 0) {
          throw new RuntimeException('Invalid student.');
        }
        if (!in_array($disableRaw, ['0', '1'], true)) {
          throw new RuntimeException('Invalid account status.');
        }
        $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => (int) $userId]);
        $existing = $userStmt->fetch();
        if (!$existing) {
          throw new RuntimeException('Student not found.');
        }
        if ((string) ($existing['role'] ?? '') !== 'student') {
          throw new RuntimeException('Only student accounts can be toggled here.');
        }
        $disableAt = $disableRaw === '1' ? date('Y-m-d H:i:s') : null;
        $upd = $pdo->prepare(
          'UPDATE users
                        SET account_is_disabled = :d,
                            account_disabled_at = :da
                      WHERE id = :id'
        );
        $upd->execute([
          'd' => (int) $disableRaw,
          'da' => $disableAt,
          'id' => (int) $userId,
        ]);
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'single_disabled_saved' => '1',
          'single_disabled_state' => $disableRaw,
        ])));
      }

      if ($action === 'delete_student') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if ($userId === false || $userId === null || $userId <= 0) {
          throw new RuntimeException('Invalid student.');
        }
        $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => (int) $userId]);
        $existing = $userStmt->fetch();
        if (!$existing) {
          throw new RuntimeException('Student not found.');
        }
        if ((string) ($existing['role'] ?? '') !== 'student') {
          throw new RuntimeException('This action only deletes student accounts.');
        }
        $del = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
        $del->execute(['id' => (int) $userId]);
        if ($del->rowCount() < 1) {
          throw new RuntimeException('Student could not be deleted.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'single_deleted' => '1',
        ])));
      }

      if ($action === 'update_student') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $studentBatch = trim((string) ($_POST['student_batch'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if ($userId === false || $userId === null || $userId <= 0) {
          throw new RuntimeException('Invalid student.');
        }
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
        if (strlen($studentBatch) > 80) {
          throw new RuntimeException('Batch / cohort must be 80 characters or fewer.');
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
          throw new RuntimeException('New password must be at least 8 characters or left blank.');
        }

        $existingStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $existingStmt->execute(['id' => (int) $userId]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
          throw new RuntimeException('Student not found.');
        }
        if ((string) ($existing['role'] ?? '') !== 'student') {
          throw new RuntimeException('Only student accounts can be edited here.');
        }

        $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
        $dupStmt->execute(['email' => $email, 'id' => (int) $userId]);
        if ($dupStmt->fetch()) {
          throw new RuntimeException('Another account already uses this email.');
        }

        $batchParam = $studentBatch === '' ? null : $studentBatch;

        if ($newPassword !== '') {
          $hash = password_hash($newPassword, PASSWORD_DEFAULT);
          if ($hash === false) {
            throw new RuntimeException('Could not update password.');
          }
          $upd = $pdo->prepare(
            'UPDATE users
                            SET first_name = :fn, last_name = :ln, email = :em,
                                student_batch = :sb, password_hash = :ph
                          WHERE id = :id'
          );
          $upd->execute([
            'fn' => $firstName,
            'ln' => $lastName,
            'em' => $email,
            'sb' => $batchParam,
            'ph' => $hash,
            'id' => (int) $userId,
          ]);
        } else {
          $upd = $pdo->prepare(
            'UPDATE users
                            SET first_name = :fn, last_name = :ln, email = :em, student_batch = :sb
                          WHERE id = :id'
          );
          $upd->execute([
            'fn' => $firstName,
            'ln' => $lastName,
            'em' => $email,
            'sb' => $batchParam,
            'id' => (int) $userId,
          ]);
        }

        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'single_saved' => '1',
        ])));
      }

      if ($action === 'bulk_set_student_batch') {
        $clearBatch = isset($_POST['clear_batch']) && (string) $_POST['clear_batch'] === '1';
        $batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
        if (!$clearBatch && $batchLabel === '') {
          throw new RuntimeException('Choose a batch / cohort label, or click "Remove batch" to clear labels.');
        }
        if (!$clearBatch && strlen($batchLabel) > 80) {
          throw new RuntimeException('Batch label must be 80 characters or fewer.');
        }

        $batchParam = $clearBatch ? null : $batchLabel;
        $batchScope = (string) ($_POST['batch_apply_scope'] ?? 'selection');
        $selectedIds = [];

        if ($batchScope === 'all_filtered_students') {
          /*
                     * "All matching filters" reuses the exact same WHERE
                     * clause as the list to pick up every student visible
                     * across pagination — never reaches beyond what the
                     * admin currently sees on screen.
                     */
          $listIdsStmt = $pdo->prepare("SELECT u.id FROM users u {$whereSql}");
          foreach ($params as $k => $v) {
            $listIdsStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
          }
          $listIdsStmt->execute();
          $selectedIds = array_values(array_filter(array_map(
            static fn($row): int => (int) ($row['id'] ?? 0),
            $listIdsStmt->fetchAll()
          ), static fn(int $id): bool => $id > 0));
          if ($selectedIds === []) {
            throw new RuntimeException('No students match the current filters.');
          }
        } else {
          $selectedIds = $clmsStudentsDecodeSelectedIds($_POST['selected_user_ids'] ?? '[]');
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
          throw new RuntimeException('No student accounts were updated.');
        }
        clms_redirect('admin/students.php?' . http_build_query($clmsStudentsBuildRedirectQuery([
          'bulk_batch_saved' => '1',
          'bulk_batch_count' => (string) $batchUpdated,
          'bulk_batch_cleared' => $clearBatch ? '1' : '0',
        ])));
      }
    } catch (Throwable $e) {
      $errorMessage = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Operation failed. Please try again.';
      if (!($e instanceof RuntimeException)) {
        error_log('admin/students bulk action: ' . $e->getMessage());
      }
    }
  }
}

/* Map GET flash flags from the redirect back into a single success message. */
if ($flashSuccess === '') {
  if (!empty($_GET['approved']) && (string) $_GET['approved'] === '1') {
    $n = (int) ($_GET['approved_count'] ?? 0);
    $flashSuccess = $n > 0
      ? "Approved {$n} student account" . ($n === 1 ? '' : 's') . '.'
      : 'Students approved.';
  } elseif (!empty($_GET['deleted']) && (string) $_GET['deleted'] === '1') {
    $n = (int) ($_GET['deleted_count'] ?? 0);
    $flashSuccess = $n > 0
      ? "Deleted {$n} student account" . ($n === 1 ? '' : 's') . '.'
      : 'Students deleted.';
  } elseif (!empty($_GET['bulk_disabled_saved']) && (string) $_GET['bulk_disabled_saved'] === '1') {
    $n = (int) ($_GET['bulk_disabled_count'] ?? 0);
    $wasDisabled = (string) ($_GET['bulk_disabled_state'] ?? '') === '1';
    $verb = $wasDisabled ? 'Disabled' : 'Enabled';
    $flashSuccess = $n > 0
      ? "{$verb} {$n} student account" . ($n === 1 ? '' : 's') . '.'
      : ($wasDisabled ? 'Students disabled.' : 'Students enabled.');
  } elseif (!empty($_GET['single_approval_saved']) && (string) $_GET['single_approval_saved'] === '1') {
    $verb = trim((string) ($_GET['single_approval_verb'] ?? 'Updated'));
    $flashSuccess = $verb . ' student account.';
  } elseif (!empty($_GET['single_disabled_saved']) && (string) $_GET['single_disabled_saved'] === '1') {
    $wasDisabled = (string) ($_GET['single_disabled_state'] ?? '') === '1';
    $flashSuccess = $wasDisabled ? 'Disabled student account.' : 'Enabled student account.';
  } elseif (!empty($_GET['single_deleted']) && (string) $_GET['single_deleted'] === '1') {
    $flashSuccess = 'Deleted student account.';
  } elseif (!empty($_GET['single_saved']) && (string) $_GET['single_saved'] === '1') {
    $flashSuccess = 'Student details saved.';
  } elseif (!empty($_GET['single_restored']) && (string) $_GET['single_restored'] === '1') {
    $flashSuccess = 'Student account restored and re-enabled for sign-in.';
  } elseif (!empty($_GET['bulk_unarchived']) && (string) $_GET['bulk_unarchived'] === '1') {
    $n = (int) ($_GET['bulk_unarchived_count'] ?? 0);
    $flashSuccess = $n > 0
      ? "Restored {$n} student account" . ($n === 1 ? '' : 's') . ' from the archive and re-enabled their sign-in.'
      : 'Students restored from the archive.';
  } elseif (!empty($_GET['batch_archived']) && (string) $_GET['batch_archived'] === '1') {
    $n = (int) ($_GET['batch_archived_count'] ?? 0);
    $lbl = trim((string) ($_GET['batch_archived_label'] ?? ''));
    $lblDisplay = $lbl === '__none__' ? '(no batch)' : ($lbl !== '' ? '"' . $lbl . '"' : 'selected');
    $flashSuccess = $n > 0
      ? "Archived batch {$lblDisplay} — {$n} student" . ($n === 1 ? '' : 's') . ' moved to archive and their sign-in disabled.'
      : "Archived batch {$lblDisplay}.";
  } elseif (!empty($_GET['bulk_batch_saved']) && (string) $_GET['bulk_batch_saved'] === '1') {
    $n = (int) ($_GET['bulk_batch_count'] ?? 0);
    $cleared = (string) ($_GET['bulk_batch_cleared'] ?? '0') === '1';
    if ($cleared) {
      $flashSuccess = $n > 0
        ? "Cleared batch label on {$n} student account" . ($n === 1 ? '' : 's') . '.'
        : 'Batch labels cleared.';
    } else {
      $flashSuccess = $n > 0
        ? "Set batch on {$n} student account" . ($n === 1 ? '' : 's') . '.'
        : 'Batch label applied.';
    }
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
        COALESCE(u.account_is_disabled, 0) AS account_is_disabled,
        COALESCE(u.account_is_archived, 0) AS account_is_archived,
        COALESCE(u.account_approval_status, 'approved') AS account_approval_status,
        COALESCE(u.student_batch, '') AS student_batch,
        COUNT(DISTINCT CASE WHEN up.is_completed = 1 THEN up.module_id END) AS modules_completed,
        COUNT(DISTINCT ea.id) AS attempts_total,
        COALESCE(AVG(CASE WHEN ea.status = 'completed' THEN ea.total_score END), 0) AS avg_score,
        COUNT(DISTINCT cert.id) AS certificates_count
     FROM users u
     LEFT JOIN user_progress up ON up.user_id = u.id
     LEFT JOIN exam_attempts ea ON ea.user_id = u.id
     LEFT JOIN certificates cert ON cert.user_id = u.id
     {$whereSql}
     GROUP BY u.id, u.first_name, u.last_name, u.email, u.created_at, u.account_is_disabled, u.account_is_archived, u.account_approval_status, u.student_batch
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

/*
 * Batch options shown in the picker dropdowns. We scope them to the
 * current archive filter so a batch that's been fully archived doesn't
 * sit dead in the default view's dropdown — it reappears when the
 * admin flips the Archive filter to "Archived only" or "All".
 */
$studentBatchFilterOptions = [];
try {
  $batchOptionsSql = "SELECT DISTINCT TRIM(student_batch) AS b
                          FROM users
                         WHERE role = 'student'
                           AND TRIM(COALESCE(student_batch, '')) <> ''";
  if ($filterArchive === 'archived') {
    $batchOptionsSql .= ' AND COALESCE(account_is_archived, 0) = 1';
  } elseif ($filterArchive !== 'all') {
    $batchOptionsSql .= ' AND COALESCE(account_is_archived, 0) = 0';
  }
  $batchOptionsSql .= ' ORDER BY b ASC';
  $sbStmt = $pdo->query($batchOptionsSql);
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
  exit;
}

if ($isListAjax) {
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-store');
  require __DIR__ . '/includes/students-list-partial.php';
  exit;
}

require_once __DIR__ . '/includes/layout-top.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
  <div>
    <h4 class="fw-bold mb-1">Students</h4>
    <small class="text-muted">Browse enrolled learners, review their progress, and run bulk actions across the cohort.</small>
  </div>
</div>

<?php
$studentsFormAction = $clmsWebBase . '/admin/students.php';
$studentsFormActionWithFilters = $studentsFormAction;
$studentsFormFilterParams = [];
if ($searchQuery !== '') {
  $studentsFormFilterParams['q'] = $searchQuery;
}
if ($filterAccount !== '') {
  $studentsFormFilterParams['account'] = $filterAccount;
}
if ($filterApproval !== '') {
  $studentsFormFilterParams['approval'] = $filterApproval;
}
if ($filterBatch !== '') {
  $studentsFormFilterParams['batch'] = $filterBatch;
}
if ($page > 1) {
  $studentsFormFilterParams['page'] = (string) $page;
}
if ($studentsFormFilterParams !== []) {
  $studentsFormActionWithFilters .= '?' . http_build_query($studentsFormFilterParams);
}
?>

<!-- Hidden forms used by the per-row action buttons.
                   Each button picks the right form and stuffs the row's
                   student id into the hidden input before submit. -->
<form id="clmsArchiveBatchForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="archive_batch" />
  <input type="hidden" name="batch_label" id="clms_archive_batch_label" value="" />
</form>
<form id="clmsUnarchiveStudentForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="unarchive_student" />
  <input type="hidden" name="user_id" id="clms_unarchive_student_id" value="" />
</form>
<form id="clmsDeleteStudentForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="delete_student" />
  <input type="hidden" name="user_id" id="clms_delete_student_id" value="" />
</form>
<form id="clmsApproveStudentForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="update_approval_status" />
  <input type="hidden" name="user_id" id="clms_approval_student_id" value="" />
  <input type="hidden" name="approval_status" id="clms_approval_status" value="" />
</form>
<form id="clmsDisableStudentForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="update_disable_status" />
  <input type="hidden" name="user_id" id="clms_disable_student_id" value="" />
  <input type="hidden" name="disable_account" id="clms_disable_account" value="" />
</form>

<!-- Edit student modal — students-only (no role / approval
                   here; those are dedicated row actions). -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editStudentModalLabel">Edit student</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editStudentForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" novalidate data-validate="true">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="update_student" />
          <input type="hidden" id="modal_edit_student_id" name="user_id" value="" />
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="modal_edit_student_fn">First name</label>
              <input class="form-control" id="modal_edit_student_fn" name="first_name" maxlength="50" required />
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_edit_student_ln">Last name</label>
              <input class="form-control" id="modal_edit_student_ln" name="last_name" maxlength="50" required />
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_edit_student_em">Email</label>
              <input class="form-control" id="modal_edit_student_em" name="email" type="email" maxlength="100" required />
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_edit_student_batch_select">Batch / cohort</label>
              <select class="form-select" id="modal_edit_student_batch_select" autocomplete="off">
                <option value="">(None)</option>
                <?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                  <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
                <option value="__other__">Other…</option>
              </select>
              <div id="modal_edit_student_batch_other_wrap" class="d-none mt-2">
                <label class="form-label" for="modal_edit_student_batch_other">New batch name</label>
                <input type="text" class="form-control" id="modal_edit_student_batch_other" maxlength="80" autocomplete="off" placeholder="e.g. 2026-A" />
              </div>
              <input type="hidden" name="student_batch" id="modal_edit_student_batch" value="" />
              <small class="text-muted">Pick an existing cohort or choose <em>Other</em> to type a new label.</small>
            </div>
            <div class="col-12">
              <label class="form-label" for="modal_edit_student_pw">New password</label>
              <input class="form-control" id="modal_edit_student_pw" name="new_password" type="password" minlength="8" autocomplete="new-password" />
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

<!-- Hidden forms posted by the bulk action buttons. The
                   currently-selected student IDs are stuffed into the
                   `selected_user_ids` hidden input as JSON right before
                   submit by the JS below. Form actions carry current
                   filters so the redirect after POST preserves them. -->
<form id="clmsBulkApproveStudentsForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="bulk_approve_students" />
  <input type="hidden" name="selected_user_ids" id="clms_bulk_approve_student_ids" value="[]" />
</form>
<form id="clmsBulkDisableStudentsForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="bulk_update_disable_status" />
  <input type="hidden" name="selected_user_ids" id="clms_bulk_disable_student_ids" value="[]" />
  <input type="hidden" name="disable_account" id="clms_bulk_disable_state" value="1" />
</form>
<form id="clmsBulkDeleteStudentsForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="bulk_delete_students" />
  <input type="hidden" name="selected_user_ids" id="clms_bulk_delete_student_ids" value="[]" />
</form>
<form id="clmsBulkUnarchiveStudentsForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>" class="d-none">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
  <input type="hidden" name="action" value="bulk_unarchive_students" />
  <input type="hidden" name="selected_user_ids" id="clms_bulk_unarchive_student_ids" value="[]" />
</form>

<!-- Bulk batch / cohort modal -->
<div class="modal fade" id="clmsStudentsBulkBatchModal" tabindex="-1" aria-labelledby="clmsStudentsBulkBatchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="clmsStudentsBulkBatchModalLabel">Set batch for students</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="clmsStudentsBulkBatchForm" method="post" action="<?php echo htmlspecialchars($studentsFormActionWithFilters, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="bulk_set_student_batch" />
          <input type="hidden" name="selected_user_ids" id="clms_students_bulk_batch_ids" value="[]" />
          <input type="hidden" name="clear_batch" id="clms_students_bulk_batch_clear" value="0" />
          <input type="hidden" name="batch_apply_scope" id="clms_students_bulk_batch_scope" value="selection" />
          <p class="small text-muted mb-2" id="clmsStudentsBulkBatchSummary">No students selected.</p>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="batch_apply_scope_ui" id="clms_students_bulk_batch_scope_selection" value="selection" checked />
              <label class="form-check-label" for="clms_students_bulk_batch_scope_selection">Selected students on this page <span class="text-muted" id="clmsStudentsBulkBatchScopeSelCount"></span></label>
            </div>
            <div class="form-check" id="clms_students_bulk_batch_scope_all_wrap">
              <input class="form-check-input" type="radio" name="batch_apply_scope_ui" id="clms_students_bulk_batch_scope_all" value="all_filtered_students" />
              <label class="form-check-label" for="clms_students_bulk_batch_scope_all">All students matching current filters <span class="text-muted">(<?php echo (int) $totalRows; ?>)</span></label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="clms_students_bulk_batch_label_select">Batch / cohort label</label>
            <select class="form-select" id="clms_students_bulk_batch_label_select" autocomplete="off">
              <option value="">Choose a batch…</option>
              <?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
                <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
              <option value="__other__">Other…</option>
            </select>
            <div id="clms_students_bulk_batch_label_other_wrap" class="d-none mt-2">
              <label class="form-label" for="clms_students_bulk_batch_label_other">New batch name</label>
              <input class="form-control" type="text" id="clms_students_bulk_batch_label_other" maxlength="80" autocomplete="off" placeholder="e.g. 2026-A" />
            </div>
            <input type="hidden" name="batch_label" id="clms_students_bulk_batch_label_hidden" value="" />
            <small class="text-muted">Use the scope above to include students on other pages of the current filter.</small>
          </div>
          <p class="small text-muted mb-0">To remove labels, click <em>Remove batch</em> below — no need to clear the picker.</p>
        </div>
        <div class="modal-footer flex-wrap gap-2">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-outline-danger ms-auto" id="clms_students_bulk_batch_remove_btn">Remove batch</button>
          <button type="submit" class="btn btn-primary">Apply label</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card" id="clms-students-card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h5 class="mb-0">All Students</h5>
    <div class="clms-students-bulk-actions d-flex flex-wrap gap-2 d-none" id="clms-students-bulk-actions" aria-hidden="true">
      <button type="button" class="btn btn-sm btn-outline-info" id="clms-students-bulk-batch-btn">
        Set batch…
      </button>
      <button type="button" class="btn btn-sm btn-outline-success" id="clms-students-bulk-approve-btn" disabled>
        Approve Selected
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="clms-students-bulk-disable-btn" disabled>
        Disable Selected
      </button>
      <button type="button" class="btn btn-sm btn-outline-success" id="clms-students-bulk-enable-btn" disabled>
        Enable Selected
      </button>
      <?php if ($filterArchive === 'archived' || $filterArchive === 'all') : ?>
        <button type="button" class="btn btn-sm btn-outline-warning" id="clms-students-bulk-unarchive-btn" disabled>
          <i class="bx bx-archive-out me-1"></i>Restore Selected
        </button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-danger" id="clms-students-bulk-delete-btn" disabled>
        Delete Selected
      </button>
    </div>
    <form
      method="get"
      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/students.php', ENT_QUOTES, 'UTF-8'); ?>"
      class="d-flex flex-wrap gap-2 align-items-center clms-students-toolbar-form"
      id="clms-students-search-form"
      role="search">
      <div class="d-flex flex-wrap gap-2 align-items-center clms-students-filters">
        <label class="visually-hidden" for="clms-students-filter-batch">Batch</label>
        <select class="form-select form-select-sm clms-students-filter-select" name="batch" id="clms-students-filter-batch" title="Batch / cohort">
          <option value="" <?php echo $filterBatch === '' ? ' selected' : ''; ?>>All batches</option>
          <option value="__none__" <?php echo $filterBatch === '__none__' ? ' selected' : ''; ?>>Unspecified batch</option>
          <?php foreach ($studentBatchFilterOptions as $batchOpt) : ?>
            <option value="<?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterBatch === $batchOpt ? ' selected' : ''; ?>><?php echo htmlspecialchars($batchOpt, ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="clms-students-filter-account">Account</label>
        <select class="form-select form-select-sm clms-students-filter-select" name="account" id="clms-students-filter-account" title="Account status">
          <option value="" <?php echo $filterAccount === '' ? ' selected' : ''; ?>>All accounts</option>
          <option value="active" <?php echo $filterAccount === 'active' ? ' selected' : ''; ?>>Active only</option>
          <option value="disabled" <?php echo $filterAccount === 'disabled' ? ' selected' : ''; ?>>Disabled only</option>
        </select>
        <label class="visually-hidden" for="clms-students-filter-approval">Approval</label>
        <select class="form-select form-select-sm clms-students-filter-select" name="approval" id="clms-students-filter-approval" title="Registration approval">
          <option value="" <?php echo $filterApproval === '' ? ' selected' : ''; ?>>All approval states</option>
          <option value="pending" <?php echo $filterApproval === 'pending' ? ' selected' : ''; ?>>Pending</option>
          <option value="approved" <?php echo $filterApproval === 'approved' ? ' selected' : ''; ?>>Approved</option>
          <option value="rejected" <?php echo $filterApproval === 'rejected' ? ' selected' : ''; ?>>Rejected</option>
        </select>
        <label class="visually-hidden" for="clms-students-filter-archive">Archive</label>
        <select class="form-select form-select-sm clms-students-filter-select" name="archive" id="clms-students-filter-archive" title="Archive state">
          <option value="" <?php echo $filterArchive === '' ? ' selected' : ''; ?>>Not archived</option>
          <option value="archived" <?php echo $filterArchive === 'archived' ? ' selected' : ''; ?>>Archived only</option>
          <option value="all" <?php echo $filterArchive === 'all' ? ' selected' : ''; ?>>All (incl. archived)</option>
        </select>
        <?php
        /*
 * "Archive batch" trigger — only meaningful when an actual batch
 * label is picked AND we're not already in the archived view (no
 * point archiving stuff that's already archived).
 */
        $canArchiveBatch = ($filterBatch !== '' && $filterArchive !== 'archived');
        ?>
        <?php if ($canArchiveBatch) : ?>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            id="clms-students-archive-batch-btn"
            data-batch-label="<?php echo htmlspecialchars($filterBatch, ENT_QUOTES, 'UTF-8'); ?>"
            title="Move every student in this batch to the archive (data preserved).">
            <i class="bx bx-archive-in me-1"></i>Archive batch
          </button>
        <?php endif; ?>
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

        /* ---- Mobile (≤ 575.98px) ----------------------
                         Stack everything in the card header into a
                         single column with full-width controls so the
                         toolbar reads top-to-bottom on a phone instead
                         of wrapping into awkward partial rows. */
        @media (max-width: 575.98px) {
          #clms-students-card>.card-header {
            flex-direction: column;
            align-items: stretch;
          }

          /* Heading sits flush left at the top. */
          #clms-students-card>.card-header>h5 {
            text-align: left;
          }

          /* Bulk-action buttons: one per row. The wrap
                           is .d-flex .flex-wrap by default, so we
                           swap to column + stretch on mobile. */
          .clms-students-bulk-actions {
            flex-direction: column;
            align-items: stretch;
            width: 100%;
          }

          .clms-students-bulk-actions .btn {
            width: 100%;
          }

          /* Filter form: column layout, then each
                           dropdown / search field stretches full width. */
          .clms-students-toolbar-form {
            flex-direction: column;
            align-items: stretch;
            width: 100%;
            justify-content: flex-start;
          }

          .clms-students-filters {
            flex-direction: column;
            align-items: stretch;
            width: 100%;
          }

          .clms-students-filter-select,
          #clms-students-filter-batch {
            width: 100%;
            max-width: none;
            min-width: 0;
          }

          .clms-students-search-field {
            width: 100%;
            min-width: 0;
          }
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

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  (() => {
    if (typeof ClmsNotify !== 'undefined') {
      ClmsNotify.fromFlash(
        <?php echo json_encode($flashSuccess, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        <?php echo json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
      );
    }
    const form = document.getElementById('clms-students-search-form');
    const input = document.getElementById('clms-students-search-input');
    const spinner = document.getElementById('clms-students-search-spinner');
    const clearBtn = document.getElementById('clms-students-search-clear');
    const partial = document.getElementById('clms-students-partial');
    const batchSel = document.getElementById('clms-students-filter-batch');
    const accountSel = document.getElementById('clms-students-filter-account');
    const approvalSel = document.getElementById('clms-students-filter-approval');
    const archiveSel = document.getElementById('clms-students-filter-archive');
    const archiveBatchBtn = document.getElementById('clms-students-archive-batch-btn');
    const archiveBatchForm = document.getElementById('clmsArchiveBatchForm');
    const archiveBatchLabelInput = document.getElementById('clms_archive_batch_label');
    const unarchiveForm = document.getElementById('clmsUnarchiveStudentForm');
    const unarchiveIdInput = document.getElementById('clms_unarchive_student_id');
    if (!form || !input || !partial) return;

    /* -------- Bulk-actions state -------- */
    const bulkWrap = document.getElementById('clms-students-bulk-actions');
    const bulkBatchBtn = document.getElementById('clms-students-bulk-batch-btn');
    const bulkApproveBtn = document.getElementById('clms-students-bulk-approve-btn');
    const bulkDisableBtn = document.getElementById('clms-students-bulk-disable-btn');
    const bulkEnableBtn = document.getElementById('clms-students-bulk-enable-btn');
    const bulkDeleteBtn = document.getElementById('clms-students-bulk-delete-btn');
    const bulkUnarchiveBtn = document.getElementById('clms-students-bulk-unarchive-btn');
    const bulkApproveForm = document.getElementById('clmsBulkApproveStudentsForm');
    const bulkApproveIdsInput = document.getElementById('clms_bulk_approve_student_ids');
    const bulkDisableForm = document.getElementById('clmsBulkDisableStudentsForm');
    const bulkDisableIdsInput = document.getElementById('clms_bulk_disable_student_ids');
    const bulkDisableStateInput = document.getElementById('clms_bulk_disable_state');
    const bulkDeleteForm = document.getElementById('clmsBulkDeleteStudentsForm');
    const bulkDeleteIdsInput = document.getElementById('clms_bulk_delete_student_ids');
    const bulkUnarchiveForm = document.getElementById('clmsBulkUnarchiveStudentsForm');
    const bulkUnarchiveIdsInput = document.getElementById('clms_bulk_unarchive_student_ids');
    const bulkBatchModalEl = document.getElementById('clmsStudentsBulkBatchModal');
    const bulkBatchForm = document.getElementById('clmsStudentsBulkBatchForm');
    const bulkBatchIdsInput = document.getElementById('clms_students_bulk_batch_ids');
    const bulkBatchClear = document.getElementById('clms_students_bulk_batch_clear');
    const bulkBatchScope = document.getElementById('clms_students_bulk_batch_scope');
    const bulkBatchScopeSel = document.getElementById('clms_students_bulk_batch_scope_selection');
    const bulkBatchScopeAll = document.getElementById('clms_students_bulk_batch_scope_all');
    const bulkBatchScopeAllWrap = document.getElementById('clms_students_bulk_batch_scope_all_wrap');
    const bulkBatchScopeSelCount = document.getElementById('clmsStudentsBulkBatchScopeSelCount');
    const bulkBatchLabelSelect = document.getElementById('clms_students_bulk_batch_label_select');
    const bulkBatchLabelOtherWrap = document.getElementById('clms_students_bulk_batch_label_other_wrap');
    const bulkBatchLabelOther = document.getElementById('clms_students_bulk_batch_label_other');
    const bulkBatchLabelHidden = document.getElementById('clms_students_bulk_batch_label_hidden');
    const bulkBatchRemoveBtn = document.getElementById('clms_students_bulk_batch_remove_btn');
    const bulkBatchSummary = document.getElementById('clmsStudentsBulkBatchSummary');

    /*
     * Selection persists across AJAX list swaps (pagination
     * mostly) by tracking IDs in this Set. When the partial
     * re-renders we re-check any visible checkbox whose
     * student id is still in here. Filter / search changes
     * clear it intentionally — the previous selection isn't
     * meaningful once the cohort changes.
     */
    const selectedIdSet = new Set();

    const updateSelectionMeta = () => {
      const meta = partial.querySelector('#clms-students-selection-meta');
      if (!meta) return;
      const n = selectedIdSet.size;
      meta.textContent = n + ' selected' + (n > 0 ? ' on this page' : '');
    };

    const updateSelectAllHeader = () => {
      const header = partial.querySelector('.clms-student-select-all');
      if (!header) return;
      const visible = [...partial.querySelectorAll('.clms-student-select')];
      if (visible.length === 0) {
        header.checked = false;
        header.indeterminate = false;
        return;
      }
      const allChecked = visible.every((cb) => cb.checked);
      const anyChecked = visible.some((cb) => cb.checked);
      header.checked = allChecked;
      header.indeterminate = anyChecked && !allChecked;
    };

    const visibleSelectedRows = () => {
      return [...partial.querySelectorAll('tr[data-clms-student-id]')]
        .filter((tr) => {
          const cb = tr.querySelector('.clms-student-select');
          return cb && cb.checked;
        });
    };

    const visibleSelectedCount = () => visibleSelectedRows().length;

    const countByApproval = (status) =>
      visibleSelectedRows().filter((tr) => tr.getAttribute('data-clms-approval-status') === status).length;

    const countByDisabled = (val) =>
      visibleSelectedRows().filter((tr) => tr.getAttribute('data-clms-account-disabled') === val).length;

    const countByArchived = (val) =>
      visibleSelectedRows().filter((tr) => tr.getAttribute('data-clms-account-archived') === val).length;

    const syncBulkButtons = () => {
      const n = selectedIdSet.size;
      if (bulkWrap) {
        bulkWrap.classList.toggle('d-none', n === 0);
        bulkWrap.setAttribute('aria-hidden', n > 0 ? 'false' : 'true');
      }
      if (bulkBatchBtn) {
        bulkBatchBtn.disabled = n === 0;
        bulkBatchBtn.textContent = n > 0 ? `Set batch… (${n})` : 'Set batch…';
      }
      /*
       * "Approve Selected" only enables when the selection
       * (on this page) contains at least one pending
       * student — approving already-approved accounts is a
       * no-op so we keep the button off to reduce noise.
       */
      if (bulkApproveBtn) {
        const pendingN = countByApproval('pending');
        bulkApproveBtn.disabled = pendingN === 0;
        bulkApproveBtn.textContent = pendingN > 0 ? `Approve Selected (${pendingN})` : 'Approve Selected';
      }
      if (bulkDisableBtn) {
        const activeN = countByDisabled('0');
        bulkDisableBtn.disabled = activeN === 0;
        bulkDisableBtn.textContent = activeN > 0 ? `Disable Selected (${activeN})` : 'Disable Selected';
      }
      if (bulkEnableBtn) {
        const disabledN = countByDisabled('1');
        bulkEnableBtn.disabled = disabledN === 0;
        bulkEnableBtn.textContent = disabledN > 0 ? `Enable Selected (${disabledN})` : 'Enable Selected';
      }
      if (bulkDeleteBtn) {
        bulkDeleteBtn.disabled = n === 0;
        bulkDeleteBtn.textContent = n > 0 ? `Delete Selected (${n})` : 'Delete Selected';
      }
      /*
       * "Restore Selected" only enables when the selection
       * contains at least one archived row on the current
       * page. Active rows in the same selection (when on
       * the "All (incl. archived)" view) are ignored by
       * the backend, but we count to keep the label
       * accurate.
       */
      if (bulkUnarchiveBtn) {
        const archivedN = countByArchived('1');
        bulkUnarchiveBtn.disabled = archivedN === 0;
        const labelIcon = '<i class="bx bx-archive-out me-1"></i>';
        bulkUnarchiveBtn.innerHTML = archivedN > 0 ?
          `${labelIcon}Restore Selected (${archivedN})` :
          `${labelIcon}Restore Selected`;
      }
      updateSelectAllHeader();
      updateSelectionMeta();
    };

    /*
     * Called after every AJAX list partial swap so the
     * visible checkboxes inherit the in-memory selection
     * state, and the bulk toolbar accurately reflects
     * what's selected.
     */
    const reapplySelectionToDom = () => {
      partial.querySelectorAll('.clms-student-select').forEach((cb) => {
        const id = parseInt(cb.value, 10);
        if (Number.isFinite(id)) cb.checked = selectedIdSet.has(id);
      });
      syncBulkButtons();
    };

    const clearSelection = () => {
      selectedIdSet.clear();
      partial.querySelectorAll('.clms-student-select:checked').forEach((cb) => {
        cb.checked = false;
      });
      syncBulkButtons();
    };

    /* -------- Single-row action handles -------- */
    const deleteForm = document.getElementById('clmsDeleteStudentForm');
    const deleteIdInput = document.getElementById('clms_delete_student_id');
    const approvalForm = document.getElementById('clmsApproveStudentForm');
    const approvalIdInput = document.getElementById('clms_approval_student_id');
    const approvalStatusInput = document.getElementById('clms_approval_status');
    const disableForm = document.getElementById('clmsDisableStudentForm');
    const disableIdInput = document.getElementById('clms_disable_student_id');
    const disableAccountInput = document.getElementById('clms_disable_account');

    /* Delegated click handler for per-row action buttons.
       Lives on `partial` so AJAX-replaced rows keep working
       without rebinding. */
    partial.addEventListener('click', (event) => {
      const restoreBtn = event.target.closest('.clms-student-restore-btn');
      if (restoreBtn) {
        if (!unarchiveForm || !unarchiveIdInput) return;
        const id = restoreBtn.getAttribute('data-user-id');
        const name = restoreBtn.getAttribute('data-display-name') || 'this student';
        if (typeof Swal === 'undefined') {
          unarchiveIdInput.value = id || '';
          unarchiveForm.submit();
          return;
        }
        Swal.fire({
          icon: 'question',
          title: 'Restore ' + name + '?',
          text: 'They will appear in the active students list again and their account will be re-enabled for sign-in.',
          showCancelButton: true,
          confirmButtonText: 'Restore',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#0f204b',
        }).then((r) => {
          if (!r.isConfirmed || !id) return;
          unarchiveIdInput.value = id;
          unarchiveForm.submit();
        });
        return;
      }
      const approveBtn = event.target.closest('.clms-student-approval-btn');
      if (approveBtn) {
        if (!approvalForm || !approvalIdInput || !approvalStatusInput) return;
        const id = approveBtn.getAttribute('data-user-id');
        const status = approveBtn.getAttribute('data-approval-status');
        if (!id || !status) return;
        approvalIdInput.value = id;
        approvalStatusInput.value = status;
        approvalForm.submit();
        return;
      }
      const disBtn = event.target.closest('.clms-student-disable-btn');
      if (disBtn) {
        if (!disableForm || !disableIdInput || !disableAccountInput) return;
        const id = disBtn.getAttribute('data-user-id');
        const disableValue = disBtn.getAttribute('data-disable-account');
        if (!id || !disableValue) return;
        disableIdInput.value = id;
        disableAccountInput.value = disableValue;
        disableForm.submit();
        return;
      }
      const delBtn = event.target.closest('.clms-student-delete-btn');
      if (delBtn) {
        if (!deleteForm || !deleteIdInput) return;
        const id = delBtn.getAttribute('data-user-id');
        const name = delBtn.getAttribute('data-display-name') || 'this student';
        const email = delBtn.getAttribute('data-user-email') || '';
        const detail = email ? name + ' (' + email + ')' : name;
        if (typeof Swal === 'undefined') {
          deleteIdInput.value = id || '';
          deleteForm.submit();
          return;
        }
        Swal.fire({
          icon: 'warning',
          title: 'Delete student?',
          text: 'Remove ' + detail + ' permanently. Related progress and activity may be deleted with the account.',
          showCancelButton: true,
          confirmButtonText: 'Delete',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#dc3545',
        }).then((r) => {
          if (!r.isConfirmed || !id) return;
          deleteIdInput.value = id;
          deleteForm.submit();
        });
        return;
      }
    });

    /* Edit-student modal: populate fields from the row's
       data-attributes when the button opens it. */
    const editModalEl = document.getElementById('editStudentModal');
    const editForm = document.getElementById('editStudentForm');
    if (editModalEl && editForm) {
      const idEl = document.getElementById('modal_edit_student_id');
      const fnEl = document.getElementById('modal_edit_student_fn');
      const lnEl = document.getElementById('modal_edit_student_ln');
      const emEl = document.getElementById('modal_edit_student_em');
      const pwEl = document.getElementById('modal_edit_student_pw');
      const batchSelect = document.getElementById('modal_edit_student_batch_select');
      const batchOtherWrap = document.getElementById('modal_edit_student_batch_other_wrap');
      const batchOther = document.getElementById('modal_edit_student_batch_other');
      const batchHidden = document.getElementById('modal_edit_student_batch');

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
        if (!matched && v !== '') {
          batchSelect.value = '__other__';
          if (batchOther) batchOther.value = v;
        } else if (matched && batchOther) {
          batchOther.value = '';
        } else if (v === '') {
          batchSelect.value = '';
          if (batchOther) batchOther.value = '';
        }
        if (batchOtherWrap) {
          batchOtherWrap.classList.toggle('d-none', batchSelect.value !== '__other__');
        }
        batchHidden.value = batchSelect.value === '__other__' ?
          (batchOther ? batchOther.value.trim() : '') :
          batchSelect.value;
      };

      const syncEditBatchHidden = () => {
        if (!batchSelect || !batchHidden) return;
        batchHidden.value = batchSelect.value === '__other__' ?
          (batchOther ? batchOther.value.trim() : '') :
          batchSelect.value;
      };

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
      editForm.addEventListener('submit', syncEditBatchHidden);

      editModalEl.addEventListener('show.bs.modal', (event) => {
        const btn = event.relatedTarget;
        if (!btn) return;
        if (idEl) idEl.value = btn.getAttribute('data-edit-id') || '';
        if (fnEl) fnEl.value = btn.getAttribute('data-edit-fn') || '';
        if (lnEl) lnEl.value = btn.getAttribute('data-edit-ln') || '';
        if (emEl) emEl.value = btn.getAttribute('data-edit-em') || '';
        if (pwEl) pwEl.value = '';
        applyEditBatchValue(btn.getAttribute('data-edit-batch') || '');
      });
    }

    /* Delegated handler for row + select-all checkboxes. */
    partial.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) return;
      if (target.classList.contains('clms-student-select')) {
        const id = parseInt(target.value, 10);
        if (!Number.isFinite(id)) return;
        if (target.checked) selectedIdSet.add(id);
        else selectedIdSet.delete(id);
        syncBulkButtons();
      } else if (target.classList.contains('clms-student-select-all')) {
        const checked = target.checked;
        partial.querySelectorAll('.clms-student-select').forEach((cb) => {
          const id = parseInt(cb.value, 10);
          if (!Number.isFinite(id)) return;
          cb.checked = checked;
          if (checked) selectedIdSet.add(id);
          else selectedIdSet.delete(id);
        });
        syncBulkButtons();
      }
    });

    const endpoint = <?php echo json_encode($clmsWebBase . '/admin/students.php', JSON_UNESCAPED_SLASHES); ?>;
    let currentPage = <?php echo (int) $page; ?>;
    let debounceId = null;
    let inFlight = null;

    const readListFilters = () => ({
      q: input.value.trim(),
      batch: batchSel ? batchSel.value : '',
      account: accountSel ? accountSel.value : '',
      approval: approvalSel ? approvalSel.value : '',
      archive: archiveSel ? archiveSel.value : ''
    });

    const buildListFetchUrl = (f) => {
      const params = new URLSearchParams();
      if (f.q) params.set('q', f.q);
      if (f.page > 1) params.set('page', String(f.page));
      if (f.batch) params.set('batch', f.batch);
      if (f.account) params.set('account', f.account);
      if (f.approval) params.set('approval', f.approval);
      if (f.archive) params.set('archive', f.archive);
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
      if (f.archive) params.set('archive', f.archive);
      const qs = params.toString();
      return qs ? `${endpoint}?${qs}` : endpoint;
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
      const f = {
        ...readListFilters(),
        page: currentPage,
        ...patch
      };
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
        history.replaceState(null, '', buildPageUrl(f));

        const navbarSearch = document.getElementById('clmsNavbarSearch');
        if (navbarSearch && navbarSearch.value) {
          navbarSearch.dispatchEvent(new Event('input'));
        }
        /* Re-paint the bulk toolbar to match the new
           page's contents (selection set is preserved
           across pages of the same filter; the helpers
           that clear it on filter/search live below). */
        reapplySelectionToDom();
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

    input.addEventListener('input', () => {
      clearTimeout(debounceId);
      const q = input.value.trim();
      if (clearBtn) clearBtn.classList.toggle('d-none', q === '');
      debounceId = setTimeout(() => {
        clearSelection();
        fetchAndSwap({
          q,
          page: 1
        });
      }, 250);
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      clearTimeout(debounceId);
      clearSelection();
      fetchAndSwap({
        page: 1
      });
    });

    if (batchSel) {
      batchSel.addEventListener('change', () => {
        clearTimeout(debounceId);
        clearSelection();
        fetchAndSwap({
          page: 1
        });
      });
    }
    if (accountSel) {
      accountSel.addEventListener('change', () => {
        clearTimeout(debounceId);
        clearSelection();
        if (accountSel.value === 'active' && approvalSel &&
          (approvalSel.value === 'pending' || approvalSel.value === 'rejected')) {
          accountSel.value = '';
        }
        fetchAndSwap({
          page: 1
        });
      });
    }
    if (approvalSel) {
      approvalSel.addEventListener('change', () => {
        clearTimeout(debounceId);
        clearSelection();
        if ((approvalSel.value === 'pending' || approvalSel.value === 'rejected') &&
          accountSel && accountSel.value === 'active') {
          accountSel.value = '';
        }
        fetchAndSwap({
          page: 1
        });
      });
    }
    if (archiveSel) {
      /*
       * Flipping into / out of the archived view changes
       * the available row actions (Restore vs Approve /
       * Disable / etc.) AND the Archive batch toolbar
       * button — easiest to do a full reload here so all
       * server-rendered state stays consistent.
       */
      archiveSel.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('page');
        if (archiveSel.value) {
          url.searchParams.set('archive', archiveSel.value);
        } else {
          url.searchParams.delete('archive');
        }
        window.location.href = url.toString();
      });
    }

    if (archiveBatchBtn) {
      archiveBatchBtn.addEventListener('click', () => {
        if (!archiveBatchForm || !archiveBatchLabelInput) return;
        const label = archiveBatchBtn.getAttribute('data-batch-label') || '';
        if (!label) return;
        const display = label === '__none__' ? '(no batch)' : '"' + label + '"';
        if (typeof Swal === 'undefined') {
          archiveBatchLabelInput.value = label;
          archiveBatchForm.submit();
          return;
        }
        Swal.fire({
          icon: 'warning',
          title: 'Archive batch ' + display + '?',
          html: 'Every student currently in this batch will be moved to the <strong>archive</strong> and their accounts <strong>disabled</strong> so they can no longer sign in. Their progress, exam attempts, and certificates are kept — they just stop appearing in the default list. You can restore them later from <em>Archive</em> → <em>Archived only</em>, which will also re-enable their sign-in.',
          showCancelButton: true,
          confirmButtonText: 'Yes, archive',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#dc3545',
        }).then((r) => {
          if (!r.isConfirmed) return;
          archiveBatchLabelInput.value = label;
          archiveBatchForm.submit();
        });
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        input.value = '';
        clearBtn.classList.add('d-none');
        clearTimeout(debounceId);
        clearSelection();
        fetchAndSwap({
          q: '',
          page: 1
        });
        input.focus();
      });
    }

    /* -------- Bulk action click handlers -------- */
    const collectSelectedIdsJson = () => JSON.stringify([...selectedIdSet]);

    const confirmAndSubmit = (formEl, idsInput, opts) => {
      if (!formEl || !idsInput) return;
      const ids = [...selectedIdSet];
      if (ids.length === 0) return;
      idsInput.value = JSON.stringify(ids);
      if (typeof Swal === 'undefined') {
        formEl.submit();
        return;
      }
      Swal.fire({
        icon: opts.icon || 'question',
        title: opts.title,
        text: opts.text,
        showCancelButton: true,
        confirmButtonText: opts.confirmText || 'Yes',
        cancelButtonText: 'Cancel',
        confirmButtonColor: opts.danger ? '#dc3545' : '#0f204b',
      }).then((r) => {
        if (r.isConfirmed) formEl.submit();
      });
    };

    if (bulkApproveBtn) {
      bulkApproveBtn.addEventListener('click', () => {
        if (bulkApproveBtn.disabled) return;
        const pendingN = countByApproval('pending');
        confirmAndSubmit(bulkApproveForm, bulkApproveIdsInput, {
          title: `Approve ${pendingN} student${pendingN === 1 ? '' : 's'}?`,
          text: 'They will be able to sign in immediately.',
          confirmText: 'Approve',
        });
      });
    }

    if (bulkDisableBtn) {
      bulkDisableBtn.addEventListener('click', () => {
        if (bulkDisableBtn.disabled) return;
        if (bulkDisableStateInput) bulkDisableStateInput.value = '1';
        const activeN = countByDisabled('0');
        confirmAndSubmit(bulkDisableForm, bulkDisableIdsInput, {
          icon: 'warning',
          title: `Disable ${activeN} student${activeN === 1 ? '' : 's'}?`,
          text: 'They will no longer be able to sign in until you re-enable them.',
          confirmText: 'Disable',
          danger: true,
        });
      });
    }
    if (bulkEnableBtn) {
      bulkEnableBtn.addEventListener('click', () => {
        if (bulkEnableBtn.disabled) return;
        if (bulkDisableStateInput) bulkDisableStateInput.value = '0';
        const disabledN = countByDisabled('1');
        confirmAndSubmit(bulkDisableForm, bulkDisableIdsInput, {
          icon: 'question',
          title: `Enable ${disabledN} student${disabledN === 1 ? '' : 's'}?`,
          text: 'They will be able to sign in again.',
          confirmText: 'Enable',
        });
      });
    }
    if (bulkDeleteBtn) {
      bulkDeleteBtn.addEventListener('click', () => {
        if (bulkDeleteBtn.disabled) return;
        const n = selectedIdSet.size;
        confirmAndSubmit(bulkDeleteForm, bulkDeleteIdsInput, {
          icon: 'warning',
          title: `Delete ${n} student${n === 1 ? '' : 's'}?`,
          text: 'This permanently removes their accounts. Related progress and attempts may be deleted with them. This cannot be undone.',
          confirmText: 'Delete',
          danger: true,
        });
      });
    }
    if (bulkUnarchiveBtn) {
      bulkUnarchiveBtn.addEventListener('click', () => {
        if (bulkUnarchiveBtn.disabled) return;
        const archivedN = countByArchived('1');
        confirmAndSubmit(bulkUnarchiveForm, bulkUnarchiveIdsInput, {
          icon: 'question',
          title: `Restore ${archivedN} student${archivedN === 1 ? '' : 's'}?`,
          text: 'They will appear in the active students list again and have their accounts re-enabled for sign-in. Active rows in the selection are skipped.',
          confirmText: 'Restore',
        });
      });
    }

    /* -------- Bulk batch modal wiring -------- */
    const syncBulkBatchLabelHidden = () => {
      if (!bulkBatchLabelSelect || !bulkBatchLabelHidden) return;
      if (bulkBatchLabelSelect.value === '__other__') {
        bulkBatchLabelHidden.value = bulkBatchLabelOther ? bulkBatchLabelOther.value.trim() : '';
      } else {
        bulkBatchLabelHidden.value = bulkBatchLabelSelect.value;
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
        if (bulkBatchClear) bulkBatchClear.value = '0';
        syncBulkBatchLabelHidden();
      });
    }
    if (bulkBatchLabelOther) {
      bulkBatchLabelOther.addEventListener('input', syncBulkBatchLabelHidden);
    }
    if (bulkBatchScopeSel) {
      bulkBatchScopeSel.addEventListener('change', () => {
        if (bulkBatchScope) bulkBatchScope.value = 'selection';
      });
    }
    if (bulkBatchScopeAll) {
      bulkBatchScopeAll.addEventListener('change', () => {
        if (bulkBatchScope) bulkBatchScope.value = 'all_filtered_students';
      });
    }
    if (bulkBatchBtn) {
      bulkBatchBtn.addEventListener('click', () => {
        if (bulkBatchBtn.disabled) return;
        if (!bulkBatchModalEl || !window.bootstrap || !bootstrap.Modal) return;
        const ids = [...selectedIdSet];
        if (bulkBatchIdsInput) bulkBatchIdsInput.value = JSON.stringify(ids);
        if (bulkBatchClear) bulkBatchClear.value = '0';
        if (bulkBatchScope) bulkBatchScope.value = 'selection';
        if (bulkBatchScopeSel) bulkBatchScopeSel.checked = true;
        if (bulkBatchLabelSelect) {
          bulkBatchLabelSelect.value = '';
          if (bulkBatchLabelOtherWrap) bulkBatchLabelOtherWrap.classList.add('d-none');
          if (bulkBatchLabelOther) bulkBatchLabelOther.value = '';
        }
        if (bulkBatchLabelHidden) bulkBatchLabelHidden.value = '';
        if (bulkBatchSummary) {
          bulkBatchSummary.textContent = ids.length + ' student' + (ids.length === 1 ? '' : 's') + ' selected.';
        }
        if (bulkBatchScopeSelCount) {
          bulkBatchScopeSelCount.textContent = ids.length > 0 ? `(${ids.length})` : '';
        }
        bootstrap.Modal.getOrCreateInstance(bulkBatchModalEl).show();
      });
    }
    if (bulkBatchRemoveBtn) {
      bulkBatchRemoveBtn.addEventListener('click', () => {
        if (!bulkBatchForm || !bulkBatchIdsInput || !bulkBatchClear) return;
        if (typeof Swal === 'undefined') {
          bulkBatchClear.value = '1';
          bulkBatchForm.submit();
          return;
        }
        Swal.fire({
          icon: 'warning',
          title: 'Remove batch label?',
          text: 'Selected students will no longer be tagged with any batch / cohort.',
          showCancelButton: true,
          confirmButtonText: 'Remove',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#dc3545',
        }).then((r) => {
          if (!r.isConfirmed) return;
          bulkBatchClear.value = '1';
          bulkBatchForm.submit();
        });
      });
    }
    if (bulkBatchForm) {
      bulkBatchForm.addEventListener('submit', (event) => {
        syncBulkBatchLabelHidden();
        const clearing = bulkBatchClear && bulkBatchClear.value === '1';
        const label = bulkBatchLabelHidden ? bulkBatchLabelHidden.value.trim() : '';
        if (!clearing && label === '') {
          event.preventDefault();
          if (typeof ClmsNotify !== 'undefined') {
            ClmsNotify.error('Pick a batch / cohort label, or use "Remove batch" to clear it.');
          }
        }
      });
    }

    /* Initial sync (in case the partial was rendered with
       a previously stored selection in URL state). */
    syncBulkButtons();

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
      fetchAndSwap({
        page: nextPage
      });
      window.scrollTo({
        top: form.getBoundingClientRect().top + window.scrollY - 80,
        behavior: 'smooth'
      });
    });

  })();
</script>

<style>
  /* Form validation error styling */
  .form-control.is-invalid,
  .form-select.is-invalid {
    border-color: #dc3545;
  }

  .form-control.is-invalid:focus,
  .form-select.is-invalid:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
  }

  .invalid-feedback {
    display: block;
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.25rem;
  }
</style>

<script src="/public/assets/js/form-validation.js"></script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
