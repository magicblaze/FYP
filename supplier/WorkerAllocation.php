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

// Get order details (修正了欄位名稱)
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
        continue;
    } elseif ($worker['current_assignments'] > 0) {
        $unavailable_workers[] = $worker;
    } else {
        $available_workers[] = $worker;
    }
}

// Get currently allocated workers
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

// ===== FETCH ORDER_EDIT DATA FOR MODAL =====
// This data is used to populate the modal with Order_Edit.php information
$edit_order_sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.deposit, o.supplierid, o.supplier_status,
                          c.clientid, c.cname as client_name, c.ctel, c.cemail, c.budget,
                          d.designid, d.designName, d.expect_price as design_price, d.tag as design_tag, d.supplierid as design_supplierid,
                          s.scheduleid, s.OrderFinishDate, s.DesignFinishDate,
                          op.payment_id, op.total_design_payment, op.total_construction_payment, op.materials_cost,
                          op.commission_1st, op.commission_final, op.total_amount_due
                   FROM `Order` o
                   LEFT JOIN `Client` c ON o.clientid = c.clientid
                   LEFT JOIN `Design` d ON o.designid = d.designid
                   LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                   LEFT JOIN `OrderPayment` op ON o.payment_id = op.payment_id
                   WHERE o.orderid = ?";

$edit_stmt = mysqli_prepare($mysqli, $edit_order_sql);
mysqli_stmt_bind_param($edit_stmt, "i", $order_id);
mysqli_stmt_execute($edit_stmt);
$edit_order = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt));

// Fetch order references
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
mysqli_stmt_bind_param($ref_stmt, "i", $order_id);
mysqli_stmt_execute($ref_stmt);
$ref_result = mysqli_stmt_get_result($ref_stmt);
$references = array();
while ($ref_row = mysqli_fetch_assoc($ref_result)) {
    $references[] = $ref_row;
}

// Fetch additional fees
$fees_sql = "SELECT fee_id, fee_name, amount, description, created_at FROM `AdditionalFee` WHERE orderid = ? ORDER BY created_at ASC";
$fees_stmt = mysqli_prepare($mysqli, $fees_sql);
mysqli_stmt_bind_param($fees_stmt, "i", $order_id);
mysqli_stmt_execute($fees_stmt);
$fees_result = mysqli_stmt_get_result($fees_stmt);
$fees = array();
$total_fees = 0;
while ($fee_row = mysqli_fetch_assoc($fees_result)) {
    $fees[] = $fee_row;
    $total_fees += floatval($fee_row['amount']);
}

// Calculate totals
$design_price = isset($edit_order["design_price"]) ? floatval($edit_order["design_price"]) : 0;
$original_budget = floatval($edit_order['budget'] ?? 0);

$payment = [
    'total_design_payment' => isset($edit_order['total_design_payment']) ? (float) $edit_order['total_design_payment'] : 0.0,
    'total_construction_payment' => isset($edit_order['total_construction_payment']) ? (float) $edit_order['total_construction_payment'] : 0.0,
    'materials_cost' => isset($edit_order['materials_cost']) ? (float) $edit_order['materials_cost'] : 0.0,
    'commission_1st' => isset($edit_order['commission_1st']) ? (float) $edit_order['commission_1st'] : 0.0,
    'commission_final' => isset($edit_order['commission_final']) ? (float) $edit_order['commission_final'] : 0.0,
    'total_amount_due' => isset($edit_order['total_amount_due']) ? (float) $edit_order['total_amount_due'] : 0.0,
];

$commission_total = $payment['commission_1st'] + $payment['commission_final'];
$construction_cost_ex_material = max(0, $payment['total_construction_payment'] - $payment['materials_cost']);

$references_total = 0.0;
if (!empty($references)) {
    foreach ($references as $r) {
        $rprice = isset($r['price']) && $r['price'] !== null ? (float) $r['price'] : (float) ($r['product_price'] ?? 0);
        $references_total += $rprice;
    }
}

$final_total_cost = $payment['total_design_payment']
    + $construction_cost_ex_material
    + $commission_total
    + $references_total
    + $total_fees;

$deducted_amount = $design_price;
$remaining_budget = $original_budget - $deducted_amount;
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
        
        /* Modal styles */
        .detail-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label {
            color: #7f8c8d;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .info-value {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .table thead th {
            background-color: #f8f9fa;
            border-top: none;
            font-size: 13px;
            color: #7f8c8d;
        }

        .total-row {
            font-size: 16px;
            font-weight: 700;
            background: #f8f9fa;
        }
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

                    <!-- 項目資訊卡片 -->
                    <div class="card mb-4 border" style="background-color: #fcfcfc;">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-dark"><i class="fas fa-info-circle me-2"></i>Project Details</h6>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal">
                                <i class="fas fa-eye me-1"></i>Detail
                            </button>
                        </div>
                        <div class="card-body py-3">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <p class="mb-1 text-muted small">Client & Address</p>
                                    <p class="mb-0"><strong><?= htmlspecialchars($order_info['client_name']) ?></strong></p>
                                    <p class="mb-0 small"><?= htmlspecialchars($order_info['client_address'] ?? 'N/A') ?></p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1 text-muted small">Design & Area</p>
                                    <p class="mb-0"><strong><?= htmlspecialchars($order_info['design_name'] ?? 'N/A') ?></strong> (#<?= htmlspecialchars($order_info['designid'] ?? 'N/A') ?>)</p>
                                    <p class="mb-0 small"><?= htmlspecialchars($order_info['gross_floor_area'] ?? 'N/A') ?> sq. ft.</p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1 text-muted small">Budget</p>
                                    <p class="mb-0"><strong>$<?= number_format($order_info['client_budget'] ?? 0, 2) ?></strong></p>
                                </div>
                                <div class="col-12">
                                    <p class="mb-1 text-muted small">Requirements</p>
                                    <p class="mb-0 small text-dark"><?= nl2br(htmlspecialchars($order_info['Requirements'] ?? 'No specific requirements.')) ?></p>
                                </div>
                            </div>
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

                    <form method="POST">
                        <h5 class="mb-3"><i class="fas fa-user-check text-success me-2"></i>Available Workers</h5>
                        <div class="row g-3">
                            <?php if (count($available_workers) > 0): ?>
                                <?php foreach ($available_workers as $worker): ?>
                                    <div class="col-md-6">
                                        <div class="card worker-card p-3 h-100" onclick="toggleWorker(this, <?= $worker['workerid'] ?>)">
                                            <div class="d-flex align-items-center">
                                                <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                                </div>
                                                <input type="checkbox" name="worker_ids[]" value="<?= $worker['workerid'] ?>" id="worker_<?= $worker['workerid'] ?>" class="form-check-input" style="cursor: pointer;">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="alert alert-info">No available workers at the moment.</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <h5 class="mb-3"><i class="fas fa-clock text-warning me-2"></i>Currently Busy</h5>
                        <div class="row g-3">
                            <?php foreach ($unavailable_workers as $worker): ?>
                                <div class="col-md-6">
                                    <div class="card disabled-worker p-3 h-100">
                                        <div class="d-flex align-items-center">
                                            <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                                <small class="text-muted"><?= htmlspecialchars($worker['certificate'] ?: 'General Worker') ?></small>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-warning text-dark"><?= $worker['current_assignments'] ?> Active Jobs</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="sticky-bottom bg-white p-3 border-top text-end" style="z-index: 100;">
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
                    <?php if (count($allocated_workers) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allocated_workers as $worker): ?>
                                <div class="list-group-item px-0 py-3">
                                    <div class="d-flex align-items-center">
                                        <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-3" alt="<?= htmlspecialchars($worker['name']) ?>">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?= htmlspecialchars($worker['name']) ?></h6>
                                            <small class="text-muted">Assigned: <?= date('M d, Y', strtotime($worker['allocation_date'])) ?></small>
                                        </div>
                                        <span class="badge bg-light text-success border border-success"><?= $worker['allocation_status'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No workers allocated to this project yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Project Details - Using Order_Edit.php Data -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-lg-down modal-dialog-centered modal-dialog-scrollable" style="max-width: 95vw;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailsModalLabel">
                        <i class="fas fa-file-alt me-2"></i>Project Details - Order #<?= $order_id ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Project Overview -->
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i> Project Overview
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Order ID</div>
                                <div class="info-value">#<?= htmlspecialchars($edit_order['orderid']) ?></div>

                                <div class="info-label">Design Name</div>
                                <div class="info-value"><?= htmlspecialchars($edit_order['designName'] ?? 'Custom Request') ?></div>

                                <div class="info-label">Project Date</div>
                                <div class="info-value"><?= date('F d, Y', strtotime($edit_order['odate'])) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <span class="status-badge bg-primary text-white"><?= htmlspecialchars($edit_order['ostatus']) ?></span>
                                </div>

                                <div class="info-label">Estimated Completion</div>
                                <div class="info-value text-primary">
                                    <?= $edit_order['OrderFinishDate'] ? date('F d, Y', strtotime($edit_order['OrderFinishDate'])) : 'TBD' ?>
                                </div>

                                <div class="info-label">Design Finish Date</div>
                                <div class="info-value">
                                    <?= $edit_order['DesignFinishDate'] ? date('F d, Y', strtotime($edit_order['DesignFinishDate'])) : 'TBD' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client Information -->
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-user"></i> Client Information
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Client Name</div>
                                <div class="info-value"><?= htmlspecialchars($edit_order['client_name']) ?></div>

                                <div class="info-label">Phone</div>
                                <div class="info-value"><?= htmlspecialchars($edit_order['ctel'] ?? 'N/A') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?= htmlspecialchars($edit_order['cemail'] ?? 'N/A') ?></div>

                                <div class="info-label">Budget</div>
                                <div class="info-value text-success">$<?= number_format($edit_order['budget'] ?? 0, 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Requirements -->
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-list"></i> Requirements
                        </div>
                        <p class="text-dark"><?= nl2br(htmlspecialchars($edit_order['Requirements'] ?? 'No specific requirements')) ?></p>
                    </div>

                    <!-- Products & Materials -->
                    <?php if (!empty($references)): ?>
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-couch"></i> Products & Materials
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th class="text-end">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($references as $ref): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($ref['pname'] ?? 'Unknown Item') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($ref['note'] ?? '') ?></small>
                                            </td>
                                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars($ref['category'] ?? 'N/A') ?></span></td>
                                            <td><?= htmlspecialchars($ref['status'] ?? 'Pending') ?></td>
                                            <td class="text-end">$<?= number_format($ref['price'] ?? 0, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Additional Fees -->
                    <?php if (!empty($fees)): ?>
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-plus-circle"></i> Additional Fees
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Fee Name</th>
                                        <th>Description</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fees as $fee): ?>
                                        <tr>
                                            <td class="fw-bold"><?= htmlspecialchars($fee['fee_name']) ?></td>
                                            <td><?= htmlspecialchars($fee['description']) ?></td>
                                            <td class="text-end">$<?= number_format($fee['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Cost Summary -->
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-calculator"></i> Cost Summary
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Design Payment</div>
                                <div class="info-value">$<?= number_format($payment['total_design_payment'], 2) ?></div>

                                <div class="info-label">Construction Payment</div>
                                <div class="info-value">$<?= number_format($payment['total_construction_payment'], 2) ?></div>

                                <div class="info-label">Materials Cost</div>
                                <div class="info-value">$<?= number_format($payment['materials_cost'], 2) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Commission (1st)</div>
                                <div class="info-value">$<?= number_format($payment['commission_1st'], 2) ?></div>

                                <div class="info-label">Commission (Final)</div>
                                <div class="info-value">$<?= number_format($payment['commission_final'], 2) ?></div>

                                <div class="info-label">Additional Fees</div>
                                <div class="info-value">$<?= number_format($total_fees, 2) ?></div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-12">
                                <div class="info-label">Total Amount Due</div>
                                <div class="info-value text-success" style="font-size: 18px;">$<?= number_format($payment['total_amount_due'], 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Information -->
                    <div class="detail-card">
                        <div class="section-title">
                            <i class="fas fa-wallet"></i> Budget Information
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Original Budget</div>
                                <div class="info-value">$<?= number_format($original_budget, 2) ?></div>

                                <div class="info-label">Design Fee (Deducted)</div>
                                <div class="info-value">$<?= number_format($deducted_amount, 2) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Remaining Budget</div>
                                <div class="info-value text-primary">$<?= number_format($remaining_budget, 2) ?></div>

                                <div class="info-label">Deposit</div>
                                <div class="info-value">$<?= number_format($edit_order['deposit'] ?? 0, 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleWorker(card, id) {
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
</body>
</html>
