<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/exam_grading.php';

clms_require_roles(['student']);

/*
 * Ensure the columns we rely on exist. Two migrations:
 *   - courses.final_exam_duration_minutes  : admin/instructor-configurable
 *                                           time limit for each course.
 *   - exam_attempts.deadline_at            : per-attempt snapshot of the
 *                                           absolute deadline, frozen when
 *                                           the attempt is created. This is
 *                                           what removes the "admin edits
 *                                           the time while a student is
 *                                           taking the exam" conflict —
 *                                           once an attempt exists, its
 *                                           deadline is fixed.
 */
try {
    $examDurationColumn = $pdo->query("SHOW COLUMNS FROM courses LIKE 'final_exam_duration_minutes'")->fetch();
    if (!$examDurationColumn) {
        $pdo->exec('ALTER TABLE courses ADD COLUMN final_exam_duration_minutes INT NOT NULL DEFAULT 45');
    }
} catch (Throwable $e) {
    error_log('take_exam: final_exam_duration_minutes migration failed: ' . $e->getMessage());
}

try {
    $deadlineColumn = $pdo->query("SHOW COLUMNS FROM exam_attempts LIKE 'deadline_at'")->fetch();
    if (!$deadlineColumn) {
        $pdo->exec('ALTER TABLE exam_attempts ADD COLUMN deadline_at DATETIME NULL AFTER attempted_at');
    }
} catch (Throwable $e) {
    error_log('take_exam: exam_attempts.deadline_at migration failed: ' . $e->getMessage());
}

$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if ($courseId === false || $courseId === null || $courseId <= 0) {
    clms_redirect('student/dashboard.php');
}

$courseStmt = $pdo->prepare(
    'SELECT id, title, COALESCE(final_exam_duration_minutes, 45) AS final_exam_duration_minutes
     FROM courses
     WHERE id = :course_id AND is_published = 1
     LIMIT 1'
);
$courseStmt->execute(['course_id' => $courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    clms_redirect('student/dashboard.php');
}

$enrollmentCheckStmt = $pdo->prepare(
    'SELECT 1
     FROM courses c
     INNER JOIN modules m ON m.course_id = c.id
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id_progress
     LEFT JOIN exam_attempts ea ON ea.course_id = c.id AND ea.user_id = :user_id_attempt
     LEFT JOIN certificates cert ON cert.course_id = c.id AND cert.user_id = :user_id_certificate
     WHERE c.id = :course_id
       AND (up.id IS NOT NULL OR ea.id IS NOT NULL OR cert.id IS NOT NULL)
     LIMIT 1'
);
$enrollmentCheckStmt->execute([
    'user_id_progress' => (int) $_SESSION['user_id'],
    'user_id_attempt' => (int) $_SESSION['user_id'],
    'user_id_certificate' => (int) $_SESSION['user_id'],
    'course_id' => (int) $courseId,
]);
if (!$enrollmentCheckStmt->fetch()) {
    clms_redirect('student/dashboard.php');
}

$moduleStatusStmt = $pdo->prepare(
    'SELECT m.id, COALESCE(up.is_completed, 0) AS is_completed
     FROM modules m
     LEFT JOIN user_progress up ON up.module_id = m.id AND up.user_id = :user_id
     WHERE m.course_id = :course_id'
);
$moduleStatusStmt->execute([
    'user_id' => (int) $_SESSION['user_id'],
    'course_id' => (int) $courseId,
]);
$moduleRows = $moduleStatusStmt->fetchAll();
if ($moduleRows === []) {
    clms_redirect('student/dashboard.php');
}
foreach ($moduleRows as $mRow) {
    if ((int) $mRow['is_completed'] !== 1) {
        clms_redirect('student/dashboard.php?notice=modules_incomplete');
    }
}

$questionsStmt = $pdo->prepare(
    'SELECT q.id, q.question_text, q.question_type, q.points, a.id AS answer_id, a.answer_text
     FROM questions q
     LEFT JOIN answers a ON a.question_id = q.id
     WHERE q.course_id = :course_id
     ORDER BY q.id ASC, a.id ASC'
);
$questionsStmt->execute(['course_id' => $courseId]);
$rows = $questionsStmt->fetchAll();

if ($rows === []) {
    clms_redirect('student/dashboard.php?notice=no_exam_questions');
}

$questions = [];
foreach ($rows as $row) {
    $qId = (int) $row['id'];
    if (!isset($questions[$qId])) {
        $questions[$qId] = [
            'id' => $qId,
            'question_text' => (string) $row['question_text'],
            'question_type' => (string) $row['question_type'],
            'points' => (float) $row['points'],
            'answers' => [],
        ];
    }
    if ($row['answer_id'] !== null) {
        $questions[$qId]['answers'][] = [
            'id' => (int) $row['answer_id'],
            'answer_text' => (string) $row['answer_text'],
        ];
    }
}

$durationMinutes = (int) ($course['final_exam_duration_minutes'] ?? 45);
if ($durationMinutes < 1) {
    $durationMinutes = 45;
}
$durationSeconds = $durationMinutes * 60;

/*
 * IMPORTANT — use UNIX_TIMESTAMP() directly from MySQL so the timestamp
 * matches real epoch seconds regardless of the PHP vs MySQL timezone
 * settings (strtotime() on a TIMESTAMP string was producing deadlines
 * that were hours off).
 */
$attemptStmt = $pdo->prepare(
    'SELECT id,
            UNIX_TIMESTAMP(attempted_at) AS attempted_at_unix,
            UNIX_TIMESTAMP(deadline_at)  AS deadline_at_unix
     FROM exam_attempts
     WHERE user_id = :user_id
       AND course_id = :course_id
       AND status = :status
     ORDER BY id DESC
     LIMIT 1'
);
$attemptStmt->execute([
    'user_id' => (int) $_SESSION['user_id'],
    'course_id' => (int) $courseId,
    'status' => 'in_progress',
]);
$attempt = $attemptStmt->fetch();

if (!$attempt) {
    // Fresh attempt — snapshot the deadline *now* using the current course
    // duration. Any later admin/instructor edit to the course time limit
    // will not retroactively change this attempt.
    $insertAttemptStmt = $pdo->prepare(
        'INSERT INTO exam_attempts (user_id, course_id, status, total_score, is_passed, deadline_at)
         VALUES (:user_id, :course_id, :status, 0.00, 0, DATE_ADD(NOW(), INTERVAL :duration MINUTE))'
    );
    $insertAttemptStmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'course_id' => (int) $courseId,
        'status' => 'in_progress',
        'duration' => $durationMinutes,
    ]);
    $attemptId = (int) $pdo->lastInsertId();

    $attemptStmt = $pdo->prepare(
        'SELECT id,
                UNIX_TIMESTAMP(attempted_at) AS attempted_at_unix,
                UNIX_TIMESTAMP(deadline_at)  AS deadline_at_unix
         FROM exam_attempts WHERE id = :attempt_id LIMIT 1'
    );
    $attemptStmt->execute(['attempt_id' => $attemptId]);
    $attempt = $attemptStmt->fetch();
    if (!$attempt) {
        throw new RuntimeException('Unable to start exam attempt.');
    }
}

$attemptId = (int) $attempt['id'];
$attemptedAt = (int) ($attempt['attempted_at_unix'] ?? 0);
if ($attemptedAt <= 0) {
    $attemptedAt = time();
}

/*
 * Authoritative deadline comes from the attempt row. Legacy in-progress
 * attempts created before the deadline_at column existed fall back to
 * attempted_at + the *current* course duration, and we backfill the
 * column so future loads are stable.
 */
$examEndAtUnix = (int) ($attempt['deadline_at_unix'] ?? 0);
if ($examEndAtUnix <= 0) {
    $examEndAtUnix = $attemptedAt + $durationSeconds;
    try {
        $backfillStmt = $pdo->prepare(
            'UPDATE exam_attempts
             SET deadline_at = FROM_UNIXTIME(:deadline_unix)
             WHERE id = :attempt_id
               AND deadline_at IS NULL'
        );
        $backfillStmt->execute([
            'deadline_unix' => $examEndAtUnix,
            'attempt_id' => $attemptId,
        ]);
    } catch (Throwable $e) {
        error_log('take_exam: deadline_at backfill failed: ' . $e->getMessage());
    }
}

/*
 * SAFETY NET — if the attempt is already past its deadline when the page
 * loads, grade it server-side using whatever was autosaved and render
 * the result inline. This is what prevents the "Saved 00:00" stuck
 * state when the student opens an attempt whose time ran out while the
 * tab was closed (or when an instructor/admin shortened the course's
 * duration after the attempt had already started, under legacy rows
 * that don't yet have a frozen deadline_at).
 */
if (time() >= $examEndAtUnix) {
    $finalizeResult = clms_finalize_exam_attempt(
        $pdo,
        $attemptId,
        (int) $courseId,
        (int) $_SESSION['user_id'],
        null
    );

    if ($finalizeResult['ok']) {
        $summary = $finalizeResult['summary'];
        if (($summary['certificate_just_issued'] ?? false) && !empty($summary['certificate_hash'])) {
            clms_notify_certificate_webhook(
                (string) ($_SESSION['email'] ?? ''),
                (string) $summary['certificate_hash']
            );
        }

        // Redirect to the stable GET result URL. This mirrors the
        // Post/Redirect/Get pattern used after manual submission — a
        // refresh here now re-renders the stored result instead of
        // starting a brand-new in_progress attempt.
        clms_redirect('student/grade_exam.php'
            . '?attempt_id=' . (int) $attemptId
            . '&course_id=' . (int) $courseId
            . '&auto=1');
    }

    // Grading the stale attempt somehow failed (e.g. no questions at all
    // for the course) — send the student to the dashboard rather than
    // looping them back into a 00:00 exam page.
    clms_redirect('student/dashboard.php?notice=exam_error');
}

/*
 * Show the *actual* remaining time on first paint. If the student reloads
 * the page 10 minutes into a 45-minute exam, we want the badge to read
 * 35:00 immediately — not flash 45:00 and then snap back once the JS
 * clock ticks.
 */
$remainingSeconds = max(0, $examEndAtUnix - time());
$initialRemainingLabel = sprintf(
    '%02d:%02d',
    intdiv($remainingSeconds, 60),
    $remainingSeconds % 60
);

/*
 * Pre-fill from autosave: any responses we've already captured for this
 * in-progress attempt are replayed into the rendered inputs below.
 */
$savedResponsesStmt = $pdo->prepare(
    'SELECT question_id, selected_answer_id, text_response, submitted_sequence_position
     FROM student_responses
     WHERE attempt_id = :attempt_id'
);
$savedResponsesStmt->execute(['attempt_id' => $attemptId]);
$savedResponsesRows = $savedResponsesStmt->fetchAll();

$savedSelectedIds = [];      // question_id => [answer_id, ...]   (single/multi)
$savedTexts = [];            // question_id => string             (fill/essay)
$savedSequence = [];         // question_id => [answer_id => pos]
foreach ($savedResponsesRows as $savedRow) {
    $qid = (int) $savedRow['question_id'];
    if ($savedRow['selected_answer_id'] !== null && $savedRow['submitted_sequence_position'] !== null) {
        $savedSequence[$qid][(int) $savedRow['selected_answer_id']] = (int) $savedRow['submitted_sequence_position'];
    } elseif ($savedRow['selected_answer_id'] !== null) {
        $savedSelectedIds[$qid][] = (int) $savedRow['selected_answer_id'];
    } elseif ($savedRow['text_response'] !== null && $savedRow['text_response'] !== '') {
        $savedTexts[$qid] = (string) $savedRow['text_response'];
    }
}

$pageTitle = 'Take Exam | Criminology LMS';
$activeStudentPage = 'dashboard';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <h4 class="fw-bold py-3 mb-2">Course Exam</h4>
              <p class="mb-1 text-muted"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p class="mb-4 small text-muted">
                Time limit set by instructor: <strong><?php echo (int) $durationMinutes; ?> minute<?php echo $durationMinutes === 1 ? '' : 's'; ?></strong>.
                Your answers are saved automatically while you work.
              </p>

              <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                <span class="fw-semibold">Time Remaining</span>
                <div class="d-flex align-items-center gap-3">
                  <span class="small text-muted" id="examAutosaveStatus" aria-live="polite">Saved</span>
                  <span class="badge bg-label-danger fs-6" id="examTimer"><?php echo htmlspecialchars($initialRemainingLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              </div>

              <form id="examForm" action="<?php echo htmlspecialchars($clmsWebBase . '/student/grade_exam.php', ENT_QUOTES, 'UTF-8'); ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="course_id" value="<?php echo (int) $courseId; ?>" />
                <input type="hidden" name="attempt_id" value="<?php echo $attemptId; ?>" />

<?php foreach ($questions as $index => $question) : ?>
                <div class="card mb-4">
                  <div class="card-header">
                    <strong>Question <?php echo $index + 1; ?></strong>
                    <span class="badge bg-label-primary ms-2"><?php echo number_format((float) $question['points'], 2); ?> pts</span>
                  </div>
                  <div class="card-body">
                    <p class="mb-3"><?php echo nl2br(htmlspecialchars((string) $question['question_text'], ENT_QUOTES, 'UTF-8')); ?></p>

<?php
                    $qid = (int) $question['id'];
                    $qSelected = $savedSelectedIds[$qid] ?? [];
                    $qText = $savedTexts[$qid] ?? '';
                    $qSequence = $savedSequence[$qid] ?? [];
?>
<?php if ($question['question_type'] === 'single_choice' || $question['question_type'] === 'true_false') : ?>
<?php foreach ($question['answers'] as $answer) : ?>
                    <div class="form-check mb-2">
                      <input
                        class="form-check-input"
                        type="radio"
                        name="responses[<?php echo $qid; ?>][single]"
                        id="q<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>"
                        value="<?php echo (int) $answer['id']; ?>"
                        <?php echo in_array((int) $answer['id'], $qSelected, true) ? 'checked' : ''; ?> />
                      <label class="form-check-label" for="q<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>">
                        <?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                      </label>
                    </div>
<?php endforeach; ?>

<?php elseif ($question['question_type'] === 'multiple_select') : ?>
<?php foreach ($question['answers'] as $answer) : ?>
                    <div class="form-check mb-2">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        name="responses[<?php echo $qid; ?>][multiple][]"
                        id="q<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>"
                        value="<?php echo (int) $answer['id']; ?>"
                        <?php echo in_array((int) $answer['id'], $qSelected, true) ? 'checked' : ''; ?> />
                      <label class="form-check-label" for="q<?php echo $qid; ?>_a<?php echo (int) $answer['id']; ?>">
                        <?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                      </label>
                    </div>
<?php endforeach; ?>

<?php elseif ($question['question_type'] === 'fill_blank') : ?>
                    <input
                      type="text"
                      class="form-control"
                      name="responses[<?php echo $qid; ?>][text]"
                      placeholder="Enter your answer"
                      value="<?php echo htmlspecialchars($qText, ENT_QUOTES, 'UTF-8'); ?>" />

<?php elseif ($question['question_type'] === 'essay') : ?>
                    <textarea
                      class="form-control"
                      rows="4"
                      name="responses[<?php echo $qid; ?>][text]"
                      placeholder="Type your essay response here"><?php echo htmlspecialchars($qText, ENT_QUOTES, 'UTF-8'); ?></textarea>

<?php elseif ($question['question_type'] === 'sequencing') : ?>
                    <p class="small text-muted mb-3">Set the correct order for each item.</p>
<?php $orderMax = count($question['answers']); ?>
<?php foreach ($question['answers'] as $answer) : ?>
                    <div class="row align-items-center mb-3">
                      <div class="col-md-8">
                        <label class="form-label mb-0" for="q<?php echo $qid; ?>_seq_<?php echo (int) $answer['id']; ?>">
                          <?php echo htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                      </div>
                      <div class="col-md-4">
<?php $savedPos = $qSequence[(int) $answer['id']] ?? null; ?>
                        <select
                          class="form-select"
                          id="q<?php echo $qid; ?>_seq_<?php echo (int) $answer['id']; ?>"
                          name="responses[<?php echo $qid; ?>][sequence][<?php echo (int) $answer['id']; ?>]">
                          <option value="">Select order</option>
<?php for ($i = 1; $i <= $orderMax; $i++) : ?>
                          <option value="<?php echo $i; ?>" <?php echo $savedPos === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
<?php endfor; ?>
                        </select>
                      </div>
                    </div>
<?php endforeach; ?>
<?php endif; ?>
                  </div>
                </div>
<?php endforeach; ?>

                <button type="submit" class="btn btn-primary">Submit Exam</button>
              </form>

              <script>
                (() => {
                  const form = document.getElementById('examForm');
                  const timerEl = document.getElementById('examTimer');
                  const statusEl = document.getElementById('examAutosaveStatus');
                  if (!form || !timerEl) return;

                  const attemptId = <?php echo (int) $attemptId; ?>;
                  const courseId = <?php echo (int) $courseId; ?>;
                  // Server-authoritative deadline as a real Unix epoch. Matches
                  // both PHP time() and JS Date.now()/1000 regardless of the
                  // DB's or browser's local timezone.
                  const deadline = <?php echo (int) $examEndAtUnix; ?>;
                  const autosaveUrl = <?php echo json_encode($clmsWebBase . '/student/autosave_exam.php', JSON_UNESCAPED_SLASHES); ?>;
                  const csrfToken = <?php echo json_encode(clms_csrf_token()); ?>;

                  /* ------------------------------------------------------------
                     Countdown
                  ------------------------------------------------------------ */
                  const pad = (n) => String(n).padStart(2, '0');
                  const formatTime = (seconds) => `${pad(Math.floor(seconds / 60))}:${pad(seconds % 60)}`;

                  let submitted = false;
                  // NOTE: declared with `let` *before* `tick()` is ever called
                  // so the first call can safely reference it. Previously this
                  // was `const intervalId = setInterval(...)` placed AFTER the
                  // first `tick()`, which put the variable in the temporal
                  // dead zone — the first tick threw a ReferenceError and
                  // silently killed the whole script (breaking auto-submit
                  // and autosave). That's what caused the "Saved 00:00"
                  // stuck state for attempts whose deadline had already
                  // elapsed on page load.
                  let intervalId = null;

                  const autoSubmit = () => {
                    if (submitted) return;
                    submitted = true;
                    flushAutosave(true);
                    form.submit();
                  };

                  const tick = () => {
                    const remaining = Math.max(0, deadline - Math.floor(Date.now() / 1000));
                    timerEl.textContent = formatTime(remaining);
                    if (remaining <= 60) {
                      timerEl.classList.remove('bg-label-danger');
                      timerEl.classList.add('bg-danger', 'text-white');
                    }
                    if (remaining <= 0) {
                      if (intervalId !== null) {
                        clearInterval(intervalId);
                        intervalId = null;
                      }
                      autoSubmit();
                    }
                  };

                  tick();
                  intervalId = setInterval(tick, 1000);

                  /* ------------------------------------------------------------
                     Autosave
                     Collects the form state, posts it as multipart form-data,
                     debounced on every change and on a safety-net interval.
                     Also flushes on visibilitychange/pagehide so a tab close
                     or navigation doesn't drop the latest answers.
                  ------------------------------------------------------------ */
                  let debounceId = null;
                  let lastPayload = '';
                  let inFlight = false;
                  let pendingWhileFlight = false;

                  const setStatus = (text, tone) => {
                    if (!statusEl) return;
                    statusEl.textContent = text;
                    statusEl.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
                    statusEl.classList.add(tone || 'text-muted');
                  };

                  // Serialise the form into a FormData we can reuse for both
                  // fetch() and sendBeacon(), plus a string signature we use
                  // to skip no-op saves.
                  const buildFormData = () => {
                    const fd = new FormData(form);
                    fd.set('csrf_token', csrfToken);
                    fd.set('attempt_id', String(attemptId));
                    fd.set('course_id', String(courseId));
                    return fd;
                  };
                  const signature = (fd) => {
                    const parts = [];
                    for (const [k, v] of fd.entries()) {
                      if (k === 'csrf_token') continue;
                      parts.push(`${k}=${typeof v === 'string' ? v : ''}`);
                    }
                    return parts.sort().join('&');
                  };

                  const doAutosave = () => {
                    if (submitted) return;
                    const fd = buildFormData();
                    const sig = signature(fd);
                    if (sig === lastPayload) return; // nothing changed
                    if (inFlight) { pendingWhileFlight = true; return; }

                    inFlight = true;
                    setStatus('Saving\u2026', 'text-muted');
                    fetch(autosaveUrl, {
                      method: 'POST',
                      body: fd,
                      credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                      .then((res) => res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status)))
                      .then((data) => {
                        if (data && data.ok) {
                          lastPayload = sig;
                          const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                          setStatus(`Saved ${time}`, 'text-success');
                        } else {
                          throw new Error((data && data.error) || 'Save failed');
                        }
                      })
                      .catch(() => {
                        setStatus('Offline \u2014 will retry', 'text-warning');
                      })
                      .finally(() => {
                        inFlight = false;
                        if (pendingWhileFlight) {
                          pendingWhileFlight = false;
                          doAutosave();
                        }
                      });
                  };

                  const scheduleAutosave = () => {
                    if (debounceId) clearTimeout(debounceId);
                    debounceId = setTimeout(doAutosave, 900);
                  };

                  // Best-effort synchronous flush via sendBeacon. Used on
                  // pagehide / auto-submit so the last keystrokes still reach
                  // the server when the tab is on its way out.
                  const flushAutosave = (syncFallback) => {
                    if (debounceId) { clearTimeout(debounceId); debounceId = null; }
                    const fd = buildFormData();
                    const sig = signature(fd);
                    if (sig === lastPayload) return;
                    if (navigator.sendBeacon) {
                      try {
                        if (navigator.sendBeacon(autosaveUrl, fd)) {
                          lastPayload = sig;
                          return;
                        }
                      } catch (_) { /* fall through */ }
                    }
                    if (syncFallback) {
                      try {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', autosaveUrl, false); // sync as last resort
                        xhr.send(fd);
                        lastPayload = sig;
                      } catch (_) { /* ignore */ }
                    }
                  };

                  form.addEventListener('input', scheduleAutosave);
                  form.addEventListener('change', scheduleAutosave);

                  // Periodic safety net — every 20s even if the user isn't
                  // actively typing (covers "picks radio once, sits and reads").
                  setInterval(doAutosave, 20000);

                  document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'hidden') flushAutosave(false);
                  });
                  window.addEventListener('pagehide', () => flushAutosave(false));
                  window.addEventListener('beforeunload', () => flushAutosave(false));

                  // Don't double-save when the student explicitly submits.
                  form.addEventListener('submit', () => { submitted = true; });

                  // Seed "lastPayload" with the pre-filled state so we don't
                  // fire an identical save immediately on mount.
                  lastPayload = signature(buildFormData());
                  setStatus('Saved', 'text-success');
                })();
              </script>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';

