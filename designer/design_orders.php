<?php
// ==============================
// File: designer/design_orders.php
// Display and manage design orders with designed picture upload
// ==============================

session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$designerId = intval($_SESSION['user']['designerid']);
$designerName = $_SESSION['user']['name'];

// Optional: show a single order when ?orderid= is provided
$orderId = isset($_GET['orderid']) ? (int) $_GET['orderid'] : 0;
if ($orderId > 0) {
    $sql = "
    SELECT 
        o.orderid,
        o.odate,
        c.budget,
        o.gross_floor_area,
        o.ostatus,
        o.designid,
        o.Requirements,
        d.designName,
        d.expect_price,
        d.tag,
        c.cname,
        c.cemail,
        c.address,
        c.Floor_Plan
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    JOIN Client c ON o.clientid = c.clientid
    WHERE d.designerid = ? AND o.orderid = ?
    LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        die('Prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param("ii", $designerId, $orderId);
    if (!$stmt->execute()) {
        die('Execute failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
} else {
    // Get all orders for designs by this designer
    $sql = "
    SELECT 
        o.orderid,
        o.odate,
        c.budget,
        o.gross_floor_area,
        o.ostatus,
        o.designid,
        o.Requirements,
        d.designName,
        d.expect_price,
        d.tag,
        c.cname,
        c.cemail,
        c.address,
        c.Floor_Plan
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    JOIN Client c ON o.clientid = c.clientid
    WHERE d.designerid = ?
    ORDER BY o.orderid DESC
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        die('Prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param("i", $designerId);
    if (!$stmt->execute()) {
        die('Execute failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
}
$orders = [];

while ($row = $result->fetch_assoc()) {
    // Get first design image from DesignImage table
    $imgSql = "SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC, imageid ASC LIMIT 1";
    $imgStmt = $mysqli->prepare($imgSql);
    $imgStmt->bind_param("i", $row['designid']);
    $imgStmt->execute();
    $imgResult = $imgStmt->get_result();
    if ($imgRow = $imgResult->fetch_assoc()) {
        $row['design_image'] = $imgRow['image_filename'];
    } else {
        $row['design_image'] = null;
    }
    $imgStmt->close();

    // Get designed pictures for this order
    $picSql = "SELECT * FROM DesignedPicture WHERE orderid = ? ORDER BY upload_date DESC";
    $picStmt = $mysqli->prepare($picSql);
    $picStmt->bind_param("i", $row['orderid']);
    $picStmt->execute();
    $picResult = $picStmt->get_result();

    $pictures = [];
    while ($pic = $picResult->fetch_assoc()) {
        $pictures[] = $pic;
    }
    $picStmt->close();

    $row['pictures'] = $pictures;
    // Get order references (if table exists)
    $row['references'] = [];
    $refSql = "SELECT id, designid, messageid, note, added_by_type, added_by_id, created_at FROM OrderReference WHERE orderid = ? ORDER BY created_at ASC";
    $refStmt = $mysqli->prepare($refSql);
    if ($refStmt) {
        $refStmt->bind_param('i', $row['orderid']);
        if ($refStmt->execute()) {
            $refRes = $refStmt->get_result();
            $refs = [];
            while ($rr = $refRes->fetch_assoc()) {
                $refs[] = $rr;
            }
            $row['references'] = $refs;
        }
        $refStmt->close();
    }
    $orders[] = $row;
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Design Orders - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }

        .order-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: box-shadow 0.3s;
        }

        .order-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }

        .order-header {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .order-image {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .order-main-info {
            flex: 1;
        }

        .order-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .order-price {
            color: #3498db;
            font-weight: 500;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .order-id {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .detail-item {
            font-size: 0.9rem;
        }

        .detail-label {
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: #212529;
            font-weight: 600;
        }

        .order-info {
            background: #f8fafd;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }

        .order-info-item {
            margin-bottom: 0.5rem;
        }

        .order-info-label {
            color: #6c757d;
            font-weight: 500;
        }

        .order-info-value {
            color: #212529;
        }

        .no-orders {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        /* Designed Picture Section Styles */
        .designed-picture-section {
            background: #f0f7ff;
            border-left: 4px solid #3498db;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
        }

        .picture-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .picture-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            border: 2px solid #dee2e6;
            transition: all 0.3s;
        }

        .picture-item:hover {
            border-color: #3498db;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.2);
        }

        .picture-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .picture-status {
            position: absolute;
            top: 5px;
            right: 5px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
        }

        .status-pending {
            background: #f39c12;
        }

        .status-approved {
            background: #27ae60;
        }

        .status-rejected {
            background: #e74c3c;
        }

        .picture-actions {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            padding: 0.5rem;
            display: flex;
            gap: 0.25rem;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .picture-item:hover .picture-actions {
            opacity: 1;
        }

        .picture-actions button {
            padding: 4px 8px;
            font-size: 0.75rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background: #3498db;
            color: white;
            transition: background 0.2s;
        }

        .picture-actions button:hover {
            background: #2980b9;
        }

        .upload-area {
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }

        .upload-area:hover {
            background: #e8f4f8;
            border-color: #2980b9;
        }

        .upload-area.dragover {
            background: #d4e9f7;
            border-color: #2980b9;
        }

        .rejection-reason {
            background: #ffe8e8;
            border-left: 4px solid #e74c3c;
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .preview-section {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            background: #f8f9fa;
            margin-top: 1rem;
        }

        .preview-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- Dashboard Content -->
    <main class="container-lg mt-4">
        <div class="mt-5 mb-4 text-center">
            <h2>Order detail</h2>
            <p class="mb-0"></p>
        </div>

        <?php if (count($orders) > 0): ?>

            <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <!-- Order Header with Image and Main Info -->
                    <div class="order-header">
                        <!-- Design Image -->
                        <div>
                            <?php if (!empty($order['design_image'])): ?>
                                <img src="../uploads/designs/<?= htmlspecialchars($order['design_image']) ?>"
                                    alt="Design #<?= $order['designid'] ?>" class="order-image">
                            <?php else: ?>
                                <div class="order-image d-flex align-items-center justify-content-center">
                                    <i class="fas fa-image text-muted" style="font-size: 2rem;"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Order Main Info -->
                        <div class="order-main-info">
                            <div class="order-title">Order #<?= $order['orderid'] ?></div>
                            <div class="order-price">HK$<?= number_format($order['budget']) ?> Budget</div>
                            <div class="order-id">Design: <a
                                    href="../design_detail.php?designid=<?= (int) $order['designid'] ?>" target="_blank"
                                    rel="noopener"><?= htmlspecialchars($order['designName'] ?? ('Design #' . $order['designid'])) ?></a>
                            </div>
                            <div id="status_<?= $order['orderid'] ?>" style="margin-top:6px"><strong>Status:</strong>
                                <?= htmlspecialchars($order['ostatus']) ?></div>
                        </div>
                    </div>

                    <!-- Order Details -->
                    <div class="order-details">
                        <div class="detail-item">
                            <div class="detail-label">Order Date</div>
                            <div class="detail-value"><?= date('M d, Y H:i', strtotime($order['odate'])) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Budget</div>
                            <div class="detail-value">HK$<?= number_format($order['budget']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Expected Price</div>
                            <div class="detail-value">HK$<?= number_format($order['expect_price']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Gross Floor Area</div>
                            <div class="detail-value"><?= isset($order['gross_floor_area']) && $order['gross_floor_area'] > 0 ? htmlspecialchars(number_format((float)$order['gross_floor_area'],2)) . ' m²' : '&mdash;' ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Tags</div>
                            <div class="detail-value">
                                <small><?= htmlspecialchars(substr($order['tag'], 0, 50)) ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Floor Plan Section -->
                    <?php if (!empty($order['Floor_Plan'])): ?>
                        <div class="order-info" style="background-color: #e3f2fd; border-left: 4px solid #3498db;">
                            <div class="order-info-item">
                                <div class="detail-label">Floor Plan</div>
                                <img src="../<?= htmlspecialchars($order['Floor_Plan']) ?>" alt="Floor Plan"
                                    title="Preview floor plan" class="ms-2"
                                    style="width:72px;height:72px;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid #e3f2fd;"
                                    onclick="event.stopPropagation(); openFloorPlanPreview('../<?= htmlspecialchars($order['Floor_Plan']) ?>')" />
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Requirements Section -->
                    <?php if (!empty($order['Requirements'])): ?>
                        <div class="order-info" style="background-color: #f3e5f5; border-left: 4px solid #9c27b0;">
                            <div class="order-info-item">
                                <span class="order-info-label"><i class="fas fa-list me-1"></i>Requirements:</span>
                                <span class="order-info-value"><?= htmlspecialchars($order['Requirements']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Designed Picture Section -->
                    <div class="designed-picture-section">
                        <h6 class="mb-3"><i class="fas fa-image me-2"></i>Designed Pictures</h6>

                        <?php if (!empty($order['pictures'])): ?>
                            <div class="picture-gallery">
                                <?php foreach ($order['pictures'] as $pic): ?>
                                    <div class="picture-item">
                                        <img src="../uploads/designed_Picture/<?= htmlspecialchars($pic['filename']) ?>"
                                            alt="Designed Picture">
                                        <span class="picture-status status-<?= $pic['status'] ?>">
                                            <?= ucfirst($pic['status']) ?>
                                        </span>
                                        <div class="picture-actions">
                                            <button
                                                onclick="viewPicture(<?= (int) $pic['pictureid'] ?>, <?= json_encode($pic['filename']) ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                        <?php if ($pic['status'] === 'rejected'): ?>
                                            <div class="rejection-reason">
                                                <strong>Rejected:</strong>
                                                <?= htmlspecialchars($pic['rejection_reason'] ?? 'No reason provided') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-3">No designed pictures uploaded yet.</p>
                        <?php endif; ?>

                        <!-- Upload Area -->
                        <div class="mt-3">
                            <?php
                            $hasPendingPicture = false;
                            $hasApprovedPicture = false;
                            foreach ($order['pictures'] as $pic) {
                                if ($pic['status'] === 'pending') {
                                    $hasPendingPicture = true;
                                }
                                if ($pic['status'] === 'approved') {
                                    $hasApprovedPicture = true;
                                }
                            }
                            ?>
                            <?php if ($hasApprovedPicture): ?>
                                <div
                                    style="background: #d4edda; border: 2px dashed #28a745; border-radius: 8px; padding: 2rem; text-align: center;">
                                    <i class="fas fa-check-circle"
                                        style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem; display: block;"></i>
                                    <strong style="color: #155724;">Picture Approved</strong>
                                    <p class="text-muted mb-0" style="font-size: 0.9rem; color: #155724;">This design has been
                                        approved. No further uploads are allowed.</p>
                                </div>
                            <?php elseif ($hasPendingPicture): ?>
                                <div
                                    style="background: #fff3cd; border: 2px dashed #ffc107; border-radius: 8px; padding: 2rem; text-align: center;">
                                    <i class="fas fa-hourglass-half"
                                        style="font-size: 2rem; color: #ffc107; margin-bottom: 0.5rem; display: block;"></i>
                                    <strong style="color: #856404;">Waiting for Client Response</strong>
                                    <p class="text-muted mb-0" style="font-size: 0.9rem; color: #856404;">You can upload a new
                                        picture if the client rejects the current one.</p>
                                </div>
                            <?php else: ?>
                                <div id="uploadContainer_<?= $order['orderid'] ?>">
                                    <label class="upload-area" id="uploadArea_<?= $order['orderid'] ?>">
                                        <div>
                                            <i class="fas fa-cloud-upload-alt"
                                                style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                            <strong>Click to upload or drag & drop</strong>
                                            <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP (Max 10MB)</p>
                                        </div>
                                        <input type="file" id="fileInput_<?= $order['orderid'] ?>" accept="image/*"
                                            style="display: none;"
                                            onchange="previewPicture(<?= $order['orderid'] ?>, this.files[0])">
                                    </label>
                                    <div id="previewSection_<?= $order['orderid'] ?>" style="display: none;">
                                        <div class="preview-section">
                                            <p style="margin-bottom: 0.5rem; color: #6c757d; font-size: 0.9rem;">
                                                <strong>Preview:</strong>
                                            </p>
                                            <img id="previewImg_<?= $order['orderid'] ?>" class="preview-image">
                                            <div style="display: flex; gap: 0.5rem;">
                                                <button type="button" class="btn btn-success btn-sm"
                                                    onclick="submitPicture(<?= $order['orderid'] ?>, this)">
                                                    <i class="fas fa-check me-1"></i>Submit Picture
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-sm"
                                                    onclick="cancelPreview(<?= $order['orderid'] ?>)">
                                                    <i class="fas fa-times me-1"></i>Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($order['references'])): ?>
                        <div class="order-info" style="background:#fff7e6;border-left:4px solid #ffc107;margin-top:1rem">
                            <div class="order-info-item"><strong>References</strong></div>
                            <div style="margin-top:0.5rem">
                                <?php foreach ($order['references'] as $ref):
                                    $refName = null; $refImg = null; $refLink = null;
                                    if (!empty($ref['designid'])) {
                                        $did = (int)$ref['designid'];
                                        // fetch design name
                                        $dstmt = $mysqli->prepare('SELECT designName FROM Design WHERE designid = ? LIMIT 1');
                                        if ($dstmt) {
                                            $dstmt->bind_param('i', $did);
                                            $dstmt->execute();
                                            $dres = $dstmt->get_result();
                                            if ($drow = $dres->fetch_assoc()) $refName = $drow['designName'];
                                            $dstmt->close();
                                        }
                                        // fetch primary image
                                        $imst = $mysqli->prepare('SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1');
                                        if ($imst) {
                                            $imst->bind_param('i', $did);
                                            $imst->execute();
                                            $imr = $imst->get_result();
                                            if ($ir = $imr->fetch_assoc()) $refImg = $ir['image_filename'];
                                            $imst->close();
                                        }
                                        $refLink = '../design_detail.php?designid=' . $did;
                                    } elseif (!empty($ref['messageid'])) {
                                        $mid = (int)$ref['messageid'];
                                        $mstmt = $mysqli->prepare('SELECT content, message_type, fileid FROM Message WHERE messageid = ? LIMIT 1');
                                        if ($mstmt) {
                                            $mstmt->bind_param('i', $mid);
                                            $mstmt->execute();
                                            $mres = $mstmt->get_result();
                                            if ($mrow = $mres->fetch_assoc()) {
                                                $ct = $mrow['content'] ?? '';
                                                $mt = $mrow['message_type'] ?? '';
                                                // try to parse JSON content for a share/design id
                                                $maybe = null;
                                                $js = @json_decode($ct, true);
                                                if (is_array($js)) $maybe = $js;
                                                if ($mt === 'design' && !empty($maybe['designid'])) {
                                                    $did = (int)$maybe['designid'];
                                                } elseif (preg_match('/^\d+$/', trim($ct))) {
                                                    $did = (int)trim($ct);
                                                } elseif (!empty($maybe['share']['designid'])) {
                                                    $did = (int)$maybe['share']['designid'];
                                                } else {
                                                    $did = null;
                                                }
                                                if (!empty($did)) {
                                                    // fetch design info
                                                    $dstmt = $mysqli->prepare('SELECT designName FROM Design WHERE designid = ? LIMIT 1');
                                                    if ($dstmt) {
                                                        $dstmt->bind_param('i', $did);
                                                        $dstmt->execute();
                                                        $dres = $dstmt->get_result();
                                                        if ($drow = $dres->fetch_assoc()) $refName = $drow['designName'];
                                                        $dstmt->close();
                                                    }
                                                    $imst = $mysqli->prepare('SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1');
                                                    if ($imst) {
                                                        $imst->bind_param('i', $did);
                                                        $imst->execute();
                                                        $imr = $imst->get_result();
                                                        if ($ir = $imr->fetch_assoc()) $refImg = $ir['image_filename'];
                                                        $imst->close();
                                                    }
                                                    $refLink = '../design_detail.php?designid=' . $did;
                                                }
                                                // if message had uploaded file, try to show that as thumbnail
                                                if (empty($refImg) && !empty($mrow['fileid'])) {
                                                    $fid = (int)$mrow['fileid'];
                                                    $fstmt = $mysqli->prepare('SELECT filepath, filename FROM UploadedFiles WHERE fileid = ? LIMIT 1');
                                                    if ($fstmt) {
                                                        $fstmt->bind_param('i', $fid);
                                                        $fstmt->execute();
                                                        $fres = $fstmt->get_result();
                                                        if ($fr = $fres->fetch_assoc()) {
                                                            // use filepath if points to uploads/designs or uploads/chat
                                                            $path = $fr['filepath'] ?? '';
                                                            if ($path) $refImg = $path;
                                                            if (empty($refName)) $refName = $fr['filename'] ?? null;
                                                        }
                                                        $fstmt->close();
                                                    }
                                                }
                                            }
                                            $mstmt->close();
                                        }
                                    }
                                ?>
                                    <div
                                        style="padding:0.5rem;border:1px solid #ffe8a6;border-radius:6px;margin-bottom:0.5rem;display:flex;align-items:center;gap:8px">
                                        <div style="flex:0 0 72px;">
                                            <?php if (!empty($refImg)): 
                                                $imgSrc = strpos($refImg, '/') === 0 ? ('..' . $refImg) : ('../uploads/designs/' . ltrim($refImg, '/'));
                                            ?>
                                                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="ref" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #ddd" />
                                            <?php else: ?>
                                                <div style="width:64px;height:64px;border-radius:6px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#666;border:1px solid #eee">REF</div>
                                            <?php endif; ?>
                                        </div>
                                        <div style="flex:1">
                                            <?php if (!empty($refLink) && !empty($refName)): ?>
                                                <a href="<?= htmlspecialchars($refLink) ?>" target="_blank" rel="noopener" style="text-decoration:none;color:#333;font-weight:600"><?= htmlspecialchars($refName) ?></a>
                                            <?php elseif (!empty($ref['designid'])): ?>
                                                <a href="../design_detail.php?designid=<?= (int)$ref['designid'] ?>" target="_blank" rel="noopener" style="text-decoration:none;color:#333;font-weight:600">Design #<?= (int)$ref['designid'] ?></a>
                                            <?php elseif (!empty($ref['messageid'])): ?>
                                                <span style="font-weight:600">Message #<?= (int)$ref['messageid'] ?></span>
                                            <?php else: ?>
                                                <span style="font-weight:600">Reference #<?= (int)$ref['id'] ?></span>
                                            <?php endif; ?>
                                            <div class="small text-muted">Added: <?= htmlspecialchars($ref['created_at'] ?? '') ?></div>
                                            <?php if (!empty($ref['note'])): ?><div class="small text-muted mt-1">Note: <?= htmlspecialchars(substr($ref['note'],0,120)) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Client Information -->
                    <div class="order-info">
                        <div class="order-info-item">
                            <span class="order-info-label">Client:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['cname']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Email:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['cemail']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Address:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></span>
                        </div>
                    </div>

                    <div id="actions_<?= $order['orderid'] ?>" style="margin-top:8px">
                        <?php if (strtolower(trim($order['ostatus'] ?? '')) === 'waiting confirm'): ?>
                            <button class="btn btn-sm btn-success"
                                onclick="updateOrder(<?= $order['orderid'] ?>,'confirm', this)">Confirm</button>
                            <button class="btn btn-sm btn-danger ms-1"
                                onclick="updateOrder(<?= $order['orderid'] ?>,'reject', this)">Reject</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="no-orders">
                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; display: block; color: #ccc;"></i>
                <h5>No Orders Yet</h5>
                <p>You don't have any design orders yet. Check back later!</p>
            </div>
        <?php endif; ?>
    </main>

    <!-- Picture Viewer Modal -->
    <div class="modal fade" id="pictureModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Designed Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="pictureImg" src="" alt="Designed Picture" style="max-width: 100%; max-height: 600px;">
                </div>
            </div>
        </div>
    </div>

    <!-- Floor Plan Preview Modal -->
    <div class="modal fade" id="floorPlanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Floor Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="floorPlanImg" src="" alt="Floor Plan"
                        style="max-width:100%;max-height:75vh;border-radius:6px;" />
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        let selectedFiles = {};

        // Handle drag and drop for all upload areas
        document.querySelectorAll('[id^="uploadArea_"]').forEach(area => {
            area.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragover');
            });

            area.addEventListener('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
            });

            area.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');

                const orderId = this.id.replace('uploadArea_', '');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    previewPicture(orderId, files[0]);
                }
            });

            area.addEventListener('click', function () {
                const orderId = this.id.replace('uploadArea_', '');
                document.getElementById('fileInput_' + orderId).click();
            });
        });

        function previewPicture(orderId, file) {
            if (!file) return;

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                alert('Please upload a valid image file (JPG, PNG, GIF, WebP)');
                return;
            }

            // Validate file size (10MB)
            if (file.size > 10 * 1024 * 1024) {
                alert('File size exceeds 10MB limit');
                return;
            }

            // Store the file
            selectedFiles[orderId] = file;

            // Create preview
            const reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('previewImg_' + orderId).src = e.target.result;
                document.getElementById('uploadArea_' + orderId).style.display = 'none';
                document.getElementById('previewSection_' + orderId).style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        function cancelPreview(orderId) {
            delete selectedFiles[orderId];
            document.getElementById('uploadArea_' + orderId).style.display = 'block';
            document.getElementById('previewSection_' + orderId).style.display = 'none';
            document.getElementById('fileInput_' + orderId).value = '';
        }

        function submitPicture(orderId, btn) {
            const file = selectedFiles[orderId];
            if (!file) {
                alert('No file selected');
                return;
            }

            const formData = new FormData();
            formData.append('orderid', orderId);
            formData.append('picture', file);

            const submitBtn = btn || document.querySelector('#previewSection_' + orderId + ' button');
            const originalText = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting...';
            }

            fetch('upload_designed_picture.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Picture submitted successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to submit picture'));
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred: ' + (error.message || error));
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
                });
        }

        function viewPicture(pictureId, filename) {
            document.getElementById('pictureImg').src = '../uploads/designed_Picture/' + filename;
            new bootstrap.Modal(document.getElementById('pictureModal')).show();
        }
        function openFloorPlanPreview(src) {
            try {
                const img = document.getElementById('floorPlanImg');
                img.src = src;
                new bootstrap.Modal(document.getElementById('floorPlanModal')).show();
            } catch (e) { console.error(e); window.open(src, '_blank'); }
        }
    </script>
    <script>
        async function updateOrder(orderId, action, btn) {
            const verb = (action === 'reject') ? 'reject' : 'confirm';
            if (!confirm('Are you sure to ' + verb + ' this order? The change cannot be undone.')) return;
            try {
                btn.disabled = true;
                const res = await fetch('update_order_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ orderid: orderId, action: action })
                });
                const j = await res.json();
                if (j && j.success) {
                    const statusEl = document.getElementById('status_' + orderId);
                    const actionsEl = document.getElementById('actions_' + orderId);
                    if (statusEl) statusEl.innerHTML = '<strong>Status:</strong> ' + (j.status || (action === 'confirm' ? 'Confirmed' : 'Rejected'));
                    if (actionsEl) Array.from(actionsEl.querySelectorAll('button')).forEach(b => b.remove());
                } else {
                    alert('Error: ' + (j && j.message ? j.message : 'Unknown'));
                    btn.disabled = false;
                }
            } catch (e) { console.error(e); alert('Request failed'); btn.disabled = false; }
        }
    </script>
</body>

</html>