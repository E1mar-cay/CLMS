<?php

declare(strict_types=1);

if (!isset($clmsWebBase)) {
    require_once dirname(__DIR__, 2) . '/includes/sneat-paths.php';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 px-3 pt-3">
  <small class="text-muted">
    Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>&ndash;<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> user(s)<?php if ($searchQuery !== '') : ?>
      for &quot;<strong><?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?></strong>&quot;
    <?php endif; ?>
    <?php if (!empty($pendingOnly)) : ?>
      <span class="ms-1">(Pending approvals only)</span>
    <?php endif; ?>
  </small>
</div>

<?php if ($userRows === []) : ?>
  <p class="mb-0 text-muted px-3 pb-3">
    <?php
    if (!empty($pendingOnly)) {
        echo $searchQuery !== ''
            ? 'No pending students match your search.'
            : 'No pending student approvals found.';
    } else {
        echo $searchQuery !== ''
            ? 'No users match your search.'
            : 'No users found.';
    }
    ?>
  </p>
<?php else : ?>
  <div class="px-3 pb-2">
    <small class="text-muted" id="clms-users-selection-meta">0 selected</small>
  </div>
  <style>
    .clms-actions-group .clms-action-btn {
      width: 30px;
      height: 30px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .clms-actions-group .clms-action-btn i {
      font-size: 1rem;
      line-height: 1;
    }
  </style>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width: 36px;">
            <input type="checkbox" class="form-check-input clms-user-select-all" aria-label="Select all deletable users on this page" />
          </th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Approval</th>
          <th>Account</th>
          <th>Joined</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($userRows as $u) : ?>
<?php
    $rowUserId = (int) $u['id'];
    $role = (string) ($u['role'] ?? '');
    $approvalStatus = clms_user_approval_normalize($u['account_approval_status'] ?? null);
    $isDisabled = clms_user_is_disabled($u['account_is_disabled'] ?? 0);
    $isStudent = $role === 'student';
    $isLastAdmin = (string) $u['role'] === 'admin' && $totalAdminCount < 2;
    $canDeleteRow = $rowUserId !== $currentUserId && !$isLastAdmin;
    $deleteDisabledTitle = $rowUserId === $currentUserId
        ? 'You cannot delete your own account while signed in.'
        : ($isLastAdmin ? 'Cannot delete the last administrator account.' : '');
?>
        <tr data-search-item data-search-text="<?php echo htmlspecialchars(trim((string) $u['first_name'] . ' ' . (string) $u['last_name'] . ' ' . (string) $u['email']), ENT_QUOTES, 'UTF-8'); ?>">
          <td>
            <input
              type="checkbox"
              class="form-check-input clms-user-select"
              value="<?php echo $rowUserId; ?>"
              <?php echo $canDeleteRow ? '' : 'disabled'; ?>
              <?php echo $canDeleteRow ? '' : 'title="' . htmlspecialchars($deleteDisabledTitle, ENT_QUOTES, 'UTF-8') . '"'; ?> />
          </td>
          <td><?php echo htmlspecialchars(trim((string) $u['first_name'] . ' ' . (string) $u['last_name']), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><span class="badge bg-label-primary"><?php echo htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8'); ?></span></td>
          <td>
<?php if ($isStudent) : ?>
<?php
    $approvalBadgeClass = 'warning';
    if ($approvalStatus === 'approved') {
        $approvalBadgeClass = 'success';
    } elseif ($approvalStatus === 'rejected') {
        $approvalBadgeClass = 'danger';
    }
?>
            <span class="badge bg-label-<?php echo $approvalBadgeClass; ?>"><?php echo htmlspecialchars(ucfirst($approvalStatus), ENT_QUOTES, 'UTF-8'); ?></span>
<?php else : ?>
            <span class="badge bg-label-success">Approved</span>
<?php endif; ?>
          </td>
          <td>
<?php if ($isDisabled) : ?>
            <span class="badge bg-label-danger">Disabled</span>
<?php else : ?>
            <span class="badge bg-label-success">Active</span>
<?php endif; ?>
          </td>
          <td><small class="text-muted"><?php echo htmlspecialchars((string) date('M j, Y', strtotime((string) $u['created_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?></small></td>
          <td class="text-end">
            <div class="btn-group clms-actions-group" role="group" aria-label="Actions">
<?php if ($isStudent && $approvalStatus !== 'approved') : ?>
              <button
                type="button"
                class="btn btn-sm btn-outline-success clms-action-btn clms-approval-btn"
                data-user-id="<?php echo $rowUserId; ?>"
                data-approval-status="approved"
                title="Approve"
                aria-label="Approve">
                <i class="bx bx-check"></i>
              </button>
<?php endif; ?>
<?php if ($isStudent && $approvalStatus !== 'rejected') : ?>
              <button
                type="button"
                class="btn btn-sm btn-outline-warning clms-action-btn clms-approval-btn"
                data-user-id="<?php echo $rowUserId; ?>"
                data-approval-status="rejected"
                title="Reject"
                aria-label="Reject">
                <i class="bx bx-x"></i>
              </button>
<?php endif; ?>
              <button
                type="button"
                class="btn btn-sm <?php echo $isDisabled ? 'btn-outline-success' : 'btn-outline-secondary'; ?> clms-action-btn clms-disable-user-btn"
                data-user-id="<?php echo $rowUserId; ?>"
                data-disable-account="<?php echo $isDisabled ? '0' : '1'; ?>"
                <?php echo $rowUserId === $currentUserId ? 'disabled title="You cannot disable your own account while signed in."' : 'title="' . ($isDisabled ? 'Enable account' : 'Disable account') . '"'; ?>
                aria-label="<?php echo $isDisabled ? 'Enable account' : 'Disable account'; ?>">
                <i class="bx <?php echo $isDisabled ? 'bx-check-circle' : 'bx-block'; ?>"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary clms-action-btn clms-edit-user-btn"
                data-bs-toggle="modal"
                data-bs-target="#editUserModal"
                data-edit-id="<?php echo (int) $u['id']; ?>"
                data-edit-fn="<?php echo htmlspecialchars((string) $u['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-ln="<?php echo htmlspecialchars((string) $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-em="<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"
                title="Edit"
                aria-label="Edit">
                <i class="bx bx-pencil"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger clms-action-btn clms-delete-user-btn"
                data-user-id="<?php echo $rowUserId; ?>"
                data-display-name="<?php echo htmlspecialchars(trim((string) $u['first_name'] . ' ' . (string) $u['last_name']), ENT_QUOTES, 'UTF-8'); ?>"
                data-user-email="<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo $canDeleteRow ? 'title="Delete"' : 'disabled title="' . htmlspecialchars($deleteDisabledTitle, ENT_QUOTES, 'UTF-8') . '"'; ?>
                aria-label="Delete">
                <i class="bx bx-trash"></i>
              </button>
            </div>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($totalPages > 1) : ?>
  <nav aria-label="Users pagination" class="mt-3 px-3 pb-3">
    <ul class="pagination pagination-sm mb-0 justify-content-end">
<?php
    $prevParams = $paginationBase;
    if ($page > 2) {
        $prevParams['page'] = $page - 1;
    }
    $prevUrl = $clmsWebBase . '/admin/users.php' . ($prevParams !== [] ? '?' . http_build_query($prevParams) : '');
?>
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>" data-users-page="<?php echo max(1, $page - 1); ?>">Previous</a>
      </li>
<?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++) : ?>
<?php
    $pageParams = $paginationBase;
    if ($pageNumber > 1) {
        $pageParams['page'] = $pageNumber;
    }
    $pageUrl = $clmsWebBase . '/admin/users.php' . ($pageParams !== [] ? '?' . http_build_query($pageParams) : '');
?>
      <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'); ?>" data-users-page="<?php echo $pageNumber; ?>"><?php echo $pageNumber; ?></a>
      </li>
<?php endfor; ?>
<?php
    $nextParams = $paginationBase;
    $nextParams['page'] = min($totalPages, $page + 1);
    $nextUrl = $clmsWebBase . '/admin/users.php?' . http_build_query($nextParams);
?>
      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>" data-users-page="<?php echo min($totalPages, $page + 1); ?>">Next</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
<?php endif; ?>
