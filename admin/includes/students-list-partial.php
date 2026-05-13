<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';

/**
 * Replaceable partial for the "All Students" card body.
 *
 * Rendered both as part of the full page and as a standalone response when
 * `admin/students.php?ajax=1` is requested (list only; not `progress=1`).
 * Keeping it in its own file means
 * the markup stays in one place and server-side rendering drives the AJAX
 * swap (no duplicate HTML templates on the client).
 *
 * Expected in scope (supplied by admin/students.php):
 *   - $students (array), $totalRows (int), $totalPages (int), $page (int)
 *   - $offset (int), $perPage (int), $totalModulesOverall (int)
 *   - $searchQuery (string), $queryBase (array), $paginationBase (array)
 *   - $filterBatch (string), $filterAccount (string), $filterApproval (string)
 *   - $clmsWebBase (string, from includes/sneat-paths.php via header.php OR
 *       manually required here for standalone AJAX calls)
 */

if (!isset($clmsWebBase)) {
    /* Standalone AJAX call: layout-top.php (which loads sneat-paths.php) has
       not run yet, so derive the web base the same way header.php does. */
    require_once dirname(__DIR__, 2) . '/includes/sneat-paths.php';
}

$filterBatch = $filterBatch ?? '';
$filterAccount = $filterAccount ?? '';
$filterApproval = $filterApproval ?? '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <small class="text-muted">
    Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>&ndash;<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> student(s)<?php if ($searchQuery !== '') : ?>
      for &quot;<strong><?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?></strong>&quot;
    <?php endif; ?>
    <?php if ($filterBatch === '__none__') : ?>
      <span class="ms-1">· Unspecified batch</span>
    <?php elseif ($filterBatch !== '') : ?>
      <span class="ms-1">· Batch <strong><?php echo htmlspecialchars($filterBatch, ENT_QUOTES, 'UTF-8'); ?></strong></span>
    <?php endif; ?>
    <?php if ($filterAccount === 'active') : ?>
      <span class="ms-1">· Active accounts</span>
    <?php elseif ($filterAccount === 'disabled') : ?>
      <span class="ms-1">· Disabled accounts</span>
    <?php endif; ?>
    <?php if ($filterApproval !== '') : ?>
      <span class="ms-1">· Approval: <strong><?php echo htmlspecialchars(ucfirst($filterApproval), ENT_QUOTES, 'UTF-8'); ?></strong></span>
    <?php endif; ?>
    <?php if (($filterArchive ?? '') === 'archived') : ?>
      <span class="ms-1">· <strong>Archived only</strong></span>
    <?php elseif (($filterArchive ?? '') === 'all') : ?>
      <span class="ms-1">· All (incl. archived)</span>
    <?php endif; ?>
  </small>
</div>

<?php if ($students === []) : ?>
  <p class="mb-0 text-muted">
    <?php
    $filterArchive = $filterArchive ?? '';
    $hasOtherFilters = $filterBatch !== '' || $filterAccount !== '' || $filterApproval !== '' || $filterArchive !== '';
    if ($searchQuery !== '') {
        echo 'No students match your search.';
    } elseif ($filterArchive === 'archived' && !$hasOtherFilters) {
        echo 'No archived students.';
    } elseif ($hasOtherFilters) {
        echo 'No students match the current filters.';
    } else {
        echo 'No students found.';
    }
    ?>
  </p>
<?php else : ?>
  <div class="mb-2">
    <small class="text-muted" id="clms-students-selection-meta">0 selected</small>
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
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th style="width: 36px;">
            <input
              type="checkbox"
              class="form-check-input clms-student-select-all"
              aria-label="Select all students on this page" />
          </th>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Modules Done</th>
          <th>Avg. Score</th>
          <th>Attempts</th>
          <th>Certificates</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($students as $index => $student) : ?>
<?php
    $rowNumber = $offset + $index + 1;
    $modulesDone = (int) $student['modules_completed'];
    $avgScore = (float) $student['avg_score'];
    $fullName = trim((string) $student['first_name'] . ' ' . (string) $student['last_name']);
    $studentEmail = (string) $student['email'];
    $studentRowId = (int) $student['id'];
    $rowAccountDisabled = (int) ($student['account_is_disabled'] ?? 0) === 1;
    $rowIsArchived = (int) ($student['account_is_archived'] ?? 0) === 1;
    $rowApprovalStatus = strtolower((string) ($student['account_approval_status'] ?? 'approved'));
    if (!in_array($rowApprovalStatus, ['pending', 'approved', 'rejected'], true)) {
        $rowApprovalStatus = 'approved';
    }
?>
        <tr
          data-search-item
          data-search-text="<?php echo htmlspecialchars($fullName . ' ' . $studentEmail, ENT_QUOTES, 'UTF-8'); ?>"
          data-clms-student-id="<?php echo $studentRowId; ?>"
          data-clms-account-disabled="<?php echo $rowAccountDisabled ? '1' : '0'; ?>"
          data-clms-account-archived="<?php echo $rowIsArchived ? '1' : '0'; ?>"
          data-clms-approval-status="<?php echo htmlspecialchars($rowApprovalStatus, ENT_QUOTES, 'UTF-8'); ?>">
          <td>
            <input
              type="checkbox"
              class="form-check-input clms-student-select"
              value="<?php echo $studentRowId; ?>"
              aria-label="Select <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" />
          </td>
          <td><?php echo $rowNumber; ?></td>
          <td>
            <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>
<?php if ($rowIsArchived) : ?>
            <span class="badge bg-label-secondary ms-1" title="This student is archived — hidden from the default list."><i class="bx bx-archive"></i> Archived</span>
<?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars((string) $student['email'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo $modulesDone; ?>/<?php echo $totalModulesOverall; ?></td>
          <td><?php echo number_format($avgScore, 2); ?>%</td>
          <td><?php echo (int) $student['attempts_total']; ?></td>
          <td><?php echo (int) $student['certificates_count']; ?></td>
          <td class="text-end">
<?php if ($rowIsArchived) : ?>
            <div class="btn-group clms-actions-group" role="group" aria-label="Actions">
              <button
                type="button"
                class="btn btn-sm btn-outline-success clms-action-btn clms-student-restore-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-display-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                title="Restore (un-archive)"
                aria-label="Restore">
                <i class="bx bx-archive-out"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger clms-action-btn clms-student-delete-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-display-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                data-user-email="<?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?>"
                title="Delete permanently"
                aria-label="Delete">
                <i class="bx bx-trash"></i>
              </button>
            </div>
<?php else : ?>
            <div class="btn-group clms-actions-group" role="group" aria-label="Actions">
<?php if ($rowApprovalStatus !== 'approved') : ?>
              <button
                type="button"
                class="btn btn-sm btn-outline-success clms-action-btn clms-student-approval-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-approval-status="approved"
                title="Approve"
                aria-label="Approve">
                <i class="bx bx-check"></i>
              </button>
<?php endif; ?>
<?php if ($rowApprovalStatus !== 'rejected') : ?>
              <button
                type="button"
                class="btn btn-sm btn-outline-warning clms-action-btn clms-student-approval-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-approval-status="rejected"
                title="Reject"
                aria-label="Reject">
                <i class="bx bx-x"></i>
              </button>
<?php endif; ?>
              <button
                type="button"
                class="btn btn-sm <?php echo $rowAccountDisabled ? 'btn-outline-success' : 'btn-outline-secondary'; ?> clms-action-btn clms-student-disable-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-disable-account="<?php echo $rowAccountDisabled ? '0' : '1'; ?>"
                title="<?php echo $rowAccountDisabled ? 'Enable account' : 'Disable account'; ?>"
                aria-label="<?php echo $rowAccountDisabled ? 'Enable account' : 'Disable account'; ?>">
                <i class="bx <?php echo $rowAccountDisabled ? 'bx-check-circle' : 'bx-block'; ?>"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary clms-action-btn clms-student-edit-btn"
                data-bs-toggle="modal"
                data-bs-target="#editStudentModal"
                data-edit-id="<?php echo $studentRowId; ?>"
                data-edit-fn="<?php echo htmlspecialchars((string) $student['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-ln="<?php echo htmlspecialchars((string) $student['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-em="<?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?>"
                data-edit-batch="<?php echo htmlspecialchars((string) ($student['student_batch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                title="Edit"
                aria-label="Edit">
                <i class="bx bx-pencil"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger clms-action-btn clms-student-delete-btn"
                data-user-id="<?php echo $studentRowId; ?>"
                data-display-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                data-user-email="<?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?>"
                title="Delete"
                aria-label="Delete">
                <i class="bx bx-trash"></i>
              </button>
            </div>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($totalPages > 1) : ?>
<?php
    clms_admin_pagination_render(
        $clmsWebBase,
        '/admin/students.php',
        $paginationBase,
        $page,
        $totalPages,
        'Students pagination',
        'page',
        'data-students-page',
        'mt-3 px-3'
    );
?>
<?php endif; ?>
<?php endif; ?>
