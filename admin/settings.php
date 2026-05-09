<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/sneat-paths.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Settings | Criminology LMS';
$activeAdminPage = 'settings';
$errorMessage = '';
$successMessage = '';

/**
 * Store runtime-editable, non-secret globals in a key/value table.
 * (Kept in DB rather than .env so admins can update them from the UI at runtime
 * without a redeploy or filesystem write access. Secrets like API tokens should
 * continue to live in environment variables.)
 */
try {
  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
  );
} catch (Throwable $e) {
  error_log('system_settings table init failed: ' . $e->getMessage());
}

$defaults = [
  'default_passing_score_percentage' => '75.00',
  'course_registrations_open' => '1',
  'administrator_contact_email' => 'admin@clms.local',
  'admin_pending_alert_sound' => '1',
  'mfa_allowed' => '1',
  'site_title' => 'Criminology LMS',
  'site_logo_url' => '',
  'primary_color' => '#800000',
  'secondary_color' => '#696cff',
  'sidebar_bg_color' => '#ffffff',
  'navbar_bg_color' => '#ffffff',
];

function clms_load_settings(PDO $pdo, array $defaults): array
{
  try {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
  } catch (Throwable $e) {
    $rows = [];
  }
  $loaded = $defaults;
  foreach ($rows as $row) {
    $key = (string) $row['setting_key'];
    if (array_key_exists($key, $loaded)) {
      $loaded[$key] = (string) ($row['setting_value'] ?? '');
    }
  }

  return $loaded;
}

$settings = clms_load_settings($pdo, $defaults);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
    $errorMessage = 'Invalid request token. Please refresh and try again.';
  } else {
    try {
      $passingInput = trim((string) ($_POST['default_passing_score_percentage'] ?? ''));
      $registrationsInput = isset($_POST['course_registrations_open']) ? '1' : '0';
      $contactEmailInput = trim((string) ($_POST['administrator_contact_email'] ?? ''));
      $adminPendingAlertSoundInput = isset($_POST['admin_pending_alert_sound']) ? '1' : '0';
      $isAdmin = (string) ($_SESSION['role'] ?? '') === 'admin';
      $mfaAllowedInput = $isAdmin && isset($_POST['mfa_allowed']) ? '1' : '0';
      
      $siteTitleInput = trim((string) ($_POST['site_title'] ?? 'Criminology LMS'));
      $siteLogoUrlInput = trim((string) ($_POST['site_logo_url'] ?? ''));
      $primaryColorInput = trim((string) ($_POST['primary_color'] ?? '#800000'));
      $secondaryColorInput = trim((string) ($_POST['secondary_color'] ?? '#696cff'));
      $sidebarBgColorInput = trim((string) ($_POST['sidebar_bg_color'] ?? '#ffffff'));
      $navbarBgColorInput = trim((string) ($_POST['navbar_bg_color'] ?? '#ffffff'));

      if (!is_numeric($passingInput)) {
        throw new RuntimeException('Default passing score must be a number.');
      }
      $passingFloat = (float) $passingInput;
      if ($passingFloat < 0.0 || $passingFloat > 100.0) {
        throw new RuntimeException('Default passing score must be between 0 and 100.');
      }
      $passingNormalized = number_format($passingFloat, 2, '.', '');

      if (mb_strlen($siteTitleInput) > 100) {
        throw new RuntimeException('Site title is too long (max 100 characters).');
      }
      if ($siteLogoUrlInput !== '' && mb_strlen($siteLogoUrlInput) > 512) {
        throw new RuntimeException('Logo URL is too long (max 512 characters).');
      }
      if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryColorInput)) {
        throw new RuntimeException('Primary color must be a valid hex color (e.g., #800000).');
      }
      if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $secondaryColorInput)) {
        throw new RuntimeException('Secondary color must be a valid hex color (e.g., #696cff).');
      }
      if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $sidebarBgColorInput)) {
        throw new RuntimeException('Sidebar background color must be a valid hex color.');
      }
      if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $navbarBgColorInput)) {
        throw new RuntimeException('Navbar background color must be a valid hex color.');
      }

      if ($contactEmailInput === '' || !filter_var($contactEmailInput, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Administrator contact email is invalid.');
      }
      if (mb_strlen($contactEmailInput) > 255) {
        throw new RuntimeException('Administrator contact email is too long.');
      }

      $upsertStmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
                 VALUES (:setting_key, :setting_value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
      );

      $pdo->beginTransaction();
      $upsertStmt->execute([
        'setting_key' => 'default_passing_score_percentage',
        'setting_value' => $passingNormalized,
      ]);
      $upsertStmt->execute([
        'setting_key' => 'course_registrations_open',
        'setting_value' => $registrationsInput,
      ]);
      $upsertStmt->execute([
        'setting_key' => 'administrator_contact_email',
        'setting_value' => $contactEmailInput,
      ]);
      $upsertStmt->execute([
        'setting_key' => 'admin_pending_alert_sound',
        'setting_value' => $adminPendingAlertSoundInput,
      ]);
      if ($isAdmin) {
        $upsertStmt->execute([
          'setting_key' => 'mfa_allowed',
          'setting_value' => $mfaAllowedInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'site_title',
          'setting_value' => $siteTitleInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'site_logo_url',
          'setting_value' => $siteLogoUrlInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'primary_color',
          'setting_value' => $primaryColorInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'secondary_color',
          'setting_value' => $secondaryColorInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'sidebar_bg_color',
          'setting_value' => $sidebarBgColorInput,
        ]);
        $upsertStmt->execute([
          'setting_key' => 'navbar_bg_color',
          'setting_value' => $navbarBgColorInput,
        ]);
      }
      $pdo->commit();

      $successMessage = 'Settings saved successfully.';
      clms_audit_log(
        $pdo,
        'settings_updated',
        'system',
        null,
        [
          'passing_score' => $passingNormalized,
          'course_registrations_open' => $registrationsInput,
          'admin_pending_sound' => $adminPendingAlertSoundInput,
          'mfa_allowed' => $isAdmin ? $mfaAllowedInput : '(unchanged)',
          'site_title' => $isAdmin ? $siteTitleInput : '(unchanged)',
          'primary_color' => $isAdmin ? $primaryColorInput : '(unchanged)',
        ],
        (int) ($_SESSION['user_id'] ?? 0)
      );
      $settings = clms_load_settings($pdo, $defaults);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $errorMessage = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to save settings. Please try again.';
      if (!($e instanceof RuntimeException)) {
        error_log($e->getMessage());
      }

      $settings['default_passing_score_percentage'] = (string) ($_POST['default_passing_score_percentage'] ?? $settings['default_passing_score_percentage']);
      $settings['course_registrations_open'] = isset($_POST['course_registrations_open']) ? '1' : '0';
      $settings['administrator_contact_email'] = (string) ($_POST['administrator_contact_email'] ?? $settings['administrator_contact_email']);
      $settings['admin_pending_alert_sound'] = isset($_POST['admin_pending_alert_sound']) ? '1' : '0';
      if ((string) ($_SESSION['role'] ?? '') === 'admin') {
        $settings['mfa_allowed'] = isset($_POST['mfa_allowed']) ? '1' : '0';
        $settings['site_title'] = (string) ($_POST['site_title'] ?? $settings['site_title']);
        $settings['site_logo_url'] = (string) ($_POST['site_logo_url'] ?? $settings['site_logo_url']);
        $settings['primary_color'] = (string) ($_POST['primary_color'] ?? $settings['primary_color']);
        $settings['secondary_color'] = (string) ($_POST['secondary_color'] ?? $settings['secondary_color']);
        $settings['sidebar_bg_color'] = (string) ($_POST['sidebar_bg_color'] ?? $settings['sidebar_bg_color']);
        $settings['navbar_bg_color'] = (string) ($_POST['navbar_bg_color'] ?? $settings['navbar_bg_color']);
      }
    }
  }
}

$lastUpdatedStmt = $pdo->query('SELECT MAX(updated_at) AS last_updated FROM system_settings');
$lastUpdatedRow = $lastUpdatedStmt ? $lastUpdatedStmt->fetch() : null;
$lastUpdated = $lastUpdatedRow && !empty($lastUpdatedRow['last_updated'])
  ? (string) $lastUpdatedRow['last_updated']
  : null;

require_once __DIR__ . '/includes/layout-top.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
  <div>
    <h4 class="fw-bold mb-1">System Settings</h4>
    <small class="text-muted">Manage global LMS defaults. Changes apply to all new courses and dashboards.</small>
  </div>
  <?php if ($lastUpdated !== null) : ?>
    <small class="text-muted">
      Last updated: <?php echo htmlspecialchars((string) date('M j, Y g:i A', strtotime($lastUpdated) ?: time()), ENT_QUOTES, 'UTF-8'); ?>
    </small>
  <?php endif; ?>
</div>

<div class="card">
  <h5 class="card-header">Global LMS Variables</h5>
  <div class="card-body">
    <form
      method="post"
      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/settings.php', ENT_QUOTES, 'UTF-8'); ?>"
      id="settingsForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />

      <div class="mb-4">
        <label for="default_passing_score_percentage" class="form-label">
          Default Passing Score (%)
        </label>
        <input
          type="number"
          id="default_passing_score_percentage"
          name="default_passing_score_percentage"
          class="form-control"
          min="0"
          max="100"
          step="0.01"
          value="<?php echo htmlspecialchars($settings['default_passing_score_percentage'], ENT_QUOTES, 'UTF-8'); ?>"
          required />
        <div class="form-text">
          Applied to newly created courses as the default minimum passing percentage.
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label d-block">Course Registrations</label>
        <div class="form-check form-switch">
          <input
            class="form-check-input"
            type="checkbox"
            id="course_registrations_open"
            name="course_registrations_open"
            value="1"
            <?php echo $settings['course_registrations_open'] === '1' ? 'checked' : ''; ?> />
          <label class="form-check-label" for="course_registrations_open">
            Allow new students to register for courses
          </label>
        </div>
        <div class="form-text">
          When disabled, the student dashboard will hide the "Enroll" action on available courses.
        </div>
      </div>

      <div class="mb-4">
        <label for="administrator_contact_email" class="form-label">
          Administrator Contact Email
        </label>
        <input
          type="email"
          id="administrator_contact_email"
          name="administrator_contact_email"
          class="form-control"
          maxlength="255"
          value="<?php echo htmlspecialchars($settings['administrator_contact_email'], ENT_QUOTES, 'UTF-8'); ?>"
          required />
        <div class="form-text">
          Used for system notifications and shown on support pages if students need to reach an admin.
        </div>
      </div>
      <?php if ((string) ($_SESSION['role'] ?? '') === 'admin') : ?>
        <div class="mb-4">
          <label class="form-label d-block">Admin Pending Account Alerts</label>
          <div class="form-check form-switch">
            <input
              class="form-check-input"
              type="checkbox"
              id="admin_pending_alert_sound"
              name="admin_pending_alert_sound"
              value="1"
              <?php echo ($settings['admin_pending_alert_sound'] ?? '1') === '1' ? 'checked' : ''; ?> />
            <label class="form-check-label" for="admin_pending_alert_sound">
              Play alert sound when new pending student accounts arrive
            </label>
          </div>
          <div class="form-text">
            Toast notifications remain enabled; this setting controls only the sound cue in the admin navbar.
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label d-block">Multi-factor authentication (MFA)</label>
          <div class="form-check form-switch">
            <input
              class="form-check-input"
              type="checkbox"
              id="mfa_allowed"
              name="mfa_allowed"
              value="1"
              <?php echo ($settings['mfa_allowed'] ?? '1') === '1' ? 'checked' : ''; ?> />
            <label class="form-check-label" for="mfa_allowed">
              Allow users to enroll authenticator apps for sign-in
            </label>
          </div>
          <div class="form-text">
            When off, nobody can enroll MFA and existing users are <strong>not</strong> prompted for a code at sign-in (administrative override). Turn back on to restore second-step checks for enrolled accounts.
            Users enroll under <strong>Account security</strong> in the profile menu.
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bx bx-save me-1"></i>Save Settings
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ((string) ($_SESSION['role'] ?? '') === 'admin') : ?>
<div class="card mt-4">
  <h5 class="card-header">Branding & Theme</h5>
  <div class="card-body">
    <form
      method="post"
      action="<?php echo htmlspecialchars($clmsWebBase . '/admin/settings.php', ENT_QUOTES, 'UTF-8'); ?>"
      id="themeForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
      <input type="hidden" name="default_passing_score_percentage" value="<?php echo htmlspecialchars($settings['default_passing_score_percentage'], ENT_QUOTES, 'UTF-8'); ?>" />
      <input type="hidden" name="administrator_contact_email" value="<?php echo htmlspecialchars($settings['administrator_contact_email'], ENT_QUOTES, 'UTF-8'); ?>" />
      <?php if ($settings['course_registrations_open'] === '1') : ?>
      <input type="hidden" name="course_registrations_open" value="1" />
      <?php endif; ?>
      <?php if ($settings['admin_pending_alert_sound'] === '1') : ?>
      <input type="hidden" name="admin_pending_alert_sound" value="1" />
      <?php endif; ?>
      <?php if ($settings['mfa_allowed'] === '1') : ?>
      <input type="hidden" name="mfa_allowed" value="1" />
      <?php endif; ?>

      <div class="mb-4">
        <label for="site_title" class="form-label">Site Title</label>
        <input
          type="text"
          id="site_title"
          name="site_title"
          class="form-control"
          maxlength="100"
          value="<?php echo htmlspecialchars($settings['site_title'], ENT_QUOTES, 'UTF-8'); ?>"
          placeholder="Criminology LMS" />
        <div class="form-text">Displayed in browser tabs, page headers, and login pages.</div>
      </div>

      <div class="mb-4">
        <label for="site_logo" class="form-label">Site Logo</label>
        <div class="d-flex align-items-start gap-3">
          <div class="flex-shrink-0">
            <?php if (!empty($settings['site_logo_url'])) : ?>
              <img
                id="logoPreview"
                src="<?php echo htmlspecialchars($settings['site_logo_url'], ENT_QUOTES, 'UTF-8'); ?>"
                alt="Site Logo"
                class="rounded"
                style="width: 120px; height: 120px; object-fit: cover; border: 2px solid #ddd;" />
            <?php else : ?>
              <div
                id="logoPreview"
                class="rounded d-flex align-items-center justify-content-center bg-light text-muted"
                style="width: 120px; height: 120px; border: 2px solid #ddd;">
                <i class="bx bx-image" style="font-size: 3rem;"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="flex-grow-1">
            <input
              type="file"
              id="site_logo"
              name="site_logo"
              class="form-control mb-2"
              accept="image/*" />
            <input type="hidden" id="site_logo_url" name="site_logo_url" value="<?php echo htmlspecialchars($settings['site_logo_url'], ENT_QUOTES, 'UTF-8'); ?>" />
            <div class="form-text">Upload a logo image. Recommended size: 200x200px or larger. Will be cropped to square.</div>
            <?php if (!empty($settings['site_logo_url'])) : ?>
              <button type="button" class="btn btn-sm btn-outline-danger mt-2" id="removeLogoBtn">
                <i class="bx bx-trash me-1"></i>Remove Logo
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label for="primary_color" class="form-label">Primary Color</label>
          <div class="input-group">
            <input
              type="color"
              id="primary_color"
              name="primary_color"
              class="form-control form-control-color"
              value="<?php echo htmlspecialchars($settings['primary_color'], ENT_QUOTES, 'UTF-8'); ?>"
              title="Choose primary color" />
            <input
              type="text"
              class="form-control"
              id="primary_color_text"
              value="<?php echo htmlspecialchars($settings['primary_color'], ENT_QUOTES, 'UTF-8'); ?>"
              readonly />
          </div>
          <div class="form-text">Main brand color for buttons and accents.</div>
        </div>
        <div class="col-md-6">
          <label for="secondary_color" class="form-label">Secondary Color</label>
          <div class="input-group">
            <input
              type="color"
              id="secondary_color"
              name="secondary_color"
              class="form-control form-control-color"
              value="<?php echo htmlspecialchars($settings['secondary_color'], ENT_QUOTES, 'UTF-8'); ?>"
              title="Choose secondary color" />
            <input
              type="text"
              class="form-control"
              id="secondary_color_text"
              value="<?php echo htmlspecialchars($settings['secondary_color'], ENT_QUOTES, 'UTF-8'); ?>"
              readonly />
          </div>
          <div class="form-text">Secondary accent color.</div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label for="sidebar_bg_color" class="form-label">Sidebar Background</label>
          <div class="input-group">
            <input
              type="color"
              id="sidebar_bg_color"
              name="sidebar_bg_color"
              class="form-control form-control-color"
              value="<?php echo htmlspecialchars($settings['sidebar_bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
              title="Choose sidebar background color" />
            <input
              type="text"
              class="form-control"
              id="sidebar_bg_color_text"
              value="<?php echo htmlspecialchars($settings['sidebar_bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
              readonly />
          </div>
          <div class="form-text">Dashboard sidebar background color.</div>
        </div>
        <div class="col-md-6">
          <label for="navbar_bg_color" class="form-label">Navbar Background</label>
          <div class="input-group">
            <input
              type="color"
              id="navbar_bg_color"
              name="navbar_bg_color"
              class="form-control form-control-color"
              value="<?php echo htmlspecialchars($settings['navbar_bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
              title="Choose navbar background color" />
            <input
              type="text"
              class="form-control"
              id="navbar_bg_color_text"
              value="<?php echo htmlspecialchars($settings['navbar_bg_color'], ENT_QUOTES, 'UTF-8'); ?>"
              readonly />
          </div>
          <div class="form-text">Top navigation bar background color.</div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bx bx-save me-1"></i>Save Theme
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mt-4">
  <h5 class="card-header">Storage Notes</h5>
  <div class="card-body">
    <p class="mb-2">
      These runtime variables are stored in the <code>system_settings</code> database table
      (key/value pairs). This is intentionally separate from <code>.env</code>, which should
      only hold secrets (DB credentials, webhook tokens) that must never be editable through
      a browser session.
    </p>
    <p class="mb-0">
      <strong>Never</strong> use this page for secrets — keep those in environment variables.
    </p>
  </div>
</div>

<?php if ((string) ($_SESSION['role'] ?? '') === 'admin') : ?>
  <div class="card mt-4">
    <h5 class="card-header">Backup &amp; export</h5>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Download core LMS tables for disaster recovery. Password hashes are <strong>not</strong> included in exports. Store files securely; they contain personal data.
      </p>
      <div class="d-flex flex-wrap gap-2">
        <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/backup_export.php', ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="backup_format" value="csv_zip" />
          <button type="submit" class="btn btn-outline-primary">
            <i class="bx bx-download me-1"></i>CSV (ZIP)
          </button>
        </form>
        <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/backup_export.php', ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="backup_format" value="sql" />
          <button type="submit" class="btn btn-outline-secondary">
            <i class="bx bx-data me-1"></i>SQL dump
          </button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

<!-- Logo Crop Modal -->
<div class="modal fade" id="logoCropModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crop Logo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="img-container" style="max-height: 500px;">
          <img id="logoCropImage" style="max-width: 100%;" />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="cropLogoBtn">
          <i class="bx bx-crop me-1"></i>Crop & Upload
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'SCRIPTS'
<script>
  (() => {
    if (typeof Swal === 'undefined') return;

    const successMsg = REPLACE_SUCCESS_MSG;
    const errorMsg = REPLACE_ERROR_MSG;
    if (successMsg) ClmsNotify.success(successMsg);
    if (errorMsg) ClmsNotify.error(errorMsg, 'Could not save settings');

    const colorInputs = [
      { picker: 'primary_color', text: 'primary_color_text' },
      { picker: 'secondary_color', text: 'secondary_color_text' },
      { picker: 'sidebar_bg_color', text: 'sidebar_bg_color_text' },
      { picker: 'navbar_bg_color', text: 'navbar_bg_color_text' }
    ];

    colorInputs.forEach(({ picker, text }) => {
      const pickerEl = document.getElementById(picker);
      const textEl = document.getElementById(text);
      if (pickerEl && textEl) {
        pickerEl.addEventListener('input', (e) => {
          textEl.value = e.target.value;
        });
      }
    });

    const settingsForm = document.getElementById('settingsForm');
    if (settingsForm) {
      settingsForm.addEventListener('submit', (event) => {
        if (settingsForm.dataset.confirmed === '1') return;
        event.preventDefault();
        Swal.fire({
          title: 'Save global settings?',
          text: 'These changes will apply to the entire LMS immediately.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Yes, save',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#800000',
        }).then((result) => {
          if (result.isConfirmed) {
            settingsForm.dataset.confirmed = '1';
            settingsForm.submit();
          }
        });
      });
    }

    const themeForm = document.getElementById('themeForm');
    if (themeForm) {
      themeForm.addEventListener('submit', (event) => {
        if (themeForm.dataset.confirmed === '1') return;
        event.preventDefault();
        Swal.fire({
          title: 'Save theme settings?',
          text: 'Theme changes will apply across all pages immediately.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Yes, save',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#800000',
        }).then((result) => {
          if (result.isConfirmed) {
            themeForm.dataset.confirmed = '1';
            themeForm.submit();
          }
        });
      });
    }
  })();

  // Logo upload with cropping
  (() => {
    const fileInput = document.getElementById('site_logo');
    const cropModalEl = document.getElementById('logoCropModal');
    const cropImage = document.getElementById('logoCropImage');
    const cropBtn = document.getElementById('cropLogoBtn');
    const logoPreview = document.getElementById('logoPreview');
    const logoUrlInput = document.getElementById('site_logo_url');
    const removeLogoBtn = document.getElementById('removeLogoBtn');
    let cropper = null;
    let cropModal = null;

    if (cropModalEl && typeof bootstrap !== 'undefined') {
      cropModal = new bootstrap.Modal(cropModalEl);
    }

    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
          if (typeof ClmsNotify !== 'undefined') {
            ClmsNotify.error('Please select a valid image file.');
          } else {
            alert('Please select a valid image file.');
          }
          fileInput.value = '';
          return;
        }
        const reader = new FileReader();
        reader.onload = (event) => {
          cropImage.src = event.target.result;
          if (cropModal && typeof Cropper !== 'undefined') {
            cropModal.show();
            setTimeout(() => {
              if (cropper) cropper.destroy();
              cropper = new Cropper(cropImage, {
                aspectRatio: 1,
                viewMode: 2,
                autoCropArea: 1,
                responsive: true,
                background: false,
              });
            }, 300);
          } else if (!cropModal) {
            alert('Modal not initialized');
          } else if (typeof Cropper === 'undefined') {
            alert('Cropper.js library not loaded');
          }
        };
        reader.readAsDataURL(file);
      });
    }

    if (cropBtn) {
      cropBtn.addEventListener('click', async () => {
        if (!cropper) return;
        cropBtn.disabled = true;
        cropBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';
        
        try {
          const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
          const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
          const formData = new FormData();
          formData.append('logo', blob, 'logo.png');
          formData.append('csrf_token', REPLACE_CSRF_TOKEN);
          
          const response = await fetch(REPLACE_UPLOAD_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });
          
          if (!response.ok) {
            throw new Error('Upload failed with status: ' + response.status);
          }
          
          const result = await response.json();
          if (result.ok && result.url) {
            logoUrlInput.value = result.url;
            const previewParent = logoPreview.parentNode;
            if (logoPreview.tagName === 'IMG') {
              logoPreview.src = result.url;
            } else {
              const img = document.createElement('img');
              img.id = 'logoPreview';
              img.src = result.url;
              img.alt = 'Site Logo';
              img.className = 'rounded';
              img.style.cssText = 'width: 120px; height: 120px; object-fit: cover; border: 2px solid #ddd;';
              previewParent.replaceChild(img, logoPreview);
              
              if (!removeLogoBtn) {
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-outline-danger mt-2';
                removeBtn.id = 'removeLogoBtn';
                removeBtn.innerHTML = '<i class="bx bx-trash me-1"></i>Remove Logo';
                previewParent.parentNode.querySelector('.flex-grow-1').appendChild(removeBtn);
                attachRemoveHandler(removeBtn);
              }
            }
            if (typeof ClmsNotify !== 'undefined') {
              ClmsNotify.success('Logo uploaded successfully. Click "Save Theme" to apply.');
            }
            if (cropModal) cropModal.hide();
            if (cropper) {
              cropper.destroy();
              cropper = null;
            }
            fileInput.value = '';
          } else {
            throw new Error(result.error || 'Failed to upload logo.');
          }
        } catch (error) {
          if (typeof ClmsNotify !== 'undefined') {
            ClmsNotify.error(error.message || 'Upload failed. Please try again.');
          } else {
            alert(error.message || 'Upload failed. Please try again.');
          }
        } finally {
          cropBtn.disabled = false;
          cropBtn.innerHTML = '<i class="bx bx-crop me-1"></i>Crop & Upload';
        }
      });
    }

    function attachRemoveHandler(btn) {
      btn.addEventListener('click', () => {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            title: 'Remove logo?',
            text: 'This will clear the logo. You\'ll need to save the theme to apply.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
          }).then((result) => {
            if (result.isConfirmed) {
              const preview = document.getElementById('logoPreview');
              logoUrlInput.value = '';
              const placeholder = document.createElement('div');
              placeholder.id = 'logoPreview';
              placeholder.className = 'rounded d-flex align-items-center justify-content-center bg-light text-muted';
              placeholder.style.cssText = 'width: 120px; height: 120px; border: 2px solid #ddd;';
              placeholder.innerHTML = '<i class="bx bx-image" style="font-size: 3rem;"></i>';
              preview.parentNode.replaceChild(placeholder, preview);
              btn.remove();
              if (typeof ClmsNotify !== 'undefined') {
                ClmsNotify.info('Logo removed. Click "Save Theme" to apply.');
              }
            }
          });
        } else if (confirm('Remove logo? This will clear the logo. You\'ll need to save the theme to apply.')) {
          const preview = document.getElementById('logoPreview');
          logoUrlInput.value = '';
          const placeholder = document.createElement('div');
          placeholder.id = 'logoPreview';
          placeholder.className = 'rounded d-flex align-items-center justify-content-center bg-light text-muted';
          placeholder.style.cssText = 'width: 120px; height: 120px; border: 2px solid #ddd;';
          placeholder.innerHTML = '<i class="bx bx-image" style="font-size: 3rem;"></i>';
          preview.parentNode.replaceChild(placeholder, preview);
          btn.remove();
        }
      });
    }

    if (removeLogoBtn) {
      attachRemoveHandler(removeLogoBtn);
    }

    if (cropModalEl) {
      cropModalEl.addEventListener('hidden.bs.modal', () => {
        if (cropper) {
          cropper.destroy();
          cropper = null;
        }
        if (fileInput) fileInput.value = '';
      });
    }
  })();
</script>
SCRIPTS;

$extraScripts = str_replace('REPLACE_SUCCESS_MSG', json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $extraScripts);
$extraScripts = str_replace('REPLACE_ERROR_MSG', json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $extraScripts);
$extraScripts = str_replace('REPLACE_CSRF_TOKEN', json_encode(clms_csrf_token()), $extraScripts);
$extraScripts = str_replace('REPLACE_UPLOAD_URL', json_encode($clmsWebBase . '/admin/upload_logo.php'), $extraScripts);

require_once __DIR__ . '/includes/layout-bottom.php';
