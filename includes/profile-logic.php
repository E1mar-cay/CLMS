<?php

declare(strict_types=1);

/**
 * Shared profile logic for admin/instructor/student profile pages.
 *
 * Expects the including role page to have:
 *  - required /includes/auth.php and /database.php
 *  - called clms_require_roles(...)
 *
 * Provides these variables to the following view:
 *  - $profileUser        array|null  current DB row (id, role, first_name, last_name, email)
 *  - $profileSuccess     string      success flash
 *  - $profileError       string      error message
 *  - $profileFormValues  array       sticky form values
 */

$profileSuccess = '';
$profileError = '';
$profileUser = null;
$profileFormValues = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
];

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    clms_redirect('login.php');
}

try {
    $userStmt = $pdo->prepare('SELECT id, role, first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $currentUserId]);
    $profileUser = $userStmt->fetch();
} catch (Throwable $e) {
    error_log('Profile fetch failed: ' . $e->getMessage());
    $profileUser = null;
}

if (!$profileUser) {
    $profileError = 'Unable to load your profile. Please log in again.';
} else {
    $profileFormValues = [
        'first_name' => (string) ($profileUser['first_name'] ?? ''),
        'last_name' => (string) ($profileUser['last_name'] ?? ''),
        'email' => (string) ($profileUser['email'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profileUser) {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $profileError = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'update_profile') {
                $firstName = trim((string) ($_POST['first_name'] ?? ''));
                $lastName = trim((string) ($_POST['last_name'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));

                $profileFormValues = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                ];

                if ($firstName === '' || mb_strlen($firstName) > 80) {
                    throw new RuntimeException('First name is required (max 80 characters).');
                }
                if ($lastName === '' || mb_strlen($lastName) > 80) {
                    throw new RuntimeException('Last name is required (max 80 characters).');
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                    throw new RuntimeException('A valid email address is required.');
                }

                $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $dupStmt->execute(['email' => $email, 'id' => $currentUserId]);
                if ($dupStmt->fetch()) {
                    throw new RuntimeException('That email is already used by another account.');
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email WHERE id = :id'
                );
                $updateStmt->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'id' => $currentUserId,
                ]);

                $_SESSION['first_name'] = $firstName;
                $_SESSION['email'] = $email;

                $profileUser['first_name'] = $firstName;
                $profileUser['last_name'] = $lastName;
                $profileUser['email'] = $email;

                $profileSuccess = 'Profile updated successfully.';
            } elseif ($action === 'update_password') {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                    throw new RuntimeException('All password fields are required.');
                }
                if (mb_strlen($newPassword) < 8) {
                    throw new RuntimeException('New password must be at least 8 characters long.');
                }
                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('New password and confirmation do not match.');
                }

                $hashStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
                $hashStmt->execute(['id' => $currentUserId]);
                $hashRow = $hashStmt->fetch();
                if (!$hashRow || !is_string($hashRow['password_hash'] ?? null) || !password_verify($currentPassword, $hashRow['password_hash'])) {
                    throw new RuntimeException('Your current password is incorrect.');
                }

                if (password_verify($newPassword, (string) $hashRow['password_hash'])) {
                    throw new RuntimeException('New password must be different from your current password.');
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pwStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $pwStmt->execute([
                    'password_hash' => $newHash,
                    'id' => $currentUserId,
                ]);

                $profileSuccess = 'Password changed successfully.';
            } else {
                throw new RuntimeException('Unknown profile action.');
            }
        } catch (Throwable $e) {
            $profileError = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Something went wrong while saving your profile.';
            if (!($e instanceof RuntimeException)) {
                error_log('Profile save failed: ' . $e->getMessage());
            }
        }
    }
}
