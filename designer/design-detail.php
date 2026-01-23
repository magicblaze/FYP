<?php
// ==============================
// File: designer/design-detail.php
// Display design details with multiple images
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

$designid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($designid <= 0) { http_response_code(404); die('Design not found.'); }

$dsql = "SELECT d.*, des.dname, des.demail, des.dtel
         FROM Design d
         JOIN Designer des ON d.designerid = des.designerid
         WHERE d.designid = ?";
$stmt = $mysqli->prepare($dsql);
$stmt->bind_param("i", $designid);
$stmt->execute();
$design = $stmt->get_result()->fetch_assoc();
if (!$design) { http_response_code(404); die('Design not found.'); }

// Get all design images
$imagesql = "SELECT imageid, image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC";
$imageStmt = $mysqli->prepare($imagesql);
$imageStmt->bind_param("i", $designid);
$imageStmt->execute();
$imageResult = $imageStmt->get_result();
$designImages = [];
while ($row = $imageResult->fetch_assoc()) {
    $designImages[] = $row;
}
$imageStmt->close();

// If no images in DesignImage table, fall back to main design image
if (empty($designImages) && !empty($design['design'])) {
    $designImages[] = ['imageid' => 0, 'image_filename' => $design['design']];
}

// Get comments for this design
$commentSql = "SELECT cd.*, c.cname FROM Comment_design cd 
               JOIN Client c ON cd.clientid = c.clientid 
               WHERE cd.designid = ? 
               ORDER BY cd.timestamp DESC";
$commentStmt = $mysqli->prepare($commentSql);
$commentStmt->bind_param("i", $designid);
$commentStmt->execute();
$commentResult = $commentStmt->get_result();
$comments = [];
while ($row = $commentResult->fetch_assoc()) {
    $comments[] = $row;
}
$commentStmt->close();

// Define designer name
$designerName = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Design #<?= $designid ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .design-detail-wrapper {
            display: flex;
            gap: 2rem;
            align-items: stretch;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .design-image-section {
            flex: 0 0 auto;
            width: 500px;
        }

        .design-carousel-wrapper {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background-color: #f8f9fa;
            margin-bottom: 1rem;
        }

        .design-carousel {
            position: relative;
            width: 100%;
            height: 500px;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .carousel-item {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .carousel-item.active {
            display: flex;
            opacity: 1;
        }

        .carousel-item img {
            max-width: 90%;
            max-height: 90%;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        .carousel-controls {
            position: absolute;
            top: 50%;
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 1rem;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .carousel-btn {
            pointer-events: all;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.3s;
        }

        .carousel-btn:hover {
            background: rgba(0, 0, 0, 0.8);
        }

        .carousel-indicators {
            position: absolute;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 0.5rem;
            z-index: 10;
        }

        .indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: background 0.3s;
        }

        .indicator.active {
            background: white;
        }

        .thumbnail-strip {
            display: flex;
            gap: 0.5rem;
            padding: 0.5rem;
            background: white;
            overflow-x: auto;
        }

        .thumbnail {
            width: 80px;
            height: 80px;
            border-radius: 6px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.3s;
            flex-shrink: 0;
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .thumbnail.active {
            border-color: #3498db;
        }

        .design-panel {
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

        .design-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .design-price {
            font-size: 1.8rem;
            color: #e74c3c;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .design-description {
            color: #5a6c7d;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-left: 4px solid #3498db;
            border-radius: 4px;
        }

        .design-meta {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .design-meta div {
            margin-bottom: 0.5rem;
        }

        .design-tags {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .design-tags .badge {
            background-color: #3498db;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            display: inline-block;
        }

        .design-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 0.5rem 0;
            border-top: 1px solid #ecf0f1;
            border-bottom: 1px solid #ecf0f1;
        }

        .likes-count {
            font-size: 1.1rem;
            color: #7f8c8d;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .heart-icon {
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            color: #bdc3c7;
        }

        .heart-icon:hover {
            transform: scale(1.2);
            color: #e74c3c;
        }

        .heart-icon.liked {
            color: #e74c3c;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .design-detail-wrapper {
                flex-direction: column;
                gap: 1.5rem;
            }

            .design-image-section {
                width: 100%;
            }

            .design-carousel {
                height: 350px;
            }

            .design-panel {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="designer_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="designer_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../supplier/schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($designerName) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <main>
        <div class="design-detail-wrapper">
            <!-- Design Images Section -->
            <div class="design-image-section">
                <div class="design-carousel-wrapper">
                    <div class="design-carousel" id="designCarousel">
                        <?php foreach ($designImages as $index => $image): ?>
                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                <img src="<?= htmlspecialchars('../uploads/designs/' . $image['image_filename']) ?>" alt="Design Image <?= $index + 1 ?>">
                            </div>
                        <?php endforeach; ?>

                        <!-- Show navigation arrows only if multiple images -->
                        <?php if (count($designImages) > 1): ?>
                        <div class="carousel-controls">
                            <button class="carousel-btn" onclick="previousImage()" title="Previous image">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="carousel-btn" onclick="nextImage()" title="Next image">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <?php endif; ?>

                        <!-- Show indicators only if multiple images -->
                        <?php if (count($designImages) > 1): ?>
                            <div class="carousel-indicators">
                                <?php foreach ($designImages as $index => $image): ?>
                                    <div class="indicator <?= $index === 0 ? 'active' : '' ?>" onclick="goToImage(<?= $index ?>)" title="Image <?= $index + 1 ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (count($designImages) > 1): ?>
                        <div class="thumbnail-strip">
                            <?php foreach ($designImages as $index => $image): ?>
                                <div class="thumbnail <?= $index === 0 ? 'active' : '' ?>" onclick="goToImage(<?= $index ?>)">
                                    <img src="<?= htmlspecialchars('../uploads/designs/' . $image['image_filename']) ?>" alt="Thumbnail <?= $index + 1 ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="design-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="history.back()" aria-label="Back">
                        ← Back
                    </button>
                </div>

                <div class="design-title"><?= htmlspecialchars($design['designName'] ?? 'Untitled Design') ?></div>
                <div style="font-size: 0.9rem; color: #7f8c8d; margin-bottom: 1rem;">Design ID: <?= $designid ?></div>
                <div class="design-price">HK$<?= number_format((float)($design['expect_price'] ?? 0)) ?></div>

                <!-- Design Description -->
                <?php if (!empty($design['description'])): ?>
                <div class="design-description">
                    <strong>Description:</strong><br>
                    <?= htmlspecialchars($design['description']) ?>
                </div>
                <?php endif; ?>

                <!-- Design Tags -->
                <?php if (!empty($design['tag'])): ?>
                <div class="design-tags">
                    <strong>Tags:</strong><br>
                    <?php 
                    $tags = array_map('trim', explode(',', $design['tag']));
                    foreach ($tags as $tag): 
                    ?>
                        <span class="badge"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Design Stats -->
                <div class="design-stats">
                    <div class="likes-count">
                        <i class="fas fa-heart text-danger"></i>
                        <span><?= $design['likes'] ?? 0 ?> Likes</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comments Section -->
        <div style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-comments me-2"></i>Client Comments (<?= count($comments) ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (count($comments) > 0): ?>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($comments as $comment): ?>
                                <div style="border-bottom: 1px solid #ecf0f1; padding: 1rem 0; margin-bottom: 1rem;">
                                    <strong style="color: #2c3e50;"><?= htmlspecialchars($comment['cname']) ?></strong>
                                    <div style="color: #7f8c8d; font-size: 0.85rem; margin-bottom: 0.5rem;">
                                        <?= date('M d, Y H:i', strtotime($comment['timestamp'])) ?>
                                    </div>
                                    <p style="color: #5a6c7d; margin: 0;"><?= htmlspecialchars($comment['content']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No comments yet. Be the first to comment!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        let currentImageIndex = 0;
        const totalImages = <?= count($designImages) ?>;

        function showImage(index) {
            const items = document.querySelectorAll('.carousel-item');
            const indicators = document.querySelectorAll('.indicator');
            const thumbnails = document.querySelectorAll('.thumbnail');

            items.forEach(item => item.classList.remove('active'));
            indicators.forEach(ind => ind.classList.remove('active'));
            thumbnails.forEach(thumb => thumb.classList.remove('active'));

            items[index].classList.add('active');
            if (indicators[index]) indicators[index].classList.add('active');
            if (thumbnails[index]) thumbnails[index].classList.add('active');

            currentImageIndex = index;
        }

        function nextImage() {
            const nextIndex = (currentImageIndex + 1) % totalImages;
            showImage(nextIndex);
        }

        function previousImage() {
            const prevIndex = (currentImageIndex - 1 + totalImages) % totalImages;
            showImage(prevIndex);
        }

        function goToImage(index) {
            showImage(index);
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowRight') nextImage();
            if (event.key === 'ArrowLeft') previousImage();
        });
    </script>
</body>
</html>
