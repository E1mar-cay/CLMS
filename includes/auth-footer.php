<?php

declare(strict_types=1);

require_once __DIR__ . '/sneat-paths.php';

$showUpgradeToPro = $showUpgradeToPro ?? true;

?>
<?php if ($showUpgradeToPro) : ?>
    <div class="buy-now">
      <a
        href="https://themeselection.com/item/sneat-dashboard-pro-bootstrap/"
        target="_blank"
        class="btn btn-danger btn-buy-now"
        >Upgrade to Pro</a
      >
    </div>
<?php endif; ?>

    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/jquery/jquery.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/popper/popper.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/js/bootstrap.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/vendor/js/menu.js"></script>
    <script src="<?php echo htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8'); ?>/assets/js/main.js"></script>
    <script async defer src="https://buttons.github.io/buttons.js"></script>
  </body>
</html>
