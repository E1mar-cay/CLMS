<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Criminology LMS';

require_once __DIR__ . '/sneat-paths.php';
require_once __DIR__ . '/theme-settings.php';
require_once __DIR__ . '/../database.php';

$clmsAssetsPath = $clmsSneatBase . '/assets/';
$clmsThemeSettings = clms_get_theme_settings($pdo);
$clmsLogoUrl = clms_get_site_logo_url($clmsThemeSettings, $clmsWebBase);
$pageTitle = $clmsThemeSettings['site_title'] . ' | ' . $pageTitle;

?>
<!doctype html>

<html
  lang="en"
  class="layout-wide customizer-hide"
  dir="ltr"
  data-assets-path="<?php echo htmlspecialchars($clmsAssetsPath, ENT_QUOTES, 'UTF-8'); ?>"
  data-template="vertical-menu-template-free">

<head>
  <meta charset="utf-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

  <meta name="description" content="" />

  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($clmsLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($clmsLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="theme-color" content="#fdfcf0" />

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
    rel="stylesheet" />

  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/fonts/iconify-icons.css" />

  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/css/core.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/css/demo.css" />

  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/css/pages/page-auth.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsWebBase, ENT_QUOTES, 'UTF-8'); ?>/public/assets/css/auth-public.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($clmsWebBase, ENT_QUOTES, 'UTF-8'); ?>/public/assets/css/custom.css" />

  <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/js/helpers.js"></script>
  <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/js/config.js"></script>
  <?php echo clms_render_theme_css($clmsThemeSettings); ?>
</head>

<body>