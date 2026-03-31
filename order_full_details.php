<?php
require_once __DIR__ . 
'/config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php?redirect=order_full_details.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'];
$userId = $user['id'] ?? $user['managerid'] ?? $user['clientid'] ?? 0;
$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

if ($orderId <= 0) {
    die("Invalid Project ID");
}

$updateMessage = "";

// 1. Fetch Basic Order & Client & Design Info first to get the status
$orderQuery = "SELECT o.*, c.cname, c.ctel, c.cemail, c.address, d.designName, d.description as designDesc, 
               s.OrderFinishDate, s.DesignFinishDate, s.construction_start_date, s.construction_end_date, s.construction_date_status 
               FROM `Order` o
               LEFT JOIN Client c ON o.clientid = c.clientid
               LEFT JOIN Design d ON o.designid = d.designid
               LEFT JOIN Schedule s ON o.orderid = s.orderid
               WHERE o.orderid = ?";
$stmt = $mysqli->prepare($orderQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Project not found");
}

$isConstructionDateLocked = strtolower($order['construction_date_status'] ?? '') === 'accepted';

// Handle POST requests for manager and client actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Manager actions
    if ($role === 'manager') {
        // Handle Project/Design Date Updates
        if (isset($_POST['update_project_dates'])) {
            $newOrderDate = $_POST['order_finish_date'];
            $newDesignDate = $_POST['design_finish_date'];

            $checkSchedule = $mysqli->prepare("SELECT scheduleid FROM Schedule WHERE orderid = ?");
            $checkSchedule->bind_param("i", $orderId);
            $checkSchedule->execute();
            $scheduleResult = $checkSchedule->get_result();

            if ($scheduleResult->num_rows > 0) {
                $updateStmt = $mysqli->prepare("UPDATE Schedule SET OrderFinishDate = ?, DesignFinishDate = ? WHERE orderid = ?");
                $updateStmt->bind_param("ssi", $newOrderDate, $newDesignDate, $orderId);
            } else {
                $managerId = $userId;
                $updateStmt = $mysqli->prepare("INSERT INTO Schedule (OrderFinishDate, DesignFinishDate, orderid, managerid) VALUES (?, ?, ?, ?)");
                $updateStmt->bind_param("ssii", $newOrderDate, $newDesignDate, $orderId, $managerId);
            }

            if ($updateStmt->execute()) {
                $updateMessage .= "<div class='alert alert-success alert-dismissible fade show' role='alert'>Project/Design dates updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            } else {
                $updateMessage .= "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error updating dates: " . $mysqli->error . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            }
        }

        // Handle Construction Date Updates
        if (isset($_POST['set_construction_dates']) && !$isConstructionDateLocked) {
            $start_date = $_POST['construction_start_date'];
            $end_date = $_POST['construction_end_date'];

            $check_sch_sql = "SELECT scheduleid FROM `Schedule` WHERE orderid = ?";
            $check_sch_stmt = $mysqli->prepare($check_sch_sql);
            $check_sch_stmt->bind_param("i", $orderId);
            $check_sch_stmt->execute();
            $sch_res = $check_sch_stmt->get_result();

            if ($sch_res->num_rows > 0) {
                $update_sch_sql = "UPDATE `Schedule` SET construction_start_date = ?, construction_end_date = ?, construction_date_status = 'pending' WHERE orderid = ?";
                $update_sch_stmt = $mysqli->prepare($update_sch_sql);
                $update_sch_stmt->bind_param("ssi", $start_date, $end_date, $orderId);
            } else {
                $managerId = $userId;
                $update_sch_sql = "INSERT INTO `Schedule` (orderid, managerid, construction_start_date, construction_end_date, construction_date_status) VALUES (?, ?, ?, ?, 'pending')";
                $update_sch_stmt = $mysqli->prepare($update_sch_sql);
                $update_sch_stmt->bind_param("iiss", $orderId, $managerId, $start_date, $end_date);
            }

            if ($update_sch_stmt->execute()) {
                // Check if workers have been allocated for this order
                $worker_check_sql = "SELECT COUNT(*) as cnt FROM `workerallocation` WHERE orderid = ? AND status != 'Completed' AND status != 'Cancelled'";
                $worker_check_stmt = $mysqli->prepare($worker_check_sql);
                $worker_check_stmt->bind_param("i", $orderId);
                $worker_check_stmt->execute();
                $worker_count = $worker_check_stmt->get_result()->fetch_assoc()['cnt'];
                if ($worker_count > 0) {
                    $status_update_sql = "UPDATE `Order` SET ostatus = 'waiting client confirm construction date' WHERE orderid = ?";
                    $status_update_stmt = $mysqli->prepare($status_update_sql);
                    $status_update_stmt->bind_param("i", $orderId);
                    $status_update_stmt->execute();
                }
                $updateMessage .= "<div class='alert alert-success alert-dismissible fade show' role='alert'>Construction dates submitted to client for approval.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            } else {
                $updateMessage .= "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error setting construction dates: " . $mysqli->error . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            }
        }
    }

    // Handle Client's acceptance/rejection
    if ($role === 'client' && isset($_POST['action_construction_date'])) {
        $action = $_POST['action_construction_date'];
        $newStatus = ($action === 'accept') ? 'accepted' : 'rejected';
        $successMessage = "Construction dates {$newStatus} successfully!";

        $updateStatusSql = "UPDATE `Schedule` SET construction_date_status = ? WHERE orderid = ?";
        $updateStatusStmt = $mysqli->prepare($updateStatusSql);
        $updateStatusStmt->bind_param("si", $newStatus, $orderId);

        if ($updateStatusStmt->execute()) {
            if ($action === 'accept') {
                $orderStatusSql = "UPDATE `Order` SET ostatus = 'In construction' WHERE orderid = ?";
                $orderStatusStmt = $mysqli->prepare($orderStatusSql);
                $orderStatusStmt->bind_param("i", $orderId);
                $orderStatusStmt->execute();
            }
            $updateMessage = "<div class='alert alert-success alert-dismissible fade show' role='alert'>{$successMessage}<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } else {
            $updateMessage = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error: " . $mysqli->error . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        }
    }
    // Re-fetch data after update
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $isConstructionDateLocked = strtolower($order['construction_date_status'] ?? '') === 'accepted';
}


// 2. Fetch Products/Materials (OrderReference)
$refQuery = "SELECT orf.*, p.pname, p.category, p.price as unit_price
             FROM OrderReference orf
             LEFT JOIN Product p ON orf.productid = p.productid
             WHERE orf.orderid = ?";
$stmt = $mysqli->prepare($refQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$references = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Fetch Additional Fees
$feeQuery = "SELECT * FROM AdditionalFee WHERE orderid = ?";
$stmt = $mysqli->prepare($feeQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Fetch Contractors
$contractorQuery = "SELECT oc.*, con.cname as contractor_name, con.ctel as contractor_tel
                    FROM Order_Contractors oc
                    JOIN Contractors con ON oc.contractorid = con.contractorid
                    WHERE oc.orderid = ?";
$stmt = $mysqli->prepare($contractorQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$contractors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate Totals
$productTotal = 0;
foreach ($references as $ref) {
    $productTotal += $ref['price'] ?? 0;
}
$feeTotal = 0;
foreach ($fees as $fee) {
    $feeTotal += $fee['amount'];
}
$grandTotal = $productTotal + $feeTotal + ($order['cost'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Detail #<?= $orderId ?> - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            background-color: #f4f7f6;
        }

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

        .back-btn {
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            color: #7f8c8d;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 8px 16px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .back-btn:hover {
            color: #3498db;
            transform: translateX(-5px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Embed-specific overrides */
        <?php if ($isEmbed): ?>
            body {
                background-color: #fff !important;
            }

            main.container {
                margin-top: 0 !important;
                padding: 15px !important;
                max-width: 100% !important;
            }

            .detail-card {
                padding: 15px !important;
                margin-bottom: 15px !important;
                box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05) !important;
            }

            .section-title {
                font-size: 16px !important;
                margin-bottom: 15px !important;
            }
        <?php endif; ?>
    </style>
</head>

<body>
    <?php if (!$isEmbed) include __DIR__ . '/navbar.php'; ?>

    <main class="container mt-5">
        <?php if (!$isEmbed): ?>
            <a href="projects.php" class="back-btn">
                <i class="fas fa-arrow-left me-2"></i> Back to Projects
            </a>
        <?php endif; ?>

        <?= $updateMessage ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0 text-primary">Project Details #<?= $orderId ?></h2>
            <div>
                <span class="badge bg-primary status-badge">
                    <?= htmlspecialchars($order['ostatus']) ?>
                </span>
            </div>
        </div>

        <?php if ($role === 'manager'): ?>
            <div class="detail-card">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i> Set Project Dates
                </div>
                <form method="POST" class="row g-3">
                    <div class="col-md-5">
                        <label for="design_finish_date" class="form-label small">Design Finish Date</label>
                        <input type="date" class="form-control" id="design_finish_date" name="design_finish_date"
                            value="<?= $order['DesignFinishDate'] ?>">
                    </div>
                    <div class="col-md-5">
                        <label for="order_finish_date" class="form-label small">Project Finish Date</label>
                        <input type="date" class="form-control" id="order_finish_date" name="order_finish_date"
                            value="<?= $order['OrderFinishDate'] ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="update_project_dates" class="btn btn-primary w-100">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
            </div>

            <?php if (strtolower($order['ostatus']) === 'preparing'): ?>
            <div class="detail-card">
                <div class="section-title">
                    <i class="fas fa-hammer"></i> Set Construction Dates
                </div>
                <form method="POST" class="row g-3">
                    <div class="col-md-5">
                        <label for="construction_start_date" class="form-label small">Construction Start Date</label>
                        <input type="date" class="form-control" id="construction_start_date" name="construction_start_date"
                            value="<?= $order['construction_start_date'] ?>" <?= $isConstructionDateLocked ? 'readonly' : '' ?>>
                    </div>
                    <div class="col-md-5">
                        <label for="construction_end_date" class="form-label small">Construction End Date</label>
                        <input type="date" class="form-control" id="construction_end_date" name="construction_end_date"
                            value="<?= $order['construction_end_date'] ?>" <?= $isConstructionDateLocked ? 'readonly' : '' ?>>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <?php if (!$isConstructionDateLocked): ?>
                        <button type="submit" name="set_construction_dates" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                        <?php endif; ?>
                    </div>
                     <?php if ($isConstructionDateLocked): ?>
                        <div class="col-12 mt-3">
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="fas fa-lock me-3 fs-4"></i>
                                <div><strong>Dates Locked:</strong> The client has accepted the construction schedule.</div>
                            </div>
                        </div>
                    <?php elseif (strtolower($order['construction_date_status'] ?? '') === 'rejected'): ?>
                        <div class="col-12 mt-2">
                            <div class="text-danger small fw-bold">
                                <i class="fas fa-exclamation-circle me-1"></i> The client rejected the previous dates. Please reschedule and submit.
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        <?php elseif ($role === 'client' && in_array(strtolower($order['ostatus']), ['preparing', 'waiting client confirm construction date']) && $order['construction_start_date']): ?>
            <div class="detail-card">
                <div class="section-title text-primary">
                    <i class="fas fa-hammer"></i> Construction Date Confirmation
                </div>
                <?php 
                $c_status = strtolower($order['construction_date_status'] ?? 'pending');
                if ($c_status === 'accepted'): 
                ?>
                    <div class="alert alert-success d-flex align-items-center">
                        <i class="fas fa-check-circle me-3 fs-4"></i>
                        <div>
                            <strong>You have accepted the dates:</strong>
                            <div class="mt-1 small">
                                Start: <?= date('F d, Y', strtotime($order['construction_start_date'])) ?> | 
                                End: <?= date('F d, Y', strtotime($order['construction_end_date'])) ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($c_status === 'pending'): ?>
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="fas fa-info-circle me-3 fs-4"></i>
                        <div>
                            Manager has proposed construction dates:
                            <strong><?= date('F d, Y', strtotime($order['construction_start_date'])) ?></strong> to
                            <strong><?= date('F d, Y', strtotime($order['construction_end_date'])) ?></strong>.
                            Please review and respond.
                        </div>
                    </div>
                    <form method="POST" class="d-flex justify-content-end gap-2">
                        <button type="submit" name="action_construction_date" value="accept" class="btn btn-success">
                            <i class="fas fa-check-circle me-2"></i>Accept Dates
                        </button>
                        <button type="submit" name="action_construction_date" value="reject" class="btn btn-danger">
                            <i class="fas fa-times-circle me-2"></i>Reject Dates
                        </button>
                    </form>
                <?php elseif ($c_status === 'rejected'): ?>
                     <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle me-3 fs-4"></i>
                        <div>
                            <strong>You have rejected the dates.</strong> Please wait for the manager to propose a new schedule.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($role === 'supplier' && strtolower($order['construction_date_status'] ?? '') === 'accepted'): ?>
            <div class="detail-card">
                <div class="section-title text-info">
                    <i class="fas fa-calendar-alt"></i> Construction Schedule
                </div>
                <div class="alert alert-info d-flex align-items-center">
                    <i class="fas fa-info-circle me-3 fs-4"></i>
                    <div>
                        <strong>Confirmed Construction Dates:</strong>
                        <div class="mt-1 small">
                            Start: <?= date('F d, Y', strtotime($order['construction_start_date'])) ?> | 
                            End: <?= date('F d, Y', strtotime($order['construction_end_date'])) ?>
                        </div>
                        <div class="mt-1 small text-success fw-bold"><i class="fas fa-check-circle me-1"></i> The construction dates have been accepted by the client.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column: Order & Client Info -->
            <div class="col-lg-8">
                <!-- Project Overview -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Project Overview
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-label">Design Name</div>
                            <div class="info-value"><?= htmlspecialchars($order['designName'] ?? 'Custom Request') ?>
                            </div>

                            <div class="info-label">Project Date</div>
                            <div class="info-value"><?= date('F d, Y', strtotime($order['odate'])) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Requirements</div>
                            <div class="info-value">
                                <?= nl2br(htmlspecialchars($order['Requirements'] ?? 'No specific requirements')) ?>
                            </div>

                            <div class="info-label">Estimated Completion</div>
                            <div class="info-value text-primary">
                                <?= $order['OrderFinishDate'] ? date('F d, Y', strtotime($order['OrderFinishDate'])) : 'TBD' ?>
                            </div>

                            <div class="info-label">Design Finish Date</div>
                            <div class="info-value text-primary">
                                <?= $order['DesignFinishDate'] ? date('F d, Y', strtotime($order['DesignFinishDate'])) : 'TBD' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products & Materials -->
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
                                <?php if (empty($references)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">No items added yet</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($references as $ref): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($ref['pname'] ?? 'Unknown Item') ?>
                                                </div>
                                                <small class="text-muted"><?= htmlspecialchars($ref['note'] ?? '') ?></small>
                                            </td>
                                            <td><span
                                                    class="badge bg-light text-dark"><?= htmlspecialchars($ref['category'] ?? 'N/A') ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($ref['status'] ?? 'Pending') ?></td>
                                            <td class="text-end">$<?= number_format($ref['price'] ?? 0, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
            </div>

            <!-- Right Column: Client & Financials -->
            <div class="col-lg-4">
                <!-- Client Info -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-user"></i> Client Information
                    </div>
                    <div class="info-label">Name</div>
                    <div class="info-value"><?= htmlspecialchars($order['cname']) ?></div>

                    <div class="info-label">Phone</div>
                    <div class="info-value"><?= htmlspecialchars($order['ctel']) ?></div>

                    <div class="info-label">Email</div>
                    <div class="info-value"><?= htmlspecialchars($order['cemail']) ?></div>

                    <div class="info-label">Address</div>
                    <div class="info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></div>
                </div>

                <!-- Financial Summary -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-file-invoice-dollar"></i> Financial Summary
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Deposit Paid:</span>
                        <span class="fw-bold">$<?= number_format($order['deposit'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Items Total:</span>
                        <span class="fw-bold">$<?= number_format($productTotal, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Additional Fees:</span>
                        <span class="fw-bold">$<?= number_format($feeTotal, 2) ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span class="h5 fw-bold">Grand Total:</span>
                        <span class="h5 fw-bold text-success">$<?= number_format($grandTotal, 2) ?></span>
                    </div>
                </div>

                <!-- Assigned Contractors -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-tools"></i> Assigned Contractors
                    </div>
                    <?php if (empty($contractors)): ?>
                        <p class="text-muted small">No contractors assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($contractors as $con): ?>
                            <div class="mb-3">
                                <div class="fw-bold"><?= htmlspecialchars($con['contractor_name']) ?></div>
                                <div class="small text-muted"><i class="fas fa-phone me-1"></i>
                                    <?= htmlspecialchars($con['contractor_tel']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$isEmbed): ?>
        <?php include __DIR__ . '/Public/chat_widget.php'; ?>
    <?php endif; ?>
</body>

</html>
