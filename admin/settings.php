<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
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

            if (!is_numeric($passingInput)) {
                throw new RuntimeException('Default passing score must be a number.');
            }
            $passingFloat = (float) $passingInput;
            if ($passingFloat < 0.0 || $passingFloat > 100.0) {
                throw new RuntimeException('Default passing score must be between 0 and 100.');
            }
            $passingNormalized = number_format($passingFloat, 2, '.', '');

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
            $pdo->commit();

            $successMessage = 'Settings saved successfully.';
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
<?php endif; ?>

                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-1"></i>Save Settings
                      </button>
                    </div>
                  </form>
                </div>
              </div>

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

              <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
              <script>
                (() => {
                  if (typeof Swal === 'undefined') return;

                  const successMsg = <?php echo json_encode($successMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                  const errorMsg = <?php echo json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                  if (successMsg) ClmsNotify.success(successMsg);
                  if (errorMsg) ClmsNotify.error(errorMsg, 'Could not save settings');

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
                        confirmButtonColor: '#b01030',
                      }).then((result) => {
                        if (result.isConfirmed) {
                          settingsForm.dataset.confirmed = '1';
                          settingsForm.submit();
                        }
                      });
                    });
                  }
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
