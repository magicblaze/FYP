<?php
// ==============================
// File: order_detail.php - Display detailed order information
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: ../login.php?redirect=' . urlencode($current_page));
    exit;
}

$clientId = (int) ($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

// Get order ID from URL
$orderId = (int) ($_GET['orderid'] ?? 0);
if ($orderId <= 0) {
    // Gracefully handle requests without a valid order id (for example URLs like ?designerid=null)
    // Redirect users to the order history page instead of returning a 400 error.
    error_log('[order_detail] missing or invalid orderid. Request URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
}

// Fetch order details with verification that it belongs to the client
// --- MODIFIED: Added o.final_payment to the query ---
$orderSql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.gross_floor_area, o.deposit, o.final_payment,
              d.designid, d.expect_price, d.tag, dz.dname, dz.designerid,
              c.budget
             FROM `Order` o
             JOIN Design d ON o.designid = d.designid
             JOIN Designer dz ON d.designerid = dz.designerid
             JOIN Client c ON o.clientid = c.clientid
             WHERE o.orderid = ? AND o.clientid = ?";
$orderStmt = $mysqli->prepare($orderSql);
$orderStmt->bind_param("ii", $orderId, $clientId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();

if ($orderResult->num_rows === 0) {
    http_response_code(404);
    die('Order not found or access denied.');
}

$order = $orderResult->fetch_assoc();
// --- NEW: Get final payment value ---
$final_payment = isset($order['final_payment']) ? floatval($order['final_payment']) : 0;
// --- END NEW ---

// expose gross floor area
$gfaDisplay = isset($order['gross_floor_area']) ? (float) $order['gross_floor_area'] : 0.0;

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Expose designer id for this order (used when opening chat by designer)
$designerIdForOrder = isset($order['designerid']) ? (int) $order['designerid'] : 0;

// Fetch products/materials for this order
$productsSql = "SELECT od.orderdeliveryid, od.productid, od.quantity, od.managerid, od.status,
                       od.color,
                       p.pname, p.price, p.category, p.description,
                       m.mname as manager_name
                FROM OrderDelivery od
                JOIN Product p ON od.productid = p.productid
                JOIN Manager m ON od.managerid = m.managerid
                WHERE od.orderid = ?
                ORDER BY od.orderdeliveryid ASC";
$productsStmt = $mysqli->prepare($productsSql);
$productsStmt->bind_param("i", $orderId);
$productsStmt->execute();
$products = $productsStmt->get_result();

// Fetch designed pictures for this order
$picturesSql = "SELECT * FROM DesignedPicture WHERE orderid = ? ORDER BY upload_date DESC";
$picturesStmt = $mysqli->prepare($picturesSql);
$picturesStmt->bind_param("i", $orderId);
$picturesStmt->execute();
$pictures = $picturesStmt->get_result();

// Fetch contractors assigned to this order
$contractorsSql = "SELECT oc.order_Contractorid, oc.contractorid, oc.managerid,
                          c.cname as contractor_name, c.ctel, c.cemail, c.price,
                          m.mname as manager_name
                   FROM Order_Contractors oc
                   JOIN Contractors c ON oc.contractorid = c.contractorid
                   JOIN Manager m ON oc.managerid = m.managerid
                   WHERE oc.orderid = ?
                   ORDER BY oc.order_Contractorid ASC";
$contractorsStmt = $mysqli->prepare($contractorsSql);
$contractorsStmt->bind_param("i", $orderId);
$contractorsStmt->execute();
$contractors = $contractorsStmt->get_result();

// Fetch schedule/timeline information
$scheduleSql = "SELECT s.scheduleid, s.managerid, s.OrderFinishDate,
                       m.mname as manager_name
                FROM Schedule s
                JOIN Manager m ON s.managerid = m.managerid
                WHERE s.orderid = ?
                ORDER BY s.scheduleid ASC";
$scheduleStmt = $mysqli->prepare($scheduleSql);
$scheduleStmt->bind_param("i", $orderId);
$scheduleStmt->execute();
$schedules = $scheduleStmt->get_result();

$referencesSql = "SELECT r.id AS orderreferenceid, r.productid, r.designid, r.added_by_type, r.added_by_id, r.created_at,
               p.pname, IFNULL(p.price, 0) as product_price,
               d.designName as design_name, IFNULL(d.expect_price, 0) as design_price,
               pci.product_image, di.design_image
           FROM OrderReference r
           LEFT JOIN Product p ON r.productid = p.productid
           LEFT JOIN Design d ON r.designid = d.designid
           LEFT JOIN (
               SELECT productid, MIN(image) AS product_image
               FROM ProductColorImage
               GROUP BY productid
           ) pci ON r.productid = pci.productid
           LEFT JOIN (
               SELECT designid, MIN(image_filename) AS design_image
               FROM DesignImage
               GROUP BY designid
           ) di ON r.designid = di.designid
           WHERE r.orderid = ?
           ORDER BY r.created_at ASC";
$referencesStmt = $mysqli->prepare($referencesSql);
if (!$referencesStmt) {
    error_log('[order_detail] failed to prepare references SQL: ' . $mysqli->error);
    // Fall back to an empty result set so the page can render without fatal error
    $references = $mysqli->query("SELECT NULL WHERE 0");
} else {
    $referencesStmt->bind_param("i", $orderId);
    $referencesStmt->execute();
    $references = $referencesStmt->get_result();
}

// Handle client accept/reject actions for design proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept: move to 'drafting 2nd proposal'
    if (isset($_POST['accept_design']) && $clientId && $orderId) {
        $next_status = 'drafting 2nd proposal';
        $u_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ? AND clientid = ?";
        $u_stmt = $mysqli->prepare($u_sql);
        if ($u_stmt) {
            $u_stmt->bind_param('sii', $next_status, $orderId, $clientId);
            $u_stmt->execute();
            $u_stmt->close();
        }
        header('Location: order_detail.php?orderid=' . $orderId);
        exit;
    }

    // Reject: move back to 'designing' and optionally record a reason
    if (isset($_POST['reject_design']) && $clientId && $orderId) {
        $next_status = 'designing';
        $reason = trim($_POST['reject_reason'] ?? 'Client rejected the proposal');
        $safeReason = $mysqli->real_escape_string($reason);
        $u_sql = "UPDATE `Order` SET ostatus = ?, Requirements = CONCAT(IFNULL(Requirements,''), 'reject message: ', ?) WHERE orderid = ? AND clientid = ?";
        $u_stmt = $mysqli->prepare($u_sql);
        if ($u_stmt) {
            $u_stmt->bind_param('ssii', $next_status, $safeReason, $orderId, $clientId);
            $u_stmt->execute();
            $u_stmt->close();
        }
        header('Location: order_detail.php?orderid=' . $orderId);
        exit;
    }
}

// Calculate totals
$productTotal = 0;
$productsTemp = $products->fetch_all(MYSQLI_ASSOC);
foreach ($productsTemp as $product) {
    $productTotal += $product['price'] * $product['quantity'];
}
$products->data_seek(0);

// --- NEW: Calculate design total (Design Cost + Final Design Price) ---
$Project_Deposit = 2000; // Fixed project deposit
$design_cost = (float) $order['expect_price'];
$Design_Fee1 = (float) $order['expect_price'] * 0.025; // 1st design fee is 2.5% of expected price
$design_total = $design_cost - $final_payment - $Project_Deposit - $Design_Fee1; // Total design cost after subtracting fees and deposit
// --- END NEW ---

// Format display variables
$budgetDisplay = $order['budget'] ?? 0;
$phoneDisplay = !empty($clientData['ctel']) ? (string) $clientData['ctel'] : '—';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order Detail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-detail-container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin: 1rem auto;
            max-width: 1400px;
        }

        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #3498db;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }

        .section-title {
            color: #2c3e50;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ecf0f1;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border-left: 4px solid #3498db;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 150px;
        }

        .info-value {
            color: #555;
            text-align: right;
            flex: 1;
        }

        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-designing {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background-color: #cce5ff;
            color: #004085;
        }

        .product-table,
        .contractor-table,
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        .product-table thead,
        .contractor-table thead,
        .schedule-table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #ecf0f1;
        }

        .product-table th,
        .contractor-table th,
        .schedule-table th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
        }

        .product-table td,
        .contractor-table td,
        .schedule-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .product-table tbody tr:hover,
        .contractor-table tbody tr:hover,
        .schedule-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .manager-badge {
            display: inline-block;
            background-color: #e8f4f8;
            color: #2c3e50;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .empty-message {
            text-align: center;
            padding: 2rem;
            color: #7f8c8d;
            background-color: #f8f9fa;
            border-radius: 10px;
        }

        .empty-message i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #bdc3c7;
        }

        .price-highlight {
            color: #27ae60;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .design-image-container {
            text-align: center;
            margin-bottom: 1rem;
        }

        .design-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .designed-picture-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .picture-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            transition: all 0.3s;
        }

        .picture-card:hover {
            border-color: #3498db;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.2);
        }

        .picture-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            cursor: pointer;
        }

        .picture-info {
            padding: 1rem;
        }

        .picture-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.5rem;
        }

        .picture-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .picture-actions button {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.85rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-approve {
            background-color: #27ae60;
            color: white;
        }

        .btn-approve:hover {
            background-color: #229954;
        }

        .btn-reject {
            background-color: #e74c3c;
            color: white;
        }

        .btn-reject:hover {
            background-color: #c0392b;
        }

        .rejection-reason {
            background-color: #ffe8e8;
            border-left: 4px solid #e74c3c;
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #c0392b;
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }

            .info-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .info-value {
                text-align: left;
                margin-top: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container mt-4">
        <div class="order-detail-container">
            <a href="order_history.php" class="back-link">
                <i class="fas fa-arrow-left me-1"></i>Back to Order History
            </a>

            <h1 class="page-title">
                <i class="fas fa-receipt me-2"></i>Order #<?= (int) $order['orderid'] ?> Details
            </h1>

            <!-- Order Overview Section -->
            <div class="section-title">
                <i class="fas fa-info-circle me-2"></i>Order Overview
            </div>
            <div class="grid-2">
                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Order ID:</span>
                        <span class="info-value">#<?= (int) $order['orderid'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Order Date:</span>
                        <span class="info-value"><?= date('M d, Y H:i', strtotime($order['odate'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <?php
                            $statusLower = strtolower($order['ostatus'] ?? '');
                            $statusClass = 'status-pending';
                            if (strpos($statusLower, 'design') !== false) {
                                $statusClass = 'status-designing';
                            } elseif (strpos($statusLower, 'complet') !== false) {
                                $statusClass = 'status-completed';
                            } elseif (strpos($statusLower, 'cancel') !== false) {
                                $statusClass = 'status-cancelled';
                            }
                            ?>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= htmlspecialchars($order['ostatus'] ?? 'waiting confirm') ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Budget:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format((float) $order['budget'], 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Gross Floor Area:</span>
                        <span
                            class="info-value"><?= $gfaDisplay > 0 ? htmlspecialchars(number_format((float) $gfaDisplay, 2)) . ' m²' : '&mdash;' ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Designer:</span>
                        <span class="info-value"><?= htmlspecialchars($order['dname']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Design Cost:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format((float) $order['expect_price'], 2) ?></span>
                    </div>
                    <!-- --- NEW: Display Final Design Payment --- -->
                    <div class="info-row">
                        <span class="info-label">Project Deposit:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($Project_Deposit, 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">1st Design Fee (designer 2.5%):</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($Design_Fee1, 2) ?></span>
                    </div>
                    <?php if ($final_payment <> 0): ?>
                    <div class="info-row">
                        <span class="info-label">2nd Design Fee:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($final_payment, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <!-- --- END NEW --- -->
                    <!-- --- NEW: Display Design Total (Design Cost + Final Design Payment) --- -->
                    <div class="info-row" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                        <span class="info-label"><strong>Design Total:</strong></span>
                        <span
                            class="info-value price-highlight"><strong>$<?= number_format($design_total, 2) ?></strong></span>
                    </div>
                    <!-- --- END NEW --- -->
                    <div class="info-row">
                        <span class="info-label">Design Tags:</span>
                        <span class="info-value">
                            <?php
                            $tags = array_filter(array_map('trim', explode(',', $order['tag'] ?? '')));
                            foreach ($tags as $tag) {
                                echo '<span class="badge bg-primary me-1">' . htmlspecialchars($tag) . '</span>';
                            }
                            ?>
                        </span>
                    </div>

                </div>
            </div>

            <!-- Designed Picture Section -->
            <div class="section-title">
                <i class="fas fa-image me-2"></i>Designed Pictures
            </div>
            <?php if ($pictures->num_rows > 0): ?>
                <div class="designed-picture-gallery">
                    <?php while ($picture = $pictures->fetch_assoc()): ?>
                        <div class="picture-card">
                            <img src="../uploads/designed_Picture/<?= htmlspecialchars($picture['filename']) ?>"
                                alt="Designed Picture" class="picture-image"
                                onclick="viewPicture('<?= htmlspecialchars($picture['filename']) ?>')">
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-image"></i>
                    <p>No designed pictures uploaded yet.</p>
                </div>
            <?php endif; ?>

            <?php if (strtolower(trim($order['ostatus'] ?? '')) === 'waiting payment'): ?>
                <div class="info-card" style="border-left:4px solid #27ae60;">
                    <div class="info-row" style="border-bottom:none;">
                        <span class="info-label"><i class="fas fa-check-circle me-2"></i>Proposal Confirmed</span>
                        <span class="info-value">
                            <a href="profile.php" class="btn btn-success btn-sm">
                                <i class="fas fa-paper-plane me-1"></i>Submit
                            </a>
                        </span>
                    </div>
                    <div class="small text-muted" style="margin-top:0.5rem;">Manager confirmed your proposal. Please submit
                        your payment method in your profile, then proceed.</div>
                </div>
            <?php endif; ?>

            <!-- Requirements Section -->
            <?php if (!empty($order['Requirements'])): ?>
                <div class="section-title">
                    <i class="fas fa-clipboard-list me-2"></i>Requirements
                </div>
                <div class="info-card">
                    <p class="mb-0"><?= htmlspecialchars($order['Requirements']) ?></p>
                </div>
            <?php endif; ?>

            <!-- References Section -->
            <?php if ($references->num_rows > 0): ?>
                <div class="section-title">
                    <i class="fas fa-link me-2"></i>Product References
                </div>
                <div class="info-card">
                    <?php
                    $referencesTotal = 0;
                    while ($ref = $references->fetch_assoc()):
                        // product reference
                        if (!empty($ref['productid']) && !empty($ref['pname'])):
                            $price = (float) ($ref['product_price'] ?? 0);
                            $referencesTotal += $price;
                            ?>
                            <div class="info-row">
                                <div class="info-label" style="display:flex;align-items:center;gap:8px;">
                                    <?php
                                    $pimg = $ref['product_image'] ?? '';
                                    if (!empty($pimg)) {
                                        $pimgSrc = $pimg;
                                        if (!preg_match('#^https?://#i', $pimgSrc)) {
                                            $pimgSrc = ($pimgSrc[0] === '/') ? ('..' . $pimgSrc) : ('../uploads/products/' . ltrim($pimgSrc, '/'));
                                        }
                                        echo '<img src="' . htmlspecialchars($pimgSrc) . '" alt="product" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #ddd;" />';
                                    } else {
                                        echo '<div style="width:36px;height:36px;border-radius:6px;background:#f1f3f5;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:0.7rem;color:#888;">IMG</div>';
                                    }
                                    ?>
                                    <span><i class="fas fa-box me-2"></i><?= htmlspecialchars($ref['pname']) ?></span>
                                </div>
                                <div class="info-value">
                                    <span class="price-highlight">$<?= number_format($price, 2) ?></span>
                                </div>
                            </div>
                            <?php
                            // design reference
                        elseif (!empty($ref['designid']) && !empty($ref['design_name'])):
                            $dprice = (float) ($ref['design_price'] ?? 0);
                            $referencesTotal += $dprice;
                            ?>
                            <div class="info-row">
                                <div class="info-label" style="display:flex;align-items:center;gap:8px;">
                                    <?php
                                    $dimg = $ref['design_image'] ?? '';
                                    if (!empty($dimg)) {
                                        $dimgSrc = $dimg;
                                        if (!preg_match('#^https?://#i', $dimgSrc)) {
                                            $dimgSrc = ($dimgSrc[0] === '/') ? ('..' . $dimgSrc) : ('../uploads/designs/' . ltrim($dimgSrc, '/'));
                                        }
                                        echo '<img src="' . htmlspecialchars($dimgSrc) . '" alt="design" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #ddd;" />';
                                    } else {
                                        echo '<div style="width:36px;height:36px;border-radius:6px;background:#f1f3f5;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:0.7rem;color:#888;">IMG</div>';
                                    }
                                    ?>
                                    <span><i class="fas fa-image me-2"></i><?= htmlspecialchars($ref['design_name']) ?> (Design
                                        Reference)</span>
                                </div>
                                <div class="info-value">
                                    <span class="price-highlight">$<?= number_format($dprice, 2) ?></span>
                                </div>
                            </div>
                            <?php
                        endif;
                    endwhile;
                    ?>
                    <?php if ($referencesTotal > 0): ?>
                        <div class="info-row" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                            <span class="info-label"><strong>References Total:</strong></span>
                            <span
                                class="info-value price-highlight"><strong>$<?= number_format($referencesTotal, 2) ?></strong></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Products/Materials Section -->
            <div class="section-title">
                <i class="fas fa-box me-2"></i>References products & materials
            </div>
            <?php if ($products->num_rows > 0): ?>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Color</th>
                            <th>Category</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Assigned Manager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($product = $products->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($product['pname']) ?></strong>
                                    <?php if (!empty($product['description'])): ?>
                                        <br><small
                                            class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 60)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $colorName = $product['color'] ?? 'N/A';
                                    $colorHex = match (strtolower($colorName)) {
                                        'red' => '#FF0000',
                                        'blue' => '#0000FF',
                                        'green' => '#008000',
                                        'yellow' => '#FFFF00',
                                        'black' => '#000000',
                                        'white' => '#FFFFFF',
                                        'grey' => '#808080',
                                        'gray' => '#808080',
                                        'orange' => '#FFA500',
                                        'purple' => '#800080',
                                        'pink' => '#FFC0CB',
                                        'brown' => '#A52A2A',
                                        'beige' => '#F5F5DC',
                                        'navy' => '#000080',
                                        'silver' => '#C0C0C0',
                                        'gold' => '#FFD700',
                                        'cyan' => '#00FFFF',
                                        'magenta' => '#FF00FF',
                                        default => '#CCCCCC'
                                    };
                                    ?>
                                    <div style="display: inline-block; width: 30px; height: 30px; background-color: <?= $colorHex ?>; border: 1px solid #999; border-radius: 4px; title='<?= htmlspecialchars($colorName) ?>'"
                                        title="<?= htmlspecialchars($colorName) ?>"></div>
                                </td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td class="price-highlight">$<?= number_format((float) $product['price'], 2) ?></td>
                                <td><?= (int) $product['quantity'] ?></td>
                                <td class="price-highlight">
                                    $<?= number_format((float) $product['price'] * $product['quantity'], 2) ?></td>
                                <td>
                                    <?php
                                    $status = $product['status'] ?? 'Pending';
                                    $statusClass = match ($status) {
                                        'Completed' => 'badge bg-success',
                                        'In Progress' => 'badge bg-info',
                                        'Pending' => 'badge bg-warning',
                                        'Cancelled' => 'badge bg-danger',
                                        default => 'badge bg-secondary'
                                    };
                                    ?>
                                    <span class="<?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                                <td>
                                    <span class="manager-badge">
                                        <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($product['manager_name']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Products Total:</span>
                        <span class="info-value price-highlight">$<?= number_format($productTotal, 2) ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-box"></i>
                    <p>No products or materials assigned yet.</p>
                </div>
            <?php endif; ?>

            <!-- Summary Section -->
            <div class="section-title">
                <i class="fas fa-chart-bar me-2"></i>Order Summary
            </div>
            <div class="info-card">
                <div class="info-row">
                    <span class="info-label">Design Cost:</span>
                    <span
                        class="info-value price-highlight">$<?= number_format((float) $order['expect_price'], 2) ?></span>
                </div>
                <div class="info-row">
                        <span class="info-label">Project Deposit:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($Project_Deposit, 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">1st Design Fee (designer 2.5%):</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($Design_Fee1, 2) ?></span>
                    </div>
                    <?php if ($final_payment <> 0): ?>
                    <div class="info-row">
                        <span class="info-label">2nd Design Fee:</span>
                        <span
                            class="info-value price-highlight">$<?= number_format($final_payment, 2) ?></span>
                    </div>
                    <?php endif; ?>
                <div class="info-row" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                    <span class="info-label"><strong>Design Total:</strong></span>
                    <span
                        class="info-value price-highlight"><strong>$<?= number_format($design_total, 2) ?></strong></span>
                </div>
                <!-- --- END NEW --- -->
                <div class="info-row">
                    <span class="info-label">Budget Allocated:</span>
                    <span class="info-value price-highlight">$<?= number_format((float) $order['budget'], 2) ?></span>
                </div>
            </div>

            <!-- Back Button -->
            <div style="margin-top: 2rem; text-align: center;">
                <?php if ($statusLower === 'waiting for review design'): ?>
                    <form id="client_action_form" method="post" style="display:inline-block;">
                        <input type="hidden" name="reject_reason" id="reject_reason_input" value="" />
                        <button type="submit" name="accept_design" class="btn btn-success me-2" onclick="return confirm('Accept this proposal and proceed to drafting 2nd proposal?');">
                            <i class="fas fa-check me-1"></i>Accept
                        </button>
                        <button type="button" id="reject_btn" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                    </form>
                    <script>
                        (function(){
                            const rejectBtn = document.getElementById('reject_btn');
                            const form = document.getElementById('client_action_form');
                            const reasonInput = document.getElementById('reject_reason_input');
                            if (rejectBtn && form && reasonInput) {
                                rejectBtn.addEventListener('click', function(){
                                    const r = prompt('Please enter a reason for rejection (optional):');
                                    if (r === null) return; // user cancelled
                                    reasonInput.value = r || 'Client rejected the proposal';
                                    // set a hidden input to indicate rejection and submit
                                    const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='reject_design'; hidden.value='1'; form.appendChild(hidden);
                                    form.submit();
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>
                <a href="order_history.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Order History
                </a>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let rejectModalPictureId = null;

        function viewPicture(filename) {
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:2000;';
            modal.innerHTML = '<div style="max-width:90%;max-height:90%;"><img src="../uploads/designed_Picture/' + filename + '" style="max-width:100%;max-height:100%;border-radius:8px;" onclick="this.parentElement.parentElement.remove();"><p style="color:white;text-align:center;margin-top:1rem;cursor:pointer;" onclick="this.parentElement.parentElement.remove();">Click to close</p></div>';
            document.body.appendChild(modal);
        }
    </script>
</body>

</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>