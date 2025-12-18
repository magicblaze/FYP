<?php
// ==============================
// File: supplier/product-detail.php
// ==============================
// 修正 config 路徑
require_once __DIR__ . '/../config.php';
session_start();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: products.php");
    exit;
}

// 獲取產品詳情和供應商信息
$sql = "SELECT p.*, s.sname FROM Product p 
        JOIN Supplier s ON p.supplierid = s.supplierid 
        WHERE p.productid = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    die("Product not found.");
}

// 獲取相關產品
$relSql = "SELECT productid, pname, price FROM Product 
           WHERE category = ? AND productid != ? LIMIT 5";
$relStmt = $mysqli->prepare($relSql);
$relStmt->bind_param("si", $product['category'], $id);
$relStmt->execute();
$related = $relStmt->get_result();

// 定義供應商名稱（從 session 或產品數據）
$supplierName = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HappyDesign - <?= htmlspecialchars($product['pname']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- 修正 CSS 路徑 -->
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .product-detail-layout { display: flex; flex-wrap: wrap; gap: 30px; margin-top: 30px; }
        .product-image-section { flex: 1; min-width: 300px; }
        .product-image-large { 
            width: 100%; height: 400px; object-fit: cover; border-radius: 15px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        }
        .product-info-section { flex: 1; min-width: 300px; }
        .product-title { font-size: 2rem; font-weight: 700; color: #2c3e50; margin-bottom: 1rem; }
        .product-details-box { 
            background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px; 
            border-left: 5px solid #3498db;
        }
        .product-details-box p { margin-bottom: 8px; }
        .product-price { font-size: 1.8rem; color: #e74c3c; font-weight: 700; margin: 20px 0; }
        
        .quantity-control { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .qty-btn { width: 35px; height: 35px; border-radius: 50%; border: 1px solid #ced4da; background: white; font-weight: bold; }
        .qty-input { width: 60px; text-align: center; border: 1px solid #ced4da; border-radius: 5px; height: 35px; }
        .btn-buy-now { 
            background: #27ae60; color: white; padding: 12px 40px; border-radius: 25px; 
            font-size: 1.1rem; font-weight: 600; width: 100%; border: none; transition: all 0.3s;
        }
        .btn-buy-now:hover { background: #219150; transform: translateY(-2px); }

        .related-products-section { margin-top: 50px; }
        .related-title { border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 20px; color: #2c3e50; }
    </style>
</head>
<body>
    <!-- Header: 連結皆須加上 ../ -->
    <header class="bg-white shadow-sm p-3 d-flex justify-content-between align-items-center">
        <div class="h4 mb-0 text-primary">HappyDesign <span class="text-muted fs-6">| Supplier Portal</span></div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted">Welcome, <strong><?= htmlspecialchars($supplierName) ?></strong></span>
            <a href="../logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </header>

    <main class="container mb-5">
        <div class="product-detail-layout">
            <div class="product-image-section">
                <img src="product_image.php?id=<?= $product['productid'] ?>" class="product-image-large" alt="<?= htmlspecialchars($product['pname']) ?>">
            </div>

            <div class="product-info-section">
                <h1 class="product-title"><?= htmlspecialchars($product['pname']) ?></h1>

                <div class="product-details-box">
                    <p><strong>Category:</strong> <?= ucfirst(htmlspecialchars($product['category'])) ?></p>
                    <p><strong>Size:</strong> <?= htmlspecialchars($product['size'] ?? 'N/A') ?></p>
                    <p><strong>Color:</strong> <?= htmlspecialchars($product['color'] ?? 'N/A') ?></p>
                    <p><strong>Material:</strong> <?= htmlspecialchars($product['material'] ?? 'N/A') ?></p>
                    <p><strong>Supplier:</strong> <?= htmlspecialchars($product['sname']) ?></p>
                    <div class="mt-2 text-muted"><?= nl2br(htmlspecialchars($product['description'] ?? '')) ?></div>
                </div>

                <h2 class="product-price">
                    HK$<?= number_format($product['price']) ?>
                </h2>

                <div class="quantity-buy-section">
                    <div class="quantity-control">
                        <span class="fw-bold me-2">Qty:</span>
                        <button class="qty-btn" onclick="decreaseQty()">−</button>
                        <input type="number" id="quantity" class="qty-input" value="1" min="1">
                        <button class="qty-btn" onclick="increaseQty()">+</button>
                    </div>
                    <button class="btn btn-buy-now" onclick="buyNow()">
                        <i class="fas fa-shopping-cart me-2"></i>Buy Now
                    </button>
                </div>
            </div>
        </div>

        <div class="related-products-section">
            <h3 class="related-title">Related Products</h3>
            <div class="row g-3">
                <?php while ($rel = $related->fetch_assoc()): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card h-100 border-0 shadow-sm">
                        <a href="product-detail.php?id=<?= $rel['productid'] ?>" style="text-decoration: none; color: inherit;">
                            <img src="product_image.php?id=<?= $rel['productid'] ?>" class="card-img-top" style="height: 120px; object-fit: cover; border-radius: 8px 8px 0 0;" alt="Related">
                            <div class="card-body p-2 text-center">
                                <h6 class="card-title text-truncate" style="font-size: 0.9rem;"><?= htmlspecialchars($rel['pname']) ?></h6>
                                <p class="text-danger fw-bold small mb-0">
                                    HK$<?= number_format($rel['price']) ?>
                                </p>
                            </div>
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </main>

    <footer class="text-center py-4 text-muted border-top mt-5">
        <p>&copy; 2025 HappyDesign. All rights reserved.</p>
    </footer>

    <script>
        function increaseQty() {
            const qty = document.getElementById('quantity');
            qty.value = parseInt(qty.value) + 1;
        }

        function decreaseQty() {
            const qty = document.getElementById('quantity');
            if (parseInt(qty.value) > 1) {
                qty.value = parseInt(qty.value) - 1;
            }
        }

        function buyNow() {
            const qty = document.getElementById('quantity').value;
            alert(`Successfully added ${qty} item(s) to your cart!`);
        }
    </script>
</body>
</html>
