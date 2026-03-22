<?php
// supplier/WorkerAllocation.php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$supplier_id = $user['supplierid'];

// Get order ID from URL parameter
$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;

if ($order_id == 0) {
    die("Invalid Order ID");
}

// Verify if this supplier has products in this order
$check_order_sql = "SELECT DISTINCT orf.orderid 
                    FROM `OrderReference` orf 
                    JOIN `Product` p ON orf.productid = p.productid 
                    WHERE orf.orderid = ? AND p.supplierid = ?";
$check_order_stmt = mysqli_prepare($mysqli, $check_order_sql);
mysqli_stmt_bind_param($check_order_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($check_order_stmt);
$check_order_result = mysqli_stmt_get_result($check_order_stmt);
if (mysqli_num_rows($check_order_result) == 0) {
    die("Access Denied: You do not have any products in this order.");
}
mysqli_stmt_close($check_order_stmt);

// Initialize variables
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['allocate_workers'])) {
    if (!isset($_POST['worker_ids']) || empty($_POST['worker_ids'])) {
        $error_message = 'Please select at least one worker.';
    } else {
        $worker_ids = $_POST['worker_ids'];
        
        mysqli_begin_transaction($mysqli);
        try {
            // Get managerid from the order schedule or order reference
            $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `Schedule` WHERE orderid = $order_id LIMIT 1");
            if (mysqli_num_rows($mgr_res) == 0) {
                // Fallback to finding any manager associated with this order's delivery or just use a default if needed
                $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `OrderDelivery` WHERE orderid = $order_id LIMIT 1");
            }
            $mgr_row = mysqli_fetch_assoc($mgr_res);
            $manager_id = $mgr_row ? $mgr_row['managerid'] : 1; // Default to 1 if not found

            foreach ($worker_ids as $worker_id) {
                $worker_id = intval($worker_id);
                
                // Check if already allocated
                $check_sql = "SELECT * FROM `workerallocation` WHERE orderid = ? AND workerid = ?";
                $check_stmt = mysqli_prepare($mysqli, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $worker_id);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($check_stmt)) > 0) {
                    continue; // Skip if already allocated
                }
                
                // Insert allocation
                $insert_sql = "INSERT INTO `workerallocation` (orderid, workerid, managerid, allocation_date, estimated_completion, status) 
                               VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 'Assigned')";
                $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
                mysqli_stmt_bind_param($insert_stmt, "iii", $order_id, $worker_id, $manager_id);
                mysqli_stmt_execute($insert_stmt);
            }
            mysqli_commit($mysqli);
            $success_message = "Workers allocated successfully!";
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get order details
$order_sql = "SELECT o.*, c.cname as client_name FROM `Order` o JOIN `Client` c ON o.clientid = c.clientid WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));

// Get available workers belonging to this supplier (those not already assigned to THIS order)
$worker_sql = "SELECT * FROM `Worker` WHERE supplierid = ? AND workerid NOT IN (SELECT workerid FROM `workerallocation` WHERE orderid = ?)";
$worker_stmt = mysqli_prepare($mysqli, $worker_sql);
mysqli_stmt_bind_param($worker_stmt, "ii", $supplier_id, $order_id);
mysqli_stmt_execute($worker_stmt);
$available_workers = mysqli_stmt_get_result($worker_stmt);

// Get currently allocated workers for this order that belong to this supplier
$allocated_sql = "SELECT w.*, wa.status as allocation_status FROM `Worker` w 
                  JOIN `workerallocation` wa ON w.workerid = wa.workerid 
                  WHERE wa.orderid = ? AND w.supplierid = ?";
$allocated_stmt = mysqli_prepare($mysqli, $allocated_sql);
mysqli_stmt_bind_param($allocated_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($allocated_stmt);
$allocated_workers = mysqli_stmt_get_result($allocated_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Worker Allocation - Supplier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body>
    <div class="container mt-5">
        <h2>Worker Allocation for Order #<?php echo $order_id; ?></h2>
        <p>Client: <?php echo htmlspecialchars($order_info['client_name']); ?></p>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <h4>Your Available Workers</h4>
                <form method="POST">
                    <div class="list-group mb-3">
                        <?php if (mysqli_num_rows($available_workers) > 0): ?>
                            <?php while($worker = mysqli_fetch_assoc($available_workers)): ?>
                                <label class="list-group-item">
                                    <input class="form-check-input me-1" type="checkbox" name="worker_ids[]" value="<?php echo $worker['workerid']; ?>">
                                    <?php echo htmlspecialchars($worker['name']); ?> (<?php echo htmlspecialchars($worker['phone']); ?>)
                                </label>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted">No more available workers to allocate.</p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="allocate_workers" class="btn btn-primary" <?php echo mysqli_num_rows($available_workers) == 0 ? 'disabled' : ''; ?>>Allocate Selected Workers</button>
                </form>
            </div>
            <div class="col-md-6">
                <h4>Your Allocated Workers</h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($allocated_workers) > 0): ?>
                            <?php while($worker = mysqli_fetch_assoc($allocated_workers)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($worker['name']); ?></td>
                                    <td><?php echo htmlspecialchars($worker['phone']); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($worker['allocation_status']); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted">No workers allocated yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">
            <a href="../design_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
