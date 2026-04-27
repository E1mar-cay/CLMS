<?php

declare(strict_types=1);

/**
 * Shared profile view for admin/instructor/student profile pages.
 *
 * Requires (from profile-logic.php):
 *   $profileUser, $profileSuccess, $profileError, $profileFormValues
 */

$displayRole = isset($profileUser['role']) ? ucfirst((string) $profileUser['role']) : 'User';
$userFullName = trim(
    (string) ($profileUser['first_name'] ?? '') . ' ' . (string) ($profileUser['last_name'] ?? '')
);
$initials = '';
if ($userFullName !== '') {
    $parts = preg_split('/\s+/', $userFullName) ?: [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
}
if ($initials === '') {
    $initials = 'U';
}
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">My Profile</h4>
                  <small class="text-muted">Update your personal information and account password.</small>
                </div>
              </div>

<?php if ($profileSuccess !== '') : ?>
              <noscript>
                <div class="alert alert-success" role="alert">
                  <?php echo htmlspecialchars($profileSuccess, ENT_QUOTES, 'UTF-8'); ?>
                </div>
              </noscript>
<?php endif; ?>
<?php if ($profileError !== '') : ?>
              <noscript>
                <div class="alert alert-danger" role="alert">
                  <?php echo htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
              </noscript>
<?php endif; ?>

              <div class="row g-4">
                <div class="col-xl-4">
                  <div class="card">
                    <div class="card-body text-center">
                      <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center rounded-circle bg-label-primary"
                           style="width: 96px; height: 96px; font-size: 2rem; font-weight: 600;">
                        <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                      <h5 class="mb-1"><?php echo htmlspecialchars($userFullName !== '' ? $userFullName : 'Your Name', ENT_QUOTES, 'UTF-8'); ?></h5>
                      <p class="text-muted mb-2 small">
                        <?php echo htmlspecialchars((string) ($profileUser['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                      </p>
                      <span class="badge bg-label-primary">
                        <?php echo htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </div>
                  </div>
                </div>

                <div class="col-xl-8">
                  <div class="card mb-4">
                    <h5 class="card-header">Personal Information</h5>
                    <div class="card-body">
                      <form
                        method="post"
                        action=""
                        id="profileInfoForm"
                        autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="update_profile" />

                        <div class="row g-3">
                          <div class="col-md-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input
                              type="text"
                              id="first_name"
                              name="first_name"
                              class="form-control"
                              maxlength="80"
                              value="<?php echo htmlspecialchars((string) $profileFormValues['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                              required />
                          </div>
                          <div class="col-md-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input
                              type="text"
                              id="last_name"
                              name="last_name"
                              class="form-control"
                              maxlength="80"
                              value="<?php echo htmlspecialchars((string) $profileFormValues['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                              required />
                          </div>
                          <div class="col-md-12">
                            <label for="email" class="form-label">Email address</label>
                            <input
                              type="email"
                              id="email"
                              name="email"
                              class="form-control"
                              maxlength="255"
                              value="<?php echo htmlspecialchars((string) $profileFormValues['email'], ENT_QUOTES, 'UTF-8'); ?>"
                              required />
                            <div class="form-text">Use an email you can always access — you'll use it to sign in.</div>
                          </div>
                          <div class="col-md-12">
                            <label class="form-label">Role</label>
                            <input
                              type="text"
                              class="form-control"
                              value="<?php echo htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8'); ?>"
                              disabled />
                            <div class="form-text">Your role is set by an administrator and cannot be changed here.</div>
                          </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                          <button type="submit" class="btn btn-primary">
                            <i class="bx bx-save me-1"></i>Save changes
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>

                  <div class="card">
                    <h5 class="card-header">Change Password</h5>
                    <div class="card-body">
                      <form
                        method="post"
                        action=""
                        id="profilePasswordForm"
                        autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="update_password" />

                        <div class="row g-3">
                          <div class="col-md-12">
                            <label for="current_password" class="form-label">Current password</label>
                            <input
                              type="password"
                              id="current_password"
                              name="current_password"
                              class="form-control"
                              autocomplete="current-password"
                              required />
                          </div>
                          <div class="col-md-6">
                            <label for="new_password" class="form-label">New password</label>
                            <input
                              type="password"
                              id="new_password"
                              name="new_password"
                              class="form-control"
                              minlength="8"
                              autocomplete="new-password"
                              required />
                            <div class="form-text">Minimum 8 characters.</div>
                          </div>
                          <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <input
                              type="password"
                              id="confirm_password"
                              name="confirm_password"
                              class="form-control"
                              minlength="8"
                              autocomplete="new-password"
                              required />
                          </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                          <button type="submit" class="btn btn-primary">
                            <i class="bx bx-lock-alt me-1"></i>Update password
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>

              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  if (typeof Swal === 'undefined') return;

                  const successMsg = <?php echo json_encode($profileSuccess, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                  const errorMsg = <?php echo json_encode($profileError, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                  if (successMsg) ClmsNotify.success(successMsg);
                  if (errorMsg) ClmsNotify.error(errorMsg, 'Profile update failed');

                  const infoForm = document.getElementById('profileInfoForm');
                  if (infoForm) {
                    infoForm.addEventListener('submit', (event) => {
                      if (infoForm.dataset.confirmed === '1') return;
                      event.preventDefault();
                      Swal.fire({
                        title: 'Save profile changes?',
                        text: 'Your name and email will be updated.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, save',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          infoForm.dataset.confirmed = '1';
                          infoForm.submit();
                        }
                      });
                    });
                  }

                  const pwForm = document.getElementById('profilePasswordForm');
                  if (pwForm) {
                    pwForm.addEventListener('submit', (event) => {
                      if (pwForm.dataset.confirmed === '1') return;
                      event.preventDefault();

                      const newPw = document.getElementById('new_password');
                      const confirmPw = document.getElementById('confirm_password');
                      if (newPw && confirmPw && newPw.value !== confirmPw.value) {
                        Swal.fire({
                          icon: 'error',
                          title: 'Passwords do not match',
                          text: 'New password and confirmation must be identical.',
                          confirmButtonColor: '#0f204b',
                        });
                        return;
                      }

                      Swal.fire({
                        title: 'Change your password?',
                        text: 'You will keep using the same account, just with a new password.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, change it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#0f204b',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          pwForm.dataset.confirmed = '1';
                          pwForm.submit();
                        }
                      });
                    });
                  }
                })();
              </script>
