<?php

declare(strict_types=1);

/**
 * Shared PDO connection for the Criminology LMS.
 * Priority:
 * 1) CLMS_DB_* environment variables (recommended for deployment)
 * 2) Auto defaults:
 *    - local (localhost/127.0.0.1): local XAMPP values
 *    - non-local host: production fallback values
 */
$serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
$isLocalHost = in_array($serverName, ['', 'localhost', '127.0.0.1'], true)
    || str_ends_with($serverName, '.local');

$defaultDbHost = '127.0.0.1';
$defaultDbName = $isLocalHost ? 'clms_db' : 'u890408583_review';
$defaultDbUser = $isLocalHost ? 'root' : 'u890408583_adminrev';
$defaultDbPass = $isLocalHost ? '' : 'Rev+123456789';

$clmsDbHost = getenv('CLMS_DB_HOST') ?: $defaultDbHost;
$clmsDbName = getenv('CLMS_DB_NAME') ?: $defaultDbName;
$clmsDbUser = getenv('CLMS_DB_USER') ?: $defaultDbUser;
$clmsDbPass = getenv('CLMS_DB_PASS') !== false ? (string) getenv('CLMS_DB_PASS') : $defaultDbPass;
$clmsDbCharset = 'utf8mb4';

$clmsDsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $clmsDbHost,
    $clmsDbName,
    $clmsDbCharset
);

$clmsPdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$pdo = new PDO($clmsDsn, $clmsDbUser, $clmsDbPass, $clmsPdoOptions);
