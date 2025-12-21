<?php
// ==============================
// File: product-detail.php
// 用途：顯示產品詳細信息
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 檢查用戶是否已登錄，如果未登錄則重定向到登錄頁面
if (empty($_SESSION['user'])) {
    $redirect = 'client/product_detail.php' . (isset($_GET['id']) ? ('?id=' . urlencode((string)$_GET['id'])) : '');
    header('Location: ../login.php?redirect=' . urlencode($redirect));
    exit;
}

$productid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productid <= 0) { http_response_code(404); die('Product not found.'); }

$psql = "SELECT p.*, s.sname, s.semail, s.stel
         FROM Product p
         JOIN Supplier s ON p.supplierid = s.supplierid
         WHERE p.productid = ?";
$stmt = $mysqli->prepare($psql);
$stmt->bind_param("i", $productid);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) { http_response_code(404); die('Product not found.'); }



// Get other products from the same supplier
$other_sql = "SELECT productid, pname, price FROM Product WHERE supplierid=? AND productid<>? LIMIT 6";
$other_stmt = $mysqli->prepare($other_sql);
$other_stmt->bind_param("ii", $product['supplierid'], $productid);
$other_stmt->execute();
$others = $other_stmt->get_result();

// Use DB-driven image endpoint
$mainImg = '../supplier/product_image.php?id=' . (int)$product['productid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - <?= htmlspecialchars($product['pname']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .product-detail-wrapper {
            display: flex;
            gap: 2rem;
            align-items: stretch;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .product-image-wrapper {
            flex: 0 0 auto;
            width: 500px;
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .product-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-panel {
            flex: 0 0 400px;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            display: flex;
            flex-direction: column;
        }

        .back-button {
            margin-bottom: 1.5rem;
        }

        .product-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .product-price {
            font-size: 1.8rem;
            color: #e74c3c;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .product-stats {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        .likes-count {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .heart-icon {
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
        }

        .heart-icon:hover {
            transform: scale(1.2);
        }

        .heart-icon.liked {
            color: #e74c3c;
        }

        .product-meta {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            margin-top: 1.5rem;
        }

        .product-meta div {
            margin-bottom: 0.5rem;
        }

        .product-specs {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .product-specs div {
            margin-bottom: 0.5rem;
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        .product-description {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .product-description h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .product-description p {
            color: #5a6c7d;
            line-height: 1.6;
            margin: 0;
        }





        @media (max-width: 768px) {
            .product-detail-wrapper {
                flex-direction: column;
                gap: 1.5rem;
            }

            .product-image-wrapper {
                width: 100%;
                max-width: 400px;
                margin: 0 auto;
            }

            .product-panel {
                padding: 1.5rem;
            }

            .product-stats {
                flex-direction: column;
                gap: 1rem;
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
                    <li class="nav-item"><a class="nav-link" href="order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main>
        <div class="product-detail-wrapper">
            <!-- Product Image -->
            <div class="product-image-wrapper">
                <img src="<?= htmlspecialchars($mainImg) ?>" alt="<?= htmlspecialchars($product['pname']) ?>">
            </div>

            <!-- Product Information Panel -->
            <div class="product-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="handleBack()" aria-label="Back">
                        ← Back
                    </button>
                </div>

                <div class="product-title"><?= htmlspecialchars($product['pname']) ?></div>
                <div class="product-price">HK$<?= number_format((float)$product['price']) ?></div>

                <div class="product-stats">
                    <div class="likes-count">
                        <span class="heart-icon" id="likeHeart" data-productid="<?= (int)$product['productid'] ?>">♡</span>
                        <span id="likeCount"><?= (int)$product['likes'] ?></span> Likes
                    </div>
                </div>

                <div class="product-meta">
                    <div><i class="fas fa-store me-2"></i><strong>Supplier:</strong> <?= htmlspecialchars($product['sname']) ?></div>
                    <div><i class="fas fa-tag me-2"></i><strong>Category:</strong> <?= htmlspecialchars($product['category']) ?></div>
                </div>

                <?php if (!empty($product['size']) || !empty($product['color']) || !empty($product['material'])): ?>
                <div class="product-specs">
                    <?php if (!empty($product['size'])): ?>
                        <div><i class="fas fa-ruler me-2"></i><strong>Size:</strong> <?= htmlspecialchars($product['size']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($product['color'])): ?>
                        <div><i class="fas fa-palette me-2"></i><strong>Color:</strong> <?= htmlspecialchars($product['color']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($product['material'])): ?>
                        <div><i class="fas fa-cube me-2"></i><strong>Material:</strong> <?= htmlspecialchars($product['material']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($product['description'])): ?>
                <div class="product-description">
                    <h6>Description</h6>
                    <p><?= htmlspecialchars($product['description']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($others->num_rows > 0): ?>
    <section class="detail-gallery" aria-label="Other Products from This Supplier" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        <h3 style="color: #2c3e50; font-weight: 600; margin-bottom: 1rem; font-size: 1.3rem;">Other Products from <?= htmlspecialchars($product['sname']) ?></h3>
        <div class="detail-gallery-images">
            <?php while ($r = $others->fetch_assoc()): ?>
                <a href="product_detail.php?id=<?= (int)$r['productid'] ?>">
                    <img src="../supplier/product_image.php?id=<?= (int)$r['productid'] ?>" alt="<?= htmlspecialchars($r['pname']) ?>">
                </a>
            <?php endwhile; ?>
        </div>
    </section>
    <?php endif; ?>

    <script>
    function handleBack() {
        // Determine which dashboard to go back to based on category
        const category = '<?= htmlspecialchars($product['category']) ?>';
        if (category === 'Furniture') {
            window.location.href = '../furniture_dashboard.php';
        } else if (category === 'Material') {
            window.location.href = '../material_dashboard.php';
        } else {
            window.location.href = '../design_dashboard.php';
        }
    }

    // Heart like functionality
    document.addEventListener('DOMContentLoaded', function() {
        const likeHeart = document.getElementById('likeHeart');
        const likeCount = document.getElementById('likeCount');
        const productId = likeHeart.getAttribute('data-productid');
        
        // Check if user has already liked this product
        const likedProducts = JSON.parse(localStorage.getItem('likedProducts') || '{}');
        if (likedProducts[productId]) {
            likeHeart.classList.add('liked');
            likeHeart.textContent = '♥'; // Filled heart
        }
        
        likeHeart.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle liked state
            const isLiked = likeHeart.classList.contains('liked');
            
            // Send AJAX request to update likes
            fetch('../api/update_product_likes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    productid: productId,
                    action: isLiked ? 'unlike' : 'like'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    if (isLiked) {
                        likeHeart.classList.remove('liked');
                        likeHeart.textContent = '♡'; // Empty heart
                        likedProducts[productId] = false;
                    } else {
                        likeHeart.classList.add('liked');
                        likeHeart.textContent = '♥'; // Filled heart
                        likedProducts[productId] = true;
                    }
                    
                    // Update like count
                    likeCount.textContent = data.likes;
                    
                    // Save to localStorage
                    localStorage.setItem('likedProducts', JSON.stringify(likedProducts));
                } else {
                    alert('Failed to update like. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
