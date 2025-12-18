<?php
// ==============================
// File: dashboard.php (updated to use design_image.php)
// ==============================
require_once __DIR__ . '/config.php';
session_start();

$sql = "SELECT d.designid, d.price, d.likes, d.tag, dz.dname
        FROM Design d
        JOIN Designer dz ON d.designerid = dz.designerid
        ORDER BY d.designid ASC";
$res = $mysqli->query($sql);
if (!$res) die('Query error: ' . $mysqli->error);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .search-section {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        .search-section .form-control {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
        }
        .search-section .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link active" href="design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="container-lg mt-4">
        <!-- Search Bar -->
        <div class="search-section mb-3">
            <form action="search.php" method="get" aria-label="Search">
                <input type="text" name="tag" class="form-control form-control-lg" placeholder="Search designs...">
            </form>
        </div>
        <div class="page-title">Design</div>
        <!-- Results Grid -->
        <div class="row g-4">
            <?php while ($row = $res->fetch_assoc()): ?>
            <div class="col-lg-4 col-md-6 col-sm-12">
                <a href="client/design_detail.php?designid=<?= htmlspecialchars($row['designid']) ?>" style="text-decoration: none;">
                    <div class="card h-100">
                        <img src="design_image.php?id=<?= (int)$row['designid'] ?>" class="card-img-top" alt="Design by <?= htmlspecialchars($row['dname']) ?>">
                        <div class="card-body text-center">
                            <p class="text-muted mb-2"><?= htmlspecialchars($row['likes']) ?> Likes</p>
                            <p class="h6 mb-0" style="color: #e74c3c; font-weight: 700;">$<?= number_format((float)$row['price'], 0) ?></p>
                        </div>
                    </div>
                </a>
            </div>
            <?php endwhile; ?>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
