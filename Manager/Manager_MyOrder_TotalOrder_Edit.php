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
while($ref_row = mysqli_fetch_assoc($ref_result)) {
    $references[] = $ref_row;
}

$edit_status = isset($_GET['edit']) && $_GET['edit'] == 'status';
$edit_order = isset($_GET['edit']) && $_GET['edit'] == 'order';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['update_status'])) {
        $new_status = mysqli_real_escape_string($mysqli, $_POST['ostatus']);
        $order_finish_date = mysqli_real_escape_string($mysqli, $_POST['OrderFinishDate']);
        $design_finish_date = mysqli_real_escape_string($mysqli, $_POST['DesignFinishDate']);
        
        // Update order status
        $update_order_status_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_order_status_sql);
        mysqli_stmt_bind_param($update_stmt, "si", $new_status, $orderid);
        $update_success = mysqli_stmt_execute($update_stmt);
        
        if($update_success) {
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
            
            header("Location: Manager_MyOrder_TotalOrder.php");
            exit();
        }
    }
    
    if(isset($_POST['update_order'])) {
        $requirements = mysqli_real_escape_string($mysqli, $_POST['Requirements']);
        $clientid = intval($_POST['clientid']);
        $designid = intval($_POST['designid']);
        $cost = isset($_POST['cost']) && !empty($_POST['cost']) ? floatval($_POST['cost']) : null;
        
        // Note: Budget is stored in Client table, not Order table
        // UPDATED: Added cost field to update
        $update_order_sql = "UPDATE `Order` SET 
                            Requirements = ?,
                            clientid = ?,
                            designid = ?,
                            cost = ?
                            WHERE orderid = ?";
        
        $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
        mysqli_stmt_bind_param($update_order_stmt, "siidi", $requirements, $clientid, $designid, $cost, $orderid);
        
        if(mysqli_stmt_execute($update_order_stmt)) {
            header("Location: Manager_MyOrder_TotalOrder.php");
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

        <?php if($order): ?>

        <!-- Order Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Order Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Order ID</label>
                        <p class="mb-0">#<?php echo htmlspecialchars($order["orderid"]); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Order Date</label>
                        <p class="mb-0"><?php echo date('Y-m-d H:i', strtotime($order["odate"])); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Client</label>
                        <div>
                            <strong><?php echo htmlspecialchars($order["client_name"] ?? 'N/A'); ?></strong>
                            <br>
                            <small class="text-muted">ID: <?php echo htmlspecialchars($order["clientid"] ?? 'N/A'); ?></small>
                            <?php if(!empty($order["cemail"])): ?>
                                <br><small class="text-muted">Email: <?php echo htmlspecialchars($order["cemail"]); ?></small>
                            <?php endif; ?>
                            <?php if(!empty($order["ctel"])): ?>
                                <br><small class="text-muted">Phone: <?php echo htmlspecialchars($order["ctel"]); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Budget</label>
                        <p class="mb-0"><strong class="text-success">$<?php echo number_format($order["budget"], 2); ?></strong></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Cost</label>
                        <p class="mb-0">
                            <strong class="text-info">
                                $<?php echo isset($order["cost"]) && $order["cost"] ? number_format($order["cost"], 2) : '0.00'; ?>
                            </strong>
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Design</label>
                        <div>
                            <small>Design #<?php echo htmlspecialchars($order["designid"] ?? 'N/A'); ?></small>
                            <br>
                            <small class="text-muted">Price: $<?php echo number_format($order["design_price"] ?? 0, 2); ?></small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Status</label>
                        <p class="mb-0">
                            <?php 
                            $status = $order["ostatus"] ?? 'Pending';
                            $status_class = '';
                            switch($status) {
                                case 'Completed': $status_class = 'status-completed'; break;
                                case 'Designing': $status_class = 'status-designing'; break;
                                case 'Pending': $status_class = 'status-pending'; break;
                                default: $status_class = 'status-pending';
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($status); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="fw-bold text-muted">Requirements</label>
                        <div class="alert alert-light p-3 mb-0">
                            <?php echo nl2br(htmlspecialchars($order["Requirements"] ?? '')); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Design References Card -->
        <?php if(!empty($references)): ?>
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
                            <?php foreach($references as $ref): ?>
                            <tr>
                                <td style="width: 25%; text-align: left;"><?php echo htmlspecialchars($ref['pname']); ?></td>
                                <td style="width: 15%; text-align: left;"><span class="badge bg-secondary"><?php echo htmlspecialchars($ref['category']); ?></span></td>
                                <td style="width: 15%; text-align: left;"><strong class="text-success">$<?php echo number_format($ref['product_price'], 2); ?></strong></td>
                                <td style="width: 45%; text-align: left;"><small><?php echo htmlspecialchars($ref['product_description'] ?? 'N/A'); ?></small></td>
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
                        <?php if(!$edit_status): ?>
                            <form method="get">
                                <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                <input type="hidden" name="edit" value="status">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-edit me-2"></i>Update Status & Dates
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="update_status" value="1">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Current Status</label>
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
                                    <input type="datetime-local" name="OrderFinishDate" class="form-control"
                                           value="<?php echo isset($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00 00:00:00' 
                                                   ? date('Y-m-d\TH:i', strtotime($order['OrderFinishDate'])) 
                                                   : date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Design Finish Date</label>
                                    <input type="datetime-local" name="DesignFinishDate" class="form-control"
                                           value="<?php echo isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00 00:00:00' 
                                                   ? date('Y-m-d\TH:i', strtotime($order['DesignFinishDate'])) 
                                                   : date('Y-m-d\TH:i', strtotime('+3 days')); ?>">
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success flex-grow-1">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                    <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
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
                            <i class="fas fa-pencil-alt me-2"></i>Update Order Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(!$edit_order): ?>
                            <form method="get">
                                <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                <input type="hidden" name="edit" value="order">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-edit me-2"></i>Update Order Information
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="update_order" value="1">
                                <div class="alert alert-info mb-3" role="alert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> Budget is managed in the Client profile, not in the Order.
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Client</label>
                                    <select name="clientid" class="form-select" required>
                                        <option value="">Select Client</option>
                                        <?php
                                        $client_stmt = mysqli_prepare($mysqli, "SELECT clientid, cname, cemail FROM Client ORDER BY cname");
                                        mysqli_stmt_execute($client_stmt);
                                        $client_result = mysqli_stmt_get_result($client_stmt);
                                        while($client = mysqli_fetch_assoc($client_result)){
                                            $selected = ($client['clientid'] == $order['clientid']) ? 'selected' : '';
                                            echo '<option value="' . $client['clientid'] . '" ' . $selected . '>' . 
                                                 htmlspecialchars($client['cname']) . ' (ID: ' . $client['clientid'] . 
                                                 ' - ' . htmlspecialchars($client['cemail']) . ')</option>';
                                        }
                                        mysqli_free_result($client_result);
                                        mysqli_stmt_close($client_stmt);
                                        ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Design</label>
                                    <select name="designid" class="form-select" required>
                                        <option value="">Select Design</option>
                                        <?php
                                        $design_stmt = mysqli_prepare($mysqli, "SELECT designid, expect_price as price, tag FROM Design ORDER BY designid");
                                        mysqli_stmt_execute($design_stmt);
                                        $design_result = mysqli_stmt_get_result($design_stmt);
                                        while($design = mysqli_fetch_assoc($design_result)){
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
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Cost</label>
                                    <input type="number" name="cost" class="form-control" step="0.01" min="0" 
                                           value="<?php echo isset($order["cost"]) && $order["cost"] ? htmlspecialchars($order["cost"]) : ''; ?>"
                                           placeholder="Enter order cost (optional)">
                                    <small class="text-muted">The actual cost of fulfilling this order</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Requirements</label>
                                    <textarea name="Requirements" class="form-control" rows="4" required><?php echo htmlspecialchars($order["Requirements"] ?? ''); ?></textarea>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success flex-grow-1">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                    <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">
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
            <a href="Manager_MyOrder_TotalOrder.php" class="btn btn-secondary">
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
if(isset($result)) mysqli_free_result($result);
if(isset($ref_result)) mysqli_free_result($ref_result);
mysqli_close($mysqli);
?>
