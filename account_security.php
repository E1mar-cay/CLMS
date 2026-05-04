<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mfa.php';
require_once __DIR__ . '/includes/audit-log.php';
require_once __DIR__ . '/includes/remember.php';
require_once __DIR__ . '/database.php';

clms_require_login();
clms_mfa_ensure_schema($pdo);
clms_audit_ensure_schema($pdo);

$uid = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? 'student');
if ($uid <= 0) {
    clms_redirect('login.php');
}

$pageTitle = 'Account security | Criminology LMS';
$errorMessage = '';
$successMessage = '';

$userStmt = $pdo->prepare('SELECT id, email, mfa_enabled, mfa_totp_secret FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $uid]);
$userRow = $userStmt->fetch();
if (!$userRow) {
    clms_redirect('login.php');
}

$mfaGlobal = clms_mfa_globally_allowed($pdo);
$mfaOn = !empty($userRow['mfa_enabled'])
    && (int) $userRow['mfa_enabled'] === 1
    && trim((string) ($userRow['mfa_totp_secret'] ?? '')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if (!$mfaGlobal && ($action === 'mfa_start' || $action === 'mfa_confirm')) {
                throw new RuntimeException('Multi-factor authentication is disabled by an administrator.');
            }
            if ($action === 'mfa_start') {
                if ($mfaOn) {
                    throw new RuntimeException('MFA is already enabled for this account.');
                }
                $secret = clms_mfa_generate_secret();
                $_SESSION['clms_mfa_enroll_secret'] = $secret;
                $successMessage = 'Scan the QR code below, then enter a 6-digit code to confirm.';
            } elseif ($action === 'mfa_confirm') {
                $secret = isset($_SESSION['clms_mfa_enroll_secret']) && is_string($_SESSION['clms_mfa_enroll_secret'])
                    ? $_SESSION['clms_mfa_enroll_secret']
                    : '';
                if ($secret === '') {
                    throw new RuntimeException('Start MFA setup first.');
                }
                $code = trim((string) ($_POST['otp_code'] ?? ''));
                if (!clms_mfa_verify_totp($secret, $code)) {
                    throw new RuntimeException('Invalid code. Check that your device clock is correct.');
                }
                $upd = $pdo->prepare('UPDATE users SET mfa_totp_secret = :s, mfa_enabled = 1 WHERE id = :id');
                $upd->execute(['s' => $secret, 'id' => $uid]);
                unset($_SESSION['clms_mfa_enroll_secret']);
                $userRow['mfa_totp_secret'] = $secret;
                $userRow['mfa_enabled'] = 1;
                $mfaOn = true;
                clms_audit_log($pdo, 'mfa_enabled', 'user', $uid, [], $uid);
                $successMessage = 'Two-factor authentication is now enabled.';
            } elseif ($action === 'mfa_disable') {
                if (!$mfaOn) {
                    throw new RuntimeException('MFA is not enabled.');
                }
                $code = trim((string) ($_POST['otp_disable'] ?? ''));
                $stored = trim((string) ($userRow['mfa_totp_secret'] ?? ''));
                if (!clms_mfa_verify_totp($stored, $code)) {
                    throw new RuntimeException('Invalid authentication code.');
                }
                $upd = $pdo->prepare('UPDATE users SET mfa_totp_secret = NULL, mfa_enabled = 0 WHERE id = :id');
                $upd->execute(['id' => $uid]);
                unset($_SESSION['clms_mfa_enroll_secret']);
                $userRow['mfa_totp_secret'] = null;
                $userRow['mfa_enabled'] = 0;
                $mfaOn = false;
                clms_audit_log($pdo, 'mfa_disabled', 'user', $uid, [], $uid);
                clms_remember_revoke_current();
                $successMessage = 'Two-factor authentication has been turned off. Use your password on each device when signing in.';
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (RuntimeException $e) {
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            error_log('account_security: ' . $e->getMessage());
            $errorMessage = 'Something went wrong. Please try again.';
        }
    }
}

$enrollSecret = '';
if (isset($_SESSION['clms_mfa_enroll_secret']) && is_string($_SESSION['clms_mfa_enroll_secret'])) {
    $enrollSecret = $_SESSION['clms_mfa_enroll_secret'];
}

$provisioningUri = $enrollSecret !== '' && !$mfaOn
    ? clms_mfa_provisioning_uri((string) ($userRow['email'] ?? ''), $enrollSecret)
    : '';

if ($role === 'admin') {
    $activeAdminPage = 'settings';
    require __DIR__ . '/admin/includes/layout-top.php';
} elseif ($role === 'instructor') {
    $activeInstructorPage = 'dashboard';
    require __DIR__ . '/instructor/includes/layout-top.php';
} else {
    $activeStudentPage = 'dashboard';
    require __DIR__ . '/student/includes/layout-top.php';
}
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Account security</h4>
                  <small class="text-muted">Protect your account with an authenticator app (TOTP).</small>
                </div>
              </div>

<?php if (!$mfaGlobal) : ?>
              <div class="alert alert-warning" role="alert">
                Multi-factor authentication is currently turned off system-wide. An administrator can enable it under System Settings.
              </div>
<?php endif; ?>

<?php if ($errorMessage !== '') : ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($successMessage !== '') : ?>
              <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

              <div class="card mb-4">
                <h5 class="card-header">Authenticator app (optional)</h5>
                <div class="card-body">
<?php if ($mfaOn) : ?>
                  <p class="mb-3">Two-factor authentication is <strong>enabled</strong> for your account. You will be asked for a code after your password when signing in.</p>
                  <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/account_security.php', ENT_QUOTES, 'UTF-8'); ?>" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" value="mfa_disable" />
                    <div class="col-md-4">
                      <label class="form-label" for="otp_disable">Code from app to turn off MFA</label>
                      <input class="form-control" type="text" name="otp_disable" id="otp_disable" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required />
                    </div>
                    <div class="col-md-4">
                      <button type="submit" class="btn btn-outline-danger">Disable MFA</button>
                    </div>
                  </form>
<?php else : ?>
                  <p class="mb-3">Add a second step to your sign-in using Google Authenticator, Microsoft Authenticator, or any TOTP app.</p>
                  <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/account_security.php', ENT_QUOTES, 'UTF-8'); ?>" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" value="mfa_start" />
                    <button type="submit" class="btn btn-primary" <?php echo $mfaGlobal ? '' : 'disabled'; ?>>Begin setup</button>
                  </form>

<?php if ($enrollSecret !== '') : ?>
                  <div class="card border mb-3">
                    <div class="card-header fw-semibold">Step 1 — Add CLMS to your authenticator app</div>
                    <div class="card-body">
                      <p class="small mb-3">
                        The <strong>long text key</strong> below is <strong>only for your phone app</strong> (or the QR). You never paste it into the website.
                        In the app, after you add the account, it will show a <strong>short 6-digit code that changes every 30 seconds</strong>.
                      </p>
                      <div class="row g-4 align-items-start">
                        <div class="col-auto text-center">
                          <div
                            id="clms-mfa-qrcode"
                            class="border rounded d-inline-block p-2 bg-white mx-auto"
                            style="min-width: 212px; min-height: 212px;"
                            role="img"
                            aria-label="QR code for authenticator app"></div>
                          <p id="clms-mfa-qr-error" class="small text-danger mt-2 mb-0" style="max-width: 220px;"></p>
                          <p class="small text-muted mt-2 mb-0" style="max-width: 220px;">QR is generated in your browser (no external image host).</p>
                        </div>
                        <div class="col">
                          <div class="alert alert-secondary small mb-3 py-2" role="note">
                            <strong>There is no input on this website for that long key.</strong>
                            Copy it, then open your <strong>phone’s authenticator app</strong> and paste it <em>there</em> when the app asks for a “setup key” / “secret key”.
                            This page only has a box for the <strong>6 numbers</strong> the app shows you later (Step&nbsp;2).
                          </div>
                          <p class="small text-muted mb-1 fw-semibold">Manual entry in the <strong>phone app</strong> (not in the browser)</p>
                          <ol class="small mb-2 ps-3">
                            <li>On your phone: open Google Authenticator, Microsoft Authenticator, or similar.</li>
                            <li>Tap <strong>+</strong> → <strong>Enter a setup key</strong> (wording may vary; not “Enter code”).</li>
                            <li>Account name: <code>CLMS</code> (any label is fine).</li>
                            <li>Key type: <strong>Time-based</strong>.</li>
                            <li>In the app’s <strong>“Your key”</strong> / <strong>“Secret”</strong> field, paste:</li>
                          </ol>
                          <p class="small text-uppercase text-muted mb-1">Secret key — paste only in the app</p>
                          <code class="d-block mb-0 p-3 bg-label-secondary rounded user-select-all small text-break"><?php echo htmlspecialchars($enrollSecret, ENT_QUOTES, 'UTF-8'); ?></code>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card border-primary border mb-3">
                    <div class="card-header fw-semibold">Step 2 — Confirm with the 6-digit code from the app</div>
                    <div class="card-body">
                      <div class="alert alert-warning small mb-3 mb-md-4" role="alert">
                        This field accepts <strong>exactly six digits</strong> (e.g. <code>482913</code>) — the code <em>shown inside the app</em> for your new CLMS entry.
                        It does <strong>not</strong> accept the long setup key (<code>T6K7…</code>).
                      </div>
                      <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/account_security.php', ENT_QUOTES, 'UTF-8'); ?>" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="mfa_confirm" />
                        <div class="col-md-4">
                          <label class="form-label" for="otp_code">6-digit code from app</label>
                          <input
                            class="form-control form-control-lg text-center"
                            type="text"
                            name="otp_code"
                            id="otp_code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]{6}"
                            minlength="6"
                            maxlength="6"
                            required
                            placeholder="000000" />
                        </div>
                        <div class="col-md-4">
                          <button type="submit" class="btn btn-success">Confirm and enable</button>
                        </div>
                      </form>
                    </div>
                  </div>

<?php if ($provisioningUri !== '') : ?>
                  <script type="application/json" id="clms-mfa-provisioning-json"><?php echo json_encode($provisioningUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?></script>
                  <script src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/js/qrcode.min.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
                  <script>
                    (function () {
                      function run() {
                        var dataEl = document.getElementById('clms-mfa-provisioning-json');
                        var holder = document.getElementById('clms-mfa-qrcode');
                        var errEl = document.getElementById('clms-mfa-qr-error');
                        if (!holder) return;
                        if (typeof QRCode === 'undefined') {
                          if (errEl) errEl.textContent = 'QR script failed to load. Use the secret key above in your app, then complete Step 2.';
                          return;
                        }
                        var uri = '';
                        try {
                          uri = dataEl ? JSON.parse(dataEl.textContent || '""') : '';
                        } catch (e) {
                          if (errEl) errEl.textContent = 'Invalid QR data.';
                          return;
                        }
                        if (!uri) return;
                        try {
                          holder.innerHTML = '';
                          new QRCode(holder, uri);
                        } catch (e) {
                          if (errEl) errEl.textContent = (e && e.message) ? e.message : 'Could not draw QR.';
                        }
                      }
                      if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', run);
                      } else {
                        run();
                      }
                    })();
                  </script>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
                </div>
              </div>

<?php
if ($role === 'admin') {
    require __DIR__ . '/admin/includes/layout-bottom.php';
} elseif ($role === 'instructor') {
    require __DIR__ . '/instructor/includes/layout-bottom.php';
} else {
    require __DIR__ . '/student/includes/layout-bottom.php';
}
