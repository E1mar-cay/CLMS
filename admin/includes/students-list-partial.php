<?php

declare(strict_types=1);

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
  </small>
</div>

<?php if ($students === []) : ?>
  <p class="mb-0 text-muted">
    <?php
    $hasOtherFilters = $filterBatch !== '' || $filterAccount !== '' || $filterApproval !== '';
    if ($searchQuery !== '') {
        echo 'No students match your search.';
    } elseif ($hasOtherFilters) {
        echo 'No students match the current filters.';
    } else {
        echo 'No students found.';
    }
    ?>
  </p>
<?php else : ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
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
?>
        <tr
          data-search-item
          data-search-text="<?php echo htmlspecialchars($fullName . ' ' . $studentEmail, ENT_QUOTES, 'UTF-8'); ?>">
          <td><?php echo $rowNumber; ?></td>
          <td><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $student['email'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo $modulesDone; ?>/<?php echo $totalModulesOverall; ?></td>
          <td><?php echo number_format($avgScore, 2); ?>%</td>
          <td><?php echo (int) $student['attempts_total']; ?></td>
          <td><?php echo (int) $student['certificates_count']; ?></td>
          <td class="text-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary clms-student-progress-btn"
              data-student-id="<?php echo (int) $student['id']; ?>"
              data-student-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
              data-student-email="<?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?>">
              <i class="bx bx-show"></i> View Progress
            </button>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($totalPages > 1) : ?>
  <nav aria-label="Students pagination" class="mt-3">
    <ul class="pagination pagination-sm mb-0 justify-content-end">
<?php
    $prevParams = $paginationBase;
    if ($page > 2) {
        $prevParams['page'] = $page - 1;
    }
    $prevUrl = $clmsWebBase . '/admin/students.php' . ($prevParams !== [] ? '?' . http_build_query($prevParams) : '');
?>
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>" data-students-page="<?php echo max(1, $page - 1); ?>">Previous</a>
      </li>
<?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++) : ?>
<?php
    $pageParams = $paginationBase;
    if ($pageNumber > 1) {
        $pageParams['page'] = $pageNumber;
    }
    $pageUrl = $clmsWebBase . '/admin/students.php' . ($pageParams !== [] ? '?' . http_build_query($pageParams) : '');
?>
      <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'); ?>" data-students-page="<?php echo $pageNumber; ?>"><?php echo $pageNumber; ?></a>
      </li>
<?php endfor; ?>
<?php
    $nextParams = $paginationBase;
    $nextParams['page'] = min($totalPages, $page + 1);
    $nextUrl = $clmsWebBase . '/admin/students.php?' . http_build_query($nextParams);
?>
      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>" data-students-page="<?php echo min($totalPages, $page + 1); ?>">Next</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
<?php endif; ?>
