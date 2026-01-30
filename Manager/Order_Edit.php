<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

$orderid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// FIXED: Check if order exists (removed OrderProduct requirement)
$check_order_sql = "SELECT COUNT(*) as count FROM `Order` WHERE orderid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_order_sql);
mysqli_stmt_bind_param($check_stmt, "i", $orderid);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$order_check = mysqli_fetch_assoc($check_result);

if ($order_check['count'] == 0) {
    die("Order not found.");
}

// Use prepared statement to prevent SQL injection - ADDED cost field
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost,
               c.clientid, c.cname as client_name, c.ctel, c.cemail, c.budget,
               d.designid, d.designName, d.expect_price as design_price, d.tag as design_tag,
               s.scheduleid, s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ?";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $orderid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

// Fetch order references
$ref_sql = "SELECT 
                orr.id, 
                orr.productid,
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

// Calculate final total cost
$design_price = isset($order["design_price"]) ? floatval($order["design_price"]) : 0;
$final_total_cost = $design_price + $total_fees;

// Determine workflow state
$order_status = $order['ostatus'] ?? 'waiting confirm';
$show_edit_cards = ($order_status !== 'waiting confirm' && !empty($order['designid']));
$show_confirm_reject = ($order_status === 'waiting confirm');
$error_msg = null;

$edit_status = isset($_GET['edit']) && $_GET['edit'] == 'status';
$edit_order = isset($_GET['edit']) && $_GET['edit'] == 'order';
$edit_requirements = isset($_GET['edit']) && $_GET['edit'] == 'requirements';
$edit_designer = isset($_GET['edit']) && $_GET['edit'] == 'designer';
$edit_cost = isset($_GET['edit']) && $_GET['edit'] == 'cost';
$edit_reference = isset($_GET['edit']) && $_GET['edit'] == 'reference';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        $new_status = mysqli_real_escape_string($mysqli, $_POST['ostatus']);
        $order_finish_date = mysqli_real_escape_string($mysqli, $_POST['OrderFinishDate']);
        $design_finish_date = mysqli_real_escape_string($mysqli, $_POST['DesignFinishDate']);

        // Update order status
        $update_order_status_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_order_status_sql);
        mysqli_stmt_bind_param($update_stmt, "si", $new_status, $orderid);
        $update_success = mysqli_stmt_execute($update_stmt);

        if ($update_success) {
            // Update or create Schedule record
            // Use prepared statements to update or insert Schedule
            $of = !empty($order_finish_date) ? $order_finish_date : null;
            $df = !empty($design_finish_date) ? $design_finish_date : null;
            if (!empty($order['scheduleid'])) {
                $u_sql = "UPDATE `Schedule` SET OrderFinishDate = ?, DesignFinishDate = ?, managerid = ? WHERE scheduleid = ?";
                $u_stmt = mysqli_prepare($mysqli, $u_sql);
                mysqli_stmt_bind_param($u_stmt, "ssii", $of, $df, $user_id, $order['scheduleid']);
                mysqli_stmt_execute($u_stmt);
                mysqli_stmt_close($u_stmt);
            } else {
                $i_sql = "INSERT INTO `Schedule` (managerid, OrderFinishDate, DesignFinishDate, orderid) VALUES (?, ?, ?, ?)";
                $i_stmt = mysqli_prepare($mysqli, $i_sql);
                mysqli_stmt_bind_param($i_stmt, "issi", $user_id, $of, $df, $orderid);
                mysqli_stmt_execute($i_stmt);
                mysqli_stmt_close($i_stmt);
            }

            header("Location: Order_Edit.php?id=" . $orderid);
            exit();
        }
    }

    if (isset($_POST['update_order'])) {
        $designid = intval($_POST['designid']);
        $update_order_sql = "UPDATE `Order` SET 
                            designid = ?
                            WHERE orderid = ?";

        $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
        mysqli_stmt_bind_param($update_order_stmt, "ii", $designid, $orderid);

        if (mysqli_stmt_execute($update_order_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid);
            exit();
        }
    }

    if (isset($_POST['update_requirements'])) {
        $requirements = mysqli_real_escape_string($mysqli, $_POST['Requirements']);
        $update_req_sql = "UPDATE `Order` SET Requirements = ? WHERE orderid = ?";
        $update_req_stmt = mysqli_prepare($mysqli, $update_req_sql);
        mysqli_stmt_bind_param($update_req_stmt, "si", $requirements, $orderid);

        if (mysqli_stmt_execute($update_req_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid);
            exit();
        }
    }

    if (isset($_POST['assign_designer'])) {
        $designer_id = intval($_POST['designer_id']);

        if ($designer_id > 0) {
            // Check if design exists, if not create one
            $design_check_sql = "SELECT designid FROM `Design` WHERE designerid = ? LIMIT 1";
            $design_check_stmt = mysqli_prepare($mysqli, $design_check_sql);
            mysqli_stmt_bind_param($design_check_stmt, "i", $designer_id);
            mysqli_stmt_execute($design_check_stmt);
            $design_check_result = mysqli_stmt_get_result($design_check_stmt);

            if ($design_check_result->num_rows == 0) {
                // Create new design for this designer
                $insert_design_sql = "INSERT INTO `Design` (designerid, designName, expect_price, tag, likes) VALUES (?, ?, ?, ?, 0)";
                $insert_design_stmt = mysqli_prepare($mysqli, $insert_design_sql);
                $design_name = "Order #" . $orderid . " Design";
                $expect_price = $order['budget'] ?? 0;
                $tag = "Order #" . $orderid;
                mysqli_stmt_bind_param($insert_design_stmt, "isds", $designer_id, $design_name, $expect_price, $tag);

                if (mysqli_stmt_execute($insert_design_stmt)) {
                    $design_id = mysqli_insert_id($mysqli);

                    // Update order with new design
                    $update_order_sql = "UPDATE `Order` SET designid = ?, ostatus = 'designing' WHERE orderid = ?";
                    $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
                    mysqli_stmt_bind_param($update_order_stmt, "ii", $design_id, $orderid);

                    if (mysqli_stmt_execute($update_order_stmt)) {
                        // Update designer status to Busy
                        $update_designer_sql = "UPDATE `Designer` SET status = 'Busy' WHERE designerid = ?";
                        $update_designer_stmt = mysqli_prepare($mysqli, $update_designer_sql);
                        mysqli_stmt_bind_param($update_designer_stmt, "i", $designer_id);
                        mysqli_stmt_execute($update_designer_stmt);
                        mysqli_stmt_close($update_designer_stmt);

                        header("Location: Order_Edit.php?id=" . $orderid);
                        exit();
                    }
                }
            } else {
                // Design exists, just update order
                $design = mysqli_fetch_assoc($design_check_result);
                $update_order_sql = "UPDATE `Order` SET designid = ?, ostatus = 'designing' WHERE orderid = ?";
                $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
                mysqli_stmt_bind_param($update_order_stmt, "ii", $design['designid'], $orderid);

                if (mysqli_stmt_execute($update_order_stmt)) {
                    header("Location: Order_Edit.php?id=" . $orderid);
                    exit();
                }
            }
        }
    }

    if (isset($_POST['save_cost_changes'])) {
        // 1. Handle Batch Fees if present
        if (isset($_POST['new_fees']) && is_array($_POST['new_fees'])) {
            $add_fee_sql = "INSERT INTO `AdditionalFee` (orderid, fee_name, amount, description) VALUES (?, ?, ?, ?)";
            $add_fee_stmt = mysqli_prepare($mysqli, $add_fee_sql);

            foreach ($_POST['new_fees'] as $fee) {
                $fee_name = mysqli_real_escape_string($mysqli, $fee['name'] ?? '');
                $amount = floatval($fee['amount'] ?? 0);
                $description = mysqli_real_escape_string($mysqli, $fee['description'] ?? '');

                if (!empty($fee_name) && $amount > 0) {
                    mysqli_stmt_bind_param($add_fee_stmt, "isds", $orderid, $fee_name, $amount, $description);
                    mysqli_stmt_execute($add_fee_stmt);
                }
            }
            mysqli_stmt_close($add_fee_stmt);
        }

        // 2. Handle Total Cost Update
        $new_cost = floatval($_POST['total_cost']);
        $update_cost_sql = "UPDATE `Order` SET cost = ? WHERE orderid = ?";
        $update_cost_stmt = mysqli_prepare($mysqli, $update_cost_sql);
        mysqli_stmt_bind_param($update_cost_stmt, "di", $new_cost, $orderid);
        mysqli_stmt_execute($update_cost_stmt);
        mysqli_stmt_close($update_cost_stmt);

        // Redirect after saving both
        header("Location: Order_Edit.php?id=" . $orderid);
        exit();
    }

    if (isset($_POST['update_reference_status'])) {
        if (isset($_POST['ref']) && is_array($_POST['ref'])) {
            foreach ($_POST['ref'] as $refId => $payload) {
                $rid = (int)$refId;
                $newPrice = isset($payload['price']) ? (float)$payload['price'] : null;
                $update_ref_sql = "UPDATE `OrderReference` SET price = ?, status = 'waiting confirm' WHERE id = ? AND orderid = ?";
                $update_ref_stmt = mysqli_prepare($mysqli, $update_ref_sql);
                mysqli_stmt_bind_param($update_ref_stmt, "dii", $newPrice, $rid, $orderid);
                mysqli_stmt_execute($update_ref_stmt);
                mysqli_stmt_close($update_ref_stmt);
            }
        }
        header("Location: Order_Edit.php?id=" . $orderid);
        exit();
    }

    if (isset($_POST['confirm_proposal'])) {
        $confirm_status = 'waiting review';
        $update_confirm_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ?";
        $confirm_stmt = mysqli_prepare($mysqli, $update_confirm_sql);
        mysqli_stmt_bind_param($confirm_stmt, "si", $confirm_status, $orderid);
        if (mysqli_stmt_execute($confirm_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid . "&msg=proposal_confirmed");
            exit();
        }
        mysqli_stmt_close($confirm_stmt);
    }

    if (isset($_POST['reject_proposal'])) {
        $reject_status = 'drafting proposal';
        $update_reject_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ?";
        $reject_stmt = mysqli_prepare($mysqli, $update_reject_sql);
        mysqli_stmt_bind_param($reject_stmt, "si", $reject_status, $orderid);
        if (mysqli_stmt_execute($reject_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid . "&msg=proposal_rejected");
            exit();
        }
        mysqli_stmt_close($reject_stmt);
    }

    if (isset($_POST['confirm_order'])) {
        $confirm_status = 'designing';
        $update_confirm_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ?";
        $confirm_stmt = mysqli_prepare($mysqli, $update_confirm_sql);
        mysqli_stmt_bind_param($confirm_stmt, "si", $confirm_status, $orderid);

        if (mysqli_stmt_execute($confirm_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid . "&msg=confirmed");
            exit();
        }
        mysqli_stmt_close($confirm_stmt);
    }

    if (isset($_POST['reject_order'])) {
        $reject_reason = mysqli_real_escape_string($mysqli, $_POST['reject_reason'] ?? '');
        $reject_status = 'reject';

        // Update order status and reason
        $update_reject_sql = "UPDATE `Order` SET ostatus = ?, Requirements = ? WHERE orderid = ?";
        $reject_stmt = mysqli_prepare($mysqli, $update_reject_sql);
        $reject_note = "REJECTED - Reason: " . $reject_reason;
        mysqli_stmt_bind_param($reject_stmt, "ssi", $reject_status, $reject_note, $orderid);

        if (mysqli_stmt_execute($reject_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid . "&msg=rejected");
            exit();
        }
        mysqli_stmt_close($reject_stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Edit Order #<?php echo $orderid; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
</head>

<body>
    <!-- Navbar -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container mb-5">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-edit me-2"></i>Proposal Drafter
        </div>

        <?php if ($order): ?>

            <!-- Workflow Status Banner -->
            <div class="alert <?php echo $show_confirm_reject ? 'alert-warning' : 'alert-info'; ?> mb-4" role="alert">
                <i class="fas <?php echo $show_confirm_reject ? 'fa-hourglass-half' : 'fa-pencil-alt'; ?> me-2"></i>
                <?php if ($show_confirm_reject): ?>
                    <strong>Pending Confirmation:</strong> Please review the details, assign a designer, then confirm or reject this order.
                <?php else: ?>
                    <strong>In Progress:</strong> This order has been confirmed. You can now update schedules, requirements, costs, and monitor the design process.
                <?php endif; ?>
            </div>

            <!-- Customer Detail Card -->
            <div class="row mb-4">
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
                                <p class="mb-0"><small><?php echo htmlspecialchars($order["clientid"] ?? 'N/A'); ?></small>
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
                            <div class="mb-0">
                                <label class="fw-bold text-muted small">Budget</label>
                                <p class="mb-0"><strong
                                        class="text-success fs-5">HK$<?php echo number_format($order["budget"], 2); ?></strong>
                                </p>
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
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Expected Price</label>
                                <p class="mb-0"><strong
                                        class="text-info">HK$<?php echo number_format($order["design_price"] ?? 0, 0); ?></strong>
                                </p>
                            </div>
                            <hr>
                            <div class="mb-0">
                                <label class="fw-bold text-muted small">Design Tag</label>
                                <p class="mb-0"><small
                                        class="text-muted"><?php echo htmlspecialchars($order["design_tag"] ?? 'N/A'); ?></small>
                                </p>
                            </div>
                            <!-- Design References Card -->
                            <?php if (!empty($references)): ?>
                                <hr>
                                <div class="fw-bold text-muted small mb-2">Product References</div>
                                <?php
                                $grouped_refs = [];
                                foreach ($references as $ref) {
                                    $grouped_refs[$ref['category']][] = $ref;
                                }
                                foreach ($grouped_refs as $category => $items):
                                    ?>
                                    <div class="mb-3">
                                        <div class="mb-1"><span
                                                class="badge bg-secondary"><?php echo htmlspecialchars($category); ?></span></div>
                                        <ul class="list-unstyled ps-2 mb-0 border-start border-2 border-light">
                                            <?php foreach ($items as $ref): ?>
                                                <li class="d-flex justify-content-between align-items-center mb-1 ps-2">
                                                    <small class="text-truncate" style="max-width: 60%;"
                                                        title="<?php echo htmlspecialchars($ref['pname']); ?>">
                                                        <?php echo htmlspecialchars($ref['pname']); ?>
                                                    </small>
                                                    <small
                                                        class="text-success fw-bold">HK$<?php echo number_format($ref['product_price'], 0); ?></small>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Order Detail Card -->
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-clipboard me-2"></i>Order Detail
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Order ID</label>
                                <p class="mb-0"><small>#<?php echo htmlspecialchars($order["orderid"]); ?></small></p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Order Date</label>
                                <p class="mb-0"><small><?php echo date('M d, Y H:i', strtotime($order["odate"])); ?></small>
                                </p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-muted small">Status</label>
                                <p class="mb-0">
                                    <?php
                                    $status = strtolower($order["ostatus"] ?? 'waiting confirm');
                                    $status_class = '';
                                    switch ($status) {
                                        case 'complete':
                                            $status_class = 'bg-success';
                                            break;
                                        case 'designing':
                                        case 'drafting proposal':
                                        case 'reviewing design proposal':
                                            $status_class = 'bg-info';
                                            break;
                                        case 'waiting confirm':
                                        case 'waiting review':
                                        case 'waiting payment':
                                            $status_class = 'bg-warning';
                                            break;
                                        case 'reject':
                                            $status_class = 'bg-danger';
                                            break;
                                        default:
                                            $status_class = 'bg-secondary';
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="text-muted mb-0 small">
                                Requirements
                            </div>
                            <?php echo nl2br(htmlspecialchars($order["Requirements"] ?? 'No requirements specified')); ?>
                            <div class="mt-3 mb-3">
                                <label class="fw-bold text-muted small">Assigned designer</label>
                                <?php
                                $current_designer_sql = "SELECT des.designerid, des.dname, des.status FROM `Design` d 
                                                        JOIN `Designer` des ON d.designerid = des.designerid 
                                                        WHERE d.designid = ?";
                                $current_designer_stmt = mysqli_prepare($mysqli, $current_designer_sql);
                                if ($order['designid']) {
                                    mysqli_stmt_bind_param($current_designer_stmt, "i", $order['designid']);
                                    mysqli_stmt_execute($current_designer_stmt);
                                    $current_designer_result = mysqli_stmt_get_result($current_designer_stmt);
                                    $current_designer = mysqli_fetch_assoc($current_designer_result);
                                }
                                ?>
                                <?php if (isset($current_designer) && $current_designer): ?>
                                    <br>
                                    <div
                                        class="badge <?php echo $current_designer['status'] === 'Available' ? 'bg-success' : 'bg-warning'; ?>">
                                        <?php echo $current_designer['status']; ?>
                                    </div>
                                    <?php echo htmlspecialchars($current_designer['dname']); ?>
                                <?php else: ?>
                                    <div><i class="fas fa-exclamation-circle me-1"></i>No designer assigned yet</div>
                                <?php endif; ?>
                            </div>
                            <hr>
                            <div class="mb-0">
                                <label class="fw-bold text-muted small">Design Price</label>
                                <p class="mb-2">
                                    <strong>HK$<?php echo isset($order["design_price"]) && $order["design_price"] ? number_format($order["design_price"], 0) : '0'; ?></strong>
                                </p>
                                <label class="fw-bold text-muted small">Additional Fees</label>
                                <p class="mb-2"><strong>HK$<?php echo number_format($total_fees, 0); ?></strong></p>
                                <label class="fw-bold text-muted small">Total Cost</label>
                                <p class="mb-0"><strong
                                        class="text-danger fs-5">HK$<?php echo number_format($final_total_cost, 0); ?></strong>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Reference Status Card -->
            <?php if (!empty($references)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-tags me-2"></i>Product Reference Status
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- View Mode -->
                                <div id="reference-view" class="<?php echo $edit_reference ? 'd-none' : ''; ?>">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Status</th>
                                                    <th>Requested Price</th>
                                                    <th>Note</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($references as $ref): 
                                                    $refStatus = strtolower(trim($ref['status'] ?? 'pending'));
                                                    $displayPrice = isset($ref['price']) && $ref['price'] !== null ? (float)$ref['price'] : (float)($ref['product_price'] ?? 0);
                                                    $badgeClass = 'bg-secondary';
                                                    if (in_array($refStatus, ['waiting confirm', 'pending'])) $badgeClass = 'bg-warning';
                                                    if (in_array($refStatus, ['confirmed', 'approved'])) $badgeClass = 'bg-success';
                                                    if (in_array($refStatus, ['rejected', 'reject'])) $badgeClass = 'bg-danger';
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($ref['pname'] ?? ('Product #' . $ref['productid'])); ?></td>
                                                        <td><?php echo htmlspecialchars($ref['category'] ?? '—'); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $badgeClass; ?>">
                                                                <?php echo $refStatus === 'waiting confirm' ? 'Request Confirm' : htmlspecialchars($refStatus); ?>
                                                            </span>
                                                        </td>
                                                        <td>HK$<?php echo number_format($displayPrice, 2); ?></td>
                                                        <td><?php echo htmlspecialchars($ref['note'] ?? '—'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-primary" onclick="toggleEdit('reference', true)">
                                            <i class="fas fa-edit me-2"></i>Edit
                                        </button>
                                    </div>
                                </div>

                                <!-- Edit Mode -->
                                <div id="reference-edit" class="<?php echo $edit_reference ? '' : 'd-none'; ?>">
                                    <form method="post">
                                        <input type="hidden" name="update_reference_status" value="1">
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Requested Price</th>
                                                        <th>Note</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($references as $ref): 
                                                        $displayPrice = isset($ref['price']) && $ref['price'] !== null ? (float)$ref['price'] : (float)($ref['product_price'] ?? 0);
                                                    ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($ref['pname'] ?? ('Product #' . $ref['productid'])); ?></td>
                                                            <td>
                                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="ref[<?php echo (int)$ref['id']; ?>][price]" value="<?php echo htmlspecialchars(number_format($displayPrice, 2, '.', '')); ?>">
                                                            </td>
                                                            <td><?php echo htmlspecialchars($ref['note'] ?? '—'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-success flex-grow-1">
                                                <i class="fas fa-save me-2"></i>Request Quotation
                                            </button>
                                            <button type="button" class="btn btn-secondary" onclick="toggleEdit('reference', false)">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Assign Designer Section (Always Available) -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-user-tie me-2"></i>Reassign Designer
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- View Mode -->
                            <div id="designer-view" class="<?php echo $edit_designer ? 'd-none' : ''; ?>">
                                <button type="button" class="btn btn-primary w-100" onclick="toggleEdit('designer', true)">
                                    <i class="fas fa-edit me-2"></i><?php echo isset($current_designer) && $current_designer ? 'Change Designer' : 'Reassign Designer'; ?>
                                </button>
                            </div>

                            <!-- Edit Mode -->
                            <div id="designer-edit" class="<?php echo $edit_designer ? '' : 'd-none'; ?>">
                                <form method="post">
                                    <input type="hidden" name="assign_designer" value="1">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Select Designer</label>
                                        <select name="designer_id" class="form-select" required>
                                            <option value="">-- Choose Designer --</option>
                                            <?php
                                            $designers_sql = "SELECT designerid, dname, status FROM `Designer` WHERE managerid = ? ORDER BY status ASC, dname ASC";
                                            $designers_stmt = mysqli_prepare($mysqli, $designers_sql);
                                            mysqli_stmt_bind_param($designers_stmt, "i", $user_id);
                                            mysqli_stmt_execute($designers_stmt);
                                            $designers_result = mysqli_stmt_get_result($designers_stmt);

                                            while ($designer = mysqli_fetch_assoc($designers_result)) {
                                                $status_indicator = $designer['status'] === 'Available' ? '✓ Available' : '⊙ Busy';
                                                echo '<option value="' . $designer['designerid'] . '">' .
                                                    htmlspecialchars($designer['dname']) . ' (' . $status_indicator . ')' .
                                                    '</option>';
                                            }
                                            mysqli_free_result($designers_result);
                                            mysqli_stmt_close($designers_stmt);
                                            ?>
                                        </select>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success flex-grow-1">
                                            <i class="fas fa-check me-2"></i>Assign
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="toggleEdit('designer', false)">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Edit Sections -->
            <?php 
                $status = strtolower($order['ostatus'] ?? 'waiting confirm');
                $isProposalConfirmed = !in_array($status, ['waiting confirm', 'designing', 'drafting proposal', 'reviewing design proposal', 'submit_proposal', 'reject']); 
                // Only show these sections if status is at a stage where a proposal has likely been accepted/confirmed.
                // Assuming 'waiting review' means Client Review, which implies manager approved it. 
                // However, "Reviewing Design Proposal" means manager is reviewing.
                // The prompt says "hide before confirmed by manager".
                // Manager confirms it at "Reviewing Design Proposal" stage to move it forward?
                // Or maybe the user means simply hide these if it's still in early stages.
                
                // Let's hide if in early stages:
                $hideEditCards = in_array($status, ['waiting confirm', 'designing', 'drafting proposal', 'reviewing design proposal', 'reject']);
            ?>
            <?php if ($status === 'reviewing design proposal'): ?>
                <div class="row mb-4">
                    <div class="col-12">
                         <div class="card bg-light border-info">
                             <div class="card-body text-center">
                                 <h5 class="text-info"><i class="fas fa-search me-2"></i>Design Proposal Review</h5>
                                 <p>The designer has submitted a proposal. Please review the design details.</p>
                                 <div class="mt-3">
                                     <?php if ($latest_picture): ?>
                                         <button type="button" class="btn btn-primary me-2"
                                             onclick="openProposalPreview('../uploads/designed_Picture/<?= htmlspecialchars($latest_picture['filename']) ?>')">
                                             <i class="fas fa-file-image me-1"></i>Preview Design Proposal
                                         </button>
                                     <?php else: ?>
                                         <button disabled class="btn btn-secondary me-2">
                                             <i class="fas fa-exclamation-triangle me-1"></i>No Proposal File Found
                                         </button>
                                     <?php endif; ?>
                                 </div>
                             </div>
                         </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row" style="<?php echo ($show_confirm_reject || $hideEditCards) ? 'display: none;' : ''; ?>">
                <!-- Update Status Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tasks me-2"></i>Update Schedule
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- View Mode -->
                            <div id="status-view" class="<?php echo $edit_status ? 'd-none' : ''; ?>">
                                <button type="button" class="btn btn-primary w-100" onclick="toggleEdit('status', true)">
                                    <i class="fas fa-edit me-2"></i>Update Schedule
                                </button>
                            </div>

                            <!-- Edit Mode -->
                            <div id="status-edit" class="<?php echo $edit_status ? '' : 'd-none'; ?>">
                                <form method="post">
                                    <input type="hidden" name="update_status" value="1">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Order Finish Date</label>
                                        <input type="datetime-local" name="OrderFinishDate" class="form-control" value="<?php echo isset($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00 00:00:00'
                                            ? date('Y-m-d\TH:i', strtotime($order['OrderFinishDate']))
                                            : date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Design Finish Date</label>
                                        <input type="datetime-local" name="DesignFinishDate" class="form-control" value="<?php echo isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00 00:00:00'
                                            ? date('Y-m-d\TH:i', strtotime($order['DesignFinishDate']))
                                            : date('Y-m-d\TH:i', strtotime('+3 days')); ?>">
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success flex-grow-1">
                                            <i class="fas fa-save me-2"></i>Save Changes
                                        </button>
                                        <button type="button" class="btn btn-secondary"
                                            onclick="toggleEdit('status', false)">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Order Information Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-pencil-alt me-2"></i>Update design references
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- View Mode -->
                            <div id="order-view" class="<?php echo $edit_order ? 'd-none' : ''; ?>">
                                <button type="button" class="btn btn-primary w-100" onclick="toggleEdit('order', true)">
                                    <i class="fas fa-edit me-2"></i>Update
                                </button>
                            </div>

                            <!-- Edit Mode -->
                            <div id="order-edit" class="<?php echo $edit_order ? '' : 'd-none'; ?>">
                                <form method="post">
                                    <input type="hidden" name="update_order" value="1">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Design</label>
                                        <select name="designid" class="form-select" required>
                                            <?php
                                            $design_stmt = mysqli_prepare($mysqli, "SELECT designid, expect_price as price, tag FROM Design ORDER BY designid");
                                            mysqli_stmt_execute($design_stmt);
                                            $design_result = mysqli_stmt_get_result($design_stmt);
                                            while ($design = mysqli_fetch_assoc($design_result)) {
                                                $selected = ($design['designid'] == $order['designid']) ? 'selected' : '';
                                                echo '<option value="' . $design['designid'] . '" ' . $selected . '>' .
                                                    'Design #' . $design['designid'] . ' - $' . number_format($design['price'], 2) .
                                                    ' (' . htmlspecialchars(substr($design['tag'], 0, 30)) . '...)' . '</option>';
                                            }
                                            mysqli_free_result($design_result);
                                            mysqli_stmt_close($design_stmt);
                                            ?>
                                        </select>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success flex-grow-1">
                                            <i class="fas fa-save me-2"></i>Save Changes
                                        </button>
                                        <button type="button" class="btn btn-secondary"
                                            onclick="toggleEdit('order', false)">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Requirements Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-clipboard-list me-2"></i>Update Requirements
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- View Mode -->
                            <div id="requirements-view" class="<?php echo $edit_requirements ? 'd-none' : ''; ?>">
                                <button type="button" class="btn btn-primary w-100"
                                    onclick="toggleEdit('requirements', true)">
                                    <i class="fas fa-edit me-2"></i>Update Requirements
                                </button>
                            </div>

                            <!-- Edit Mode -->
                            <div id="requirements-edit" class="<?php echo $edit_requirements ? '' : 'd-none'; ?>">
                                <form method="post">
                                    <input type="hidden" name="update_requirements" value="1">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Requirements</label>
                                        <textarea name="Requirements" class="form-control" rows="6"
                                            required><?php echo htmlspecialchars($order['Requirements'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success flex-grow-1">
                                            <i class="fas fa-save me-2"></i>Save Changes
                                        </button>
                                        <button type="button" class="btn btn-secondary"
                                            onclick="toggleEdit('requirements', false)">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Cost Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-dollar-sign me-2"></i>Edit Cost & Fees
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- View Mode -->
                            <div id="cost-view" class="<?php echo $edit_cost ? 'd-none' : ''; ?>">
                                <button type="button" class="btn btn-primary w-100" onclick="toggleEdit('cost', true)">
                                    <i class="fas fa-edit me-2"></i>Edit
                                </button>
                            </div>

                            <!-- Edit Mode -->
                            <div id="cost-edit" class="<?php echo $edit_cost ? '' : 'd-none'; ?>">
                                <!-- Additional Fees List -->
                                <div class="mb-4">
                                    <label class="fw-bold mb-2">Additional Fees Detail</label>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Fee Name</th>
                                                    <th>Amount</th>
                                                    <th style="width: 60px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="fee_list_body">
                                                <?php if (!empty($fees)): ?>
                                                    <?php foreach ($fees as $fee): ?>
                                                        <tr>
                                                            <td>
                                                                <small><?php echo htmlspecialchars($fee['fee_name']); ?></small>
                                                                <?php if ($fee['description']): ?>
                                                                    <br><small
                                                                        class="text-muted"><?php echo htmlspecialchars($fee['description']); ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><small><strong>HK$<?php echo number_format($fee['amount'], 0); ?></strong></small>
                                                            </td>
                                                            <td>
                                                                <form method="post" style="display: inline;">
                                                                    <input type="hidden" name="delete_fee" value="1">
                                                                    <input type="hidden" name="fee_id"
                                                                        value="<?php echo $fee['fee_id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                                        onclick="return confirm('Delete this fee?');">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr id="no_fees_row">
                                                        <td colspan="3" class="text-center text-muted small">No additional fees
                                                            recorded.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Combined Cost Edit Form -->
                                <form method="post" id="cost-edit-form">
                                    <input type="hidden" name="save_cost_changes" value="1">

                                    <!-- Add New Fee Interface -->
                                    <div class="mb-4">
                                        <div class="card p-3 bg-light border">
                                            <div class="row g-2 mb-2 align-items-end">
                                                <div class="col-md-4">
                                                    <label class="small text-muted">Name</label>
                                                    <input type="text" id="new_fee_name"
                                                        class="form-control form-control-sm" placeholder="e.g. Rush Fee">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="small text-muted">Amount</label>
                                                    <input type="number" id="new_fee_amount"
                                                        class="form-control form-control-sm" placeholder="0.00" min="0"
                                                        step="0.01">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="small text-muted">Description</label>
                                                    <input type="text" id="new_fee_desc"
                                                        class="form-control form-control-sm" placeholder="Optional">
                                                </div>
                                                <div class="col-md-2">
                                                    <button type="button" class="btn btn-sm btn-primary w-100"
                                                        onclick="addFeeToList()">
                                                        <i class="fas fa-plus"></i> Add
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Update Total Cost Input -->
                                    <div class="mb-3">
                                        <!-- Cost Summary -->
                                        <div class="mb-4 p-3 rounded border">
                                            <div class="row mb-2">
                                                 <div class="mt-3">
                                                     <?php if ($latest_picture): ?>
                                                         <button type="button" class="btn btn-primary me-2"
                                                             onclick="openProposalPreview('../uploads/designed_Picture/<?= htmlspecialchars($latest_picture['filename']) ?>')">
                                                             <i class="fas fa-file-image me-1"></i>Preview Design Proposal
                                                         </button>
                                                     <?php else: ?>
                                                         <button disabled class="btn btn-secondary me-2">
                                                             <i class="fas fa-exclamation-triangle me-1"></i>No Proposal File Found
                                                         </button>
                                                     <?php endif; ?>
                                     
                                                     <!-- Confirm/Reject buttons are inside the preview modal -->
                                                    <strong class="text-info" id="preview_fees_text"
                                                        data-base="<?php echo $total_fees; ?>">HK$<?php echo number_format($total_fees, 0); ?></strong>
                                                </div>
                                            </div>
                                            <hr class="my-2">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Total Cost:</small>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <strong class="text-danger fs-5" id="preview_total_text"
                                                        data-design="<?php echo $order["design_price"] ?? 0; ?>">HK$<?php echo number_format($final_total_cost, 0); ?></strong>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-success flex-grow-1">
                                                <i class="fas fa-check me-2"></i>Save
                                            </button>
                                            <button type="button" class="btn btn-secondary"
                                                onclick="toggleEdit('cost', false)">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </div>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
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
                                <img id="proposalPreviewImage" src="" alt="Design Proposal" style="max-width:100%;max-height:70vh;border-radius:8px;" />
                            </div>
                            <div id="proposalPreviewPdfWrap" style="display:none;">
                                <iframe id="proposalPreviewPdf" src="" style="width:100%;height:70vh;border:0;"></iframe>
                            </div>
                        </div>
                        <div class="modal-footer d-flex justify-content-between">
                            <div class="text-muted small">Confirm or reject after reviewing the proposal.</div>
                            <div class="d-flex gap-2">
                                <form method="post" class="m-0">
                                    <input type="hidden" name="confirm_proposal" value="1">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check me-1"></i>Confirm Proposal
                                    </button>
                                </form>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="reject_proposal" value="1">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-times me-1"></i>Reject Proposal
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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

                function toggleEdit(section, show) {
                    const view = document.getElementById(section + '-view');
                    const edit = document.getElementById(section + '-edit');
                    if (show) {
                        view.classList.add('d-none');
                        edit.classList.remove('d-none');
                    } else {
                        view.classList.remove('d-none');
                        edit.classList.add('d-none');
                        // Optional: Clear URL param if present
                        const url = new URL(window.location);
                        if (url.searchParams.has('edit')) {
                            url.searchParams.delete('edit');
                            window.history.pushState({}, '', url);
                        }
                    }
                }

                // Legacy support for cost edit if needed, or alias it
                function toggleCostEdit(show) {
                    toggleEdit('cost', show);
                }

                let feeIndex = 0;
                function addFeeToList() {
                    const nameInput = document.getElementById('new_fee_name');
                    const amountInput = document.getElementById('new_fee_amount');
                    const descInput = document.getElementById('new_fee_desc');
                    const name = nameInput.value.trim();
                    const amount = parseFloat(amountInput.value);
                    const desc = descInput.value.trim();

                    if (!name || isNaN(amount) || amount <= 0) {
                        alert('Please enter a valid name and amount.');
                        return;
                    }

                    // Remove "No fees" message if it exists
                    const noFeesRow = document.getElementById('no_fees_row');
                    if (noFeesRow) noFeesRow.remove();

                    const tbody = document.getElementById('fee_list_body');
                    const row = document.createElement('tr');
                    row.id = 'fee-row-' + feeIndex;
                    row.className = 'table-success'; // Highlight new rows
                    row.innerHTML = `
                        <td>
                            <small>${name} <span class="badge bg-success ms-1">Not saved</span></small>
                            ${desc ? '<br><small class="text-muted">' + desc + '</small>' : ''}
                            <input type="hidden" name="new_fees[${feeIndex}][name]" value="${name}" form="cost-edit-form">
                            <input type="hidden" name="new_fees[${feeIndex}][description]" value="${desc}" form="cost-edit-form">
                        </td>
                        <td>
                            <small><strong>HK$${amount.toFixed(2)}</strong></small>
                            <input type="hidden" name="new_fees[${feeIndex}][amount]" value="${amount}" form="cost-edit-form">
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeFeeFromList(${feeIndex})">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);

                    // clear inputs
                    nameInput.value = '';
                    amountInput.value = '';
                    descInput.value = '';
                    nameInput.focus();

                    feeIndex++;
                    updatePricePreview();
                }

                function removeFeeFromList(index) {
                    const row = document.getElementById('fee-row-' + index);
                    if (row) row.remove();

                    const tbody = document.getElementById('fee_list_body');
                    if (tbody.children.length === 0) {
                        const noRow = document.createElement('tr');
                        noRow.id = 'no_fees_row';
                        noRow.innerHTML = '<td colspan="3" class="text-center text-muted small">No additional fees recorded.</td>';
                        tbody.appendChild(noRow);
                    }
                    updatePricePreview();
                }

                function updatePricePreview() {
                    const feesText = document.getElementById('preview_fees_text');
                    const totalText = document.getElementById('preview_total_text');
                    const totalInput = document.getElementById('input_total_cost');

                    if (!feesText || !totalText) return;

                    const baseFees = parseFloat(feesText.getAttribute('data-base')) || 0;
                    const designPrice = parseFloat(totalText.getAttribute('data-design')) || 0;

                    // Sum new fees
                    let newFeesTotal = 0;
                    const newFeeInputs = document.querySelectorAll('input[name^="new_fees"][name$="[amount]"]');
                    newFeeInputs.forEach(input => {
                        newFeesTotal += parseFloat(input.value) || 0;
                    });

                    const currentTotalFees = baseFees + newFeesTotal;
                    const finalTotal = designPrice + currentTotalFees;

                    // Update display
                    feesText.textContent = 'HK$' + currentTotalFees.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    totalText.textContent = 'HK$' + finalTotal.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

                    // Update input if it exists
                    if (totalInput) {
                        totalInput.value = finalTotal.toFixed(2);
                    }
                }

                function showRejectModal() {
                    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
                    rejectModal.show();
                }
            </script>

            <!-- Confirm/Reject Order Section -->
            <div class="row mt-4" style="<?php echo !$show_confirm_reject ? 'display: none;' : ''; ?>">
                <div class="col-lg-12">
                    <div class="card border-warning">
                        <div class="card-header bg-warning bg-opacity-10">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-check-circle me-2"></i>Order Action
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($error_msg): ?>
                                <div class="alert alert-danger mb-3" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-muted mb-3">Please review all details and assign a designer. Then confirm or reject the order.</p>
                            
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="confirm_order" value="1">
                                        <button type="submit" class="btn btn-success w-100" onclick="return confirm('Confirm this order?');">
                                            <i class="fas fa-check-circle me-2"></i>Confirm Order
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <button type="button" class="btn btn-danger w-100" onclick="showRejectModal()">
                                        <i class="fas fa-times-circle me-2"></i>Reject Order
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Reject Modal -->
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger bg-opacity-10">
                        <h5 class="modal-title" id="rejectModalLabel">
                            <i class="fas fa-times-circle me-2"></i>Reject Order
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="reject_order" value="1">
                            <div class="mb-3">
                                <label for="reject_reason" class="form-label fw-bold">Reason for Rejection</label>
                                <textarea id="reject_reason" name="reject_reason" class="form-control" rows="4"
                                    placeholder="Enter reason for rejecting this order..." required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger"
                                onclick="return confirm('Are you sure you want to reject this order?');">
                                <i class="fas fa-times-circle me-2"></i>Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Order not found.</strong>
            <p class="mb-0">The requested order does not exist or has been removed.</p>
        </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="Order_Management.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Order List
        </a>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Include chat widget -->
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