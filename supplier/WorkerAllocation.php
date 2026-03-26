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

// Verify if this supplier is assigned to this order and has accepted it
$check_order_sql = "SELECT orderid FROM `Order` WHERE orderid = ? AND supplierid = ? AND supplier_status = 'Accepted'";
$check_order_stmt = mysqli_prepare($mysqli, $check_order_sql);
mysqli_stmt_bind_param($check_order_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($check_order_stmt);
$check_order_result = mysqli_stmt_get_result($check_order_stmt);
if (mysqli_num_rows($check_order_result) == 0) {
    die("Access Denied: You must accept the assignment before allocating workers.");
}
mysqli_stmt_close($check_order_stmt);

// Initialize variables
$error_message = '';
$success_message = '';

// Handle form submission for NEW allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['allocate_workers'])) {
    if (!isset($_POST['worker_ids']) || empty($_POST['worker_ids'])) {
        $error_message = 'Please select at least one worker.';
    } else {
        $worker_ids = $_POST['worker_ids'];
        $percentages = $_POST['percentages'] ?? [];
        
        // Calculate total percentage from existing allocations
        $existing_sql = "SELECT COALESCE(SUM(percentage), 0) as total_percentage FROM `workerallocation` 
                         WHERE orderid = ? AND status != 'Completed' AND status != 'Cancelled'";
        $existing_stmt = mysqli_prepare($mysqli, $existing_sql);
        mysqli_stmt_bind_param($existing_stmt, "i", $order_id);
        mysqli_stmt_execute($existing_stmt);
        $existing_result = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_stmt));
        $existing_total = floatval($existing_result['total_percentage']);
        
        // Calculate new percentages total
        $new_total = 0;
        foreach ($worker_ids as $worker_id) {
            $worker_id = intval($worker_id);
            $percentage = isset($percentages[$worker_id]) ? floatval($percentages[$worker_id]) : 0;
            $new_total += $percentage;
        }
        
        // Check if total exceeds 100%
        if ($existing_total + $new_total > 100) {
            $error_message = "Error: Total percentage cannot exceed 100%. Current: " . number_format($existing_total, 2) . "%, New: " . number_format($new_total, 2) . "%, Total: " . number_format($existing_total + $new_total, 2) . "%";
        } else {
            mysqli_begin_transaction($mysqli);
            try {
                // Get managerid
                $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `Schedule` WHERE orderid = $order_id LIMIT 1");
                if (mysqli_num_rows($mgr_res) == 0) {
                    $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `OrderDelivery` WHERE orderid = $order_id LIMIT 1");
                }
                $mgr_row = mysqli_fetch_assoc($mgr_res);
                $manager_id = $mgr_row ? $mgr_row['managerid'] : 1;

                foreach ($worker_ids as $worker_id) {
                    $worker_id = intval($worker_id);
                    $percentage = isset($percentages[$worker_id]) ? floatval($percentages[$worker_id]) : 0;
                    
                    // Check if already allocated
                    $check_sql = "SELECT * FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
                    $check_stmt = mysqli_prepare($mysqli, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $worker_id);
                    mysqli_stmt_execute($check_stmt);
                    if (mysqli_num_rows(mysqli_stmt_get_result($check_stmt)) > 0) {
                        continue;
                    }
                    
                    // Insert allocation using the new 'percentage' field
                    $insert_sql = "INSERT INTO `workerallocation` (orderid, workerid, managerid, percentage, status) 
                                   VALUES (?, ?, ?, ?, 'Assigned')";
                    $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
                    mysqli_stmt_bind_param($insert_stmt, "iiid", $order_id, $worker_id, $manager_id, $percentage);
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
}

// Handle UPDATE of existing allocation percentage
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_percentage'])) {
    $allocation_id = intval($_POST['allocation_id']);
    $new_percentage = floatval($_POST['new_percentage']);
    
    // Calculate total percentage from other allocations
    $other_sql = "SELECT COALESCE(SUM(percentage), 0) as total_percentage FROM `workerallocation` 
                  WHERE orderid = ? AND allocation_id != ? AND status != 'Completed' AND status != 'Cancelled'";
    $other_stmt = mysqli_prepare($mysqli, $other_sql);
    mysqli_stmt_bind_param($other_stmt, "ii", $order_id, $allocation_id);
    mysqli_stmt_execute($other_stmt);
    $other_result = mysqli_fetch_assoc(mysqli_stmt_get_result($other_stmt));
    $other_total = floatval($other_result['total_percentage']);
    
    // Check if new total exceeds 100%
    if ($other_total + $new_percentage > 100) {
        $error_message = "Error: Total percentage cannot exceed 100%.";
    } else {
        $update_sql = "UPDATE `workerallocation` SET percentage = ? WHERE allocation_id = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "di", $new_percentage, $allocation_id);
        if (mysqli_stmt_execute($update_stmt)) {
            $success_message = "Percentage updated successfully!";
        } else {
            $error_message = "Failed to update percentage.";
        }
        mysqli_stmt_close($update_stmt);
    }
}

// Get order details
$order_sql = "SELECT o.*, c.cname as client_name, c.address as client_address, c.budget as client_budget, d.designName as design_name, d.designid
              FROM `Order` o 
              JOIN `Client` c ON o.clientid = c.clientid 
              LEFT JOIN `Design` d ON o.designid = d.designid
              WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));

// Get all workers belonging to this supplier
$worker_sql = "SELECT w.*, 
               (SELECT COUNT(*) FROM `workerallocation` wa WHERE wa.workerid = w.workerid AND wa.status != 'Completed' AND wa.status != 'Cancelled') as current_assignments
               FROM `Worker` w 
               WHERE w.supplierid = ?
               ORDER BY w.name";
$worker_stmt = mysqli_prepare($mysqli, $worker_sql);
mysqli_stmt_bind_param($worker_stmt, "i", $supplier_id);
mysqli_stmt_execute($worker_stmt);
$all_workers_res = mysqli_stmt_get_result($worker_stmt);
$all_workers = mysqli_fetch_all($all_workers_res, MYSQLI_ASSOC);

// Separate workers
$available_workers = [];
$unavailable_workers = [];
foreach ($all_workers as $worker) {
    $check_allocated_sql = "SELECT COUNT(*) as count FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
    $check_allocated_stmt = mysqli_prepare($mysqli, $check_allocated_sql);
    mysqli_stmt_bind_param($check_allocated_stmt, "ii", $order_id, $worker['workerid']);
    mysqli_stmt_execute($check_allocated_stmt);
    $check_allocated_result = mysqli_fetch_assoc(mysqli_stmt_get_result($check_allocated_stmt));
    if ($check_allocated_result['count'] > 0) continue;
    
    if ($worker['current_assignments'] > 0) $unavailable_workers[] = $worker;
    else $available_workers[] = $worker;
}

// Get currently allocated workers
$allocated_sql = "SELECT w.*, wa.allocation_id, wa.status as allocation_status, wa.percentage 
                  FROM `Worker` w 
                  JOIN `workerallocation` wa ON w.workerid = wa.workerid 
                  WHERE wa.orderid = ? AND w.supplierid = ?
                  ORDER BY wa.created_at DESC";
$allocated_stmt = mysqli_prepare($mysqli, $allocated_sql);
mysqli_stmt_bind_param($allocated_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($allocated_stmt);
$allocated_workers = mysqli_fetch_all(mysqli_stmt_get_result($allocated_stmt), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Allocation - Supplier Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background-color: #f4f7f6; }
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: none; margin-bottom: 1.5rem; }
        .worker-card { transition: all 0.3s ease; border: 2px solid transparent; cursor: pointer; }
        .worker-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .worker-card.selected { border-color: #28a745; background-color: #f8fff9; }
        .disabled-worker { opacity: 0.6; cursor: not-allowed; background-color: #f8f9fa; border: 2px solid #e9ecef; }
        .worker-img { width: 50px; height: 50px; object-fit: cover; border-radius: 50%; }
        .back-btn { color: #7f8c8d; text-decoration: none; font-weight: 600; transition: all 0.3s; }
        .back-btn:hover { color: #3498db; transform: translateX(-5px); }
        .percentage-input { width: 80px; display: inline-block; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4 mb-5">
        <a href="ProjectWorkerManagement.php" class="back-btn mb-3 d-inline-block">
            <i class="fas fa-arrow-left me-1"></i>Back to Project Management
        </a>

        <div class="row">
            <div class="col-lg-8">
                <div class="card p-4">
                    <h2 class="mb-1">Allocate Workers</h2>
                    <p class="text-muted mb-4">Project #<?= $order_id ?> - Client: <?= htmlspecialchars($order_info['client_name']) ?></p>

                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>

                    <form method="POST">
                        <h5 class="mb-3"><i class="fas fa-user-check text-success me-2"></i>Available Workers</h5>
                        <div class="row g-3 mb-4">
                            <?php if (count($available_workers) > 0): ?>
                                <?php foreach ($available_workers as $worker): ?>
                                    <div class="col-md-6">
                                        <div class="card worker-card p-3 h-100" onclick="toggleWorker(this, <?= $worker['workerid'] ?>)">
                                            <div class="d-flex align-items-center mb-2">
                                                <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                                </div>
                                                <input type="checkbox" name="worker_ids[]" value="<?= $worker['workerid'] ?>" id="worker_<?= $worker['workerid'] ?>" class="form-check-input">
                                            </div>
                                            <div class="mt-2" onclick="event.stopPropagation()">
                                                <label class="small text-muted">Payment %:</label>
                                                <input type="number" name="percentages[<?= $worker['workerid'] ?>]" class="form-control form-control-sm percentage-input" value="0" min="0" max="100" step="0.1">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12"><div class="alert alert-info">No available workers.</div></div>
                            <?php endif; ?>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="allocate_workers" class="btn btn-primary btn-lg px-5 shadow" id="submitBtn" disabled>
                                <i class="fas fa-user-plus me-2"></i>Allocate Selected Workers
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card p-4">
                    <h5 class="mb-4"><i class="fas fa-users-cog me-2"></i>Currently Allocated</h5>
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-3"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>
                    <?php if (count($allocated_workers) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allocated_workers as $worker): ?>
                                <div class="list-group-item px-0 py-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                            <span class="badge bg-info small"><?= htmlspecialchars($worker['allocation_status']) ?></span>
                                        </div>
                                    </div>
                                    <form method="POST" class="d-flex align-items-center gap-2 mt-2">
                                        <input type="hidden" name="allocation_id" value="<?= $worker['allocation_id'] ?>">
                                        <label class="small text-muted">Payment:</label>
                                        <input type="number" name="new_percentage" class="form-control form-control-sm percentage-input" value="<?= number_format($worker['percentage'] ?? 0, 1) ?>" min="0" max="100" step="0.1">
                                        <span class="small">%</span>
                                        <button type="submit" name="update_percentage" class="btn btn-sm btn-outline-primary">Update</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No workers allocated yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleWorker(card, id) {
            const checkbox = document.getElementById('worker_' + id);
            checkbox.checked = !checkbox.checked;
            if (checkbox.checked) card.classList.add('selected');
            else card.classList.remove('selected');
            updateSubmitButton();
        }
        function updateSubmitButton() {
            const checkedCount = document.querySelectorAll('input[name="worker_ids[]"]:checked').length;
            document.getElementById('submitBtn').disabled = checkedCount === 0;
        }
    </script>
</body>
</html>
