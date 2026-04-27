<?php

declare(strict_types=1);

/**
 * Exam result partial.
 *
 * Expected variables in scope:
 *   - string  $clmsWebBase
 *   - float   $totalAwardedPoints
 *   - float   $totalPossiblePoints
 *   - float   $passingPercentage
 *   - float   $achievedPercentage
 *   - int     $isPassed                   (0|1)
 *   - string  $finalStatus                ('completed')
 *   - ?string $certificateHash
 *
 * Optional:
 *   - bool    $examAutoSubmittedOnExpiry  — show the "Time's up" banner
 *   - ?string $examCourseTitle            — subtitle under the hero
 */

$resultPassed = (int) ($isPassed ?? 0) === 1;
$resultFailed = !$resultPassed;

if ($resultPassed) {
    $resultTone = 'success';
    $resultIcon = 'bx-trophy';
    $resultTitle = 'Exam Passed';
    $resultLead = 'Great work — you cleared the passing threshold for this course.';
} else {
    $resultTone = 'warning';
    $resultIcon = 'bx-error-circle';
    $resultTitle = 'Not Yet Passed';
    $resultLead = 'You didn\'t clear the passing threshold this time. Review your modules and try the exam again when you\'re ready.';
}

$statusLabelMap = [
    'completed' => 'Completed',
];
$statusLabel = $statusLabelMap[$finalStatus ?? ''] ?? ucwords(str_replace('_', ' ', (string) ($finalStatus ?? '')));

$achievedPctSafe = max(0.0, min(100.0, (float) ($achievedPercentage ?? 0)));
$passingPctSafe = max(0.0, min(100.0, (float) ($passingPercentage ?? 0)));
$awardedSafe = (float) ($totalAwardedPoints ?? 0);
$possibleSafe = (float) ($totalPossiblePoints ?? 0);
?>
<?php if (!empty($examAutoSubmittedOnExpiry)) : ?>
              <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
                <i class="bx bx-time fs-4 me-2"></i>
                <div>
                  <strong>Time's up.</strong> Your exam was submitted automatically using your latest auto-saved answers.
                </div>
              </div>
<?php endif; ?>

              <div class="card clms-exam-result clms-exam-result--<?php echo htmlspecialchars($resultTone, ENT_QUOTES, 'UTF-8'); ?> mb-4">
                <div class="clms-exam-result__hero">
                  <div class="clms-exam-result__hero-icon">
                    <i class="bx <?php echo htmlspecialchars($resultIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                  </div>
                  <h3 class="clms-exam-result__hero-title"><?php echo htmlspecialchars($resultTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                  <p class="clms-exam-result__hero-lead"><?php echo htmlspecialchars($resultLead, ENT_QUOTES, 'UTF-8'); ?></p>
<?php if (!empty($examCourseTitle)) : ?>
                  <p class="clms-exam-result__hero-course"><?php echo htmlspecialchars((string) $examCourseTitle, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
                </div>

                <div class="card-body pt-4">
                  <div class="clms-exam-result__score">
                    <div class="clms-exam-result__score-value">
                      <span class="clms-exam-result__percent"><?php echo number_format($achievedPctSafe, 1); ?></span><span class="clms-exam-result__percent-sign">%</span>
                    </div>
                    <div class="clms-exam-result__score-label">Your Score</div>

                    <div class="clms-exam-result__bar" role="progressbar"
                      aria-valuenow="<?php echo number_format($achievedPctSafe, 1); ?>"
                      aria-valuemin="0" aria-valuemax="100"
                      aria-label="Score">
                      <div class="clms-exam-result__bar-fill" style="width: <?php echo number_format($achievedPctSafe, 2); ?>%;"></div>
                      <div class="clms-exam-result__bar-marker"
                        style="left: <?php echo number_format($passingPctSafe, 2); ?>%;"
                        title="Passing threshold: <?php echo number_format($passingPctSafe, 1); ?>%"
                        aria-hidden="true"></div>
                    </div>
                    <div class="clms-exam-result__bar-legend">
                      <span><i class="bx bx-target-lock"></i> Passing at <?php echo number_format($passingPctSafe, 1); ?>%</span>
                    </div>
                  </div>

                  <div class="row g-3 mt-1 clms-exam-result__stats">
                    <div class="col-12 col-sm-4">
                      <div class="clms-exam-result__stat">
                        <div class="clms-exam-result__stat-icon"><i class="bx bx-star"></i></div>
                        <div class="clms-exam-result__stat-label">Points Scored</div>
                        <div class="clms-exam-result__stat-value">
                          <?php echo number_format($awardedSafe, 2); ?>
                          <span class="clms-exam-result__stat-muted">/ <?php echo number_format($possibleSafe, 2); ?></span>
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-sm-4">
                      <div class="clms-exam-result__stat">
                        <div class="clms-exam-result__stat-icon"><i class="bx bx-flag"></i></div>
                        <div class="clms-exam-result__stat-label">Passing Threshold</div>
                        <div class="clms-exam-result__stat-value"><?php echo number_format($passingPctSafe, 1); ?>%</div>
                      </div>
                    </div>
                    <div class="col-12 col-sm-4">
                      <div class="clms-exam-result__stat">
                        <div class="clms-exam-result__stat-icon"><i class="bx bx-info-circle"></i></div>
                        <div class="clms-exam-result__stat-label">Status</div>
                        <div class="clms-exam-result__stat-value"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    </div>
                  </div>

<?php if (!empty($certificateHash)) : ?>
                  <div class="clms-exam-result__certificate mt-4">
                    <div class="clms-exam-result__certificate-icon">
                      <i class="bx bx-award"></i>
                    </div>
                    <div class="clms-exam-result__certificate-body">
                      <div class="clms-exam-result__certificate-title">Certificate earned</div>
                      <div class="clms-exam-result__certificate-hash">
                        <span class="text-muted">Verification hash:</span>
                        <code><?php echo htmlspecialchars((string) $certificateHash, ENT_QUOTES, 'UTF-8'); ?></code>
                      </div>
                    </div>
                    <a
                      class="btn btn-success"
                      href="<?php echo htmlspecialchars($clmsWebBase . '/student/download_certificate.php?hash=' . urlencode((string) $certificateHash), ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bx bx-download me-1"></i>Download PDF
                    </a>
                  </div>
<?php endif; ?>

                  <div class="d-flex flex-wrap gap-2 mt-4 pt-2">
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bx bx-grid-alt me-1"></i>Back to Dashboard
                    </a>
<?php if ($resultFailed) : ?>
                    <a
                      class="btn btn-outline-primary"
                      href="<?php echo htmlspecialchars($clmsWebBase . '/student/take_exam.php?course_id=' . (int) ($courseId ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bx bx-refresh me-1"></i>Retake Exam
                    </a>
<?php endif; ?>
                  </div>
                </div>
              </div>
