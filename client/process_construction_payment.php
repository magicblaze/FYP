<?php
// Public/process_construction_payment.php
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_id = (int) ($_SESSION['user']['clientid'] ?? 0);

// Check if payment session exists
if (!isset($_SESSION['construction_payment'])) {
    header('Location: order_history.php');
    exit;
}

$payment = $_SESSION['construction_payment'];
$order_id = $payment['order_id'];
$amount_to_pay = $payment['amount'];
$plan = $payment['plan'];
$installment_index = $payment['installment_index'];
$installments = $payment['installments'];

// Verify order and get payment method
$sql = "SELECT o.*, op.total_cost, c.payment_method, c.cname, c.budget, op.payment_id,
           op.construction_main_pct, op.construction_deposit_pct, op.materials_pct
        FROM `Order` o
        JOIN OrderPayment op ON o.payment_id = op.payment_id
        JOIN Client c ON o.clientid = c.clientid
        WHERE o.orderid = ? AND o.clientid = ?";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "ii", $order_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    session_unset();
    header('Location: order_history.php');
    exit;
}

$paid_total_sql = "SELECT IFNULL(SUM(amount), 0) AS total_paid FROM ConstructionPaymentRecord WHERE orderid = ? AND status = 'paid'";
$paid_total_stmt = mysqli_prepare($mysqli, $paid_total_sql);
mysqli_stmt_bind_param($paid_total_stmt, "i", $order_id);
mysqli_stmt_execute($paid_total_stmt);
$paid_total_result = mysqli_stmt_get_result($paid_total_stmt);
$paid_total_row = mysqli_fetch_assoc($paid_total_result);
$current_total_paid = floatval($paid_total_row['total_paid'] ?? 0);
mysqli_stmt_close($paid_total_stmt);

$paid_split_sql = "SELECT
                        IFNULL(SUM(CASE
                            WHEN LOWER(TRIM(IFNULL(milestone, ''))) LIKE '%construction deposit%'
                            THEN amount ELSE 0 END), 0) AS paid_before_construction,
                        IFNULL(SUM(CASE
                            WHEN percentage IN (25, 50, 75, 100)
                                 AND LOWER(TRIM(IFNULL(milestone, ''))) NOT LIKE '%design%'
                                 AND LOWER(TRIM(IFNULL(milestone, ''))) NOT LIKE '%construction deposit%'
                            THEN amount ELSE 0 END), 0) AS paid_while_construction
                    FROM ConstructionPaymentRecord
                    WHERE orderid = ?
                      AND status = 'paid'";
$paid_split_stmt = mysqli_prepare($mysqli, $paid_split_sql);
mysqli_stmt_bind_param($paid_split_stmt, "i", $order_id);
mysqli_stmt_execute($paid_split_stmt);
$paid_split_result = mysqli_stmt_get_result($paid_split_stmt);
$paid_split_row = mysqli_fetch_assoc($paid_split_result);
$paid_before_construction = floatval($paid_split_row['paid_before_construction'] ?? 0);
$paid_while_construction = floatval($paid_split_row['paid_while_construction'] ?? 0);
mysqli_stmt_close($paid_split_stmt);

$paid_deposit_material_sql = "SELECT IFNULL(SUM(amount), 0) AS paid_deposit_material
                                                            FROM ConstructionPaymentRecord
                                                            WHERE orderid = ?
                                                                AND status = 'paid'
                                                                AND (
                                                                        LOWER(TRIM(IFNULL(milestone, ''))) LIKE '%deposit%'
                                                                        OR LOWER(TRIM(IFNULL(milestone, ''))) LIKE '%material%'
                                                                )";
$paid_deposit_material_stmt = mysqli_prepare($mysqli, $paid_deposit_material_sql);
mysqli_stmt_bind_param($paid_deposit_material_stmt, "i", $order_id);
mysqli_stmt_execute($paid_deposit_material_stmt);
$paid_deposit_material_result = mysqli_stmt_get_result($paid_deposit_material_stmt);
$paid_deposit_material_row = mysqli_fetch_assoc($paid_deposit_material_result);
$paid_deposit_material = floatval($paid_deposit_material_row['paid_deposit_material'] ?? 0);
mysqli_stmt_close($paid_deposit_material_stmt);

$total_cost = floatval($order['total_cost'] ?? 0);
$construction_main_pct = isset($order['construction_main_pct']) ? floatval($order['construction_main_pct']) : 0.0;
$construction_deposit_pct = isset($order['construction_deposit_pct']) ? floatval($order['construction_deposit_pct']) : 0.0;
$materials_pct = isset($order['materials_pct']) ? floatval($order['materials_pct']) : 0.0;

$construction_main_cost = $total_cost * ($construction_main_pct / 100.0);
$materials_allocated = $total_cost * ($materials_pct / 100.0);
$construction_cost_excl_materials = max(0.0, $construction_main_cost - $materials_allocated);

$actual_materials_cost = 0.0;
$hasRefQuantity = false;
$quantity_column_result = mysqli_query($mysqli, "SHOW COLUMNS FROM `OrderReference` LIKE 'quantity'");
if ($quantity_column_result) {
    $hasRefQuantity = (mysqli_num_rows($quantity_column_result) > 0);
    mysqli_free_result($quantity_column_result);
}

$quantity_expr = $hasRefQuantity
    ? "CASE WHEN orr.quantity IS NULL OR orr.quantity <= 0 THEN 1 ELSE orr.quantity END"
    : "1";

$materials_sql = "SELECT IFNULL(SUM(COALESCE(orr.price, p.price, 0) * {$quantity_expr}), 0) AS material_total
                  FROM `OrderReference` orr
                  LEFT JOIN `Product` p ON orr.productid = p.productid
                  WHERE orr.orderid = ?
                    AND (orr.status IS NULL OR LOWER(TRIM(orr.status)) <> 'rejected')";
$materials_stmt = mysqli_prepare($mysqli, $materials_sql);
if ($materials_stmt) {
    mysqli_stmt_bind_param($materials_stmt, "i", $order_id);
    mysqli_stmt_execute($materials_stmt);
    $materials_result = mysqli_stmt_get_result($materials_stmt);
    $materials_row = mysqli_fetch_assoc($materials_result);
    $actual_materials_cost = isset($materials_row['material_total']) ? (float) $materials_row['material_total'] : 0.0;
    mysqli_stmt_close($materials_stmt);
}

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Define milestone messages based on payment plan and installment index
function getSelectedMilestonePercentage($plan, $installment_index, $installments) {
    if ($plan === 'installment_25') {
        $map = [25, 50, 75, 100];
        return isset($map[$installment_index]) ? (int) $map[$installment_index] : 25;
    }
    if ($plan === 'installment_50') {
        $map = [50, 100];
        return isset($map[$installment_index]) ? (int) $map[$installment_index] : 50;
    }
    $fallback = $installments[$installment_index]['percentage'] ?? 100;
    return (int) $fallback;
}

function getMilestoneMessage($plan, $installment_index, $total_installments, $installments) {
    $current_percent = getSelectedMilestonePercentage($plan, $installment_index, $installments);
    if ($plan === 'installment_25') {
        return "Installment " . ($installment_index + 1) . " of 4 - {$current_percent}% Complete";
    } elseif ($plan === 'installment_50') {
        if ((int) $installment_index === 0) {
            return "Installment 1 of 2 - Start Construction (50%)";
        }
        return "Installment 2 of 2 - End Construction (100%)";
    } else {
        return "Full Construction Payment";
    }
}

$selected_milestone_percentage = getSelectedMilestonePercentage($plan, $installment_index, $installments);
$prev_paid_milestone_pct = 0;
$prev_paid_pct_sql = "SELECT IFNULL(MAX(percentage), 0) AS prev_paid_milestone_pct
                      FROM ConstructionPaymentRecord
                      WHERE orderid = ?
                        AND status = 'paid'
                        AND percentage IN (25, 50, 75, 100)
                        AND percentage < ?
                        AND LOWER(TRIM(IFNULL(milestone, ''))) NOT LIKE '%design%'
                        AND LOWER(TRIM(IFNULL(milestone, ''))) NOT LIKE '%construction deposit%'";
$prev_paid_pct_stmt = mysqli_prepare($mysqli, $prev_paid_pct_sql);
if ($prev_paid_pct_stmt) {
    mysqli_stmt_bind_param($prev_paid_pct_stmt, "ii", $order_id, $selected_milestone_percentage);
    mysqli_stmt_execute($prev_paid_pct_stmt);
    $prev_paid_pct_result = mysqli_stmt_get_result($prev_paid_pct_stmt);
    $prev_paid_pct_row = mysqli_fetch_assoc($prev_paid_pct_result);
    $prev_paid_milestone_pct = isset($prev_paid_pct_row['prev_paid_milestone_pct'])
        ? (int) $prev_paid_pct_row['prev_paid_milestone_pct']
        : 0;
    mysqli_stmt_close($prev_paid_pct_stmt);
}

$effective_installment_pct = max(0, $selected_milestone_percentage - $prev_paid_milestone_pct);
$times_to_pay = max(0.0, ((float) $effective_installment_pct) / 100.0);
$base_payable = max(0.0, ($construction_cost_excl_materials + $actual_materials_cost) - $paid_before_construction);
$amount_to_pay = round($base_payable * $times_to_pay, 2);
$remaining_budget_after_payment = floatval($order['budget']) - ($current_total_paid + $amount_to_pay);

// Handle payment submission
$message = '';
$error = '';
$payment_success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['proceed_pay'])) {
    // Check if payment method exists
    if (empty($paymentMethodData) || empty($paymentMethodData['method'])) {
        $error = 'Please configure a payment method in your profile first.';
    } else {
        // Begin transaction
        mysqli_begin_transaction($mysqli);
        
        try {
            $payment_id = intval($order['payment_id']);
            $installment_num = $installment_index + 1;
            $current_percentage = $selected_milestone_percentage;
            
            // Store installment payment record (if table exists)
            $installment_sql = "INSERT INTO ConstructionPaymentRecord 
                                (orderid, installment_number, percentage, amount, milestone, paid_at, status)
                                VALUES (?, ?, ?, ?, ?, NOW(), 'paid')";
            $installment_stmt = mysqli_prepare($mysqli, $installment_sql);
            if ($installment_stmt) {
                $milestone = getMilestoneMessage($plan, $installment_index, count($installments), $installments);
                mysqli_stmt_bind_param($installment_stmt, "iiids", $order_id, $installment_num, $current_percentage, $amount_to_pay, $milestone);
                mysqli_stmt_execute($installment_stmt);
                mysqli_stmt_close($installment_stmt);
            }
            
            mysqli_commit($mysqli);
            
            // Check if this was the last installment
            $is_last = ($installment_index + 1 >= count($installments));
            
            if ($is_last) {
                // Clear session
                unset($_SESSION['construction_payment']);
                $payment_success = true;
                $message = "Payment successful!";
            } else {
                // Update session for next installment
                $_SESSION['construction_payment']['installment_index'] = $installment_index + 1;
                $_SESSION['construction_payment']['amount'] = $installments[$installment_index + 1]['amount'];
                
                $next_milestone = getMilestoneMessage($plan, $installment_index + 1, count($installments), $installments);
                $payment_success = true;
                $message = "Payment successful! Your next payment of $" . number_format($installments[$installment_index + 1]['amount'], 2) . " will be due at {$next_milestone}.";
            }
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            error_log("Payment error: " . $e->getMessage());
            $error = "Payment processing failed. Please try again.";
        }
    }
}

$current_installment_info = $installments[$installment_index];
$total_paid = $current_total_paid;
$remaining_budget = floatval($order['budget']) - $total_paid;
$current_milestone = getMilestoneMessage($plan, $installment_index, count($installments), $installments);

// Determine progress steps based on payment plan
if ($plan === 'installment_25') {
    $total_installments = 4;
    $current_step = $installment_index + 1;
    $step_labels = ['25% Complete', '50% Complete', '75% Complete', '100% Complete'];
} elseif ($plan === 'installment_50') {
    $total_installments = 2;
    $current_step = $installment_index + 1;
    $step_labels = ['Start Construction (50%)', 'End Construction (100%)'];
} else {
    $total_installments = 1;
    $current_step = 1;
    $step_labels = ['Full Payment'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Construction Payment - Project #<?php echo $order_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payment-section {
            background: #e8f5e9;
            border-left: 4px solid #27ae60;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .payment-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #e74c3c;
        }
        .alert-message {
            margin-top: 1rem;
        }
        .budget-info {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        /* Progress Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 50px;
            overflow-x: auto;
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            flex: 1;
            min-width: 80px;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            border: 2px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #6c757d;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .step.completed .step-circle {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }
        
        .step.current .step-circle {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }
        
        .step.future .step-circle {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #6c757d;
        }
        
        .step-label {
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
            color: #6c757d;
            white-space: nowrap;
        }
        
        .step.completed .step-label {
            color: #28a745;
        }
        
        .step.current .step-label {
            color: #ffc107;
            font-weight: 600;
        }
        
        .step-connector {
            flex: 0 0 20px;
            height: 2px;
            background-color: #dee2e6;
        }
        
        .step-connector.completed {
            background-color: #28a745;
        }
        
        .payment-type-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
            background-color: #27ae60;
            color: white;
        }
        
        .payment-detail {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .payment-detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .payment-detail-item:last-child {
            border-bottom: none;
        }
        
        .fee-breakdown {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 6px;
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <div class="payment-type-badge">
                    <i class="fas fa-hard-hat me-1"></i> 
                    <?php 
                    if ($plan === 'full') {
                        echo 'Construction Payment (Full)';
                    } elseif ($plan === 'installment_25') {
                        echo 'Construction Payment - Installment ' . $current_step . ' of 4 (25% each)';
                    } else {
                        echo 'Construction Payment - Installment ' . $current_step . ' of 2 (50% each)';
                    }
                    ?>
                </div>
                
                <h4>Payment for Project #<?php echo $order_id; ?></h4>
                
                <!-- Success Message -->
                <?php if ($payment_success && $message): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $message; ?>
                    </div>
                    <a href="order_history.php" class="btn btn-primary mt-3">Back to Project History</a>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-message">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$payment_success): ?>
                <!-- Progress Steps -->
                <div class="progress-steps">
                    <?php for ($i = 1; $i <= $total_installments; $i++): ?>
                        <div class="step <?php 
                            if ($i < $current_step) echo 'completed';
                            elseif ($i == $current_step) echo 'current';
                            else echo 'future';
                        ?>">
                            <div class="step-circle">
                                <?php if ($i < $current_step): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    <?php echo $i; ?>
                                <?php endif; ?>
                            </div>
                            <span class="step-label">
                                <?php echo htmlspecialchars($step_labels[$i - 1] ?? ('Milestone ' . $i)); ?>
                            </span>
                        </div>
                        <?php if ($i < $total_installments): ?>
                            <div class="step-connector <?php echo ($i < $current_step) ? 'completed' : ''; ?>"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>

                <!-- Payment Section -->
                <div class="payment-section">
                    <h5 class="mb-3"><i class="fas fa-credit-card me-2"></i><?php echo htmlspecialchars($current_milestone); ?></h5>
                    
                    <div class="payment-detail">
                        <div class="payment-detail-item">
                            <span>Project #<?php echo $order_id; ?></span>
                            <span><?php echo date('Y-m-d'); ?></span>
                        </div>
                        
                        <div class="fee-breakdown">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Construction Cost (Excl. Materials):</span>
                                <span>HK$<?php echo number_format($construction_cost_excl_materials, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Material Cost:</span>
                                <span>HK$<?php echo number_format($actual_materials_cost, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 text-info fw-bold">
                                <span>Already Paid (Deposit + Material):</span>
                                <span>- HK$<?php echo number_format($paid_deposit_material, 2); ?></span>
                            </div>                        
                            <div class="d-flex justify-content-between mb-1 fw-bold" style="border-top: 2px solid #27ae60; padding-top: 0.5rem;">
                                <span>Total Amount to Pay during Construction</span>
                                <span>HK$<?php echo number_format($base_payable, 2); ?></span>
                            </div>
                            
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">This Installment Amount:</span>
                            <span class="payment-amount fw-bold">
                                HK$<?php echo number_format($amount_to_pay, 2); ?>
                                <small class="d-block text-muted fs-6 fw-normal">/ <?php echo (int) $effective_installment_pct; ?>% of Total Amount to Pay during Construction</small>
                            </span>
                        </div>
                    </div>
                    
                    <div class="budget-info">
                        <i class="fas fa-wallet me-2"></i>
                        Remaining Budget after this payment: <strong>HK$<?php echo number_format($remaining_budget_after_payment, 2); ?></strong>
                    </div>
                </div>

                <hr>

                <h5>Payment Method</h5>
                <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                    <div class="mb-3">
                        <p>Using: <strong><?php echo htmlspecialchars($paymentMethodData['method']); ?></strong></p>
                        <?php if (!empty($paymentMethodData['card_last4'])): ?>
                            <p class="small text-muted">Card ending in <?php echo htmlspecialchars($paymentMethodData['card_last4']); ?></p>
                        <?php endif; ?>
                        <p class="small text-muted">If you want to change the payment method, update it in your <a href="profile.php">profile</a>.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">No payment method configured. Please add one in your <a href="profile.php">profile</a> before proceeding.</div>
                <?php endif; ?>

                <form method="post">
                    <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                        <button type="submit" name="proceed_pay" class="btn btn-success" 
                                onclick="return confirm('Confirm payment of HK$<?php echo number_format($amount_to_pay, 2); ?>?');">
                            <i class="fas fa-credit-card me-1"></i>
                            Pay HK$<?php echo number_format($amount_to_pay, 2); ?>
                        </button>
                    <?php else: ?>
                        <a href="profile.php" class="btn btn-warning">Add Payment Method</a>
                    <?php endif; ?>
                </form>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>