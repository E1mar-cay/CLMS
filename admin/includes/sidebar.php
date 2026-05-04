<?php

declare(strict_types=1);

/**
 * Admin sidebar (Sneat vertical menu).
 * Requires $clmsWebBase and $activeAdminPage to be in scope.
 */

$activeAdminPage = $activeAdminPage ?? 'dashboard';
$clmsWebBase = $clmsWebBase ?? '';
$clmsSidebarRole = function_exists('clms_current_role') ? clms_current_role() : null;
?>
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme d-flex flex-column">
  <div class="app-brand demo">
    <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="app-brand-link">
      <img
        src="<?php echo htmlspecialchars($clmsWebBase . '/public/assets/img/logo-clms.png', ENT_QUOTES, 'UTF-8'); ?>"
        alt="CLMS"
        class="app-brand-logo clms-brand-logo" />
      <span class="app-brand-text demo menu-text fw-bold ms-2">CLMS Admin</span>
    </a>
    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
      <i class="bx bx-chevron-left d-block align-middle"></i>
    </a>
  </div>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1 flex-grow-1">
    <li class="menu-header small text-uppercase">
      <span class="menu-header-text">Main</span>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'dashboard' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-home-smile"></i>
        <div class="text-truncate">Dashboard</div>
      </a>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'students' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/students.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-user"></i>
        <div class="text-truncate">Students</div>
      </a>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'student_activity' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/student_activity.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-pulse"></i>
        <div class="text-truncate">Student Activity</div>
      </a>
    </li>

<?php if ($clmsSidebarRole === 'admin') : ?>
    <li class="menu-item <?php echo $activeAdminPage === 'users' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/users.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-group"></i>
        <div class="text-truncate">Users</div>
      </a>
    </li>
<?php endif; ?>

    <li class="menu-item <?php echo $activeAdminPage === 'courses' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-book"></i>
        <div class="text-truncate">Courses</div>
      </a>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'announcements' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/announcements.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-bell"></i>
        <div class="text-truncate">Announcements</div>
      </a>
    </li>

    <li class="menu-header small text-uppercase">
      <span class="menu-header-text">Insights</span>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'reports' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/reports.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-file"></i>
        <div class="text-truncate">Reports</div>
      </a>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'rankings' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/rankings.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-trophy"></i>
        <div class="text-truncate">Course Rankings</div>
      </a>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'data_analytics' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/data_analytics.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-bar-chart-alt-2"></i>
        <div class="text-truncate">Data Analytics</div>
      </a>
    </li>

    <li class="menu-header small text-uppercase">
      <span class="menu-header-text">Support</span>
    </li>

    <li class="menu-item <?php echo $activeAdminPage === 'users_guide' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/users_guide.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-help-circle"></i>
        <div class="text-truncate">User Guide</div>
      </a>
    </li>

<?php if ($clmsSidebarRole === 'admin') : ?>
    <li class="menu-item <?php echo $activeAdminPage === 'audit_log' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/audit_log.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-list-check"></i>
        <div class="text-truncate">Audit trail</div>
      </a>
    </li>
<?php endif; ?>

    <li class="menu-item <?php echo $activeAdminPage === 'settings' ? 'active' : ''; ?>">
      <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/settings.php', ENT_QUOTES, 'UTF-8'); ?>" class="menu-link">
        <i class="menu-icon tf-icons bx bx-cog"></i>
        <div class="text-truncate">Settings</div>
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
