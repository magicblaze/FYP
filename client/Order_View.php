<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Only allow logged-in clients
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$client_id = $user['clientid'];

$orderid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Verify order exists and belongs to this client
$check_order_sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.deposit, o.final_payment,
               COALESCE(NULLIF(o.budget, 0), op.total_cost, 0) AS order_budget,
               c.clientid, c.cname as client_name, c.ctel, c.cemail,
               d.designid, d.designName, d.expect_price as design_price, d.tag as design_tag,
               s.scheduleid, s.OrderFinishDate, s.DesignFinishDate,
               des.dname as designer_name, des.status as designer_status,
         IFNULL(op.total_cost, 0) * ((IFNULL(op.design_fee_designer_1st_pct, 0) + IFNULL(op.design_fee_designer_2nd_pct, 0) + IFNULL(op.design_fee_manager_1st_pct, 0) + IFNULL(op.design_fee_manager_2nd_pct, 0)) / 100) AS total_design_payment,
         IFNULL(op.total_cost, 0) * (IFNULL(op.construction_main_pct, 0) / 100) AS total_construction_payment,
         IFNULL(op.total_cost, 0) * (IFNULL(op.commission_1st_pct, 0) / 100) AS commission_1st,
         IFNULL(op.total_cost, 0) * (IFNULL(op.commission_final_pct, 0) / 100) AS commission_final,
         IFNULL(op.total_cost, 0) AS total_amount_due,
         IFNULL(op.total_cost, 0) * (IFNULL(op.inspection_pct, 0) / 100) AS inspection_fee,
         IFNULL(op.total_cost, 0) * (IFNULL(op.contractor_pct, 0) / 100) AS contractor_fee
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Designer` des ON d.designerid = des.designerid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        LEFT JOIN `OrderPayment` op ON o.payment_id = op.payment_id
        WHERE o.orderid = ? AND o.clientid = ?";

$stmt = mysqli_prepare($mysqli, $check_order_sql);
mysqli_stmt_bind_param($stmt, "ii", $orderid, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    die("Order not found or you don't have permission to view this order.");
}

// Get payment values
$payment = [
    'total_design_payment' => isset($order['total_design_payment']) ? (float) $order['total_design_payment'] : 0.0,
    'total_construction_payment' => isset($order['total_construction_payment']) ? (float) $order['total_construction_payment'] : 0.0,
    'commission_1st' => isset($order['commission_1st']) ? (float) $order['commission_1st'] : 0.0,
    'commission_final' => isset($order['commission_final']) ? (float) $order['commission_final'] : 0.0,
    'inspection_fee' => isset($order['inspection_fee']) ? (float) $order['inspection_fee'] : 0.0,
    'contractor_fee' => isset($order['contractor_fee']) ? (float) $order['contractor_fee'] : 0.0,
    'total_amount_due' => isset($order['total_amount_due']) ? (float) $order['total_amount_due'] : 0.0,
];

$commission_total = $payment['commission_1st'] + $payment['commission_final'];
$constructor_cost = $payment['inspection_fee'] + $payment['contractor_fee'];

// Fetch order references (schema-safe: supports DBs without color/quantity columns yet)
$hasRefColor = false;
$hasRefQuantity = false;

$refColorRes = mysqli_query($mysqli, "SHOW COLUMNS FROM `OrderReference` LIKE 'color'");
if ($refColorRes) {
    $hasRefColor = (mysqli_num_rows($refColorRes) > 0);
    mysqli_free_result($refColorRes);
}

$refQuantityRes = mysqli_query($mysqli, "SHOW COLUMNS FROM `OrderReference` LIKE 'quantity'");
if ($refQuantityRes) {
    $hasRefQuantity = (mysqli_num_rows($refQuantityRes) > 0);
    mysqli_free_result($refQuantityRes);
}

$refColorSelect = $hasRefColor ? 'orr.color' : 'NULL AS color';
$refQuantitySelect = $hasRefQuantity ? 'orr.quantity' : 'NULL AS quantity';

$ref_sql = "SELECT 
                orr.id, 
                orr.productid,
                {$refColorSelect},
                {$refQuantitySelect},
                orr.status,
                orr.price,
                orr.note,
                p.pname, 
                p.price as product_price, 
                p.category,
                p.description as product_description
            FROM `OrderReference` orr
            LEFT JOIN `Product` p ON orr.productid = p.productid
            WHERE orr.orderid = ?";

$ref_stmt = mysqli_prepare($mysqli, $ref_sql);
mysqli_stmt_bind_param($ref_stmt, "i", $orderid);
mysqli_stmt_execute($ref_stmt);
$ref_result = mysqli_stmt_get_result($ref_stmt);
$references = array();
while ($ref_row = mysqli_fetch_assoc($ref_result)) {
    $references[] = $ref_row;
}

// Fetch actual material cost from products in this order (OrderReference)
$actual_material_cost = 0.0;
$material_sql = "SELECT IFNULL(SUM(COALESCE(orr.price, p.price, 0)), 0) AS material_total
                                 FROM `OrderReference` orr
                                 LEFT JOIN `Product` p ON orr.productid = p.productid
                                 WHERE orr.orderid = ?
                                     AND (orr.status IS NULL OR LOWER(orr.status) <> 'rejected')";
$material_stmt = mysqli_prepare($mysqli, $material_sql);
if ($material_stmt) {
    mysqli_stmt_bind_param($material_stmt, "i", $orderid);
    mysqli_stmt_execute($material_stmt);
    $material_result = mysqli_stmt_get_result($material_stmt);
    $material_row = mysqli_fetch_assoc($material_result);
    $actual_material_cost = isset($material_row['material_total']) ? (float) $material_row['material_total'] : 0.0;
    mysqli_stmt_close($material_stmt);
}

// Fetch additional fees
$fees_sql = "SELECT fee_id, fee_name, amount, description, created_at FROM `AdditionalFee` WHERE orderid = ? ORDER BY created_at ASC";
$fees_stmt = mysqli_prepare($mysqli, $fees_sql);
mysqli_stmt_bind_param($fees_stmt, "i", $orderid);
mysqli_stmt_execute($fees_stmt);
$fees_result = mysqli_stmt_get_result($fees_stmt);
$fees = array();
$total_fees = 0;
while ($fee_row = mysqli_fetch_assoc($fees_result)) {
    $fees[] = $fee_row;
    $total_fees += floatval($fee_row['amount']);
}

// Fetch latest designed picture
$pic_sql = "SELECT filename, pictureid FROM `DesignedPicture` WHERE orderid = ? ORDER BY upload_date DESC LIMIT 1";
$pic_stmt = mysqli_prepare($mysqli, $pic_sql);
mysqli_stmt_bind_param($pic_stmt, "i", $orderid);
mysqli_stmt_execute($pic_stmt);
$pic_result = mysqli_stmt_get_result($pic_stmt);
$latest_picture = mysqli_fetch_assoc($pic_result);

// --- Define all needed variables ---
$design_price = isset($order["design_price"]) ? floatval($order["design_price"]) : 0;
$final_payment = isset($order['final_payment']) ? floatval($order['final_payment']) : 0;
$deposit = isset($order['deposit']) ? floatval($order['deposit']) : 2000.0;
$original_budget = floatval($order['order_budget'] ?? 0);

// Calculate total cost
$references_total = 0.0;
if (!empty($references)) {
    foreach ($references as $r) {
        $rprice = isset($r['price']) && $r['price'] !== null ? (float) $r['price'] : (float) ($r['product_price'] ?? 0);
        $references_total += $rprice;
    }
}

$final_payment_amount = $final_payment;

// Calculate Design Fee (2.5% of design price)
$Design_Fee1 = $design_price * 0.025;
$Project_Deposit = 2000; // Fixed project deposit

$total_deducted = $payment['total_design_payment']
    + $actual_material_cost
    + $constructor_cost
    + $commission_total
    + $total_fees;
$final_total_cost = $total_deducted;

// Calculate remaining budget
$remaining_budget = $original_budget - $total_deducted;

$status = strtolower($order['ostatus'] ?? 'waiting confirm');

// Handle client actions: confirm proposal or request revision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['client_confirm_proposal'])) {
        $next_status = 'waiting final design phase payment';
        $u_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ? AND clientid = ?";
        $u_stmt = mysqli_prepare($mysqli, $u_sql);
        mysqli_stmt_bind_param($u_stmt, "sii", $next_status, $orderid, $client_id);
        mysqli_stmt_execute($u_stmt);
        mysqli_stmt_close($u_stmt);
        header('Location: ../client/payment3.php?proposal_confirmed=1&orderid=' . $orderid);
        exit;
    }
        // Handle inspection actions
if (isset($_POST['accept_inspection'])) {
    $update_sql = "UPDATE `Order` SET inspection_status = 'accepted', client_suggested_date = NULL WHERE orderid = ? AND clientid = ?";
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "ii", $orderid, $client_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    // Notify manager
    $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                   VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has accepted the inspection for Order #$orderid.', 'inspection_accepted', NOW())";
    $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
    mysqli_stmt_bind_param($notify_stmt, "ii", $orderid, $orderid);
    mysqli_stmt_execute($notify_stmt);
    mysqli_stmt_close($notify_stmt);
    
    header("Location: Order_View.php?id=" . $orderid . "&msg=inspection_accepted");
    exit;
}

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
        mysqli_stmt_bind_param($update_stmt, "sii", $suggested_datetime, $orderid, $client_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Notify manager
        $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                       VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has suggested a new inspection time for Order #$orderid: " . date('Y-m-d H:i', strtotime($suggested_datetime)) . "', 'inspection_suggested', NOW())";
        $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
        mysqli_stmt_bind_param($notify_stmt, "ii", $orderid, $orderid);
        mysqli_stmt_execute($notify_stmt);
        mysqli_stmt_close($notify_stmt);
        
        header("Location: Order_View.php?id=" . $orderid . "&msg=inspection_suggested");
        exit;
    }
}

if (isset($_POST['confirm_inspection_report'])) {
    // Check if already confirmed
    $check_sql = "SELECT COUNT(*) as cnt FROM InspectionConfirmation WHERE orderid = ? AND clientid = ?";
    $check_stmt = mysqli_prepare($mysqli, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $orderid, $client_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $check_row = mysqli_fetch_assoc($check_result);
    $already_confirmed = ($check_row['cnt'] > 0);
    mysqli_stmt_close($check_stmt);
    
    if (!$already_confirmed) {
        // Insert confirmation record
        $insert_confirm_sql = "INSERT INTO InspectionConfirmation (orderid, clientid, confirmed_at) VALUES (?, ?, NOW())";
        $insert_confirm_stmt = mysqli_prepare($mysqli, $insert_confirm_sql);
        mysqli_stmt_bind_param($insert_confirm_stmt, "ii", $orderid, $client_id);
        mysqli_stmt_execute($insert_confirm_stmt);
        mysqli_stmt_close($insert_confirm_stmt);
    }
    
    // Update order status to inspection_completed
    $update_sql = "UPDATE `Order` SET ostatus = 'inspection_completed' WHERE orderid = ? AND clientid = ?";
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "ii", $orderid, $client_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    // Notify manager
    $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                   VALUES ('manager', (SELECT managerid FROM Schedule WHERE orderid = ? LIMIT 1), ?, 'Client has confirmed the inspection report for Order #$orderid and is ready for final payment.', 'inspection_confirmed', NOW())";
    $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
    mysqli_stmt_bind_param($notify_stmt, "ii", $orderid, $orderid);
    mysqli_stmt_execute($notify_stmt);
    mysqli_stmt_close($notify_stmt);
    
    header("Location: Order_View.php?id=" . $orderid . "&msg=inspection_confirmed");
    exit;
}

	    if (isset($_POST['client_request_revision'])) {
	        $next_status = 'drafting 2nd proposal';
	        $note = mysqli_real_escape_string($mysqli, $_POST['revision_note'] ?? 'Client requested revision');
	        $u_sql = "UPDATE `Order` SET ostatus = ?, Requirements = CONCAT(Requirements, '\n\nCLIENT REQUEST: ', ?) WHERE orderid = ? AND clientid = ?";
	        $u_stmt = mysqli_prepare($mysqli, $u_sql);
	        mysqli_stmt_bind_param($u_stmt, "ssii", $next_status, $note, $orderid, $client_id);
	        mysqli_stmt_execute($u_stmt);
	        mysqli_stmt_close($u_stmt);
	        header('Location: Order_View.php?id=' . $orderid);
	        exit;
	    }

	    if (isset($_POST['agree_reopen_project']) && $client_id && $orderid) {
	        $u_sql = "UPDATE `Order`
	                  SET supplierid = NULL,
	                      supplier_status = 'Pending',
	                      ostatus = 'Coordinating Contractors',
	                      reassignment_status = 'Accepted'
	                  WHERE orderid = ?
	                    AND clientid = ?
	                    AND ostatus = 'waiting client reassignment'";
	        $u_stmt = mysqli_prepare($mysqli, $u_sql);
	        if ($u_stmt) {
	            mysqli_stmt_bind_param($u_stmt, 'ii', $orderid, $client_id);
	            mysqli_stmt_execute($u_stmt);
	            mysqli_stmt_close($u_stmt);
	        }
	        header('Location: Order_View.php?id=' . $orderid . '&shared=1');
	        exit;
	    }
	}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order #<?php echo $orderid; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .budget-info {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
        }

        .budget-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .budget-item:last-child {
            border-bottom: none;
        }

        .budget-label {
            font-weight: 500;
            color: #2c3e50;
        }

        .budget-value {
            font-weight: 600;
            color: #27ae60;
            font-size: 1.1rem;
        }

        .budget-remaining {
            font-size: 1.2rem;
            color: #e74c3c;
        }

        .designer-info {
            background: #f0f7ff;
            border-left: 4px solid #3498db;
            padding: 0.75rem;
            border-radius: 6px;
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .card-title {
            color: #495057;
            font-size: 1.1rem;
        }

        .status-badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container mb-5">
        <div class="page-title"><i class="fas fa-info-circle me-2"></i>Project Detail</div>

        <div class="alert <?php echo in_array($status, ['waiting confirm', 'waiting client review']) ? 'alert-warning' : 'alert-info'; ?> mb-4"
            role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Status:</strong> <?php echo htmlspecialchars($status); ?>
        </div>

        <div class="row mb-4">
            <!-- Customer Detail Card -->
            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user me-2"></i>Customer Detail
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Client Name</label>
                            <p class="mb-0">
                                <strong><?php echo htmlspecialchars($order["client_name"] ?? 'N/A'); ?></strong>
                            </p>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Client ID</label>
                            <p class="mb-0"><small>#<?php echo htmlspecialchars($order["clientid"] ?? 'N/A'); ?></small>
                            </p>
                        </div>
                        <?php if (!empty($order["cemail"])): ?>
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Email</label>
                                <p class="mb-0"><small><?php echo htmlspecialchars($order["cemail"]); ?></small></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($order["ctel"])): ?>
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Phone</label>
                                <p class="mb-0"><small><?php echo htmlspecialchars($order["ctel"]); ?></small></p>
                            </div>
                        <?php endif; ?>
                        <hr>

                        <!-- Budget Information Section -->
                        <div class="budget-info">
                            <h6 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Budget Overview</h6>
                            <div class="budget-item">
                                <span class="budget-label">Original Budget:</span>
                                <span class="budget-value">HK$<?php echo number_format($original_budget, 2); ?></span>
                            </div>

                            <div class="budget-item" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                                <span class="budget-label"><strong>Remaining Budget:</strong></span>
                                <span class="budget-value budget-remaining"><strong>HK$<?php echo number_format($remaining_budget, 2); ?></strong></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Design Detail Card -->
            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-pencil-alt me-2"></i>Design Detail
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Design ID</label>
                            <p class="mb-0"><small>#<?php echo htmlspecialchars($order["designid"] ?? 'N/A'); ?></small>
                            </p>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Design Name</label>
                            <p class="mb-0">
                                <small><?php echo htmlspecialchars($order["designName"] ?? 'N/A'); ?></small>
                            </p>
                        </div>

                        <!-- Designer Information -->
                        <?php if (!empty($order['designer_name'])): ?>
                            <div class="designer-info">
                                <label class="fw-bold text-muted small">Assigned Designer</label>
                                <p class="mb-1"><?php echo htmlspecialchars($order['designer_name']); ?></p>
                                <div
                                    class="badge <?php echo ($order['designer_status'] ?? 'Available') === 'Available' ? 'bg-success' : 'bg-warning'; ?>">
                                    <?php echo htmlspecialchars($order['designer_status'] ?? 'Available'); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Expected Price</label>
                            <p class="mb-0"><strong
                                    class="text-info">HK$<?php echo number_format($design_price, 2); ?></strong></p>
                        </div>
                        
                        <hr>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Design Tag</label>
                            <p class="mb-0"><small
                                    class="text-muted"><?php echo htmlspecialchars($order["design_tag"] ?? 'N/A'); ?></small>
                            </p>
                        </div>

                        <!-- Design References -->
                        <?php if (!empty($references)): ?>
                            <hr>
                            <div class="fw-bold text-muted small mb-2">Product References</div>
                            <?php
                            $grouped_refs = [];
                            foreach ($references as $ref) {
                                $grouped_refs[$ref['category']][] = $ref;
                            }
                            foreach ($grouped_refs as $category => $items): ?>
                                <div class="mb-3">
                                    <div class="mb-1"><span
                                            class="badge bg-secondary"><?php echo htmlspecialchars($category); ?></span></div>
                                    <ul class="list-unstyled ps-2 mb-0 border-start border-2 border-light">
                                        <?php foreach ($items as $ref): ?>
                                            <li class="d-flex justify-content-between align-items-center mb-1 ps-2">
                                                <small class="text-truncate" style="max-width: 60%;"
                                                    title="<?php echo htmlspecialchars($ref['pname']); ?>"><?php echo htmlspecialchars($ref['pname']); ?></small>
                                                <small
                                                    class="text-success fw-bold">HK$<?php echo number_format($ref['product_price'] ?? 0, 0); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Design Proposal -->
                        <div class="mt-3">
                            <label class="fw-bold text-muted small mb-2">Design proposal</label>
                            <?php if ($latest_picture): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                    onclick="openProposalPreview('../uploads/designed_Picture/<?php echo htmlspecialchars($latest_picture['filename']); ?>')">
                                    <i class="fas fa-file-image me-1"></i>Preview Proposal
                                </button>
                            <?php else: ?>
                                <p class="text-muted small">No proposal uploaded yet</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Detail Card -->
            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clipboard me-2"></i>Project Detail
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Project ID</label>
                            <p class="mb-0"><small>#<?php echo htmlspecialchars($order["orderid"]); ?></small></p>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Project Date</label>
                            <p class="mb-0"><small><?php echo date('M d, Y H:i', strtotime($order["odate"])); ?></small>
                            </p>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Status</label>
                            <p class="mb-0">
                                <?php
                                $status_class = '';
                                switch ($status) {
                                    case 'complete':
                                        $status_class = 'bg-success';
                                        break;
                                    case 'designing':
                                    case 'drafting 2nd proposal':
                                    case 'reviewing design proposal':
                                        $status_class = 'bg-info';
                                        break;
                                    case 'waiting confirm':
                                    case 'waiting client review':
                                    case 'waiting payment':
                                    case 'waiting design phase payment':
                                    case 'waiting 2nd design phase payment':
                                    case 'waiting final design phase payment':
                                        $status_class = 'bg-warning';
                                        break;
                                    case 'rejected':
                                        $status_class = 'bg-danger';
                                        break;
                                    default:
                                        $status_class = 'bg-secondary';
                                }
                                ?>
                                <span class="badge <?php echo $status_class; ?> status-badge">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </p>
                        </div>

                        <?php if (!empty($order['OrderFinishDate']) || !empty($order['DesignFinishDate'])): ?>
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Schedule Dates</label>
                                <?php if (!empty($order['DesignFinishDate'])): ?>
                                    <p class="mb-1"><small>Design Finish:
                                            <?php echo date('M d, Y', strtotime($order['DesignFinishDate'])); ?></small></p>
                                <?php endif; ?>
                                <?php if (!empty($order['OrderFinishDate'])): ?>
                                    <p class="mb-0"><small>Order Finish:
                                            <?php echo date('M d, Y', strtotime($order['OrderFinishDate'])); ?></small></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="fw-bold text-muted small">Requirements</label>
                            <div class="p-2 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($order["Requirements"] ?? 'No requirements specified')); ?>
                            </div>
                        </div>

                        <hr>

                        <!-- Cost Breakdown -->
                        <div class="mb-0">
                            <label class="fw-bold text-muted small">Cost Breakdown</label>
                            <div class="mb-2">
                                <ul class="list-unstyled mb-0">
                                    <li class="d-flex justify-content-between">
                                        <small class="text-muted">Design Cost</small>
                                        <strong>HK$<?php echo number_format($payment['total_design_payment'], 2); ?></strong>
                                    </li>

                                    <li class="d-flex justify-content-between">
                                        <small class="text-muted">Material Cost (Actual Products)</small>
                                        <strong>HK$<?php echo number_format($actual_material_cost, 2); ?></strong>
                                    </li>

                                    <li class="d-flex justify-content-between">
                                        <small class="text-muted">Constructor Cost</small>
                                        <strong>HK$<?php echo number_format($constructor_cost, 2); ?></strong>
                                    </li>
                                    <li class="d-flex justify-content-between">
                                        <small class="text-muted">Commission</small>
                                        <strong>HK$
                                            <?php echo number_format($commission_total, 2); ?>
                                        </strong>
                                    </li>
                                    <?php if (!empty($fees)): ?>
                                        <li class="mt-2"><small class="text-muted">Additional Fees</small></li>
                                        <?php foreach ($fees as $f): ?>
                                            <li class="d-flex justify-content-between ps-3">
                                                <small><?php echo htmlspecialchars($f['fee_name']); ?></small>
                                                <small
                                                    class="text-success">HK$<?php echo number_format((float) $f['amount'], 2); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <label class="fw-bold text-muted small">Actual Cost</label>
                            <p class="mb-0"><strong
                                    class="text-danger fs-5">HK$<?php echo number_format($final_total_cost, 2); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Reference Status Table -->
        <?php if (!empty($references)): ?>
            <div class="row mb-4">
                <div class="col-12">    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tags me-2"></i>Product Reference Status
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead class="table">
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                            <th>Requested Price</th>
                                            <th>Color</th>
                                            <th>Quantity</th>
                                            <th>Note</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($references as $ref):
                                            $refStatus = strtolower(trim($ref['status'] ?? 'pending'));
                                            $displayPrice = isset($ref['price']) && $ref['price'] !== null ? (float) $ref['price'] : (float) ($ref['product_price'] ?? 0);
                                            $badgeClass = 'bg-secondary';
                                            if (in_array($refStatus, ['waiting confirm', 'pending']))
                                                $badgeClass = 'bg-warning';
                                            if (in_array($refStatus, ['confirmed', 'approved']))
                                                $badgeClass = 'bg-success';
                                            if (in_array($refStatus, ['rejected']))
                                                $badgeClass = 'bg-danger';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ref['pname'] ?? ('Product #' . $ref['productid'])); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($ref['category'] ?? '—'); ?></td>
                                                <td><span
                                                        class="badge <?php echo $badgeClass; ?>"><?php echo $refStatus === 'waiting confirm' ? 'Request Confirm' : htmlspecialchars($refStatus); ?></span>
                                                </td>
                                                <td>HK$<?php echo number_format($displayPrice, 2); ?></td>
                                                <td><?php echo htmlspecialchars($ref['color'] ?? '—'); ?></td>
                                                <td><?php echo (isset($ref['quantity']) && $ref['quantity'] !== null && $ref['quantity'] !== '') ? (int) $ref['quantity'] : '—'; ?></td>
                                                <td><?php echo htmlspecialchars($ref['note'] ?? '—'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Proposal Preview Modal -->
        <div class="modal fade" id="proposalPreviewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Design Proposal Preview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="proposalPreviewImageWrap" class="text-center" style="display:none;">
                            <img id="proposalPreviewImage" src="" alt="Design Proposal"
                                style="max-width:100%;max-height:70vh;border-radius:8px;" />
                        </div>
                        <div id="proposalPreviewPdfWrap" style="display:none;">
                            <iframe id="proposalPreviewPdf" src="" style="width:100%;height:70vh;border:0;"></iframe>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplier Reassignment -->
        <?php if (($order['ostatus'] ?? '') === 'waiting client reassignment'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-warning" style="border-left: 5px solid #ffc107;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <i class="fas fa-people-arrows me-2 text-warning"></i>Contractor Reassignment
                                    </h5>
                                    <p class="text-muted mb-0 small">Current contractor rejected this project. Click the button to allow other contractors to view it in contractor search.</p>
                                </div>
                                <form method="post" onsubmit="return confirm('Allow other contractors to view and take this project?');">
                                    <button type="submit" name="agree_reopen_project" class="btn btn-warning">
                                        <i class="fas fa-share-square me-1"></i>Agree & Share to Other Contractors
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php 
// ========== Add Inspection Report Display and Final Payment Button ==========
// Get inspection data for display
$inspection_sql = "SELECT inspection_date, inspection_status, client_suggested_date, ostatus 
                   FROM `Order` WHERE orderid = ? AND clientid = ?";
$inspection_stmt = mysqli_prepare($mysqli, $inspection_sql);
mysqli_stmt_bind_param($inspection_stmt, "ii", $orderid, $client_id);
mysqli_stmt_execute($inspection_stmt);
$inspection_result = $inspection_stmt->get_result();
$inspection_data = $inspection_result->fetch_assoc();
mysqli_stmt_close($inspection_stmt);

$inspection_date = $inspection_data['inspection_date'] ?? null;
$inspection_status = $inspection_data['inspection_status'] ?? null;
$client_suggested_date = $inspection_data['client_suggested_date'] ?? null;
$current_ostatus = $inspection_data['ostatus'] ?? '';

// Check if inspection report exists (both pass and fail)
$has_report_sql = "SELECT COUNT(*) as cnt FROM InspectionReport WHERE orderid = ? AND result IN ('pass', 'fail')";
$has_report_stmt = mysqli_prepare($mysqli, $has_report_sql);
mysqli_stmt_bind_param($has_report_stmt, "i", $orderid);
mysqli_stmt_execute($has_report_stmt);
$has_report_result = $has_report_stmt->get_result();
$has_report_row = $has_report_result->fetch_assoc();
$has_inspection_report = ($has_report_row['cnt'] > 0);
mysqli_stmt_close($has_report_stmt);

// Show inspection report if report exists OR status is inspection_completed OR inspection_failed
$show_inspection_report = ($has_inspection_report || $current_ostatus == 'inspection_completed' || $current_ostatus == 'inspection_failed');

// Check if client has already confirmed (for pass reports)
$has_confirmed = false;
$confirm_check_stmt = null;

if ($show_inspection_report) {
    // Fetch inspection report
    $report_sql = "SELECT * FROM InspectionReport WHERE orderid = ? AND result IN ('pass', 'fail') ORDER BY submitted_at DESC LIMIT 1";
    $report_stmt = mysqli_prepare($mysqli, $report_sql);
    mysqli_stmt_bind_param($report_stmt, "i", $orderid);
    mysqli_stmt_execute($report_stmt);
    $report_result = $report_stmt->get_result();
    $inspection_report = $report_result->fetch_assoc();
    mysqli_stmt_close($report_stmt);
    
    if ($inspection_report && $inspection_report['result'] == 'pass') {
        $confirm_check_sql = "SELECT COUNT(*) as cnt FROM InspectionConfirmation WHERE orderid = ? AND clientid = ?";
        $confirm_check_stmt = mysqli_prepare($mysqli, $confirm_check_sql);
        mysqli_stmt_bind_param($confirm_check_stmt, "ii", $orderid, $client_id);
        mysqli_stmt_execute($confirm_check_stmt);
        $confirm_check_result = $confirm_check_stmt->get_result();
        $confirm_check_row = mysqli_fetch_assoc($confirm_check_result);
        $has_confirmed = ($confirm_check_row['cnt'] > 0);
        mysqli_stmt_close($confirm_check_stmt);
    }
}
?>

<!-- ========== INSPECTION STATUS DISPLAY (Time/Date) ========== -->
<?php if ($inspection_status == 'pending' && $inspection_date): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Inspection Appointment</h5>
            </div>
            <div class="card-body text-center">
                <h4>Proposed Inspection Date & Time:</h4>
                <h3 class="text-primary mb-3"><?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?></h3>
                <div class="d-flex gap-3 justify-content-center">
                    <form method="POST" style="flex:0 1 auto">
                        <button type="submit" name="accept_inspection" class="btn btn-success px-4" 
                                onclick="return confirm('Accept this inspection date and time?');">
                            <i class="fas fa-check-circle me-2"></i>Accept
                        </button>
                    </form>
                    <button type="button" class="btn btn-warning px-4" data-bs-toggle="modal" data-bs-target="#suggestTimeModal">
                        <i class="fas fa-calendar-alt me-2"></i>Suggest New Time
                    </button>
                </div>
            </div>
        </div>
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
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Inspection Status</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Your suggested time has been sent to the manager. Waiting for confirmation.
                    <?php if ($client_suggested_date): ?>
                        <br><small>Suggested: <?php echo date('F d, Y h:i A', strtotime($client_suggested_date)); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($inspection_status == 'accepted' && $inspection_date): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-success">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Inspection Confirmed</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle me-2"></i>
                    Inspection scheduled for: <strong><?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($inspection_status == 'rejected' && $inspection_date): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Inspection Rescheduled</h5>
            </div>
            <div class="card-body text-center">
                <h5>Manager Proposed New Inspection Date & Time:</h5>
                <h3 class="text-warning mb-3"><?php echo date('F d, Y h:i A', strtotime($inspection_date)); ?></h3>
                <div class="d-flex gap-3 justify-content-center">
                    <form method="POST" style="flex:0 1 auto">
                        <button type="submit" name="accept_inspection" class="btn btn-success px-4" 
                                onclick="return confirm('Accept this inspection date and time?');">
                            <i class="fas fa-check-circle me-2"></i>Accept
                        </button>
                    </form>
                    <button type="button" class="btn btn-warning px-4" data-bs-toggle="modal" data-bs-target="#suggestTimeModal">
                        <i class="fas fa-calendar-alt me-2"></i>Suggest New Time
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========== INSPECTION REPORT SECTION ========== -->
<?php if ($show_inspection_report): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Inspection Report</h5>
            </div>
            <div class="card-body">
                <?php if (isset($inspection_report) && $inspection_report): ?>
                    <?php $report_result_value = $inspection_report['result'] ?? 'pass'; ?>
                    <?php $is_report_fail = ($report_result_value == 'fail'); ?>
                    
                    <!-- Report Status Banner -->
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
                    
                    <!-- Report Details -->
                    <div class="card border-<?php echo $is_report_fail ? 'danger' : 'primary'; ?> mb-3">
                        <div class="card-header bg-<?php echo $is_report_fail ? 'danger' : 'primary'; ?> text-white">
                            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Inspection Report Details</h5>
                        </div>
                        <div class="card-body">
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
                            
                            <?php 
                            $attached_files = [];
                            if (!empty($inspection_report['file_paths'])) {
                                $attached_files = json_decode($inspection_report['file_paths'], true);
                                if (!is_array($attached_files)) {
                                    $attached_files = [];
                                }
                            }
                            ?>
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
                    
                    <!-- Final Payment Button for PASS reports only -->
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
                                    <button type="submit" class="btn btn-success btn-lg px-5">
                                        <i class="fas fa-check-circle me-2"></i>Confirm & Proceed to Final Payment
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle me-2 fa-lg"></i>
                                <strong>Inspection report confirmed!</strong><br>
                                <a href="payment_construction2.php?orderid=<?php echo $orderid; ?>" class="btn btn-primary mt-2">
                                    <i class="fas fa-credit-card me-2"></i>Proceed to Final Payment
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- For FAIL reports, show contact message -->
                        <div class="alert alert-warning text-center mt-3">
                            <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                            <strong>Inspection Failed</strong><br>
                            The inspection did not pass. Please contact the manager to discuss the next steps or required modifications.
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-clock me-2"></i>
                        Inspection report is being prepared. You will be notified when available.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

        <!-- Client Action Buttons -->
        <?php if ($status === 'waiting client review'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="mb-2">Proposal Ready for Your Review</h5>
                            <p class="text-muted">Please preview the proposal. Confirm to proceed to payment or request a
                                revision.</p>
                            <div class="mt-3 d-flex justify-content-center gap-2">
                                <form method="post"
                                    onsubmit="return confirm('Confirm this proposal and proceed to payment?');"
                                    style="display:inline;">
                                    <input type="hidden" name="client_confirm_proposal" value="1">
                                    <button type="submit" class="btn btn-success">
                                        Confirm Proposal
                                    </button>
                                </form>

                                <button class="btn btn-warning" data-bs-toggle="collapse" data-bs-target="#revisionBox">
                                    Request Revision
                                </button>
                            </div>

                            <div class="collapse mt-3" id="revisionBox">
                                <form method="post">
                                    <input type="hidden" name="client_request_revision" value="1">
                                    <div class="mb-2">
                                        <textarea name="revision_note" class="form-control"
                                            placeholder="Describe requested changes" required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Send Revision Request</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (in_array($status, ['waiting design phase payment', 'waiting 2nd design phase payment'], true)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="mb-2">Proceed to Payment</h5>
                            <p class="text-muted">Your proposal has been confirmed. Please proceed to complete the payment
                                to start the work.</p>
                            <div class="mt-3">
                                <a href="payment.php?orderid=<?php echo $orderid; ?>" class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>Proceed to Payment
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'waiting final design phase payment' && $final_payment_amount > 0): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="mb-2">Final Design Payment</h5>
                            <p class="text-muted">Please complete the final design payment to proceed.</p>
                            <div class="mt-3">
                                <a href="payment_final.php?orderid=<?php echo $orderid; ?>&amount=<?php echo $final_payment_amount; ?>"
                                    class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>Pay Final Design & Products
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Back Button -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="../client/order_history.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back
                to Order History</a>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openProposalPreview(fileUrl) {
            const imgWrap = document.getElementById('proposalPreviewImageWrap');
            const pdfWrap = document.getElementById('proposalPreviewPdfWrap');
            const img = document.getElementById('proposalPreviewImage');
            const pdf = document.getElementById('proposalPreviewPdf');
            if (!imgWrap || !pdfWrap || !img || !pdf) return;

            const isPdf = fileUrl.toLowerCase().endsWith('.pdf');
            if (isPdf) {
                imgWrap.style.display = 'none';
                pdfWrap.style.display = 'block';
                pdf.src = fileUrl;
                img.src = '';
            } else {
                pdfWrap.style.display = 'none';
                imgWrap.style.display = 'block';
                img.src = fileUrl;
                pdf.src = '';
            }

            const modalEl = document.getElementById('proposalPreviewModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }
    </script>

    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if (isset($result))
    mysqli_free_result($result);
if (isset($ref_result))
    mysqli_free_result($ref_result);
mysqli_close($mysqli);
?>