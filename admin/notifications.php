<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/user-approval.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);
clms_user_approval_ensure_schema($pdo);

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
         FROM users
         WHERE role = 'student' AND account_approval_status = 'pending'"
    );
    $pendingCount = (int) ($countStmt->fetch()['c'] ?? 0);

    $listStmt = $pdo->query(
        "SELECT id, first_name, last_name, email, created_at
         FROM users
         WHERE role = 'student' AND account_approval_status = 'pending'
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $rows = $listStmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $email = (string) ($row['email'] ?? '');
        $createdAt = (string) ($row['created_at'] ?? '');
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'email' => $email,
            'created_at_human' => date('M j', strtotime($createdAt) ?: time()),
            'url' => $clmsWebBase . '/admin/users.php?pending=1&q=' . rawurlencode($email),
        ];
    }

    echo json_encode([
        'ok' => true,
        'pending_count' => $pendingCount,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('admin notifications endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Could not load pending notifications.',
    ], JSON_UNESCAPED_SLASHES);
}
