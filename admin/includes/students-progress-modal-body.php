<?php

declare(strict_types=1);

/**
 * Modal body: course progress + recent attempts for one student.
 *
 * Expected: $selectedStudent (array|null), $courseProgress (array), $recentAttempts (array)
 */

if ($selectedStudent === null) {
    echo '<p class="text-danger mb-0">Student not found or is not a learner account.</p>';
    return;
}
?>
  <div
    class="visually-hidden"
    data-clms-progress-name="<?php echo htmlspecialchars(trim((string) $selectedStudent['first_name'] . ' ' . (string) $selectedStudent['last_name']), ENT_QUOTES, 'UTF-8'); ?>"
    data-clms-progress-email="<?php echo htmlspecialchars((string) $selectedStudent['email'], ENT_QUOTES, 'UTF-8'); ?>"></div>

  <h6 class="mb-3">Course progress</h6>
<?php if ($courseProgress === []) : ?>
  <p class="text-muted mb-4">No course progress yet.</p>
<?php else : ?>
  <div class="table-responsive mb-4">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Course</th>
          <th>Modules</th>
          <th>Completion</th>
          <th>Best score</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($courseProgress as $progressRow) : ?>
<?php
    $totalModules = (int) $progressRow['total_modules'];
    $completedModules = (int) $progressRow['completed_modules'];
    $percent = $totalModules > 0 ? (int) round(($completedModules / $totalModules) * 100) : 0;
    $bestScore = $progressRow['best_score'] !== null ? (float) $progressRow['best_score'] : null;
    $hasPassed = (int) $progressRow['has_passed'] === 1;
?>
        <tr>
          <td><?php echo htmlspecialchars((string) $progressRow['course_title'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo $completedModules; ?> / <?php echo $totalModules; ?></td>
          <td style="min-width: 160px;">
            <div class="progress" style="height: 6px;">
              <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <small class="text-muted"><?php echo $percent; ?>%</small>
          </td>
          <td><?php echo $bestScore !== null ? number_format($bestScore, 2) . '%' : '—'; ?></td>
          <td>
            <span class="badge <?php echo $hasPassed ? 'bg-label-success' : 'bg-label-warning'; ?>">
              <?php echo $hasPassed ? 'Passed' : 'In Progress'; ?>
            </span>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

  <h6 class="mb-3">Recent exam attempts</h6>
<?php if ($recentAttempts === []) : ?>
  <p class="text-muted mb-0">No exam attempts recorded.</p>
<?php else : ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Course</th>
          <th>Status</th>
          <th>Score</th>
          <th>Started</th>
          <th>Completed</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($recentAttempts as $attempt) : ?>
        <tr>
          <td><?php echo htmlspecialchars((string) $attempt['course_title'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <span class="badge bg-label-<?php echo (string) $attempt['status'] === 'completed' ? 'success' : ((string) $attempt['status'] === 'in_progress' ? 'info' : 'warning'); ?>">
              <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $attempt['status'])), ENT_QUOTES, 'UTF-8'); ?>
            </span>
          </td>
          <td>
<?php if ($attempt['total_score'] !== null) : ?>
            <?php echo number_format((float) $attempt['total_score'], 2); ?>%
            <?php if ((int) $attempt['is_passed'] === 1) : ?>
              <i class="bx bx-check-circle text-success"></i>
            <?php endif; ?>
<?php else : ?>
            —
<?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) $attempt['attempted_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
<?php if ($attempt['completed_at'] !== null) : ?>
            <?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime((string) $attempt['completed_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
<?php else : ?>
            —
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
