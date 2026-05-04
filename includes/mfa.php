<?php

declare(strict_types=1);

/**
 * Optional TOTP (RFC 6238) MFA — no external dependencies.
 */

const CLMS_MFA_ISSUER = 'CLMS';

function clms_mfa_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach ([
        'mfa_totp_secret' => 'VARCHAR(64) NULL',
        'mfa_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $col => $spec) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($col))->fetch();
            if (!$check) {
                $pdo->exec('ALTER TABLE users ADD COLUMN ' . $col . ' ' . $spec);
            }
        } catch (Throwable $e) {
            error_log('clms_mfa_ensure_schema: ' . $e->getMessage());
        }
    }
}

function clms_mfa_globally_allowed(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1'
        );
        $stmt->execute(['k' => 'mfa_allowed']);
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }

        return (string) ($row['setting_value'] ?? '1') === '1';
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * @param array<string,mixed> $userRow from users SELECT including mfa_* columns
 */
function clms_mfa_user_must_challenge(PDO $pdo, array $userRow): bool
{
    if (!clms_mfa_globally_allowed($pdo)) {
        return false;
    }
    if (empty($userRow['mfa_enabled']) || (int) $userRow['mfa_enabled'] !== 1) {
        return false;
    }
    $secret = trim((string) ($userRow['mfa_totp_secret'] ?? ''));

    return $secret !== '';
}

function clms_mfa_generate_secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $b) {
        $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) !== 5) {
            break;
        }
        $out .= $alphabet[(int) bindec($chunk)];
    }

    return $out;
}

function clms_mfa_base32_decode(string $secret): string
{
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
    if ($secret === '') {
        return '';
    }
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($secret);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($map, $secret[$i]);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $chars = str_split($bits, 8);
    $binary = '';
    foreach ($chars as $ch) {
        if (strlen($ch) === 8) {
            $binary .= chr((int) bindec($ch));
        }
    }

    return $binary;
}

function clms_mfa_hotp(string $binarySecret, int $counter): string
{
    $high = ($counter >> 32) & 0xffffffff;
    $low = $counter & 0xffffffff;
    $binCounter = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $binCounter, $binarySecret, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $truncated = (
        ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;

    return str_pad((string) $truncated, 6, '0', STR_PAD_LEFT);
}

function clms_mfa_verify_totp(string $secretBase32, string $code, int $window = 1): bool
{
    $binary = clms_mfa_base32_decode($secretBase32);
    if ($binary === '') {
        return false;
    }
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $timeSlice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $try = clms_mfa_hotp($binary, $timeSlice + $i);
        if (hash_equals($try, $code)) {
            return true;
        }
    }

    return false;
}

function clms_mfa_provisioning_uri(string $email, string $secretBase32): string
{
    $label = rawurlencode(CLMS_MFA_ISSUER . ':' . $email);
    $issuer = rawurlencode(CLMS_MFA_ISSUER);

    return 'otpauth://totp/'
        . $label
        . '?secret=' . rawurlencode($secretBase32)
        . '&issuer=' . $issuer
        . '&period=30&digits=6';
}
