<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/course-publish-schema.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);
clms_ensure_course_publish_schema($pdo);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? 'list');
if ($action !== 'list') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Unsupported action.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $countStmt = $pdo->query(
        "SELECT COUNT(*) AS c
         FROM courses
         WHERE COALESCE(publish_status, 'draft') = 'pending_review'"
    );
    $pendingCount = (int) ($countStmt->fetch()['c'] ?? 0);

    $listStmt = $pdo->query(
        "SELECT
            c.id,
            c.title,
            c.publish_submitted_at,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS submitter_name,
            u.email AS submitter_email
         FROM courses c
         LEFT JOIN users u ON u.id = c.publish_submitted_by
         WHERE COALESCE(c.publish_status, 'draft') = 'pending_review'
         ORDER BY c.publish_submitted_at DESC, c.id DESC
         LIMIT 10"
    );
    $rows = $listStmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $submittedAt = (string) ($row['publish_submitted_at'] ?? '');
        $submitterName = trim((string) ($row['submitter_name'] ?? ''));
        if ($submitterName === '') {
            $submitterName = (string) ($row['submitter_email'] ?? '');
        }
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'submitter' => $submitterName !== '' ? $submitterName : 'Instructor',
            'submitted_at_human' => $submittedAt !== ''
                ? date('M j', strtotime($submittedAt) ?: time())
                : '',
            'url' => 'preview_course.php?course_id=' . (int) ($row['id'] ?? 0),
        ];
    }

    echo json_encode([
        'ok' => true,
        'pending_count' => $pendingCount,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('admin course-review notifications endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Could not load pending course reviews.',
    ], JSON_UNESCAPED_SLASHES);
}
