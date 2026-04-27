<?php

declare(strict_types=1);

/**
 * Shared PDO connection for the Criminology LMS.
 * Prefer setting CLMS_DB_* environment variables; defaults suit local XAMPP.
 */
$clmsDbHost = getenv('CLMS_DB_HOST') ?: '127.0.0.1';
$clmsDbName = getenv('CLMS_DB_NAME') ?: 'clms_db';
$clmsDbUser = getenv('CLMS_DB_USER') ?: 'root';
$clmsDbPass = getenv('CLMS_DB_PASS') ?: '';
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
