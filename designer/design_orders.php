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

// Product list for reference selection
$productMap = [];
$productOptionsHtml = '';
$prodRes = $mysqli->query("SELECT productid, pname, category FROM Product ORDER BY pname");
if ($prodRes) {
    while ($prod = $prodRes->fetch_assoc()) {
        $pid = (int) $prod['productid'];
        $pname = $prod['pname'] ?? '';
        $pcat = $prod['category'] ?? '';
        $productMap[$pid] = $pname;
        $label = $pname;
        if (!empty($pcat)) {
            $label .= ' (' . $pcat . ')';
        }
        $productOptionsHtml .= '<option value="' . $pid . '">' . htmlspecialchars($label) . '</option>';
    }
}

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
    // Get order references (with images)
    $row['references'] = [];
    $refSql = "
        SELECT r.*, 
               (SELECT image FROM ProductColorImage WHERE productid = r.productid LIMIT 1) as product_image,
               (SELECT image_filename FROM DesignImage WHERE designid = r.designid ORDER BY image_order ASC LIMIT 1) as design_image
        FROM OrderReference r 
        WHERE r.orderid = ? 
        ORDER BY r.created_at ASC
    ";
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
$anyCanEdit = false;
foreach ($orders as $o) {
    if (strtolower(trim($o['ostatus'] ?? '')) === 'designing') {
        $anyCanEdit = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Orders - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        .order-title-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            gap: 1rem;
        }

        .status-pill {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #fdebd0;
            color: #b9770e;
        }

        .action-bar {
            position: sticky;
            bottom: 0;
            background: #fff;
            padding: 0.75rem 0.5rem;
            border-top: 1px solid #e9ecef;
            box-shadow: 0 -6px 16px rgba(0, 0, 0, 0.04);
            border-radius: 0 0 10px 10px;
        }

        .section-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .muted-hint {
            color: #6c757d;
            font-size: 0.85rem;
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

        .delete-picture-btn {
            background: #e74c3c !important;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .delete-picture-btn:hover {
            background: #c0392b !important;
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

        .edit-mode-toggle {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }

        .edit-mode-toggle.off {
            background: #3498db;
            color: white;
        }

        .edit-mode-toggle.off:hover {
            background: #2980b9;
        }

        .edit-mode-toggle.save {
            background: #27ae60;
            color: white;
        }

        .edit-mode-toggle.save:hover {
            background: #219150;
        }

        .edit-mode-toggle.cancel {
            background: #e74c3c;
            color: white;
        }

        .edit-mode-toggle.cancel:hover {
            background: #c0392b;
        }

        .upload-section-hidden {
            display: none;
        }

        .order-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .design-items-info {
            background: #e3f2fd;
            border-left: 4px solid #3498db;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            flex: 1;
        }

        .design-items-info-title {
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 0.5rem;
        }

        .design-items-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .design-item-badge {
            background: #bbdefb;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            color: #0d47a1;
            font-weight: 500;
        }

        .reference-item {
            padding: 0.75rem;
            border: 1px solid #ffe8a6;
            border-radius: 6px;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s;
        }

        .reference-item:hover {
            background: #fffbf0;
        }

        .reference-thumbnail {
            flex: 0 0 64px;
        }

        .reference-content {
            flex: 1;
        }

        .reference-actions {
            flex: 0 0 auto;
            display: flex;
            gap: 0.5rem;
        }

        .reference-actions button {
            padding: 4px 8px;
            font-size: 0.8rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ref-delete-btn {
            background: #e74c3c;
            color: white;
        }

        .ref-delete-btn:hover {
            background: #c0392b;
        }

        .add-reference-form {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            display: none;
        }

        .add-reference-form.active {
            display: block;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 0.95rem;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .form-actions button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary-custom {
            background: #3498db;
            color: white;
        }

        .btn-primary-custom:hover {
            background: #2980b9;
        }

        .btn-secondary-custom {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary-custom:hover {
            background: #7f8c8d;
        }

        .liked-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1055;
        }

        .liked-modal-backdrop.show {
            display: flex;
        }

        .liked-modal {
            background: #fff;
            border-radius: 10px;
            width: min(900px, 92vw);
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .liked-modal-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .liked-modal-body {
            padding: 1rem;
            overflow: auto;
        }

        .liked-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.75rem;
        }

        .liked-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.5rem;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .liked-thumb {
            width: 100%;
            height: 110px;
            border-radius: 6px;
            background: #f1f3f5;
            background-size: cover;
            background-position: center;
        }

        .liked-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: #212529;
        }

        .liked-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- Dashboard Content -->
    <main class="container-lg mt-4">
        <div class="mt-5 mb-4 text-center">
            <?php if ($anyCanEdit): ?>
                <h2>Proposal Drafter</h2>
            <?php else: ?>
                <h2>Order Detail</h2>
            <?php endif; ?>
            <p class="mb-0"></p>
        </div>

        <?php if (count($orders) > 0): ?>

            <?php foreach ($orders as $order): ?>
                <?php $canEdit = (strtolower(trim($order['ostatus'] ?? '')) === 'designing'); ?>
                <div class="order-card">
                    <!-- Order Title and Status (Main Focus) -->
                    <div class="order-title-bar">
                        <div>
                            <h3 style="margin: 0; color: #2c3e50;">Order #<?= $order['orderid'] ?></h3>
                            <div style="font-size: 1.1rem; color: #3498db; font-weight: 600; margin-top: 0.25rem;">
                                Status: <span id="status_<?= $order['orderid'] ?>" class="status-pill"><?= htmlspecialchars($order['ostatus']) ?></span>
                            </div>
                        </div>
                        <div>
                            <?php if ($canEdit): ?>
                                <button class="edit-mode-toggle off" id="editBtn_<?= $order['orderid'] ?>" onclick="toggleEditMode(<?= $order['orderid'] ?>)">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                                <button class="edit-mode-toggle save" id="saveBtn_<?= $order['orderid'] ?>" onclick="saveEditMode(<?= $order['orderid'] ?>)" style="display:none;">
                                    <i class="fas fa-save me-1"></i>Save
                                </button>
                                <button class="edit-mode-toggle cancel" id="cancelBtn_<?= $order['orderid'] ?>" onclick="toggleEditMode(<?= $order['orderid'] ?>)" style="display:none;">
                                    <i class="fas fa-times me-1"></i>Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Client Information (Who the order is for) -->
                    <div class="order-info" style="background: #e8f8f5; border-left: 4px solid #16a085; margin-bottom: 1rem;">
                        <div style="display: flex; gap: 2rem;">
                            <div style="flex: 1;">
                                <div class="order-info-item">
                                    <span class="order-info-label"><i class="fas fa-user me-1"></i>Client:</span>
                                    <span class="order-info-value"><?= htmlspecialchars($order['cname']) ?></span>
                                </div>
                                <div class="order-info-item">
                                    <span class="order-info-label"><i class="fas fa-envelope me-1"></i>Email:</span>
                                    <span class="order-info-value"><?= htmlspecialchars($order['cemail']) ?></span>
                                </div>
                            </div>
                            <div style="flex: 1;">
                                <div class="order-info-item">
                                    <span class="order-info-label"><i class="fas fa-map-marker-alt me-1"></i>Address:</span>
                                    <span class="order-info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Details (Key Information) -->
                    <div class="order-details" style="margin-bottom: 1rem;">
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-calendar me-1"></i>Order Date</div>
                            <div class="detail-value"><?= date('M d, Y H:i', strtotime($order['odate'])) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-money-bill-wave me-1"></i>Budget</div>
                            <div class="detail-value">HK$<?= number_format($order['budget']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-tag me-1"></i>Expected Price</div>
                            <div class="detail-value">HK$<?= number_format($order['expect_price']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-ruler-combined me-1"></i>Floor Area</div>
                            <div class="detail-value"><?= isset($order['gross_floor_area']) && $order['gross_floor_area'] > 0 ? htmlspecialchars(number_format((float)$order['gross_floor_area'],2)) . ' m²' : '&mdash;' ?></div>
                        </div>
                    </div>

                    <!-- Requirements Section -->
                    <?php if (!empty($order['Requirements'])): ?>
                        <div class="order-info" style="background-color: #f3e5f5; border-left: 4px solid #9c27b0; margin-bottom: 1rem;">
                            <div class="order-info-item">
                                <span class="order-info-label"><i class="fas fa-list me-1"></i>Customer Requirements:</span>
                            </div>
                            <div style="margin-top: 0.5rem; padding: 0.75rem; background: white; border-radius: 4px; color: #212529;">
                                <?= htmlspecialchars($order['Requirements']) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Floor Plan Section -->
                    <?php if (!empty($order['Floor_Plan'])): ?>
                        <div class="order-info" style="background-color: #e3f2fd; border-left: 4px solid #3498db; margin-bottom: 1rem;">
                            <div class="order-info-item">
                                <span class="order-info-label"><i class="fas fa-map me-1"></i>Floor Plan</span>
                            </div>
                            <?php $floorPlanSrc = '../' . ltrim($order['Floor_Plan'], '/'); ?>
                            <img src="<?= htmlspecialchars($floorPlanSrc) ?>" alt="Floor Plan"
                                title="Click to preview floor plan" class="ms-2"
                                style="width:100px;height:100px;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid #e3f2fd; margin-top: 0.5rem;"
                                onclick="event.stopPropagation(); openFloorPlanPreview(<?= htmlspecialchars(json_encode($floorPlanSrc), ENT_QUOTES, 'UTF-8') ?>)" />
                        </div>
                    <?php endif; ?>

                    <!-- Design picture Section -->
                    <div class="designed-picture-section" style="margin-top: 1.5rem;">
                        <h6 class="mb-2 section-title"><i class="fas fa-file-alt me-2"></i>Design Picture</h6>
                        <div class="muted-hint mb-3">Upload images or PDF files for client review.</div>

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
                                                onclick="viewPicture(<?= (int) $pic['pictureid'] ?>, <?= htmlspecialchars(json_encode($pic['filename']), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                        <div class="picture-actions picture-delete-actions" data-order="<?= $order['orderid'] ?>" style="display: none;">
                                            <button class="delete-picture-btn"
                                                onclick="deletePicture(<?= (int) $pic['pictureid'] ?>, <?= $order['orderid'] ?>)">
                                                <i class="fas fa-trash"></i> Delete
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
                            <p class="text-muted mb-3">No design proposals uploaded yet.</p>
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
                                <?php /* Pending picture exists - hide old message */ ?>
                            <?php else: ?>
                                <div id="uploadContainer_<?= $order['orderid'] ?>" class="upload-section-hidden">
                                    <label class="upload-area" id="uploadArea_<?= $order['orderid'] ?>">
                                        <div>
                                            <i class="fas fa-cloud-upload-alt"
                                                style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                            <strong>Click to upload or drag & drop</strong>
                                            <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP, PDF (Max 10MB)</p>
                                        </div>
                                        <input type="file" id="fileInput_<?= $order['orderid'] ?>" accept="image/*,.pdf"
                                            style="display: none;"
                                            onchange="previewPicture(<?= $order['orderid'] ?>, this.files[0])">
                                    </label>
                                    <div id="previewSection_<?= $order['orderid'] ?>" style="display: none;">
                                        <div class="preview-section">
                                            <p style="margin-bottom: 0.5rem; color: #6c757d; font-size: 0.9rem;">
                                                <strong>Preview:</strong>
                                            </p>
                                            <img id="previewImg_<?= $order['orderid'] ?>" class="preview-image">
                                            <div class="text-muted small">The proposal will be uploaded when you click Save.</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($order['references'])): ?>
                        <div class="order-info" style="background:#fff7e6;border-left:4px solid #ffc107;margin-top:1rem">
                            <div class="order-info-item" style="display: flex; justify-content: space-between; align-items: center;">
                                <strong><i class="fas fa-link me-2"></i>References (<?= count($order['references']) ?>)</strong>
                                <button class="btn btn-sm btn-warning" id="refAddBtn_<?= $order['orderid'] ?>" onclick="toggleReferenceEdit(<?= $order['orderid'] ?>, true)" style="display: none;">
                                    <i class="fas fa-plus me-1"></i>Add Reference
                                </button>
                            </div>
                            <div style="margin-top:0.5rem">
                                <?php foreach ($order['references'] as $ref):
                                    $refName = null; $refImg = null; $refLink = null;
                                    if (!empty($ref['productid'])) {
                                        $pid = (int)$ref['productid'];
                                        $refName = $productMap[$pid] ?? ('Product #' . $pid);
                                        $refLink = '../product_detail.php?id=' . $pid;
                                        // Use pre-fetched image
                                    if (!empty($ref['product_image'])) {
                                        $refImg = '../uploads/products/' . $ref['product_image']; 
                                    }
                                    } elseif (!empty($ref['designid'])) {
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
                                        // Use pre-fetched image
                                        if (!empty($ref['design_image'])) {
                                            $refImg = '../uploads/designs/' . $ref['design_image'];
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
                                    <div class="reference-item">
                                        <div class="reference-thumbnail">
                                            <?php if (!empty($refImg)): 
                                                if (strpos($refImg, '..') === 0) {
                                                    $imgSrc = $refImg;
                                                } elseif (strpos($refImg, 'uploads/') === 0) {
                                                    $imgSrc = '../' . $refImg;
                                                } elseif (strpos($refImg, '/') === 0) {
                                                    $imgSrc = '..' . $refImg;
                                                } else {
                                                    $imgSrc = '../uploads/designs/' . $refImg;
                                                }
                                            ?>
                                                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="ref" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #ddd" />
                                            <?php else: ?>
                                                <div style="width:64px;height:64px;border-radius:6px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#666;border:1px solid #eee;font-size:0.8rem;">REF</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="reference-content">
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
                                        <div class="reference-actions ref-actions" data-order="<?= $order['orderid'] ?>" style="display: none;">
                                            <button class="ref-delete-btn" onclick="deleteReference(<?= $order['orderid'] ?>, <?= (int)$ref['id'] ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Add Reference Form -->
                            <div class="add-reference-form" id="addRefForm_<?= $order['orderid'] ?>">
                                <h6 style="margin-bottom: 1rem;"><i class="fas fa-plus-circle me-2"></i>Add New Reference</h6>
                                <div class="form-group">
                                    <label>Material / Product</label>
                                    <select id="refProductId_<?= $order['orderid'] ?>">
                                        <option value="">-- Select a product --</option>
                                        <?= $productOptionsHtml ?>
                                    </select>
                                    <div style="margin-top:0.5rem;">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openLikedModal(<?= $order['orderid'] ?>)">
                                            <i class="fas fa-thumbs-up me-1"></i>Pick from liked items
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Note (optional)</label>
                                    <textarea id="refNote_<?= $order['orderid'] ?>" placeholder="Add a note for this reference..."></textarea>
                                </div>
                                <div class="form-actions">
                                    <button class="btn-primary-custom" onclick="queueReference(<?= $order['orderid'] ?>)">Add Reference</button>
                                </div>
                                <div id="pendingRefs_<?= $order['orderid'] ?>" class="mt-2"></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="order-info" style="background:#e8f5e9;border-left:4px solid #4caf50;margin-top:1rem">
                            <div class="order-info-item" style="display: flex; justify-content: space-between; align-items: center;">
                                <strong><i class="fas fa-link me-2"></i>References</strong>
                                <button class="btn btn-sm btn-success" id="refAddBtn_<?= $order['orderid'] ?>" onclick="toggleReferenceEdit(<?= $order['orderid'] ?>, true)" style="display: none;">
                                    <i class="fas fa-plus me-1"></i>Add Reference
                                </button>
                            </div>
                            <div style="margin-top:0.5rem; color: #558b2f;">
                                <i class="fas fa-info-circle me-2"></i>No references yet. Click "Add Reference" to add one.
                            </div>
                            
                            <!-- Add Reference Form -->
                            <div class="add-reference-form" id="addRefForm_<?= $order['orderid'] ?>">
                                <h6 style="margin-bottom: 1rem;"><i class="fas fa-plus-circle me-2"></i>Add New Reference</h6>
                                <div class="form-group">
                                    <label>Material / Product</label>
                                    <select id="refProductId_<?= $order['orderid'] ?>">
                                        <option value="">-- Select a product --</option>
                                        <?= $productOptionsHtml ?>
                                    </select>
                                    <div style="margin-top:0.5rem;">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openLikedModal(<?= $order['orderid'] ?>)">
                                            <i class="fas fa-thumbs-up me-1"></i>Pick from liked items
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Note (optional)</label>
                                    <textarea id="refNote_<?= $order['orderid'] ?>" placeholder="Add a note for this reference..."></textarea>
                                </div>
                                <div class="form-actions">
                                    <button class="btn-primary-custom" onclick="queueReference(<?= $order['orderid'] ?>)">Add Reference</button>
                                </div>
                                <div id="pendingRefs_<?= $order['orderid'] ?>" class="mt-2"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div id="actions_<?= $order['orderid'] ?>" class="action-bar" style="margin-top:1.5rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                        <div>
                            <?php 
                                $status = strtolower(trim($order['ostatus'] ?? ''));
                                $canSubmit = in_array($status, ['designing']);
                                $hasPictures = !empty($order['pictures']);
                                $hasReferences = !empty($order['references']);
                                $disableSubmit = !$hasPictures || !$hasReferences;
                            ?>
                            <?php if ($canSubmit): ?>
                                <button class="btn <?php echo $disableSubmit ? 'btn-secondary' : 'btn-primary'; ?>" onclick="updateOrder(<?= $order['orderid'] ?>,'submit_proposal', this)" <?php if ($disableSubmit): ?>disabled title="Add at least one proposal and one reference before submitting."<?php endif; ?>>
                                    <i class="fas fa-paper-plane me-1"></i>Submit Proposal
                                </button>
                            <?php endif; ?>
                        </div>
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

    <!-- Liked Items Modal -->
    <div id="likedModal" class="liked-modal-backdrop" aria-hidden="true">
        <div class="liked-modal" role="dialog" aria-label="Liked items">
            <div class="liked-modal-header">
                <strong><i class="fas fa-thumbs-up me-2"></i>Liked Materials / Products</strong>
                <button type="button" class="btn btn-sm btn-light" onclick="closeLikedModal()">Close</button>
            </div>
            <div class="liked-modal-body">
                <div id="likedModalStatus" class="small text-muted mb-2">Loading...</div>
                <div id="likedModalGrid" class="liked-grid"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        // Edit mode state tracker
        const editModes = {};

        function toggleEditMode(orderId) {
            const editBtn = document.getElementById('editBtn_' + orderId);
            const saveBtn = document.getElementById('saveBtn_' + orderId);
            const cancelBtn = document.getElementById('cancelBtn_' + orderId);
            const uploadContainer = document.getElementById('uploadContainer_' + orderId);
            const refAddBtn = document.getElementById('refAddBtn_' + orderId);
            const refActionBtns = document.querySelectorAll('.ref-actions[data-order="' + orderId + '"]');
            const pictureDeleteBtns = document.querySelectorAll('.picture-delete-actions[data-order="' + orderId + '"]');

            if (!editBtn || !saveBtn) return;

            editModes[orderId] = !editModes[orderId];

            if (editModes[orderId]) {
                editBtn.style.display = 'none';
                saveBtn.style.display = 'inline-block';
                if (cancelBtn) cancelBtn.style.display = 'inline-block';
                if (uploadContainer) uploadContainer.classList.remove('upload-section-hidden');
                if (refAddBtn) refAddBtn.style.display = 'inline-block';
                refActionBtns.forEach(el => el.style.display = 'flex');
                pictureDeleteBtns.forEach(el => el.style.display = 'flex');
            } else {
                editBtn.style.display = 'inline-block';
                saveBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                if (uploadContainer) uploadContainer.classList.add('upload-section-hidden');
                if (refAddBtn) refAddBtn.style.display = 'none';
                refActionBtns.forEach(el => el.style.display = 'none');
                pictureDeleteBtns.forEach(el => el.style.display = 'none');
                cancelPreview(orderId);
                toggleReferenceEdit(orderId, false);
            }
        }

        async function saveEditMode(orderId) {
            const previewSection = document.getElementById('previewSection_' + orderId);
            const refForm = document.getElementById('addRefForm_' + orderId);
            const productSelect = document.getElementById('refProductId_' + orderId);
            const hasPendingUpload = !!selectedFiles[orderId];
            const isPreviewVisible = previewSection && previewSection.style.display !== 'none';
            const isRefFormActive = refForm && refForm.classList.contains('active');
            const hasSelectedProduct = productSelect && productSelect.value;
            const pending = pendingReferences[orderId] || [];

            let attemptMade = false;
            let successCount = 0;

            if (hasPendingUpload && isPreviewVisible) {
                attemptMade = true;
                const res = await submitPicture(orderId, document.querySelector('#previewSection_' + orderId + ' button'), false);
                if (res) successCount++;
            }

            if (pending.length) {
                attemptMade = true;
                for (const ref of pending) {
                    const res = await addReference(orderId, false, ref.productId, ref.note);
                    if (res) successCount++;
                }
            } else if (isRefFormActive && hasSelectedProduct) {
                attemptMade = true;
                const res = await addReference(orderId, false);
                if (res) successCount++;
            }

            if (successCount > 0) {
                location.reload();
                return;
            }

            if (!attemptMade) {
                alert('Please upload a picture or add a reference before saving.');
                return;
            }
            
            // If we are here, we attempted but failed (errors already alerted).
            // Do NOT toggle edit mode. Stay in edit mode so user can retry.
        }

        let selectedFiles = {};
        let pendingReferences = {};

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

            // Prevent default label click behavior - the hidden file input will handle it via onchange
            area.addEventListener('click', function (e) {
                // Prevent event from bubbling if click is on the label itself
                if (e.target === this) {
                    e.preventDefault();
                    const orderId = this.id.replace('uploadArea_', '');
                    document.getElementById('fileInput_' + orderId).click();
                }
            });
        });

        function previewPicture(orderId, file) {
            if (!file) return;

            // Validate file type (images and PDF)
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                alert('Please upload a valid file (JPG, PNG, GIF, WebP, or PDF)');
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
                // Handle PDF preview differently
                if (file.type === 'application/pdf') {
                    document.getElementById('previewImg_' + orderId).style.display = 'none';
                    let pdfPreview = document.getElementById('pdfPreview_' + orderId);
                    if (!pdfPreview) {
                        pdfPreview = document.createElement('div');
                        pdfPreview.id = 'pdfPreview_' + orderId;
                        pdfPreview.style.cssText = 'background: #f0f0f0; padding: 2rem; text-align: center; border-radius: 6px; margin-bottom: 1rem;';
                        document.getElementById('previewSection_' + orderId).insertBefore(pdfPreview, document.querySelector('#previewSection_' + orderId + ' p'));
                    }
                    pdfPreview.innerHTML = '<i class="fas fa-file-pdf" style="font-size: 3rem; color: #e74c3c; margin-bottom: 1rem; display: block;"></i><strong>PDF File Selected</strong><p style="margin: 0.5rem 0 0 0; color: #6c757d; font-size: 0.9rem;">' + file.name + '</p>';
                } else {
                    let pdfPreview = document.getElementById('pdfPreview_' + orderId);
                    if (pdfPreview) pdfPreview.remove();
                    document.getElementById('previewImg_' + orderId).src = e.target.result;
                    document.getElementById('previewImg_' + orderId).style.display = 'block';
                }
                document.getElementById('uploadArea_' + orderId).style.display = 'none';
                document.getElementById('previewSection_' + orderId).style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        function cancelPreview(orderId) {
            delete selectedFiles[orderId];
            const uploadArea = document.getElementById('uploadArea_' + orderId);
            const previewSection = document.getElementById('previewSection_' + orderId);
            const fileInput = document.getElementById('fileInput_' + orderId);
            if (uploadArea) uploadArea.style.display = 'block';
            if (previewSection) previewSection.style.display = 'none';
            if (fileInput) fileInput.value = '';
            // Clear PDF preview if exists
            let pdfPreview = document.getElementById('pdfPreview_' + orderId);
            if (pdfPreview) pdfPreview.remove();
            // Clear image preview
            const previewImg = document.getElementById('previewImg_' + orderId);
            if (previewImg) previewImg.style.display = 'block';
        }

        function submitPicture(orderId, btn, shouldReload = true) {
            const file = selectedFiles[orderId];
            if (!file) {
                alert('No file selected');
                return Promise.resolve(false);
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

            return fetch('upload_designed_picture.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (shouldReload) {
                            alert('Picture submitted successfully!');
                            location.reload();
                        }
                        return true;
                    } else {
                        alert('Error: ' + (data.message || 'Failed to submit'));
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
                        return false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred: ' + (error.message || error));
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
                    return false;
                });
        }

        function viewPicture(pictureId, filename) {
            window.open('../uploads/designed_Picture/' + filename, '_blank');
        }

        function deletePicture(pictureId, orderId) {
            if (!confirm('Are you sure you want to delete this picture? This action cannot be undone.')) {
                return;
            }

            fetch('delete_designed_picture.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pictureid: pictureId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the picture item from DOM
                    const pictureItems = document.querySelectorAll('.picture-item');
                    pictureItems.forEach(item => {
                        const deleteBtn = item.querySelector('button[onclick*="deletePicture(' + pictureId + ',"]');
                        if (deleteBtn) {
                            item.remove();
                        }
                    });

                    // Check if there are any pictures left in the gallery
                    const gallery = document.querySelector('.picture-gallery');
                    if (gallery && gallery.children.length === 0) {
                        // Remove the gallery and show the "no pictures" message
                        const parent = gallery.parentElement;
                        gallery.remove();
                        
                        // Create and insert the "no pictures" message
                        const noMsg = document.createElement('p');
                        noMsg.className = 'text-muted mb-3';
                        noMsg.textContent = 'No design proposals uploaded yet.';
                        parent.insertBefore(noMsg, parent.querySelector('.mt-3'));
                    }

                    // Show upload container if in edit mode
                    const uploadContainer = document.getElementById('uploadContainer_' + orderId);
                    if (uploadContainer) {
                        // Make sure it exists and is visible in edit mode
                        if (editModes[orderId]) {
                            uploadContainer.classList.remove('upload-section-hidden');
                            uploadContainer.style.display = 'block';
                        }
                    } else {
                        // If upload container doesn't exist, create it
                        const uploadSection = document.querySelector('.designed-picture-section .mt-3');
                        if (uploadSection && editModes[orderId]) {
                            const newUploadContainer = document.createElement('div');
                            newUploadContainer.id = 'uploadContainer_' + orderId;
                            newUploadContainer.innerHTML = `
                                <label class="upload-area" id="uploadArea_${orderId}">
                                    <div>
                                        <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                        <strong>Click to upload or drag & drop</strong>
                                        <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP, PDF (Max 10MB)</p>
                                    </div>
                                    <input type="file" id="fileInput_${orderId}" accept="image/*,.pdf" style="display: none;" onchange="previewPicture(${orderId}, this.files[0])">
                                </label>
                                <div id="previewSection_${orderId}" style="display: none;">
                                    <div class="preview-section">
                                        <p style="margin-bottom: 0.5rem; color: #6c757d; font-size: 0.9rem;"><strong>Preview:</strong></p>
                                        <img id="previewImg_${orderId}" class="preview-image">
                                        <div class="text-muted small">The proposal will be uploaded when you click Save.</div>
                                    </div>
                                </div>
                            `;
                            uploadSection.insertBefore(newUploadContainer, uploadSection.firstChild);
                            
                            // Re-attach drag and drop handlers
                            const newUploadArea = document.getElementById('uploadArea_' + orderId);
                            if (newUploadArea) {
                                newUploadArea.addEventListener('dragover', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    this.classList.add('dragover');
                                });
                                newUploadArea.addEventListener('dragleave', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    this.classList.remove('dragover');
                                });
                                newUploadArea.addEventListener('drop', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    this.classList.remove('dragover');
                                    const files = e.dataTransfer.files;
                                    if (files.length > 0) {
                                        previewPicture(orderId, files[0]);
                                    }
                                });
                                newUploadArea.addEventListener('click', function (e) {
                                    if (e.target === this) {
                                        e.preventDefault();
                                        document.getElementById('fileInput_' + orderId).click();
                                    }
                                });
                            }
                        }
                    }

                    alert('Picture deleted successfully! You can now upload a new picture.');
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete picture'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + (error.message || error));
            });
        }
        function openFloorPlanPreview(src) {
            try {
                const img = document.getElementById('floorPlanImg');
                img.src = src;
                new bootstrap.Modal(document.getElementById('floorPlanModal')).show();
            } catch (e) { console.error(e); window.open(src, '_blank'); }
        }

        // Reference management functions
        function toggleReferenceEdit(orderId, show) {
            const form = document.getElementById('addRefForm_' + orderId);
            if (!form) return;
            if (show) {
                form.classList.add('active');
            } else {
                form.classList.remove('active');
                // Clear form
                const productSelect = document.getElementById('refProductId_' + orderId);
                if (productSelect) productSelect.value = '';
                document.getElementById('refNote_' + orderId).value = '';
            }
        }

        function addReference(orderId, shouldReload = true, productIdOverride = null, noteOverride = null) {
            const productId = (productIdOverride !== null)
                ? String(productIdOverride).trim()
                : document.getElementById('refProductId_' + orderId).value.trim();
            const note = (noteOverride !== null)
                ? String(noteOverride).trim()
                : document.getElementById('refNote_' + orderId).value.trim();

            if (!productId) {
                alert('Please select a material or product');
                return Promise.resolve(false);
            }

            return fetch('manage_reference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add',
                    orderid: orderId,
                    productid: productId ? parseInt(productId) : null,
                    note: note
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (shouldReload) {
                        alert('Reference added successfully');
                        location.reload();
                    }
                    return true;
                } else {
                    alert('Error: ' + (data.message || 'Failed to add reference'));
                    return false;
                }
            })
            .catch(err => {
                console.error(err);
                alert('Request failed: ' + err.message);
                return false;
            });
        }

        function queueReference(orderId) {
            const select = document.getElementById('refProductId_' + orderId);
            const noteInput = document.getElementById('refNote_' + orderId);
            const pendingBox = document.getElementById('pendingRefs_' + orderId);
            if (!select || !pendingBox) return;

            const productId = select.value.trim();
            const note = noteInput ? noteInput.value.trim() : '';
            if (!productId) {
                alert('Please select a material or product');
                return;
            }

            if (!pendingReferences[orderId]) pendingReferences[orderId] = [];
            const option = select.options[select.selectedIndex];
            pendingReferences[orderId].push({ productId, note, label: option ? option.textContent : 'Product' });

            if (pendingBox) {
                const item = document.createElement('div');
                item.className = 'small text-muted';
                item.textContent = `Pending: ${option ? option.textContent : productId}${note ? ' — ' + note : ''}`;
                pendingBox.appendChild(item);
            }

            select.value = '';
            if (noteInput) noteInput.value = '';
        }

        function deleteReference(orderId, refId) {
            if (!confirm('Are you sure you want to delete this reference?')) return;

            fetch('manage_reference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    refid: refId,
                    orderid: orderId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Reference deleted successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete reference'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Request failed: ' + err.message);
            });
        }

        let likedModalOrderId = null;

        function openLikedModal(orderId) {
            likedModalOrderId = orderId;
            const modal = document.getElementById('likedModal');
            if (!modal) return;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            loadLikedProducts();
        }

        function closeLikedModal() {
            const modal = document.getElementById('likedModal');
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }

        async function loadLikedProducts() {
            const status = document.getElementById('likedModalStatus');
            const grid = document.getElementById('likedModalGrid');
            if (!status || !grid) return;
            status.textContent = 'Loading...';
            grid.innerHTML = '';
            try {
                const res = await fetch('../Public/get_chat_suggestions.php');
                const data = await res.json();
                if (data && data.error) {
                    status.textContent = (data.error === 'not_logged_in') ? 'Please log in to see your liked items.' : ('Error: ' + data.error);
                    return;
                }
                const likedProducts = (data && data.liked_products) ? data.liked_products : [];
                if (!likedProducts.length) {
                    status.textContent = 'No liked products found.';
                    return;
                }
                status.textContent = 'Click a card to select.';
                likedProducts.forEach(p => {
                    const card = document.createElement('div');
                    card.className = 'liked-card';
                    const thumb = document.createElement('div');
                    thumb.className = 'liked-thumb';
                    if (p.image) thumb.style.backgroundImage = 'url(' + p.image + ')';
                    const title = document.createElement('div');
                    title.className = 'liked-title';
                    title.textContent = p.title || p.pname || 'Product';
                    const meta = document.createElement('div');
                    meta.className = 'liked-meta';
                    meta.textContent = p.category ? ('Category: ' + p.category) : 'Liked product';
                    const btn = document.createElement('button');
                    btn.className = 'btn btn-sm btn-outline-primary';
                    btn.textContent = 'Select';
                    btn.addEventListener('click', () => {
                        if (!likedModalOrderId) return;
                        const select = document.getElementById('refProductId_' + likedModalOrderId);
                        if (select) {
                            select.value = p.id || p.productid || p.productId || '';
                        }
                        closeLikedModal();
                    });
                    card.appendChild(thumb);
                    card.appendChild(title);
                    card.appendChild(meta);
                    card.appendChild(btn);
                    grid.appendChild(card);
                });
            } catch (e) {
                console.error(e);
                status.textContent = 'Failed to load liked items.';
            }
        }
    </script>
    <script>
        async function updateOrder(orderId, action, btn) {
            let verb = action;
            if (action === 'submit_proposal') verb = 'submit proposal for';
            
            if (!confirm('Are you sure you want to ' + verb + ' this order? The change cannot be undone.')) return;
            try {
                btn.disabled = true;
                const res = await fetch('update_order_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ orderid: orderId, action: action })
                });
                const j = await res.json();
                if (j && j.success) {
                    alert('Order status updated successfully.');
                    location.reload();
                } else {
                    alert('Error: ' + (j && j.message ? j.message : 'Unknown'));
                    btn.disabled = false;
                }
            } catch (e) { console.error(e); alert('Request failed'); btn.disabled = false; }
        }
    </script>
</body>

</html>