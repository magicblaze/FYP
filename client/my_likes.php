<?php
// ==============================
// File: my_likes.php
// Purpose: Display all products and designs that the user has liked
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in
if (empty($_SESSION['user'])) {
    header('Location: ../login.php?redirect=' . urlencode('client/my_likes.php'));
    exit;
}

$clientid = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientid <= 0) {
    http_response_code(403);
    die('Invalid session. Please sign in again.');
}

// Get liked products
$products_sql = "SELECT p.*, s.sname 
                 FROM Product p
                 JOIN Supplier s ON p.supplierid = s.supplierid
                 WHERE p.productid IN (
                     SELECT productid FROM ProductLike WHERE clientid = ?
                 )
                 ORDER BY p.productid DESC";
$products_stmt = $mysqli->prepare($products_sql);
$products_stmt->bind_param("i", $clientid);
$products_stmt->execute();
$liked_products = $products_stmt->get_result();

// Get liked designs
$designs_sql = "SELECT d.*, dz.dname 
                FROM Design d
                JOIN Designer dz ON d.designerid = dz.designerid
                WHERE d.designid IN (
                    SELECT designid FROM DesignLike WHERE clientid = ?
                )
                ORDER BY d.designid DESC";
$designs_stmt = $mysqli->prepare($designs_sql);
$designs_stmt->bind_param("i", $clientid);
$designs_stmt->execute();
$liked_designs = $designs_stmt->get_result();

$products_count = $liked_products->num_rows;
$designs_count = $liked_designs->num_rows;
$total_count = $products_count + $designs_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - My Likes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .my-likes-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .page-header {
            margin-bottom: 2rem;
            border-bottom: 2px solid #3498db;
            padding-bottom: 1rem;
        }

        .page-header h1 {
            color: #2c3e50;
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #7f8c8d;
            font-size: 1rem;
            margin: 0;
        }

        .section-title {
            color: #2c3e50;
            font-weight: 600;
            font-size: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ecf0f1;
        }

        .section-title i {
            color: #3498db;
            margin-right: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 4rem;
            color: #bdc3c7;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .empty-state a {
            display: inline-block;
            margin: 0 0.5rem;
        }

        /* Product/Design Grid */
        .likes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .like-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .like-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }

        .like-card-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            position: relative;
        }

        .like-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .like-card-image.no-image {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .like-card-body {
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .like-card-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .like-card-meta {
            color: #7f8c8d;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .like-card-price {
            color: #e74c3c;
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .like-card-designer {
            color: #3498db;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .like-card-footer {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
        }

        .like-card-btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.85rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .like-card-btn.view {
            background: #3498db;
            color: white;
        }

        .like-card-btn.view:hover {
            background: #2980b9;
        }

        .like-card-btn.remove {
            background: #ecf0f1;
            color: #e74c3c;
        }

        .like-card-btn.remove:hover {
            background: #e74c3c;
            color: white;
        }

        .stats-bar {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 12px;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #3498db;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 1.8rem;
            }

            .likes-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 1rem;
            }

            .stats-bar {
                gap: 1rem;
            }

            .stat-number {
                font-size: 1.2rem;
            }

            .stat-label {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="../furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link active" href="../client/my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link" href="../client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main>
        <div class="my-likes-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-heart" style="color: #e74c3c;"></i> My Likes</h1>
                <p>Manage all your favorite products and designs in one place</p>
            </div>

            <!-- Statistics Bar -->
            <?php if ($total_count > 0): ?>
            <div class="stats-bar">
                <div class="stat-item">
                    <span class="stat-number"><?= $total_count ?></span>
                    <span class="stat-label">Total Likes</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $products_count ?></span>
                    <span class="stat-label">Products</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $designs_count ?></span>
                    <span class="stat-label">Designs</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Liked Products Section -->
            <?php if ($products_count > 0): ?>
            <div class="section-title">
                <i class="fas fa-shopping-bag"></i> Liked Products (<?= $products_count ?>)
            </div>
            <div class="likes-grid">
                <?php while ($product = $liked_products->fetch_assoc()): 
                    // Determine image URL based on color
                    $productImageUrl = '../uploads/products/' . $product['image'];
                    if (!empty($product['color'])) {
                        // If product has colors, use first color's image
                        $productColors = array_map('trim', explode(',', $product['color']));
                        $productFirstColor = reset($productColors);
                        $productColorLower = strtolower(str_replace(' ', '_', $productFirstColor));
                        $productBaseImageName = pathinfo($product['image'], PATHINFO_FILENAME);
                        $productImageExtension = pathinfo($product['image'], PATHINFO_EXTENSION);
                        $productImageUrl = '../uploads/products/' . $productBaseImageName . '_' . $productColorLower . '.' . $productImageExtension;
                    }
                ?>
                <div class="like-card">
                    <div class="like-card-image">
                        <img src="<?= htmlspecialchars($productImageUrl) ?>" alt="<?= htmlspecialchars($product['pname']) ?>">
                    </div>
                    <div class="like-card-body">
                        <div class="like-card-title" title="<?= htmlspecialchars($product['pname']) ?>">
                            <?= htmlspecialchars($product['pname']) ?>
                        </div>
                        <div class="like-card-meta">
                            <i class="fas fa-store"></i> <?= htmlspecialchars($product['sname']) ?>
                        </div>
                        <div class="like-card-meta">
                            <i class="fas fa-tag"></i> <?= htmlspecialchars($product['category']) ?>
                        </div>
                        <div class="like-card-price">HK$<?= number_format((float)$product['price']) ?></div>
                        <div class="like-card-designer">
                            <i class="fas fa-heart"></i> <?= (int)$product['likes'] ?> likes
                        </div>
                        <div class="like-card-footer">
                            <a href="product_detail.php?id=<?= (int)$product['productid'] ?>&from=my_likes" class="like-card-btn view">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- Liked Designs Section -->
            <?php if ($designs_count > 0): ?>
            <div class="section-title">
                <i class="fas fa-pencil-ruler"></i> Liked Designs (<?= $designs_count ?>)
            </div>
            <div class="likes-grid">
                <?php while ($design = $liked_designs->fetch_assoc()): ?>
                <div class="like-card">
                    <div class="like-card-image">
                        <img src="../uploads/designs/<?= htmlspecialchars($design['design']) ?>" alt="<?= htmlspecialchars($design['dname']) ?>">
                    </div>
                    <div class="like-card-body">
                        <div class="like-card-title" title="<?= htmlspecialchars($design['dname']) ?>">
                            <?= htmlspecialchars($design['dname']) ?>
                        </div>
                        <div class="like-card-meta">
                            <i class="fas fa-user"></i> Designer
                        </div>
                        <div class="like-card-price">HK$<?= number_format((float)$design['price']) ?></div>
                        <div class="like-card-designer">
                            <i class="fas fa-heart"></i> <?= (int)$design['likes'] ?> likes
                        </div>
                        <div class="like-card-footer">
                            <a href="design_detail.php?designid=<?= (int)$design['designid'] ?>&from=my_likes" class="like-card-btn view">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- Complete Empty State -->
            <?php if ($total_count === 0): ?>
            <div class="empty-state" style="grid-column: 1 / -1;">
                <i class="fas fa-heart-broken"></i>
                <h3>You haven't liked anything yet</h3>
                <p>Start exploring our products and designs to build your collection of favorites!</p>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
    // Remove button functionality removed - users can only view items
    </script>
</body>
</html>
