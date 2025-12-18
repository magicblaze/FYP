<?php
// ==============================
// File: supplier/products.php
// ==============================
// 修正 config 路徑
require_once __DIR__ . '/../config.php';
session_start();

// 處理搜尋與篩選
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? 'all');

$sql = "SELECT p.*, s.sname FROM Product p 
        JOIN Supplier s ON p.supplierid = s.supplierid 
        WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (p.pname LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($category !== 'all') {
    $sql .= " AND p.category = ?";
    $params[] = $category;
    $types .= "s";
}

$sql .= " ORDER BY p.productid DESC";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$catSql = "SELECT DISTINCT category FROM Product";
$catRes = $mysqli->query($catSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HappyDesign - Products</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- 修正 CSS 路徑: 指向上一層的 css/styles.css -->
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        /* 這裡保留原本為產品頁面設計的樣式 */
        .products-layout { display: flex; gap: 20px; margin-top: 20px; }
        .sidebar { width: 250px; flex-shrink: 0; background: #fff; padding: 20px; border-radius: 10px; height: fit-content; }
        .products-main { flex: 1; }
        .product-card {
            background: #fff; border-radius: 10px; padding: 15px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s;
            height: 100%; display: flex; flex-direction: column;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .product-image { height: 180px; width: 100%; object-fit: cover; border-radius: 8px; margin-bottom: 10px; }
        .product-name { font-size: 1.1rem; font-weight: 600; margin-bottom: 5px; color: #2c3e50; }
        .product-price { color: #e74c3c; font-weight: 700; margin-bottom: 10px; }
        .btn-buy { background-color: #3498db; color: white; border: none; padding: 8px 20px; border-radius: 20px; width: 100%; margin-top: auto; }
        .btn-buy:hover { background-color: #2980b9; color: white; }
    </style>
</head>
<body>
    <!-- Header: 注意連結都需要加上 ../ 回到根目錄 -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center sticky-top">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <!-- 當前頁面是 products.php，自己連自己不用 ../，但通常保持一致性或留空 -->
                    <li class="nav-item"><a class="nav-link active" href="products.php">Products</a></li> 
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <!-- 修正 Profile 連結: ../client/profile.php -->
                        <a class="nav-link text-muted" href="../client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <div class="container mb-5">
        <!-- 搜尋與篩選區塊 -->
        <div class="bg-white p-3 rounded mt-4 shadow-sm">
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value="all">All Categories</option>
                        <?php while ($row = $catRes->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($row['category']) ?>" <?= $category === $row['category'] ? 'selected' : '' ?>>
                                <?= ucfirst(htmlspecialchars($row['category'])) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>

        <div class="products-layout">
            <aside class="sidebar d-none d-lg-block">
                <h5 class="mb-3">Categories</h5>
                <div class="list-group">
                    <a href="products.php" class="list-group-item list-group-item-action <?= $category === 'all' ? 'active' : '' ?>">All</a>
                    <?php $catRes->data_seek(0); ?>
                    <?php while ($row = $catRes->fetch_assoc()): ?>
                        <a href="products.php?category=<?= urlencode($row['category']) ?>" class="list-group-item list-group-item-action <?= $category === $row['category'] ? 'active' : '' ?>">
                            <?= ucfirst(htmlspecialchars($row['category'])) ?>
                        </a>
                    <?php endwhile; ?>
                </div>
            </aside>

            <main class="products-main">
                <div class="row g-3">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($prod = $result->fetch_assoc()): ?>
                        <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12">
                            <div class="product-card">
                                <!-- 連結到同一資料夾內的 product-detail.php -->
                                <a href="product-detail.php?id=<?= $prod['productid'] ?>" style="text-decoration: none; color: inherit;">
                                    <!-- 圖片讀取同一資料夾內的 product_image.php -->
                                    <img src="product_image.php?id=<?= $prod['productid'] ?>" class="product-image" alt="<?= htmlspecialchars($prod['pname']) ?>">
                                    <h3 class="product-name"><?= htmlspecialchars($prod['pname']) ?></h3>
                                    <div class="product-price">
                                        HK$<?= number_format($prod['price']) ?>
                                    </div>
                                    <p class="text-muted small mb-2"><i class="fas fa-store me-1"></i><?= htmlspecialchars($prod['sname']) ?></p>
                                </a>
                                <a href="product-detail.php?id=<?= $prod['productid'] ?>" class="btn btn-buy">View Details</a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12 text-center py-5 text-muted">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <h4>No products found</h4>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <footer class="text-center py-4 text-muted border-top mt-5">
        <p>&copy; 2025 HappyDesign. All rights reserved.</p>
    </footer>
</body>
</html>
