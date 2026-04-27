<?php

declare(strict_types=1);

$clmsProjectRoot = dirname(__DIR__);
$clmsSneatDir = $clmsProjectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'sneat';
$clmsDocRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$clmsSneatReal = is_dir($clmsSneatDir) ? realpath($clmsSneatDir) : false;

if ($clmsSneatReal && $clmsDocRoot) {
    $docNorm = str_replace('\\', '/', $clmsDocRoot);
    $sneatNorm = str_replace('\\', '/', $clmsSneatReal);
    if (str_starts_with(strtolower($sneatNorm), strtolower($docNorm))) {
        $clmsSneatBase = '/' . ltrim(substr($sneatNorm, strlen($docNorm)), '/');
    } else {
        $clmsSneatBase = getenv('CLMS_SNEAT_BASE') ?: '/CLMS/public/assets/sneat';
    }
} else {
    $clmsSneatBase = getenv('CLMS_SNEAT_BASE') ?: '/CLMS/public/assets/sneat';
}

$clmsSneatBase = rtrim($clmsSneatBase, '/');

$clmsAppRootReal = realpath($clmsProjectRoot) ?: $clmsProjectRoot;
if ($clmsDocRoot) {
    $docNorm = str_replace('\\', '/', $clmsDocRoot);
    $appNorm = str_replace('\\', '/', $clmsAppRootReal);
    if (str_starts_with(strtolower($appNorm), strtolower($docNorm))) {
        $clmsWebBase = '/' . ltrim(substr($appNorm, strlen($docNorm)), '/');
    } else {
        $clmsWebBase = getenv('CLMS_WEB_BASE') ?: '/CLMS';
    }
} else {
    $clmsWebBase = getenv('CLMS_WEB_BASE') ?: '/CLMS';
}

$clmsWebBase = rtrim($clmsWebBase, '/');

if (!function_exists('clms_question_type_label')) {
    /**
     * Convert a raw question_type DB value into a human-friendly label.
     * `single_choice` and `multiple_select` both map to "Multiple Choice"
     * because admins/instructors author them the same way (A/B/C/D with
     * one correct answer); internally the types still drive radio vs
     * checkbox rendering at exam time.
     */
    function clms_question_type_label(string $type): string
    {
        $map = [
            'single_choice' => 'Multiple Choice',
            'multiple_select' => 'Multiple Choice',
            'true_false' => 'True / False',
            'fill_blank' => 'Fill in the Blank',
            'sequencing' => 'Sequencing',
            'essay' => 'Essay',
        ];
        $key = strtolower(trim($type));
        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}
