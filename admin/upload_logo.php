<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['logo'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!in_array($file['type'], $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large. Maximum size is 5MB.']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/public/uploads/logos/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

$extension = match ($file['type']) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    default => 'png',
};

$filename = 'logo_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
$targetPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

require_once dirname(__DIR__) . '/includes/sneat-paths.php';
$relativeUrl = '/public/uploads/logos/' . $filename;
$fullUrl = rtrim($clmsWebBase, '/') . $relativeUrl;

echo json_encode([
    'ok' => true,
    'url' => $fullUrl,
    'filename' => $filename
]);
