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
$check_order_sql = "SELECT DISTINCT od.orderid 
                    FROM `OrderDelivery` od 
                    JOIN `Product` p ON od.productid = p.productid 
                    WHERE od.orderid = ? AND p.supplierid = ?";
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
            // Get managerid from the order schedule or order delivery
            $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `Schedule` WHERE orderid = $order_id LIMIT 1");
            if (mysqli_num_rows($mgr_res) == 0) {
                $mgr_res = mysqli_query($mysqli, "SELECT managerid FROM `OrderDelivery` WHERE orderid = $order_id LIMIT 1");
            }
            $mgr_row = mysqli_fetch_assoc($mgr_res);
            $manager_id = $mgr_row ? $mgr_row['managerid'] : 1; // Default to 1 if not found

            // Get estimated completion from schedule
            $sched_res = mysqli_query($mysqli, "SELECT OrderFinishDate FROM `Schedule` WHERE orderid = $order_id LIMIT 1");
            $sched_row = mysqli_fetch_assoc($sched_res);
            $estimated_completion = $sched_row ? $sched_row['OrderFinishDate'] : date('Y-m-d', strtotime('+7 days'));

            foreach ($worker_ids as $worker_id) {
                $worker_id = intval($worker_id);
                
                // Check if already allocated
                $check_sql = "SELECT * FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
                $check_stmt = mysqli_prepare($mysqli, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $worker_id);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($check_stmt)) > 0) {
                    continue; // Skip if already allocated
                }
                
                // Insert allocation
                $insert_sql = "INSERT INTO `workerallocation` (orderid, workerid, managerid, allocation_date, estimated_completion, status) 
                               VALUES (?, ?, ?, NOW(), ?, 'Assigned')";
                $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
                mysqli_stmt_bind_param($insert_stmt, "iiis", $order_id, $worker_id, $manager_id, $estimated_completion);
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

// Get all workers belonging to this supplier (including those already assigned to THIS order and those with active jobs)
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

// Separate workers into available and unavailable
$available_workers = [];
$unavailable_workers = [];

foreach ($all_workers as $worker) {
    // Check if already allocated to THIS order
    $check_allocated_sql = "SELECT COUNT(*) as count FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
    $check_allocated_stmt = mysqli_prepare($mysqli, $check_allocated_sql);
    mysqli_stmt_bind_param($check_allocated_stmt, "ii", $order_id, $worker['workerid']);
    mysqli_stmt_execute($check_allocated_stmt);
    $check_allocated_result = mysqli_fetch_assoc(mysqli_stmt_get_result($check_allocated_stmt));
    $is_allocated_to_this_order = $check_allocated_result['count'] > 0;
    
    if ($is_allocated_to_this_order) {
        // Already allocated to this order, skip
        continue;
    } elseif ($worker['current_assignments'] > 0) {
        // Has active jobs, mark as unavailable
        $unavailable_workers[] = $worker;
    } else {
        // Available to allocate
        $available_workers[] = $worker;
    }
}

// Get currently allocated workers for this order that belong to this supplier
$allocated_sql = "SELECT w.*, wa.status as allocation_status, wa.allocation_date, wa.estimated_completion 
                  FROM `Worker` w 
                  JOIN `workerallocation` wa ON w.workerid = wa.workerid 
                  WHERE wa.orderid = ? AND w.supplierid = ?
                  ORDER BY wa.allocation_date DESC";
$allocated_stmt = mysqli_prepare($mysqli, $allocated_sql);
mysqli_stmt_bind_param($allocated_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($allocated_stmt);
$allocated_workers_res = mysqli_stmt_get_result($allocated_stmt);
$allocated_workers = mysqli_fetch_all($allocated_workers_res, MYSQLI_ASSOC);
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
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05 ); border: none; margin-bottom: 1.5rem; }
        .worker-card { transition: all 0.3s ease; border: 2px solid transparent; cursor: pointer; }
        .worker-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .worker-card.selected { border-color: #28a745; background-color: #f8fff9; }
        .disabled-worker { opacity: 0.6; cursor: not-allowed; background-color: #f8f9fa; border: 2px solid #e9ecef; }
        .worker-img { width: 50px; height: 50px; object-fit: cover; border-radius: 50%; }
        .back-btn { color: #7f8c8d; text-decoration: none; font-weight: 600; transition: all 0.3s; }
        .back-btn:hover { color: #3498db; transform: translateX(-5px); }
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
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Allocate Workers</h2>
                            <p class="text-muted mb-0">Project #<?= $order_id ?> - Client: <?= htmlspecialchars($order_info['client_name']) ?></p>
                        </div>
                        <div>
                            <span class="badge bg-success me-2"><?= count($available_workers) ?> Available</span>
                            <span class="badge bg-warning"><?= count($unavailable_workers) ?> Busy</span>
                        </div>
                    </div>

                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?= $error_message ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="allocationForm">
                        <!-- Available Workers Section -->
                        <div class="mb-5">
                            <h5 class="mb-3"><i class="fas fa-check-circle text-success me-2"></i>Available Workers</h5>
                            <div class="row g-3 mb-4">
                                <?php if (count($available_workers) > 0): ?>
                                    <?php foreach ($available_workers as $worker): ?>
                                        <div class="col-md-6">
                                            <div class="card worker-card p-3 h-100" onclick="toggleWorker(this, <?= $worker['workerid'] ?>)">
                                                <div class="d-flex align-items-center">
                                                    <input type="checkbox" name="worker_ids[]" value="<?= $worker['workerid'] ?>" class="d-none" id="worker_<?= $worker['workerid'] ?>">
                                                    <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                        <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-success">
                                                            Available
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-12 text-center py-3">
                                        <p class="text-muted mb-0">No available workers.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Busy Workers Section -->
                        <?php if (count($unavailable_workers) > 0): ?>
                            <div class="mb-5">
                                <h5 class="mb-3"><i class="fas fa-hourglass-end text-warning me-2"></i>Busy Workers (Cannot Select)</h5>
                                <div class="row g-3 mb-4">
                                    <?php foreach ($unavailable_workers as $worker): ?>
                                        <div class="col-md-6">
                                            <div class="card worker-card p-3 h-100 disabled-worker">
                                                <div class="d-flex align-items-center">
                                                    <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                        <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-warning">
                                                            <?= $worker['current_assignments'] ?> job<?= $worker['current_assignments'] > 1 ? 's' : '' ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid">
                            <button type="submit" name="allocate_workers" class="btn btn-success btn-lg" id="submitBtn" disabled>
                                <i class="fas fa-user-plus me-2"></i>Allocate Selected Workers
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card p-4">
                    <h4 class="mb-4">Allocated Workers</h4>
                    <?php if (count($allocated_workers) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allocated_workers as $worker): ?>
                                <div class="list-group-item px-0 py-3">
                                    <div class="d-flex align-items-center">
                                        <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                            <small class="text-muted d-block"><?= htmlspecialchars($worker['allocation_status']) ?></small>
                                            <small class="text-muted" style="font-size: 0.75rem;">
                                                Until: <?= date('M d, Y', strtotime($worker['estimated_completion'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-muted mb-0">No workers allocated yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleWorker(card, id ) {
            const checkbox = document.getElementById('worker_' + id);
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
            
            updateSubmitButton();
        }

        function updateSubmitButton() {
            const checkedCount = document.querySelectorAll('input[name="worker_ids[]"]:checked').length;
            document.getElementById('submitBtn').disabled = checkedCount === 0;
        }
    </script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>
