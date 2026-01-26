<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$role = strtolower(trim($_SESSION['user']['role'] ?? ''));
$name = $_SESSION['user']['name'] ?? ($_SESSION['user']['dname'] ?? ($_SESSION['user']['cname'] ?? 'User'));

// 计算基础 URL
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$appRoot = '/' . trim(str_replace($docRoot, '', $projectRoot), '/');
if ($appRoot === '/') $appRoot = '';
$baseUrl = $scheme . '://' . $host . $appRoot;

// Profile 页面路径
$profileUrl = $baseUrl . '/includes/profile.php';
?>

<header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
  <div class="d-flex align-items-center gap-3">
    <!-- Logo -->
    <div class="h4 mb-0">
      <a href="<?= htmlspecialchars($baseUrl ?: '/') ?>" style="text-decoration: none; color: inherit; font-weight: bold;">HappyDesign</a>
    </div>

    <!-- 左侧导航链接 -->
    <nav>
      <ul class="nav align-items-center gap-3">
        <?php if ($role === 'designer'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/designer_dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/designer/OrderManager.php">Orders</a></li>
        <?php elseif ($role === 'supplier'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/supplier/dashboard.php">Dashboard</a></li>
        <?php elseif ($role === 'manager'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/Manager/Manager_MyOrder.php">Orders</a></li>
        <?php endif; ?>

        <!-- 直接显示，不使用 Browse 下拉菜单 -->
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/design_dashboard.php">Designs</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/material_dashboard.php">Materials</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/furniture_dashboard.php">Furnitures</a></li>
      </ul>
    </nav>
  </div>

  <!-- 右侧用户信息 -->
  <nav>
    <ul class="nav align-items-center gap-2">
      <?php if (!empty($_SESSION['user'])): ?>
        <li class="nav-item me-2">
          <!-- 移除身份标签 (Identity Group)，只保留 Hello {name} -->
          <a class="nav-link text-muted" href="<?= $profileUrl ?>">
            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($name) ?>
          </a>
        </li>
      <?php endif; ?>
      <?php if (!empty($_SESSION['user'])): ?>
        <?php if ($role === 'client'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/client/order_history.php">Order History</a></li>
        <?php endif; ?>

        <li class="nav-item">
          <a class="nav-link text-danger" href="<?= $baseUrl ?>/logout.php">Logout</a>
        </li>
      <?php else: ?>
        <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/login.php">Login</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</header>