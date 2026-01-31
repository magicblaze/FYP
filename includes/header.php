<?php
if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();
$role = strtolower(trim($_SESSION['user']['role'] ?? ''));
$name = $_SESSION['user']['name'] ?? ($_SESSION['user']['dname'] ?? ($_SESSION['user']['cname'] ?? ''));
// compute base URL pointing to application root (robust across include contexts)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
// Determine project root relative to document root using this file's location
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$appRoot = '/' . trim(str_replace($docRoot, '', $projectRoot), '/');
if ($appRoot === '/')
  $appRoot = '';
$baseUrl = $scheme . '://' . $host . $appRoot;
?>
<!-- Bootstrap JS included above; no custom dropdown navigation required when using anchors -->
<script>
  (function () {
    // Open dropdowns on hover for non-touch devices with a short delay.
    if (typeof window === 'undefined') return;
    if ('ontouchstart' in window) return; // skip touch devices
    document.addEventListener('DOMContentLoaded', function () {
      var dropdowns = document.querySelectorAll('header .nav-item.dropdown');
      dropdowns.forEach(function (drop) {
        var toggle = drop.querySelector('.dropdown-toggle');
        var menu = drop.querySelector('.dropdown-menu');
        if (!toggle || !menu) return;
        var openTimer = null, closeTimer = null;
        drop.addEventListener('mouseenter', function () {
          clearTimeout(closeTimer);
          openTimer = setTimeout(function () {
            drop.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
            menu.classList.add('show');
          }, 150);
        });
        drop.addEventListener('mouseleave', function () {
          clearTimeout(openTimer);
          closeTimer = setTimeout(function () {
            drop.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
            menu.classList.remove('show');
          }, 200);
        });
      });
    });
  })();
</script>
<header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
  <div class="d-flex align-items-center gap-3">
    <div class="h4 mb-0 user-select-none">HappyDesign</div>
    <nav>
      <ul class="nav align-items-center gap-2">
        <?php if ($role === 'designer'): ?>
          <li class=""><a class="nav-link" href="<?= $baseUrl ?>/designer/designer_dashboard.php">Dashboard</a></li>
          <?php if (file_exists(__DIR__ . '/../designer/schedule.php')): ?>
            <li class=""><a class="nav-link" href="<?= $baseUrl ?>/designer/schedule.php">Schedule</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/DesignManager.php">Designs</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/OrderManager.php">Orders</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownDesigner" role="button"
              data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownDesigner">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php elseif ($role === 'supplier'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/supplier/dashboard.php">Dashboard</a></li>
          <li class=""><a class="nav-link" href="<?= $baseUrl ?>/supplier/schedule.php">Schedule</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownSupplier" role="button"
              data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownSupplier">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php elseif ($role === 'manager'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Manager_Dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Order_Management.php">Orders</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Manager_Schedule.php">Schedule</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownManager" role="button"
              data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownManager">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
  <nav>
    <ul class="nav align-items-center">
      <?php if (!empty($name) || isset($clientData)): ?>
        <li class=" me-2">
          <a class="nav-link text-muted" href="<?= $baseUrl ?>/includes/profile.php">
            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($name ?: ($clientData['cname'] ?? 'User')) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if (!empty($_SESSION['user'])): ?>
        <li class=""><a class="nav-link" href="<?= $baseUrl ?>/my_likes.php">Liked</a></li>
        <?php if ($role === 'client'): ?>
          <li class=""><a class="nav-link" href="<?= $baseUrl ?>/client/order_history.php">Order History</a></li>
        <?php endif; ?>
        <li class=""><a class="nav-link" href="<?= $baseUrl ?>/logout.php">Logout</a></li>
      <?php else: ?>
        <li class=""><a class="nav-link" href="<?= $baseUrl ?>/login.php">Login</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</header>