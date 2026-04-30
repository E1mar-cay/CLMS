<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Courses | Criminology LMS';
$activeAdminPage = 'courses';
$errorMessage = '';
$successMessage = '';

foreach ([
    'level' => 'VARCHAR(20) NULL',
    'thumbnail_url' => 'VARCHAR(500) NULL',
    'final_exam_duration_minutes' => 'INT NOT NULL DEFAULT 45',
] as $columnName => $columnSpec) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM courses LIKE '" . $columnName . "'")->fetch();
        if (!$check) {
            $pdo->exec('ALTER TABLE courses ADD COLUMN ' . $columnName . ' ' . $columnSpec);
        }
    } catch (Throwable $e) {
        error_log('courses.' . $columnName . ' migration failed: ' . $e->getMessage());
    }
}

$allowedLevels = ['', 'beginner', 'intermediate', 'advanced'];
$thumbnailUploadWebDir = '/public/assets/uploads/course-thumbnails';
$thumbnailUploadFsDir = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $thumbnailUploadWebDir);
$resolveThumbnailUrl = static function (?string $rawPath) use ($clmsWebBase): string {
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return rtrim((string) $clmsWebBase, '/') . $path;
    }
    return rtrim((string) $clmsWebBase, '/') . '/' . ltrim($path, '/');
};

$editIdRaw = $_GET['edit'] ?? null;
$editId = filter_var(
    $editIdRaw,
    FILTER_VALIDATE_INT,
    ['options' => ['default' => 0, 'min_range' => 1]]
);
$editId = $editId === false ? 0 : (int) $editId;
$formTitle = '';
$formDescription = '';
$formPassingScore = '75.00';
$formIsPublished = 1;
$formLevel = '';
$formThumbnailUrl = '';
$formFinalExamDuration = '45';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'create' || $action === 'update') {
                $titleInput = trim((string) ($_POST['title'] ?? ''));
                $descriptionInput = trim((string) ($_POST['description'] ?? ''));
                $passingInput = trim((string) ($_POST['passing_score_percentage'] ?? ''));
                $isPublishedInput = isset($_POST['is_published']) ? 1 : 0;
                $levelInput = strtolower(trim((string) ($_POST['level'] ?? '')));
                $existingThumbnailInput = trim((string) ($_POST['existing_thumbnail_url'] ?? ''));
                $removeThumbnailInput = isset($_POST['remove_thumbnail']) && $_POST['remove_thumbnail'] !== '';
                $finalExamDurationInput = trim((string) ($_POST['final_exam_duration_minutes'] ?? '45'));

                if ($titleInput === '' || mb_strlen($titleInput) > 255) {
                    throw new RuntimeException('Title is required and must be 255 characters or fewer.');
                }
                if (!is_numeric($passingInput)) {
                    throw new RuntimeException('Passing score must be a number.');
                }
                $passingScore = (float) $passingInput;
                if ($passingScore < 0 || $passingScore > 100) {
                    throw new RuntimeException('Passing score must be between 0 and 100.');
                }
                if (!in_array($levelInput, $allowedLevels, true)) {
                    throw new RuntimeException('Invalid level value.');
                }
                $levelValue = $levelInput === '' ? null : $levelInput;

                $thumbnailValue = $existingThumbnailInput === '' ? null : $existingThumbnailInput;
                if ($removeThumbnailInput) {
                    if (
                        $thumbnailValue !== null
                        && str_starts_with($thumbnailValue, $thumbnailUploadWebDir . '/')
                    ) {
                        $oldFsPath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $thumbnailValue);
                        if (is_file($oldFsPath)) {
                            @unlink($oldFsPath);
                        }
                    }
                    $thumbnailValue = null;
                }
                if (isset($_FILES['thumbnail_image']) && is_array($_FILES['thumbnail_image'])) {
                    $thumbnailFile = $_FILES['thumbnail_image'];
                    $uploadError = (int) ($thumbnailFile['error'] ?? UPLOAD_ERR_NO_FILE);

                    if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                        if ($uploadError !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('Thumbnail upload failed. Please try again.');
                        }

                        $tmpName = (string) ($thumbnailFile['tmp_name'] ?? '');
                        $fileSize = (int) ($thumbnailFile['size'] ?? 0);
                        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                            throw new RuntimeException('Invalid thumbnail upload payload.');
                        }
                        if ($fileSize <= 0) {
                            throw new RuntimeException('Uploaded thumbnail is empty.');
                        }
                        if ($fileSize > 5 * 1024 * 1024) {
                            throw new RuntimeException('Thumbnail image must be 5MB or smaller.');
                        }

                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = (string) $finfo->file($tmpName);
                        $allowedMimes = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                            'image/gif' => 'gif',
                        ];
                        if (!isset($allowedMimes[$mimeType])) {
                            throw new RuntimeException('Thumbnail must be JPG, PNG, WEBP, or GIF.');
                        }

                        if (!is_dir($thumbnailUploadFsDir) && !mkdir($thumbnailUploadFsDir, 0775, true) && !is_dir($thumbnailUploadFsDir)) {
                            throw new RuntimeException('Failed to prepare thumbnail upload directory.');
                        }

                        $ext = $allowedMimes[$mimeType];
                        $filename = 'course-thumb-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $destinationFsPath = $thumbnailUploadFsDir . DIRECTORY_SEPARATOR . $filename;
                        if (!move_uploaded_file($tmpName, $destinationFsPath)) {
                            throw new RuntimeException('Failed to save uploaded thumbnail.');
                        }

                        // Replace old uploaded thumbnail file when a new one is provided.
                        if (
                            $thumbnailValue !== null
                            && str_starts_with($thumbnailValue, $thumbnailUploadWebDir . '/')
                        ) {
                            $oldFsPath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $thumbnailValue);
                            if (is_file($oldFsPath)) {
                                @unlink($oldFsPath);
                            }
                        }

                        $thumbnailValue = $thumbnailUploadWebDir . '/' . $filename;
                    }
                }

                if ($thumbnailValue !== null && mb_strlen($thumbnailValue) > 500) {
                    throw new RuntimeException('Thumbnail path is too long.');
                }

                if (!is_numeric($finalExamDurationInput)) {
                    throw new RuntimeException('Final exam time limit must be a whole number of minutes.');
                }
                $finalExamDurationValue = (int) $finalExamDurationInput;
                if ($finalExamDurationValue < 1 || $finalExamDurationValue > 600) {
                    throw new RuntimeException('Final exam time limit must be between 1 and 600 minutes.');
                }

                if ($action === 'create') {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO courses (title, description, passing_score_percentage, is_published, level, thumbnail_url, final_exam_duration_minutes)
                         VALUES (:title, :description, :passing_score, :is_published, :level, :thumbnail_url, :final_exam_duration_minutes)'
                    );
                    $insertStmt->execute([
                        'title' => $titleInput,
                        'description' => $descriptionInput,
                        'passing_score' => number_format($passingScore, 2, '.', ''),
                        'is_published' => $isPublishedInput,
                        'level' => $levelValue,
                        'thumbnail_url' => $thumbnailValue,
                        'final_exam_duration_minutes' => $finalExamDurationValue,
                    ]);
                    $successMessage = 'Course created successfully.';
                } else {
                    $courseIdRaw = $_POST['course_id'] ?? null;
                    $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                    $courseId = $courseId === false ? 0 : (int) $courseId;
                    if ($courseId <= 0) {
                        throw new RuntimeException('Invalid course id.');
                    }

                    $updateStmt = $pdo->prepare(
                        'UPDATE courses
                         SET title = :title,
                             description = :description,
                             passing_score_percentage = :passing_score,
                             is_published = :is_published,
                             level = :level,
                             thumbnail_url = :thumbnail_url,
                             final_exam_duration_minutes = :final_exam_duration_minutes
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        'title' => $titleInput,
                        'description' => $descriptionInput,
                        'passing_score' => number_format($passingScore, 2, '.', ''),
                        'is_published' => $isPublishedInput,
                        'level' => $levelValue,
                        'thumbnail_url' => $thumbnailValue,
                        'final_exam_duration_minutes' => $finalExamDurationValue,
                        'id' => $courseId,
                    ]);

                    // Keep currently in-progress attempts in sync with the
                    // new course time limit so students immediately see the
                    // updated duration when they reopen the exam.
                    $syncDeadlineStmt = $pdo->prepare(
                        'UPDATE exam_attempts
                         SET deadline_at = DATE_ADD(attempted_at, INTERVAL :duration MINUTE)
                         WHERE course_id = :course_id
                           AND status = :status'
                    );
                    $syncDeadlineStmt->execute([
                        'duration' => $finalExamDurationValue,
                        'course_id' => $courseId,
                        'status' => 'in_progress',
                    ]);

                    $successMessage = 'Course updated successfully.';
                    clms_redirect('admin/courses.php?flash=updated');
                }
            } elseif ($action === 'delete') {
                $courseIdRaw = $_POST['course_id'] ?? null;
                $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                $courseId = $courseId === false ? 0 : (int) $courseId;
                if ($courseId <= 0) {
                    throw new RuntimeException('Invalid course id.');
                }

                $pdo->beginTransaction();
                try {
                    $deleteResponsesStmt = $pdo->prepare(
                        'DELETE sr
                         FROM student_responses sr
                         INNER JOIN exam_attempts ea ON ea.id = sr.attempt_id
                         WHERE ea.course_id = :course_id'
                    );
                    $deleteResponsesStmt->execute(['course_id' => $courseId]);

                    $deleteAttemptsStmt = $pdo->prepare(
                        'DELETE FROM exam_attempts WHERE course_id = :course_id'
                    );
                    $deleteAttemptsStmt->execute(['course_id' => $courseId]);

                    $deleteAnswersStmt = $pdo->prepare(
                        'DELETE a
                         FROM answers a
                         INNER JOIN questions q ON q.id = a.question_id
                         WHERE q.course_id = :course_id'
                    );
                    $deleteAnswersStmt->execute(['course_id' => $courseId]);

                    $deleteQuestionsStmt = $pdo->prepare(
                        'DELETE FROM questions WHERE course_id = :course_id'
                    );
                    $deleteQuestionsStmt->execute(['course_id' => $courseId]);

                    $deleteProgressStmt = $pdo->prepare(
                        'DELETE up
                         FROM user_progress up
                         INNER JOIN modules m ON m.id = up.module_id
                         WHERE m.course_id = :course_id'
                    );
                    $deleteProgressStmt->execute(['course_id' => $courseId]);

                    $deleteModulesStmt = $pdo->prepare(
                        'DELETE FROM modules WHERE course_id = :course_id'
                    );
                    $deleteModulesStmt->execute(['course_id' => $courseId]);

                    $deleteCertificatesStmt = $pdo->prepare(
                        'DELETE FROM certificates WHERE course_id = :course_id'
                    );
                    $deleteCertificatesStmt->execute(['course_id' => $courseId]);

                    try {
                        $deleteInstructorsStmt = $pdo->prepare(
                            'DELETE FROM course_instructors WHERE course_id = :course_id'
                        );
                        $deleteInstructorsStmt->execute(['course_id' => $courseId]);
                    } catch (PDOException $instructorException) {
                        if (!isset($instructorException->errorInfo[1]) || (int) $instructorException->errorInfo[1] !== 1146) {
                            throw $instructorException;
                        }
                    }

                    $deleteStmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
                    $deleteStmt->execute(['id' => $courseId]);

                    $pdo->commit();
                } catch (Throwable $deleteException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $deleteException;
                }

                $successMessage = 'Course and all related records deleted successfully.';
            } elseif ($action === 'toggle_publish') {
                $courseIdRaw = $_POST['course_id'] ?? null;
                $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                $courseId = $courseId === false ? 0 : (int) $courseId;
                if ($courseId <= 0) {
                    throw new RuntimeException('Invalid course id.');
                }
                $toggleStmt = $pdo->prepare(
                    'UPDATE courses SET is_published = IF(is_published = 1, 0, 1) WHERE id = :id'
                );
                $toggleStmt->execute(['id' => $courseId]);
                $successMessage = 'Course publish status updated.';
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

$flashCode = (string) ($_GET['flash'] ?? '');
if ($successMessage === '' && $flashCode !== '') {
    $flashMap = [
        'updated' => 'Course updated successfully.',
    ];
    if (isset($flashMap[$flashCode])) {
        $successMessage = $flashMap[$flashCode];
    }
}

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, title, description, passing_score_percentage, is_published, level, thumbnail_url, final_exam_duration_minutes
         FROM courses WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch();
    if ($editRow) {
        $formTitle = (string) $editRow['title'];
        $formDescription = (string) ($editRow['description'] ?? '');
        $formPassingScore = number_format((float) $editRow['passing_score_percentage'], 2, '.', '');
        $formIsPublished = (int) $editRow['is_published'];
        $formLevel = (string) ($editRow['level'] ?? '');
        $formThumbnailUrl = (string) ($editRow['thumbnail_url'] ?? '');
        $formFinalExamDuration = (string) ((int) ($editRow['final_exam_duration_minutes'] ?? 45));
    } else {
        $editId = 0;
    }
}

$courseListStmt = $pdo->query(
    'SELECT
        c.id,
        c.title,
        c.description,
        c.passing_score_percentage,
        c.is_published,
        c.level,
        c.thumbnail_url,
        COALESCE(c.final_exam_duration_minutes, 45) AS final_exam_duration_minutes,
        (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS module_count,
        (SELECT COUNT(*) FROM questions q WHERE q.course_id = c.id) AS question_count,
        (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.course_id = c.id) AS attempt_count
     FROM courses c
     ORDER BY c.title ASC'
);
$courses = $courseListStmt->fetchAll();

require_once __DIR__ . '/includes/layout-top.php';
?>
<?php
$isEditMode = $editId > 0;
// Auto-open the form modal when the admin is editing a course, or when a
// form submission failed server-side validation (so the half-filled data
// the user typed doesn't silently disappear).
$shouldAutoOpenModal = $isEditMode || ($errorMessage !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Courses</h4>
                  <small class="text-muted">Manage course catalog, passing thresholds, and publication status.</small>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a
                    href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php', ENT_QUOTES, 'UTF-8'); ?>"
                    class="btn btn-outline-primary btn-sm">
                    <i class="bx bx-list-plus me-1"></i>Open Question Builder
                  </a>
                  <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#courseFormModal">
                    <i class="bx bx-plus me-1"></i>Add New Course
                  </button>
                </div>
              </div>

              <div class="card">
                <h5 class="card-header">All Courses</h5>
                <div class="card-body">
<?php if ($courses === []) : ?>
                  <p class="mb-0 text-muted">No courses created yet. Click <strong>Add New Course</strong> to create the first one.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Title</th>
                          <th>Modules</th>
                          <th>Questions</th>
                          <th>Attempts</th>
                          <th>Passing</th>
                          <th>Exam Time</th>
                          <th>Status</th>
                          <th class="text-end">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($courses as $course) : ?>
<?php $isPublished = (int) $course['is_published'] === 1; ?>
                        <tr
                          data-search-item
                          data-search-text="<?php echo htmlspecialchars(((string) $course['title']) . ' ' . ((string) ($course['description'] ?? '')) . ' ' . ((string) ($course['level'] ?? '')) . ' ' . ($isPublished ? 'published' : 'draft'), ENT_QUOTES, 'UTF-8'); ?>">
                          <td>
                            <div class="d-flex align-items-center gap-2">
<?php if (!empty($course['thumbnail_url'])) : ?>
                              <img src="<?php echo htmlspecialchars($resolveThumbnailUrl((string) $course['thumbnail_url']), ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:.35rem;" onerror="this.style.display='none';" />
<?php endif; ?>
                              <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php if (!empty($course['level'])) : ?>
                                <small class="badge bg-label-secondary text-uppercase"><?php echo htmlspecialchars((string) $course['level'], ENT_QUOTES, 'UTF-8'); ?></small>
<?php endif; ?>
<?php if (trim((string) ($course['description'] ?? '')) !== '') : ?>
                                <small class="text-muted d-block text-truncate" style="max-width: 280px;">
                                  <?php echo htmlspecialchars((string) $course['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
<?php endif; ?>
                              </div>
                            </div>
                          </td>
                          <td><?php echo (int) $course['module_count']; ?></td>
                          <td><?php echo (int) $course['question_count']; ?></td>
                          <td><?php echo (int) $course['attempt_count']; ?></td>
                          <td><?php echo number_format((float) $course['passing_score_percentage'], 2); ?>%</td>
                          <td><?php echo (int) $course['final_exam_duration_minutes']; ?> min</td>
                          <td>
                            <span class="badge <?php echo $isPublished ? 'bg-label-success' : 'bg-label-secondary'; ?>">
                              <?php echo $isPublished ? 'Published' : 'Draft'; ?>
                            </span>
                          </td>
                          <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                              <a
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php?course_id=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-sm btn-outline-info"
                                title="Manage questions">
                                <i class="bx bx-list-ul"></i>
                              </a>
                              <a
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php?edit=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-sm btn-outline-primary"
                                title="Edit course">
                                <i class="bx bx-edit-alt"></i>
                              </a>
                              <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="toggle_publish" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?php echo $isPublished ? 'Unpublish' : 'Publish'; ?>">
                                  <i class="bx <?php echo $isPublished ? 'bx-hide' : 'bx-show'; ?>"></i>
                                </button>
                              </form>
                              <form
                                method="post"
                                action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                                class="d-inline js-delete-course-form"
                                data-course-title="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete course">
                                  <i class="bx bx-trash"></i>
                                </button>
                              </form>
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

              <!-- Course create / edit modal -->
              <div
                class="modal fade"
                id="courseFormModal"
                tabindex="-1"
                aria-labelledby="courseFormModalLabel"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <form
                      method="post"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                      id="courseForm"
                      enctype="multipart/form-data"
                      <?php if ($isEditMode) : ?>data-confirm-update="1"<?php endif; ?>>
                      <div class="modal-header">
                        <h5 class="modal-title" id="courseFormModalLabel">
                          <i class="bx <?php echo $isEditMode ? 'bx-edit-alt' : 'bx-plus-circle'; ?> me-1"></i>
                          <?php echo $isEditMode ? 'Edit Course' : 'Add New Course'; ?>
                        </h5>
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="btn-close"
                          aria-label="Close"></a>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="<?php echo $isEditMode ? 'update' : 'create'; ?>" />
                        <input type="hidden" name="existing_thumbnail_url" value="<?php echo htmlspecialchars($formThumbnailUrl, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($isEditMode) : ?>
                        <input type="hidden" name="course_id" value="<?php echo $editId; ?>" />
<?php endif; ?>
                        <div class="mb-3">
                          <label for="title" class="form-label">Title</label>
                          <input
                            type="text"
                            id="title"
                            name="title"
                            maxlength="255"
                            class="form-control"
                            placeholder="e.g., Crime Scene Investigation Fundamentals"
                            value="<?php echo htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8'); ?>"
                            required />
                        </div>
                        <div class="mb-3">
                          <label for="description" class="form-label">Description</label>
                          <textarea
                            id="description"
                            name="description"
                            rows="4"
                            class="form-control"
                            placeholder="Short summary of the course..."><?php echo htmlspecialchars($formDescription, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="row g-3 mb-3">
                          <div class="col-md-6">
                            <label for="level" class="form-label">Level</label>
                            <select id="level" name="level" class="form-select">
                              <option value="" <?php echo $formLevel === '' ? 'selected' : ''; ?>>Unspecified</option>
                              <option value="beginner" <?php echo $formLevel === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                              <option value="intermediate" <?php echo $formLevel === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                              <option value="advanced" <?php echo $formLevel === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                          </div>
                          <div class="col-md-6">
                            <label for="thumbnail_image" class="form-label">Thumbnail Image</label>
                            <input
                              type="file"
                              id="thumbnail_image"
                              name="thumbnail_image"
                              class="form-control"
                              accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" />
                            <small class="text-muted">Optional. Upload JPG/PNG/WEBP/GIF up to 5MB. Leave empty to keep current image.</small>
<?php if ($formThumbnailUrl !== '') : ?>
                            <div class="mt-2 d-flex align-items-center gap-2">
                              <img
                                src="<?php echo htmlspecialchars($resolveThumbnailUrl($formThumbnailUrl), ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Current thumbnail"
                                style="width:52px;height:52px;object-fit:cover;border-radius:.35rem;border:1px solid rgba(15, 32, 75, .15);" />
                              <small class="text-muted">Current thumbnail</small>
                            </div>
                            <div class="form-check mt-2">
                              <input class="form-check-input" type="checkbox" id="remove_thumbnail" name="remove_thumbnail" value="1" />
                              <label class="form-check-label" for="remove_thumbnail">Remove thumbnail</label>
                            </div>
<?php endif; ?>
                          </div>
                        </div>
                        <div class="row g-3 mb-3">
                          <div class="col-md-6">
                            <label for="passing_score_percentage" class="form-label">Passing Score (%)</label>
                            <input
                              type="number"
                              id="passing_score_percentage"
                              name="passing_score_percentage"
                              class="form-control"
                              min="0"
                              max="100"
                              step="0.01"
                              value="<?php echo htmlspecialchars($formPassingScore, ENT_QUOTES, 'UTF-8'); ?>"
                              required />
                          </div>
                          <div class="col-md-6">
                            <label for="final_exam_duration_minutes" class="form-label">Final Exam Time Limit (minutes)</label>
                            <input
                              type="number"
                              id="final_exam_duration_minutes"
                              name="final_exam_duration_minutes"
                              class="form-control"
                              min="1"
                              max="600"
                              step="1"
                              value="<?php echo htmlspecialchars($formFinalExamDuration, ENT_QUOTES, 'UTF-8'); ?>"
                              required />
                            <small class="text-muted">Students get this much time to finish the combined final exam. Default: 45.</small>
                          </div>
                        </div>
                        <div class="form-check form-switch">
                          <input
                            class="form-check-input"
                            type="checkbox"
                            id="is_published"
                            name="is_published"
                            value="1"
                            <?php echo $formIsPublished === 1 ? 'checked' : ''; ?> />
                          <label class="form-check-label" for="is_published">Published (visible to students)</label>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                          <i class="bx <?php echo $isEditMode ? 'bx-save' : 'bx-plus'; ?> me-1"></i>
                          <?php echo $isEditMode ? 'Update Course' : 'Create Course'; ?>
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  if (typeof Swal === 'undefined') return;

                  ClmsNotify.fromFlash(
                    <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                    <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                  );

                  // Re-open the form modal when editing or when a server
                  // validation error just came back, so the admin doesn't
                  // lose the form context.
                  const shouldAutoOpenModal = <?php echo $shouldAutoOpenModal ? 'true' : 'false'; ?>;
                  const openCourseModal = () => {
                    const courseFormModalEl = document.getElementById('courseFormModal');
                    if (!shouldAutoOpenModal || !courseFormModalEl) return;
                    if (window.bootstrap && bootstrap.Modal) {
                      bootstrap.Modal.getOrCreateInstance(courseFormModalEl).show();
                    }
                  };
                  if (document.readyState === 'complete') {
                    openCourseModal();
                  } else {
                    window.addEventListener('load', openCourseModal, { once: true });
                  }

                  const courseForm = document.getElementById('courseForm');
                  if (courseForm && courseForm.dataset.confirmUpdate === '1') {
                    courseForm.addEventListener('submit', (event) => {
                      if (courseForm.dataset.confirmed === '1') return;
                      event.preventDefault();
                      Swal.fire({
                        title: 'Save changes?',
                        text: 'This will update the course details.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, update',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          courseForm.dataset.confirmed = '1';
                          courseForm.submit();
                        }
                      });
                    });
                  }

                  document.querySelectorAll('.js-delete-course-form').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                      if (form.dataset.confirmed === '1') return;
                      event.preventDefault();
                      const title = form.dataset.courseTitle || 'this course';
                      Swal.fire({
                        title: 'Delete "' + title + '"?',
                        html: 'All its <strong>modules, questions, exam attempts, student progress, and certificates</strong> will be permanently removed. This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#d33',
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
