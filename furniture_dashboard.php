<?php
// ==============================
// File: furniture_dashboard.php
// 用途：顯示供應商的傢俱 (Furniture) 列表
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// --- 1. 處理搜尋邏輯 ---
$search = trim($_GET['search'] ?? '');

$sql = "SELECT p.*, s.sname FROM Product p 
        JOIN Supplier s ON p.supplierid = s.supplierid 
        WHERE p.category = 'Furniture'";
$params = [];
$types = "";

// 關鍵字搜尋
if (!empty($search)) {
    $sql .= " AND (p.pname LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$sql .= " ORDER BY p.productid ASC";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furniture - HappyDesign</title>
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
                    <li class="nav-item"><a class="nav-link" href="design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link active" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?>
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
            <form method="GET" aria-label="Search">
                <input type="text" name="search" class="form-control form-control-lg" placeholder="Search furniture..." value="<?= htmlspecialchars($search) ?>">
            </form>
        </div>
        <div class="page-title">Furniture</div>
        <!-- Results Grid -->
        <div class="row g-4">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($prod = $result->fetch_assoc()): ?>
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <a href="client/product_detail.php?id=<?= htmlspecialchars($prod['productid']) ?>" style="text-decoration: none;">
                        <div class="card h-100">
                            <img src="supplier/product_image.php?id=<?= (int)$prod['productid'] ?>" class="card-img-top" alt="<?= htmlspecialchars($prod['pname']) ?>">
                            <div class="card-body text-center">
                                <h5 class="card-title"><?= htmlspecialchars($prod['pname']) ?></h5>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-store me-1"></i><?= htmlspecialchars($prod['sname']) ?>
                                </p>
                                <p class="h6 mb-0" style="color: #e74c3c; font-weight: 700;">HK$<?= number_format($prod['price']) ?></p>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5" style="color: #7f8c8d;">
                        <i class="fas fa-couch" style="font-size: 4rem; margin-bottom: 1rem; color: #bdc3c7;"></i>
                        <h3>No Furniture Found</h3>
                        <p>Try adjusting your search to find what you're looking for.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
