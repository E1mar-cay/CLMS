<?php

declare(strict_types=1);

/**
 * Course publish-workflow schema + helpers.
 *
 * Adds an explicit four-state workflow on top of the existing
 * `courses.is_published` boolean so a brand-new course is hidden from
 * students until the instructor submits it for review and an admin
 * approves it.
 *
 *   draft ─submit→ pending_review ─approve→ published
 *                                  ─reject→ changes_requested ─submit→ pending_review
 *                                  ←unpublish─ published
 *
 * Visibility for students is still driven by `is_published = 1` (every
 * existing student query keeps working). The workflow simply keeps
 * `is_published` in lock-step with `publish_status`:
 *   - published          → is_published = 1
 *   - everything else    → is_published = 0
 *
 * The migration is idempotent: safe to call on every page load, just
 * like the other ALTER TABLE checks in this codebase.
 */

if (!function_exists('clms_ensure_course_publish_schema')) {
    function clms_ensure_course_publish_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        // 1. publish_status (ENUM) — primary workflow state.
        try {
            $check = $pdo->query("SHOW COLUMNS FROM courses LIKE 'publish_status'")->fetch();
            if (!$check) {
                $pdo->exec(
                    "ALTER TABLE courses
                        ADD COLUMN publish_status
                        ENUM('draft','pending_review','published','changes_requested')
                        NOT NULL DEFAULT 'draft'
                        AFTER is_published"
                );
                // Backfill: any course that was already is_published=1 is
                // already live for students — keep it that way.
                $pdo->exec("UPDATE courses SET publish_status = 'published' WHERE is_published = 1");
            }
            try {
                $check = $pdo->query("SHOW COLUMNS FROM courses LIKE 'target_track'")->fetch();
                if (!$check) {
                    $pdo->exec(
                        "ALTER TABLE courses
                            ADD COLUMN target_track ENUM('Regular Review','Course Enhancement','All') NOT NULL DEFAULT 'All' AFTER publish_status"
                    );
                }
            } catch (Throwable $e) {
                error_log('courses.target_track migration failed: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log('courses.publish_status migration failed: ' . $e->getMessage());
        }

        // 2. Audit columns: who submitted / who reviewed / when / notes.
        $auditColumns = [
            'publish_submitted_at' => 'DATETIME NULL',
            'publish_submitted_by' => 'INT NULL',
            'publish_reviewed_at'  => 'DATETIME NULL',
            'publish_reviewed_by'  => 'INT NULL',
            'publish_review_notes' => 'TEXT NULL',
        ];
        foreach ($auditColumns as $col => $spec) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM courses LIKE '" . $col . "'")->fetch();
                if (!$check) {
                    $pdo->exec('ALTER TABLE courses ADD COLUMN ' . $col . ' ' . $spec);
                }
            } catch (Throwable $e) {
                error_log('courses.' . $col . ' migration failed: ' . $e->getMessage());
            }
        }

        // 3. Instructor course-request approval, separate from publishing.
        $requestColumns = [
            'request_status' => "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'approved'",
            'request_submitted_at' => 'DATETIME NULL',
            'request_submitted_by' => 'INT NULL',
            'request_reviewed_at' => 'DATETIME NULL',
            'request_reviewed_by' => 'INT NULL',
            'request_review_notes' => 'TEXT NULL',
        ];
        foreach ($requestColumns as $col => $spec) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM courses LIKE '" . $col . "'")->fetch();
                if (!$check) {
                    $pdo->exec('ALTER TABLE courses ADD COLUMN ' . $col . ' ' . $spec);
                }
            } catch (Throwable $e) {
                error_log('courses.' . $col . ' migration failed: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('clms_course_publish_status_meta')) {
    /**
     * Display metadata for a publish_status value.
     *
     * @return array{label: string, badge: string, icon: string}
     */
    function clms_course_publish_status_meta(string $status): array
    {
        return match ($status) {
            'pending_review' => [
                'label' => 'Pending review',
                'badge' => 'bg-label-warning',
                'icon'  => 'bx-time-five',
            ],
            'published' => [
                'label' => 'Published',
                'badge' => 'bg-label-success',
                'icon'  => 'bx-check-circle',
            ],
            'changes_requested' => [
                'label' => 'Changes requested',
                'badge' => 'bg-label-danger',
                'icon'  => 'bx-error-circle',
            ],
            default => [
                'label' => 'Draft',
                'badge' => 'bg-label-secondary',
                'icon'  => 'bx-edit',
            ],
        };
    }
}

if (!function_exists('clms_course_publish_readiness')) {
    /**
     * Whether a course has the minimum content needed to be submitted
     * for publishing review (≥1 module AND ≥1 question).
     *
     * @return array{
     *   can_submit: bool,
     *   reason: string|null,
     *   module_count: int,
     *   question_count: int,
     * }
     */
    function clms_course_publish_readiness(PDO $pdo, int $courseId): array
    {
        $moduleCount   = 0;
        $questionCount = 0;
        try {
            $m = $pdo->prepare('SELECT COUNT(*) FROM modules WHERE course_id = :cid');
            $m->execute(['cid' => $courseId]);
            $moduleCount = (int) $m->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE course_id = :cid');
            $q->execute(['cid' => $courseId]);
            $questionCount = (int) $q->fetchColumn();
        } catch (Throwable $e) {
            error_log('clms_course_publish_readiness: ' . $e->getMessage());
        }

        $reason = null;
        if ($moduleCount === 0 && $questionCount === 0) {
            $reason = 'Add at least one module and one question before submitting.';
        } elseif ($moduleCount === 0) {
            $reason = 'Add at least one module before submitting.';
        } elseif ($questionCount === 0) {
            $reason = 'Add at least one question before submitting.';
        }

        return [
            'can_submit'     => $reason === null,
            'reason'         => $reason,
            'module_count'   => $moduleCount,
            'question_count' => $questionCount,
        ];
    }
}
