<?php
// ==============================
// File: design_detail.php (UPDATED for new like system)
// Purpose: Display design details with new unified like system
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// Page is accessible to all users (login optional)

// Determine current user's role for conditional UI rendering
$userRole = isset($_SESSION['user']['role']) ? strtolower($_SESSION['user']['role']) : null;
if (empty($userRole)) {
    if (!empty($_SESSION['user']['designerid']))
        $userRole = 'designer';
    elseif (!empty($_SESSION['user']['managerid']))
        $userRole = 'manager';
    elseif (!empty($_SESSION['user']['clientid']))
        $userRole = 'client';
}

$designid = isset($_GET['designid']) ? (int) $_GET['designid'] : 0;
if ($designid <= 0) {
    http_response_code(404);
    die('Design not found.');
}

$dsql = "SELECT d.designid, d.designName, d.expect_price, d.likes, d.tag, d.description, dz.dname, d.designerid
         FROM Design d
         JOIN Designer dz ON d.designerid = dz.designerid
         WHERE d.designid = ?";
$stmt = $mysqli->prepare($dsql);
$stmt->bind_param("i", $designid);
$stmt->execute();
$design = $stmt->get_result()->fetch_assoc();
if (!$design) {
    http_response_code(404);
    die('Design not found.');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $content = trim($_POST['comment']);
    if ($content !== '') {
        $clientId = (int) ($_SESSION['user']['clientid'] ?? 0);
        if ($clientId <= 0) {
            $err = 'Invalid session. Please sign in again.';
        } else {
            $cstmt = $mysqli->prepare("INSERT INTO Comment_design (clientid, content, designid) VALUES (?,?,?)");
            $cstmt->bind_param("isi", $clientId, $content, $designid);
            if ($cstmt->execute()) {
                header("Location: design_detail.php?designid=" . $designid);
                exit;
            } else {
                $err = 'Failed to add comment. Please try again later.';
            }
        }
    } else {
        $err = 'Comment cannot be empty.';
    }
}

$cmsql = "SELECT c.content, c.timestamp, u.cname
          FROM Comment_design c
          JOIN Client u ON u.clientid = c.clientid
          WHERE c.designid = ?
          ORDER BY c.timestamp DESC";
$cst = $mysqli->prepare($cmsql);
$cst->bind_param("i", $designid);
$cst->execute();
$comments = $cst->get_result();
$commentCount = $comments->num_rows;

$rawTags = (string) ($design['tag'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $rawTags)));

$o = $mysqli->prepare("SELECT designid, expect_price, description FROM Design WHERE designerid=? AND designid<>? LIMIT 6");
$o->bind_param("ii", $design['designerid'], $designid);
$o->execute();
$others = $o->get_result();

// Check if current user has liked this design
$liked = false;
$user_type = $_SESSION['user']['role'] ?? null;
$user_id = 0;
if (!empty($user_type)) {
    $user_type = strtolower($user_type);
    if ($user_type === 'client') $user_id = (int)($_SESSION['user']['clientid'] ?? 0);
    elseif ($user_type === 'designer') $user_id = (int)($_SESSION['user']['designerid'] ?? 0);
    elseif ($user_type === 'manager') $user_id = (int)($_SESSION['user']['managerid'] ?? 0);
}

if ($user_id > 0 && !empty($user_type)) {
    // Prefer unified UserLike table if present
    $ulike_sql = "SELECT COUNT(*) AS cnt FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'design' AND item_id = ?";
    $ulike_stmt = $mysqli->prepare($ulike_sql);
    if ($ulike_stmt) {
        $ulike_stmt->bind_param("sii", $user_type, $user_id, $designid);
        $ulike_stmt->execute();
        $res = $ulike_stmt->get_result()->fetch_assoc();
        $liked = ($res['cnt'] ?? 0) > 0;
    }
}

// Determine back button destination based on referrer
// Default destination (file is in project root)
$backUrl = 'design_dashboard.php';
if (isset($_GET['from']) && $_GET['from'] === 'my_likes') {
    $backUrl = 'my_likes.php';
}

// Fetch design images from DesignImage table
$images_sql = "SELECT imageid, designid, image_filename, image_order FROM DesignImage WHERE designid = ? ORDER BY image_order ASC, imageid ASC";
$images_stmt = $mysqli->prepare($images_sql);
$images_stmt->bind_param("i", $designid);
$images_stmt->execute();
$images_result = $images_stmt->get_result();
$images = [];
while ($row = $images_result->fetch_assoc()) {
    $images[] = $row;
}
$imageCount = count($images);

// Use DB-driven image endpoint (absolute URL to avoid relative-path issues)
$baseUrlEarly = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
// compute application root (one level above this `client/` folder) so URLs point to /FYP/design_image.php
$appRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$appPathEarly = $appRoot; // legacy variable used later in templates
$mainImg = $baseUrlEarly . $appRoot . '/design_image.php?id=' . (int) $design['designid'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - <?= htmlspecialchars($design['dname']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
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
            z-index: 5;
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
            margin-bottom: 1rem;
        }

        .design-price .price-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            font-weight: 500;
            display: block;
            margin-bottom: 0.25rem;
        }

        .design-price .price-value {
            font-size: 1.8rem;
            color: #27ae60;
            font-weight: 700;
        }

        .design-description {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #3498db;
        }

        .design-description h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .design-description p {
            color: #5a6c7d;
            line-height: 1.6;
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .design-stats {
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

        .design-meta {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            margin-top: 1.5rem;
        }

        .design-meta div {
            margin-bottom: 0.5rem;
        }

        .design-tags {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .design-tags h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .tag {
            display: inline-block;
            background-color: #ecf0f1;
            color: #2c3e50;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
        }

        .tag:hover {
            background-color: #3498db;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .comments-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #ecf0f1;
        }

        .comments-section h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .comment-form {
            margin-bottom: 1.5rem;
        }

        .comment-form textarea {
            resize: vertical;
            min-height: 80px;
        }

        .comment-item {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .comment-author {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .comment-time {
            font-size: 0.85rem;
            color: #95a5a6;
        }

        .comment-content {
            color: #5a6c7d;
            line-height: 1.6;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .design-detail-wrapper {
                flex-direction: column;
                gap: 1.5rem;
            }

            .design-image-wrapper {
                width: 100%;
                max-width: 400px;
                margin: 0 auto;
            }

            .design-panel {
                padding: 1.5rem;
            }

            .design-stats {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/includes/header.php'; ?>

    <main>
        <div class="design-detail-wrapper">
            <!-- Design Image with Carousel -->
            <div class="design-image-section">
                <div class="design-carousel-wrapper">
                    <div class="design-carousel" id="designCarousel">
                        <?php foreach ($images as $index => $image): ?>
                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                <img src="<?= htmlspecialchars('uploads/designs/' . $image['image_filename']) ?>"
                                    alt="<?= htmlspecialchars($design['dname']) ?> Image <?= $index + 1 ?>">
                            </div>
                        <?php endforeach; ?>

                        <!-- Show navigation arrows only if multiple images -->
                        <?php if ($imageCount > 1): ?>
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
                        <?php if ($imageCount > 1): ?>
                            <div class="carousel-indicators">
                                <?php foreach ($images as $index => $image): ?>
                                    <div class="indicator <?= $index === 0 ? 'active' : '' ?>"
                                        onclick="goToImage(<?= $index ?>)" title="Image <?= $index + 1 ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($imageCount > 1): ?>
                    <div class="thumbnail-strip">
                        <?php foreach ($images as $index => $image): ?>
                            <div class="thumbnail <?= $index === 0 ? 'active' : '' ?>" onclick="goToImage(<?= $index ?>)">
                                <img src="<?= htmlspecialchars('uploads/designs/' . $image['image_filename']) ?>"
                                    alt="Thumbnail <?= $index + 1 ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Design Information Panel -->
            <div class="design-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light"
                        onclick="window.location.href='<?= htmlspecialchars($backUrl) ?>'" aria-label="Back">
                        ← Back
                    </button>
                </div>

                <div class="design-title"><?= htmlspecialchars($design['designName']) ?></div>
                <div class="design-price">
                    <span class="price-label">Expected Price:</span>
                    <span class="price-value">HK$<?= number_format((float) $design['expect_price']) ?></span>
                </div>

                <div class="design-stats">
                    <div class="likes-count">
                        <button class="heart-icon <?= $liked ? 'liked' : '' ?>" id="likeHeart"
                            data-designid="<?= (int) $design['designid'] ?>" title="Like this design" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
                            <i class="<?= $liked ? 'fas' : 'far' ?> fa-heart" aria-hidden="true"></i>
                        </button>
                        <span id="likeCount"><?= (int) $design['likes'] ?></span> Likes
                    </div>
                </div>

                <div class="design-meta">
                    <div><i class="fas fa-user me-2"></i><strong>Designer:</strong>
                        <?= htmlspecialchars($design['dname']) ?></div>
                </div>

                <?php if (!empty($design['description'])): ?>
                    <div class="design-description">
                        <p><?= nl2br(htmlspecialchars($design['description'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($tags)): ?>
                    <div class="design-tags">
                        <h6>Tags</h6>
                        <?php foreach ($tags as $tag): ?>
                            <span class="tag"
                                onclick="searchTag('<?= htmlspecialchars(addslashes($tag), ENT_QUOTES) ?>')"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="design-actions">
                    <?php if (!in_array($userRole, ['designer', 'manager'], true)): ?>
                        <button type="button" class="btn btn-primary btn-order"
                            onclick="handleOrder(<?= (int) $design['designid'] ?>)">
                            <i class="fas fa-shopping-cart me-2"></i>Order
                        </button>
                    <?php endif; ?>
                    <!-- share button moved into chat widget panel -->
                    <button type="button" class="btn btn-info btn-chat"
                        onclick="(window.handleChat ? window.handleChat(<?= (int) $design['designerid'] ?>, { creatorId: <?= (int) $clientid ?>, otherName: '<?= htmlspecialchars(addslashes($design['dname']), ENT_QUOTES) ?>' }) : (window.location.href = '<?= htmlspecialchars($baseUrlEarly . $appRoot . '/Public/chat_widget.php?designerid=' . (int) $design['designerid']) ?>'))">
                        <i class="fas fa-comments me-2"></i>Chat
                    </button>
                </div>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section" style="max-width: 1200px; margin: 0 auto; padding: 0 1rem;">
            <h5><i class="fas fa-comments me-2"></i>Comments (<?= $commentCount ?>)</h5>

            <?php if (!empty($err)): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>

            <!-- Comment Form -->
            <form method="POST" class="comment-form">
                <div class="mb-3">
                    <textarea class="form-control" name="comment" placeholder="Share your thoughts about this design..."
                        required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Post Comment</button>
            </form>

            <!-- Comments List -->
            <?php if ($commentCount > 0): ?>
                <?php while ($comment = $comments->fetch_assoc()): ?>
                    <div class="comment-item">
                        <div class="comment-author"><?= htmlspecialchars($comment['cname']) ?></div>
                        <div class="comment-time"><?= htmlspecialchars($comment['timestamp']) ?></div>
                        <div class="comment-content"><?= htmlspecialchars($comment['content']) ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-muted">No comments yet. Be the first to comment!</p>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($others->num_rows > 0): ?>
        <section class="detail-gallery" aria-label="Other Designs from This Designer"
            style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
            <h3 style="color: #2c3e50; font-weight: 600; margin-bottom: 1rem; font-size: 1.3rem;">Other Designs from
                <?= htmlspecialchars($design['dname']) ?></h3>
            <div class="detail-gallery-images">
                <?php while ($r = $others->fetch_assoc()): ?>
                    <?php
                    $img_sql = "SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1";
                    $img_stmt = $mysqli->prepare($img_sql);
                    $img_stmt->bind_param("i", $r['designid']);
                    $img_stmt->execute();
                    $img_result = $img_stmt->get_result()->fetch_assoc();
                    $img_filename = $img_result ? $img_result['image_filename'] : 'placeholder.jpg';
                    ?>
                    <a href="design_detail.php?designid=<?= (int) $r['designid'] ?>">
                        <img src="<?= htmlspecialchars('uploads/designs/' . $img_filename) ?>" alt="Design">
                    </a>
                <?php endwhile; ?>
            </div>
        </section>
    <?php endif; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        // 處理訂單按鈕點擊事件
        function handleOrder(designId) {
            // 重定向到訂單頁面
            window.location.href = 'client/order.php?designid=' + designId;
        }

        // 處理標籤點擊事件，重定向到設計儀表板並搜索該標籤
        function searchTag(tag) {
            const encodedTag = encodeURIComponent(tag);
            window.location.href = 'design_dashboard.php?tag=' + encodedTag;
        }

        let currentImageIndex = 0;
        const totalImages = <?= $imageCount ?>;

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

        document.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight') nextImage();
            if (event.key === 'ArrowLeft') previousImage();
        });
    </script>

    <!-- Chat widget: include unified PHP widget (handles markup and initialization) -->
    <?php
    include __DIR__ . '/Public/chat_widget.php'; ?>
</body>

</html>