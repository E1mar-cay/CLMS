<?php

declare(strict_types=1);

/**
 * Instructor notification endpoint.
 *
 * GET  ?action=list          -> { ok, unread, items: [...] }
 * POST action=mark_all       -> { ok, unread: 0 }
 * POST action=mark_read&announcement_id=N -> { ok, unread }
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

clms_require_roles(['instructor']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list'));

try {
    if ($method === 'POST') {
        if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
            exit;
        }

        if ($action === 'mark_all') {
            clms_notifications_mark_all_read($pdo, $userId);
            echo json_encode([
                'ok' => true,
                'unread' => clms_notifications_unread_count($pdo, $userId),
            ]);
            exit;
        }

        if ($action === 'mark_read') {
            $announcementId = (int) ($_POST['announcement_id'] ?? 0);
            if ($announcementId !== -1 && $announcementId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid announcement id.']);
                exit;
            }
            clms_notifications_mark_read($pdo, $userId, $announcementId);
            echo json_encode([
                'ok' => true,
                'unread' => clms_notifications_unread_count($pdo, $userId),
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;
    }

    $items = clms_notifications_list($pdo, $userId, 10);
    $unread = clms_notifications_unread_count($pdo, $userId);

    echo json_encode([
        'ok' => true,
        'unread' => $unread,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => $item['id'],
                'title' => $item['title'],
                'body' => $item['body'],
                'created_at' => $item['created_at'],
                'time_ago' => clms_notifications_format_time_ago($item['created_at']),
                'is_read' => $item['is_read'],
                'is_mfa_nudge' => !empty($item['is_mfa_nudge']),
            ];
        }, $items),
    ]);
} catch (Throwable $e) {
    error_log('instructor/notifications.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error.']);
}

