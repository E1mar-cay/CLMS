<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

$pageTitle = 'Add Question | Admin';
$activeAdminPage = 'courses';
$errorMessage = '';
$successMessage = '';
$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$editQuestionId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$deleteQuestionId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
$editQuestion = null;

$coursesStmt = $pdo->query('SELECT id, title FROM courses ORDER BY title ASC');
$courses = $coursesStmt->fetchAll();

if ($deleteQuestionId !== false && $deleteQuestionId !== null && $deleteQuestionId > 0) {
    try {
        $deleteStmt = $pdo->prepare(
            'DELETE q, a
             FROM questions q
             LEFT JOIN answers a ON a.question_id = q.id
             WHERE q.id = :question_id'
        );
        $deleteStmt->execute(['question_id' => (int) $deleteQuestionId]);
        $successMessage = 'Question deleted successfully.';
    } catch (Throwable $e) {
        $errorMessage = 'Unable to delete question.';
        error_log($e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token.';
    } else {
        try {
            $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
            $questionText = trim((string) ($_POST['question_text'] ?? ''));
            $questionType = strtolower(trim((string) ($_POST['question_type'] ?? '')));
            $pointsRaw = trim((string) ($_POST['points'] ?? '1'));
            $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
            $allowedQuestionTypes = ['true_false', 'multiple_select', 'fill_blank', 'sequencing'];

            if ($courseId === false || $courseId === null || $courseId <= 0) {
                throw new RuntimeException('Please select a valid course.');
            }
            if ($questionText === '' || !in_array($questionType, $allowedQuestionTypes, true) || !is_numeric($pointsRaw)) {
                throw new RuntimeException('Invalid question input.');
            }
            $points = (float) $pointsRaw;
            if ($points < 0) {
                throw new RuntimeException('Points must be zero or higher.');
            }

            $answerRows = [];
            if ($questionType === 'multiple_select') {
                $optionA = trim((string) ($_POST['option_a'] ?? ''));
                $optionB = trim((string) ($_POST['option_b'] ?? ''));
                $optionC = trim((string) ($_POST['option_c'] ?? ''));
                $optionD = trim((string) ($_POST['option_d'] ?? ''));
                $correctOption = strtoupper(trim((string) ($_POST['correct_option'] ?? '')));

                if ($optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
                    throw new RuntimeException('Please fill Option A, B, C, and D.');
                }
                if (!in_array($correctOption, ['A', 'B', 'C', 'D'], true)) {
                    throw new RuntimeException('Please select a valid Correct Answer.');
                }

                $optionMap = [
                    'A' => $optionA,
                    'B' => $optionB,
                    'C' => $optionC,
                    'D' => $optionD,
                ];
                foreach ($optionMap as $letter => $optionText) {
                    $answerRows[] = [
                        'answer_text' => $optionText,
                        'is_correct' => $letter === $correctOption ? 1 : 0,
                        'sequence_position' => null,
                    ];
                }
            } elseif ($questionType === 'true_false') {
                $trueFalseCorrect = strtolower(trim((string) ($_POST['true_false_correct'] ?? '')));
                if (!in_array($trueFalseCorrect, ['true', 'false'], true)) {
                    throw new RuntimeException('Please select the Correct Answer for True / False.');
                }
                $answerRows[] = [
                    'answer_text' => 'True',
                    'is_correct' => $trueFalseCorrect === 'true' ? 1 : 0,
                    'sequence_position' => null,
                ];
                $answerRows[] = [
                    'answer_text' => 'False',
                    'is_correct' => $trueFalseCorrect === 'false' ? 1 : 0,
                    'sequence_position' => null,
                ];
            } elseif ($questionType === 'fill_blank') {
                $exact = trim((string) ($_POST['fill_blank_exact'] ?? ''));
                $alternativesInput = $_POST['fill_blank_alternatives'] ?? [];
                if ($exact === '') {
                    throw new RuntimeException('Please provide the Exact correct answer.');
                }
                $answerRows[] = [
                    'answer_text' => $exact,
                    'is_correct' => 1,
                    'sequence_position' => null,
                ];
                $alternatives = [];
                if (is_array($alternativesInput)) {
                    $alternatives = array_map(static fn ($alt): string => trim((string) $alt), $alternativesInput);
                } else {
                    $alternativesRaw = trim((string) $alternativesInput);
                    if ($alternativesRaw !== '') {
                        $alternatives = preg_split('/[\r\n,]+/', $alternativesRaw) ?: [];
                    }
                }
                foreach ($alternatives as $alt) {
                        $alt = trim((string) $alt);
                        if ($alt === '' || mb_strtolower($alt) === mb_strtolower($exact)) {
                            continue;
                        }
                        $answerRows[] = [
                            'answer_text' => $alt,
                            'is_correct' => 1,
                            'sequence_position' => null,
                        ];
                }
            } elseif ($questionType === 'sequencing') {
                $sequencingItemsInput = $_POST['sequencing_items'] ?? [];
                $sequencingItems = [];
                if (is_array($sequencingItemsInput)) {
                    $sequencingItems = array_map(static fn ($item): string => trim((string) $item), $sequencingItemsInput);
                }
                $sequencingItems = array_values(array_filter($sequencingItems, static fn (string $item): bool => $item !== ''));
                if (count($sequencingItems) < 2) {
                    throw new RuntimeException('Please provide at least two sequencing items.');
                }
                foreach ($sequencingItems as $index => $itemText) {
                    $answerRows[] = [
                        'answer_text' => $itemText,
                        'is_correct' => 1,
                        'sequence_position' => $index + 1,
                    ];
                }
            } else {
                for ($i = 1; $i <= 6; $i++) {
                    $answerText = trim((string) ($_POST['answer_' . $i . '_text'] ?? ''));
                    if ($answerText === '') {
                        continue;
                    }
                    $seqRaw = trim((string) ($_POST['answer_' . $i . '_sequence'] ?? ''));
                    $seq = null;
                    if ($seqRaw !== '') {
                        $seqVal = filter_var($seqRaw, FILTER_VALIDATE_INT);
                        if ($seqVal === false || (int) $seqVal < 1) {
                            throw new RuntimeException('Invalid sequence value in answer ' . $i . '.');
                        }
                        $seq = (int) $seqVal;
                    }
                    $answerRows[] = [
                        'answer_text' => $answerText,
                        'is_correct' => isset($_POST['answer_' . $i . '_correct']) ? 1 : 0,
                        'sequence_position' => $seq,
                    ];
                }
            }

            if ($answerRows === []) {
                throw new RuntimeException('Provide at least one answer.');
            }
            if (($questionType === 'true_false' || $questionType === 'multiple_select') && array_sum(array_column($answerRows, 'is_correct')) !== 1) {
                throw new RuntimeException('Single choice/true-false need exactly one correct answer.');
            }

            $pdo->beginTransaction();
            if ($questionId !== false && $questionId !== null && $questionId > 0) {
                $updateStmt = $pdo->prepare(
                    'UPDATE questions
                     SET course_id = :course_id, question_text = :question_text, question_type = :question_type, points = :points
                     WHERE id = :question_id'
                );
                $updateStmt->execute([
                    'course_id' => (int) $courseId,
                    'question_text' => $questionText,
                    'question_type' => $questionType,
                    'points' => number_format($points, 2, '.', ''),
                    'question_id' => (int) $questionId,
                ]);

                $deleteAnswersStmt = $pdo->prepare('DELETE FROM answers WHERE question_id = :question_id');
                $deleteAnswersStmt->execute(['question_id' => (int) $questionId]);
                $questionId = (int) $questionId;
            } else {
                $questionStmt = $pdo->prepare(
                    'INSERT INTO questions (course_id, question_text, question_type, points)
                     VALUES (:course_id, :question_text, :question_type, :points)'
                );
                $questionStmt->execute([
                    'course_id' => (int) $courseId,
                    'question_text' => $questionText,
                    'question_type' => $questionType,
                    'points' => number_format($points, 2, '.', ''),
                ]);
                $questionId = (int) $pdo->lastInsertId();
            }

            if ($answerRows !== []) {
                $answerStmt = $pdo->prepare(
                    'INSERT INTO answers (question_id, answer_text, is_correct, sequence_position)
                     VALUES (:question_id, :answer_text, :is_correct, :sequence_position)'
                );
                foreach ($answerRows as $row) {
                    $answerStmt->execute([
                        'question_id' => $questionId,
                        'answer_text' => $row['answer_text'],
                        'is_correct' => $row['is_correct'],
                        'sequence_position' => $row['sequence_position'],
                    ]);
                }
            }

            $pdo->commit();
            $successMessage = $questionId > 0 && $questionId === (int) ($_POST['question_id'] ?? 0)
                ? 'Question updated successfully.'
                : 'Question added successfully.';
            $selectedCourseId = (int) $courseId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to add question.';
            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0 && $editQuestionId !== false && $editQuestionId !== null && $editQuestionId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, course_id, question_text, question_type, points
         FROM questions
         WHERE id = :question_id AND course_id = :course_id
         LIMIT 1'
    );
    $editStmt->execute([
        'question_id' => (int) $editQuestionId,
        'course_id' => (int) $selectedCourseId,
    ]);
    $editQuestion = $editStmt->fetch();

    if ($editQuestion) {
        $answersStmt = $pdo->prepare(
            'SELECT answer_text, is_correct, sequence_position
             FROM answers
             WHERE question_id = :question_id
             ORDER BY id ASC'
        );
        $answersStmt->execute(['question_id' => (int) $editQuestionId]);
        $editQuestion['answers'] = $answersStmt->fetchAll();
    }
}

$courseQuestions = [];
if ($selectedCourseId !== false && $selectedCourseId !== null && $selectedCourseId > 0) {
    $questionListStmt = $pdo->prepare(
        'SELECT id, question_text, question_type, points
         FROM questions
         WHERE course_id = :course_id
         ORDER BY id DESC'
    );
    $questionListStmt->execute(['course_id' => (int) $selectedCourseId]);
    $courseQuestions = $questionListStmt->fetchAll();
}

$questionCount = count($courseQuestions);

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="bg-white p-3 mb-3 shadow-sm">
                <div class="row align-items-center g-2">
                  <div class="col-md-6">
                    <h4 class="fw-bold mb-0">Question Builder</h4>
                  </div>
                  <div class="col-md-6">
                    <form method="get" class="d-flex gap-2 align-items-center">
                      <label class="form-label mb-0" for="course_picker"><strong>Course:</strong></label>
                      <select id="course_picker" class="form-select" name="course_id" onchange="this.form.submit()" required>
                        <option value="">-- Select Course --</option>
<?php foreach ($courses as $course) : ?>
                        <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
<?php endforeach; ?>
                      </select>
                      <span class="badge bg-info fs-6">Questions: <?php echo $questionCount; ?></span>
                    </form>
                  </div>
                </div>
              </div>

              <noscript>
<?php if ($successMessage !== '') : ?>
                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($errorMessage !== '') : ?>
                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
              </noscript>

              <div class="card">
                <div class="card-header bg-primary text-white">
                  <h5 class="mb-0"><?php echo $editQuestion ? 'Edit Question' : 'Add New Question'; ?></h5>
                </div>
                <div class="card-body">
<?php if (!$selectedCourseId) : ?>
                  <p class="mb-0">Select a course first to build questions.</p>
<?php else : ?>
                  <form id="singleQuestionForm" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . (int) $selectedCourseId, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($editQuestion) : ?>
                    <input type="hidden" name="question_id" value="<?php echo (int) $editQuestion['id']; ?>" />
<?php endif; ?>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Course</label>
                        <select class="form-select" name="course_id" required>
                          <option value="">Select course</option>
<?php foreach ($courses as $course) : ?>
                          <option value="<?php echo (int) $course['id']; ?>" <?php echo ((int) $selectedCourseId === (int) $course['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Question Type</label>
                        <select class="form-select" id="questionTypeSelect" name="question_type" required>
<?php $currentType = (string) ($editQuestion['question_type'] ?? 'multiple_select'); ?>
                          <option value="multiple_select" <?php echo $currentType === 'multiple_select' ? 'selected' : ''; ?>>Multiple Choice (A/B/C/D)</option>
                          <option value="true_false" <?php echo $currentType === 'true_false' ? 'selected' : ''; ?>>True / False</option>
                          <option value="fill_blank" <?php echo $currentType === 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option>
                          <option value="sequencing" <?php echo $currentType === 'sequencing' ? 'selected' : ''; ?>>Sequencing</option>
                        </select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Points</label>
                        <input class="form-control" type="number" name="points" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($editQuestion['points'] ?? '1.00'), ENT_QUOTES, 'UTF-8'); ?>" required />
                      </div>
                      <div class="col-12">
                        <label class="form-label">Question Text</label>
                        <textarea class="form-control" name="question_text" rows="3" required><?php echo htmlspecialchars((string) ($editQuestion['question_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      </div>
                    </div>

                    <hr class="my-4" />
<?php
  $editAnswers = $editQuestion['answers'] ?? [];
  $optionAValue = (string) ($editAnswers[0]['answer_text'] ?? '');
  $optionBValue = (string) ($editAnswers[1]['answer_text'] ?? '');
  $optionCValue = (string) ($editAnswers[2]['answer_text'] ?? '');
  $optionDValue = (string) ($editAnswers[3]['answer_text'] ?? '');
  $correctOptionValue = '';
  foreach ([0 => 'A', 1 => 'B', 2 => 'C', 3 => 'D'] as $idx => $letter) {
      if ((int) ($editAnswers[$idx]['is_correct'] ?? 0) === 1) {
          $correctOptionValue = $letter;
          break;
      }
  }
?>
                    <div id="multipleSelectBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                      </div>
                      <p class="text-muted mb-3">Answer Options *</p>
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="form-label">Option A *</label>
                          <input class="form-control" type="text" name="option_a" value="<?php echo htmlspecialchars($optionAValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option B *</label>
                          <input class="form-control" type="text" name="option_b" value="<?php echo htmlspecialchars($optionBValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option C *</label>
                          <input class="form-control" type="text" name="option_c" value="<?php echo htmlspecialchars($optionCValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Option D *</label>
                          <input class="form-control" type="text" name="option_d" value="<?php echo htmlspecialchars($optionDValue, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Correct Answer *</label>
                          <select class="form-select" name="correct_option">
                            <option value="">Select answer</option>
                            <option value="A" <?php echo $correctOptionValue === 'A' ? 'selected' : ''; ?>>Option A</option>
                            <option value="B" <?php echo $correctOptionValue === 'B' ? 'selected' : ''; ?>>Option B</option>
                            <option value="C" <?php echo $correctOptionValue === 'C' ? 'selected' : ''; ?>>Option C</option>
                            <option value="D" <?php echo $correctOptionValue === 'D' ? 'selected' : ''; ?>>Option D</option>
                          </select>
                        </div>
                      </div>
                    </div>

<?php
  $fillBlankCorrectValue = '';
  $fillBlankAlternatives = [];
  foreach ($editAnswers as $answerRow) {
      if ((int) ($answerRow['is_correct'] ?? 0) !== 1) {
          continue;
      }
      $answerText = trim((string) ($answerRow['answer_text'] ?? ''));
      if ($answerText === '') {
          continue;
      }
      if ($fillBlankCorrectValue === '') {
          $fillBlankCorrectValue = $answerText;
      } else {
          $fillBlankAlternatives[] = $answerText;
      }
  }
?>
                    <div id="fillBlankBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_fb" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                        <div class="col-12">
                          <label class="form-label">Correct Answer *</label>
                          <input class="form-control" type="text" name="fill_blank_exact" value="<?php echo htmlspecialchars($fillBlankCorrectValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Exact correct answer" />
                        </div>
                        <div class="col-12">
                          <label class="form-label">Accepted Alternatives (optional)</label>
                          <div id="fillBlankAlternativesWrap">
<?php if ($fillBlankAlternatives === []) : ?>
                            <div class="input-group mb-2 fill-blank-alt-row">
                              <input class="form-control" type="text" name="fill_blank_alternatives[]" placeholder="Alternative answer" />
                              <button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>
                            </div>
<?php else : ?>
<?php foreach ($fillBlankAlternatives as $altValue) : ?>
                            <div class="input-group mb-2 fill-blank-alt-row">
                              <input class="form-control" type="text" name="fill_blank_alternatives[]" value="<?php echo htmlspecialchars((string) $altValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Alternative answer" />
                              <button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                          </div>
                          <button class="btn btn-outline-primary btn-sm mt-1" id="fillBlankAddAlternativeBtn" type="button">Add Alternative</button>
                          <small class="text-muted">Other acceptable spellings or phrasings (e.g. abbreviations, case variants).</small>
                        </div>
                      </div>
                    </div>

<?php
  $trueFalseCorrectValue = 'true';
  foreach ($editAnswers as $answerRow) {
      $answerTextRaw = strtolower(trim((string) ($answerRow['answer_text'] ?? '')));
      if ((int) ($answerRow['is_correct'] ?? 0) === 1) {
          if ($answerTextRaw === 'false') {
              $trueFalseCorrectValue = 'false';
          } elseif ($answerTextRaw === 'true') {
              $trueFalseCorrectValue = 'true';
          }
      }
  }
?>
                    <div id="trueFalseBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_tf" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                      </div>
                      <div class="mb-2">
                        <label class="form-label d-block">Correct Answer *</label>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="true_false_correct" id="tf_true_admin" value="true" <?php echo $trueFalseCorrectValue === 'true' ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="tf_true_admin">True</label>
                        </div>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="true_false_correct" id="tf_false_admin" value="false" <?php echo $trueFalseCorrectValue === 'false' ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="tf_false_admin">False</label>
                        </div>
                      </div>
                    </div>

<?php
  $sequencingItems = [];
  foreach ($editAnswers as $answerRow) {
      $itemText = trim((string) ($answerRow['answer_text'] ?? ''));
      if ($itemText === '') {
          continue;
      }
      $sequencingItems[] = [
          'text' => $itemText,
          'position' => (int) ($answerRow['sequence_position'] ?? 0),
      ];
  }
  usort($sequencingItems, static function (array $left, array $right): int {
      $leftPos = $left['position'] > 0 ? $left['position'] : 999999;
      $rightPos = $right['position'] > 0 ? $right['position'] : 999999;
      return $leftPos <=> $rightPos;
  });
?>
                    <div id="sequencingBlock" style="display:none;">
                      <div class="row g-3 mb-3">
                        <div class="col-12">
                          <label class="form-label">Question Image (Optional)</label>
                          <input class="form-control" type="file" name="question_image_sq" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" />
                          <small class="text-muted">Allowed formats: JPG, JPEG, PNG, GIF</small>
                        </div>
                        <div class="col-12">
                          <label class="form-label d-block">Sequence Items *</label>
                          <p class="text-muted mb-2">Drag and drop items to set the correct order.</p>
                          <div id="sequencingItemsWrap" class="d-flex flex-column gap-2">
<?php if ($sequencingItems === []) : ?>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
<?php else : ?>
<?php foreach ($sequencingItems as $item) : ?>
                            <div class="input-group sequencing-item-row" draggable="true">
                              <span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span>
                              <input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" value="<?php echo htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Sequence item" />
                              <button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                          </div>
                          <button class="btn btn-outline-primary btn-sm mt-2" id="sequencingAddItemBtn" type="button">Add Item</button>
                        </div>
                      </div>
                    </div>

                    <p class="text-muted mb-3">Answers (for sequencing, fill in the sequence number for each option)</p>
                    <div id="answersBlock">
<?php for ($i = 1; $i <= 6; $i++) : ?>
<?php
  $answerText = (string) ($editQuestion['answers'][$i - 1]['answer_text'] ?? '');
  $isCorrect = (int) ($editQuestion['answers'][$i - 1]['is_correct'] ?? 0) === 1;
  $sequencePosition = $editQuestion['answers'][$i - 1]['sequence_position'] ?? '';
?>
                    <div class="row g-2 mb-2 answer-row">
                      <div class="col-md-7"><input class="form-control" type="text" name="answer_<?php echo $i; ?>_text" placeholder="Answer <?php echo $i; ?>" value="<?php echo htmlspecialchars($answerText, ENT_QUOTES, 'UTF-8'); ?>" /></div>
                      <div class="col-md-2 sequence-col"><input class="form-control sequence-input" type="number" min="1" name="answer_<?php echo $i; ?>_sequence" placeholder="Seq" value="<?php echo htmlspecialchars((string) $sequencePosition, ENT_QUOTES, 'UTF-8'); ?>" /></div>
                      <div class="col-md-3 d-flex align-items-center">
                        <div class="form-check">
                          <input class="form-check-input correct-input" type="checkbox" name="answer_<?php echo $i; ?>_correct" id="admin_ans_<?php echo $i; ?>" <?php echo $isCorrect ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="admin_ans_<?php echo $i; ?>">Correct</label>
                        </div>
                      </div>
                    </div>
<?php endfor; ?>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                      <button class="btn btn-primary" type="submit"><?php echo $editQuestion ? 'Update Question' : 'Save Question'; ?></button>
<?php if ($editQuestion) : ?>
                      <a class="btn btn-secondary" href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . (int) $selectedCourseId, ENT_QUOTES, 'UTF-8'); ?>">Cancel Edit</a>
<?php endif; ?>
                    </div>
                  </form>
<?php endif; ?>
                </div>
              </div>

<?php if ($selectedCourseId) : ?>
              <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Question List</h5>
                  <span class="badge bg-info"><?php echo $questionCount; ?> question(s)</span>
                </div>
                <div class="card-body">
<?php if ($courseQuestions === []) : ?>
                  <p class="mb-0">No questions yet for this course.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Question</th>
                          <th>Type</th>
                          <th>Points</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($courseQuestions as $q) : ?>
<?php $typeLabel = clms_question_type_label((string) $q['question_type']); ?>
                        <tr
                          data-search-item
                          data-search-text="<?php echo htmlspecialchars(((string) $q['question_text']) . ' ' . $typeLabel, ENT_QUOTES, 'UTF-8'); ?>">
                          <td><?php echo (int) $q['id']; ?></td>
                          <td><?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><span class="badge bg-label-primary"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                          <td><?php echo number_format((float) $q['points'], 2); ?></td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <a
                                class="btn btn-sm btn-warning edit-question-btn"
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . (int) $selectedCourseId . '&edit=' . (int) $q['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-question-text="<?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="bx bx-edit-alt"></i> Edit
                              </a>
                              <a
                                class="btn btn-sm btn-danger delete-question-btn"
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . (int) $selectedCourseId . '&delete=' . (int) $q['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-question-text="<?php echo htmlspecialchars((string) $q['question_text'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="bx bx-trash"></i> Delete
                              </a>
                            </div>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
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
                })();

                (() => {
                  const typeSelect = document.getElementById('questionTypeSelect');
                  const multipleSelectBlock = document.getElementById('multipleSelectBlock');
                  const trueFalseBlock = document.getElementById('trueFalseBlock');
                  const fillBlankBlock = document.getElementById('fillBlankBlock');
                  const sequencingBlock = document.getElementById('sequencingBlock');
                  const answersBlock = document.getElementById('answersBlock');
                  if (!typeSelect || !answersBlock || !multipleSelectBlock || !trueFalseBlock || !fillBlankBlock || !sequencingBlock) return;

                  const rows = [...answersBlock.querySelectorAll('.answer-row')];
                  const correctInputs = [...answersBlock.querySelectorAll('.correct-input')];
                  const sequenceCols = [...answersBlock.querySelectorAll('.sequence-col')];
                  const sequenceInputs = [...answersBlock.querySelectorAll('.sequence-input')];

                  const syncUi = () => {
                    const type = typeSelect.value;
                    const isMultipleSelect = type === 'multiple_select';
                    const isTrueFalse = type === 'true_false';
                    const isFillBlank = type === 'fill_blank';
                    const isSequencing = type === 'sequencing';

                    multipleSelectBlock.style.display = isMultipleSelect ? '' : 'none';
                    trueFalseBlock.style.display = isTrueFalse ? '' : 'none';
                    fillBlankBlock.style.display = isFillBlank ? '' : 'none';
                    sequencingBlock.style.display = isSequencing ? '' : 'none';
                    answersBlock.style.display = (isMultipleSelect || isTrueFalse || isFillBlank || isSequencing) ? 'none' : '';
                    rows.forEach((row) => {
                      row.style.display = '';
                    });
                    sequenceCols.forEach((col) => {
                      col.style.display = isSequencing ? '' : 'none';
                    });
                    sequenceInputs.forEach((input) => {
                      if (!isSequencing) input.value = '';
                    });

                    correctInputs.forEach((input) => {
                      input.type = 'checkbox';
                    });

                  };

                  correctInputs.forEach((input) => {
                    input.addEventListener('change', () => {
                      const type = typeSelect.value;
                      const isSingle = type === 'true_false' || type === 'multiple_select';
                      if (!isSingle || !input.checked) return;
                      correctInputs.forEach((other) => {
                        if (other !== input) other.checked = false;
                      });
                    });
                  });

                  syncUi();
                  typeSelect.addEventListener('change', syncUi);
                })();

                (() => {
                  const wrap = document.getElementById('sequencingItemsWrap');
                  const addBtn = document.getElementById('sequencingAddItemBtn');
                  if (!wrap || !addBtn) return;

                  const createRow = () => {
                    const row = document.createElement('div');
                    row.className = 'input-group sequencing-item-row';
                    row.setAttribute('draggable', 'true');
                    row.innerHTML = '<span class="input-group-text sequencing-drag-handle" style="cursor:grab;">::</span><input class="form-control sequencing-item-input" type="text" name="sequencing_items[]" placeholder="Sequence item" /><button class="btn btn-outline-danger sequencing-delete-item" type="button">Delete</button>';
                    return row;
                  };

                  addBtn.addEventListener('click', () => {
                    wrap.appendChild(createRow());
                  });

                  wrap.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement) || !target.classList.contains('sequencing-delete-item')) return;
                    const rows = wrap.querySelectorAll('.sequencing-item-row');
                    if (rows.length <= 2) {
                      const input = target.closest('.sequencing-item-row')?.querySelector('.sequencing-item-input');
                      if (input instanceof HTMLInputElement) input.value = '';
                      return;
                    }
                    target.closest('.sequencing-item-row')?.remove();
                  });

                  let draggingRow = null;
                  wrap.addEventListener('dragstart', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const row = target.closest('.sequencing-item-row');
                    if (!(row instanceof HTMLElement)) return;
                    draggingRow = row;
                    row.classList.add('opacity-50');
                  });

                  wrap.addEventListener('dragend', () => {
                    if (draggingRow instanceof HTMLElement) draggingRow.classList.remove('opacity-50');
                    draggingRow = null;
                  });

                  wrap.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    const target = event.target;
                    if (!(target instanceof HTMLElement) || !(draggingRow instanceof HTMLElement)) return;
                    const row = target.closest('.sequencing-item-row');
                    if (!(row instanceof HTMLElement) || row === draggingRow) return;
                    const rect = row.getBoundingClientRect();
                    const shouldInsertBefore = event.clientY < rect.top + rect.height / 2;
                    if (shouldInsertBefore) {
                      wrap.insertBefore(draggingRow, row);
                    } else {
                      wrap.insertBefore(draggingRow, row.nextSibling);
                    }
                  });
                })();

                (() => {
                  const addBtn = document.getElementById('fillBlankAddAlternativeBtn');
                  const wrap = document.getElementById('fillBlankAlternativesWrap');
                  if (!addBtn || !wrap) return;

                  const removeRow = (button) => {
                    const rows = wrap.querySelectorAll('.fill-blank-alt-row');
                    if (rows.length <= 1) {
                      const input = rows[0]?.querySelector('input[name="fill_blank_alternatives[]"]');
                      if (input) input.value = '';
                      return;
                    }
                    button.closest('.fill-blank-alt-row')?.remove();
                  };

                  wrap.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    if (!target.classList.contains('fill-blank-remove-alt')) return;
                    removeRow(target);
                  });

                  addBtn.addEventListener('click', () => {
                    const row = document.createElement('div');
                    row.className = 'input-group mb-2 fill-blank-alt-row';
                    row.innerHTML = '<input class="form-control" type="text" name="fill_blank_alternatives[]" placeholder="Alternative answer" /><button class="btn btn-outline-danger fill-blank-remove-alt" type="button">Delete</button>';
                    wrap.appendChild(row);
                  });
                })();

                (() => {
                  if (typeof Swal === 'undefined') return;

                  const truncate = (text, max = 120) => {
                    if (!text) return '';
                    return text.length > max ? text.slice(0, max - 1) + '…' : text;
                  };

                  document.querySelectorAll('.delete-question-btn').forEach((button) => {
                    button.addEventListener('click', (event) => {
                      event.preventDefault();
                      const deleteUrl = button.getAttribute('href');
                      if (!deleteUrl) return;
                      const questionText = button.getAttribute('data-question-text') || '';

                      Swal.fire({
                        title: 'Delete this question?',
                        html: (questionText ? '<div class="mb-2 text-muted fst-italic">"' + truncate(questionText) + '"</div>' : '')
                          + 'The question, all of its answer choices, and any student responses will be permanently removed. This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          window.location.href = deleteUrl;
                        }
                      });
                    });
                  });

                  document.querySelectorAll('.edit-question-btn').forEach((button) => {
                    button.addEventListener('click', (event) => {
                      event.preventDefault();
                      const editUrl = button.getAttribute('href');
                      if (!editUrl) return;
                      const questionText = button.getAttribute('data-question-text') || '';

                      Swal.fire({
                        title: 'Edit this question?',
                        html: (questionText ? '<div class="mb-2 text-muted fst-italic">"' + truncate(questionText) + '"</div>' : '')
                          + 'The Question Builder form will load this question so you can update it.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, load editor',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          window.location.href = editUrl;
                        }
                      });
                    });
                  });
                })();
              </script>
<?php
require_once __DIR__ . '/includes/layout-bottom.php';
