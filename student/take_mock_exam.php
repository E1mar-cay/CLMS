<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['student']);

/* ── Schema migrations ─────────────────────────────────────────────── */
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mock_exam_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_id INT NOT NULL,
            status ENUM(\'in_progress\',\'completed\') NOT NULL DEFAULT \'in_progress\',
            total_score DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_possible DECIMAL(10,2) NOT NULL DEFAULT 0,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
            is_passed TINYINT(1) NOT NULL DEFAULT 0,
            question_order TEXT NULL COMMENT \'JSON array of question IDs in the order shown\',
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deadline_at DATETIME NULL,
            completed_at DATETIME NULL,
            INDEX idx_mock_user_course (user_id, course_id),
            CONSTRAINT fk_mock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_mock_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
} catch (Throwable $e) {
    error_log('mock_exam_attempts init failed: ' . $e->getMessage());
}

try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mock_exam_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            question_id INT NOT NULL,
            selected_answer_id INT NULL,
            text_response TEXT NULL,
            submitted_sequence_position INT NULL,
            INDEX idx_mer_attempt (attempt_id),
            CONSTRAINT fk_mer_attempt FOREIGN KEY (attempt_id) REFERENCES mock_exam_attempts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
} catch (Throwable $e) {
    error_log('mock_exam_responses init failed: ' . $e->getMessage());
}

/* ── Input validation ──────────────────────────────────────────────── */
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if ($courseId === false || $courseId === null || $courseId <= 0) {
    clms_redirect('student/dashboard.php');
}

$courseStmt = $pdo->prepare(
    'SELECT id, title, COALESCE(final_exam_duration_minutes, 45) AS duration
     FROM courses WHERE id = :id AND is_published = 1 LIMIT 1'
);
$courseStmt->execute(['id' => $courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    clms_redirect('student/dashboard.php');
}

/* ── Enrollment check ──────────────────────────────────────────────── */
$enrollStmt = $pdo->prepare(
    'SELECT 1 FROM courses c
     INNER JOIN modules m ON m.course_id = c.id
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :uid
     WHERE c.id = :cid AND up.id IS NOT NULL LIMIT 1'
);
$enrollStmt->execute(['uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
if (!$enrollStmt->fetch()) {
    clms_redirect('student/dashboard.php');
}

/* ── All modules must be complete ─────────────────────────────────── */
$modCheckStmt = $pdo->prepare(
    'SELECT m.id, COALESCE(up.is_completed, 0) AS is_completed
     FROM modules m
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :uid
     WHERE m.course_id = :cid'
);
$modCheckStmt->execute(['uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
foreach ($modCheckStmt->fetchAll() as $mr) {
    if ((int) $mr['is_completed'] !== 1) {
        clms_redirect('student/dashboard.php?notice=modules_incomplete');
    }
}

/* ── Load all module-linked questions for this course ─────────────── */
$qStmt = $pdo->prepare(
    'SELECT q.id, q.question_text, q.question_type, q.points,
            a.id AS answer_id, a.answer_text, a.sequence_position
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.course_id = :cid AND q.module_id IS NOT NULL
     ORDER BY q.id ASC, a.id ASC'
);
$qStmt->execute(['cid' => (int) $courseId]);
$rawRows = $qStmt->fetchAll();

if ($rawRows === []) {
    clms_redirect('student/dashboard.php?notice=no_mock_questions');
}

$allQuestions = [];
foreach ($rawRows as $row) {
    $qId = (int) $row['id'];
    if (!isset($allQuestions[$qId])) {
        $allQuestions[$qId] = [
            'id'            => $qId,
            'question_text' => (string) $row['question_text'],
            'question_type' => (string) $row['question_type'],
            'points'        => (float) $row['points'],
            'answers'       => [],
        ];
    }
    if ($row['answer_id'] !== null) {
        $allQuestions[$qId]['answers'][] = [
            'id'                => (int) $row['answer_id'],
            'answer_text'       => (string) $row['answer_text'],
            'sequence_position' => $row['sequence_position'] !== null ? (int) $row['sequence_position'] : null,
        ];
    }
}

/* ── Resolve or create in-progress attempt ────────────────────────── */
$durationMinutes = max(1, (int) $course['duration']);
$durationSeconds = $durationMinutes * 60;

$attemptStmt = $pdo->prepare(
    'SELECT id,
            UNIX_TIMESTAMP(attempted_at) AS attempted_at_unix,
            UNIX_TIMESTAMP(deadline_at)  AS deadline_at_unix,
            question_order
     FROM mock_exam_attempts
     WHERE user_id = :uid AND course_id = :cid AND status = \'in_progress\'
     ORDER BY id DESC LIMIT 1'
);
$attemptStmt->execute(['uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
$attempt = $attemptStmt->fetch();

$questionOrder = null; // JSON-encoded order from DB

if (!$attempt) {
    // Shuffle question IDs for this attempt
    $shuffledIds = array_keys($allQuestions);
    shuffle($shuffledIds);
    $questionOrder = json_encode($shuffledIds);

    $insertStmt = $pdo->prepare(
        'INSERT INTO mock_exam_attempts (user_id, course_id, status, deadline_at, question_order)
         VALUES (:uid, :cid, \'in_progress\', DATE_ADD(NOW(), INTERVAL :dur MINUTE), :qorder)'
    );
    $insertStmt->execute([
        'uid'    => (int) $_SESSION['user_id'],
        'cid'    => (int) $courseId,
        'dur'    => $durationMinutes,
        'qorder' => $questionOrder,
    ]);
    $attemptId = (int) $pdo->lastInsertId();

    $attemptStmt->execute(['uid' => (int) $_SESSION['user_id'], 'cid' => (int) $courseId]);
    $attempt = $attemptStmt->fetch();
    if (!$attempt) {
        throw new RuntimeException('Unable to start mock exam attempt.');
    }
} else {
    $attemptId = (int) $attempt['id'];
    $questionOrder = (string) ($attempt['question_order'] ?? '');
}

$attemptId = (int) $attempt['id'];
$examEndAtUnix = (int) ($attempt['deadline_at_unix'] ?? 0);
if ($examEndAtUnix <= 0) {
    $examEndAtUnix = (int) ($attempt['attempted_at_unix'] ?? time()) + $durationSeconds;
}

/* ── Auto-finalize if deadline passed ─────────────────────────────── */
if (time() >= $examEndAtUnix) {
    clms_redirect('student/grade_mock_exam.php?attempt_id=' . $attemptId . '&course_id=' . (int) $courseId . '&auto=1');
}

/* ── Build ordered question list ──────────────────────────────────── */
$orderedIds = [];
if ($questionOrder !== '' && $questionOrder !== null) {
    $decoded = json_decode($questionOrder, true);
    if (is_array($decoded)) {
        $orderedIds = array_map('intval', $decoded);
    }
}
// Fallback: use natural order if stored order is missing/corrupt
if ($orderedIds === []) {
    $orderedIds = array_keys($allQuestions);
}
// Only keep IDs that still exist
$orderedIds = array_values(array_filter($orderedIds, static fn(int $id): bool => isset($allQuestions[$id])));

/* ── Shuffle answers for each question (tricky!) ──────────────────── */
// For multiple_select and true_false we shuffle the answer options so
// the correct answer isn't always in the same position.
$questions = [];
foreach ($orderedIds as $qId) {
    $q = $allQuestions[$qId];
    if (in_array($q['question_type'], ['multiple_select', 'true_false'], true)) {
        shuffle($q['answers']);
    }
    $questions[] = $q;
}

/* ── Pre-fill from saved responses ────────────────────────────────── */
$savedStmt = $pdo->prepare(
    'SELECT question_id, selected_answer_id, text_response, submitted_sequence_position
     FROM mock_exam_responses WHERE attempt_id = :aid'
);
$savedStmt->execute(['aid' => $attemptId]);
$savedSelectedIds = [];
$savedTexts       = [];
$savedSequence    = [];
foreach ($savedStmt->fetchAll() as $sr) {
    $qid = (int) $sr['question_id'];
    if ($sr['selected_answer_id'] !== null && $sr['submitted_sequence_position'] !== null) {
        $savedSequence[$qid][(int) $sr['selected_answer_id']] = (int) $sr['submitted_sequence_position'];
    } elseif ($sr['selected_answer_id'] !== null) {
        $savedSelectedIds[$qid][] = (int) $sr['selected_answer_id'];
    } elseif ($sr['text_response'] !== null && $sr['text_response'] !== '') {
        $savedTexts[$qid] = (string) $sr['text_response'];
    }
}

$remainingSeconds    = max(0, $examEndAtUnix - time());
$initialRemainingLabel = sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60);

$pageTitle         = 'Mock Exam | Criminology LMS';
$activeStudentPage = 'dashboard';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <h4 class="fw-bold py-3 mb-2">Mock Exam</h4>
              <p class="mb-1 text-muted"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p class="mb-4 small text-muted">
                This is a <strong>practice exam</strong> combining questions from all module assessments — randomized and shuffled to challenge you.
                Score at least <strong>70%</strong> to unlock the final exam.
                Time limit: <strong><?php echo $durationMinutes; ?> minute<?php echo $durationMinutes === 1 ? '' : 's'; ?></strong>.
              </p>

              <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                <span class="fw-semibold"><i class="bx bx-brain me-1"></i>Mock Exam — Time Remaining</span>
                <div class="d-flex align-items-center gap-3">
                  <span class="small text-muted" id="mockAutosaveStatus" aria-live="polite">Saved</span>
                  <span class="badge bg-label-warning fs-6" id="mockTimer"><?php echo htmlspecialchars($initialRemainingLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              </div>

              <form id="mockExamForm" class="pb-5" data-clms-exam-progress-key="<?php echo htmlspecialchars('clms-exam-pane-mock-' . (int) $attemptId, ENT_QUOTES, 'UTF-8'); ?>" action="<?php echo htmlspecialchars($clmsWebBase . '/student/grade_mock_exam.php', ENT_QUOTES, 'UTF-8'); ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="course_id" value="<?php echo (int) $courseId; ?>" />
                <input type="hidden" name="attempt_id" value="<?php echo $attemptId; ?>" />

                <div class="clms-exam-stepper mb-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold mb-0">Progress</span>
                    <span class="small text-muted clms-exam-stepper__summary" aria-live="polite"></span>
                  </div>
                  <p class="small text-muted mb-0 mt-2">Use the <strong>round button</strong> (bottom-right) to open the question map and submit. Green / orange show answered status.</p>
                </div>

<?php foreach ($questions as $index => $question) :
    $qid      = (int) $question['id'];
    $qSelected = $savedSelectedIds[$qid] ?? [];
    $qText     = $savedTexts[$qid] ?? '';
    $qSequence = $savedSequence[$qid] ?? [];
?>
                <div class="card mb-4 clms-exam-q-pane<?php echo $index === 0 ? ' clms-exam-q-pane--active' : ''; ?>">
                  <div class="card-header">
                    <strong>Question <?php echo $index + 1; ?></strong>
                    <span class="badge bg-label-warning ms-2"><?php echo number_format((float) $question['points'], 2); ?> pts</span>
                  </div>
                  <div class="card-body">
                    <p class="mb-3"><?php echo nl2br(htmlspecialchars((string) $question['question_text'], ENT_QUOTES, 'UTF-8')); ?></p>

<?php if ($question['question_type'] === 'true_false') : ?>
<?php foreach ($question['answers'] as $answer) : ?>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="radio"
                        name="responses[<?php echo $qid; ?>][single]"
                        id="mq<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>"
                        value="<?php echo (int) $answer['id']; ?>"
                        <?php echo in_array((int) $answer['id'], $qSelected, true) ? 'checked' : ''; ?> />
                      <label class="form-check-label" for="mq<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>">
                        <?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                      </label>
                    </div>
<?php endforeach; ?>

<?php elseif ($question['question_type'] === 'multiple_select') : ?>
<?php foreach ($question['answers'] as $answer) : ?>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="radio"
                        name="responses[<?php echo $qid; ?>][single]"
                        id="mq<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>"
                        value="<?php echo (int) $answer['id']; ?>"
                        <?php echo in_array((int) $answer['id'], $qSelected, true) ? 'checked' : ''; ?> />
                      <label class="form-check-label" for="mq<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>">
                        <?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                      </label>
                    </div>
<?php endforeach; ?>

<?php elseif ($question['question_type'] === 'fill_blank') : ?>
                    <input type="text" class="form-control"
                      name="responses[<?php echo $qid; ?>][text]"
                      placeholder="Type your answer"
                      value="<?php echo htmlspecialchars($qText, ENT_QUOTES, 'UTF-8'); ?>" />

<?php elseif ($question['question_type'] === 'sequencing') : ?>
                    <p class="small text-muted mb-2">Drag the items into the correct order. You can also use the <strong>↑ / ↓</strong> buttons (or arrow keys while focused).</p>
<?php
                    /*
                     * Sequencing items are reorderable: each row carries a hidden
                     * position input named responses[<qid>][sequence][<answer_id>]
                     * so the existing server-side schema keeps working. Saved
                     * positions (from autosave) sort the items; unsaved items
                     * fall back to their natural DB order. The JS renumbers
                     * positions on every drag/keyboard move.
                     */
                    $seqAnswers = $question['answers'];
                    $seqSaved = $qSequence;
                    if ($seqSaved !== []) {
                        usort($seqAnswers, static function ($a, $b) use ($seqSaved) {
                            $pa = $seqSaved[(int) $a['id']] ?? PHP_INT_MAX;
                            $pb = $seqSaved[(int) $b['id']] ?? PHP_INT_MAX;
                            if ($pa === $pb) {
                                return ((int) $a['id']) <=> ((int) $b['id']);
                            }
                            return $pa <=> $pb;
                        });
                    }
?>
                    <ol class="clms-seq-list" data-clms-sequence-list data-clms-qid="<?php echo $qid; ?>" aria-label="Drag to reorder">
<?php foreach ($seqAnswers as $seqIdx => $answer) :
    $ansId = (int) $answer['id'];
    $pos = $seqIdx + 1;
?>
                      <li
                        class="clms-seq-item"
                        data-answer-id="<?php echo $ansId; ?>"
                        draggable="true"
                        tabindex="0"
                        role="listitem"
                        aria-roledescription="Sortable item">
                        <span class="clms-seq-handle" aria-hidden="true">
                          <i class="bx bx-menu"></i>
                        </span>
                        <span class="clms-seq-pos" aria-label="Position"><?php echo $pos; ?></span>
                        <span class="clms-seq-text"><?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="clms-seq-controls" role="group" aria-label="Reorder">
                          <button type="button" class="btn btn-outline-secondary" data-clms-seq-up aria-label="Move up">
                            <i class="bx bx-chevron-up" aria-hidden="true"></i>
                          </button>
                          <button type="button" class="btn btn-outline-secondary" data-clms-seq-down aria-label="Move down">
                            <i class="bx bx-chevron-down" aria-hidden="true"></i>
                          </button>
                        </span>
                        <input
                          type="hidden"
                          data-clms-seq-input
                          name="responses[<?php echo $qid; ?>][sequence][<?php echo $ansId; ?>]"
                          value="<?php echo $pos; ?>" />
                      </li>
<?php endforeach; ?>
                    </ol>
<?php endif; ?>
                  </div>
                </div>
<?php endforeach; ?>

                <div class="d-flex justify-content-between align-items-center gap-3 my-4 clms-exam-stepper-pager">
                  <button type="button" class="btn btn-outline-warning clms-exam-stepper__prev">← Previous</button>
                  <button type="button" class="btn btn-warning text-white clms-exam-stepper__next">Next →</button>
                </div>

                <button
                  type="button"
                  class="clms-exam-nav-fab btn btn-warning text-white"
                  data-bs-toggle="modal"
                  data-bs-target="#clmsMockExamNavModal"
                  title="Question map"
                  aria-label="Open question map">
                  <i class="bx bx-grid-alt" aria-hidden="true"></i>
                </button>
              </form>

              <div class="modal fade" id="clmsMockExamNavModal" tabindex="-1" aria-labelledby="clmsMockExamNavTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="clmsMockExamNavTitle">Jump to question</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <p class="small text-muted mb-3">Tap a number to open that question. <strong>Green</strong> = answered, <strong>orange</strong> = not answered (5 per row).</p>
                      <div class="clms-exam-stepper__nav" data-clms-exam-nav-map role="navigation" aria-label="Question numbers"></div>
                    </div>
                    <div class="modal-footer d-flex flex-wrap gap-2 justify-content-between">
                      <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
                      <button type="submit" form="mockExamForm" class="btn btn-warning text-white">Submit mock exam</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal fade" id="clmsMockExamReviewModal" tabindex="-1" aria-labelledby="clmsMockExamReviewTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="clmsMockExamReviewTitle">Review answers</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div data-clms-exam-review-summary></div>
                      <p class="small text-muted mb-2 mb-0">Green = answered · Orange = unanswered (5 per row).</p>
                      <div class="mt-2 clms-exam-stepper__nav" data-clms-exam-review-nav></div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Continue editing</button>
                      <button type="button" class="btn btn-warning text-white" data-clms-exam-review-submit>Submit mock exam</button>
                    </div>
                  </div>
                </div>
              </div>

              <script src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/js/clms-exam-sequencing.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
              <script src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/js/clms-exam-stepper.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
              <script>
                (() => {
                  const form = document.getElementById('mockExamForm');
                  const timerEl = document.getElementById('mockTimer');
                  const statusEl = document.getElementById('mockAutosaveStatus');
                  if (!form || !timerEl) return;

                  const attemptId = <?php echo $attemptId; ?>;
                  const courseId = <?php echo (int) $courseId; ?>;
                  const deadline = <?php echo (int) $examEndAtUnix; ?>;
                  const autosaveUrl = <?php echo json_encode($clmsWebBase . '/student/autosave_mock_exam.php', JSON_UNESCAPED_SLASHES); ?>;
                  const csrfToken = <?php echo json_encode(clms_csrf_token()); ?>;

                  const pad = (n) => String(n).padStart(2, '0');
                  let submitted = false;
                  let intervalId = null;

                  const autoSubmit = () => {
                    if (submitted) return;
                    submitted = true;
                    flushAutosave(true);
                    form.submit();
                  };

                  const tick = () => {
                    const remaining = Math.max(0, deadline - Math.floor(Date.now() / 1000));
                    timerEl.textContent = `${pad(Math.floor(remaining / 60))}:${pad(remaining % 60)}`;
                    if (remaining <= 60) {
                      timerEl.classList.remove('bg-label-warning');
                      timerEl.classList.add('bg-warning', 'text-dark');
                    }
                    if (remaining <= 0) {
                      clearInterval(intervalId);
                      autoSubmit();
                    }
                  };

                  tick();
                  intervalId = setInterval(tick, 1000);

                  /* Autosave */
                  let debounceId = null;
                  let lastPayload = '';
                  let inFlight = false;
                  let pendingWhileFlight = false;

                  const setStatus = (text, tone) => {
                    if (!statusEl) return;
                    statusEl.textContent = text;
                    statusEl.className = 'small ' + (tone || 'text-muted');
                  };

                  const buildFd = () => {
                    const fd = new FormData(form);
                    fd.set('csrf_token', csrfToken);
                    fd.set('attempt_id', String(attemptId));
                    fd.set('course_id', String(courseId));
                    return fd;
                  };

                  const sig = (fd) => {
                    const parts = [];
                    for (const [k, v] of fd.entries()) {
                      if (k === 'csrf_token') continue;
                      parts.push(`${k}=${typeof v === 'string' ? v : ''}`);
                    }
                    return parts.sort().join('&');
                  };

                  const doAutosave = () => {
                    if (submitted) return;
                    const fd = buildFd();
                    const s = sig(fd);
                    if (s === lastPayload) return;
                    if (inFlight) { pendingWhileFlight = true; return; }
                    inFlight = true;
                    setStatus('Saving\u2026', 'text-muted');
                    fetch(autosaveUrl, {
                      method: 'POST', body: fd, credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                      .then(r => r.ok ? r.json() : Promise.reject())
                      .then(d => {
                        if (d && d.ok) {
                          lastPayload = s;
                          setStatus('Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }), 'text-success');
                        } else throw new Error();
                      })
                      .catch(() => setStatus('Offline \u2014 will retry', 'text-warning'))
                      .finally(() => {
                        inFlight = false;
                        if (pendingWhileFlight) { pendingWhileFlight = false; doAutosave(); }
                      });
                  };

                  const scheduleAutosave = () => {
                    if (debounceId) clearTimeout(debounceId);
                    debounceId = setTimeout(doAutosave, 900);
                  };

                  const flushAutosave = (syncFallback) => {
                    if (debounceId) { clearTimeout(debounceId); debounceId = null; }
                    const fd = buildFd();
                    const s = sig(fd);
                    if (s === lastPayload) return;
                    if (navigator.sendBeacon) {
                      try { if (navigator.sendBeacon(autosaveUrl, fd)) { lastPayload = s; return; } } catch (_) {}
                    }
                    if (syncFallback) {
                      try { const x = new XMLHttpRequest(); x.open('POST', autosaveUrl, false); x.send(fd); lastPayload = s; } catch (_) {}
                    }
                  };

                  form.addEventListener('input', scheduleAutosave);
                  form.addEventListener('change', scheduleAutosave);
                  setInterval(doAutosave, 20000);
                  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushAutosave(false); });
                  window.addEventListener('pagehide', () => flushAutosave(false));
                  window.addEventListener('beforeunload', () => flushAutosave(false));
                  form.addEventListener('submit', () => { submitted = true; });
                  lastPayload = sig(buildFd());
                  setStatus('Saved', 'text-success');

                  if (typeof window.clmsInitExamStepper === 'function') {
                    window.clmsInitExamStepper({
                      formSelector: '#mockExamForm',
                      navMapModalId: 'clmsMockExamNavModal',
                      reviewModalId: 'clmsMockExamReviewModal',
                    });
                  }
                })();
              </script>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';
