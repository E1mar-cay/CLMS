<?php

declare(strict_types=1);

require_once __DIR__ . '/sneat-paths.php';

$extraScripts = $extraScripts ?? '';

?>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/jquery/jquery.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/popper/popper.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/js/bootstrap.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/js/menu.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/js/main.js"></script>
    <script>
      /* Sneat Free ships the CSS for `.layout-menu-collapsed`, but its
         `Helpers.setCollapsed()` only has a mobile branch — on desktop it
         silently no-ops (that flip is a Pro-only feature). We add the
         desktop branch here, then persist the state across page loads.

         - DESKTOP_BP matches Sneat's own LAYOUT_BREAKPOINT (1200) so our
           definition of "large screen" agrees with Sneat's.
         - On small screens we do nothing; Sneat's own handler already
           toggles `layout-menu-expanded` for the offcanvas slide-in. */
      (function () {
        var DESKTOP_BP = 1200;
        var html = document.documentElement;
        var STORAGE_KEY = 'clms-menu-collapsed';

        var isDesktopToggleClick = function (target) {
          if (!target) return false;
          return !!target.closest(
            '.app-brand .layout-menu-toggle, .layout-navbar .layout-menu-toggle a[aria-label="Toggle sidebar"]'
          );
        };

        document.addEventListener('click', function (event) {
          if (!isDesktopToggleClick(event.target)) return;

          if (window.innerWidth < DESKTOP_BP) {
            // Let Sneat handle mobile offcanvas behavior, but clear stale
            // desktop collapse class if it leaked in from persistence.
            if (html.classList.contains('layout-menu-collapsed')) {
              html.classList.remove('layout-menu-collapsed');
            }
            return;
          }

          // On desktop, fully own the collapse toggle to avoid collisions
          // with Sneat's non-persistent free-template behavior.
          event.preventDefault();
          event.stopPropagation();
          html.classList.toggle('layout-menu-collapsed');
          html.classList.remove('layout-menu-expanded');
          try {
            localStorage.setItem(
              STORAGE_KEY,
              html.classList.contains('layout-menu-collapsed') ? '1' : '0'
            );
          } catch (e) { /* storage disabled */ }
        }, true);

        // If viewport shrinks below desktop while collapsed, auto-clear the
        // desktop-only class so mobile/tablet menu behavior stays usable.
        window.addEventListener('resize', function () {
          if (window.innerWidth < DESKTOP_BP && html.classList.contains('layout-menu-collapsed')) {
            html.classList.remove('layout-menu-collapsed');
          }
        });

        try {
          new MutationObserver(function () {
            try {
              localStorage.setItem(
                STORAGE_KEY,
                html.classList.contains('layout-menu-collapsed') ? '1' : '0'
              );
            } catch (e) { /* storage disabled: silently skip persistence */ }
          }).observe(html, { attributes: true, attributeFilter: ['class'] });
        } catch (e) { /* very old browser without MutationObserver: fine */ }
      })();
    </script>
    <?php echo $extraScripts; ?>
  </body>
</html>
