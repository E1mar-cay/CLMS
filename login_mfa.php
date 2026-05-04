<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/remember.php';
require_once __DIR__ . '/includes/user-approval.php';
require_once __DIR__ . '/includes/audit-log.php';
require_once __DIR__ . '/includes/mfa.php';
require_once __DIR__ . '/database.php';

clms_avatar_ensure_schema($pdo);
clms_audit_ensure_schema($pdo);
clms_mfa_ensure_schema($pdo);
clms_user_approval_ensure_schema($pdo);

clms_session_start();

if (clms_logged_in()) {
    clms_redirect_if_logged_in();
}

$pending = $_SESSION['clms_mfa_pending'] ?? null;
if (!is_array($pending) || empty($pending['user_id']) || (int) $pending['user_id'] <= 0) {
    clms_redirect('login.php');
}

$pendingUserId = (int) $pending['user_id'];
$pendingTs = (int) ($pending['ts'] ?? 0);
if ($pendingTs <= 0 || time() - $pendingTs > 600) {
    unset($_SESSION['clms_mfa_pending']);
    clms_redirect('login.php');
}

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please sign in again.';
    } else {
        $code = trim((string) ($_POST['otp_code'] ?? ''));
        $stmt = $pdo->prepare(
            'SELECT id, email, password_hash, role, first_name, account_approval_status, account_is_disabled, avatar_url,
                    mfa_totp_secret, mfa_enabled
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $pendingUserId]);
        $user = $stmt->fetch();
        if (
            !$user
            || empty($user['mfa_enabled'])
            || (int) $user['mfa_enabled'] !== 1
            || !is_string($user['mfa_totp_secret'] ?? null)
            || trim($user['mfa_totp_secret']) === ''
        ) {
            unset($_SESSION['clms_mfa_pending']);
            $formError = 'MFA is no longer active for this account. Please sign in again.';
        } elseif (!clms_mfa_verify_totp(trim($user['mfa_totp_secret']), $code)) {
            $formError = 'Invalid authentication code.';
            clms_audit_log($pdo, 'mfa_failed', 'user', $pendingUserId, [], $pendingUserId);
        } else {
            unset($_SESSION['clms_mfa_pending']);
            session_regenerate_id(true);
            $role = (string) ($user['role'] ?? '');
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['role'] = $role;
            $_SESSION['email'] = (string) $user['email'];
            $_SESSION['first_name'] = (string) ($user['first_name'] ?? '');
            $_SESSION['avatar_url'] = (string) ($user['avatar_url'] ?? '');

            $remember = !empty($pending['remember']);
            if ($remember) {
                clms_remember_issue_token((int) $user['id']);
            } else {
                clms_remember_revoke_current();
            }

            clms_audit_log($pdo, 'login_success', 'user', (int) $user['id'], ['via' => 'mfa'], (int) $user['id']);

            if ($role === 'admin') {
                clms_redirect('admin/dashboard.php');
            }
            if ($role === 'instructor') {
                clms_redirect('instructor/dashboard.php');
            }
            clms_redirect('student/dashboard.php');
        }
    }
}

$pageTitle = 'Two-factor authentication | Criminology LMS';

require_once __DIR__ . '/includes/auth-header.php';

?>
    <style>
      html, body { height: 100%; }
      body.clms-auth-body { background-color: var(--clms-cream, #fdfcf0); margin: 0; }
      .clms-auth-form-col {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 2rem 1rem;
      }
      .clms-auth-card {
        width: 100%;
        max-width: 420px;
        background: #fff;
        border: 1px solid rgba(15, 32, 75, .06);
        border-radius: var(--clms-radius);
        box-shadow: var(--clms-shadow-hover);
        padding: 2rem;
      }
    </style>
    <div class="clms-auth-form-col">
      <div class="clms-auth-card">
        <h2 class="h4 fw-bold mb-2">Verify your sign-in</h2>
        <p class="text-muted small mb-4">Enter the 6-digit code from your authenticator app.</p>
<?php if ($formError !== '') : ?>
        <div class="alert alert-danger mb-3" role="alert"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/login_mfa.php', ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <div class="mb-3">
            <label class="form-label" for="otp_code">Authentication code</label>
            <input class="form-control form-control-lg text-center tracking-wider" type="text" inputmode="numeric" pattern="\d{6}" maxlength="8" name="otp_code" id="otp_code" autocomplete="one-time-code" required autofocus />
          </div>
          <button type="submit" class="btn btn-clms-primary w-100">Continue</button>
        </form>
        <p class="text-center mt-3 mb-0 small">
          <a href="<?php echo htmlspecialchars($clmsWebBase . '/login.php', ENT_QUOTES, 'UTF-8'); ?>">Back to sign in</a>
        </p>
      </div>
    </div>
    <script>document.body.classList.add('clms-auth-body');</script>
<?php require __DIR__ . '/includes/auth-footer.php'; ?>
