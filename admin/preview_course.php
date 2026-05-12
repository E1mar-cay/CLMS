<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';
require_once dirname(__DIR__) . '/includes/course-publish-schema.php';
require_once dirname(__DIR__) . '/includes/clms-exam-types-schema.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);
clms_ensure_course_publish_schema($pdo);
clms_ensure_exam_types_schema($pdo);

$pageTitle = 'Preview Course | Admin';
$activeAdminPage = 'courses';
$errorMessage = '';
$successMessage = '';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$courseIdRaw = $_GET['course_id'] ?? null;
$courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
$courseId = $courseId === false ? 0 : (int) $courseId;

if ($courseId <= 0) {
    clms_redirect('admin/courses.php');
}

/*
 * Approve / reject inline so the admin can act without bouncing back
 * to the courses list. Mirrors the workflow rules in admin/courses.php.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            $statusStmt = $pdo->prepare(
                "SELECT title, COALESCE(publish_status, 'draft') AS publish_status FROM courses WHERE id = :id LIMIT 1"
            );
            $statusStmt->execute(['id' => $courseId]);
            $statusRow = $statusStmt->fetch();
            if (!$statusRow) {
                throw new RuntimeException('Course not found.');
            }
            $currentStatus = (string) $statusRow['publish_status'];

            if ($action === 'approve_publish') {
                if ($currentStatus !== 'pending_review') {
                    throw new RuntimeException('Only courses waiting for review can be approved.');
                }
                $stmt = $pdo->prepare(
                    "UPDATE courses
                        SET publish_status = 'published',
                            is_published = 1,
                            publish_reviewed_at = NOW(),
                            publish_reviewed_by = :uid,
                            publish_review_notes = NULL
                      WHERE id = :id"
                );
                $stmt->execute(['uid' => $currentUserId ?: null, 'id' => $courseId]);
                clms_audit_ensure_schema($pdo);
                clms_audit_log(
                    $pdo,
                    'course_approve_publish',
                    'course',
                    $courseId,
                    ['title' => (string) $statusRow['title'], 'previous_status' => $currentStatus],
                    $currentUserId
                );
                clms_redirect('admin/courses.php?flash=approved');
            } elseif ($action === 'reject_publish') {
                if ($currentStatus !== 'pending_review') {
                    throw new RuntimeException('Only courses waiting for review can be sent back for changes.');
                }
                $notes = trim((string) ($_POST['publish_review_notes'] ?? ''));
                if ($notes === '') {
                    throw new RuntimeException('Please include a short note explaining what needs to change.');
                }
                if (mb_strlen($notes) > 2000) {
                    throw new RuntimeException('Review notes must be 2000 characters or fewer.');
                }
                $stmt = $pdo->prepare(
                    "UPDATE courses
                        SET publish_status = 'changes_requested',
                            is_published = 0,
                            publish_reviewed_at = NOW(),
                            publish_reviewed_by = :uid,
                            publish_review_notes = :notes
                      WHERE id = :id"
                );
                $stmt->execute(['uid' => $currentUserId ?: null, 'notes' => $notes, 'id' => $courseId]);
                clms_audit_ensure_schema($pdo);
                clms_audit_log(
                    $pdo,
                    'course_reject_publish',
                    'course',
                    $courseId,
                    ['title' => (string) $statusRow['title'], 'previous_status' => $currentStatus],
                    $currentUserId
                );
                clms_redirect('admin/courses.php?flash=rejected');
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Operation failed. Please try again.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

$courseStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.title,
        c.description,
        c.passing_score_percentage,
        c.is_published,
        COALESCE(c.publish_status, 'draft') AS publish_status,
        c.publish_submitted_at,
        c.publish_reviewed_at,
        c.publish_review_notes,
        TRIM(CONCAT(COALESCE(sb.first_name, ''), ' ', COALESCE(sb.last_name, ''))) AS publish_submitted_by_name,
        TRIM(CONCAT(COALESCE(rb.first_name, ''), ' ', COALESCE(rb.last_name, ''))) AS publish_reviewed_by_name,
        c.level,
        c.thumbnail_url,
        COALESCE(c.final_exam_duration_minutes, 45) AS final_exam_duration_minutes
     FROM courses c
     LEFT JOIN users sb ON sb.id = c.publish_submitted_by
     LEFT JOIN users rb ON rb.id = c.publish_reviewed_by
     WHERE c.id = :id
     LIMIT 1"
);
$courseStmt->execute(['id' => $courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    clms_redirect('admin/courses.php');
}

$publishStatus = (string) ($course['publish_status'] ?? 'draft');
$publishMeta = clms_course_publish_status_meta($publishStatus);

$modulesStmt = $pdo->prepare(
    'SELECT id, title, video_url, duration_minutes, sequence_order
       FROM modules
      WHERE course_id = :cid
      ORDER BY sequence_order ASC, id ASC'
);
$modulesStmt->execute(['cid' => $courseId]);
$modules = $modulesStmt->fetchAll();

$questionsStmt = $pdo->prepare(
    'SELECT id, question_text, question_type, points, module_id, exam_type_id
       FROM questions
      WHERE course_id = :cid
      ORDER BY id ASC'
);
$questionsStmt->execute(['cid' => $courseId]);
$questions = $questionsStmt->fetchAll();

$answersByQuestion = [];
if ($questions !== []) {
    $ids = array_map(static fn ($q): int => (int) $q['id'], $questions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $answersStmt = $pdo->prepare(
        "SELECT id, question_id, answer_text, is_correct, sequence_position
           FROM answers
          WHERE question_id IN ($placeholders)
          ORDER BY question_id ASC,
                   CASE WHEN sequence_position IS NULL THEN 1 ELSE 0 END,
                   sequence_position ASC,
                   id ASC"
    );
    $answersStmt->execute($ids);
    foreach ($answersStmt->fetchAll() as $ans) {
        $answersByQuestion[(int) $ans['question_id']][] = $ans;
    }
}

$examTypesById = [];
try {
    $examTypeStmt = $pdo->query('SELECT id, name FROM exam_types ORDER BY sort_order ASC, name ASC');
    foreach ($examTypeStmt->fetchAll() as $et) {
        $examTypesById[(int) $et['id']] = (string) $et['name'];
    }
} catch (Throwable $e) {
    error_log('preview_course exam_types fetch: ' . $e->getMessage());
}

/*
 * Group questions for display:
 *   - module:<module_id>     → questions tied to that module quiz
 *   - exam_type:<exam_id>    → questions for the typed exam (mock, etc.)
 *   - final                  → final-exam pool (no module, no exam type)
 */
$groupedQuestions = [
    'final' => [],
    'modules' => [],
    'exam_types' => [],
];
foreach ($questions as $q) {
    $moduleId = $q['module_id'] !== null ? (int) $q['module_id'] : null;
    $examTypeId = $q['exam_type_id'] !== null ? (int) $q['exam_type_id'] : null;
    if ($moduleId !== null && $moduleId > 0) {
        $groupedQuestions['modules'][$moduleId][] = $q;
    } elseif ($examTypeId !== null && $examTypeId > 0) {
        $groupedQuestions['exam_types'][$examTypeId][] = $q;
    } else {
        $groupedQuestions['final'][] = $q;
    }
}

$questionTypeLabel = static function (string $type): string {
    return match ($type) {
        'multiple_select' => 'Multiple choice',
        'true_false'      => 'True / False',
        'fill_blank'      => 'Fill in the blank',
        'sequencing'      => 'Sequencing',
        default           => ucfirst(str_replace('_', ' ', $type)),
    };
};

$resolveThumbnailUrl = static function (?string $rawPath) use ($clmsWebBase): string {
    $path = trim((string) $rawPath);
    if ($path === '') return '';
    if (preg_match('/^(https?:)?\/\//i', $path) === 1 || str_starts_with($path, 'data:')) return $path;
    if (str_starts_with($path, '/')) return rtrim((string) $clmsWebBase, '/') . $path;
    return rtrim((string) $clmsWebBase, '/') . '/' . ltrim($path, '/');
};

$totalQuestions = count($questions);
$totalModules = count($modules);
$totalPoints = array_sum(array_map(static fn ($q): int => (int) $q['points'], $questions));
$canApproveOrReject = $publishStatus === 'pending_review';

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start py-3 mb-3 gap-2">
  <div class="flex-grow-1" style="min-width: 0;">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-1">
        <li class="breadcrumb-item">
          <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>">Courses</a>
        </li>
        <li class="breadcrumb-item active">Preview</li>
      </ol>
    </nav>
    <h4 class="fw-bold mb-1 d-flex align-items-center gap-2 flex-wrap">
      <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
      <span class="badge <?php echo htmlspecialchars($publishMeta['badge'], ENT_QUOTES, 'UTF-8'); ?>">
        <i class="bx <?php echo htmlspecialchars($publishMeta['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
        <?php echo htmlspecialchars($publishMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </h4>
    <small class="text-muted">
      Read-only review. Use the buttons on the right to approve or send back for changes.
    </small>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bx bx-arrow-back me-1"></i>Back to courses
    </a>
    <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . $courseId, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info btn-sm">
      <i class="bx bx-list-ul me-1"></i>Open question builder
    </a>
<?php if ($canApproveOrReject) : ?>
    <form method="post" class="d-inline js-approve-publish-form">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <input type="hidden" name="action" value="approve_publish" />
      <button type="submit" class="btn btn-success btn-sm">
        <i class="bx bx-check me-1"></i>Approve &amp; publish
      </button>
    </form>
    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectPublishModal">
      <i class="bx bx-x me-1"></i>Send back
    </button>
<?php endif; ?>
  </div>
</div>

<?php if ($publishStatus === 'changes_requested' && trim((string) ($course['publish_review_notes'] ?? '')) !== '') : ?>
<div class="alert alert-danger mb-3">
  <strong><i class="bx bx-message-detail me-1"></i>Previously sent back:</strong>
  <div class="small mt-1"><?php echo nl2br(htmlspecialchars((string) $course['publish_review_notes'], ENT_QUOTES, 'UTF-8')); ?></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex gap-3 align-items-start">
<?php if (!empty($course['thumbnail_url'])) : ?>
          <img src="<?php echo htmlspecialchars($resolveThumbnailUrl((string) $course['thumbnail_url']), ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:.5rem;border:1px solid rgba(15, 32, 75, .12);" onerror="this.style.display='none';" />
<?php endif; ?>
          <div class="flex-grow-1" style="min-width: 0;">
            <h5 class="mb-1"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
<?php if (!empty($course['level'])) : ?>
            <span class="badge bg-label-secondary text-uppercase me-1"><?php echo htmlspecialchars((string) $course['level'], ENT_QUOTES, 'UTF-8'); ?></span>
<?php endif; ?>
            <span class="badge bg-label-primary me-1">Passing: <?php echo number_format((float) $course['passing_score_percentage'], 2); ?>%</span>
            <span class="badge bg-label-info">Final exam: <?php echo (int) $course['final_exam_duration_minutes']; ?> min</span>
<?php if (trim((string) ($course['description'] ?? '')) !== '') : ?>
            <p class="mt-2 mb-0 text-muted small"><?php echo nl2br(htmlspecialchars((string) $course['description'], ENT_QUOTES, 'UTF-8')); ?></p>
<?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="text-muted text-uppercase small fw-semibold mb-3">Summary</h6>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Modules</span>
          <strong><?php echo $totalModules; ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Questions</span>
          <strong><?php echo $totalQuestions; ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Total points</span>
          <strong><?php echo $totalPoints; ?></strong>
        </div>
<?php if (!empty($course['publish_submitted_by_name'])) : ?>
        <hr class="my-2">
        <div class="small text-muted">
          Submitted by <strong class="text-body"><?php echo htmlspecialchars((string) $course['publish_submitted_by_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
<?php if (!empty($course['publish_submitted_at'])) : ?>
          on <?php echo htmlspecialchars(date('M j, Y g:i a', strtotime((string) $course['publish_submitted_at']) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
<?php endif; ?>
        </div>
<?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <h5 class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bx bx-folder me-1"></i>Modules <span class="text-muted small">(<?php echo $totalModules; ?>)</span></span>
  </h5>
  <div class="card-body p-0">
<?php if ($modules === []) : ?>
    <p class="m-3 text-muted mb-0"><i class="bx bx-error-circle me-1"></i>No modules added yet.</p>
<?php else : ?>
    <ol class="list-group list-group-flush mb-0" style="counter-reset: clms-modnum;">
<?php foreach ($modules as $mod) :
        $moduleQuestions = $groupedQuestions['modules'][(int) $mod['id']] ?? [];
?>
      <li class="list-group-item">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div class="flex-grow-1" style="min-width: 0;">
            <div class="fw-semibold">
              <span class="text-muted me-1">#<?php echo (int) $mod['sequence_order']; ?></span>
              <?php echo htmlspecialchars((string) $mod['title'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
<?php if (!empty($mod['video_url'])) : ?>
            <small class="text-muted d-block text-truncate" style="max-width: 100%;">
              <i class="bx bx-link-external me-1"></i>
              <a href="<?php echo htmlspecialchars((string) $mod['video_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="text-muted">
                <?php echo htmlspecialchars((string) $mod['video_url'], ENT_QUOTES, 'UTF-8'); ?>
              </a>
            </small>
<?php else : ?>
            <small class="text-danger d-block"><i class="bx bx-error-circle me-1"></i>No video URL set.</small>
<?php endif; ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
<?php if (!empty($mod['duration_minutes'])) : ?>
            <span class="badge bg-label-secondary"><?php echo (int) $mod['duration_minutes']; ?> min</span>
<?php endif; ?>
            <span class="badge <?php echo count($moduleQuestions) > 0 ? 'bg-label-info' : 'bg-label-warning'; ?>">
              <?php echo count($moduleQuestions); ?> module quiz Q
            </span>
          </div>
        </div>
      </li>
<?php endforeach; ?>
    </ol>
<?php endif; ?>
  </div>
</div>

<?php
$renderQuestionList = static function (array $list) use ($answersByQuestion, $questionTypeLabel): void {
    if ($list === []) {
        echo '<p class="m-3 text-muted mb-0"><i class="bx bx-info-circle me-1"></i>No questions in this group.</p>';
        return;
    }
    echo '<ol class="list-group list-group-flush mb-0">';
    foreach ($list as $idx => $q) {
        $qid = (int) $q['id'];
        $qType = (string) $q['question_type'];
        $answers = $answersByQuestion[$qid] ?? [];
        echo '<li class="list-group-item">';
        echo '<div class="d-flex justify-content-between flex-wrap gap-2 mb-2">';
        echo '<div class="flex-grow-1" style="min-width: 0;">';
        echo '<div class="d-flex align-items-center gap-2 mb-1">';
        echo '<span class="badge bg-label-secondary">Q' . ($idx + 1) . '</span>';
        echo '<span class="badge bg-label-primary">' . htmlspecialchars($questionTypeLabel($qType), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<span class="badge bg-label-info">' . (int) $q['points'] . ' pt</span>';
        echo '</div>';
        echo '<div class="fw-semibold">' . nl2br(htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8')) . '</div>';
        echo '</div>';
        echo '</div>';

        if ($answers === []) {
            echo '<p class="small text-danger mb-0"><i class="bx bx-error-circle me-1"></i>No answers configured.</p>';
        } elseif ($qType === 'sequencing') {
            echo '<ol class="mb-0 ps-3 small text-body">';
            foreach ($answers as $a) {
                echo '<li>' . htmlspecialchars((string) $a['answer_text'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
            echo '</ol>';
            echo '<small class="text-muted d-block mt-1"><i class="bx bx-info-circle me-1"></i>Order shown above is the correct sequence.</small>';
        } elseif ($qType === 'fill_blank') {
            $exact = '';
            $alternatives = [];
            foreach ($answers as $a) {
                if ($exact === '' && (int) $a['is_correct'] === 1) {
                    $exact = (string) $a['answer_text'];
                } else {
                    $alternatives[] = (string) $a['answer_text'];
                }
            }
            echo '<div class="small">';
            echo '<div><span class="text-muted">Expected answer:</span> <span class="badge bg-label-success">' . htmlspecialchars($exact, ENT_QUOTES, 'UTF-8') . '</span></div>';
            if ($alternatives !== []) {
                echo '<div class="mt-1"><span class="text-muted">Also accepted:</span> ';
                foreach ($alternatives as $alt) {
                    echo '<span class="badge bg-label-secondary me-1">' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '</span>';
                }
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<ul class="list-unstyled small mb-0">';
            foreach ($answers as $a) {
                $isCorrect = (int) $a['is_correct'] === 1;
                echo '<li class="d-flex align-items-center gap-2 py-1">';
                if ($isCorrect) {
                    echo '<i class="bx bx-check-circle text-success"></i>';
                    echo '<span class="fw-semibold text-success">' . htmlspecialchars((string) $a['answer_text'], ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '<span class="badge bg-label-success ms-auto">Correct</span>';
                } else {
                    echo '<i class="bx bx-circle text-muted"></i>';
                    echo '<span>' . htmlspecialchars((string) $a['answer_text'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</li>';
    }
    echo '</ol>';
};
?>

<div class="card mb-3">
  <h5 class="card-header">
    <i class="bx bx-trophy me-1"></i>Final exam questions
    <span class="text-muted small">(<?php echo count($groupedQuestions['final']); ?>)</span>
  </h5>
  <div class="card-body p-0">
<?php $renderQuestionList($groupedQuestions['final']); ?>
  </div>
</div>

<?php if ($groupedQuestions['exam_types'] !== []) : ?>
<?php foreach ($groupedQuestions['exam_types'] as $etId => $etQuestions) :
  $etName = $examTypesById[$etId] ?? ('Exam type #' . $etId);
?>
<div class="card mb-3">
  <h5 class="card-header">
    <i class="bx bx-book-bookmark me-1"></i><?php echo htmlspecialchars($etName, ENT_QUOTES, 'UTF-8'); ?>
    <span class="text-muted small">(<?php echo count($etQuestions); ?>)</span>
  </h5>
  <div class="card-body p-0">
<?php $renderQuestionList($etQuestions); ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($groupedQuestions['modules'] !== []) : ?>
<?php foreach ($modules as $mod) :
  $mid = (int) $mod['id'];
  $mqs = $groupedQuestions['modules'][$mid] ?? [];
  if ($mqs === []) continue;
?>
<div class="card mb-3">
  <h5 class="card-header">
    <i class="bx bx-folder-open me-1"></i>Module quiz: <?php echo htmlspecialchars((string) $mod['title'], ENT_QUOTES, 'UTF-8'); ?>
    <span class="text-muted small">(<?php echo count($mqs); ?>)</span>
  </h5>
  <div class="card-body p-0">
<?php $renderQuestionList($mqs); ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($canApproveOrReject) : ?>
<div class="d-flex flex-wrap justify-content-end gap-2 mb-4">
  <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Back to courses</a>
  <form method="post" class="d-inline js-approve-publish-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
    <input type="hidden" name="action" value="approve_publish" />
    <button type="submit" class="btn btn-success">
      <i class="bx bx-check me-1"></i>Approve &amp; publish
    </button>
  </form>
  <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectPublishModal">
    <i class="bx bx-x me-1"></i>Send back
  </button>
</div>

<div class="modal fade" id="rejectPublishModal" tabindex="-1" aria-labelledby="rejectPublishModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="rejectPublishForm">
        <div class="modal-header">
          <h5 class="modal-title" id="rejectPublishModalLabel">
            <i class="bx bx-message-detail me-1"></i>Send course back for changes
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="reject_publish" />
          <p class="mb-2">Rejecting <strong><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></strong> sends it back to the instructor and hides it from students.</p>
          <div class="mb-2">
            <label for="publish_review_notes" class="form-label">What needs to change?</label>
            <textarea id="publish_review_notes" name="publish_review_notes" class="form-control" rows="4" maxlength="2000" placeholder="e.g., Module 2 is missing the quiz questions, and the passing score is too low." required></textarea>
            <small class="text-muted">The instructor will see this on their Manage Content page.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">
            <i class="bx bx-send me-1"></i>Send back
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  (() => {
    if (typeof Swal === 'undefined') return;
    ClmsNotify.fromFlash(
      <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
      <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
    );

    document.querySelectorAll('.js-approve-publish-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed === '1') return;
        event.preventDefault();
        Swal.fire({
          title: 'Approve and publish?',
          text: 'The course will become visible to students right away.',
          icon: 'success',
          showCancelButton: true,
          confirmButtonText: 'Yes, publish',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#28a745',
        }).then((result) => {
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });
    });
  })();
</script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
