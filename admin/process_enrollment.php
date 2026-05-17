<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/sneat-paths.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

header('Content-Type: text/html; charset=UTF-8');

$redirectTo = $clmsWebBase . '/admin/pending_enrollments.php';
$errorMessage = '';
$successMessage = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Invalid request token. Please refresh and try again.');
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $userId = (int) filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($userId <= 0) {
        throw new RuntimeException('Invalid user ID.');
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new RuntimeException('Invalid action.');
    }

    /* Verify the user exists and is pending */
    $userCheckStmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, account_approval_status, review_track
         FROM users
         WHERE id = :user_id AND role = 'student' AND account_approval_status = 'pending'
         LIMIT 1"
    );
    $userCheckStmt->execute(['user_id' => $userId]);
    $user = $userCheckStmt->fetch();

    if (!$user) {
        throw new RuntimeException('User not found or is not pending approval.');
    }

    if ($action === 'approve') {
        /* Approve the enrollment using a transaction */
        try {
            $pdo->beginTransaction();

            /* Update user status to 'active' */
            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET account_approval_status = 'approved',
                     account_approved_at = NOW()
                 WHERE id = :user_id"
            );
            $updateStmt->execute(['user_id' => $userId]);

            if ($updateStmt->rowCount() < 1) {
                throw new RuntimeException('Failed to approve user status.');
            }

            /* Insert enrollment record with review_track */
            $reviewTrack = trim((string) ($user['review_track'] ?? 'Regular'));
            if ($reviewTrack === '' || !in_array($reviewTrack, ['Regular', 'Enhancement'], true)) {
                $reviewTrack = 'Regular';
            }

            $enrollmentStmt = $pdo->prepare(
                "INSERT INTO enrollments (user_id, review_track, created_at)
                 VALUES (:user_id, :review_track, NOW())
                 ON DUPLICATE KEY UPDATE created_at = NOW()"
            );
            $enrollmentStmt->execute([
                'user_id' => $userId,
                'review_track' => $reviewTrack,
            ]);

            $pdo->commit();

            $successMessage = 'Enrollment approved and activated successfully.';
            $userDisplayName = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']) ?: (string) $user['email'];
            error_log("Enrollment approved for user {$userId} ({$userDisplayName}) with track: {$reviewTrack}");
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'reject') {
        /* Reject the enrollment - set status to 'rejected' */
        $rejectStmt = $pdo->prepare(
            "UPDATE users
             SET account_approval_status = 'rejected'
             WHERE id = :user_id"
        );
        $rejectStmt->execute(['user_id' => $userId]);

        if ($rejectStmt->rowCount() < 1) {
            throw new RuntimeException('Failed to reject enrollment.');
        }

        $successMessage = 'Enrollment rejected successfully.';
        $userDisplayName = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']) ?: (string) $user['email'];
        error_log("Enrollment rejected for user {$userId} ({$userDisplayName})");
    }

    /* Redirect back to pending enrollments page with success flag */
    $_SESSION['enrollment_success_message'] = $successMessage;
    header('Location: ' . htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8'));
    exit;
} catch (Throwable $e) {
    $errorMessage = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'An unexpected error occurred. Please try again.';

    if (!($e instanceof RuntimeException)) {
        error_log('process_enrollment error: ' . $e->getMessage());
    }

    /* Store error in session and redirect */
    $_SESSION['enrollment_error_message'] = $errorMessage;
    header('Location: ' . htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8'));
    exit;
}
