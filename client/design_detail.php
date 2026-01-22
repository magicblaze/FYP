<?php
// ==============================
// File: design_detail.php (UPDATED for new like system)
// Purpose: Display design details with new unified like system
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in
if (empty($_SESSION['user'])) {
    $redirect = 'client/design_detail.php' . (isset($_GET['designid']) ? ('?designid=' . urlencode((string)$_GET['designid'])) : '');
    header('Location: ../login.php?redirect=' . urlencode($redirect));
    exit;
}

$designid = isset($_GET['designid']) ? (int)$_GET['designid'] : 0;
if ($designid <= 0) { http_response_code(404); die('Design not found.'); }

$dsql = "SELECT d.designid, d.price, d.likes, d.tag, dz.dname, d.designerid
         FROM Design d
         JOIN Designer dz ON d.designerid = dz.designerid
         WHERE d.designid = ?";
$stmt = $mysqli->prepare($dsql);
$stmt->bind_param("i", $designid);
$stmt->execute();
$design = $stmt->get_result()->fetch_assoc();
if (!$design) { http_response_code(404); die('Design not found.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $content = trim($_POST['comment']);
    if ($content !== '') {
        $clientId = (int)($_SESSION['user']['clientid'] ?? 0);
        if ($clientId <= 0) {
            $err = 'Invalid session. Please sign in again.';
        } else {
            $cstmt = $mysqli->prepare("INSERT INTO Comment_design (clientid, content, designid) VALUES (?,?,?)");
            $cstmt->bind_param("isi", $clientId, $content, $designid);
            if ($cstmt->execute()) {
                header("Location: design_detail.php?designid=".$designid);
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

$rawTags = (string)($design['tag'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $rawTags)));

$o = $mysqli->prepare("SELECT designid, price FROM Design WHERE designerid=? AND designid<>? LIMIT 6");
$o->bind_param("ii", $design['designerid'], $designid);
$o->execute();
$others = $o->get_result();

// Check if current user has liked this design
$clientid = (int)($_SESSION['user']['clientid'] ?? 0);
$liked = false;
if ($clientid > 0) {
    $like_check_sql = "SELECT COUNT(*) as count FROM DesignLike WHERE clientid = ? AND designid = ?";
    $like_check_stmt = $mysqli->prepare($like_check_sql);
    $like_check_stmt->bind_param("ii", $clientid, $designid);
    $like_check_stmt->execute();
    $like_result = $like_check_stmt->get_result()->fetch_assoc();
    $liked = $like_result['count'] > 0;
}

// Determine back button destination based on referrer
$backUrl = '../design_dashboard.php'; // Default destination
if (isset($_GET['from']) && $_GET['from'] === 'my_likes') {
    $backUrl = 'my_likes.php';
}

// Use DB-driven image endpoint (absolute URL to avoid relative-path issues)
$baseUrlEarly = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
// compute application root (one level above this `client/` folder) so URLs point to /FYP/design_image.php
$appRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$appPathEarly = $appRoot; // legacy variable used later in templates
$mainImg = $baseUrlEarly . $appRoot . '/design_image.php?id=' . (int)$design['designid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - <?= htmlspecialchars($design['dname']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
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

        .design-image-wrapper {
            flex: 0 0 auto;
            width: 500px;
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .design-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
            margin-bottom: 1rem;
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

        .heart-icon {
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
            background: none;
            border: none;
            padding: 0;
            color: #7f8c8d;
        }

        .heart-icon:hover {
            transform: scale(1.2);
        }

        .heart-icon.liked {
            color: #e74c3c;
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
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link active" href="../design_dashboard.php">Design</a></li>
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
                    <li class="nav-item"><a class="nav-link" href="../client/my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link" href="order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main>
        <div class="design-detail-wrapper">
            <!-- Design Image -->
            <div class="design-image-wrapper">
                <img src="<?= htmlspecialchars($mainImg) ?>" alt="<?= htmlspecialchars($design['dname']) ?>">
            </div>

            <!-- Design Information Panel -->
            <div class="design-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="window.location.href='<?= htmlspecialchars($backUrl) ?>'" aria-label="Back">
                        ← Back
                    </button>
                </div>

                <div class="design-title"><?= htmlspecialchars($design['dname']) ?></div>
                <div class="design-price">HK$<?= number_format((float)$design['price']) ?></div>

                <div class="design-stats">
                    <div class="likes-count">
                        <button class="heart-icon <?= $liked ? 'liked' : '' ?>" id="likeHeart" data-designid="<?= (int)$design['designid'] ?>" title="Like this design">
                            <?= $liked ? '♥' : '♡' ?>
                        </button>
                        <span id="likeCount"><?= (int)$design['likes'] ?></span> Likes
                    </div>
                </div>

                <div class="design-meta">
                    <div><i class="fas fa-user me-2"></i><strong>Designer:</strong> <?= htmlspecialchars($design['dname']) ?></div>
                </div>

                <?php if (!empty($tags)): ?>
                <div class="design-tags">
                    <h6>Tags</h6>
                    <?php foreach ($tags as $tag): ?>
                        <span class="tag"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="design-actions">
                    <button type="button" class="btn btn-primary btn-order" onclick="handleOrder(<?= (int)$design['designid'] ?>)">
                        <i class="fas fa-shopping-cart me-2"></i>Order
                    </button>
                    <!-- share button moved into chat widget panel -->
                    <button type="button" class="btn btn-info btn-chat" onclick="(window.handleChat ? window.handleChat(<?= (int)$design['designerid'] ?>, { creatorId: <?= (int)$clientid ?>, otherName: '<?= htmlspecialchars(addslashes($design['dname']), ENT_QUOTES) ?>' }) : (window.location.href = '../chat.php?designerid=<?= (int)$design['designerid'] ?>'))" >
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
                    <textarea class="form-control" name="comment" placeholder="Share your thoughts about this design..." required></textarea>
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
    <section class="detail-gallery" aria-label="Other Designs from This Designer" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        <h3 style="color: #2c3e50; font-weight: 600; margin-bottom: 1rem; font-size: 1.3rem;">Other Designs from <?= htmlspecialchars($design['dname']) ?></h3>
        <div class="detail-gallery-images">
            <?php while ($r = $others->fetch_assoc()): ?>
                <a href="design_detail.php?designid=<?= (int)$r['designid'] ?>">
                    <img src="<?= htmlspecialchars($baseUrlEarly . $appPathEarly . '/design_image.php?id=' . (int)$r['designid']) ?>" alt="Design">
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
        window.location.href = 'order.php?designid=' + designId;
    }
    </script>

        <script>
        // Like toggle for designs (AJAX)
        (function(){
            const heart = document.getElementById('likeHeart');
            if (!heart) return;
            heart.addEventListener('click', function(e){
                e.preventDefault();
                const designid = this.dataset.designid;
                const btn = this;
                const formData = new FormData();
                formData.append('action', 'toggle_like');
                formData.append('type', 'design');
                formData.append('id', designid);

                fetch('../api/handle_like.php', { method: 'POST', body: formData })
                    .then(r=>r.json())
                    .then(data=>{
                        if (data && data.success) {
                            if (data.liked) { btn.classList.add('liked'); btn.textContent='♥'; }
                            else { btn.classList.remove('liked'); btn.textContent='♡'; }
                            const lc = document.getElementById('likeCount'); if (lc) lc.textContent = data.likes;
                        } else {
                            alert('Error: ' + (data.message || 'Failed to update like'));
                        }
                    }).catch(err=>{ console.error(err); alert('An error occurred while updating the like.'); });
            });
        })();
        </script>

    <!-- Chat widget: include unified PHP widget (handles markup and initialization) -->
    <?php
        // Provide server-side share payload so the widget handles sharing internally
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        // Ensure we include the application path (script directory) so URLs like /FYP/design_image.php are correct
        $appPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $CHAT_SHARE = [
            'designId' => (int)$designid,
            'title' => $design['dname'] ?? '',
            'url' => $baseUrl . $_SERVER['REQUEST_URI'],
            'designerId' => (int)$design['designerid'],
            'image' => $baseUrl . $appPath . '/design_image.php?id=' . (int)$designid
        ];
        include __DIR__ . '/../Public/chat_widget.php';
    ?>
</body>
</html>
