<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$role = strtolower(trim($_SESSION['user']['role'] ?? ''));
$name = $_SESSION['user']['name'] ?? ($_SESSION['user']['dname'] ?? ($_SESSION['user']['cname'] ?? ''));
// compute base URL pointing to application root (robust across include contexts)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
// Determine project root relative to document root using this file's location
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$appRoot = '/' . trim(str_replace($docRoot, '', $projectRoot), '/');
if ($appRoot === '/') $appRoot = '';
$baseUrl = $scheme . '://' . $host . $appRoot;
?>
<header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
  <div class="d-flex align-items-center gap-3">
    <div class="h4 mb-0 user-select-none">HappyDesign</div>
    <nav>
      <ul class="nav align-items-center gap-2">
        <?php if ($role === 'designer'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/designer_dashboard.php">Dashboard</a></li>
          <?php if (file_exists(__DIR__ . '/../designer/schedule.php')): ?>
            <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/schedule.php">Schedule</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/DesignManager.php">Designs</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/OrderManager.php">Orders</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownDesigner" role="button" data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownDesigner">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php elseif ($role === 'supplier'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/supplier/dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/supplier/product-detail.php">Products</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownSupplier" role="button" data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownSupplier">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php elseif ($role === 'manager'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Manager_MyOrder.php">Orders</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Manager_Schedule.php">Schedule</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdownManager" role="button" data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdownManager">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="browseDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Browse</a>
            <ul class="dropdown-menu" aria-labelledby="browseDropdown">
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
              <li><a class="dropdown-item" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
  <nav>
    <ul class="nav align-items-center">
      <?php if (!empty($name) || isset($clientData)): ?>
        <li class="nav-item me-2">
          <a class="nav-link text-muted" href="<?= $baseUrl ?>/client/profile.php">
            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($name ?: ($clientData['cname'] ?? 'User')) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if (!empty($_SESSION['user'])): ?>
        <?php if ($role === 'client'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/my_likes.php">My Likes</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/client/order_history.php">Order History</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/logout.php">Logout</a></li>
      <?php else: ?>
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/login.php">Login</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</header>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enable dropdown on hover for non-touch devices (only for top nav .nav-item.dropdown)
(function(){
  if ('ontouchstart' in window) return; // skip touch devices
  const opens = new WeakMap();
  const closes = new WeakMap();
  const OPEN_DELAY = 120; // ms
  const CLOSE_DELAY = 220; // ms
  document.querySelectorAll('.nav .nav-item.dropdown').forEach(function(drop){
    const toggle = drop.querySelector('.dropdown-toggle');
    if (!toggle) return;
    let bs = null;
    drop.addEventListener('mouseenter', function(){
      clearTimeout(closes.get(drop));
      opens.set(drop, setTimeout(()=>{
        bs = bootstrap.Dropdown.getOrCreateInstance(toggle);
        bs.show();
        toggle.setAttribute('aria-expanded','true');
      }, OPEN_DELAY));
    });
    drop.addEventListener('mouseleave', function(){
      clearTimeout(opens.get(drop));
      closes.set(drop, setTimeout(()=>{
        if (!bs) bs = bootstrap.Dropdown.getOrCreateInstance(toggle);
        bs.hide();
        toggle.setAttribute('aria-expanded','false');
      }, CLOSE_DELAY));
    });
    // also close when focus leaves (keyboard navigation)
    toggle.addEventListener('blur', function(){
      if (!bs) bs = bootstrap.Dropdown.getOrCreateInstance(toggle);
      bs.hide();
      toggle.setAttribute('aria-expanded','false');
    });
  });
})();
</script>
