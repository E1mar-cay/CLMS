<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/remember.php';
require_once __DIR__ . '/database.php';

clms_session_start();
clms_redirect_if_logged_in();

$pageTitle = 'Login | Criminology LMS';
$formError = '';
$emailValue = '';
$registered = isset($_GET['registered']) && $_GET['registered'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim((string) ($_POST['email'] ?? ''));
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if ($emailValue === '' || $password === '') {
            $formError = 'Please enter your email and password.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, email, password_hash, role, first_name FROM users WHERE email = :email LIMIT 1'
            );
            $stmt->execute(['email' => $emailValue]);
            $user = $stmt->fetch();

            if (
                !$user
                || !is_string($user['password_hash'] ?? null)
                || !password_verify($password, $user['password_hash'])
            ) {
                $formError = 'Invalid email or password.';
            } else {
                $role = $user['role'] ?? '';
                if (!in_array($role, ['student', 'instructor', 'admin'], true)) {
                    $formError = 'Your account is not authorized.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['role'] = $role;
                    $_SESSION['email'] = (string) $user['email'];
                    $_SESSION['first_name'] = (string) ($user['first_name'] ?? '');

                    // Honour the "Keep me signed in" checkbox: mint a
                    // long-lived token so the session can be rebuilt after
                    // the browser is closed. Must be set before we emit
                    // the redirect headers below.
                    $rememberChecked = isset($_POST['remember']) && $_POST['remember'] !== '';
                    if ($rememberChecked) {
                        clms_remember_issue_token((int) $user['id']);
                    } else {
                        // If the user previously opted in but is now
                        // un-checking, make sure we kill any existing
                        // cookie on this browser too.
                        clms_remember_revoke_current();
                    }

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
    }
}

$showUpgradeToPro = false;

require_once __DIR__ . '/includes/auth-header.php';

?>
    <style>
      /* --- Login hero layout (scoped to this page) ------------------
         Two-column branded shell: navy hero on the left carries the
         brand story, cream/white form card on the right carries the
         UI. Collapses to a single column at <992px so mobile users
         get the form immediately. */
      html, body { height: 100%; }
      body.clms-auth-body {
        background-color: var(--clms-cream, #fdfcf0);
        margin: 0;
      }

      .clms-auth-shell {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
        min-height: 100vh;
      }
      @media (max-width: 991.98px) {
        .clms-auth-shell { grid-template-columns: 1fr; }
      }

      /* ---- Left: branded hero ---- */
      .clms-auth-hero {
        position: relative;
        color: #fff;
        padding: 3rem 2.75rem;
        background: linear-gradient(135deg, #0a1736 0%, #0f204b 45%, #1a2f6b 100%);
        overflow: hidden;
        display: flex;
        flex-direction: column;
      }
      @media (max-width: 991.98px) {
        .clms-auth-hero { display: none; }
      }
      /* Subtle dot pattern echoes the "network nodes" in the logo */
      .clms-auth-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: radial-gradient(circle at 1px 1px, rgba(255, 255, 255, .08) 1px, transparent 0);
        background-size: 24px 24px;
        opacity: .55;
        pointer-events: none;
      }
      /* Soft gold glow in the corner, nodding to the cert gold in the logo */
      .clms-auth-hero::after {
        content: '';
        position: absolute;
        right: -80px;
        top: -80px;
        width: 320px;
        height: 320px;
        background: radial-gradient(circle, rgba(212, 175, 55, .18) 0%, transparent 65%);
        pointer-events: none;
      }
      .clms-auth-hero-top,
      .clms-auth-hero-main,
      .clms-auth-hero-bottom { position: relative; }

      .clms-auth-hero-brand {
        display: inline-flex;
        align-items: center;
        gap: .75rem;
        color: #fff;
        text-decoration: none;
      }
      .clms-auth-hero-brand-mark {
        width: 42px;
        height: 42px;
        object-fit: contain;
      }
      .clms-auth-hero-brand-text {
        font-weight: 700;
        font-size: 1rem;
        letter-spacing: .5px;
      }

      .clms-auth-hero-main {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        max-width: 540px;
      }
      .clms-auth-hero-logo {
        width: 148px;
        height: 148px;
        margin-bottom: 2rem;
        object-fit: contain;
        filter: drop-shadow(0 14px 40px rgba(0, 0, 0, .35));
      }
      .clms-auth-hero-title {
        font-size: 2.25rem;
        font-weight: 700;
        line-height: 1.15;
        margin: 0 0 1rem;
        color: #fff;
      }
      .clms-auth-hero-subtitle {
        font-size: 1.05rem;
        line-height: 1.55;
        color: rgba(255, 255, 255, .78);
        margin: 0 0 2rem;
      }
      .clms-auth-hero-pillars {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: .85rem;
      }
      .clms-auth-hero-pillars li {
        display: flex;
        align-items: flex-start;
        gap: .75rem;
        color: rgba(255, 255, 255, .85);
        font-size: .95rem;
      }
      .clms-auth-hero-pillars i {
        color: #d4af37;
        font-size: 1.25rem;
        line-height: 1.25;
        flex-shrink: 0;
      }

      .clms-auth-hero-bottom {
        font-size: .8rem;
        color: rgba(255, 255, 255, .55);
      }

      /* ---- Right: form column ---- */
      .clms-auth-form-col {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.25rem 1.25rem;
      }
      .clms-auth-card {
        width: 100%;
        max-width: 440px;
        background: #fff;
        border: 1px solid rgba(15, 32, 75, .06);
        border-radius: var(--clms-radius);
        box-shadow: var(--clms-shadow-hover);
        padding: 2.5rem 2.25rem;
      }
      @media (max-width: 575.98px) {
        .clms-auth-card { padding: 1.75rem 1.25rem; }
      }

      .clms-auth-mobile-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .5rem;
        margin-bottom: 1.25rem;
      }
      .clms-auth-mobile-brand img {
        width: 64px;
        height: 64px;
        object-fit: contain;
      }
      .clms-auth-mobile-brand span {
        font-weight: 700;
        color: var(--clms-navy);
        letter-spacing: .3px;
      }

      .clms-auth-welcome {
        font-size: 1.55rem;
        font-weight: 700;
        color: #1a2340;
        line-height: 1.2;
        margin: 0 0 .4rem;
      }
      .clms-auth-lead {
        color: #6b7280;
        font-size: .95rem;
        margin: 0 0 1.5rem;
      }

      /* Demo accounts */
      .clms-auth-divider {
        display: flex;
        align-items: center;
        gap: .75rem;
        color: #6b7280;
        font-size: .75rem;
        font-weight: 600;
        letter-spacing: .8px;
        text-transform: uppercase;
        margin: 1.75rem 0 1rem;
      }
      .clms-auth-divider::before,
      .clms-auth-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: rgba(15, 32, 75, .1);
      }

      .clms-demo-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: .5rem;
      }
      @media (max-width: 380px) {
        .clms-demo-grid { grid-template-columns: 1fr; }
      }
      .clms-demo-pill {
        border: 1px solid rgba(15, 32, 75, .12);
        background: #fff;
        border-radius: var(--clms-radius);
        padding: .65rem .5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .25rem;
        cursor: pointer;
        transition: var(--clms-transition);
        color: var(--clms-navy);
        font-size: .8rem;
        font-weight: 600;
      }
      .clms-demo-pill i {
        font-size: 1.15rem;
        color: var(--clms-navy);
      }
      .clms-demo-pill small {
        color: #8a94a6;
        font-weight: 500;
        font-size: .68rem;
      }
      .clms-demo-pill:hover {
        border-color: var(--clms-navy);
        background: rgba(15, 32, 75, .03);
        transform: translateY(-1px);
        box-shadow: var(--clms-shadow);
      }
      .clms-demo-pill:active { transform: translateY(0); }

      /* Shared auth input/button/link tokens are in public/assets/css/auth-public.css */
    </style>

    <div class="clms-auth-shell">
      <!-- LEFT: branded hero (hidden on mobile) -->
      <div class="clms-auth-hero">
        <div class="clms-auth-hero-top">
          <a class="clms-auth-hero-brand" href="<?php echo htmlspecialchars($clmsWebBase . '/login.php', ENT_QUOTES, 'UTF-8'); ?>">
            <img
              class="clms-auth-hero-brand-mark"
              src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>"
              alt="CLMS" />
            <span class="clms-auth-hero-brand-text">CRIMINOLOGY LMS</span>
          </a>
        </div>

        <div class="clms-auth-hero-main">
          <img
            class="clms-auth-hero-logo"
            src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>"
            alt="Criminology Learning Management System" />
          <h1 class="clms-auth-hero-title">Where justice education meets modern learning.</h1>
          <p class="clms-auth-hero-subtitle">
            A dedicated learning platform for criminology students, instructors, and administrators &mdash; built around real coursework, exams, and measurable progress.
          </p>
          <ul class="clms-auth-hero-pillars">
            <li><i class="bx bx-book-open"></i><span>Structured courses, modules, and readings.</span></li>
            <li><i class="bx bx-task"></i><span>Timed exams with automated and essay grading.</span></li>
            <li><i class="bx bx-award"></i><span>Certificates that track real learner outcomes.</span></li>
          </ul>
        </div>

        <div class="clms-auth-hero-bottom">
          &copy; <?php echo date('Y'); ?> Criminology Learning Management System
        </div>
      </div>

      <!-- RIGHT: sign-in card -->
      <div class="clms-auth-form-col">
        <div class="clms-auth-card">
          <!-- Mobile-only brand (left hero is hidden there) -->
          <div class="clms-auth-mobile-brand d-lg-none">
            <img
              src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>"
              alt="CLMS" />
            <span>CRIMINOLOGY LMS</span>
          </div>

          <h2 class="clms-auth-welcome">Welcome back</h2>
          <p class="clms-auth-lead">Sign in to continue your learning journey.</p>

<?php if ($registered) : ?>
          <div class="alert alert-success mb-4 d-flex align-items-center" role="alert">
            <i class="bx bx-check-circle me-2"></i>
            <span>Registration successful. You can sign in below.</span>
          </div>
<?php endif; ?>

<?php if ($formError !== '') : ?>
          <div class="alert alert-danger mb-4 d-flex align-items-center" role="alert">
            <i class="bx bx-error-circle me-2"></i>
            <span><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
<?php endif; ?>

          <form id="formAuthentication" class="mb-3" action="<?php echo htmlspecialchars($clmsWebBase . '/login.php', ENT_QUOTES, 'UTF-8'); ?>" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />

            <div class="mb-3">
              <label for="email" class="form-label">Email</label>
              <input
                type="email"
                class="form-control"
                id="email"
                name="email"
                placeholder="you@example.com"
                value="<?php echo htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8'); ?>"
                autofocus
                required />
            </div>

            <div class="mb-3 form-password-toggle">
              <div class="d-flex justify-content-between">
                <label class="form-label" for="password">Password</label>
                <a href="javascript:void(0);" class="small text-decoration-none">Forgot password?</a>
              </div>
              <div class="input-group input-group-merge">
                <input
                  type="password"
                  id="password"
                  class="form-control"
                  name="password"
                  placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                  aria-describedby="password"
                  required />
                <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
              </div>
            </div>

            <div class="mb-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember-me" name="remember" />
                <label class="form-check-label" for="remember-me">Keep me signed in</label>
              </div>
            </div>

            <button class="btn btn-clms-primary w-100" type="submit">
              <i class="bx bx-log-in-circle me-1"></i>Sign in
            </button>
          </form>

          <p class="text-center mb-0">
            <span class="text-muted">New here?</span>
            <a href="<?php echo htmlspecialchars($clmsWebBase . '/register.php', ENT_QUOTES, 'UTF-8'); ?>">Create an account</a>
          </p>

          <div class="clms-auth-divider">Demo Accounts</div>
          <div class="clms-demo-grid">
            <button
              type="button"
              class="clms-demo-pill"
              data-demo-email="admin@clms.local"
              data-demo-password="Admin@123"
              title="Use the admin demo account">
              <i class="bx bx-shield-quarter"></i>
              <span>Admin</span>
              <small>Admin@123</small>
            </button>
            <button
              type="button"
              class="clms-demo-pill"
              data-demo-email="instructor@clms.local"
              data-demo-password="Instructor@123"
              title="Use the instructor demo account">
              <i class="bx bx-chalkboard"></i>
              <span>Instructor</span>
              <small>Instructor@123</small>
            </button>
            <button
              type="button"
              class="clms-demo-pill"
              data-demo-email=""
              data-demo-password="Student@123"
              data-demo-note="Use any seeded student email (e.g. student1.user123@clms.local)"
              title="Copy the student demo password">
              <i class="bx bx-user-circle"></i>
              <span>Student</span>
              <small>Student@123</small>
            </button>
          </div>
        </div>
      </div>
    </div>

    <script>
      /* Apply the cream background reliably even if another stylesheet
         beats Sneat's body reset. Scoping via a class keeps it tidy. */
      document.body.classList.add('clms-auth-body');
    </script>

    <script>
      (function () {
        var emailInput = document.getElementById('email');
        var passwordInput = document.getElementById('password');
        if (!emailInput || !passwordInput) { return; }

        document.querySelectorAll('[data-demo-email]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var email = btn.getAttribute('data-demo-email') || '';
            var password = btn.getAttribute('data-demo-password') || '';
            var note = btn.getAttribute('data-demo-note') || '';

            if (email) {
              emailInput.value = email;
            } else if (note) {
              emailInput.focus();
              emailInput.placeholder = note;
            }
            if (password) {
              passwordInput.value = password;
            }
          });
        });
      })();
    </script>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
