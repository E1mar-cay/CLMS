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

$clmsWebBase = $clmsWebBase ?? '';
$profileFormValues = $profileFormValues ?? [
  'first_name' => '',
  'last_name' => '',
  'email' => '',
];
$profileAvatarHref = '';
if (isset($profileUser['avatar_url']) && is_string($profileUser['avatar_url'])) {
  $profileAvatarHref = clms_avatar_resolve_url($profileUser['avatar_url'], $clmsWebBase);
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
        <div class="mx-auto mb-3 clms-profile-photo rounded-circle overflow-hidden bg-label-primary d-inline-flex align-items-center justify-content-center"
          style="width: 96px; height: 96px; font-size: 2rem; font-weight: 600;">
          <?php if ($profileAvatarHref !== '') : ?>
            <img src="<?php echo htmlspecialchars($profileAvatarHref, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="w-100 h-100" style="object-fit: cover;" width="96" height="96" />
          <?php else : ?>
            <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
          <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" action="" class="mb-3" id="profileAvatarForm" style="display:none;">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="upload_avatar" />
          <input type="file" id="avatarFileInput" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" />
        </form>
        <div class="mb-3">
          <label class="form-label small text-muted mb-1">Profile photo</label>
          <div class="form-text small mb-2">JPG, PNG, WEBP, or GIF. Max 2MB.</div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="selectPhotoBtn">
            <i class="bx bx-image-add me-1"></i>Select photo
          </button>
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

<div class="modal fade" id="cropperModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crop Profile Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3">
          <div style="max-height: 400px; overflow: hidden;">
            <img id="cropperImage" style="max-width: 100%; display: block;" />
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="cropAndUploadBtn">
          <i class="bx bx-crop me-1"></i>Crop & Upload
        </button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  (() => {
    if (typeof Swal === 'undefined') return;

    const successMsg = <?php echo json_encode($profileSuccess, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const errorMsg = <?php echo json_encode($profileError, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    if (successMsg) ClmsNotify.success(successMsg);
    if (errorMsg) ClmsNotify.error(errorMsg, 'Profile update failed');

    // Image cropper functionality
    let cropper = null;
    const selectPhotoBtn = document.getElementById('selectPhotoBtn');
    const avatarFileInput = document.getElementById('avatarFileInput');
    const cropperModal = document.getElementById('cropperModal');
    const cropperImage = document.getElementById('cropperImage');
    const cropAndUploadBtn = document.getElementById('cropAndUploadBtn');
    const avatarForm = document.getElementById('profileAvatarForm');
    let bsModal = null;

    if (cropperModal && typeof bootstrap !== 'undefined') {
      bsModal = new bootstrap.Modal(cropperModal);
    }

    if (selectPhotoBtn && avatarFileInput) {
      selectPhotoBtn.addEventListener('click', () => {
        avatarFileInput.click();
      });

      avatarFileInput.addEventListener('change', (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        console.log('File selected:', file.name);

        const maxBytes = 2097152;
        if (file.size > maxBytes) {
          Swal.fire({
            icon: 'error',
            title: 'File too large',
            text: 'Please choose an image that is 2MB or smaller.',
            confirmButtonColor: '#800000',
          });
          avatarFileInput.value = '';
          return;
        }

        const reader = new FileReader();
        reader.onload = (event) => {
          console.log('Image loaded, setting src');
          cropperImage.src = event.target.result;
          
          if (cropper) {
            cropper.destroy();
            cropper = null;
          }
          
          console.log('Showing modal...');
          if (bsModal) {
            bsModal.show();
          } else {
            cropperModal.classList.add('show');
            cropperModal.style.display = 'block';
            document.body.classList.add('modal-open');
          }
          
          setTimeout(() => {
            console.log('Initializing cropper...');
            if (typeof Cropper !== 'undefined') {
              cropper = new Cropper(cropperImage, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.8,
                responsive: true,
                restore: false,
                guides: true,
                center: true,
                highlight: true,
                cropBoxResizable: true,
                cropBoxMovable: true,
                toggleDragModeOnDblclick: false,
                dragMode: 'move',
                minCropBoxWidth: 100,
                minCropBoxHeight: 100,
              });
              console.log('Cropper initialized');
            } else {
              console.error('Cropper library not loaded');
            }
          }, 300);
        };
        reader.readAsDataURL(file);
      });
    }

    if (cropAndUploadBtn && avatarForm) {
      cropAndUploadBtn.addEventListener('click', () => {
        if (!cropper) return;

        cropper.getCroppedCanvas({
          width: 512,
          height: 512,
          imageSmoothingEnabled: true,
          imageSmoothingQuality: 'high',
        }).toBlob((blob) => {
          if (!blob) {
            Swal.fire({
              icon: 'error',
              title: 'Crop failed',
              text: 'Could not process the image. Please try again.',
              confirmButtonColor: '#800000',
            });
            return;
          }

          const dataTransfer = new DataTransfer();
          const croppedFile = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
          dataTransfer.items.add(croppedFile);
          avatarFileInput.files = dataTransfer.files;

          if (bsModal) bsModal.hide();
          if (cropper) {
            cropper.destroy();
            cropper = null;
          }

          Swal.fire({
            title: 'Update profile photo?',
            text: 'Your new picture will appear in the header for every page.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, upload',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#800000',
          }).then((result) => {
            if (result.isConfirmed) {
              avatarForm.submit();
            }
          });
        }, 'image/jpeg', 0.9);
      });
    }

    if (cropperModal) {
      cropperModal.addEventListener('hidden.bs.modal', () => {
        if (cropper) {
          cropper.destroy();
          cropper = null;
        }
        if (avatarFileInput) avatarFileInput.value = '';
      });
      
      const closeBtn = cropperModal.querySelector('.btn-close');
      const cancelBtn = cropperModal.querySelector('.btn-secondary');
      
      if (closeBtn) {
        closeBtn.addEventListener('click', () => {
          if (bsModal) {
            bsModal.hide();
          } else {
            cropperModal.classList.remove('show');
            cropperModal.style.display = 'none';
            document.body.classList.remove('modal-open');
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
          }
          if (cropper) {
            cropper.destroy();
            cropper = null;
          }
          if (avatarFileInput) avatarFileInput.value = '';
        });
      }
      
      if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
          if (bsModal) {
            bsModal.hide();
          } else {
            cropperModal.classList.remove('show');
            cropperModal.style.display = 'none';
            document.body.classList.remove('modal-open');
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
          }
          if (cropper) {
            cropper.destroy();
            cropper = null;
          }
          if (avatarFileInput) avatarFileInput.value = '';
        });
      }
    }

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
          confirmButtonColor: '#800000',
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
            confirmButtonColor: '#800000',
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
          confirmButtonColor: '#800000',
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