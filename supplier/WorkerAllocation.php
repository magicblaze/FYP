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
$supplier_id = $user["supplierid"];

// Fetch supplier's default worker pay
$default_pay_sql = "SELECT default_worker_pay FROM `Supplier` WHERE supplierid = ?";
$default_pay_stmt = mysqli_prepare($mysqli, $default_pay_sql);
mysqli_stmt_bind_param($default_pay_stmt, "i", $supplier_id);
mysqli_stmt_execute($default_pay_stmt);
$default_pay_result = mysqli_stmt_get_result($default_pay_stmt);
$supplier_default_pay = mysqli_fetch_assoc($default_pay_result)["default_worker_pay"] ?? 0.00;
mysqli_stmt_close($default_pay_stmt);

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

function upsertWorkerDistributionRecord($mysqli, $allocation_id, $default_percentage)
{
    $select_sql = "SELECT wa.orderid,
                          wa.workerid,
                          wa.notes,
                          IFNULL(wa.status, 'Assigned') AS allocation_status,
                          IFNULL(o.budget, 0) AS order_budget,
                          COALESCE(NULLIF(TRIM(w.name), ''), CONCAT('Worker #', wa.workerid)) AS worker_name
                   FROM `workerallocation` wa
                   LEFT JOIN `Order` o ON o.orderid = wa.orderid
                   LEFT JOIN `Worker` w ON w.workerid = wa.workerid
                   WHERE wa.allocation_id = ?
                   LIMIT 1";
    $select_stmt = mysqli_prepare($mysqli, $select_sql);
    if (!$select_stmt) {
        return false;
    }
    mysqli_stmt_bind_param($select_stmt, "i", $allocation_id);
    mysqli_stmt_execute($select_stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select_stmt));
    mysqli_stmt_close($select_stmt);

    if (!$row) {
        return false;
    }

    $orderid = intval($row['orderid']);
    $percentage = floatval($default_percentage);
    $amount = (floatval($row['order_budget']) * $percentage) / 100;
    $description = trim((string)($row['notes'] ?? ''));
    $is_active = strcasecmp(trim((string)($row['allocation_status'] ?? 'Assigned')), 'Cancelled') === 0 ? 0 : 1;
    $entry_type = 'worker';
    $worker_allocation_id = intval($allocation_id);
    $distribution_name = (string)$row['worker_name'];

    $upsert_sql = "INSERT INTO `OrderConstDistri`
                   (`orderid`, `entry_type`, `worker_allocation_id`, `distribution_name`, `percentage`, `amount`, `description`, `is_active`)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                   `distribution_name` = VALUES(`distribution_name`),
                   `percentage` = VALUES(`percentage`),
                   `amount` = VALUES(`amount`),
                   `description` = VALUES(`description`),
                   `is_active` = VALUES(`is_active`)";
    $upsert_stmt = mysqli_prepare($mysqli, $upsert_sql);
    if (!$upsert_stmt) {
        return false;
    }
    mysqli_stmt_bind_param($upsert_stmt, "isisddsi", $orderid, $entry_type, $worker_allocation_id, $distribution_name, $percentage, $amount, $description, $is_active);
    $ok = mysqli_stmt_execute($upsert_stmt);
    mysqli_stmt_close($upsert_stmt);
    return $ok;
}

// Initialize variables
$error_message = '';
$success_message = '';

// Handle form submission for NEW allocation (SELECTION ONLY)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['allocate_workers'])) {
    if (!isset($_POST['worker_ids']) || empty($_POST['worker_ids'])) {
        $error_message = 'Please select at least one worker.';
    } else {
        $worker_ids = $_POST['worker_ids'];
        
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
                
                // Check if already allocated
                $check_sql = "SELECT * FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
                $check_stmt = mysqli_prepare($mysqli, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $worker_id);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($check_stmt)) > 0) {
                    mysqli_stmt_close($check_stmt);
                    continue;
                }
                mysqli_stmt_close($check_stmt);
                
                // Distribution percentages are stored in OrderConstDistri, not workerallocation.
                $insert_sql = "INSERT INTO `workerallocation` (orderid, workerid, managerid, status) 
                               VALUES (?, ?, ?, 'Assigned')";
                $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
                mysqli_stmt_bind_param($insert_stmt, "iii", $order_id, $worker_id, $manager_id);
                if (!mysqli_stmt_execute($insert_stmt)) {
                    throw new Exception(mysqli_error($mysqli));
                }
                $allocation_id = mysqli_insert_id($mysqli);
                mysqli_stmt_close($insert_stmt);

                if (!upsertWorkerDistributionRecord($mysqli, $allocation_id, $supplier_default_pay)) {
                    throw new Exception('Failed to create worker distribution record.');
                }
            }
            mysqli_commit($mysqli);
            $success_message = "Workers allocated successfully!";
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Handle deletion of existing allocation
if (isset($_POST['delete_allocation'])) {
    $allocation_id = intval($_POST['allocation_id']);
    $delete_sql = "DELETE FROM `workerallocation` WHERE allocation_id = ? AND orderid = ?";
    $delete_stmt = mysqli_prepare($mysqli, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "ii", $allocation_id, $order_id);
    if (mysqli_stmt_execute($delete_stmt)) {
        $success_message = "Worker allocation removed.";
    } else {
        $error_message = "Failed to remove worker.";
    }
}

// Get order details
$order_sql = "SELECT o.*, c.cname as client_name FROM `Order` o JOIN `Client` c ON o.clientid = c.clientid WHERE o.orderid = ?";
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
foreach ($all_workers as $worker) {
    // Modified: Check if worker is assigned to ANY active project (not just the current one)
    $check_allocated_sql = "SELECT COUNT(*) as count FROM `workerallocation` WHERE workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
    $check_allocated_stmt = mysqli_prepare($mysqli, $check_allocated_sql);
    mysqli_stmt_bind_param($check_allocated_stmt, "i", $worker['workerid']);
    mysqli_stmt_execute($check_allocated_stmt);
    $check_allocated_result = mysqli_fetch_assoc(mysqli_stmt_get_result($check_allocated_stmt));
    
    // If they have any active assignments elsewhere, they are not available
    if ($check_allocated_result['count'] > 0) continue;
    
    $available_workers[] = $worker;
}

// Get currently allocated workers
$allocated_sql = "SELECT w.*, wa.allocation_id, wa.status as allocation_status 
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
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05 ); border: none; margin-bottom: 1.5rem; }
        .worker-card { transition: all 0.3s ease; border: 2px solid transparent; cursor: pointer; }
        .worker-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .worker-card.selected { border-color: #28a745; background-color: #f8fff9; }
        .worker-img { width: 50px; height: 50px; object-fit: cover; border-radius: 50%; }
        .back-btn { color: #7f8c8d; text-decoration: none; font-weight: 600; transition: all 0.3s; }
        .back-btn:hover { color: #3498db; transform: translateX(-5px); }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="ProjectWorkerManagement.php" class="back-btn">
                <i class="fas fa-arrow-left me-1"></i>Back to Projects
            </a>
        </div>

        <div class="row">
            <!-- Left Side: Selection -->
            <div class="col-lg-7">
                <div class="card p-4 h-100">
                    <h2 class="mb-1">Select Workers</h2>
                    <p class="text-muted mb-4">Choose workers to assign to Project #<?= $order_id ?></p>

                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="row g-3 mb-4">
                            <?php if (count($available_workers) > 0): ?>
                                <?php foreach ($available_workers as $worker): ?>
                                    <div class="col-md-6">
                                        <div class="card worker-card p-3 h-100 shadow-sm border" onclick="toggleWorker(this, <?= $worker['workerid'] ?>)">
                                            <div class="d-flex align-items-center">
                                                <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3 border">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                                </div>
                                                <input type="checkbox" name="worker_ids[]" value="<?= $worker['workerid'] ?>" id="worker_<?= $worker['workerid'] ?>" class="form-check-input">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12"><div class="alert alert-info">No more available workers.</div></div>
                            <?php endif; ?>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="allocate_workers" class="btn btn-success btn-lg px-5 shadow" id="submitBtn" disabled>
                                <i class="fas fa-plus-circle me-2"></i>Allocate Selected
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Side: Allocated Workers Overview -->
            <div class="col-lg-5">
                <div class="card p-4 h-100">
                    <h2 class="mb-1">Current Team</h2>
                    <p class="text-muted mb-4">Workers already assigned to this project.</p>

                    <?php if (count($allocated_workers) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allocated_workers as $worker): ?>
                                <div class="list-group-item px-0 py-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3 border">
                                            <div>
                                                <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                <span class="badge bg-info small"><?= htmlspecialchars($worker['allocation_status']) ?></span>
                                            </div>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Remove this worker?')">
                                            <input type="hidden" name="allocation_id" value="<?= $worker['allocation_id'] ?>">
                                            <button type="submit" name="delete_allocation" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users-slash fa-3x mb-3"></i>
                            <p>No workers assigned yet.</p>
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
