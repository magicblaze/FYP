<?php
// ==============================
// File: designer/design-detail.php
// Display design details with multiple images
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

$designid = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($designid <= 0) {
    http_response_code(404);
    die('Design not found.');
}

$dsql = "SELECT d.*, des.dname, des.demail, des.dtel, des.status
         FROM Design d
         JOIN Designer des ON d.designerid = des.designerid
         WHERE d.designid = ?";
$stmt = $mysqli->prepare($dsql);
$stmt->bind_param("i", $designid);
$stmt->execute();
$design = $stmt->get_result()->fetch_assoc();
if (!$design) {
    http_response_code(404);
    die('Design not found.');
}

// Handle edit save (designer-only, must own the design)
$saveErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'save_edit')) {
    // require designer session and ownership
    if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer' || (int) ($_SESSION['user']['designerid'] ?? 0) !== (int) $design['designerid']) {
        $saveErr = 'Permission denied.';
    } else {
        $newName = trim((string) ($_POST['designName'] ?? ''));
        $newPrice = isset($_POST['expect_price']) ? (float) $_POST['expect_price'] : (float) $design['expect_price'];
        $newDesc = trim((string) ($_POST['description'] ?? ''));
        $newTag = trim((string) ($_POST['tag'] ?? ''));
        $upd = $mysqli->prepare("UPDATE Design SET designName = ?, expect_price = ?, description = ?, tag = ? WHERE designid = ?");
        if ($upd) {
            $upd->bind_param('sdssi', $newName, $newPrice, $newDesc, $newTag, $designid);
            if ($upd->execute()) {
                $upd->close();
                // If the edit form submitted references to add, insert them now so Save Changes is authoritative
                if (!empty($_POST['add_refs']) && is_array($_POST['add_refs'])) {
                    $designerId = (int) ($_SESSION['user']['designerid'] ?? 0);
                    $ins = $mysqli->prepare("INSERT INTO DesignReference (designid, productid, note, added_by_designerid) VALUES (?, ?, ?, ?)");
                    $chk = $mysqli->prepare("SELECT id FROM DesignReference WHERE designid = ? AND productid = ? LIMIT 1");
                    if ($ins && $chk) {
                        foreach ($_POST['add_refs'] as $rid) {
                            $pid = (int) $rid;
                            if ($pid <= 0)
                                continue;
                            // skip if already referenced
                            $chk->bind_param('ii', $designid, $pid);
                            $chk->execute();
                            $chk->store_result();
                            if ($chk->num_rows > 0) {
                                $chk->free_result();
                                continue;
                            }
                            $chk->free_result();
                            $note = '';
                            $ins->bind_param('iisi', $designid, $pid, $note, $designerId);
                            $ins->execute();
                        }
                        $ins->close();
                        $chk->close();
                    }
                }
                // Redirect to GET to avoid resubmission and refresh displayed data (remove edit flag)
                $base = strtok($_SERVER['REQUEST_URI'], '?');
                header('Location: ' . $base . '?id=' . $designid);
                exit;
            } else {
                $saveErr = 'Failed to save changes: ' . $upd->error;
            }
        } else {
            $saveErr = 'Failed to prepare update: ' . $mysqli->error;
        }
    }
}

// Handle add/delete product reference (designer-owner only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_product_ref', 'delete_product_ref'], true)) {
    if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer' || (int) ($_SESSION['user']['designerid'] ?? 0) !== (int) $design['designerid']) {
        $saveErr = 'Permission denied.';
    } else {
        if ($_POST['action'] === 'add_product_ref') {
            $pid = isset($_POST['productid']) ? (int) $_POST['productid'] : 0;
            $note = trim((string) ($_POST['product_note'] ?? '')) ?: null;
            if ($pid <= 0) {
                $saveErr = 'Invalid product id.';
            } else {
                $ins = $mysqli->prepare("INSERT INTO DesignReference (designid, productid, note, added_by_designerid) VALUES (?, ?, ?, ?)");
                if ($ins) {
                    $designerId = (int) ($_SESSION['user']['designerid'] ?? 0);
                    $ins->bind_param('iiis', $designid, $pid, $note, $designerId);
                    // Note: bind_param with NULL string should be passed as nullable string; using 's' for note
                    // but variable types must match - use 'iiss' to be safe
                    $ins->close();
                    // Reprepare with correct types
                    $ins = $mysqli->prepare("INSERT INTO DesignReference (designid, productid, note, added_by_designerid) VALUES (?, ?, ?, ?)");
                    if ($ins) {
                        $ins->bind_param('iisi', $designid, $pid, $note, $designerId);
                            if ($ins->execute()) {
                                $ins->close();
                                $base = strtok($_SERVER['REQUEST_URI'], '?');
                                header('Location: ' . $base . '?id=' . $designid);
                                exit;
                        } else {
                            $saveErr = 'Failed to add reference: ' . $ins->error;
                        }
                    } else {
                        $saveErr = 'Failed to prepare insert: ' . $mysqli->error;
                    }
                } else {
                    $saveErr = 'Failed to prepare insert: ' . $mysqli->error;
                }
            }
        } elseif ($_POST['action'] === 'delete_product_ref') {
            $refid = isset($_POST['refid']) ? (int) $_POST['refid'] : 0;
            if ($refid <= 0) {
                $saveErr = 'Invalid reference id.';
            } else {
                $del = $mysqli->prepare("DELETE FROM DesignReference WHERE id = ? AND designid = ?");
                if ($del) {
                    $del->bind_param('ii', $refid, $designid);
                        if ($del->execute()) {
                            $del->close();
                            $base = strtok($_SERVER['REQUEST_URI'], '?');
                            header('Location: ' . $base . '?id=' . $designid);
                            exit;
                    } else {
                        $saveErr = 'Failed to delete reference: ' . $del->error;
                    }
                } else {
                    $saveErr = 'Failed to prepare delete: ' . $mysqli->error;
                }
            }
        }
    }
}

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

// Load product references for this design
$productRefs = [];
$refSql = "SELECT r.*, p.pname AS product_name 
           FROM DesignReference r 
           LEFT JOIN Product p ON p.productid = r.productid 
           WHERE r.designid = ? 
           ORDER BY r.created_at DESC";
$refStmt = $mysqli->prepare($refSql);
if ($refStmt) {
    $refStmt->bind_param('i', $designid);
    $refStmt->execute();
    $refResult = $refStmt->get_result();
    while ($r = $refResult->fetch_assoc()) {
        $productRefs[] = $r;
    }
    $refStmt->close();
}

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
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

        /* Hide remove buttons (preview mode). Shown when entering edit mode via JS. */
        .ref-remove { display: none; }

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
    <!-- Navbar -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <div class="design-detail-wrapper">
            <!-- Design Images Section -->
            <?php if (empty($editMode)): ?>
            <div class="design-image-section">
                <div class="design-carousel-wrapper">
                    <div class="design-carousel" id="designCarousel">
                        <?php foreach ($designImages as $index => $image): ?>
                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                <img src="<?= htmlspecialchars('../uploads/designs/' . $image['image_filename']) ?>"
                                    alt="Design Image <?= $index + 1 ?>">
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
                                    <div class="indicator <?= $index === 0 ? 'active' : '' ?>"
                                        onclick="goToImage(<?= $index ?>)" title="Image <?= $index + 1 ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (count($designImages) > 1): ?>
                        <div class="thumbnail-strip">
                            <?php foreach ($designImages as $index => $image): ?>
                                <div class="thumbnail <?= $index === 0 ? 'active' : '' ?>" onclick="goToImage(<?= $index ?>)">
                                    <img src="<?= htmlspecialchars('../uploads/designs/' . $image['image_filename']) ?>"
                                        alt="Thumbnail <?= $index + 1 ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="design-panel">

                <?php
                // Show edit controls only to the designer who owns this design
                $isOwnerDesigner = !empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'designer') && ((int) ($_SESSION['user']['designerid'] ?? 0) === (int) $design['designerid']);
                // Server-side edit mode flag (owner must request ?edit=1)
                $editMode = $isOwnerDesigner && (isset($_GET['edit']) && $_GET['edit'] === '1');
                ?>

                <?php if (empty($editMode)): ?>
                <div class="design-title"><?= htmlspecialchars($design['designName'] ?? 'Untitled Design') ?></div>
                <div style="font-size: 0.9rem; color: #7f8c8d; margin-bottom: 1rem;">Design ID: <?= $designid ?></div>
                <div class="design-price">HK$<?= number_format((float) ($design['expect_price'] ?? 0)) ?></div>

                <!-- Design Stats -->
                <div class="design-stats">
                    <div class="likes-count">
                        <i class="fas fa-heart text-danger"></i>
                        <?= $design['likes'] ?? 0 ?>
                    </div>
                </div>

                <?php if (empty($editMode)): ?>
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="history.back()" aria-label="Back">
                        ← Back
                    </button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <!-- Design Description -->
                <?php if (empty($editMode) && !empty($design['description'])): ?>
                <div class="design-description">
                    <strong>Description:</strong><br>
                    <?= htmlspecialchars($design['description']) ?>
                </div>
                <?php endif; ?>

                <!-- Design Tags -->
                <?php if (empty($editMode) && !empty($design['tag'])): ?>
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

                <?php if (!empty($saveErr)): ?>
                    <div class="alert alert-danger mt-3"><?= htmlspecialchars($saveErr) ?></div>
                <?php endif; ?>

                <?php if ($isOwnerDesigner): ?>
                    <div id="editFormWrapper" class="mt-3" style="<?= !empty($editMode) ? 'display:block;' : 'display:none;' ?>">
                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="save_edit">
                            <div class="mb-3">
                                <label class="form-label">Design Name</label>
                                <input name="designName" class="form-control"
                                    value="<?= htmlspecialchars($design['designName'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Expected Price (HK$)</label>
                                <input name="expect_price" type="number" step="0.01" class="form-control"
                                    value="<?= htmlspecialchars($design['expect_price'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tags (comma separated)</label>
                                <input name="tag" class="form-control"
                                    value="<?= htmlspecialchars($design['tag'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    rows="4"><?= htmlspecialchars($design['description'] ?? '') ?></textarea>
                            </div>
                            <div id="selectedLikesContainer" class="mb-3" style="display:none;">
                                <label class="form-label">Items to add as references</label>
                                <div id="selectedLikes" class="d-flex flex-wrap gap-2"></div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">Save Changes</button>
                                <button type="button" class="btn btn-secondary" onclick="hideEditForm()">Cancel</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Product References -->
                <div class="mt-4">
                    <h6>Product References</h6>
                    <?php if (count($productRefs) > 0): ?>
                        <ul class="list-group mb-2">
                            <?php foreach ($productRefs as $ref): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($ref['product_name'] ?? ('Product #' . ($ref['productid'] ?? ''))) ?></strong>
                                        <?php if (!empty($ref['note'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($ref['note']) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small">Added: <?= htmlspecialchars($ref['created_at'] ?? '') ?>
                                        </div>
                                    </div>
                                    <?php if ($isOwnerDesigner && !empty($editMode)): ?>
                                                        <form method="post" class="ref-remove" style="margin:0">
                                            <input type="hidden" name="action" value="delete_product_ref">
                                            <input type="hidden" name="refid" value="<?= (int) $ref['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                                onclick="return confirm('Remove this reference?')">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No product references yet.</p>
                    <?php endif; ?>

                    <?php if ($isOwnerDesigner): ?>
                        <div class="d-flex gap-2">
                            <form id="addProductRefForm" method="post" style="margin:0">
                                <input type="hidden" name="action" value="add_product_ref">
                                <input id="productid_input" type="hidden" name="productid" value="">
                                <input id="product_note_input" type="hidden" name="product_note" value="">
                            </form>
                            <div>
                                <button id="openLikedBtn" class="btn btn-sm btn-outline-secondary" <?= $editMode ? '' : 'style="display:none"' ?>>Add from liked items</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($isOwnerDesigner): ?>
            <!-- Liked items chooser (lightweight modal) -->
            <div id="likedModal"
                style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1050;align-items:center;justify-content:center;">
                <div
                    style="background:white;border-radius:8px;max-width:900px;width:95%;max-height:80vh;overflow:auto;padding:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <h5 style="margin:0">Choose liked items</h5>
                        <button id="likedModalClose" class="btn btn-sm btn-outline-secondary">Close</button>
                    </div>
                    <div id="likedItemsList" class="row g-2">Loading...</div>
                </div>
            </div>
            <div class="d-flex justify-content-center mb-3">
                <?php if (empty($editMode)): ?>
                    <a href="?id=<?= $designid ?>&edit=1" id="startEditBtn" class="btn btn-primary">Start Edit</a>
                <?php else: ?>
                    <a href="?id=<?= $designid ?>" class="btn btn-outline-secondary">Exit Edit</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
        document.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight') nextImage();
            if (event.key === 'ArrowLeft') previousImage();
        });

        // Edit form toggles for designer-owner
        function showEditForm() {
            const wrapper = document.getElementById('editFormWrapper');
            const startBtn = document.getElementById('startEditBtn');
            if (wrapper) wrapper.style.display = 'block';
            if (startBtn) startBtn.style.display = 'none';
            // reveal liked-items button when in edit mode
            try { const b = document.getElementById('openLikedBtn'); if (b) b.style.display = ''; } catch(e){}
            // show delete/remove buttons for references
            try { document.querySelectorAll('.ref-remove').forEach(function(el){ el.style.display = 'inline-block'; }); } catch(e){}
            window.scrollTo({ top: wrapper ? wrapper.offsetTop - 20 : 0, behavior: 'smooth' });
        }

        function hideEditForm() {
            const wrapper = document.getElementById('editFormWrapper');
            const startBtn = document.getElementById('startEditBtn');
            if (wrapper) wrapper.style.display = 'none';
            if (startBtn) startBtn.style.display = 'inline-block';
            try { const b = document.getElementById('openLikedBtn'); if (b) b.style.display = 'none'; } catch(e){}
            // hide delete/remove buttons when leaving edit mode
            try { document.querySelectorAll('.ref-remove').forEach(function(el){ el.style.display = 'none'; }); } catch(e){}
        }
    </script>
    <script>
        // Liked items chooser logic
        (function () {
            const openBtn = document.getElementById('openLikedBtn');
            const modal = document.getElementById('likedModal');
            const closeBtn = document.getElementById('likedModalClose');
            const list = document.getElementById('likedItemsList');
            const addForm = document.getElementById('addProductRefForm');
            const productIdInput = document.getElementById('productid_input');
            const selectedLikesContainer = document.getElementById('selectedLikes');
            const selectedLikesWrapper = document.getElementById('selectedLikesContainer');

            function renderItems(items) {
                if (!list) return;
                list.innerHTML = '';
                if (!items || !items.length) {
                    list.innerHTML = '<div class="col-12 text-muted">No liked items found.</div>';
                    return;
                }
                items.forEach(it => {
                    const col = document.createElement('div'); col.className = 'col-6 col-md-4';
                    const card = document.createElement('div'); card.className = 'card h-100';
                    const b = document.createElement('div'); b.className = 'card-body d-flex flex-column';
                    const title = document.createElement('div'); title.className = 'fw-bold mb-2'; title.textContent = it.title || it.pname || it.name || ('#' + (it.id || it.productid || it.item_id || ''));
                    const img = document.createElement('div'); img.style.minHeight = '80px'; img.style.marginBottom = '8px';
                    if (it.image) { const im = document.createElement('img'); im.src = it.image; im.style.maxWidth = '100%'; im.style.maxHeight = '120px'; im.style.objectFit = 'cover'; img.appendChild(im); }
                    const meta = document.createElement('div'); meta.className = 'text-muted small mb-2'; meta.textContent = (it.price ? ('HK$' + it.price) : '');
                    const btn = document.createElement('button'); btn.className = 'btn btn-sm btn-primary mt-auto'; btn.textContent = 'Add';
                    btn.addEventListener('click', function () {
                        const pid = it.productid || it.id || it.item_id || '';
                        if (!pid) return;
                        const editForm = document.querySelector('#editFormWrapper form');
                        if (!editForm) { alert('Enter Edit mode and click "Start Edit" to add references.'); return; }
                        if (editForm.querySelector('input[name="add_refs[]"][value="' + pid + '"]')) return;
                        const hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'add_refs[]'; hid.value = pid; editForm.appendChild(hid);
                        if (selectedLikesContainer && selectedLikesWrapper) {
                            selectedLikesWrapper.style.display = '';
                            const pill = document.createElement('div'); pill.className = 'badge bg-primary text-white d-inline-flex align-items-center';
                            pill.style.padding = '0.45rem 0.6rem'; pill.style.marginRight = '6px';
                            const txt = document.createElement('span'); txt.textContent = it.title || it.pname || pid; pill.appendChild(txt);
                            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn-close btn-close-white btn-sm ms-2'; remove.style.marginLeft = '8px';
                            remove.addEventListener('click', function () { hid.remove(); pill.remove(); if (!document.querySelector('#editFormWrapper form input[name="add_refs[]"]')) selectedLikesWrapper.style.display = 'none'; });
                            pill.appendChild(remove);
                            selectedLikesContainer.appendChild(pill);
                        }
                    });
                    b.appendChild(title); b.appendChild(img); b.appendChild(meta); b.appendChild(btn);
                    card.appendChild(b); col.appendChild(card); list.appendChild(col);
                });
            }

            async function loadLiked() {
                if (!list) return;
                list.innerHTML = '<div class="col-12 text-muted">Loading...</div>';
                try {
                    const res = await fetch('<?= dirname($_SERVER['SCRIPT_NAME']) ?>/../Public/get_chat_suggestions.php');
                    const j = await res.json();
                    const items = (j && j.liked_products) ? j.liked_products : [];
                    renderItems(items);
                } catch (e) { list.innerHTML = '<div class="col-12 text-danger">Failed to load liked items.</div>'; }
            }

            if (openBtn && modal) {
                openBtn.addEventListener('click', function (e) {
                    e.preventDefault(); modal.style.display = 'flex'; loadLiked();
                });
            }
            if (closeBtn && modal) { closeBtn.addEventListener('click', function () { modal.style.display = 'none'; }); }
            if (modal) { modal.addEventListener('click', function (ev) { if (ev.target === modal) modal.style.display = 'none'; }); }
        })();
    </script>
</body>

</html>