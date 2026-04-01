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

// Initialize variables
$error_message = '';
$success_message = '';

// Handle BATCH UPDATE of allocation percentages
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_update'])) {
    $percentages = $_POST['percentages']; 
    $total = 0;
    foreach($percentages as $p) { $total += floatval($p); }
    
    if ($total > 100.01) { 
        $error_message = "Error: Total percentage cannot exceed 100%. Current total: " . number_format($total, 1) . "%";
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
    $fee_name = mysqli_real_escape_string($mysqli, $_POST['fee_name']);
    $fee_amount = floatval($_POST['fee_amount']);
    $fee_description = mysqli_real_escape_string($mysqli, $_POST['fee_description']);
    
    if (empty($fee_name) || $fee_amount <= 0) {
        $error_message = "Please provide a valid fee name and amount.";
    } else {
        $insert_fee_sql = "INSERT INTO `AdditionalFee` (orderid, fee_name, amount, description) VALUES (?, ?, ?, ?)";
        $insert_fee_stmt = mysqli_prepare($mysqli, $insert_fee_sql);
        mysqli_stmt_bind_param($insert_fee_stmt, "isds", $order_id, $fee_name, $fee_amount, $fee_description);
        if (mysqli_stmt_execute($insert_fee_stmt)) {
            $success_message = "Custom fee '$fee_name' added successfully!";
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

// Get order details
$order_sql = "SELECT o.*, c.cname as client_name FROM `Order` o JOIN `Client` c ON o.clientid = c.clientid WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));

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
foreach($allocated_workers as $aw) { $total_allocated_percent += floatval($aw['percentage']); }
$total_extra_fees = 0;
foreach($additional_fees as $fee) { $total_extra_fees += $fee['amount']; }
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
    <style>
        body { background-color: #f4f7f6; }
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05 ); border: none; margin-bottom: 1.5rem; }
        .worker-img { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; }
        .back-btn { color: #7f8c8d; text-decoration: none; font-weight: 600; transition: all 0.3s; }
        .back-btn:hover { color: #3498db; transform: translateX(-5px); }
        .section-title { border-left: 4px solid #3498db; padding-left: 10px; margin-bottom: 20px; font-weight: 700; }
        .chart-container { position: relative; height: 280px; width: 100%; }
        .percent-input { width: 80px !important; text-align: center; }
        .unallocated-box { background: #f8f9fa; border: 1px dashed #dee2e6; border-radius: 8px; padding: 10px; }
        .limit-reached { color: #e74c3c !important; font-weight: bold; }
        .fee-list-container { max-height: 350px; overflow-y: auto; }
        .fee-item { border-left: 3px solid #e67e22; background: #fffaf5; margin-bottom: 12px; padding: 12px; border-radius: 6px; }
        .fee-desc { font-size: 11px; color: #7f8c8d; margin-top: 4px; font-style: italic; }
        .default-pay-box { background: #e8f4fd; border: 1px solid #3498db; border-radius: 8px; padding: 10px; margin-bottom: 15px; }
        .table thead th { color: #000 !important; }
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
            <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="row">
            <!-- Top Row: Chart on Left, Worker List on Right -->
            <div class="col-lg-5 mb-4">
                <div class="card p-4 h-100">
                    <h4 class="section-title">Visual Distribution</h4>
                    <div class="chart-container mb-3">
                        <canvas id="distributionChart"></canvas>
                    </div>
                    <div class="unallocated-box text-center">
                        <span class="text-muted small">Remaining Unallocated:</span>
                        <h4 id="remainingPercent" class="mb-0 text-primary">0.0%</h4>
                    </div>
                    <div class="card bg-light border-0 p-3 mt-3">
                        <h6 class="small text-muted mb-2">Summary Info</h6>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small">Total Workers:</span>
                            <span class="small fw-bold"><?= count($allocated_workers) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small">Extra Fees:</span>
                            <span class="small fw-bold text-warning">$<?= number_format($total_extra_fees, 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 mb-4">
                <div class="card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="section-title mb-0">1. Worker Pay Split (%)</h4>
                        <button type="submit" form="batchUpdateForm" name="batch_update" id="saveBtn" class="btn btn-primary btn-sm px-4 shadow-sm">
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
                                <input type="number" name="default_pay_value" id="defaultPayValue" class="form-control" value="<?= number_format($supplier_default_pay, 1) ?>" min="0" max="100" step="0.1">
                                <span class="input-group-text">%</span>
                            </div>
                            <button type="submit" name="set_default_pay" class="btn btn-sm btn-primary ms-2">Set Default Pay</button>
                            <button type="button" onclick="applyDefaultPay()" class="btn btn-sm btn-outline-secondary ms-2">Apply to 0% Workers</button>
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
                                                    <img src="../uploads/worker/<?= $worker['image'] ?: 'default.jpg' ?>" class="worker-img me-2 border">
                                                    <div>
                                                        <span class="fw-bold d-block small"><?= htmlspecialchars($worker['name']) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="input-group input-group-sm mx-auto" style="width: 100px;">
                                                    <input type="number" name="percentages[<?= $worker['allocation_id'] ?>]" 
                                                           class="form-control percent-input" 
                                                           data-id="<?= $worker['allocation_id'] ?>"
                                                           value="<?= number_format($worker['percentage'], 1) ?>" 
                                                           min="0" max="100" step="0.1"
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
                        <p class="text-center py-5 text-muted">No workers allocated. Go back to Team Allocation to add workers.</p>
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
                            <form method="POST" class="bg-light p-3 rounded">
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted">Fee Name</label>
                                    <input type="text" name="fee_name" class="form-control form-control-sm" placeholder="e.g. Special Bonus" required>
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted">Amount ($)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">$</span>
                                        <input type="number" name="fee_amount" class="form-control" placeholder="0.00" step="0.01" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold text-muted">Description</label>
                                    <textarea name="fee_description" class="form-control form-control-sm" rows="2" placeholder="Describe the purpose of this fee..."></textarea>
                                </div>
                                <button type="submit" name="add_fee" class="btn btn-sm btn-success w-100">Add New Fee</button>
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
                                                            <span class="fw-bold small text-truncate" title="<?= htmlspecialchars($fee['fee_name']) ?>"><?= htmlspecialchars($fee['fee_name']) ?></span>
                                                            <span class="text-primary small fw-bold">$<?= number_format($fee['amount'], 2) ?></span>
                                                        </div>
                                                        <?php if (!empty($fee['description'])): ?>
                                                            <div class="fee-desc"><?= htmlspecialchars($fee['description']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this fee? This action cannot be undone.')">
                                                        <input type="hidden" name="fee_id" value="<?= $fee['fee_id'] ?>">
                                                        <button type="submit" name="delete_fee" class="btn btn-link text-danger p-0 ms-2"><i class="fas fa-trash-alt fa-xs"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted py-5 small">No extra fees added yet. Use the form on the left to add commissions or other costs.</p>
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
        let chart;

        function initChart() {
            const ctx = document.getElementById('distributionChart').getContext('2d');
            const data = {
                labels: workers.map(w => w.name).concat(['Unallocated']),
                datasets: [{
                    data: workers.map(w => parseFloat(w.percentage)).concat([Math.max(0, 100 - workers.reduce((a, b) => a + parseFloat(b.percentage), 0))]),
                    backgroundColor: [
                        '#3498db', '#2ecc71', '#f1c40f', '#e74c3c', '#9b59b6', '#1abc9c', '#f39c12', '#d35400', '#7f8c8d'
                    ].slice(0, workers.length).concat(['#ecf0f1']),
                    borderWidth: 2
                }]
            };

            chart = new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    cutout: '75%'
                }
            });
            updateRemaining();
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
                    if (otherTotal + finalVal > 100) {
                        finalVal = Math.max(0, 100 - otherTotal);
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

            if (currentTotal + newVal > 100) {
                newVal = 100 - currentTotal;
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

            if (currentTotal + newVal > 100) {
                newVal = 100 - currentTotal;
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
            const newData = [];
            
            inputs.forEach((input) => {
                let val = parseFloat(input.value) || 0;
                total += val;
                newData.push(val);
            });

            const remainingDisplay = document.getElementById('remainingPercent');
            const saveBtn = document.getElementById('saveBtn');

            if (total > 100.01) {
                remainingDisplay.classList.add('limit-reached');
                saveBtn.disabled = true;
            } else {
                remainingDisplay.classList.remove('limit-reached');
                saveBtn.disabled = false;
            }

            newData.push(Math.max(0, 100 - total));
            chart.data.datasets[0].data = newData;
            chart.update();
            updateRemaining(total);
        }

        function updateRemaining(total) {
            if (total === undefined) {
                total = Array.from(document.querySelectorAll('.percent-input')).reduce((a, b) => a + (parseFloat(b.value) || 0), 0);
            }
            const remaining = (100 - total).toFixed(1);
            document.getElementById('remainingPercent').innerText = remaining + '%';
        }

        window.onload = initChart;
    </script>
</body>
</html>
