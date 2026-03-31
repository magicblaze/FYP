<?php
// supplier/ProjectWorkerManagement.php
// Dedicated page for suppliers to manage worker allocations across all projects
require_once dirname(__DIR__) . 
'/config.php';
session_start();

// Check if user is logged in as supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$supplier_id = $user['supplierid'];

// Handle Accept/Reject Assignment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["handle_assignment"])) {
    $order_id = intval($_POST["order_id"]);
    $action = $_POST["action"]; // 'Accepted' or 'Rejected'
    
    if (in_array($action, ['Accepted', 'Rejected'])) {
        if ($action === 'Accepted') {
            $update_sql = "UPDATE `Order` 
                           SET supplier_status = ?,
                               ostatus = CASE 
                                   WHEN LOWER(ostatus) = 'coordinating contractors' THEN 'preparing'
                                   ELSE ostatus
                               END
                           WHERE orderid = ? AND supplierid = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "sii", $action, $order_id, $supplier_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        } else {
            $update_sql = "UPDATE `Order` SET supplier_status = ? WHERE orderid = ? AND supplierid = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "sii", $action, $order_id, $supplier_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
        header("Location: ProjectWorkerManagement.php");
        exit();
    }
}

// Fetch all projects (orders) that contain this supplier's products
$projects_sql = "
    SELECT DISTINCT 
        o.orderid,
        o.odate,
        o.ostatus,
        o.supplier_status,
        o.Requirements,
        o.budget,
        c.cname as client_name,
        c.cemail,
        c.ctel,
        c.address,
        (SELECT COUNT(DISTINCT od2.productid) FROM `OrderDelivery` od2 JOIN `Product` p2 ON od2.productid = p2.productid WHERE od2.orderid = o.orderid AND p2.supplierid = ?) as product_count,
        (SELECT COUNT(DISTINCT wa.workerid) FROM `workerallocation` wa JOIN `Worker` w ON wa.workerid = w.workerid WHERE wa.orderid = o.orderid AND w.supplierid = ?) as allocated_workers
    FROM `Order` o
    JOIN Client c ON o.clientid = c.clientid
    WHERE o.supplierid = ?
    ORDER BY o.odate DESC
";

$projects_stmt = mysqli_prepare($mysqli, $projects_sql);
mysqli_stmt_bind_param($projects_stmt, "iii", $supplier_id, $supplier_id, $supplier_id);
mysqli_stmt_execute($projects_stmt);
$projects_result = mysqli_stmt_get_result($projects_stmt);
$projects = [];
while ($row = mysqli_fetch_assoc($projects_result)) {
    $order_id = $row['orderid'];
    
    // --- FETCH FULL DETAILS FOR MODAL (Sync with WorkerAllocation.php) ---
    
    // 1. Fetch Order Edit Data
    $edit_order_sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.deposit, o.supplierid, o.supplier_status,
                              c.clientid, c.cname as client_name, c.ctel, c.cemail, c.budget,
                              d.designid, d.designName, d.expect_price as design_price, d.tag as design_tag, d.supplierid as design_supplierid,
                              s.scheduleid, s.OrderFinishDate, s.DesignFinishDate,
                              op.payment_id, op.total_design_payment, op.total_construction_payment, op.materials_cost,
                               op.commission_1st, op.commission_final, op.total_amount_due,
                               dp.filename as designed_picture_filename
                       FROM `Order` o
                       LEFT JOIN `Client` c ON o.clientid = c.clientid
                       LEFT JOIN `Design` d ON o.designid = d.designid
                       LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                       LEFT JOIN `OrderPayment` op ON o.payment_id = op.payment_id
                       LEFT JOIN `DesignedPicture` dp ON o.orderid = dp.orderid AND dp.is_current = TRUE
                       WHERE o.orderid = ?";
    $edit_stmt = mysqli_prepare($mysqli, $edit_order_sql);
    mysqli_stmt_bind_param($edit_stmt, "i", $order_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_order = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt));
    $row['edit_order'] = $edit_order;

    // 2. Fetch Order References
    $hasRefColor = false;
    $hasRefQuantity = false;
    $refColorRes = mysqli_query($mysqli, "SHOW COLUMNS FROM `OrderReference` LIKE 'color'");
    if ($refColorRes) { $hasRefColor = (mysqli_num_rows($refColorRes) > 0); mysqli_free_result($refColorRes); }
    $refQuantityRes = mysqli_query($mysqli, "SHOW COLUMNS FROM `OrderReference` LIKE 'quantity'");
    if ($refQuantityRes) { $hasRefQuantity = (mysqli_num_rows($refQuantityRes) > 0); mysqli_free_result($refQuantityRes); }
    
    $refColorSelect = $hasRefColor ? 'orr.color' : 'NULL AS color';
    $refQuantitySelect = $hasRefQuantity ? 'orr.quantity' : 'NULL AS quantity';
    
    $ref_sql = "SELECT orr.id, orr.productid, {$refColorSelect}, {$refQuantitySelect}, orr.status, orr.price, orr.note,
                       p.pname, p.price as product_price, p.category, p.description as product_description
                FROM `OrderReference` orr
                LEFT JOIN `Product` p ON orr.productid = p.productid
                WHERE orr.orderid = ?";
    $ref_stmt = mysqli_prepare($mysqli, $ref_sql);
    mysqli_stmt_bind_param($ref_stmt, "i", $order_id);
    mysqli_stmt_execute($ref_stmt);
    $ref_result = mysqli_stmt_get_result($ref_stmt);
    $references = mysqli_fetch_all($ref_result, MYSQLI_ASSOC);
    $row['references'] = $references;

    // 3. Fetch Additional Fees
    $fees_sql = "SELECT fee_id, fee_name, amount, description, created_at FROM `AdditionalFee` WHERE orderid = ? ORDER BY created_at ASC";
    $fees_stmt = mysqli_prepare($mysqli, $fees_sql);
    mysqli_stmt_bind_param($fees_stmt, "i", $order_id);
    mysqli_stmt_execute($fees_stmt);
    $fees_result = mysqli_stmt_get_result($fees_stmt);
    $fees = mysqli_fetch_all($fees_result, MYSQLI_ASSOC);
    $row['fees'] = $fees;
    
    $total_fees = 0;
    foreach ($fees as $f) { $total_fees += floatval($f['amount']); }
    $row['total_fees'] = $total_fees;

    // 4. Calculations
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
    $row['payment'] = $payment;
    
    $references_total = 0.0;
    foreach ($references as $r) {
        $rprice = isset($r['price']) && $r['price'] !== null ? (float) $r['price'] : (float) ($r['product_price'] ?? 0);
        $references_total += $rprice;
    }
    $row['references_total'] = $references_total;
    $row['deducted_amount'] = $design_price;
    $row['remaining_budget'] = $original_budget - $design_price;

    $projects[] = $row;
}
mysqli_stmt_close($projects_stmt);

// Get total statistics
$stats_sql = "
    SELECT 
        COUNT(DISTINCT o.orderid) as total_projects,
        (SELECT COUNT(DISTINCT wa.workerid) FROM `workerallocation` wa JOIN `Worker` w ON wa.workerid = w.workerid WHERE w.supplierid = ?) as total_allocated_workers,
        (SELECT COUNT(DISTINCT w.workerid) FROM `Worker` w WHERE w.supplierid = ?) as total_available_workers
    FROM `Order` o
    WHERE o.supplierid = ?
";
$stats_stmt = mysqli_prepare($mysqli, $stats_sql);
mysqli_stmt_bind_param($stats_stmt, "iii", $supplier_id, $supplier_id, $supplier_id);
mysqli_stmt_execute($stats_stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt));
mysqli_stmt_close($stats_stmt);

// Get available workers count
$workers_sql = "SELECT COUNT(*) as total_workers FROM Worker WHERE supplierid = ?";
$workers_stmt = mysqli_prepare($mysqli, $workers_sql);
mysqli_stmt_bind_param($workers_stmt, "i", $supplier_id);
mysqli_stmt_execute($workers_stmt);
$workers_count = mysqli_fetch_assoc(mysqli_stmt_get_result($workers_stmt));
mysqli_stmt_close($workers_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Worker Management - Supplier Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background-color: #f4f7f6; }
        .stat-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05 ); text-align: center; margin-bottom: 1.5rem; transition: all 0.3s ease; }
        .stat-card:hover { box-shadow: 0 4px 16px rgba(52, 152, 219, 0.15); transform: translateY(-2px); }
        .stat-number { font-size: 2.5rem; font-weight: 700; color: #3498db; margin-bottom: 0.5rem; }
        .stat-label { color: #7f8c8d; font-size: 0.95rem; font-weight: 500; }
        .project-card { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); border-left: 4px solid #3498db; transition: all 0.3s ease; }
        .project-card:hover { box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); transform: translateX(5px); }
        .project-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .project-title { font-size: 1.2rem; font-weight: 600; color: #2c3e50; margin-bottom: 0.5rem; }
        .status-badge { padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .project-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #ecf0f1; }
        .info-label { color: #7f8c8d; font-size: 13px; margin-bottom: 2px; }
        .info-value { font-weight: 600; color: #2c3e50; }
        .action-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .btn-allocate, .btn-detail { border: none; color: white; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; }
        .btn-allocate { background: #27ae60; }
        .btn-allocate:hover { background: #229954; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3); }
        .btn-detail { background: #3498db; }
        .btn-detail:hover { background: #2980b9; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3); }
        .back-btn { display: inline-flex; align-items: center; gap: 0.5rem; color: #7f8c8d; text-decoration: none; font-weight: 600; margin-bottom: 1.5rem; transition: all 0.3s; }
        .back-btn:hover { color: #3498db; transform: translateX(-5px); }

        /* Modal Styles (Synced with WorkerAllocation.php) */
        .detail-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05); }
        .section-title { font-size: 18px; font-weight: 700; color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #eee; display: flex; align-items: center; gap: 10px; }
        .table thead th { background-color: #f8f9fa; border-top: none; font-size: 13px; color: #7f8c8d; }
        .modal-header { background: #3498db; color: white; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    </br>
    <div class="container mb-5">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>Back to Dashboard
        </a>

        <!-- Statistics Section -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total_projects'] ?? 0 ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $workers_count['total_workers'] ?? 0 ?></div>
                    <div class="stat-label">Available Workers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total_allocated_workers'] ?? 0 ?></div>
                    <div class="stat-label">Allocated Workers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= ($workers_count['total_workers'] ?? 0) - ($stats['total_allocated_workers'] ?? 0) ?></div>
                    <div class="stat-label">Unallocated Workers</div>
                </div>
            </div>
        </div>

        <h3 class="mb-3">Your Projects</h3>
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <?php 
                    $edit_order = $project['edit_order'];
                    $references = $project['references'];
                    $fees = $project['fees'];
                    $payment = $project['payment'];
                    $total_fees = $project['total_fees'];
                    $references_total = $project['references_total'];
                    $deducted_amount = $project['deducted_amount'];
                    $remaining_budget = $project['remaining_budget'];
                    $original_budget = floatval($edit_order['budget'] ?? 0);
                ?>
                <div class="project-card">
                    <div class="project-header">
                        <div>
                            <div class="project-title">Project #<?= $project['orderid'] ?></div>
                            <div class="project-client"><i class="fas fa-user me-1"></i><?= htmlspecialchars($project['client_name']) ?></div>
                            <div class="project-date"><i class="fas fa-calendar me-1"></i><?= date('M d, Y', strtotime($project['odate'])) ?></div>
                        </div>
                        <span class="status-badge bg-primary text-white">
                            <?= htmlspecialchars($project['ostatus']) ?>
                        </span>
                    </div>

                    <div class="project-info">
                        <div class="info-item">
                            <div class="info-label">Products</div>
                            <div class="info-value"><?= $project['product_count'] ?> items</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Allocated Workers</div>
                            <div class="info-value"><?= $project['allocated_workers'] ?> workers</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Client Email</div>
                            <div class="info-value"><?= htmlspecialchars($project['cemail']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($project['address'] ?? 'N/A') ?></div>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn-detail" data-bs-toggle="modal" data-bs-target="#detailsModal<?= $project['orderid'] ?>">
                            <i class="fas fa-eye me-1"></i>Detail
                        </button>

                        <?php if ($project['supplier_status'] === 'Accepted'): ?>
                            <a href="WorkerAllocation.php?orderid=<?= $project['orderid'] ?>" class="btn-allocate">
                                <i class="fas fa-users"></i>Manage Workers
                            </a>
                        <?php elseif ($project['supplier_status'] === 'Rejected'): ?>
                            <span class="badge bg-danger p-2">Rejected</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Details Modal (Synced with WorkerAllocation.php) -->
                <div class="modal fade" id="detailsModal<?= $project['orderid'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content" style="background-color: #f4f7f6;">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Project #<?= $project['orderid'] ?> Full Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row">
                                    <div class="col-lg-12">
                                        <!-- Project Overview -->
                                        <div class="detail-card">
                                            <div class="section-title"><i class="fas fa-project-diagram"></i> Project Overview</div>
                                            <div class="row">
                                                <div class="col-md-6">
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
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Client Information -->
                                        <div class="detail-card">
                                            <div class="section-title"><i class="fas fa-user"></i> Client Information</div>
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
                                            <div class="section-title"><i class="fas fa-list"></i> Requirements</div>
                                            <p class="text-dark"><?= nl2br(htmlspecialchars($edit_order['Requirements'] ?? 'No specific requirements')) ?></p>
                                        </div>

                                        <!-- Design Proposal -->
                                        <?php if (!empty($edit_order['designed_picture_filename'])): ?>
                                        <div class="detail-card">
                                            <div class="section-title"><i class="fas fa-paint-brush"></i> Design Proposal</div>
                                            <div class="text-center">
                                                <img src="../uploads/designed_Picture/<?= htmlspecialchars($edit_order['designed_picture_filename']) ?>" 
                                                     alt="Design Proposal" class="img-fluid rounded shadow-sm" style="max-height: 400px; object-fit: contain;">
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Products & Materials -->
                                        <?php if (!empty($references)): ?>
                                        <div class="detail-card">
                                            <div class="section-title"><i class="fas fa-couch"></i> Products & Materials</div>
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
                                                                    <div class="fw-bold"><?= htmlspecialchars($ref['pname'] ?? 'N/A') ?></div>
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
                                            <div class="section-title"><i class="fas fa-plus-circle"></i> Additional Fees</div>
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
                                            <div class="section-title"><i class="fas fa-calculator"></i> Construction Payment</div>
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="info-label">Total Construction Payment</div>
                                                    <div class="info-value text-success" style="font-size: 18px;">$<?= number_format($payment['total_construction_payment'], 2) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-white">
                                <?php if ($project['supplier_status'] === 'Pending'): ?>
                                    <form method="POST" class="w-100 d-flex justify-content-between align-items-center">
                                        <div class="text-muted small"><i class="fas fa-info-circle me-1"></i> Please review all details before making a decision.</div>
                                        <div>
                                            <input type="hidden" name="handle_assignment" value="1">
                                            <input type="hidden" name="order_id" value="<?= $project['orderid'] ?>">
                                            <button type="submit" name="action" value="Rejected" class="btn btn-outline-danger me-2 px-4">
                                                <i class="fas fa-times me-1"></i> Reject
                                            </button>
                                            <button type="submit" name="action" value="Accepted" class="btn btn-success px-4">
                                                <i class="fas fa-check me-1"></i> Accept
                                            </button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                                    <?php if ($project['supplier_status'] === 'Accepted'): ?>
                                        <a href="WorkerAllocation.php?orderid=<?= $project['orderid'] ?>" class="btn btn-primary px-4">
                                            <i class="fas fa-users me-1"></i> Manage Workers
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="project-card">
                <div class="empty-state text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h4>No Projects Found</h4>
                    <p class="text-muted">You don't have any active projects yet.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>