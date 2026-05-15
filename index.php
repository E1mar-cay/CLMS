<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sneat-paths.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme-settings.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/landing-carousel-schema.php';

clms_session_start();
clms_try_remember_autologin();

$isLoggedIn = clms_logged_in();
$userRole = clms_current_role();

$clmsThemeSettings = clms_get_theme_settings($pdo);
/** @var list<array<string,mixed>> $carouselSlides */
$carouselSlides = clms_landing_carousel_active_slides($pdo);

$dashboardTarget = 'login.php';
if ($userRole === 'admin') {
  $dashboardTarget = 'admin/dashboard.php';
} elseif ($userRole === 'instructor') {
  $dashboardTarget = 'instructor/dashboard.php';
} elseif ($userRole === 'student') {
  $dashboardTarget = 'student/dashboard.php';
}

$courses = [];
try {
  $courseStmt = $pdo->prepare(
    'SELECT
            c.id,
            c.title,
            c.description,
            c.thumbnail_url,
            c.level,
            (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS total_modules,
            (SELECT COALESCE(SUM(m.duration_minutes), 0) FROM modules m WHERE m.course_id = c.id) AS total_duration_minutes,
            (SELECT COUNT(DISTINCT up.user_id)
                FROM user_progress up
                INNER JOIN modules mm ON mm.id = up.module_id
                WHERE mm.course_id = c.id) AS learner_count,
            (SELECT COUNT(*) FROM questions q WHERE q.course_id = c.id) AS question_count
         FROM courses c
         WHERE c.is_published = 1
         ORDER BY c.id DESC
         LIMIT 6'
  );
  $courseStmt->execute();
  /** @var list<array{id:int|string,title:string,description:string|null,thumbnail_url:string|null,level:string|null}> $courses */
  $courses = $courseStmt->fetchAll();
} catch (Throwable $e) {
  $courses = [];
}

$formatDuration = static function (int $totalMinutes): string {
  if ($totalMinutes <= 0) {
    return 'Self-paced';
  }
  if ($totalMinutes < 60) {
    return $totalMinutes . ' min';
  }
  $hours = intdiv($totalMinutes, 60);
  $minutes = $totalMinutes % 60;
  if ($minutes === 0) {
    return $hours . ($hours === 1 ? ' hr' : ' hrs');
  }
  return $hours . ' hr ' . $minutes . ' min';
};

$coursePlaceholderGradients = [
  'linear-gradient(135deg, #5c0a0a 0%, #800000 45%, #a52a2a 100%)',
  'linear-gradient(135deg, #5c0a0a 0%, #800000 50%, #a52a2a 100%)',
  'linear-gradient(135deg, #3d050f 0%, #5c0a0a 40%, #800000 100%)',
  'linear-gradient(135deg, #5c0a0a 0%, #800000 55%, #a52a2a 100%)',
  'linear-gradient(135deg, #5c0a0a 0%, #800000 35%, #a52a2a 100%)',
  'linear-gradient(135deg, #5c0a0a 0%, #a52a2a 60%, #800000 100%)',
];

$resolveThumbnailUrl = static function (?string $rawPath) use ($clmsWebBase): string {
  $path = trim((string) $rawPath);
  if ($path === '') {
    return '';
  }
  if (preg_match('/^(https?:)?\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
    return $path;
  }
  if (str_starts_with($path, '/')) {
    return rtrim((string) $clmsWebBase, '/') . $path;
  }
  return rtrim((string) $clmsWebBase, '/') . '/' . ltrim($path, '/');
};

$resolveLandingHref = static function (string $rawUrl) use ($clmsWebBase): string {
  $u = trim($rawUrl);
  if ($u === '') {
    return 'register.php';
  }
  if (preg_match('/^(https?:)?\/\//i', $u) === 1) {
    return $u;
  }
  if (str_starts_with($u, '/')) {
    return rtrim((string) $clmsWebBase, '/') . $u;
  }
  if (str_starts_with($u, '#')) {
    return $u;
  }

  return $u;
};
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Criminology Learning Management System for strict, self-paced board exam preparation.">
  <title><?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?> | Criminology Learning Management System</title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(($clmsWebBase ?: '') . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(($clmsWebBase ?: '') . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="theme-color" content="#fdfcf0" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(($clmsWebBase ?: '') . '/public/assets/css/custom.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <?php echo clms_render_theme_css($clmsThemeSettings); ?>
  <style>
    /* Landing-only layout; maroon theme with Bootstrap primary */
    :root {
      --clms-maroon: var(--clms-primary-color);
      --clms-maroon-light: color-mix(in srgb, var(--clms-primary-color) 110%, white);
      --clms-maroon-dark: color-mix(in srgb, var(--clms-primary-color) 85%, black);
      --clms-cream: #fdfcf0;
      --clms-radius: 0.5rem;
      --clms-shadow: 0 4px 6px -1px rgba(128, 0, 0, 0.10), 0 2px 4px -1px rgba(128, 0, 0, 0.06);
      --clms-shadow-hover: 0 10px 15px -3px rgba(128, 0, 0, 0.12), 0 4px 6px -2px rgba(128, 0, 0, 0.08);
      --clms-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
      font-family: "Public Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      color: #2c1810;
      line-height: 1.6;
      background-color: var(--clms-cream);
    }

    .site-navbar {
      background-color: rgba(253, 252, 240, 0.96);
      border-bottom: 1px solid rgba(128, 0, 0, 0.1);
      -webkit-backdrop-filter: saturate(120%) blur(6px);
      backdrop-filter: saturate(120%) blur(6px);
    }

    .brand-logo {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      font-weight: 800;
      color: var(--clms-maroon);
      letter-spacing: 0.3px;
      text-decoration: none;
      transition: var(--clms-transition);
    }

    .brand-logo-icon {
      width: 32px;
      height: 32px;
      object-fit: contain;
      flex-shrink: 0;
    }

    .brand-logo:hover {
      color: var(--clms-maroon-dark);
      transform: scale(1.05);
    }

    .site-navbar .navbar-toggler {
      border: 1px solid rgba(128, 0, 0, 0.2);
      border-radius: var(--clms-radius);
      padding: .35rem .45rem;
      box-shadow: var(--clms-shadow);
      transition: var(--clms-transition);
    }

    .site-navbar .navbar-toggler:hover {
      box-shadow: var(--clms-shadow-hover);
      background-color: rgba(128, 0, 0, 0.05);
    }

    .site-navbar .navbar-toggler:focus {
      box-shadow: 0 0 0 .2rem rgba(128, 0, 0, 0.15);
    }

    .site-navbar .navicon {
      font-size: 1.35rem;
      line-height: 1;
      color: var(--clms-maroon);
      display: block;
    }

    .btn-clms {
      border-radius: var(--clms-radius);
      box-shadow: var(--clms-shadow);
      transition: var(--clms-transition);
      font-weight: 600;
      position: relative;
      overflow: hidden;
    }

    .btn-clms::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .btn-clms:hover::before {
      left: 100%;
    }

    .btn-clms:hover {
      box-shadow: var(--clms-shadow-hover);
      transform: translateY(-2px);
    }

    .btn-clms-primary {
      background-color: var(--clms-maroon);
      border-color: var(--clms-maroon);
      color: #fff;
    }

    .btn-clms-primary:hover {
      background-color: var(--clms-maroon-dark);
      border-color: var(--clms-maroon-dark);
      color: #fff;
    }

    .btn-clms-outline {
      border-color: var(--clms-maroon);
      color: var(--clms-maroon);
      background-color: transparent;
    }

    .btn-clms-outline:hover {
      background-color: var(--clms-maroon);
      border-color: var(--clms-maroon);
      color: #fff;
    }

    .hero-section {
      background: linear-gradient(180deg, var(--clms-cream) 0%, #f4f1df 50%, rgba(128, 0, 0, 0.05) 100%);
      padding: 6rem 0 5rem;
      position: relative;
    }

    .clms-hero-carousel .carousel-inner {
      overflow: visible;
    }

    .clms-hero-carousel .carousel-item .hero-section {
      min-height: clamp(32rem, 55vh, 44rem);
      padding: 6rem 0 5.5rem;
    }

    /* Full-bleed slide: the photo fills the entire hero, edge to edge.
       A horizontal gradient keeps the left side readable while letting
       the photo show on the right. On mobile we fall back to a vertical
       gradient so text never collides with the subject of the photo. */
    .hero-slide-frame {
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }

    .hero-slide-bg {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      opacity: 1;
      pointer-events: none;
      z-index: 0;
    }

    /* Photo-only slides (no headline/description/button): the image is the
       whole message — show it in full (no cropping), centred, with no text
       overlay or dark scrim covering it. The background uses `contain` so
       portrait passer photos remain entirely visible. */
    .hero-slide-photo-only {
      background:
        radial-gradient(circle at center, rgba(128, 0, 0, 0.04) 0%, rgba(128, 0, 0, 0.10) 100%),
        var(--clms-cream);
    }

    .hero-slide-bg-contain {
      background-size: contain;
      background-position: center;
    }

    /* Soft left-to-bottom darkening keeps text readable without hiding
       the photo. The right side stays bright so the image is visible. */
    .hero-slide-scrim {
      position: absolute;
      inset: 0;
      background:
        linear-gradient(
          100deg,
          rgba(0, 0, 0, 0.55) 0%,
          rgba(0, 0, 0, 0.30) 45%,
          rgba(0, 0, 0, 0.10) 80%,
          rgba(0, 0, 0, 0.00) 100%
        );
      pointer-events: none;
      z-index: 1;
    }

    .hero-slide-content {
      position: relative;
      z-index: 2;
    }

    /* Text rendered directly on the image; rely on the scrim + shadows
       for legibility instead of a card / panel. */
    .clms-hero-carousel .carousel-item .hero-section .hero-title {
      color: #fff;
      text-shadow: 0 2px 12px rgba(0, 0, 0, 0.55), 0 1px 2px rgba(0, 0, 0, 0.45);
    }

    .clms-hero-carousel .carousel-item .hero-section .lead {
      color: rgba(255, 255, 255, 0.95);
      text-shadow: 0 1px 6px rgba(0, 0, 0, 0.55);
    }

    .clms-hero-carousel .carousel-item .hero-section .hero-kpi {
      background: rgba(0, 0, 0, 0.32);
      border-color: rgba(255, 255, 255, 0.18);
      -webkit-backdrop-filter: blur(4px);
      backdrop-filter: blur(4px);
    }

    .clms-hero-carousel .carousel-item .hero-section .hero-kpi-value {
      color: #fff;
    }

    .clms-hero-carousel .carousel-item .hero-section .hero-kpi-label {
      color: rgba(255, 255, 255, 0.85);
    }

    @media (max-width: 767.98px) {
      .clms-hero-carousel .carousel-item .hero-section {
        min-height: clamp(26rem, 70vh, 36rem);
        padding: 4rem 0 4rem;
      }

      .hero-slide-scrim {
        background:
          linear-gradient(
            180deg,
            rgba(0, 0, 0, 0.30) 0%,
            rgba(0, 0, 0, 0.45) 55%,
            rgba(0, 0, 0, 0.55) 100%
          );
      }
    }

    .clms-hero-carousel .carousel-indicators {
      margin-bottom: 0.35rem;
    }

    .clms-hero-carousel .carousel-indicators [type="button"] {
      width: 0.55rem;
      height: 0.55rem;
      border-radius: 50%;
      background-color: var(--clms-maroon);
      opacity: 0.35;
    }

    .clms-hero-carousel .carousel-indicators .active {
      opacity: 1;
    }

    .clms-hero-carousel .carousel-control-prev-icon,
    .clms-hero-carousel .carousel-control-next-icon {
      display: none;
    }

    .clms-hero-carousel .carousel-control-prev,
    .clms-hero-carousel .carousel-control-next {
      width: 3rem;
      opacity: 1;
      color: var(--clms-maroon);
      font-size: 1.75rem;
      line-height: 1;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .clms-hero-carousel .carousel-control-prev > .bx,
    .clms-hero-carousel .carousel-control-next > .bx {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.4rem;
      height: 2.4rem;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.85);
      border: 1px solid rgba(128, 0, 0, 0.18);
      box-shadow: 0 4px 8px rgba(128, 0, 0, 0.12);
      transition: var(--clms-transition);
    }

    .clms-hero-carousel .carousel-control-prev:hover > .bx,
    .clms-hero-carousel .carousel-control-next:hover > .bx {
      background: #fff;
      color: var(--clms-maroon-dark);
      transform: scale(1.05);
    }

    .clms-hero-carousel .carousel-indicators {
      margin-bottom: 0.75rem;
    }

    .hero-section::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="rgba(128,0,0,0.1)"/></svg>') repeat;
      opacity: 0.3;
      pointer-events: none;
    }

    .hero-kpis {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .75rem;
      margin-top: 1.25rem;
      max-width: 560px;
    }

    @supports not (display: grid) {
      .hero-kpis {
        display: block;
      }

      .hero-kpis>* {
        margin-bottom: .75rem;
      }
    }

    .hero-kpi {
      border: 1px solid rgba(128, 0, 0, 0.12);
      border-radius: var(--clms-radius);
      background: rgba(255, 255, 255, 0.8);
      box-shadow: var(--clms-shadow);
      transition: var(--clms-transition);
      padding: .7rem .8rem;
      -webkit-backdrop-filter: blur(10px);
      backdrop-filter: blur(10px);
    }

    .hero-kpi:hover {
      box-shadow: var(--clms-shadow-hover);
      transform: translateY(-3px);
      background: rgba(255, 255, 255, 0.9);
    }

    .hero-kpi-value {
      color: var(--clms-maroon);
      font-size: 1.05rem;
      font-weight: 800;
      line-height: 1.1;
    }

    .hero-kpi-label {
      font-size: .74rem;
      color: #8b4513;
      text-transform: uppercase;
      letter-spacing: .07em;
      font-weight: 600;
    }

    .hero-title {
      color: var(--clms-maroon);
      font-weight: 800;
      letter-spacing: -0.02em;
      line-height: 1.15;
      text-shadow: 0 2px 4px rgba(128, 0, 0, 0.1);
    }

    .section-title {
      color: var(--clms-maroon);
      font-weight: 700;
    }

    .course-card,
    .feature-item {
      border: 1px solid rgba(128, 0, 0, 0.1);
      border-radius: var(--clms-radius);
      box-shadow: var(--clms-shadow);
      transition: var(--clms-transition);
      background-color: #fff;
      height: 100%;
      overflow: hidden;
    }

    .course-card:hover,
    .feature-item:hover {
      box-shadow: var(--clms-shadow-hover);
      transform: translateY(-4px);
    }

    .course-thumb {
      border-radius: var(--clms-radius);
      overflow: hidden;
      border: 1px solid rgba(128, 0, 0, 0.1);
      box-shadow: var(--clms-shadow);
      margin-bottom: 1rem;
      aspect-ratio: 16 / 9;
      background: linear-gradient(135deg, #fdeef1 0%, #f0e4e8 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }

    .course-thumb::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(45deg, transparent 30%, rgba(128, 0, 0, 0.1) 50%, transparent 70%);
      opacity: 0;
      transition: opacity 0.3s;
    }

    .course-card:hover .course-thumb::after {
      opacity: 1;
    }

    .course-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: var(--clms-transition);
    }

    .course-card:hover .course-thumb img {
      transform: scale(1.05);
    }

    .course-thumb-fallback {
      color: var(--clms-maroon);
      text-align: center;
      padding: 1rem;
    }

    .course-thumb-fallback i {
      font-size: 2rem;
      display: block;
      margin-bottom: .3rem;
    }

    .course-title {
      color: var(--clms-maroon);
      font-weight: 700;
    }

    .course-description {
      color: #4d5a78;
      margin-bottom: 0;
    }

    .request-badge {
      border-radius: var(--clms-radius);
      background-color: rgba(128, 0, 0, 0.08);
      color: var(--clms-maroon);
      border: 1px solid rgba(128, 0, 0, 0.15);
      font-weight: 600;
    }

    .landing-catalog-card {
      border: 1px solid rgba(128, 0, 0, 0.1);
      border-radius: var(--clms-radius);
      box-shadow: var(--clms-shadow);
      transition: var(--clms-transition);
      background: #fff;
      overflow: hidden;
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .landing-catalog-card:hover {
      box-shadow: var(--clms-shadow-hover);
      transform: translateY(-4px);
    }

    .landing-catalog-media {
      position: relative;
      aspect-ratio: 16 / 9;
      background: linear-gradient(135deg, #5c0a0a 0%, #800000 45%, #a52a2a 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .landing-catalog-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: var(--clms-transition);
    }

    .landing-catalog-card:hover .landing-catalog-media img {
      transform: scale(1.05);
    }

    .landing-catalog-placeholder {
      color: rgba(255, 255, 255, 0.9);
      font-size: 2rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-shadow: 0 2px 10px rgba(0, 0, 0, .25);
      text-transform: uppercase;
    }

    .landing-catalog-level {
      position: absolute;
      top: .6rem;
      left: .6rem;
      border-radius: var(--clms-radius);
      background: rgba(255, 255, 255, 0.94);
      color: var(--clms-maroon);
      border: 1px solid rgba(128, 0, 0, 0.15);
      font-size: .72rem;
      font-weight: 700;
      padding: .25rem .45rem;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .landing-catalog-cert {
      position: absolute;
      top: .6rem;
      right: .6rem;
      border-radius: var(--clms-radius);
      background: rgba(107, 12, 32, 0.92);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.25);
      font-size: .72rem;
      font-weight: 600;
      padding: .25rem .45rem;
    }

    .landing-catalog-body {
      padding: 1rem;
      display: flex;
      flex-direction: column;
      gap: .75rem;
      flex: 1;
    }

    .landing-catalog-desc {
      color: #4d5a78;
      font-size: .9rem;
      margin: 0;
      min-height: 44px;
    }

    .landing-catalog-meta {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }

    .landing-meta-chip {
      border: 1px solid rgba(128, 0, 0, 0.12);
      background: rgba(128, 0, 0, 0.05);
      color: #8b4513;
      border-radius: var(--clms-radius);
      font-size: .75rem;
      font-weight: 600;
      padding: .22rem .45rem;
    }

    .landing-modal-chip {
      display: inline-flex;
      align-items: center;
      border-radius: var(--clms-radius);
      padding: .35rem .55rem;
      font-size: .78rem;
      font-weight: 700;
      border: 1px solid transparent;
      letter-spacing: .01em;
    }

    .landing-modal-chip-info {
      background: #fdeef1;
      color: var(--clms-maroon);
      border-color: #f5c4cd;
    }

    .landing-modal-chip-meta {
      background: #eef2f7;
      color: #8b4513;
      border-color: #d6deea;
    }

    .landing-modal-chip-exam {
      background: #fff3cd;
      color: #7a5a00;
      border-color: #ffdf9a;
    }

    .feature-icon {
      font-size: 1.5rem;
      color: var(--clms-maroon);
      line-height: 1;
      transition: var(--clms-transition);
    }

    .feature-item:hover .feature-icon {
      transform: scale(1.1);
      color: var(--clms-maroon-dark);
    }

    .cert-section {
      background: linear-gradient(180deg, #f7f4e5 0%, var(--clms-cream) 100%);
      border-top: 1px solid rgba(128, 0, 0, 0.08);
      border-bottom: 1px solid rgba(128, 0, 0, 0.08);
    }

    .cert-preview {
      border-radius: var(--clms-radius);
      background: #fff;
      border: 1px solid rgba(128, 0, 0, 0.15);
      box-shadow: var(--clms-shadow-hover);
      padding: 1.2rem;
      transition: var(--clms-transition);
    }

    .cert-preview:hover {
      transform: scale(1.02);
    }

    .cert-paper {
      border-radius: var(--clms-radius);
      border: 2px solid rgba(128, 0, 0, 0.17);
      background: linear-gradient(180deg, #fffef7 0%, #fff 100%);
      padding: .75rem;
      position: relative;
    }

    .cert-paper::before {
      content: "";
      position: absolute;
      top: .65rem;
      right: .65rem;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      border: 3px solid #d4af37;
      box-shadow: 0 4px 6px -1px rgba(128, 0, 0, 0.10), 0 2px 4px -1px rgba(128, 0, 0, 0.06);
      background: #f8edd0;
    }

    .cert-title {
      color: var(--clms-maroon);
      font-weight: 800;
      margin-bottom: .25rem;
    }

    .cert-subline {
      color: #8b4513;
      font-size: .92rem;
      margin-bottom: .4rem;
    }

    .cert-template-img {
      width: 100%;
      height: auto;
      display: block;
      border-radius: calc(var(--clms-radius) - 1px);
      border: 1px solid rgba(128, 0, 0, 0.12);
      box-shadow: var(--clms-shadow);
      background: #fff;
      transition: var(--clms-transition);
    }

    .cert-preview:hover .cert-template-img {
      transform: scale(1.02);
    }

    a,
    .nav-link {
      transition: var(--clms-transition);
    }

    .site-footer {
      background-color: var(--clms-maroon);
      color: rgba(255, 255, 255, 0.9);
    }

    .site-footer a {
      color: #fff;
      text-decoration: none;
      transition: var(--clms-transition);
    }

    .site-footer a:hover {
      color: #ffc9d4;
    }

    .cta-strip {
      background: linear-gradient(135deg, var(--clms-maroon-dark) 0%, var(--clms-maroon) 45%, var(--clms-maroon-light) 100%);
      color: #fff;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      border-bottom: 1px solid rgba(128, 0, 0, 0.18);
    }

    .cta-strip-title {
      font-weight: 700;
      letter-spacing: -0.01em;
    }

    .cta-strip-lead {
      color: rgba(255, 255, 255, 0.85);
      margin-bottom: 0;
    }

    .btn-cta-light {
      background-color: #fff;
      border-color: #fff;
      color: var(--clms-maroon);
    }

    .btn-cta-light:hover {
      background-color: #fff5f6;
      border-color: #fff5f6;
      color: var(--clms-maroon-dark);
    }

    @media (max-width: 767.98px) {
      .site-navbar {
        padding-top: .6rem !important;
        padding-bottom: .6rem !important;
      }

      .brand-logo {
        font-size: 1.2rem !important;
      }

      .hero-section {
        padding: 4rem 0 3.25rem;
      }

      .hero-title {
        font-size: 2rem;
      }

      .lead {
        font-size: 1rem;
      }

      .hero-kpis {
        grid-template-columns: 1fr;
      }

      .course-card,
      .feature-item,
      .cert-preview {
        padding: 1rem !important;
      }

      .course-description,
      .cert-subline {
        font-size: .95rem;
      }

      .section-title.h3 {
        font-size: 1.45rem;
      }
    }

    @media (max-width: 575.98px) {
      .btn-lg {
        padding: .7rem 1rem;
        font-size: 1rem;
      }

      .cta-strip .btn {
        width: 100%;
      }
    }

    .mobile-register-cta {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1035;
      padding: .65rem .75rem max(.65rem, env(safe-area-inset-bottom));
      background: rgba(253, 252, 240, 0.98);
      border-top: 1px solid rgba(128, 0, 0, 0.12);
      backdrop-filter: blur(4px);
    }

    .mobile-register-cta .btn {
      width: 100%;
    }

    @media (min-width: 768px) {
      .mobile-register-cta {
        display: none;
      }
    }

    @media (max-width: 767.98px) {
      .site-footer {
        padding-bottom: 4.6rem !important;
      }
    }

    /* Additional animations and improvements */
    .fade-in {
      animation: fadeIn 0.8s ease-in-out;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .hero-section,
    .section-title,
    .course-card,
    .feature-item,
    .cert-preview {
      animation: fadeIn 1s ease-in-out;
    }

    .hero-section {
      animation-delay: 0.2s;
    }

    .section-title {
      animation-delay: 0.4s;
    }

    .course-card,
    .feature-item,
    .cert-preview {
      animation-delay: 0.6s;
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg sticky-top site-navbar py-3">
    <div class="container">
      <a class="brand-logo fs-4" href="<?php echo htmlspecialchars($clmsWebBase ?: '/', ENT_QUOTES, 'UTF-8'); ?>/">
        <img class="brand-logo-icon" src="<?php echo htmlspecialchars(($clmsWebBase ?: '') . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>" alt="CLMS Logo">
        <span><?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?></span>
      </a>

      <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#landingNavbar" aria-controls="landingNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <i class="bx bx-menu navicon" aria-hidden="true"></i>
      </button>

      <div class="collapse navbar-collapse" id="landingNavbar">
        <div class="ms-auto d-flex flex-column flex-lg-row gap-2 mt-3 mt-lg-0">
          <?php if ($isLoggedIn) : ?>
            <a class="btn btn-clms btn-clms-primary px-4" href="<?php echo htmlspecialchars($dashboardTarget, ENT_QUOTES, 'UTF-8'); ?>">Go to Dashboard</a>
          <?php else : ?>
            <a class="btn btn-clms btn-clms-outline px-4" href="login.php">Login</a>
            <a class="btn btn-clms btn-clms-primary px-4" href="register.php">Register</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </nav>

  <?php if ($carouselSlides !== []) : ?>
    <div id="clmsLandingHeroCarousel" class="carousel slide clms-hero-carousel" data-bs-ride="carousel" data-bs-interval="8000" data-bs-pause="hover">
      <div class="carousel-indicators">
        <?php foreach ($carouselSlides as $ci => $_slide) : ?>
          <button type="button" data-bs-target="#clmsLandingHeroCarousel" data-bs-slide-to="<?php echo (int) $ci; ?>"<?php echo $ci === 0 ? ' class="active" aria-current="true"' : ''; ?> aria-label="Slide <?php echo (int) ($ci + 1); ?>"></button>
        <?php endforeach; ?>
      </div>
      <div class="carousel-inner">
        <?php foreach ($carouselSlides as $ci => $slide) : ?>
          <?php
          $slideBg = $resolveThumbnailUrl((string) ($slide['image_url'] ?? ''));
          $btnLabelRaw = trim((string) ($slide['button_label'] ?? ''));
          $btnLabel = $btnLabelRaw !== ''
            ? $btnLabelRaw
            : ('Start Your ' . ($clmsThemeSettings['site_title'] ?? 'CLMS') . ' Account');
          $btnHref = $resolveLandingHref((string) ($slide['button_url'] ?? ''));
          $kpis = [];
          foreach (
            [
              [trim((string) ($slide['kpi1_value'] ?? '')), trim((string) ($slide['kpi1_label'] ?? ''))],
              [trim((string) ($slide['kpi2_value'] ?? '')), trim((string) ($slide['kpi2_label'] ?? ''))],
              [trim((string) ($slide['kpi3_value'] ?? '')), trim((string) ($slide['kpi3_label'] ?? ''))],
            ] as $pair
          ) {
            if ($pair[0] !== '' || $pair[1] !== '') {
              $kpis[] = $pair;
            }
          }
          ?>
          <?php
          $slideTitle = trim((string) ($slide['title'] ?? ''));
          $slideSubtitle = trim((string) ($slide['subtitle'] ?? ''));
          $slideHasButton = $btnLabelRaw !== '' || trim((string) ($slide['button_url'] ?? '')) !== '';
          $slideHasText = $slideTitle !== '' || $slideSubtitle !== '' || $slideHasButton || $kpis !== [];
          ?>
          <div class="carousel-item<?php echo $ci === 0 ? ' active' : ''; ?>">
            <header class="hero-section hero-slide-frame<?php echo $slideHasText ? '' : ' hero-slide-photo-only'; ?>">
              <?php if ($slideBg !== '') : ?>
                <div class="hero-slide-bg<?php echo $slideHasText ? '' : ' hero-slide-bg-contain'; ?>" style="background-image:url('<?php echo htmlspecialchars($slideBg, ENT_QUOTES, 'UTF-8'); ?>');"></div>
                <?php if ($slideHasText) : ?>
                  <div class="hero-slide-scrim" aria-hidden="true"></div>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($slideHasText) : ?>
              <div class="container hero-slide-content">
                <div class="row align-items-center g-4">
                  <div class="col-lg-7 col-xl-6">
                    <?php if ($slideTitle !== '') : ?>
                      <h1 class="display-4 hero-title mb-3"><?php echo htmlspecialchars($slideTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php endif; ?>
                    <?php if ($slideSubtitle !== '') : ?>
                      <p class="lead mb-4" style="white-space: pre-wrap;"><?php echo htmlspecialchars($slideSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($slideHasButton) : ?>
                      <a href="<?php echo htmlspecialchars($btnHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-clms btn-clms-primary btn-lg px-4"><?php echo htmlspecialchars($btnLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                    <?php if ($kpis !== []) : ?>
                      <div class="hero-kpis">
                        <?php foreach ($kpis as $kpi) : ?>
                          <div class="hero-kpi">
                            <div class="hero-kpi-value"><?php echo htmlspecialchars($kpi[0] !== '' ? $kpi[0] : '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hero-kpi-label"><?php echo htmlspecialchars($kpi[1] !== '' ? $kpi[1] : ' ', ENT_QUOTES, 'UTF-8'); ?></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </header>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#clmsLandingHeroCarousel" data-bs-slide="prev">
        <span class="visually-hidden">Previous slide</span>
        <i class="bx bx-chevron-left" aria-hidden="true"></i>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#clmsLandingHeroCarousel" data-bs-slide="next">
        <span class="visually-hidden">Next slide</span>
        <i class="bx bx-chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  <?php else : ?>
  <header class="hero-section">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-8">
          <h1 class="display-4 hero-title mb-3">Master the Criminology Board Exams</h1>
          <p class="lead mb-4">Train with a strict, self-paced learning system built for discipline: complete each module in sequence, pass high-standard assessments, and earn verified certification only when requirements are fully met.</p>
          <a href="register.php" class="btn btn-clms btn-clms-primary btn-lg px-4">Start Your <?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?> Account</a>
          <div class="hero-kpis">
            <div class="hero-kpi">
              <div class="hero-kpi-value">Self-Paced</div>
              <div class="hero-kpi-label">Flexible Timeline</div>
            </div>
            <div class="hero-kpi">
              <div class="hero-kpi-value">Strict Grading</div>
              <div class="hero-kpi-label">Mastery Required</div>
            </div>
            <div class="hero-kpi">
              <div class="hero-kpi-value">Verified Cert</div>
              <div class="hero-kpi-label">Completion Proof</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
  <?php endif; ?>

  <main>
    <section class="py-5">
      <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
          <h2 class="section-title h3 mb-0">Featured Courses</h2>
          <span class="text-secondary small">Published and ready for enrollment requests</span>
        </div>

        <div class="row g-4">
          <?php if ($courses !== []) : ?>
            <?php foreach ($courses as $course) : ?>
              <?php
              $thumb = $resolveThumbnailUrl((string) ($course['thumbnail_url'] ?? ''));
              $title = (string) ($course['title'] ?? 'Course');
              $level = trim((string) ($course['level'] ?? ''));
              $description = trim((string) ($course['description'] ?? ''));
              $descText = $description === ''
                ? 'Course overview will be provided upon enrollment request approval.'
                : mb_strimwidth($description, 0, 120, '...');
              $levelText = $level !== '' ? $level : 'All Levels';
              $initials = mb_strtoupper(mb_substr($title, 0, 2));
              $courseId = (int) ($course['id'] ?? 0);
              $placeholderGradient = $coursePlaceholderGradients[$courseId % count($coursePlaceholderGradients)];
              $modalId = 'landingCourseInfoModal-' . (int) ($course['id'] ?? 0);
              $totalModules = (int) ($course['total_modules'] ?? 0);
              $learnerCount = (int) ($course['learner_count'] ?? 0);
              $questionCount = (int) ($course['question_count'] ?? 0);
              $durationDisplay = $formatDuration((int) ($course['total_duration_minutes'] ?? 0));
              ?>
              <div class="col-sm-6 col-lg-4 col-xl-3">
                <article class="landing-catalog-card">
                  <div class="landing-catalog-media" style="<?php echo $thumb === '' ? 'background: ' . htmlspecialchars($placeholderGradient, ENT_QUOTES, 'UTF-8') . ';' : ''; ?>">
                    <?php if ($thumb !== '') : ?>
                      <img src="<?php echo htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> thumbnail" loading="lazy" onerror="this.style.display='none'; this.parentElement.querySelector('.landing-catalog-placeholder').style.display='block';">
                    <?php endif; ?>
                    <div class="landing-catalog-placeholder" style="<?php echo $thumb !== '' ? 'display:none;' : ''; ?>">
                      <?php echo htmlspecialchars($initials !== '' ? $initials : 'CL', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <span class="landing-catalog-level"><?php echo htmlspecialchars($levelText, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="landing-catalog-cert"><i class="bx bx-award me-1"></i>Certificate</span>
                  </div>
                  <div class="landing-catalog-body">
                    <h3 class="course-title h6 mb-0"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="landing-catalog-desc"><?php echo htmlspecialchars($descText, ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="landing-catalog-meta">
                      <span class="landing-meta-chip"><i class="bx bx-lock-alt me-1"></i>Request Access</span>
                      <span class="landing-meta-chip"><i class="bx bx-check-shield me-1"></i>Verified Track</span>
                    </div>
                    <div class="d-flex gap-2 mt-auto">
                      <button type="button" class="btn btn-clms btn-clms-outline flex-fill" data-bs-toggle="modal" data-bs-target="#<?php echo htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>">
                        More Info
                      </button>
                      <a class="btn btn-clms btn-clms-primary flex-fill" href="register.php">
                        <i class="bx bx-rocket me-1"></i>Start Learning
                      </a>
                    </div>
                  </div>
                </article>
              </div>
              <div class="modal fade" id="<?php echo htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="landing-modal-chip landing-modal-chip-info"><?php echo htmlspecialchars($levelText, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="landing-modal-chip landing-modal-chip-meta"><i class="bx bx-book-open me-1"></i><?php echo $totalModules; ?> modules</span>
                        <span class="landing-modal-chip landing-modal-chip-meta"><i class="bx bx-time-five me-1"></i><?php echo htmlspecialchars($durationDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="landing-modal-chip landing-modal-chip-meta"><i class="bx bx-group me-1"></i><?php echo number_format($learnerCount); ?> learners</span>
                        <?php if ($questionCount > 0) : ?>
                          <span class="landing-modal-chip landing-modal-chip-exam"><i class="bx bx-certification me-1"></i>Final Exam</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($description !== '') : ?>
                        <p class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
                      <?php else : ?>
                        <p class="mb-0 text-muted">No detailed course description is available yet.</p>
                      <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                      <a class="btn btn-clms btn-clms-primary" href="register.php">
                        <i class="bx bx-rocket me-1"></i>Start Learning
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else : ?>
            <div class="col-12">
              <div class="course-card p-4">
                <p class="mb-0 text-secondary">No published courses are available right now. Please check back soon or register to receive updates from the Review Center administrator.</p>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="py-5 cert-section">
      <div class="container">
        <div class="row g-4 align-items-center">
          <div class="col-lg-6">
            <h2 class="section-title h3 mb-3">Certification You Can Earn</h2>
            <p class="mb-3 text-secondary">After completing all required modules and passing final assessments, learners receive a verified <?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?> certificate issued by the review center with course-level traceability.</p>
            <ul class="list-unstyled mb-0">
              <li class="mb-2"><i class="bx bx-check-circle me-2 text-primary" aria-hidden="true"></i>Issued only after full requirement completion</li>
              <li class="mb-2"><i class="bx bx-check-circle me-2 text-primary" aria-hidden="true"></i>Tied to course and learner identity records</li>
              <li><i class="bx bx-check-circle me-2 text-primary" aria-hidden="true"></i>Verifiable output for academic and review documentation</li>
            </ul>
          </div>
          <div class="col-lg-6">
            <div class="cert-preview">
              <div class="cert-paper">
                <img
                  class="cert-template-img"
                  src="<?php echo htmlspecialchars(($clmsWebBase ?: '') . '/public/assets/images/blank_cert_template.jpg', ENT_QUOTES, 'UTF-8'); ?>"
                  alt="Sample <?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?> certificate template"
                  loading="lazy" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="py-5">
      <div class="container">
        <h2 class="section-title h3 text-center mb-4">How <?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'CLMS', ENT_QUOTES, 'UTF-8'); ?> Works</h2>
        <div class="row g-4">
          <div class="col-md-4">
            <div class="feature-item p-4">
              <i class="bx bx-video feature-icon mb-3 d-inline-block" aria-hidden="true"></i>
              <h3 class="h5 section-title mb-2">Self-Paced Video Modules</h3>
              <p class="mb-0 text-secondary">Study anytime with structured lesson sequences designed for focused criminology board preparation.</p>
            </div>
          </div>
          <div class="col-md-4">
            <div class="feature-item p-4">
              <i class="bx bx-check-shield feature-icon mb-3 d-inline-block" aria-hidden="true"></i>
              <h3 class="h5 section-title mb-2">Strict All-or-Nothing Assessments</h3>
              <p class="mb-0 text-secondary">Progress only after meeting required passing scores, reinforcing mastery before advancing.</p>
            </div>
          </div>
          <div class="col-md-4">
            <div class="feature-item p-4">
              <i class="bx bx-certification feature-icon mb-3 d-inline-block" aria-hidden="true"></i>
              <h3 class="h5 section-title mb-2">Verified Certification</h3>
              <p class="mb-0 text-secondary">Earn certificates tied to validated completion records for credible proof of achievement.</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer py-4 mt-5">
    <div class="container text-center">
      <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($clmsThemeSettings['site_title'] ?? 'Criminology Learning Management System', ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.</p>
    </div>
  </footer>

  <?php if (!$isLoggedIn) : ?>
    <div class="mobile-register-cta d-md-none">
      <a href="register.php" class="btn btn-clms btn-clms-primary">Register and Request Course Access</a>
    </div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>

</html>
