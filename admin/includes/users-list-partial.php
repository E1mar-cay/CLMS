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
  </small>
</div>

<?php if ($userRows === []) : ?>
  <p class="mb-0 text-muted px-3 pb-3">
    <?php echo $searchQuery !== ''
        ? 'No users match your search.'
        : 'No users found.'; ?>
  </p>
<?php else : ?>
  <div class="px-3 pb-2">
    <small class="text-muted" id="clms-users-selection-meta">0 selected</small>
  </div>
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
          <th>Joined</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($userRows as $u) : ?>
<?php
    $rowUserId = (int) $u['id'];
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
          <td><span class="badge bg-label-primary"><?php echo htmlspecialchars(ucfirst((string) $u['role']), ENT_QUOTES, 'UTF-8'); ?></span></td>
          <td><small class="text-muted"><?php echo htmlspecialchars((string) date('M j, Y', strtotime((string) $u['created_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?></small></td>
          <td class="text-end">
            <div class="btn-group" role="group" aria-label="Actions">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary clms-edit-user-btn"
                data-bs-toggle="modal"
                data-bs-target="#editUserModal"
                data-edit-id="<?php echo (int) $u['id']; ?>"
                data-edit-fn="<?php echo htmlspecialchars((string) $u['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-ln="<?php echo htmlspecialchars((string) $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-em="<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-role="<?php echo htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8'); ?>">
                Edit
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger clms-delete-user-btn"
                data-user-id="<?php echo $rowUserId; ?>"
                data-display-name="<?php echo htmlspecialchars(trim((string) $u['first_name'] . ' ' . (string) $u['last_name']), ENT_QUOTES, 'UTF-8'); ?>"
                data-user-email="<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo $canDeleteRow ? '' : 'disabled title="' . htmlspecialchars($deleteDisabledTitle, ENT_QUOTES, 'UTF-8') . '"'; ?>>
                Delete
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
