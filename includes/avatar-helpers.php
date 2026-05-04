<?php

declare(strict_types=1);

/**
 * User profile avatar uploads (stored under public/assets/uploads/avatars).
 */

const CLMS_AVATAR_MAX_BYTES = 2097152; // 2 MiB
const CLMS_AVATAR_OUTPUT_SIZE = 256;

function clms_avatar_ensure_schema(PDO $pdo): void
{
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_url'")->fetch();
        if (!$check) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar_url VARCHAR(512) NULL');
        }
    } catch (Throwable $e) {
        error_log('users.avatar_url migration failed: ' . $e->getMessage());
    }
}

/**
 * Turn a DB path or URL into a browser-ready href (mirrors course thumbnail resolution).
 */
function clms_avatar_resolve_url(?string $rawPath, string $clmsWebBase): string
{
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return rtrim($clmsWebBase, '/') . $path;
    }

    return rtrim($clmsWebBase, '/') . '/' . ltrim($path, '/');
}

/**
 * @return string empty string on success, or user-facing error message
 */
function clms_avatar_save_uploaded_file(int $userId, array $file, string $fsDir): string
{
    if ($userId <= 0) {
        return 'Invalid user.';
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return 'No file was uploaded.';
    }
    if ($err !== UPLOAD_ERR_OK) {
        return 'Upload failed. Please try again.';
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Invalid upload payload.';
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > CLMS_AVATAR_MAX_BYTES) {
        return 'Image must be 2MB or smaller.';
    }
    if ($size <= 0) {
        return 'Uploaded file is empty.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        return 'Please upload a JPG, PNG, WEBP, or GIF image.';
    }

    if (!is_dir($fsDir) && !mkdir($fsDir, 0775, true) && !is_dir($fsDir)) {
        return 'Could not prepare upload folder.';
    }

    $destName = 'user-' . $userId . '.jpg';
    $destFs = $fsDir . DIRECTORY_SEPARATOR . $destName;
    $tempDest = $destFs . '.tmp';

    if (!clms_avatar_resize_to_square_jpeg($tmpName, $mime, $tempDest, CLMS_AVATAR_OUTPUT_SIZE)) {
        return 'Could not process that image. Try a different file.';
    }

    if (!rename($tempDest, $destFs)) {
        @unlink($tempDest);
        return 'Could not save your photo.';
    }

    return '';
}

function clms_avatar_resize_to_square_jpeg(string $srcPath, string $mime, string $destPath, int $size): bool
{
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($srcPath),
        'image/png' => @imagecreatefrompng($srcPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        'image/gif' => @imagecreatefromgif($srcPath),
        default => false,
    };
    if ($src === false) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return false;
    }

    $crop = min($srcW, $srcH);
    $sx = (int) (($srcW - $crop) / 2);
    $sy = (int) (($srcH - $crop) / 2);

    $dst = imagecreatetruecolor($size, $size);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }

    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagealphablending($dst, true);

    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $crop, $crop);
    imagedestroy($src);

    $ok = imagejpeg($dst, $destPath, 88);
    imagedestroy($dst);

    return $ok;
}
