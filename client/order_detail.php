<?php
// ==============================
// File: order_detail.php - Display detailed order information
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    error_log('[order_detail] missing or invalid orderid. Request URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
}
// Get message from URL
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';

// ========== Handle Inspection Actions ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept inspection
    if (isset($_POST['accept_inspection'])) {
        $update_sql = "UPDATE `Order` SET inspection_status = 'accepted', client_suggested_date = NULL WHERE orderid = ? AND clientid = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $orderId, $clientId);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Notify manager
        $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                       VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has accepted the inspection for Order #$orderId.', 'inspection_accepted', NOW())";
        $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
        mysqli_stmt_bind_param($notify_stmt, "ii", $orderId, $orderId);
        mysqli_stmt_execute($notify_stmt);
        mysqli_stmt_close($notify_stmt);
        
        header("Location: order_detail.php?orderid=" . $orderId . "&msg=inspection_accepted");
        exit;
    }
    
    // Reject inspection with suggested time
    if (isset($_POST['reject_inspection'])) {
        $suggested_date = $_POST['suggested_date'] ?? '';
        $suggested_time = $_POST['suggested_time'] ?? '';
        
        if (!empty($suggested_date) && !empty($suggested_time)) {
            $suggested_datetime = $suggested_date . ' ' . $suggested_time . ':00';
            $update_sql = "UPDATE `Order` SET 
                           inspection_status = 'client_suggested',
                           client_suggested_date = ?
                           WHERE orderid = ? AND clientid = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "sii", $suggested_datetime, $orderId, $clientId);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            // Notify manager
            $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                           VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has suggested a new inspection time for Order #$orderId: " . date('Y-m-d H:i', strtotime($suggested_datetime)) . "', 'inspection_suggested', NOW())";
            $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
            mysqli_stmt_bind_param($notify_stmt, "ii", $orderId, $orderId);
            mysqli_stmt_execute($notify_stmt);
            mysqli_stmt_close($notify_stmt);
            
            header("Location: order_detail.php?orderid=" . $orderId . "&msg=inspection_suggested");
            exit;
        }
    }
    
    // Confirm inspection report and proceed to payment
    if (isset($_POST['confirm_inspection_report'])) {
        // Check if already confirmed
        $check_sql = "SELECT COUNT(*) as cnt FROM InspectionConfirmation WHERE orderid = ? AND clientid = ?";
        $check_stmt = mysqli_prepare($mysqli, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ii", $orderId, $clientId);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $check_row = mysqli_fetch_assoc($check_result);
        $already_confirmed = ($check_row['cnt'] > 0);
        mysqli_stmt_close($check_stmt);
        
        if (!$already_confirmed) {
            // Insert confirmation record
            $insert_confirm_sql = "INSERT INTO InspectionConfirmation (orderid, clientid, confirmed_at) VALUES (?, ?, NOW())";
            $insert_confirm_stmt = mysqli_prepare($mysqli, $insert_confirm_sql);
            mysqli_stmt_bind_param($insert_confirm_stmt, "ii", $orderId, $clientId);
            mysqli_stmt_execute($insert_confirm_stmt);
            mysqli_stmt_close($insert_confirm_stmt);
        }
        
        // Update order status to waiting for final construction payment
        $update_sql = "UPDATE `Order` SET ostatus = 'waiting 1st construction phase payment' WHERE orderid = ? AND clientid = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $orderId, $clientId);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Notify manager
        $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                       VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has confirmed the inspection report for Order #$orderId and is ready for final payment.', 'inspection_confirmed', NOW())";
        $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
        mysqli_stmt_bind_param($notify_stmt, "ii", $orderId, $orderId);
        mysqli_stmt_execute($notify_stmt);
        mysqli_stmt_close($notify_stmt);
        
        header("Location: order_detail.php?orderid=" . $orderId . "&msg=inspection_confirmed");
        exit;
    }
    
    // Confirm project completion after inspection (legacy)
    if (isset($_POST['confirm_project_completion'])) {
        $update_sql = "UPDATE `Order` SET ostatus = 'complete' WHERE orderid = ? AND clientid = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $orderId, $clientId);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        header("Location: payment_construction3.php?orderid=" . $orderId);
        exit;
    }
}
// ========== End Inspection Handling ==========

// Fetch order details with verification that it belongs to the client
$orderSql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.gross_floor_area, o.deposit,
              o.supplierid, o.supplier_status,
              d.designid, d.expect_price, d.tag, dz.dname, dz.designerid,
              c.budget,
              op.payment_id, op.total_cost,
              op.design_fee_designer_1st, op.design_fee_designer_2nd,
              op.design_fee_manager_1st, op.design_fee_manager_2nd,
              op.design_deposit, op.commission_1st,
              op.construction_main_price, op.construction_deposit, op.materials_cost,
              op.inspection_fee, op.contractor_fee, op.commission_final,
              op.additional_fees,
              op.total_design_payment, op.total_construction_payment,
              op.total_amount_due, op.total_amount_paid, op.payment_status
             FROM `Order` o
             JOIN Design d ON o.designid = d.designid
             JOIN Designer dz ON d.designerid = dz.designerid
             JOIN Client c ON o.clientid = c.clientid
             LEFT JOIN OrderPayment op ON o.payment_id = op.payment_id
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

$payment = [
    'total_cost' => isset($order['total_cost']) ? (float) $order['total_cost'] : 0.0,
    'design_fee_designer_1st' => isset($order['design_fee_designer_1st']) ? (float) $order['design_fee_designer_1st'] : 0.0,
    'design_fee_designer_2nd' => isset($order['design_fee_designer_2nd']) ? (float) $order['design_fee_designer_2nd'] : 0.0,
    'design_fee_manager_1st' => isset($order['design_fee_manager_1st']) ? (float) $order['design_fee_manager_1st'] : 0.0,
    'design_fee_manager_2nd' => isset($order['design_fee_manager_2nd']) ? (float) $order['design_fee_manager_2nd'] : 0.0,
    'design_deposit' => isset($order['design_deposit']) ? (float) $order['design_deposit'] : 0.0,
    'commission_1st' => isset($order['commission_1st']) ? (float) $order['commission_1st'] : 0.0,
    'construction_main_price' => isset($order['construction_main_price']) ? (float) $order['construction_main_price'] : 0.0,
    'construction_deposit' => isset($order['construction_deposit']) ? (float) $order['construction_deposit'] : 0.0,
    'materials_cost' => isset($order['materials_cost']) ? (float) $order['materials_cost'] : 0.0,
    'inspection_fee' => isset($order['inspection_fee']) ? (float) $order['inspection_fee'] : 0.0,
    'contractor_fee' => isset($order['contractor_fee']) ? (float) $order['contractor_fee'] : 0.0,
    'commission_final' => isset($order['commission_final']) ? (float) $order['commission_final'] : 0.0,
    'additional_fees' => isset($order['additional_fees']) ? (float) $order['additional_fees'] : 0.0,
    'total_design_payment' => isset($order['total_design_payment']) ? (float) $order['total_design_payment'] : 0.0,
    'total_construction_payment' => isset($order['total_construction_payment']) ? (float) $order['total_construction_payment'] : 0.0,
    'total_amount_due' => isset($order['total_amount_due']) ? (float) $order['total_amount_due'] : 0.0,
    'total_amount_paid' => isset($order['total_amount_paid']) ? (float) $order['total_amount_paid'] : 0.0,
    'payment_status' => isset($order['payment_status']) ? (string) $order['payment_status'] : 'pending',
];

$commissionTotal = $payment['commission_1st'] + $payment['commission_final'];
$gfaDisplay = isset($order['gross_floor_area']) ? (float) $order['gross_floor_area'] : 0.0;

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

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
    $references = $mysqli->query("SELECT NULL WHERE 0");
} else {
    $referencesStmt->bind_param("i", $orderId);
    $referencesStmt->execute();
    $references = $referencesStmt->get_result();
}

// Handle client accept/reject actions for design proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['client_confirm_proposal'])) {
        // ... existing logic if any ...
    }
    if (isset($_POST['accept_design']) && $clientId && $orderId) {
        $next_status = 'waiting 2nd design phase payment';
        $u_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ? AND clientid = ?";
        $u_stmt = $mysqli->prepare($u_sql);
        if ($u_stmt) {
            $u_stmt->bind_param('sii', $next_status, $orderId, $clientId);
            $u_stmt->execute();
            $u_stmt->close();
        }
        header('Location: payment2.php?orderid=' . $orderId);
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

$design_cost = (float) $order['expect_price'];
$design_total = $payment['total_design_payment'];

// Format display variables
$budgetDisplay = $order['budget'] ?? 0;
$phoneDisplay = !empty($clientData['ctel']) ? (string) $clientData['ctel'] : '—';

// Get inspection data for display
$inspection_sql = "SELECT inspection_date, inspection_status, client_suggested_date, ostatus 
                   FROM `Order` WHERE orderid = ? AND clientid = ?";
$inspection_stmt = $mysqli->prepare($inspection_sql);
$inspection_stmt->bind_param("ii", $orderId, $clientId);
$inspection_stmt->execute();
$inspection_result = $inspection_stmt->get_result();
$inspection_data = $inspection_result->fetch_assoc();
$inspection_stmt->close();

$inspection_date = $inspection_data['inspection_date'] ?? null;
$inspection_status = $inspection_data['inspection_status'] ?? null;
$client_suggested_date = $inspection_data['client_suggested_date'] ?? null;
$current_ostatus = $inspection_data['ostatus'] ?? '';

// Check if report exists (both pass and fail)
$has_report_sql = "SELECT COUNT(*) as cnt FROM InspectionReport WHERE orderid = ? AND result IN ('pass', 'fail')";
$has_report_stmt = $mysqli->prepare($has_report_sql);
$has_report_stmt->bind_param("i", $orderId);
$has_report_stmt->execute();
$has_report_result = $has_report_stmt->get_result();
$has_report_row = $has_report_result->fetch_assoc();
$has_inspection_report = ($has_report_row['cnt'] > 0);
$has_report_stmt->close();

// Show inspection report if report exists OR status is inspection_completed OR inspection_failed
$show_inspection_report = ($has_inspection_report || $current_ostatus == 'inspection_completed' || $current_ostatus == 'inspection_failed');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Project Detail</title>
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
                <i class="fas fa-arrow-left me-1"></i>Back to Project History
            </a>
            <?php if (isset($_GET['shared'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Project has been shared to other Contractor for review.
                </div>
            <?php endif; ?>

            <h1 class="page-title">
                <i class="fas fa-receipt me-2"></i>Project #<?= (int) $order['orderid'] ?> Details
            </h1>

            <!-- Project Overview Section -->
            <div class="section-title">
                <i class="fas fa-info-circle me-2"></i>Project Overview
            </div>
            <div class="grid-2">
                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Project ID:</span>
                        <span class="info-value">#<?= (int) $order['orderid'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Project Date:</span>
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
                    <div class="info-row">
                        <span class="info-label">Constructor Assignment:</span>
                        <span class="info-value">
                            <span class="badge <?php echo (($order['supplier_status'] ?? '') === 'Rejected') ? 'bg-danger' : (((($order['supplier_status'] ?? '') === 'Accepted') ? 'bg-success' : 'bg-warning text-dark')); ?>">
                                <?php echo htmlspecialchars($order['supplier_status'] ?? 'Pending'); ?>
                            </span>
                        </span>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Designer:</span>
                        <span class="info-value"><?= htmlspecialchars($order['dname']) ?></span>
                    </div>
                    <div class="info-row" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                        <span class="info-label">Design Cost:</span>
                        <span class="info-value price-highlight">$<?= number_format($design_total, 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Construction Cost:</span>
                        <span class="info-value price-highlight">$<?= number_format($payment['total_construction_payment'], 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Commission:</span>
                        <span class="info-value price-highlight">$<?= number_format($commissionTotal, 2) ?></span>
                    </div>
                    <div class="info-row" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                        <span class="info-label">Total Cost:</span>
                        <span class="info-value price-highlight">$<?= number_format($payment['total_amount_due'], 2) ?></span>
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

            <?php if (in_array(strtolower(trim($order['ostatus'] ?? '')), ['waiting payment', 'waiting design phase payment', 'waiting 2nd design phase payment', 'waiting final design phase payment'], true)): ?>
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
            
            <!-- Inspection Confirmation Section for Client -->
            <?php if ($msg == 'inspection_accepted'): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i>Inspection accepted successfully!</div>
            <?php elseif ($msg == 'inspection_suggested'): ?>
                <div class="alert alert-info mb-3"><i class="fas fa-calendar-alt me-2"></i>Your suggested time has been sent to the manager.</div>
            <?php elseif ($msg == 'inspection_confirmed'): ?>
                <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i>Inspection report confirmed! You can now proceed to final payment.</div>
            <?php endif; ?>

            <!-- Inspection Status Display -->
            <?php if ($inspection_status == 'pending' && $inspection_date): ?>
            <div class="section-title">
                <i class="fas fa-calendar-check me-2"></i>Inspection Appointment
            </div>
            <div class="info-card">
                <div class="text-center mb-4">
                    <h5>Proposed Inspection Date & Time:</h5>
                    <h3 class="text-primary">
                        <?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?>
                    </h3>
                </div>
                
                <div class="d-flex gap-3">
                    <form method="POST" style="flex:1">
                        <button type="submit" name="accept_inspection" class="btn btn-success w-100" 
                                onclick="return confirm('Accept this inspection date and time?');">
                            <i class="fas fa-check-circle me-2"></i>Accept
                        </button>
                    </form>
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#suggestTimeModal">
                        <i class="fas fa-calendar-alt me-2"></i>Suggest New Time
                    </button>
                </div>
            </div>

            <!-- Suggest Time Modal -->
            <div class="modal fade" id="suggestTimeModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header bg-warning">
                                <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Suggest New Inspection Time</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="reject_inspection" value="1">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Proposed Date</label>
                                    <input type="date" name="suggested_date" class="form-control" 
                                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Proposed Time</label>
                                    <select name="suggested_time" class="form-select" required>
                                        <option value="">-- Select Time --</option>
                                        <option value="09:00:00">09:00 AM</option>
                                        <option value="10:00:00">10:00 AM</option>
                                        <option value="11:00:00">11:00 AM</option>
                                        <option value="12:00:00">12:00 PM</option>
                                        <option value="13:00:00">01:00 PM</option>
                                        <option value="14:00:00">02:00 PM</option>
                                        <option value="15:00:00">03:00 PM</option>
                                        <option value="16:00:00">04:00 PM</option>
                                        <option value="17:00:00">05:00 PM</option>
                                    </select>
                                </div>
                                <p class="text-muted small">The manager will review your suggestion and confirm.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-warning">Send Suggestion</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php elseif ($inspection_status == 'client_suggested'): ?>
            <div class="section-title">
                <i class="fas fa-clock me-2"></i>Inspection Status
            </div>
            <div class="info-card">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Your suggested time has been sent to the manager. Waiting for confirmation.
                    <?php if ($client_suggested_date): ?>
                        <br><small>Suggested: <?php echo date('F d, Y h:i A', strtotime($client_suggested_date)); ?></small>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($inspection_status == 'accepted' && $inspection_date): ?>
            <div class="section-title">
                <i class="fas fa-check-circle me-2"></i>Inspection Confirmed
            </div>
            <div class="info-card">
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle me-2"></i>
                    Inspection scheduled for: <strong><?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?></strong>
                </div>
            </div>
            
            <?php elseif ($inspection_status == 'rejected' && $inspection_date): ?>
            <div class="section-title">
                <i class="fas fa-calendar-alt me-2"></i>Inspection Rescheduled
            </div>
            <div class="info-card">
                <div class="text-center mb-4">
                    <h5>Manager Proposed New Inspection Date & Time:</h5>
                    <h3 class="text-warning">
                        <?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?>
                    </h3>
                </div>
                
                <div class="d-flex gap-3">
                    <form method="POST" style="flex:1">
                        <button type="submit" name="accept_inspection" class="btn btn-success w-100" 
                                onclick="return confirm('Accept this inspection date and time?');">
                            <i class="fas fa-check-circle me-2"></i>Accept
                        </button>
                    </form>
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#suggestTimeModal">
                        <i class="fas fa-calendar-alt me-2"></i>Suggest New Time
                    </button>
                </div>
            </div>
                        <?php endif; ?>
            
<?php if ($show_inspection_report): ?>
<div class="section-title">
    <i class="fas fa-file-alt me-2"></i>Inspection Report
</div>
<div class="info-card">
    <?php
    // Fetch inspection report - 同时获取 pass 和 fail 的结果
    $report_sql = "SELECT * FROM InspectionReport WHERE orderid = ? AND result IN ('pass', 'fail') ORDER BY submitted_at DESC LIMIT 1";
    $report_stmt = $mysqli->prepare($report_sql);
    $report_stmt->bind_param("i", $orderId);
    $report_stmt->execute();
    $report_result = $report_stmt->get_result();
    $inspection_report = $report_result->fetch_assoc();
    $report_stmt->close();
    
    if ($inspection_report):
        $report_result_value = $inspection_report['result'] ?? 'pass';
        $is_report_fail = ($report_result_value == 'fail');
        
        // Decode file paths
        $attached_files = [];
        if (!empty($inspection_report['file_paths'])) {
            $attached_files = json_decode($inspection_report['file_paths'], true);
            if (!is_array($attached_files)) {
                $attached_files = [];
            }
        }
        
        // Check if client has already confirmed (only for pass reports)
        $has_confirmed = false;
        if (!$is_report_fail) {
            $confirm_check_sql = "SELECT COUNT(*) as cnt FROM InspectionConfirmation WHERE orderid = ? AND clientid = ?";
            $confirm_check_stmt = $mysqli->prepare($confirm_check_sql);
            $confirm_check_stmt->bind_param("ii", $orderId, $clientId);
            $confirm_check_stmt->execute();
            $confirm_check_result = $confirm_check_stmt->get_result();
            $confirm_check_row = mysqli_fetch_assoc($confirm_check_result);
            $has_confirmed = ($confirm_check_row['cnt'] > 0);
            $confirm_check_stmt->close();
        }
    ?>
    
    <!-- 显示报告状态横幅 -->
    <?php if ($is_report_fail): ?>
        <div class="alert alert-danger mb-3">
            <i class="fas fa-times-circle me-2 fa-lg"></i>
            <strong>Inspection Failed</strong><br>
            The inspection was completed on <?php echo date('F d, Y h:i A', strtotime($inspection_report['submitted_at'])); ?> with result: FAILED.
            Please contact the manager for further discussion.
        </div>
    <?php else: ?>
        <div class="alert alert-success mb-3">
            <i class="fas fa-check-circle me-2"></i>
            <strong>Inspection Completed</strong><br>
            Inspection was completed on <?php echo date('F d, Y h:i A', strtotime($inspection_report['submitted_at'])); ?>
        </div>
    <?php endif; ?>
    
    <!-- Inspection Report Content -->
    <div class="mb-4">
        <div class="card border-<?php echo $is_report_fail ? 'danger' : 'primary'; ?>">
            <div class="card-header bg-<?php echo $is_report_fail ? 'danger' : 'primary'; ?> text-white">
                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Inspection Report Details</h5>
            </div>
            <div class="card-body">
                <!-- 显示结果 -->
                <div class="mb-3">
                    <strong><i class="fas fa-clipboard-list me-2"></i>Result:</strong>
                    <span class="badge <?php echo $is_report_fail ? 'bg-danger' : 'bg-success'; ?> ms-2">
                        <?php echo $is_report_fail ? 'FAILED' : 'PASSED'; ?>
                    </span>
                </div>
                
                <?php if (!empty($inspection_report['report_content'])): ?>
                    <div class="mb-3">
                        <strong><i class="fas fa-pen me-2"></i>Report Content:</strong>
                        <div class="border rounded p-3 bg-light mt-2" style="white-space: pre-wrap;">
                            <?php echo nl2br(htmlspecialchars($inspection_report['report_content'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($attached_files)): ?>
                    <div class="mb-3">
                        <strong><i class="fas fa-paperclip me-2"></i>Attached Files:</strong>
                        <div class="row mt-2">
                            <?php foreach ($attached_files as $file): 
                                $file_url = "../" . $file;
                                $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                            ?>
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="card h-100">
                                        <?php if (in_array($file_ext, $image_extensions)): ?>
                                            <img src="<?php echo $file_url; ?>" class="card-img-top" alt="Inspection Image" style="height: 150px; object-fit: cover; cursor: pointer;" onclick="viewImage('<?php echo $file_url; ?>')">
                                        <?php else: ?>
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-alt fa-3x text-secondary mb-2"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="card-body p-2 text-center">
                                            <a href="<?php echo $file_url; ?>" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                                                <i class="fas fa-download me-1"></i> <?php echo basename($file); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 只有 PASS 的报告才显示确认和支付按钮 -->
    <?php if (!$is_report_fail): ?>
        <?php if (!$has_confirmed): ?>
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Please Review the Inspection Report</strong><br>
                After reviewing the inspection report, click the confirm button below to complete the project and proceed to final payment.
            </div>
            
            <div class="text-center mt-4">
                <form method="POST" onsubmit="return confirm('Have you reviewed the inspection report? Confirm to proceed to final payment.');">
                    <input type="hidden" name="confirm_inspection_report" value="1">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-check-circle me-2"></i>Confirm & Proceed to Final Payment
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-success text-center">
                <i class="fas fa-check-circle me-2 fa-lg"></i>
                <strong>You have confirmed the inspection report!</strong><br>
                <a href="payment_construction2.php?orderid=<?php echo $orderId; ?>" class="btn btn-primary mt-2">
                    <i class="fas fa-credit-card me-2"></i>Go to Final Payment
                </a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- 如果是 FAIL 的报告，只显示联系管理员的提示 -->
        <div class="alert alert-warning text-center mt-3">
            <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
            <strong>Inspection Failed</strong><br>
            The inspection did not pass. Please contact the manager to discuss the next steps or required modifications.
            <hr>
            <a href="order_history.php" class="btn btn-outline-secondary mt-2">
                <i class="fas fa-arrow-left me-2"></i>Back to Projects
            </a>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-clock me-2"></i>
        Inspection report is being prepared. You will be notified when available.
    </div>
<?php endif; ?>
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
                                    <span><i class="fas fa-image me-2"></i><?= htmlspecialchars($ref['design_name']) ?> (Design Reference)</span>
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
                            <span class="info-value price-highlight"><strong>$<?= number_format($referencesTotal, 2) ?></strong></span>
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
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 60)) ?></small>
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
                                    <div style="display: inline-block; width: 30px; height: 30px; background-color: <?= $colorHex ?>; border: 1px solid #999; border-radius: 4px;" title="<?= htmlspecialchars($colorName) ?>"></div>
                                </td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td class="price-highlight">$<?= number_format((float) $product['price'], 2) ?></td>
                                <td><?= (int) $product['quantity'] ?></td>
                                <td class="price-highlight">$<?= number_format((float) $product['price'] * $product['quantity'], 2) ?></td>
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

            <!-- Back Button -->
            <div class="d-flex justify-content-start mt-4">
                <?php if ($statusLower === 'waiting for review design'): ?>
                    <form id="client_action_form" method="post" class="d-flex gap-2">
                        <input type="hidden" name="reject_reason" id="reject_reason_input" value="" />
                        <button type="submit" name="accept_design" class="btn btn-success me-2" onclick="return confirm('Accept this proposal and proceed to payment?');">
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
                                    if (r === null) return;
                                    reasonInput.value = r || 'Client rejected the proposal';
                                    const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='reject_design'; hidden.value='1'; form.appendChild(hidden);
                                    form.submit();
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>
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
        
        function viewImage(imageUrl) {
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center;z-index:9999;cursor:pointer;';
            modal.innerHTML = '<div style="max-width:90%;max-height:90%;"><img src="' + imageUrl + '" style="max-width:100%;max-height:100%;border-radius:8px;"><p style="color:white;text-align:center;margin-top:1rem;">Click anywhere to close</p></div>';
            modal.onclick = function() { this.remove(); };
            document.body.appendChild(modal);
        }
    </script>
</body>

</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>