<?php
// ==============================
// File: my_likes.php
// Purpose: Display all products and designs that the user has liked
// ==============================
require_once __DIR__ . '/config.php';
session_start();
 
// Compute application root (handles hosting under a subfolder like /FYP)
$appRoot = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($appRoot === '' || $appRoot === '/' ) $appRoot = '';
// Check if user is logged in
if (empty($_SESSION['user'])) {
    header('Location: login.php?redirect=' . urlencode('my_likes.php'));
    exit;
}

// Determine current user type and id
$user = $_SESSION['user'];
$user_type = strtolower(trim($user['role'] ?? '')) ?: 'client';
$user_id = 0;
if (!empty($user['clientid']))
    $user_id = (int) $user['clientid'];
elseif (!empty($user['designerid']))
    $user_id = (int) $user['designerid'];
elseif (!empty($user['supplierid']))
    $user_id = (int) $user['supplierid'];
elseif (!empty($user['managerid']))
    $user_id = (int) $user['managerid'];
elseif (!empty($user['id']))
    $user_id = (int) $user['id'];

if ($user_id <= 0) {
    http_response_code(403);
    die('Invalid session. Please sign in again.');
}

// Get liked products with their first color images (UserLike only)
$likedProductIds = [];
$pr = $mysqli->prepare("SELECT item_id FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'product'");
if ($pr) {
    $pr->bind_param('si', $user_type, $user_id);
    $pr->execute();
    $res = $pr->get_result();
    while ($r = $res->fetch_assoc())
        $likedProductIds[] = (int) $r['item_id'];
    $pr->close();
}

if (count($likedProductIds)) {
    $in = implode(',', array_map('intval', $likedProductIds));
    // Use a correlated subquery to reliably fetch the first ProductColorImage.image for each product
    $products_sql = "SELECT p.*, s.sname,
                     (SELECT pci.image FROM ProductColorImage pci WHERE pci.productid = p.productid ORDER BY pci.id ASC LIMIT 1) AS first_color_image
                     FROM Product p
                     JOIN Supplier s ON p.supplierid = s.supplierid
                     WHERE p.productid IN ($in)
                     ORDER BY p.productid DESC";
    $liked_products = $mysqli->query($products_sql);
} else {
    $liked_products = $mysqli->query("SELECT * FROM Product WHERE 0");
}

// Get liked designs (UserLike only)
$likedDesignIds = [];
$dr = $mysqli->prepare("SELECT item_id FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'design'");
if ($dr) {
    $dr->bind_param('si', $user_type, $user_id);
    $dr->execute();
    $dres = $dr->get_result();
    while ($r = $dres->fetch_assoc())
        $likedDesignIds[] = (int) $r['item_id'];
    $dr->close();
}

if (count($likedDesignIds)) {
    $in = implode(',', array_map('intval', $likedDesignIds));
    $designs_sql = "SELECT d.*, dz.dname, di.image_filename
                    FROM Design d
                    JOIN Designer dz ON d.designerid = dz.designerid
                    LEFT JOIN DesignImage di ON d.designid = di.designid
                    WHERE d.designid IN ($in)
                    GROUP BY d.designid
                    ORDER BY d.designid DESC";
    $liked_designs = $mysqli->query($designs_sql);
} else {
    $liked_designs = $mysqli->query("SELECT * FROM Design WHERE 0");
}

// Process designs and get first image for each
$designs_list = [];
while ($row = $liked_designs->fetch_assoc()) {
    if (empty($row['image_filename'])) {
        $img_sql = "SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC, imageid ASC LIMIT 1";
        $img_stmt = $mysqli->prepare($img_sql);
        $img_stmt->bind_param("i", $row['designid']);
        $img_stmt->execute();
        $img_result = $img_stmt->get_result();
        if ($img_row = $img_result->fetch_assoc()) {
            $row['image_filename'] = $img_row['image_filename'];
        }
        $img_stmt->close();
    }
    $designs_list[] = $row;
}

$products_count = $liked_products->num_rows;
$designs_count = count($designs_list);
$total_count = $products_count + $designs_count;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - My Likes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <?php include_once __DIR__ . '/includes/header.php'; ?>

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
                        // Use first color image from ProductColorImage table, or placeholder if not available or missing on disk
                        $fname = basename((string)($product['first_color_image'] ?? ''));
                        $diskPath = __DIR__ . '/uploads/products/' . $fname;
                        if ($fname && is_file($diskPath)) {
                            $productImageUrl = $appRoot . '/uploads/products/' . rawurlencode($fname);
                        } else {
                            $productImageUrl = $appRoot . '/uploads/products/placeholder.jpg';
                        }
                        ?>
                        <div class="like-card">
                            <div class="like-card-image">
                                <img src="<?= $productImageUrl ?>" alt="<?= htmlspecialchars($product['pname']) ?>">
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
                                <div class="like-card-price">HK$<?= number_format((float) $product['price']) ?></div>
                                <div class="like-card-designer">
                                    <i class="fas fa-heart"></i> <?= (int) $product['likes'] ?> likes
                                </div>
                                <div class="like-card-footer">
                                    <a href="product_detail.php?id=<?= (int) $product['productid'] ?>&from=my_likes"
                                        class="like-card-btn view">
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
                    <?php foreach ($designs_list as $design):
                        $dname = basename((string)($design['image_filename'] ?? ''));
                        $dpath = __DIR__ . '/uploads/designs/' . $dname;
                        $designImageUrl = ($dname && is_file($dpath)) ? $appRoot . '/uploads/designs/' . rawurlencode($dname) : $appRoot . '/uploads/designs/placeholder.jpg';
                        ?>
                        <div class="like-card">
                            <div class="like-card-image">
                                <img src="<?= $designImageUrl ?>"
                                    alt="<?= htmlspecialchars($design['dname']) ?>">
                            </div>
                            <div class="like-card-body">
                                <div class="like-card-title" title="<?= htmlspecialchars($design['dname']) ?>">
                                    <?= htmlspecialchars($design['dname']) ?>
                                </div>
                                <div class="like-card-meta">
                                    <i class="fas fa-user"></i> Designer
                                </div>
                                <div class="like-card-price">HK$<?= number_format((float) $design['expect_price']) ?></div>
                                <div class="like-card-designer">
                                    <i class="fas fa-heart"></i> <?= (int) $design['likes'] ?> likes
                                </div>
                                <div class="like-card-footer">
                                    <a href="design_detail.php?designid=<?= (int) $design['designid'] ?>&from=my_likes"
                                        class="like-card-btn view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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


    <?php include __DIR__ . '/Public/chat_widget.php'; ?>
</body>

</html>