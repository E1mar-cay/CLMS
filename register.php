<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/database.php';

clms_session_start();
clms_redirect_if_logged_in();

$pageTitle = 'Register | Criminology LMS';
$formError = '';
$firstName = '';
$lastName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $formError = 'Please enter your first and last name.';
        } elseif (strlen($firstName) > 50 || strlen($lastName) > 50) {
            $formError = 'Name fields must be 50 characters or fewer.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Please enter a valid email address.';
        } elseif (strlen($email) > 100) {
            $formError = 'Email must be 100 characters or fewer.';
        } elseif (strlen($password) < 8) {
            $formError = 'Password must be at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                $formError = 'Registration could not be completed. Please try again.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (:first_name, :last_name, :email, :password_hash, :role)'
                    );
                    $stmt->execute([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'password_hash' => $hash,
                        'role' => 'student',
                    ]);
                    clms_redirect('login.php?registered=1');
                } catch (PDOException $e) {
                    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                        $formError = 'An account with this email already exists.';
                    } else {
                        error_log($e->getMessage());
                        $formError = 'Registration could not be completed. Please try again.';
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/auth-header.php';

$showUpgradeToPro = false;

?>
    <style>
      body {
        background: linear-gradient(180deg, var(--clms-cream) 0%, #f4f1df 100%);
      }

      .clms-auth-shell {
        min-height: 100vh;
        display: flex;
        align-items: center;
        padding: 2rem 0;
      }

      .clms-auth-card {
        border-radius: var(--clms-radius);
        border: 1px solid rgba(15, 32, 75, 0.1);
        box-shadow: var(--clms-shadow-hover);
        overflow: hidden;
      }

      .clms-auth-panel {
        background: linear-gradient(135deg, #0f204b 0%, #1a2f6b 100%);
        color: #fff;
        height: 100%;
        padding: 2rem;
      }

      .clms-auth-panel h2 {
        color: #fff;
        font-weight: 700;
        margin-bottom: .75rem;
      }

      .clms-auth-panel p {
        color: rgba(255, 255, 255, 0.85);
      }

      .clms-point {
        display: flex;
        gap: .5rem;
        align-items: flex-start;
        margin-bottom: .9rem;
        color: rgba(255, 255, 255, 0.9);
      }

      .clms-point i {
        color: #cdd8ff;
        font-size: 1.1rem;
        line-height: 1.2;
      }

      .clms-auth-form {
        padding: 2rem;
        background: #fff;
      }

      .clms-auth-form h1 {
        color: var(--clms-navy);
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.2;
      }

      @media (max-width: 991.98px) {
        .clms-auth-panel {
          border-bottom: 1px solid rgba(255, 255, 255, 0.18);
        }
      }

      @media (max-width: 575.98px) {
        .clms-auth-panel,
        .clms-auth-form {
          padding: 1.25rem;
        }
      }
    </style>
    <div class="container-xxl">
      <div class="clms-auth-shell">
        <div class="container">
          <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">
              <div class="card clms-auth-card">
                <div class="row g-0">
                  <div class="col-lg-5">
                    <div class="clms-auth-panel">
                      <h2>Join CLMS</h2>
                      <p class="mb-4">Create your student account to request course access and begin your structured board exam review journey.</p>
                      <div class="clms-point"><i class="bx bx-check-shield" aria-hidden="true"></i><span>Strict progression with mastery-based assessments</span></div>
                      <div class="clms-point"><i class="bx bx-video" aria-hidden="true"></i><span>Self-paced module videos aligned to criminology outcomes</span></div>
                      <div class="clms-point"><i class="bx bx-certification" aria-hidden="true"></i><span>Verified certification upon full completion</span></div>
                    </div>
                  </div>
                  <div class="col-lg-7">
                    <div class="clms-auth-form">
                      <h1 class="mb-2">Create Student Account</h1>
                      <p class="text-muted mb-4">Use your active email address. Password must be at least 8 characters.</p>
                      <form id="formAuthentication" class="mb-4" action="<?php echo htmlspecialchars($clmsWebBase . '/register.php', ENT_QUOTES, 'UTF-8'); ?>" method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <div class="row g-3">
                          <div class="col-sm-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input
                              type="text"
                              class="form-control"
                              id="first_name"
                              name="first_name"
                              placeholder="Enter first name"
                              value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>"
                              maxlength="50"
                              autofocus
                              required />
                          </div>
                          <div class="col-sm-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input
                              type="text"
                              class="form-control"
                              id="last_name"
                              name="last_name"
                              placeholder="Enter last name"
                              value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>"
                              maxlength="50"
                              required />
                          </div>
                          <div class="col-12">
                            <label for="email" class="form-label">Email address</label>
                            <input
                              type="email"
                              class="form-control"
                              id="email"
                              name="email"
                              placeholder="name@example.com"
                              value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                              maxlength="100"
                              required />
                          </div>
                          <div class="col-12 form-password-toggle">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-group input-group-merge">
                              <input
                                type="password"
                                id="password"
                                class="form-control"
                                name="password"
                                placeholder="Enter at least 8 characters"
                                aria-describedby="password"
                                minlength="8"
                                required />
                              <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                            </div>
                          </div>
                        </div>
                        <button class="btn btn-clms-primary d-grid w-100 mt-4" type="submit">Create Account</button>
                      </form>

                      <p class="text-center mb-0">
                        <span class="text-muted">Already have an account?</span>
                        <a class="clms-auth-footer-link" href="<?php echo htmlspecialchars($clmsWebBase . '/login.php', ENT_QUOTES, 'UTF-8'); ?>">
                          Sign in
                        </a>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if ($formError !== '') : ?>
    <script>
      Swal.fire({
        icon: 'error',
        title: 'Registration Failed',
        text: <?php echo json_encode($formError, JSON_UNESCAPED_SLASHES); ?>,
        confirmButtonColor: '#0f204b',
      });
    </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
