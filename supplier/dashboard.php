<?php
// ==============================
// File: supplier/dashboard.php
// 供應商專屬後台首頁
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 1. 檢查是否登入，且身分必須是 supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php');
    exit;
}

$supplierId = $_SESSION['user']['supplierid'];
$supplierName = $_SESSION['user']['name'];

// 2. 讀取該供應商擁有的產品
$sql = "SELECT * FROM Product WHERE supplierid = ? ORDER BY productid DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $supplierId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Dashboard - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
            height: 100%;
        }
        .stat-number { font-size: 2rem; font-weight: bold; color: #3498db; }
        .product-table img {
            width: 50px; height: 50px; object-fit: cover; border-radius: 5px;
        }
        .action-btn { width: 32px; height: 32px; padding: 0; line-height: 32px; border-radius: 50%; text-align: center; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <header class="bg-white shadow-sm p-3 d-flex justify-content-between align-items-center">
        <div class="h4 mb-0 text-primary">HappyDesign <span class="text-muted fs-6">| Supplier Portal</span></div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted">Welcome, <strong><?= htmlspecialchars($supplierName) ?></strong></span>
            <a href="../logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">
        <div class="dashboard-header text-center">
            <h2>Product Management Console</h2>
            <p class="mb-0">Manage your inventory and listings</p>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-muted mb-2">Total Products</div>
                    <div class="stat-number"><?= $result->num_rows ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-muted mb-2">Views (Demo)</div>
                    <div class="stat-number">1,240</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-center flex-column">
                    <a href="Addpoduct.php" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-plus me-2"></i>Add New Product
                    </a>
                </div>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>My Products List</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 product-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Image</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <img src="product_image.php?id=<?= $row['productid'] ?>" alt="img">
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['pname']) ?></div>
                                        <small class="text-muted">ID: <?= $row['productid'] ?></small>
                                    </td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row['category']) ?></span></td>
                                    <td>
                                        HK$<?= number_format($row['price']) ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="product-detail.php?id=<?= $row['productid'] ?>" class="btn btn-primary action-btn btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-warning action-btn btn-sm text-white" title="Edit"><i class="fas fa-pen"></i></button>
                                        <button class="btn btn-danger action-btn btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        You haven't uploaded any products yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
