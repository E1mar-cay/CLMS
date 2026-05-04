<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$format = (string) ($_POST['backup_format'] ?? '');
if (!in_array($format, ['csv_zip', 'sql'], true)) {
    http_response_code(400);
    echo 'Unknown export format.';
    exit;
}

$coreTables = [
    'users',
    'courses',
    'modules',
    'questions',
    'answers',
    'exam_attempts',
    'student_responses',
    'user_progress',
    'certificates',
    'course_instructors',
    'system_settings',
    'audit_log',
    'announcements',
    'announcement_reads',
    'module_quiz_attempts',
];

$existing = [];
foreach ($coreTables as $table) {
    try {
        $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
        $existing[] = $table;
    } catch (Throwable $e) {
        // Table missing in this deployment — skip.
    }
}

if ($existing === []) {
    http_response_code(500);
    echo 'No exportable tables found.';
    exit;
}

$stamp = gmdate('Ymd-His');
$actorId = (int) ($_SESSION['user_id'] ?? 0);

/**
 * @return list<string>
 */
function clms_backup_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['Field'])) {
            $cols[] = (string) $row['Field'];
        }
    }

    return $cols;
}

function clms_backup_sql_escape(string $value): string
{
    return str_replace(["\0", "'", '\\'], ['', "''", '\\\\'], $value);
}

try {
    if ($format === 'csv_zip') {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is not available on this server.');
        }
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'clmsbk');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temporary file.');
        }
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not open ZIP archive.');
        }

        foreach ($existing as $table) {
            $columns = clms_backup_table_columns($pdo, $table);
            if ($table === 'users') {
                $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== 'password_hash'));
            }
            if ($columns === []) {
                continue;
            }
            $colSql = implode(
                ', ',
                array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns)
            );
            $rows = $pdo->query('SELECT ' . $colSql . ' FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
            $fh = fopen('php://temp', 'r+');
            if ($fh === false) {
                continue;
            }
            fputcsv($fh, $columns);
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $c) {
                    $v = $row[$c] ?? null;
                    $line[] = $v === null ? null : (string) $v;
                }
                fputcsv($fh, $line);
            }
            rewind($fh);
            $csv = stream_get_contents($fh) ?: '';
            fclose($fh);
            $zip->addFromString($table . '.csv', $csv);
        }
        $zip->close();

        clms_audit_log($pdo, 'backup_exported', 'system', null, ['format' => 'csv_zip', 'tables' => $existing], $actorId);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="clms-backup-' . $stamp . '.zip"');
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    // SQL dump
    $sql = "-- CLMS core tables export " . $stamp . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($existing as $table) {
        $columns = clms_backup_table_columns($pdo, $table);
        if ($table === 'users') {
            $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== 'password_hash'));
        }
        if ($columns === []) {
            continue;
        }
        $colSql = implode(
            ', ',
            array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns)
        );
        $stmt = $pdo->query('SELECT ' . $colSql . ' FROM `' . str_replace('`', '', $table) . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($columns as $c) {
                $v = $row[$c] ?? null;
                if ($v === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = "'" . clms_backup_sql_escape((string) $v) . "'";
                }
            }
            $sql .= 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $colSql . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    clms_audit_log($pdo, 'backup_exported', 'system', null, ['format' => 'sql', 'tables' => $existing], $actorId);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="clms-backup-' . $stamp . '.sql"');
    header('Cache-Control: no-store');
    echo $sql;
    exit;
} catch (Throwable $e) {
    error_log('backup_export: ' . $e->getMessage());
    http_response_code(500);
    echo 'Export failed.';
    exit;
}
