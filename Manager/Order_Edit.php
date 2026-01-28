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

$edit_status = isset($_GET['edit']) && $_GET['edit'] == 'status';
$edit_order = isset($_GET['edit']) && $_GET['edit'] == 'order';
$edit_requirements = isset($_GET['edit']) && $_GET['edit'] == 'requirements';
$edit_designer = isset($_GET['edit']) && $_GET['edit'] == 'designer';
$edit_cost = isset($_GET['edit']) && $_GET['edit'] == 'cost';

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
                    $update_order_sql = "UPDATE `Order` SET designid = ?, ostatus = 'Designing' WHERE orderid = ?";
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
                $update_order_sql = "UPDATE `Order` SET designid = ?, ostatus = 'Designing' WHERE orderid = ?";
                $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
                mysqli_stmt_bind_param($update_order_stmt, "ii", $design['designid'], $orderid);

                if (mysqli_stmt_execute($update_order_stmt)) {
                    header("Location: Order_Edit.php?id=" . $orderid);
                    exit();
                }
            }
        }
    }

    if (isset($_POST['add_fee'])) {
        $fee_name = mysqli_real_escape_string($mysqli, $_POST['fee_name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $description = mysqli_real_escape_string($mysqli, $_POST['description'] ?? '');

        if (!empty($fee_name) && $amount > 0) {
            $add_fee_sql = "INSERT INTO `AdditionalFee` (orderid, fee_name, amount, description) VALUES (?, ?, ?, ?)";
            $add_fee_stmt = mysqli_prepare($mysqli, $add_fee_sql);
            mysqli_stmt_bind_param($add_fee_stmt, "isds", $orderid, $fee_name, $amount, $description);
            
            if (mysqli_stmt_execute($add_fee_stmt)) {
                header("Location: Order_Edit.php?id=" . $orderid);
                exit();
            }
        }
    }

    if (isset($_POST['delete_fee'])) {
        $fee_id = intval($_POST['fee_id']);
        $delete_fee_sql = "DELETE FROM `AdditionalFee` WHERE fee_id = ? AND orderid = ?";
        $delete_fee_stmt = mysqli_prepare($mysqli, $delete_fee_sql);
        mysqli_stmt_bind_param($delete_fee_stmt, "ii", $fee_id, $orderid);
        
        if (mysqli_stmt_execute($delete_fee_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid);
            exit();
        }
    }

    if (isset($_POST['update_cost'])) {
        $new_cost = floatval($_POST['total_cost']);
        $update_cost_sql = "UPDATE `Order` SET cost = ? WHERE orderid = ?";
        $update_cost_stmt = mysqli_prepare($mysqli, $update_cost_sql);
        mysqli_stmt_bind_param($update_cost_stmt, "di", $new_cost, $orderid);
        
        if (mysqli_stmt_execute($update_cost_stmt)) {
            header("Location: Order_Edit.php?id=" . $orderid);
            exit();
        }
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
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>

<body>
    <!-- Navbar -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container mb-5">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-edit me-2"></i>Edit Order #<?php echo htmlspecialchars($order["orderid"] ?? 'N/A'); ?>
        </div>

        <?php if ($order): ?>

            <!-- Customer Detail Card -->
            <div class="row mb-4">
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white">
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
                        <div class="card-header bg-info text-white">
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
                                        class="text-info">HK$<?php echo number_format($order["design_price"] ?? 0, 2); ?></strong>
                                </p>
                            </div>
                            <hr>
                            <div class="mb-0">
                                <label class="fw-bold text-muted small">Design Tag</label>
                                <p class="mb-0"><small
                                        class="text-muted"><?php echo htmlspecialchars($order["design_tag"] ?? 'N/A'); ?></small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Detail Card -->
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="card h-100">
                        <div class="card-header bg-success text-white">
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
                                    $status = $order["ostatus"] ?? 'Pending';
                                    $status_class = '';
                                    switch ($status) {
                                        case 'Completed':
                                            $status_class = 'bg-success';
                                            break;
                                        case 'Designing':
                                            $status_class = 'bg-info';
                                            break;
                                        case 'Pending':
                                            $status_class = 'bg-warning';
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
                                <p class="mb-2"><strong>HK$<?php echo isset($order["design_price"]) && $order["design_price"] ? number_format($order["design_price"], 2) : '0.00'; ?></strong></p>
                                <label class="fw-bold text-muted small">Additional Fees</label>
                                <p class="mb-2"><strong>HK$<?php echo number_format($total_fees, 2); ?></strong></p>
                                <label class="fw-bold text-muted small">Total Cost</label>
                                <p class="mb-0"><strong class="text-danger fs-5">HK$<?php echo isset($order["cost"]) && $order["cost"] ? number_format($order["cost"], 2) : '0.00'; ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Design References Card -->
            <?php if (!empty($references)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-link me-2"></i>Product References
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="color: black; width: 25%; text-align: left;">Product Name</th>
                                        <th style="color: black; width: 15%; text-align: left;">Category</th>
                                        <th style="color: black; width: 15%; text-align: left;">Price</th>
                                        <th style="color: black; width: 45%; text-align: left;">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($references as $ref): ?>
                                        <tr>
                                            <td style="width: 25%; text-align: left;"><?php echo htmlspecialchars($ref['pname']); ?>
                                            </td>
                                            <td style="width: 15%; text-align: left;"><span
                                                    class="badge bg-secondary"><?php echo htmlspecialchars($ref['category']); ?></span>
                                            </td>
                                            <td style="width: 15%; text-align: left;"><strong
                                                    class="text-success">$<?php echo number_format($ref['product_price'], 2); ?></strong>
                                            </td>
                                            <td style="width: 45%; text-align: left;">
                                                <small><?php echo htmlspecialchars($ref['product_description'] ?? 'N/A'); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Edit Sections -->
            <div class="row">
                <!-- Update Status Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tasks me-2"></i>Update Status & Dates
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!$edit_status): ?>
                                <form method="get">
                                    <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                    <input type="hidden" name="edit" value="status">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-edit me-2"></i>Edit
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="update_status" value="1">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Status</label>
                                        <div>
                                            <span class="status-badge status-pending">
                                                <?php echo htmlspecialchars($order["ostatus"] ?? 'Pending'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">New Status</label>
                                        <select name="ostatus" class="form-select" required>
                                            <option value="Pending" <?php echo (($order["ostatus"] ?? 'Pending') == 'Pending') ? 'selected' : ''; ?>>
                                                <i class="fas fa-hourglass-half"></i> Pending
                                            </option>
                                            <option value="Designing" <?php echo (($order["ostatus"] ?? '') == 'Designing') ? 'selected' : ''; ?>>
                                                <i class="fas fa-pencil-alt"></i> Designing
                                            </option>
                                            <option value="Completed" <?php echo (($order["ostatus"] ?? '') == 'Completed') ? 'selected' : ''; ?>>
                                                <i class="fas fa-check-circle"></i> Completed
                                            </option>
                                            <option value="Cancelled" <?php echo (($order["ostatus"] ?? '') == 'Cancelled') ? 'selected' : ''; ?>>
                                                <i class="fas fa-times-circle"></i> Cancelled
                                            </option>
                                        </select>
                                    </div>
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
                                        <a href="Order_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
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
                            <?php if (!$edit_order): ?>
                                <form method="get">
                                    <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                    <input type="hidden" name="edit" value="order">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-edit me-2"></i>Edit
                                    </button>
                                </form>
                            <?php else: ?>
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
                                        <a href="Order_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
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
                            <?php if (!$edit_requirements): ?>
                                <form method="get">
                                    <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                    <input type="hidden" name="edit" value="requirements">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-edit me-2"></i>Update Requirements
                                    </button>
                                </form>
                            <?php else: ?>
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
                                        <a href="Order_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
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
                            <?php if (!$edit_cost): ?>
                                <form method="get">
                                    <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                    <input type="hidden" name="edit" value="cost">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-edit me-2"></i>Edit 
                                    </button>
                                </form>
                            <?php else: ?>
                                <?php 
                                    $design_price = floatval($order["design_price"] ?? 0);
                                    $calculated_total = $design_price + $total_fees;
                                ?>
                                <!-- Cost Summary with Auto-Calculation -->
                                <div class="mb-4 p-3 bg-light rounded border-start border-warning border-4">
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block mb-1"><strong>Design Price</strong></small>
                                            <div class="fs-6">HK$<span id="designPriceDisplay"><?php echo number_format($design_price, 2); ?></span></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block mb-1"><strong>Additional Fees</strong></small>
                                            <div class="fs-6 text-info">HK$<span id="feesTotalDisplay"><?php echo number_format($total_fees, 2); ?></span></div>
                                        </div>
                                    </div>
                                    <hr class="my-2">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-muted d-block mb-1"><strong>Calculated Total</strong></small>
                                        </div>
                                        <div class="col-6 text-end">
                                            <span class="fs-5 fw-bold text-success">HK$<span id="calculatedTotal"><?php echo number_format($calculated_total, 2); ?></span></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Additional Fees Detailed List -->
                                <div class="mb-4">
                                    <label class="fw-bold mb-2 d-flex align-items-center">
                                        <i class="fas fa-list me-2"></i>Fee Breakdown
                                        <?php if (!empty($fees)): ?>
                                            <span class="badge bg-info ms-auto"><?php echo count($fees); ?> fee(s)</span>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if (!empty($fees)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered table-hover mb-3">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th width="35%">Fee Name</th>
                                                        <th width="30%">Amount</th>
                                                        <th width="35%">Description</th>
                                                        <th width="10%" class="text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($fees as $fee): ?>
                                                        <tr>
                                                            <td>
                                                                <strong class="text-dark"><?php echo htmlspecialchars($fee['fee_name']); ?></strong><br>
                                                                <small class="text-muted"><?php echo date('M d, Y', strtotime($fee['created_at'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <strong class="text-success">HK$<?php echo number_format($fee['amount'], 2); ?></strong>
                                                            </td>
                                                            <td>
                                                                <?php if ($fee['description']): ?>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($fee['description']); ?></small>
                                                                <?php else: ?>
                                                                    <small class="text-muted fst-italic">No description</small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <form method="post" style="display: inline;">
                                                                    <input type="hidden" name="delete_fee" value="1">
                                                                    <input type="hidden" name="fee_id" value="<?php echo $fee['fee_id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete fee" onclick="return confirm('Delete this fee? Amount: HK$<?php echo number_format($fee['amount'], 2); ?>');">
                                                                        <i class="fas fa-trash-alt"></i>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mb-3">
                                            <i class="fas fa-info-circle me-2"></i>No additional fees added yet.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Add New Fee Form -->
                                <div class="card mb-4 border-success">
                                    <div class="card-header bg-light border-success">
                                        <h6 class="card-title mb-0 text-success">
                                            <i class="fas fa-plus-circle me-2"></i>Add New Fee
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="post" id="addFeeForm">
                                            <input type="hidden" name="add_fee" value="1">
                                            <div class="mb-2">
                                                <label class="form-label small fw-bold">Fee Name <span class="text-danger">*</span></label>
                                                <input type="text" name="fee_name" class="form-control form-control-sm" placeholder="e.g., Installation, Consultation, Revision" required>
                                                <small class="text-muted">Give this fee a descriptive name</small>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small fw-bold">Amount (HK$) <span class="text-danger">*</span></label>
                                                <input type="number" name="amount" class="form-control form-control-sm" placeholder="0.00" step="0.01" min="0" required>
                                                <small class="text-muted">Enter the fee amount</small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Description</label>
                                                <input type="text" name="description" class="form-control form-control-sm" placeholder="e.g., Additional design revisions requested by client" maxlength="255">
                                                <small class="text-muted">Optional: Explain why this fee was added</small>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-success w-100">
                                                <i class="fas fa-plus me-1"></i>Add Fee
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Update Total Cost Form -->
                                <form method="post" id="updateCostForm">
                                    <input type="hidden" name="update_cost" value="1">
                                    <div class="card border-danger">
                                        <div class="card-header bg-light border-danger">
                                            <h6 class="card-title mb-0 text-danger">
                                                <i class="fas fa-calculator me-2"></i>Final Total Cost
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <small class="d-block text-muted mb-2">
                                                    Calculated from: Design Price (HK$<?php echo number_format($design_price, 2); ?>) + Additional Fees (HK$<?php echo number_format($total_fees, 2); ?>)
                                                </small>
                                                <div class="input-group">
                                                    <span class="input-group-text">HK$</span>
                                                    <input type="number" name="total_cost" id="totalCostInput" class="form-control form-control-lg fw-bold" 
                                                           value="<?php echo number_format($calculated_total, 2); ?>"
                                                           step="0.01" min="0" required>
                                                </div>
                                                <small class="text-muted d-block mt-2">
                                                    <i class="fas fa-lightbulb me-1"></i>Value auto-calculates but you can override if needed
                                                </small>
                                            </div>
                                            <div class="d-flex gap-2 mt-3">
                                                <button type="submit" class="btn btn-danger flex-grow-1 fw-bold">
                                                    <i class="fas fa-save me-2"></i>Save Total Cost
                                                </button>
                                                <a href="Order_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
                                                    <i class="fas fa-times me-2"></i>Cancel
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Assign Designer Section -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-user-tie me-2"></i>Assign Designer
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!$edit_designer): ?>
                                <form method="get">
                                    <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                    <input type="hidden" name="edit" value="designer">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i
                                            class="fas fa-edit me-2"></i><?php echo isset($current_designer) && $current_designer ? 'Change Designer' : 'Assign Designer'; ?>
                                    </button>
                                </form>
                            <?php else: ?>
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
                                        <a href="Order_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
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

    <!-- Auto-calculate total cost when fees change -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const designPrice = <?php echo json_encode($design_price); ?>;
            const totalCostInput = document.getElementById('totalCostInput');
            
            if (totalCostInput) {
                // Auto-calculate when form is submitted (fees will be recalculated on page reload)
                const updateCostForm = document.getElementById('updateCostForm');
                if (updateCostForm) {
                    updateCostForm.addEventListener('submit', function() {
                        // Total will be recalculated from DB on next load
                    });
                }
                
                // Listen for input changes to show real-time calculation
                totalCostInput.addEventListener('input', function() {
                    const value = parseFloat(this.value) || 0;
                    // Value can be manually edited or will auto-calculate on save
                });
            }
        });
    </script>

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