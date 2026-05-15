<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';
require_once dirname(__DIR__) . '/includes/course-publish-schema.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Courses | Criminology LMS';
$activeAdminPage = 'courses';
$activeInstructorPage = 'courses';
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

clms_ensure_course_publish_schema($pdo);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS course_instructors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        instructor_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_course_instructor (course_id, instructor_user_id),
        CONSTRAINT fk_course_instructors_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_course_instructors_user FOREIGN KEY (instructor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

try {
    $pdo->exec(
        "UPDATE courses c
         INNER JOIN (
            SELECT course_id, MIN(instructor_user_id) AS instructor_user_id
              FROM course_instructors
             GROUP BY course_id
         ) ci ON ci.course_id = c.id
            SET c.request_status = 'pending',
                c.request_submitted_at = COALESCE(c.request_submitted_at, NOW()),
                c.request_submitted_by = COALESCE(c.request_submitted_by, ci.instructor_user_id)
          WHERE COALESCE(c.request_status, 'approved') = 'approved'
            AND c.request_reviewed_at IS NULL
            AND c.request_reviewed_by IS NULL
            AND COALESCE(c.publish_status, 'draft') = 'draft'
            AND COALESCE(c.is_published, 0) = 0
            AND NOT EXISTS (SELECT 1 FROM modules m WHERE m.course_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.course_id = c.id)"
    );
} catch (Throwable $e) {
    error_log('courses.request_status backfill failed: ' . $e->getMessage());
}

$currentUserRole = (string) ($_SESSION['role'] ?? '');
$currentUserId   = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin         = $currentUserRole === 'admin';
$isInstructor    = $currentUserRole === 'instructor';

$canManageCourse = static function (PDO $pdo, int $courseId, bool $isAdmin, int $currentUserId): bool {
    if ($isAdmin) {
        return true;
    }
    if ($courseId <= 0 || $currentUserId <= 0) {
        return false;
    }
    $scopeStmt = $pdo->prepare(
        'SELECT 1
           FROM courses c
           LEFT JOIN course_instructors ci
             ON ci.course_id = c.id
            AND ci.instructor_user_id = :uid
          WHERE c.id = :course_id
            AND ci.instructor_user_id IS NOT NULL
          LIMIT 1'
    );
    $scopeStmt->execute([
        'uid' => $currentUserId,
        'course_id' => $courseId,
    ]);
    return (bool) $scopeStmt->fetchColumn();
};

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
$formInstructorId = 0;

$instructorOptions = [];
try {
    $instructorOptions = $pdo->query(
        "SELECT id, first_name, last_name, email
           FROM users
          WHERE role = 'instructor'
          ORDER BY last_name ASC, first_name ASC, email ASC"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('courses instructor list failed: ' . $e->getMessage());
}

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
                $assignedInstructorId = $isAdmin
                    ? (int) filter_input(INPUT_POST, 'instructor_user_id', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]])
                    : $currentUserId;

                if ($titleInput === '' || mb_strlen($titleInput) > 255) {
                    throw new RuntimeException('Title is required and must be 255 characters or fewer.');
                }
                if ($assignedInstructorId <= 0) {
                    throw new RuntimeException('Please choose an instructor for this course.');
                }
                $instructorCheckStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'instructor' LIMIT 1");
                $instructorCheckStmt->execute(['id' => $assignedInstructorId]);
                if (!$instructorCheckStmt->fetchColumn()) {
                    throw new RuntimeException('Please choose a valid instructor.');
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
                    $requestStatusValue = $isInstructor ? 'pending' : 'approved';
                    /*
                     * Newly created courses are always created as DRAFT
                     * (publish_status='draft', is_published=0), regardless
                     * of what the form sent. A brand-new course has no
                     * modules / questions yet, so it must not appear on the
                     * student dashboard until the instructor builds it out
                     * and submits it for review.
                     */
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO courses
                            (title, description, passing_score_percentage, is_published, publish_status,
                             request_status, request_submitted_at, request_submitted_by,
                             level, thumbnail_url, final_exam_duration_minutes)
                         VALUES
                            (:title, :description, :passing_score, 0, 'draft',
                             :request_status, :request_submitted_at, :request_submitted_by,
                             :level, :thumbnail_url, :final_exam_duration_minutes)"
                    );
                    $insertStmt->execute([
                        'title' => $titleInput,
                        'description' => $descriptionInput,
                        'passing_score' => number_format($passingScore, 2, '.', ''),
                        'request_status' => $requestStatusValue,
                        'request_submitted_at' => $isInstructor ? date('Y-m-d H:i:s') : null,
                        'request_submitted_by' => $isInstructor ? ($currentUserId ?: null) : null,
                        'level' => $levelValue,
                        'thumbnail_url' => $thumbnailValue,
                        'final_exam_duration_minutes' => $finalExamDurationValue,
                    ]);
                    $createdCourseId = (int) $pdo->lastInsertId();
                    if ($createdCourseId > 0) {
                        $assignStmt = $pdo->prepare(
                            'INSERT IGNORE INTO course_instructors (course_id, instructor_user_id)
                             VALUES (:course_id, :instructor_id)'
                        );
                        $assignStmt->execute([
                            'course_id' => $createdCourseId,
                            'instructor_id' => $assignedInstructorId,
                        ]);
                    }
                    $successMessage = $isInstructor
                        ? 'Course request submitted. An admin can approve or reject it from Courses.'
                        : 'Course created as draft. Build out modules + questions, then submit it for publishing review.';
                } else {
                    $courseIdRaw = $_POST['course_id'] ?? null;
                    $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                    $courseId = $courseId === false ? 0 : (int) $courseId;
                    if ($courseId <= 0) {
                        throw new RuntimeException('Invalid course id.');
                    }
                    if (!$canManageCourse($pdo, $courseId, $isAdmin, $currentUserId)) {
                        throw new RuntimeException('You can only update courses in your scope.');
                    }

                    /*
                     * Course metadata edits never change publish status —
                     * that's driven by the workflow buttons (submit /
                     * approve / reject / unpublish). The admin can still
                     * fix typos in a Published course without accidentally
                     * unpublishing it from the student dashboard.
                     */
                    $updateStmt = $pdo->prepare(
                        'UPDATE courses
                         SET title = :title,
                             description = :description,
                             passing_score_percentage = :passing_score,
                             level = :level,
                             thumbnail_url = :thumbnail_url,
                             final_exam_duration_minutes = :final_exam_duration_minutes
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        'title' => $titleInput,
                        'description' => $descriptionInput,
                        'passing_score' => number_format($passingScore, 2, '.', ''),
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

                    if ($isAdmin) {
                        $pdo->prepare('DELETE FROM course_instructors WHERE course_id = :course_id')
                            ->execute(['course_id' => $courseId]);
                        $pdo->prepare(
                            'INSERT INTO course_instructors (course_id, instructor_user_id)
                             VALUES (:course_id, :instructor_id)'
                        )->execute([
                            'course_id' => $courseId,
                            'instructor_id' => $assignedInstructorId,
                        ]);
                    }

                    $successMessage = 'Course updated successfully.';
                    clms_redirect('admin/courses.php?flash=updated');
                }
            } elseif ($action === 'delete') {
                if (!$isAdmin) {
                    throw new RuntimeException('Only an admin can delete courses.');
                }
                $courseIdRaw = $_POST['course_id'] ?? null;
                $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                $courseId = $courseId === false ? 0 : (int) $courseId;
                if ($courseId <= 0) {
                    throw new RuntimeException('Invalid course id.');
                }

                $courseTitleStmt = $pdo->prepare('SELECT title FROM courses WHERE id = :id LIMIT 1');
                $courseTitleStmt->execute(['id' => $courseId]);
                $courseTitleRow = $courseTitleStmt->fetch();
                $deletedCourseTitle = (string) ($courseTitleRow['title'] ?? '');

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
                clms_audit_ensure_schema($pdo);
                clms_audit_log(
                    $pdo,
                    'course_deleted',
                    'course',
                    $courseId,
                    ['title' => $deletedCourseTitle],
                    (int) ($_SESSION['user_id'] ?? 0)
                );
            } elseif (
                $action === 'approve_course_request'
                || $action === 'reject_course_request'
                ||
                $action === 'submit_for_publish'
                || $action === 'approve_publish'
                || $action === 'reject_publish'
                || $action === 'unpublish_course'
            ) {
                $courseIdRaw = $_POST['course_id'] ?? null;
                $courseId = filter_var($courseIdRaw, FILTER_VALIDATE_INT);
                $courseId = $courseId === false ? 0 : (int) $courseId;
                if ($courseId <= 0) {
                    throw new RuntimeException('Invalid course id.');
                }
                if (!$canManageCourse($pdo, $courseId, $isAdmin, $currentUserId)) {
                    throw new RuntimeException('You can only submit courses in your scope.');
                }

                $statusStmt = $pdo->prepare(
                    "SELECT title, is_published, publish_status, COALESCE(request_status, 'approved') AS request_status
                       FROM courses WHERE id = :id LIMIT 1"
                );
                $statusStmt->execute(['id' => $courseId]);
                $statusRow = $statusStmt->fetch();
                if (!$statusRow) {
                    throw new RuntimeException('Course not found.');
                }
                $currentStatus = (string) ($statusRow['publish_status'] ?? 'draft');
                $requestStatus = (string) ($statusRow['request_status'] ?? 'approved');
                $courseTitleForFlash = (string) ($statusRow['title'] ?? '');

                if ($action === 'approve_course_request') {
                    if (!$isAdmin) {
                        throw new RuntimeException('Only an admin can approve course requests.');
                    }
                    if ($requestStatus !== 'pending') {
                        throw new RuntimeException('Only pending course requests can be approved.');
                    }
                    $approveRequestStmt = $pdo->prepare(
                        "UPDATE courses
                            SET request_status = 'approved',
                                request_reviewed_at = NOW(),
                                request_reviewed_by = :uid,
                                request_review_notes = NULL
                          WHERE id = :id"
                    );
                    $approveRequestStmt->execute(['uid' => $currentUserId ?: null, 'id' => $courseId]);
                    $successMessage = 'Course request approved. The instructor can now build content and submit it for publishing review.';
                } elseif ($action === 'reject_course_request') {
                    if (!$isAdmin) {
                        throw new RuntimeException('Only an admin can reject course requests.');
                    }
                    if ($requestStatus !== 'pending') {
                        throw new RuntimeException('Only pending course requests can be rejected.');
                    }
                    $notesInput = trim((string) ($_POST['publish_review_notes'] ?? ''));
                    if ($notesInput === '') {
                        throw new RuntimeException('Please include a short note explaining why this course request is rejected.');
                    }
                    if (mb_strlen($notesInput) > 2000) {
                        throw new RuntimeException('Review notes must be 2000 characters or fewer.');
                    }
                    $rejectRequestStmt = $pdo->prepare(
                        "UPDATE courses
                            SET request_status = 'rejected',
                                request_reviewed_at = NOW(),
                                request_reviewed_by = :uid,
                                request_review_notes = :notes
                          WHERE id = :id"
                    );
                    $rejectRequestStmt->execute([
                        'uid' => $currentUserId ?: null,
                        'notes' => $notesInput,
                        'id' => $courseId,
                    ]);
                    $successMessage = 'Course request rejected and sent back to the instructor.';
                } elseif ($action === 'submit_for_publish') {
                    if ($requestStatus !== 'approved') {
                        throw new RuntimeException('This course request must be approved before it can be submitted for publishing.');
                    }
                    if (!in_array($currentStatus, ['draft', 'changes_requested'], true)) {
                        throw new RuntimeException('This course is not in a state that can be submitted for review.');
                    }
                    $readiness = clms_course_publish_readiness($pdo, $courseId);
                    if (!$readiness['can_submit']) {
                        throw new RuntimeException((string) $readiness['reason']);
                    }
                    $submitStmt = $pdo->prepare(
                        "UPDATE courses
                            SET publish_status = 'pending_review',
                                is_published = 0,
                                publish_submitted_at = NOW(),
                                publish_submitted_by = :uid,
                                publish_reviewed_at = NULL,
                                publish_reviewed_by = NULL,
                                publish_review_notes = NULL
                          WHERE id = :id"
                    );
                    $submitStmt->execute(['uid' => $currentUserId ?: null, 'id' => $courseId]);
                    $successMessage = 'Course submitted for publishing review. An admin will be notified to approve it.';
                } elseif ($action === 'approve_publish') {
                    if (!$isAdmin) {
                        throw new RuntimeException('Only an admin can approve a course for publishing.');
                    }
                    if ($currentStatus !== 'pending_review') {
                        throw new RuntimeException('Only courses waiting for review can be approved.');
                    }
                    $approveStmt = $pdo->prepare(
                        "UPDATE courses
                            SET publish_status = 'published',
                                is_published = 1,
                                publish_reviewed_at = NOW(),
                                publish_reviewed_by = :uid,
                                publish_review_notes = NULL
                          WHERE id = :id"
                    );
                    $approveStmt->execute(['uid' => $currentUserId ?: null, 'id' => $courseId]);
                    $successMessage = 'Course approved and published — it is now visible to students.';
                } elseif ($action === 'reject_publish') {
                    if (!$isAdmin) {
                        throw new RuntimeException('Only an admin can request changes on a course.');
                    }
                    if ($currentStatus !== 'pending_review') {
                        throw new RuntimeException('Only courses waiting for review can be sent back for changes.');
                    }
                    $notesInput = trim((string) ($_POST['publish_review_notes'] ?? ''));
                    if ($notesInput === '') {
                        throw new RuntimeException('Please include a short note explaining what needs to change.');
                    }
                    if (mb_strlen($notesInput) > 2000) {
                        throw new RuntimeException('Review notes must be 2000 characters or fewer.');
                    }
                    $rejectStmt = $pdo->prepare(
                        "UPDATE courses
                            SET publish_status = 'changes_requested',
                                is_published = 0,
                                publish_reviewed_at = NOW(),
                                publish_reviewed_by = :uid,
                                publish_review_notes = :notes
                          WHERE id = :id"
                    );
                    $rejectStmt->execute([
                        'uid' => $currentUserId ?: null,
                        'notes' => $notesInput,
                        'id' => $courseId,
                    ]);
                    $successMessage = 'Course sent back to the instructor with your notes.';
                } elseif ($action === 'unpublish_course') {
                    if (!$isAdmin) {
                        throw new RuntimeException('Only an admin can unpublish a course.');
                    }
                    if ($currentStatus !== 'published') {
                        throw new RuntimeException('Only published courses can be unpublished.');
                    }
                    $unpublishStmt = $pdo->prepare(
                        "UPDATE courses
                            SET publish_status = 'draft',
                                is_published = 0,
                                publish_reviewed_at = NOW(),
                                publish_reviewed_by = :uid
                          WHERE id = :id"
                    );
                    $unpublishStmt->execute(['uid' => $currentUserId ?: null, 'id' => $courseId]);
                    $successMessage = 'Course unpublished — it has been hidden from students and returned to draft.';
                }

                clms_audit_ensure_schema($pdo);
                clms_audit_log(
                    $pdo,
                    'course_' . $action,
                    'course',
                    $courseId,
                    [
                        'title' => $courseTitleForFlash,
                        'previous_status' => $currentStatus,
                        'previous_request_status' => $requestStatus,
                    ],
                    $currentUserId
                );
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
        'approved' => 'Course approved and published — it is now visible to students.',
        'rejected' => 'Course sent back to the instructor with your notes.',
    ];
    if (isset($flashMap[$flashCode])) {
        $successMessage = $flashMap[$flashCode];
    }
}

$formPublishStatus = 'draft';
$formPublishReviewNotes = '';

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, title, description, passing_score_percentage, is_published, publish_status,
                publish_review_notes, level, thumbnail_url, final_exam_duration_minutes,
                (SELECT ci.instructor_user_id
                   FROM course_instructors ci
                  WHERE ci.course_id = courses.id
                  ORDER BY ci.id ASC
                  LIMIT 1) AS assigned_instructor_id
         FROM courses WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch();
    if ($editRow && $canManageCourse($pdo, $editId, $isAdmin, $currentUserId)) {
        $formTitle = (string) $editRow['title'];
        $formDescription = (string) ($editRow['description'] ?? '');
        $formPassingScore = number_format((float) $editRow['passing_score_percentage'], 2, '.', '');
        $formIsPublished = (int) $editRow['is_published'];
        $formPublishStatus = (string) ($editRow['publish_status'] ?? 'draft');
        $formPublishReviewNotes = (string) ($editRow['publish_review_notes'] ?? '');
        $formLevel = (string) ($editRow['level'] ?? '');
        $formThumbnailUrl = (string) ($editRow['thumbnail_url'] ?? '');
        $formFinalExamDuration = (string) ((int) ($editRow['final_exam_duration_minutes'] ?? 45));
        $formInstructorId = (int) ($editRow['assigned_instructor_id'] ?? 0);
    } else {
        if ($editRow) {
            $errorMessage = 'You can only edit courses in your scope.';
        }
        $editId = 0;
    }
}

$courseScopeWhere = '';
if (!$isAdmin) {
    $courseScopeWhere = 'WHERE ci_scope.instructor_user_id IS NOT NULL';
}

$courseListStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.title,
        c.description,
        c.passing_score_percentage,
        c.is_published,
        COALESCE(c.publish_status, 'draft') AS publish_status,
        COALESCE(c.request_status, 'approved') AS request_status,
        c.request_submitted_at,
        c.request_reviewed_at,
        c.request_review_notes,
        c.publish_submitted_at,
        c.publish_reviewed_at,
        c.publish_review_notes,
        TRIM(CONCAT(COALESCE(rsb.first_name, ''), ' ', COALESCE(rsb.last_name, ''))) AS request_submitted_by_name,
        TRIM(CONCAT(COALESCE(rrb.first_name, ''), ' ', COALESCE(rrb.last_name, ''))) AS request_reviewed_by_name,
        TRIM(CONCAT(COALESCE(sb.first_name, ''), ' ', COALESCE(sb.last_name, ''))) AS publish_submitted_by_name,
        TRIM(CONCAT(COALESCE(rb.first_name, ''), ' ', COALESCE(rb.last_name, ''))) AS publish_reviewed_by_name,
        c.level,
        c.thumbnail_url,
        COALESCE(c.final_exam_duration_minutes, 45) AS final_exam_duration_minutes,
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(CONCAT(COALESCE(iu.first_name, ''), ' ', COALESCE(iu.last_name, ''))), '') ORDER BY iu.last_name, iu.first_name SEPARATOR ', ') AS instructor_names,
        (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS module_count,
        (SELECT COUNT(*) FROM questions q WHERE q.course_id = c.id) AS question_count,
        (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.course_id = c.id) AS attempt_count
     FROM courses c
     LEFT JOIN users rsb ON rsb.id = c.request_submitted_by
     LEFT JOIN users rrb ON rrb.id = c.request_reviewed_by
     LEFT JOIN users sb ON sb.id = c.publish_submitted_by
     LEFT JOIN users rb ON rb.id = c.publish_reviewed_by
     LEFT JOIN course_instructors ci_scope
       ON ci_scope.course_id = c.id
      AND ci_scope.instructor_user_id = :uid
     LEFT JOIN course_instructors ci_all ON ci_all.course_id = c.id
     LEFT JOIN users iu ON iu.id = ci_all.instructor_user_id
     {$courseScopeWhere}
     GROUP BY
        c.id,
        c.title,
        c.description,
        c.passing_score_percentage,
        c.is_published,
        c.publish_status,
        c.request_status,
        c.request_submitted_at,
        c.request_reviewed_at,
        c.request_review_notes,
        rsb.first_name,
        rsb.last_name,
        rrb.first_name,
        rrb.last_name,
        c.publish_submitted_at,
        c.publish_reviewed_at,
        c.publish_review_notes,
        sb.first_name,
        sb.last_name,
        rb.first_name,
        rb.last_name,
        c.level,
        c.thumbnail_url,
        c.final_exam_duration_minutes
     ORDER BY
        FIELD(COALESCE(c.publish_status, 'draft'), 'pending_review', 'changes_requested', 'draft', 'published'),
        c.title ASC"
);
$courseListStmt->execute(['uid' => $currentUserId]);
$courses = $courseListStmt->fetchAll();

$pendingReviewCount = 0;
foreach ($courses as $courseRow) {
    if ((string) ($courseRow['publish_status'] ?? '') === 'pending_review') {
        $pendingReviewCount++;
    }
}
$courseBuilderPath = $isAdmin ? '/admin/add_question.php' : '/instructor/add_question.php';

require_once $isAdmin
    ? __DIR__ . '/includes/layout-top.php'
    : dirname(__DIR__) . '/instructor/includes/layout-top.php';
?>
<?php
$isEditMode = $editId > 0;
// Auto-open the form modal when the admin is editing a course, or when a
// form submission failed server-side validation (so the half-filled data
// the user typed doesn't silently disappear).
$shouldAutoOpenModal = $isEditMode || ($errorMessage !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
?>
              <div class="d-flex flex-wrap align-items-center py-3 mb-3 gap-2">
                <div class="flex-grow-1" style="min-width: 0;">
                  <h4 class="fw-bold mb-1">
                    <?php echo $isAdmin ? 'Courses' : 'My Course Requests'; ?>
<?php if ($isAdmin && $pendingReviewCount > 0) : ?>
                    <span class="badge bg-label-warning align-middle ms-2"><?php echo $pendingReviewCount; ?> awaiting review</span>
<?php endif; ?>
                  </h4>
                  <small class="text-muted">
<?php if ($isAdmin) : ?>
                    Review instructor course submissions, approve publish requests, and manage course details.
<?php else : ?>
                    Create a course draft, build modules + questions, then submit it for admin approval before students can see it.
<?php endif; ?>
                  </small>
                </div>
                <div class="d-flex flex-wrap gap-2 ms-auto justify-content-end">
                  <a
                    href="<?php echo htmlspecialchars($clmsWebBase . $courseBuilderPath, ENT_QUOTES, 'UTF-8'); ?>"
                    class="btn btn-outline-primary btn-sm">
                    <i class="bx bx-list-plus me-1"></i>Open Question Builder
                  </a>
                  <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#courseFormModal">
                    <i class="bx bx-plus me-1"></i><?php echo $isAdmin ? 'Add New Course' : 'Request Course'; ?>
                  </button>
                </div>
              </div>

              <div class="card">
                <h5 class="card-header"><?php echo $isAdmin ? 'All Courses' : 'My Courses'; ?></h5>
                <div class="card-body">
<?php if ($courses === []) : ?>
                  <p class="mb-0 text-muted"><?php echo $isAdmin ? 'No courses created yet.' : 'No course requests yet. Click Request Course to create your first draft.'; ?></p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Title</th>
<?php if ($isAdmin) : ?>
                          <th>Instructor</th>
<?php endif; ?>
                          <th>Status</th>
                          <th class="text-end" style="min-width: 220px;">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($courses as $course) : ?>
<?php
                        $isPublished = (int) $course['is_published'] === 1;
                        $publishStatus = (string) ($course['publish_status'] ?? 'draft');
                        $requestStatus = (string) ($course['request_status'] ?? 'approved');
                        $publishMeta = clms_course_publish_status_meta($publishStatus);
                        $courseModuleCount = (int) $course['module_count'];
                        $courseQuestionCount = (int) $course['question_count'];
                        $requestApproved = $requestStatus === 'approved' || $requestStatus === 'none';
                        $canSubmitNow = $requestApproved && $courseModuleCount > 0 && $courseQuestionCount > 0;
                        $submitBlockedReason = '';
                        if (!$requestApproved) {
                            $submitBlockedReason = $requestStatus === 'pending'
                                ? 'Admin must approve this course request first.'
                                : 'This course request was rejected.';
                        } elseif (!$canSubmitNow) {
                            if ($courseModuleCount === 0 && $courseQuestionCount === 0) {
                                $submitBlockedReason = 'Add at least one module and one question first.';
                            } elseif ($courseModuleCount === 0) {
                                $submitBlockedReason = 'Add at least one module first.';
                            } else {
                                $submitBlockedReason = 'Add at least one question first.';
                            }
                        }
                        $reviewNotes = trim((string) ($course['publish_review_notes'] ?? ''));
                        $requestNotes = trim((string) ($course['request_review_notes'] ?? ''));
                        $submittedByName = trim((string) ($course['publish_submitted_by_name'] ?? ''));
                        $reviewedByName = trim((string) ($course['publish_reviewed_by_name'] ?? ''));
                        $requestSubmittedByName = trim((string) ($course['request_submitted_by_name'] ?? ''));
                        $requestReviewedByName = trim((string) ($course['request_reviewed_by_name'] ?? ''));
?>
                        <tr
                          data-search-item
                          data-search-text="<?php echo htmlspecialchars(((string) $course['title']) . ' ' . ((string) ($course['description'] ?? '')) . ' ' . ((string) ($course['level'] ?? '')) . ' ' . $publishStatus . ' ' . $publishMeta['label'], ENT_QUOTES, 'UTF-8'); ?>">
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
<?php if ($isAdmin) : ?>
                          <td>
                            <small class="text-muted">
                              <?php echo htmlspecialchars(trim((string) ($course['instructor_names'] ?? '')) !== '' ? (string) $course['instructor_names'] : 'Unassigned', ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                          </td>
<?php endif; ?>
                          <td>
<?php if ($requestStatus === 'pending') : ?>
                            <span class="badge bg-label-warning">
                              <i class="bx bx-time-five me-1"></i>Request pending
                            </span>
<?php elseif ($requestStatus === 'rejected') : ?>
                            <span class="badge bg-label-danger">
                              <i class="bx bx-x-circle me-1"></i>Request rejected
                            </span>
<?php else : ?>
                            <span class="badge <?php echo htmlspecialchars($publishMeta['badge'], ENT_QUOTES, 'UTF-8'); ?>">
                              <i class="bx <?php echo htmlspecialchars($publishMeta['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                              <?php echo htmlspecialchars($publishMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
<?php endif; ?>
<?php if ($requestStatus === 'pending' && $requestSubmittedByName !== '') : ?>
                            <small class="text-muted d-block mt-1">Requested by <?php echo htmlspecialchars($requestSubmittedByName, ENT_QUOTES, 'UTF-8'); ?></small>
<?php elseif ($requestStatus === 'approved' && $requestReviewedByName !== '') : ?>
                            <small class="text-muted d-block mt-1">Request approved by <?php echo htmlspecialchars($requestReviewedByName, ENT_QUOTES, 'UTF-8'); ?></small>
<?php elseif ($requestStatus === 'rejected' && $requestNotes !== '') : ?>
                            <small class="text-muted d-block mt-1 text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($requestNotes, ENT_QUOTES, 'UTF-8'); ?>">
                              <i class="bx bx-message-detail"></i> <?php echo htmlspecialchars($requestNotes, ENT_QUOTES, 'UTF-8'); ?>
                            </small>
<?php endif; ?>
<?php if ($publishStatus === 'pending_review' && $submittedByName !== '') : ?>
                            <small class="text-muted d-block mt-1">Submitted by <?php echo htmlspecialchars($submittedByName, ENT_QUOTES, 'UTF-8'); ?></small>
<?php elseif ($publishStatus === 'published' && $reviewedByName !== '') : ?>
                            <small class="text-muted d-block mt-1">Approved by <?php echo htmlspecialchars($reviewedByName, ENT_QUOTES, 'UTF-8'); ?></small>
<?php elseif ($publishStatus === 'changes_requested' && $reviewNotes !== '') : ?>
                            <small class="text-muted d-block mt-1 text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($reviewNotes, ENT_QUOTES, 'UTF-8'); ?>">
                              <i class="bx bx-message-detail"></i> <?php echo htmlspecialchars($reviewNotes, ENT_QUOTES, 'UTF-8'); ?>
                            </small>
<?php endif; ?>
                          </td>
                          <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end flex-wrap" style="min-width: 220px;">
<?php if ($isAdmin) : ?>
                              <a
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/preview_course.php?course_id=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-sm <?php echo $publishStatus === 'pending_review' ? 'btn-warning' : 'btn-outline-secondary'; ?>"
                                title="<?php echo $publishStatus === 'pending_review' ? 'Preview &amp; review submission' : 'Preview course content'; ?>">
                                <i class="bx bx-show"></i><?php echo $publishStatus === 'pending_review' ? '<span class="d-none d-md-inline ms-1">Review</span>' : ''; ?>
                              </a>
<?php endif; ?>
                              <a
                                href="<?php echo htmlspecialchars($clmsWebBase . $courseBuilderPath . '?course_id=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-sm btn-outline-info <?php echo $isInstructor && !$requestApproved ? 'disabled' : ''; ?>"
                                title="<?php echo $isInstructor && !$requestApproved ? 'Wait for admin approval before building content' : 'Manage questions'; ?>">
                                <i class="bx bx-list-ul"></i>
                              </a>
                              <a
                                href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php?edit=' . (int) $course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="btn btn-sm btn-outline-primary"
                                title="Edit course">
                                <i class="bx bx-edit-alt"></i>
                              </a>
<?php if ($isAdmin && $requestStatus === 'pending') : ?>
                              <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-approve-request-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="approve_course_request" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-success" title="Approve course request">
                                  <i class="bx bx-check"></i><span class="d-none d-md-inline ms-1">Approve</span>
                                </button>
                              </form>
                              <button
                                type="button"
                                class="btn btn-sm btn-outline-danger js-reject-request-trigger"
                                data-bs-toggle="modal"
                                data-bs-target="#rejectRequestModal"
                                data-course-id="<?php echo (int) $course['id']; ?>"
                                data-course-title="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                onclick="document.getElementById('rejectRequestCourseId').value=this.dataset.courseId||'';document.getElementById('rejectRequestCourseTitle').textContent=this.dataset.courseTitle||'this course request';var n=document.getElementById('request_review_notes');if(n)n.value='';"
                                title="Reject course request">
                                <i class="bx bx-x"></i><span class="d-none d-md-inline ms-1">Reject</span>
                              </button>
<?php endif; ?>
<?php if ($requestApproved && in_array($publishStatus, ['draft', 'changes_requested'], true)) : ?>
                              <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-submit-publish-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="submit_for_publish" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button
                                  type="submit"
                                  class="btn btn-sm btn-warning"
                                  title="<?php echo $canSubmitNow ? 'Submit for publishing review' : htmlspecialchars($submitBlockedReason, ENT_QUOTES, 'UTF-8'); ?>"
                                  <?php echo $canSubmitNow ? '' : 'disabled'; ?>>
                                  <i class="bx bx-upload"></i><span class="d-none d-md-inline ms-1">Submit</span>
                                </button>
                              </form>
<?php endif; ?>
<?php if ($isAdmin && $publishStatus === 'pending_review') : ?>
                              <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-approve-publish-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="approve_publish" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-success" title="Approve and publish">
                                  <i class="bx bx-check"></i><span class="d-none d-md-inline ms-1">Approve</span>
                                </button>
                              </form>
                              <button
                                type="button"
                                class="btn btn-sm btn-outline-danger js-reject-publish-trigger"
                                data-course-id="<?php echo (int) $course['id']; ?>"
                                data-course-title="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                title="Send back to instructor with notes">
                                <i class="bx bx-x"></i><span class="d-none d-md-inline ms-1">Reject</span>
                              </button>
<?php endif; ?>
<?php if ($isAdmin && $publishStatus === 'published') : ?>
                              <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline js-unpublish-course-form" data-course-title="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="unpublish_course" />
                                <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>" />
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Unpublish (hide from students)">
                                  <i class="bx bx-hide"></i>
                                </button>
                              </form>
<?php endif; ?>
<?php if ($isAdmin) : ?>
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
<?php endif; ?>
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
                <div class="modal-dialog modal-lg modal-dialog-centered" style="height: calc(100vh - 2rem);">
                  <div class="modal-content h-100">
                    <form
                      method="post"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                      id="courseForm"
                      enctype="multipart/form-data"
                      class="d-flex flex-column h-100"
                      <?php if ($isEditMode) : ?>data-confirm-update="1"<?php endif; ?>>
                      <div class="modal-header">
                        <h5 class="modal-title" id="courseFormModalLabel">
                          <i class="bx <?php echo $isEditMode ? 'bx-edit-alt' : 'bx-plus-circle'; ?> me-1"></i>
                          <?php echo $isEditMode ? 'Edit Course' : ($isAdmin ? 'Add New Course' : 'Request Course'); ?>
                        </h5>
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="btn-close"
                          aria-label="Close"></a>
                      </div>
                      <div class="modal-body flex-grow-1" style="overflow-y: auto; min-height: 0;">
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
<?php if ($isAdmin) : ?>
                        <div class="mb-3">
                          <label for="instructor_user_id" class="form-label">Assigned Instructor</label>
                          <select id="instructor_user_id" name="instructor_user_id" class="form-select" required>
                            <option value="">Choose instructor</option>
<?php foreach ($instructorOptions as $instructorOption) : ?>
<?php
                            $instructorOptionId = (int) $instructorOption['id'];
                            $instructorName = trim((string) ($instructorOption['first_name'] ?? '') . ' ' . (string) ($instructorOption['last_name'] ?? ''));
                            if ($instructorName === '') {
                                $instructorName = (string) ($instructorOption['email'] ?? ('Instructor #' . $instructorOptionId));
                            }
?>
                            <option value="<?php echo $instructorOptionId; ?>" <?php echo $formInstructorId === $instructorOptionId ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($instructorName, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
<?php endforeach; ?>
                          </select>
                          <small class="text-muted">Only this instructor can see and build this course.</small>
<?php if ($instructorOptions === []) : ?>
                          <div class="alert alert-warning mt-2 mb-0">Create an instructor account before adding courses.</div>
<?php endif; ?>
                        </div>
<?php endif; ?>
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
<?php if ($isEditMode) : ?>
<?php $editMeta = clms_course_publish_status_meta($formPublishStatus); ?>
                        <div class="alert alert-light border d-flex align-items-start gap-2 mb-0" role="status">
                          <i class="bx <?php echo htmlspecialchars($editMeta['icon'], ENT_QUOTES, 'UTF-8'); ?> fs-4 mt-1"></i>
                          <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                              <span class="badge <?php echo htmlspecialchars($editMeta['badge'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($editMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                              </span>
                              <strong>Publishing status</strong>
                            </div>
                            <small class="text-muted d-block">
<?php if ($formPublishStatus === 'draft') : ?>
                              This course is hidden from students. Add modules + questions, then use <em>Submit</em> on the courses list to send it for review.
<?php elseif ($formPublishStatus === 'pending_review') : ?>
                              Waiting for admin approval. Students still can&rsquo;t see it.
<?php elseif ($formPublishStatus === 'published') : ?>
                              Visible on the student dashboard. Editing details here will <strong>not</strong> unpublish the course.
<?php elseif ($formPublishStatus === 'changes_requested') : ?>
                              An admin sent this back for changes. After fixing it, submit it again from the courses list.
<?php endif; ?>
                            </small>
<?php if ($formPublishStatus === 'changes_requested' && trim($formPublishReviewNotes) !== '') : ?>
                            <div class="mt-2 small">
                              <strong>Admin notes:</strong>
                              <span class="d-block text-body"><?php echo nl2br(htmlspecialchars($formPublishReviewNotes, ENT_QUOTES, 'UTF-8')); ?></span>
                            </div>
<?php endif; ?>
                          </div>
                        </div>
<?php else : ?>
                        <div class="alert alert-info d-flex align-items-start gap-2 mb-0" role="status">
                          <i class="bx bx-info-circle fs-4 mt-1"></i>
                          <div>
                            <strong>New courses start as Draft.</strong>
                            <small class="d-block text-muted"><?php echo $isAdmin ? 'After creating, add modules + questions, then submit the course for publishing review.' : 'After creating, add modules + questions, then submit the course for admin review. Admin approval is required before students can see it.'; ?></small>
                          </div>
                        </div>
<?php endif; ?>
                      </div>
                      <div class="modal-footer flex-shrink-0">
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" <?php echo $isAdmin && $instructorOptions === [] ? 'disabled' : ''; ?>>
                          <i class="bx <?php echo $isEditMode ? 'bx-save' : 'bx-plus'; ?> me-1"></i>
                          <?php echo $isEditMode ? 'Update Course' : ($isAdmin ? 'Create Course' : 'Create Request'); ?>
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

<?php if ($isAdmin) : ?>
              <!-- Reject / send-back modal (admin only) -->
              <div
                class="modal fade"
                id="rejectPublishModal"
                tabindex="-1"
                aria-labelledby="rejectPublishModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form
                      method="post"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                      id="rejectPublishForm">
                      <div class="modal-header">
                        <h5 class="modal-title" id="rejectPublishModalLabel">
                          <i class="bx bx-message-detail me-1"></i>Send course back for changes
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="reject_publish" />
                        <input type="hidden" name="course_id" id="rejectPublishCourseId" value="" />
                        <p class="mb-2">
                          Rejecting <strong id="rejectPublishCourseTitle">this course</strong> sends it back to the instructor and hides it from students.
                        </p>
                        <div class="mb-2">
                          <label for="publish_review_notes" class="form-label">What needs to change?</label>
                          <textarea
                            id="publish_review_notes"
                            name="publish_review_notes"
                            class="form-control"
                            rows="4"
                            maxlength="2000"
                            placeholder="e.g., Module 2 is missing the quiz questions, and the passing score is too low."
                            required></textarea>
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

              <div
                class="modal fade"
                id="rejectRequestModal"
                tabindex="-1"
                aria-labelledby="rejectRequestModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <form
                      method="post"
                      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                      id="rejectRequestForm">
                      <div class="modal-header">
                        <h5 class="modal-title" id="rejectRequestModalLabel">
                          <i class="bx bx-message-x me-1"></i>Reject course request
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="reject_course_request" />
                        <input type="hidden" name="course_id" id="rejectRequestCourseId" value="" />
                        <p class="mb-2">
                          Rejecting <strong id="rejectRequestCourseTitle">this course request</strong> sends it back to the instructor.
                        </p>
                        <div class="mb-2">
                          <label for="request_review_notes" class="form-label">Why is this request rejected?</label>
                          <textarea
                            id="request_review_notes"
                            name="publish_review_notes"
                            class="form-control"
                            rows="4"
                            maxlength="2000"
                            placeholder="e.g., This course duplicates an existing course."
                            required></textarea>
                          <small class="text-muted">The instructor will see this note.</small>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                          <i class="bx bx-x me-1"></i>Reject Request
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
                  const hasSwal = typeof Swal !== 'undefined';

                  if (window.ClmsNotify && typeof ClmsNotify.fromFlash === 'function') {
                    ClmsNotify.fromFlash(
                      <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                      <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                    );
                  }

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
                  if (hasSwal && courseForm && courseForm.dataset.confirmUpdate === '1') {
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

                  if (hasSwal) {
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

                    document.querySelectorAll('.js-submit-publish-form').forEach((form) => {
                      form.addEventListener('submit', (event) => {
                        if (form.dataset.confirmed === '1') return;
                        event.preventDefault();
                        Swal.fire({
                          title: 'Submit for publishing?',
                          text: 'An admin will review the course before it appears on the student dashboard.',
                          icon: 'question',
                          showCancelButton: true,
                          confirmButtonText: 'Yes, submit',
                          cancelButtonText: 'Cancel',
                          confirmButtonColor: '#0f204b',
                        }).then((result) => {
                          if (result.isConfirmed) {
                            form.dataset.confirmed = '1';
                            form.submit();
                          }
                        });
                      });
                    });

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

                    document.querySelectorAll('.js-approve-request-form').forEach((form) => {
                      form.addEventListener('submit', (event) => {
                        if (form.dataset.confirmed === '1') return;
                        event.preventDefault();
                        Swal.fire({
                          title: 'Approve course request?',
                          text: 'The instructor can build the course content after approval. It will still stay hidden from students.',
                          icon: 'success',
                          showCancelButton: true,
                          confirmButtonText: 'Yes, approve',
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

                    document.querySelectorAll('.js-unpublish-course-form').forEach((form) => {
                      form.addEventListener('submit', (event) => {
                        if (form.dataset.confirmed === '1') return;
                        event.preventDefault();
                        const title = form.dataset.courseTitle || 'this course';
                        Swal.fire({
                          title: 'Unpublish "' + title + '"?',
                          text: 'Students will no longer see it on their dashboard. The course will return to Draft.',
                          icon: 'warning',
                          showCancelButton: true,
                          confirmButtonText: 'Yes, unpublish',
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
                  }

                  const rejectModalEl = document.getElementById('rejectPublishModal');
                  if (rejectModalEl && window.bootstrap && bootstrap.Modal) {
                    const rejectModal = bootstrap.Modal.getOrCreateInstance(rejectModalEl);
                    const rejectCourseIdInput = document.getElementById('rejectPublishCourseId');
                    const rejectCourseTitleEl = document.getElementById('rejectPublishCourseTitle');
                    const rejectNotesInput = document.getElementById('publish_review_notes');
                    document.querySelectorAll('.js-reject-publish-trigger').forEach((btn) => {
                      btn.addEventListener('click', () => {
                        if (rejectCourseIdInput) rejectCourseIdInput.value = btn.dataset.courseId || '';
                        if (rejectCourseTitleEl) rejectCourseTitleEl.textContent = btn.dataset.courseTitle || 'this course';
                        if (rejectNotesInput) rejectNotesInput.value = '';
                        rejectModal.show();
                      });
                    });
                  }

                  const rejectRequestModalEl = document.getElementById('rejectRequestModal');
                  if (rejectRequestModalEl && window.bootstrap && bootstrap.Modal) {
                    const rejectRequestModal = bootstrap.Modal.getOrCreateInstance(rejectRequestModalEl);
                    const rejectRequestCourseIdInput = document.getElementById('rejectRequestCourseId');
                    const rejectRequestCourseTitleEl = document.getElementById('rejectRequestCourseTitle');
                    const rejectRequestNotesInput = document.getElementById('request_review_notes');
                    document.querySelectorAll('.js-reject-request-trigger').forEach((btn) => {
                      btn.addEventListener('click', () => {
                        if (rejectRequestCourseIdInput) rejectRequestCourseIdInput.value = btn.dataset.courseId || '';
                        if (rejectRequestCourseTitleEl) rejectRequestCourseTitleEl.textContent = btn.dataset.courseTitle || 'this course request';
                        if (rejectRequestNotesInput) rejectRequestNotesInput.value = '';
                        rejectRequestModal.show();
                      });
                    });
                  }
                })();
              </script>

<?php
require_once $isAdmin
    ? __DIR__ . '/includes/layout-bottom.php'
    : dirname(__DIR__) . '/instructor/includes/layout-bottom.php';
