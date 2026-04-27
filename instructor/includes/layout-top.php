<?php

declare(strict_types=1);

if (!isset($pageTitle) || !is_string($pageTitle)) {
    $pageTitle = 'Instructor | CLMS';
}

$activeInstructorPage = $activeInstructorPage ?? 'dashboard';

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme d-flex flex-column">
          <div class="app-brand demo">
            <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="app-brand-link">
              <img
                src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>"
                alt="CLMS"
                class="app-brand-logo clms-brand-logo" />
              <span class="app-brand-text demo menu-text fw-bold ms-2">CLMS Instructor</span>
            </a>
            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
              <i class="bx bx-chevron-left d-block align-middle"></i>
            </a>
          </div>

          <div class="menu-inner-shadow"></div>

          <ul class="menu-inner py-1 flex-grow-1">
            <li class="menu-item <?php echo $activeInstructorPage === 'dashboard' ? 'active' : ''; ?>">
              <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
                <i class="menu-icon tf-icons bx bx-home-smile"></i>
                <div class="text-truncate">Dashboard</div>
              </a>
            </li>
            <li class="menu-item <?php echo $activeInstructorPage === 'manage_content' ? 'active' : ''; ?>">
              <a href="<?php echo htmlspecialchars($clmsWebBase . '/instructor/add_question.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
                <i class="menu-icon tf-icons bx bx-folder-plus"></i>
                <div class="text-truncate">Manage Content</div>
              </a>
            </li>
          </ul>
          <div class="mt-auto border-top">
            <ul class="menu-inner py-1">
              <li class="menu-item">
                <a href="<?php echo htmlspecialchars($clmsWebBase . '/logout.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
                  <i class="menu-icon tf-icons bx bx-log-out"></i>
                  <div class="text-truncate">Logout</div>
                </a>
              </li>
            </ul>
          </div>
        </aside>

        <div class="layout-page">
<?php require dirname(__DIR__, 2) . '/includes/navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
