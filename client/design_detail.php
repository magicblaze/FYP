<?php
// ==============================
// File: detail.php (updated to use design_image.php)
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 檢查用戶是否已登錄，如果未登錄則重定向到登錄頁面
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
            $cstmt = $mysqli->prepare("INSERT INTO Comment (clientid, content, designid) VALUES (?,?,?)");
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
          FROM Comment c
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

// Use DB-driven image endpoint
$mainImg = '../design_image.php?id=' . (int)$design['designid'];
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
                    <li class="nav-item"><a class="nav-link" href="order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                      <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="detail-main">
        <div class="detail-container">
            <div class="mb-2">
                <button type="button" class="btn btn-light" onclick="handleBack()" aria-label="Back">
                    ← Back
                </button>
            </div>

            <div class="detail-content">
                <div class="detail-image-section">
                    <img src="<?= htmlspecialchars($mainImg) ?>" alt="Design image">
                </div>
                <div class="detail-info-section">
                    <div class="designer-info">
                        <div class="designer-name"><?= htmlspecialchars($design['dname']) ?></div>
                        <div class="price">$<?= number_format((float)$design['price'], 0) ?></div>
                        <?php if (!empty($tags)): ?>
                        <div class="mt-2">
                            <?php foreach ($tags as $tg): ?>
                                <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($tg) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="detail-stats">
                        <div class="likes-count"><?= (int)$design['likes'] ?> Likes</div>
                        <div><?= (int)$commentCount ?> Comments</div>
                    </div>

                    <div class="detail-actions">
                        <a class="btn btn-primary btn-lg" href="order.php?designid=<?= (int)$design['designid'] ?>" aria-label="Order this design now">
                            Order Now
                        </a>
                    </div>

                    <div class="detail-comments-section">
                        <div class="comments-header">Comments</div>
                        <?php if ($err): ?>
                            <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
                        <?php endif; ?>

                        <div class="detail-comments-list">
                            <?php if ($commentCount === 0): ?>
                                <div class="detail-comment-text">No comments yet.</div>
                            <?php endif; ?>
                            <?php while ($c = $comments->fetch_assoc()): ?>
                                <div class="detail-comment">
                                    <strong><?= htmlspecialchars($c['cname']) ?>:</strong>
                                    <div class="detail-comment-text"><?= htmlspecialchars($c['content']) ?></div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <form method="post" class="comment-input-group" autocomplete="off">
                            <div style="display:flex;gap:0.5rem">
                                <input class="form-control form-control-lg" type="text" name="comment" placeholder="Write a comment..." maxlength="255">
                                <button class="btn btn-success btn-lg" type="submit">Post</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <section class="detail-gallery" aria-label="This Designer's Other Designs">
        <h3>This Designer's Other Designs</h3>
        <div class="detail-gallery-images">
            <?php while ($r = $others->fetch_assoc()): ?>
                <a href="design_detail.php?designid=<?= (int)$r['designid'] ?>">
                    <img src="../design_image.php?id=<?= (int)$r['designid'] ?>" alt="Design">
                </a>
            <?php endwhile; ?>
        </div>
    </section>

    <script>
    function handleBack() {
            window.location.href = '../design_dashboard.php';
        }
    
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
