<?php
// ==============================
// File: furniture_dashboard.php (FINAL - Fixed SQL + UI Design)
// 用途:顯示供應商的傢俱 (Furniture) 列表,含進階過濾功能
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// --- 1. 處理過濾邏輯 ---
$search = trim($_GET['search'] ?? '');
$min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (int) $_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (int) $_GET['max_price'] : 999999;
$supplier_id = isset($_GET['supplier_id']) && is_numeric($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : '';
$color = ''; // Color filter disabled
$size = trim($_GET['size'] ?? '');
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'recent';

// 构建 SQL 查询 - 使用 real_escape_string 避免 bind_param 问题
$sql = "SELECT p.*, s.sname FROM Product p 
        JOIN Supplier s ON p.supplierid = s.supplierid 
        WHERE p.category = 'Furniture'";

// 關鍵字搜尋
if (!empty($search)) {
    $search_escaped = $mysqli->real_escape_string($search);
    $sql .= " AND (p.pname LIKE '%$search_escaped%' OR p.description LIKE '%$search_escaped%')";
}

// 價格範圍過濾
if ($min_price > 0 || $max_price < 999999) {
    $sql .= " AND p.price BETWEEN $min_price AND $max_price";
}

// 供應商過濾
if (!empty($supplier_id)) {
    $sql .= " AND p.supplierid = $supplier_id";
}

// 顏色過濾 (已禁用)
// if (!empty($color)) {
//     $color_escaped = $mysqli->real_escape_string($color);
//     $sql .= " AND p.color LIKE '%$color_escaped%'";
// }

// 排序
switch ($sort_by) {
    case 'price_low':
        $sql .= " ORDER BY p.price ASC";
        break;
    case 'price_high':
        $sql .= " ORDER BY p.price DESC";
        break;
    case 'likes':
        $sql .= " ORDER BY p.likes DESC";
        break;
    case 'recent':
    default:
        $sql .= " ORDER BY p.productid DESC";
        break;
}

// 执行查询
$result = $mysqli->query($sql);
if (!$result) {
    die('Query error: ' . $mysqli->error . '<br>SQL: ' . $sql);
}

// 獲取所有供應商用於過濾下拉菜單
$supplier_sql = "SELECT DISTINCT s.supplierid, s.sname FROM Supplier s 
                 JOIN Product p ON s.supplierid = p.supplierid 
                 WHERE p.category = 'Furniture' ORDER BY s.sname ASC";
$supplier_result = $mysqli->query($supplier_sql);
if (!$supplier_result)
    die('Query error: ' . $mysqli->error);

// 獲取每個產品的第一個顏色圖片
$productFirstColorImages = [];
$colorImageSql = "SELECT DISTINCT p.productid, pci.image FROM Product p 
                  LEFT JOIN ProductColorImage pci ON p.productid = pci.productid 
                  WHERE p.category = 'Furniture' 
                  ORDER BY p.productid, pci.id ASC";
$colorImageResult = $mysqli->query($colorImageSql);
if ($colorImageResult) {
    $seenProducts = [];
    while ($row = $colorImageResult->fetch_assoc()) {
        if (!isset($seenProducts[$row['productid']])) {
            $productFirstColorImages[$row['productid']] = $row['image'];
            $seenProducts[$row['productid']] = true;
        }
    }
}

// 獲取所有顏色用於過濾下拉菜單 (已禁用)
$color_result = null; // 设置为 null

// 獲取所有尺寸用於過濾下拉菜單 (已禁用 - size 列不存在)
$size_result = null; // 设置为 null 以避免后续错误
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
            margin-bottom: 0.5rem;
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

        .filter-panel {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .filter-panel h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .filter-group {
            margin-bottom: 1.5rem;
        }

        .filter-group label {
            font-weight: 500;
            color: #34495e;
            margin-bottom: 0.5rem;
            display: block;
        }

        .filter-group .form-control,
        .filter-group .form-select {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
        }

        .filter-group .form-control:focus,
        .filter-group .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .price-inputs {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .price-inputs .form-control {
            flex: 1;
        }

        .price-separator {
            color: #7f8c8d;
            font-weight: 600;
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .filter-buttons button {
            flex: 1;
        }

        .btn-apply-filter {
            background: #3498db;
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-apply-filter:hover {
            background: #2980b9;
            color: white;
        }

        .btn-clear-filter {
            background: #ecf0f1;
            border: none;
            color: #7f8c8d;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none !important;
        }

        .btn-clear-filter:hover {
            background: #bdc3c7;
        }

        .results-info {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }

        .container-with-filter {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .card-img-top {
            height: 250px;
            object-fit: cover;
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .container-with-filter {
                grid-template-columns: 1fr;
            }

            .filter-panel {
                order: 2;
                position: static;
            }

            .main-content {
                order: 1;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Search Bar -->
        <div class="search-section">
            <form method="GET" aria-label="Search">
                <div class="input-group">
                    <input type="text" name="search" class="form-control form-control-lg"
                        placeholder="Search furniture..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <!-- Filter Panel -->
            <h5><i class="fas fa-filter me-2 mt-3"></i>Filters</h5>
            <form method="GET" action="furniture_dashboard.php" id="filterForm">
                <!-- Search (Hidden) -->
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <!-- Color filter disabled - no need to pass color parameter -->

                <div class="row g-3">
                    <!-- Price Range Filter -->
                    <div class="col-md-3">
                        <div class="filter-group">
                            <label>Price Range (HK$)</label>
                            <div class="price-inputs">
                                <input type="number" name="min_price" class="form-control" placeholder="Min" step="1000"
                                    value="<?= $min_price > 0 ? $min_price : '' ?>" min="0">
                                <span class="price-separator">-</span>
                                <input type="number" name="max_price" class="form-control" placeholder="Max" step="1000"
                                    value="<?= $max_price < 999999 ? $max_price : '' ?>" min="0">
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Filter -->
                    <div class="col-md-3">
                        <div class="filter-group">
                            <label for="supplier_id">Supplier</label>
                            <select name="supplier_id" id="supplier_id" class="form-select">
                                <option value="">All Suppliers</option>
                                <?php while ($supplier = $supplier_result->fetch_assoc()): ?>
                                    <option value="<?= $supplier['supplierid'] ?>" <?= $supplier_id == $supplier['supplierid'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($supplier['sname']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Sort By -->
                    <div class="col-md-3">
                        <div class="filter-group">
                            <label for="sort_by">Sort By</label>
                            <select name="sort_by" id="sort_by" class="form-select">
                                <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Newest</option>
                                <option value="price_low" <?= $sort_by === 'price_low' ? 'selected' : '' ?>>Price: Low
                                    to High</option>
                                <option value="price_high" <?= $sort_by === 'price_high' ? 'selected' : '' ?>>Price:
                                    High to Low</option>
                                <option value="likes" <?= $sort_by === 'likes' ? 'selected' : '' ?>>Most Liked</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="col-md-3">
                        <div class="filter-group" style="margin-top: 1.85rem;">
                            <div class="filter-buttons">
                                <button type="submit" class="btn-apply-filter">
                                    <i class="fas fa-check me-1"></i>Apply
                                </button>
                                <a href="furniture_dashboard.php" class="btn-clear-filter" style="text-align: center;">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="container-with-filter">
            <!-- Main Content -->
            <div class="main-content">
                <!-- Results Grid -->
                <div class="row g-4">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($prod = $result->fetch_assoc()): ?>
                            <div class="col-lg-4 col-md-6 col-sm-12">
                                <a href="product_detail.php?id=<?= htmlspecialchars($prod['productid']) ?>"
                                    style="text-decoration: none;">
                                    <div class="card h-100">
                                        <?php
                                        $prodId = $prod['productid'];
                                        $imageFile = $productFirstColorImages[$prodId] ?? null;
                                        if ($imageFile) {
                                            $imageSrc = 'uploads/products/' . htmlspecialchars($imageFile);
                                        } else {
                                            $imageSrc = 'uploads/products/placeholder.jpg';
                                        }
                                        ?>
                                        <img src="<?= $imageSrc ?>" class="card-img-top"
                                            alt="<?= htmlspecialchars($prod['pname']) ?>"
                                            onerror="this.src='https://via.placeholder.com/300x250?text=No+Image'"
                                            style="height: 250px; object-fit: cover;">
                                        <div class="card-body text-center">
                                            <h5 class="card-title"><?= htmlspecialchars($prod['pname']) ?></h5>
                                            <p class="text-muted mb-2">
                                                <i class="fas fa-store me-1"></i><?= htmlspecialchars($prod['sname']) ?>
                                            </p>
                                            <p class="h6 mb-0" style="color: #e74c3c; font-weight: 700;">
                                                HK$<?= number_format($prod['price']) ?></p>
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
                                <p>Try adjusting your filters to find what you're looking for.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ==================== Chat Widget Integration ==================== -->
    <?php
    // Include floating chat widget for logged-in users only
    if (isset($_SESSION['user'])) {
        include __DIR__ . '/Public/chat_widget.php';
    }
    ?>

    <!-- Chatfunction and initialization moved into Public/chat_widget.php -->
    </script>
    <!-- ==================== End Chat Widget Integration ==================== -->

</body>

</html>