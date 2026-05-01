<?php

declare(strict_types=1);

/**
 * Student account approval helpers.
 */

function clms_user_approval_statuses(): array
{
    return ['pending', 'approved', 'rejected'];
}

function clms_user_approval_normalize(?string $status): string
{
    $value = strtolower(trim((string) $status));
    if (in_array($value, clms_user_approval_statuses(), true)) {
        return $value;
    }

    return 'approved';
}

function clms_users_has_approval_column(PDO $pdo): bool
{
    static $hasColumn = null;
    if (is_bool($hasColumn)) {
        return $hasColumn;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS c
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = 'account_approval_status'"
    );
    $hasColumn = ((int) ($stmt->fetch()['c'] ?? 0)) > 0;

    return $hasColumn;
}

function clms_users_has_disabled_column(PDO $pdo): bool
{
    static $hasColumn = null;
    if (is_bool($hasColumn)) {
        return $hasColumn;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS c
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = 'account_is_disabled'"
    );
    $hasColumn = ((int) ($stmt->fetch()['c'] ?? 0)) > 0;

    return $hasColumn;
}

function clms_user_approval_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!clms_users_has_approval_column($pdo)) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN account_approval_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER role,
                 ADD COLUMN account_approved_at DATETIME NULL AFTER account_approval_status"
            );
        }
        if (!clms_users_has_disabled_column($pdo)) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN account_is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER account_approved_at,
                 ADD COLUMN account_disabled_at DATETIME NULL AFTER account_is_disabled"
            );
        }
    } catch (Throwable $e) {
        error_log('clms_user_approval_ensure_schema: ' . $e->getMessage());
    }
}

function clms_user_is_disabled(mixed $raw): bool
{
    return (int) $raw === 1;
}

function clms_user_approval_can_login(string $role, ?string $status, mixed $isDisabled = 0): bool
{
    if (clms_user_is_disabled($isDisabled)) {
        return false;
    }
    if ($role !== 'student') {
        return true;
    }

    return clms_user_approval_normalize($status) === 'approved';
}

function clms_user_approval_login_error(string $status, mixed $isDisabled = 0): string
{
    if (clms_user_is_disabled($isDisabled)) {
        return 'Your account is disabled. Please contact the administrator.';
    }

    $normalized = clms_user_approval_normalize($status);
    if ($normalized === 'rejected') {
        return 'Your account request was rejected. Please contact the administrator.';
    }

    return 'Your account is pending admin approval. Please wait for approval before signing in.';
}
