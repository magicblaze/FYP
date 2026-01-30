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
                </div>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="comments-section" style="max-width: max-content; margin: 0 auto; padding: 0 1rem;">
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
            style="max-width: max-content; margin: 2rem auto; padding: 0 1rem;">
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

    <script>
        // Like toggle for designs (AJAX)
        (function () {
            const heart = document.getElementById('likeHeart');
            if (!heart) return;
            heart.addEventListener('click', function (e) {
                e.preventDefault();
                const designid = this.dataset.designid;
                const btn = this;
                const formData = new FormData();
                formData.append('action', 'toggle_like');
                formData.append('type', 'design');
                formData.append('id', designid);

                fetch('api/handle_like.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            const icon = btn.querySelector('i');
                            if (data.liked) {
                                btn.classList.add('liked');
                                if (icon) { icon.classList.remove('far'); icon.classList.add('fas'); }
                                btn.setAttribute('aria-pressed', 'true');
                            } else {
                                btn.classList.remove('liked');
                                if (icon) { icon.classList.remove('fas'); icon.classList.add('far'); }
                                btn.setAttribute('aria-pressed', 'false');
                            }
                            const lc = document.getElementById('likeCount'); if (lc) lc.textContent = data.likes;
                        } else {
                            alert('Error: ' + (data.message || 'Failed to update like'));
                        }
                    }).catch(err => { console.error(err); alert('An error occurred while updating the like.'); });
            });
        })();
    </script>

    <!-- Chat widget: include unified PHP widget (handles markup and initialization) -->
    <?php
    include __DIR__ . '/Public/chat_widget.php'; ?>
</body>

</html>