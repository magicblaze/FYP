<?php
// supplier/distribution.php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$supplier_id = $user['supplierid'];

// Fetch supplier's default worker pay
$default_pay_sql = "SELECT default_worker_pay FROM `Supplier` WHERE supplierid = ?";
$default_pay_stmt = mysqli_prepare($mysqli, $default_pay_sql);
mysqli_stmt_bind_param($default_pay_stmt, "i", $supplier_id);
mysqli_stmt_execute($default_pay_stmt);
$default_pay_result = mysqli_stmt_get_result($default_pay_stmt);
$supplier_default_pay = mysqli_fetch_assoc($default_pay_result)['default_worker_pay'] ?? 0.00;
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
    die("Access Denied: You must accept the assignment before managing payments.");
}
mysqli_stmt_close($check_order_stmt);

// Get order details once so calculations and validations can use the same budget value
$order_sql = "SELECT o.*, c.cname as client_name FROM `Order` o JOIN `Client` c ON o.clientid = c.clientid WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));
mysqli_stmt_close($order_stmt);

if (!$order_info) {
    die("Order not found");
}

$project_budget = floatval($order_info['budget'] ?? 0);

// Initialize variables
$error_message = '';
$success_message = '';

// Handle BATCH UPDATE of allocation percentages
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_update'])) {
    $percentages = $_POST['percentages'];
    $total = 0;
    foreach ($percentages as $p) {
        $total += floatval($p);
    }

    $fee_total_for_validation = 0.0;
    $fee_total_sql = "SELECT IFNULL(SUM(amount), 0) AS total_fee FROM `AdditionalFee` WHERE orderid = ?";
    $fee_total_stmt = mysqli_prepare($mysqli, $fee_total_sql);
    mysqli_stmt_bind_param($fee_total_stmt, "i", $order_id);
    mysqli_stmt_execute($fee_total_stmt);
    $fee_total_row = mysqli_fetch_assoc(mysqli_stmt_get_result($fee_total_stmt));
    mysqli_stmt_close($fee_total_stmt);
    $fee_total_for_validation = floatval($fee_total_row['total_fee'] ?? 0);

    $fee_percent_for_validation = $project_budget > 0 ? (($fee_total_for_validation / $project_budget) * 100) : 0;
    $max_worker_percent = max(0, 100 - $fee_percent_for_validation);

    if ($total > $max_worker_percent + 0.01) {
        $error_message = "Error: Worker percentage cannot exceed " . number_format($max_worker_percent, 1) . "%. " .
            "Current worker total: " . number_format($total, 1) . "%, fee portion: " . number_format($fee_percent_for_validation, 1) . "%.";
    } else {
        mysqli_begin_transaction($mysqli);
        try {
            foreach ($percentages as $allocation_id => $val) {
                $update_sql = "UPDATE `workerallocation` SET percentage = ? WHERE allocation_id = ? AND orderid = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_sql);
                $f_val = floatval($val);
                $i_id = intval($allocation_id);
                mysqli_stmt_bind_param($update_stmt, "dii", $f_val, $i_id, $order_id);
                mysqli_stmt_execute($update_stmt);
            }
            mysqli_commit($mysqli);
            $success_message = "All percentages updated successfully!";
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            $error_message = "Failed to update percentages: " . $e->getMessage();
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_pay'])) {
    $new_default_pay = floatval($_POST['default_pay_value']);
    if ($new_default_pay < 0 || $new_default_pay > 100) {
        $error_message = "Default Pay must be between 0 and 100%.";
    } else {
        $update_default_sql = "UPDATE `Supplier` SET default_worker_pay = ? WHERE supplierid = ?";
        $update_default_stmt = mysqli_prepare($mysqli, $update_default_sql);
        mysqli_stmt_bind_param($update_default_stmt, "di", $new_default_pay, $supplier_id);
        if (mysqli_stmt_execute($update_default_stmt)) {
            $success_message = "Default Pay updated successfully to " . number_format($new_default_pay, 1) . "%!";
            $supplier_default_pay = $new_default_pay; // Update the displayed value immediately
        } else {
            $error_message = "Failed to update Default Pay.";
        }
        mysqli_stmt_close($update_default_stmt);
    }
}

// Handle ADDITION of custom commission/fee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_fee'])) {
    $fee_name = trim($_POST['fee_name'] ?? '');
    $fee_input_type = $_POST['fee_input_type'] ?? 'amount';
    $fee_amount = floatval($_POST['fee_amount'] ?? 0);
    $fee_percent = floatval($_POST['fee_percent'] ?? 0);
    $fee_description = trim($_POST['fee_description'] ?? '');

    if ($fee_input_type === 'percent') {
        if ($project_budget <= 0) {
            $error_message = "Cannot use percentage fee because project budget is 0.";
        } elseif ($fee_percent <= 0 || $fee_percent > 100) {
            $error_message = "Please provide a valid fee percentage between 0 and 100.";
        } else {
            $fee_amount = ($project_budget * $fee_percent) / 100;
            $percent_note = "Rate: " . number_format($fee_percent, 2) . "% of budget";
            $fee_description = $fee_description === '' ? $percent_note : ($percent_note . " | " . $fee_description);
        }
    }

    if (empty($error_message) && (empty($fee_name) || $fee_amount <= 0)) {
        $error_message = "Please provide a valid fee name and amount.";
    }

    if (empty($error_message)) {
        // Remaining budget for new fee = budget - worker allocation amount - existing fees
        $worker_pct_sql = "SELECT IFNULL(SUM(percentage), 0) AS total_pct FROM `workerallocation` WHERE orderid = ?";
        $worker_pct_stmt = mysqli_prepare($mysqli, $worker_pct_sql);
        mysqli_stmt_bind_param($worker_pct_stmt, "i", $order_id);
        mysqli_stmt_execute($worker_pct_stmt);
        $worker_pct_row = mysqli_fetch_assoc(mysqli_stmt_get_result($worker_pct_stmt));
        mysqli_stmt_close($worker_pct_stmt);

        $existing_fee_sql = "SELECT IFNULL(SUM(amount), 0) AS total_fee FROM `AdditionalFee` WHERE orderid = ?";
        $existing_fee_stmt = mysqli_prepare($mysqli, $existing_fee_sql);
        mysqli_stmt_bind_param($existing_fee_stmt, "i", $order_id);
        mysqli_stmt_execute($existing_fee_stmt);
        $existing_fee_row = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_fee_stmt));
        mysqli_stmt_close($existing_fee_stmt);

        $allocated_worker_percent = floatval($worker_pct_row['total_pct'] ?? 0);
        $allocated_worker_budget = ($project_budget * $allocated_worker_percent) / 100;
        $existing_fee_total = floatval($existing_fee_row['total_fee'] ?? 0);
        $remaining_for_new_fee = $project_budget - $allocated_worker_budget - $existing_fee_total;

        if ($remaining_for_new_fee <= 0) {
            $error_message = "Cannot add fee. Remaining Budget is $0.00.";
        } elseif ($fee_amount > ($remaining_for_new_fee + 0.00001)) {
            $error_message = "Fee amount exceeds Remaining Budget. Max allowed: $" . number_format($remaining_for_new_fee, 2) . ".";
        }
    }

    if (empty($error_message)) {
        $insert_fee_sql = "INSERT INTO `AdditionalFee` (orderid, fee_name, amount, description) VALUES (?, ?, ?, ?)";
        $insert_fee_stmt = mysqli_prepare($mysqli, $insert_fee_sql);
        mysqli_stmt_bind_param($insert_fee_stmt, "isds", $order_id, $fee_name, $fee_amount, $fee_description);
        if (mysqli_stmt_execute($insert_fee_stmt)) {
            $success_message = "Custom fee '$fee_name' added successfully (" . number_format($fee_amount, 2) . ").";
        } else {
            $error_message = "Failed to add custom fee.";
        }
        mysqli_stmt_close($insert_fee_stmt);
    }
}

// Handle DELETION of custom fee
if (isset($_POST['delete_fee'])) {
    $fee_id = intval($_POST['fee_id']);
    $delete_fee_sql = "DELETE FROM `AdditionalFee` WHERE fee_id = ? AND orderid = ?";
    $delete_fee_stmt = mysqli_prepare($mysqli, $delete_fee_sql);
    mysqli_stmt_bind_param($delete_fee_stmt, "ii", $fee_id, $order_id);
    if (mysqli_stmt_execute($delete_fee_stmt)) {
        $success_message = "Fee removed successfully.";
    } else {
        $error_message = "Failed to remove fee.";
    }
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

// Get additional fees
$fees_sql = "SELECT * FROM `AdditionalFee` WHERE orderid = ? ORDER BY created_at DESC";
$fees_stmt = mysqli_prepare($mysqli, $fees_sql);
mysqli_stmt_bind_param($fees_stmt, "i", $order_id);
mysqli_stmt_execute($fees_stmt);
$additional_fees = mysqli_fetch_all(mysqli_stmt_get_result($fees_stmt), MYSQLI_ASSOC);

// Calculate totals
$total_allocated_percent = 0;
foreach ($allocated_workers as $aw) {
    $total_allocated_percent += floatval($aw['percentage']);
}
$total_extra_fees = 0;
foreach ($additional_fees as $fee) {
    $total_extra_fees += $fee['amount'];
}

$total_extra_fee_percent = $project_budget > 0 ? (($total_extra_fees / $project_budget) * 100) : 0;
$max_worker_percent = max(0, 100 - $total_extra_fee_percent);
$remaining_percent = max(0, 100 - $total_allocated_percent - $total_extra_fee_percent);
$remaining_budget = max(0, $project_budget - (($project_budget * $total_allocated_percent) / 100) - $total_extra_fees);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribution - Supplier Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body {
            background-color: #f4f7f6;
        }

        .card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: none;
            margin-bottom: 1.5rem;
        }

        .worker-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
        }

        .back-btn {
            color: #7f8c8d;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .back-btn:hover {
            color: #3498db;
            transform: translateX(-5px);
        }

        .section-title {
            border-left: 4px solid #3498db;
            padding-left: 10px;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 340px;
            width: 100%;
            padding: 22px 8px;
        }

        .percent-input {
            width: 80px !important;
            text-align: center;
        }

        .unallocated-box {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 10px;
        }

        .limit-reached {
            color: #e74c3c !important;
            font-weight: bold;
        }

        .fee-list-container {
            max-height: 350px;
            overflow-y: auto;
        }

        .fee-item {
            border-left: 3px solid #e67e22;
            background: #fffaf5;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 6px;
        }

        .fee-desc {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 4px;
            font-style: italic;
        }

        .default-pay-box {
            background: #e8f4fd;
            border: 1px solid #3498db;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 15px;
        }

        .table thead th {
            color: #000 !important;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 6px;
        }

        .summary-label {
            font-size: 0.875rem;
            color: #212529;
            white-space: nowrap;
        }

        .summary-value {
            font-size: 0.875rem;
            font-weight: 700;
            text-align: right;
            min-width: 110px;
        }

        .summary-fee-names {
            white-space: normal;
            word-break: break-word;
            line-height: 1.35;
            max-width: 62%;
        }

        .summary-fee-label {
            color: #6c757d;
            font-size: 0.82rem;
            padding-left: 10px;
        }

        .summary-value-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            min-width: 120px;
            line-height: 1.2;
        }

        .summary-subvalue {
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="ProjectWorkerManagement.php" class="back-btn">
                <i class="fas fa-arrow-left me-1"></i>Back to Project
            </a>
            <div class="text-end">
                <h5 class="mb-0">Project #<?= $order_id ?> Distribution</h5>
                <small class="text-muted">Client: <?= htmlspecialchars($order_info['client_name']) ?></small>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4"><?= $success_message ?><button
                    type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4"><?= $error_message ?><button
                    type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="row">
            <!-- Top Row: Chart on Left, Worker List on Right -->
            <div class="col-lg-5 mb-4">
                <div class="card p-4 h-100">
                    <h4 class="section-title">Overview</h4>
                    <div class="chart-container mb-3">
                        <canvas id="distributionChart"></canvas>
                    </div>
                    <div class="card bg-light border-0 p-3 mt-3">
                        <h6 class="small text-muted mb-2">Summary Info</h6>
                        <div class="summary-row">
                            <span class="summary-label">Total Budget:</span>
                            <span class="summary-value text-success">$<?= number_format(floatval($order_info['budget'] ?? 0), 2) ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Total Workers:</span>
                            <span class="summary-value"><?= count($allocated_workers) ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Remaining Budget:</span>
                            <span class="summary-value-stack">
                                <span class="summary-value text-primary"
                                    id="remainingPercent"><?= number_format($remaining_percent, 1) ?>%</span>
                                <small id="remainingBudget" class="summary-subvalue"><?= '$' . number_format($remaining_budget, 2) ?></small>
                            </span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Fee Items:</span>
                            <span class="summary-value text-warning"><?= !empty($additional_fees) ? count($additional_fees) : 0 ?></span>
                        </div>
                        <?php if (!empty($additional_fees)): ?>
                            <?php foreach ($additional_fees as $fee): ?>
                                <div class="summary-row">
                                    <span class="summary-label summary-fee-label"><?= htmlspecialchars((string)($fee['fee_name'] ?? 'Fee')) ?>:</span>
                                    <span class="summary-value text-warning">$<?= number_format((float)($fee['amount'] ?? 0), 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="summary-row">
                                <span class="summary-label summary-fee-label">None</span>
                                <span class="summary-value text-warning">$0.00</span>
                            </div>
                        <?php endif; ?>
                        <div class="summary-row">
                            <span class="summary-label">Fees Total:</span>
                            <span class="summary-value text-warning">$<?= number_format($total_extra_fees, 2) ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Fee Share %:</span>
                            <span class="summary-value text-warning"><?= number_format($total_extra_fee_percent, 1) ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 mb-4">
                <div class="card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="section-title mb-0">1. Worker Pay Split (%)</h4>
                        <button type="submit" form="batchUpdateForm" name="batch_update" id="saveBtn"
                            class="btn btn-primary btn-sm px-4 shadow-sm">
                            <i class="fas fa-save me-1"></i> Save All Changes
                        </button>
                    </div>

                    <!-- Default Pay Value Box -->
                    <div class="default-pay-box d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-magic text-primary me-2"></i>
                            <span class="small fw-bold text-muted me-3">Default Pay:</span>
                        </div>
                        <form method="POST" class="d-flex align-items-center">
                            <div class="input-group input-group-sm" style="width: 120px;">
                                <input type="number" name="default_pay_value" id="defaultPayValue" class="form-control"
                                    value="<?= number_format($supplier_default_pay, 1) ?>" min="0" max="100" step="0.1">
                                <span class="input-group-text">%</span>
                            </div>
                            <button type="submit" name="set_default_pay" class="btn btn-sm btn-primary ms-2">Set Default
                                Pay</button>
                            <button type="button" onclick="applyDefaultPay()"
                                class="btn btn-sm btn-outline-secondary ms-2">Apply Distribution</button>
                        </form>
                    </div>

                    <form method="POST" id="batchUpdateForm">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Worker</th>
                                        <th class="text-center">Allocation %</th>
                                        <th>Slider Adjust</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allocated_workers as $worker): ?>
                                        <tr class="worker-row" data-id="<?= $worker['allocation_id'] ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>"
                                                        class="worker-img me-2 border">
                                                    <div>
                                                        <span
                                                            class="fw-bold d-block small"><?= htmlspecialchars($worker['name']) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="input-group input-group-sm mx-auto" style="width: 100px;">
                                                    <input type="number" name="percentages[<?= $worker['allocation_id'] ?>]"
                                                        class="form-control percent-input"
                                                        data-id="<?= $worker['allocation_id'] ?>"
                                                        value="<?= number_format($worker['percentage'], 1) ?>" min="0"
                                                        max="100" step="0.1"
                                                        oninput="syncFromInput(<?= $worker['allocation_id'] ?>, this.value)">
                                                </div>
                                            </td>
                                            <td>
                                                <input type="range" class="form-range worker-slider"
                                                    data-id="<?= $worker['allocation_id'] ?>"
                                                    value="<?= $worker['percentage'] ?>" min="0" max="100" step="0.1"
                                                    oninput="syncFromSlider(<?= $worker['allocation_id'] ?>, this.value)">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <?php if (empty($allocated_workers)): ?>
                        <p class="text-center py-5 text-muted">No workers allocated. Go back to Team Allocation to add
                            workers.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom Row: Custom Commissions & Fees (Full Width) -->
            <div class="col-12">
                <div class="card p-4">
                    <h4 class="section-title">2. Custom Commissions & Fees</h4>
                    <div class="row">
                        <!-- Left side of section: Form -->
                        <div class="col-md-4 border-end">
                            <form method="POST" id="addFeeForm" class="bg-light p-3 rounded" onsubmit="return validateFeeForm()">
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted">Fee Name</label>
                                    <input type="text" name="fee_name" class="form-control form-control-sm"
                                        placeholder="e.g. Special Bonus" required>
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted">Input Type</label>
                                    <select name="fee_input_type" id="feeInputType" class="form-select form-select-sm"
                                        onchange="updateFeeInputMode()">
                                        <option value="amount" selected>Amount ($)</option>
                                        <option value="percent">Percentage (%)</option>
                                    </select>
                                </div>
                                <div class="mb-2" id="feeAmountGroup">
                                    <label class="small fw-bold text-muted">Amount ($)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">$</span>
                                        <input type="number" id="feeAmountInput" name="fee_amount" class="form-control"
                                            placeholder="0.00" step="0.01" min="0.01" required>
                                    </div>
                                </div>
                                <div class="mb-2" id="feePercentGroup" style="display: none;">
                                    <label class="small fw-bold text-muted">Percentage (%)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="feePercentInput" name="fee_percent" class="form-control"
                                            placeholder="0.0" step="0.1" min="0.1" max="100" oninput="updateFeePreview()">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div id="feePreviewText" class="small text-muted mb-2"></div>
                                <div class="small text-muted mb-2">Remaining Budget for new fee: <strong><?= '$' . number_format($remaining_budget, 2) ?></strong></div>
                                <div class="mb-3">
                                    <label class="small fw-bold text-muted">Description</label>
                                    <textarea name="fee_description" class="form-control form-control-sm" rows="2"
                                        placeholder="Describe the purpose of this fee..."></textarea>
                                </div>
                                <button type="submit" name="add_fee" class="btn btn-sm btn-success w-100">Add New
                                    Fee</button>
                            </form>
                        </div>
                        <!-- Right side of section: List with Descriptions -->
                        <div class="col-md-8">
                            <div class="fee-list-container px-2">
                                <?php if (!empty($additional_fees)): ?>
                                    <div class="row">
                                        <?php foreach ($additional_fees as $fee): ?>
                                            <div class="col-md-6">
                                                <div class="fee-item d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <span class="fw-bold small text-truncate"
                                                                title="<?= htmlspecialchars($fee['fee_name']) ?>"><?= htmlspecialchars($fee['fee_name']) ?></span>
                                                            <span class="text-primary small fw-bold">
                                                                $<?= number_format($fee['amount'], 2) ?>
                                                                <?php if ($project_budget > 0): ?>
                                                                    <small class="text-muted">(<?= number_format(($fee['amount'] / $project_budget) * 100, 1) ?>%)</small>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                        <?php if (!empty($fee['description'])): ?>
                                                            <div class="fee-desc"><?= htmlspecialchars($fee['description']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <form method="POST"
                                                        onsubmit="return confirm('Are you sure you want to delete this fee? This action cannot be undone.')">
                                                        <input type="hidden" name="fee_id" value="<?= $fee['fee_id'] ?>">
                                                        <button type="submit" name="delete_fee"
                                                            class="btn btn-link text-danger p-0 ms-2"><i
                                                                class="fas fa-trash-alt fa-xs"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted py-5 small">No custom fees added yet. Use the form on
                                        the left to add commissions or other costs.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const workers = <?= json_encode($allocated_workers) ?>;
        const feeItems = <?= json_encode(array_map(function($fee) {
            return [
                'name' => (string)($fee['fee_name'] ?? 'Fee'),
                'amount' => (float)($fee['amount'] ?? 0)
            ];
        }, $additional_fees)) ?>;
        const projectBudget = <?= floatval($order_info['budget'] ?? 0) ?>;
        const additionalFeesTotal = <?= floatval($total_extra_fees) ?>;
        const additionalFeesPercent = projectBudget > 0 ? (additionalFeesTotal / projectBudget) * 100 : 0;
        const maxWorkerPercent = Math.max(0, 100 - additionalFeesPercent);
        const remainingBudgetForNewFee = <?= floatval($remaining_budget) ?>;
        const additionalFeesCount = <?= count($additional_fees) ?>;
        const hasAdditionalFees = additionalFeesCount > 0;
        let chart;

        function formatCurrency(amount) {
            return '$' + (amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function initChart() {
            const ctx = document.getElementById('distributionChart').getContext('2d');
            const workerPercentages = workers.map(w => parseFloat(w.percentage) || 0);
            const workerTotalPercent = workerPercentages.reduce((a, b) => a + b, 0);
            const workerBudgetData = workerPercentages.map(p => (projectBudget * p) / 100);
            const feeLabels = feeItems.map(f => (f.name || 'Fee'));
            const feeData = feeItems.map(f => parseFloat(f.amount) || 0);
            const allocatedBudget = workerBudgetData.reduce((a, b) => a + b, 0);
            const unallocatedBudget = Math.max(0, projectBudget - allocatedBudget - additionalFeesTotal);
            const chartLabels = workers.map(w => w.name).concat(['Unallocated']).concat(feeLabels);
            const chartData = workerBudgetData.concat([unallocatedBudget]).concat(feeData);
            const chartColors = [
                '#3498db', '#2ecc71', '#f1c40f', '#e74c3c', '#9b59b6', '#1abc9c', '#f39c12', '#d35400', '#7f8c8d'
            ].slice(0, workers.length).concat(['#ecf0f1']);

            const feePalette = ['#e67e22', '#d35400', '#f39c12', '#c0392b', '#16a085', '#8e44ad'];
            feeLabels.forEach((_, i) => chartColors.push(feePalette[i % feePalette.length]));

            const data = {
                labels: chartLabels,
                datasets: [{
                    data: chartData,
                    backgroundColor: chartColors,
                    borderWidth: 2
                }]
            };

            chart = new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            left: 56,
                            right: 56,
                            top: 24,
                            bottom: 24
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            color: '#2c3e50',
                            anchor: 'end',
                            align: 'end',
                            offset: 3,
                            clamp: true,
                            font: { weight: '600', size: 10 },
                            formatter: (value, context) => {
                                if (!value || value <= 0) return null;
                                const label = context.chart.data.labels[context.dataIndex] || '';
                                return label + '\n' + formatCurrency(value);
                            }
                        }
                    },
                    cutout: '75%'
                },
                plugins: [ChartDataLabels]
            });
            updateRemaining(workerTotalPercent);
        }

        function applyDefaultPay() {
            const defaultValue = parseFloat(document.getElementById('defaultPayValue').value) || 0;
            const inputs = document.querySelectorAll('.percent-input');

            inputs.forEach(input => {
                const currentVal = parseFloat(input.value) || 0;
                if (currentVal === 0) {
                    const id = input.dataset.id;
                    const otherTotal = calculateOtherTotal(parseInt(id));
                    let finalVal = defaultValue;

                    // Cap to remaining percentage
                    if (otherTotal + finalVal > maxWorkerPercent) {
                        finalVal = Math.max(0, maxWorkerPercent - otherTotal);
                    }

                    input.value = finalVal.toFixed(1);
                    document.querySelector(`.worker-slider[data-id="${id}"]`).value = finalVal;
                }
            });
            updateChart();
        }

        function syncFromSlider(id, val) {
            const input = document.querySelector(`.percent-input[data-id="${id}"]`);
            const currentTotal = calculateOtherTotal(id);
            let newVal = parseFloat(val);

            if (currentTotal + newVal > maxWorkerPercent) {
                newVal = maxWorkerPercent - currentTotal;
                if (newVal < 0) newVal = 0;
                document.querySelector(`.worker-slider[data-id="${id}"]`).value = newVal;
            }

            input.value = newVal.toFixed(1);
            updateChart();
        }

        function syncFromInput(id, val) {
            const slider = document.querySelector(`.worker-slider[data-id="${id}"]`);
            const currentTotal = calculateOtherTotal(id);
            let newVal = parseFloat(val) || 0;

            if (currentTotal + newVal > maxWorkerPercent) {
                newVal = maxWorkerPercent - currentTotal;
                if (newVal < 0) newVal = 0;
                document.querySelector(`.percent-input[data-id="${id}"]`).value = newVal.toFixed(1);
            }

            slider.value = newVal;
            updateChart();
        }

        function calculateOtherTotal(currentId) {
            let total = 0;
            document.querySelectorAll('.percent-input').forEach(input => {
                if (parseInt(input.dataset.id) !== currentId) {
                    total += parseFloat(input.value) || 0;
                }
            });
            return total;
        }

        function updateChart() {
            const inputs = document.querySelectorAll('.percent-input');
            let total = 0;
            const workerBudgetData = [];

            inputs.forEach((input) => {
                let val = parseFloat(input.value) || 0;
                total += val;
                workerBudgetData.push((projectBudget * val) / 100);
            });

            const remainingDisplay = document.getElementById('remainingPercent');
            const saveBtn = document.getElementById('saveBtn');

            if (total > maxWorkerPercent + 0.01) {
                remainingDisplay.classList.add('limit-reached');
                saveBtn.disabled = true;
            } else {
                remainingDisplay.classList.remove('limit-reached');
                saveBtn.disabled = false;
            }

            const allocatedBudget = workerBudgetData.reduce((a, b) => a + b, 0);
            workerBudgetData.push(Math.max(0, projectBudget - allocatedBudget - additionalFeesTotal));
            const feeData = feeItems.map(f => parseFloat(f.amount) || 0);
            workerBudgetData.push(...feeData);
            chart.data.datasets[0].data = workerBudgetData;
            chart.update();
            updateRemaining(total);
        }

        function updateRemaining(total) {
            if (total === undefined) {
                total = Array.from(document.querySelectorAll('.percent-input')).reduce((a, b) => a + (parseFloat(b.value) || 0), 0);
            }
            const remaining = (100 - total - additionalFeesPercent).toFixed(1);
            document.getElementById('remainingPercent').innerText = remaining + '%';
            const remainingBudgetValue = Math.max(0, projectBudget - ((projectBudget * total) / 100) - additionalFeesTotal);
            document.getElementById('remainingBudget').innerText = formatCurrency(remainingBudgetValue);
        }

        function updateFeeInputMode() {
            const type = document.getElementById('feeInputType').value;
            const amountGroup = document.getElementById('feeAmountGroup');
            const amountInput = document.getElementById('feeAmountInput');
            const percentInput = document.getElementById('feePercentInput');
            const percentGroup = document.getElementById('feePercentGroup');

            if (type === 'percent') {
                amountGroup.style.display = 'none';
                percentGroup.style.display = '';
                percentInput.required = true;
                amountInput.required = false;
                amountInput.value = '';
            } else {
                amountGroup.style.display = '';
                percentGroup.style.display = 'none';
                percentInput.required = false;
                percentInput.value = '';
                amountInput.required = true;
            }
            updateFeePreview();
        }

        function updateFeePreview() {
            const preview = document.getElementById('feePreviewText');
            const type = document.getElementById('feeInputType').value;
            if (type !== 'percent') {
                preview.innerText = '';
                return;
            }
            const percent = parseFloat(document.getElementById('feePercentInput').value) || 0;
            const amount = (projectBudget * percent) / 100;
            preview.innerText = 'Preview: ' + percent.toFixed(1) + '% of budget = ' + formatCurrency(amount);
        }

        function validateFeeForm() {
            const type = document.getElementById('feeInputType').value;
            const amountInput = document.getElementById('feeAmountInput');
            const percentInput = document.getElementById('feePercentInput');

            let feeAmount = 0;
            if (type === 'percent') {
                const percent = parseFloat(percentInput.value) || 0;
                feeAmount = (projectBudget * percent) / 100;
            } else {
                feeAmount = parseFloat(amountInput.value) || 0;
            }

            if (feeAmount > (remainingBudgetForNewFee + 0.00001)) {
                alert('Fee amount exceeds Remaining Budget. Max allowed: ' + formatCurrency(remainingBudgetForNewFee));
                return false;
            }
            return true;
        }

        window.onload = function() {
            initChart();
            updateFeeInputMode();
        };
    </script>
</body>

</html>