<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Only allow logged-in clients
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$client_id = $user['clientid'];

$orderid = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;
if ($orderid <= 0) die('Project ID missing');

$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// Load order and payment data from OrderPayment
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, o.deposit, o.final_payment,
               d.expect_price as design_price, d.tag,
               c.clientid, c.cname, c.payment_method, c.budget,
           op.total_cost,
           op.commission_final_pct, op.construction_main_pct,
           op.construction_deposit_pct, op.materials_pct,
           op.inspection_pct, op.contractor_pct
        FROM `Order` o
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `OrderPayment` op ON o.payment_id = op.payment_id
        WHERE o.orderid = ? AND o.clientid = ? LIMIT 1";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "ii", $orderid, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);
if (!$order) {
    die('Project not found or access denied.');
}

$order_status_lower = strtolower(trim((string) ($order['ostatus'] ?? '')));
$can_pay_inspection = ($order_status_lower === 'inspection_completed');

// Get payment values from percentage configuration
$total_cost = isset($order['total_cost']) ? floatval($order['total_cost']) : 0;
$commission_final_pct = isset($order['commission_final_pct']) ? floatval($order['commission_final_pct']) : 0;
$construction_main_pct = isset($order['construction_main_pct']) ? floatval($order['construction_main_pct']) : 0;
$construction_deposit_pct = isset($order['construction_deposit_pct']) ? floatval($order['construction_deposit_pct']) : 0;
$materials_pct = isset($order['materials_pct']) ? floatval($order['materials_pct']) : 0;
$inspection_pct = isset($order['inspection_pct']) ? floatval($order['inspection_pct']) : 0;
$contractor_pct = isset($order['contractor_pct']) ? floatval($order['contractor_pct']) : 0;

$construction_main_price = $total_cost * ($construction_main_pct / 100);
$construction_deposit = $construction_main_price * ($construction_deposit_pct / 100);
$commission_final = $total_cost * ($commission_final_pct / 100);
$materials_cost = $total_cost * ($materials_pct / 100);
$inspection_fee = $total_cost * ($inspection_pct / 100);
$contractor_fee = $total_cost * ($contractor_pct / 100);
$total_amount_due = $total_cost;

$paid_total_sql = "SELECT IFNULL(SUM(amount), 0) AS total_paid FROM ConstructionPaymentRecord WHERE orderid = ? AND status = 'paid'";
$paid_total_stmt = mysqli_prepare($mysqli, $paid_total_sql);
mysqli_stmt_bind_param($paid_total_stmt, "i", $orderid);
mysqli_stmt_execute($paid_total_stmt);
$paid_total_result = mysqli_stmt_get_result($paid_total_stmt);
$paid_total_row = mysqli_fetch_assoc($paid_total_result);
$total_amount_paid = floatval($paid_total_row['total_paid'] ?? 0);
mysqli_stmt_close($paid_total_stmt);
$design_price = isset($order['design_price']) ? floatval($order['design_price']) : 0;
$current_budget = isset($order['budget']) ? floatval($order['budget']) : 0;

// This page only charges the inspection fee.
$total_to_pay = max(0.0, $inspection_fee);

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Payment status - from session or URL (keep backward compatibility with legacy key)
$payment_success = isset($_GET['success']) ? true : (
    (isset($_SESSION['payment_inspection_success_' . $orderid]) ? $_SESSION['payment_inspection_success_' . $orderid] : false)
    || (isset($_SESSION['payment_final_construction_success_' . $orderid]) ? $_SESSION['payment_final_construction_success_' . $orderid] : false)
);
$payment_rejected = isset($_GET['rejected']) ? true : (isset($_SESSION['payment_rejected_' . $orderid]) ? $_SESSION['payment_rejected_' . $orderid] : false);

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reject_payment'])) {
        if (!$can_pay_inspection) {
            header('Location: order_history.php');
            exit;
        }
        $_SESSION['payment_rejected_' . $orderid] = true;
        $payment_rejected = true;
        header('Location: payment_construction2.php?orderid=' . $orderid . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        if (!$can_pay_inspection) {
            header('Location: order_history.php');
            exit;
        }
        // Begin transaction
        mysqli_begin_transaction($mysqli);
        
        try {
            $milestone = 'Inspection Payment';
            $installment_number = 6;
            $percentage = intval(round($inspection_pct));
            $pay_record_sql = "INSERT INTO ConstructionPaymentRecord (orderid, installment_number, percentage, amount, milestone, paid_at, status) VALUES (?, ?, ?, ?, ?, NOW(), 'paid')";
            $pay_record_stmt = mysqli_prepare($mysqli, $pay_record_sql);
            mysqli_stmt_bind_param($pay_record_stmt, "iiids", $orderid, $installment_number, $percentage, $total_to_pay, $milestone);
            mysqli_stmt_execute($pay_record_stmt);
            mysqli_stmt_close($pay_record_stmt);
            
            // Update order status to complete
            $u_sql = "UPDATE `Order` SET ostatus = 'complete' WHERE orderid = ? AND clientid = ?";
            $u_stmt = mysqli_prepare($mysqli, $u_sql);
            mysqli_stmt_bind_param($u_stmt, "ii", $orderid, $client_id);
            mysqli_stmt_execute($u_stmt);
            mysqli_stmt_close($u_stmt);
            
            mysqli_commit($mysqli);
            
            // Set session flag for successful payment
            $_SESSION['payment_inspection_success_' . $orderid] = true;
            
            // Redirect to same page with success parameter
            header('Location: payment_construction2.php?orderid=' . $orderid . '&amount=' . $total_to_pay . '&success=1');
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            error_log("Payment error: " . $e->getMessage());
            die("Payment processing failed. Please try again.");
        }
    }
}

$stage_title = 'Inspection Payment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inspection Payment - Project #<?php echo $orderid; ?></title>
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
        
        .step.rejected .step-circle {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
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
                    <i class="fas fa-clipboard-check me-1"></i> Inspection Payment
                </div>
                
                <h4>Payment for Project #<?php echo $orderid; ?></h4>

                <?php if (!$can_pay_inspection && !$payment_success): ?>
                    <div class="alert alert-warning alert-message">
                        <i class="fas fa-info-circle me-2"></i>
                        Inspection payment is available only after inspection status is <strong>inspection_completed</strong>.
                    </div>
                <?php endif; ?>
                
                <!-- Budget check removed for this payment stage -->
                
                <!-- Success Message -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>
                        Payment successful.
                    </div>
                <?php endif; ?>
                
                <!-- Rejected Message -->
                <?php if (isset($_GET['rejected'])): ?>
                    <div class="alert alert-danger alert-message">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Payment rejected. You may contact support for further assistance.
                    </div>
                <?php endif; ?>

                <!-- Progress Steps (6 stages) -->
                <div class="progress-steps">
                    <!-- Stage 1: Deposit - Completed -->
                    <div class="step completed">
                        <div class="step-circle">
                            <i class="fas fa-check"></i>
                        </div>
                        <span class="step-label">Deposit</span>
                    </div>
                    <div class="step-connector completed"></div>
                    
                    <!-- Stage 2: 2nd Payment - Completed -->
                    <div class="step completed">
                        <div class="step-circle">
                            <i class="fas fa-check"></i>
                        </div>
                        <span class="step-label">2nd Payment</span>
                    </div>
                    <div class="step-connector completed"></div>
                    
                    <!-- Stage 3: Final Design - Completed -->
                    <div class="step completed">
                        <div class="step-circle">
                            <i class="fas fa-check"></i>
                        </div>
                        <span class="step-label">Final Design</span>
                    </div>
                    <div class="step-connector completed"></div>
                    
                    <!-- Stage 4: Const. Deposit - Completed -->
                    <div class="step completed">
                        <div class="step-circle">
                            <i class="fas fa-check"></i>
                        </div>
                        <span class="step-label">Const. Deposit</span>
                    </div>
                    <div class="step-connector completed"></div>
                    
                    <!-- Stage 5: Inspection - Current (Yellow) -->
                    <div class="step <?php 
                        if ($payment_rejected) echo 'rejected';
                        elseif ($payment_success) echo 'completed';
                        else echo 'current';
                    ?>">
                        <div class="step-circle">
                            <?php if ($payment_success): ?>
                                <i class="fas fa-check"></i>
                            <?php elseif ($payment_rejected): ?>
                                <i class="fas fa-times"></i>
                            <?php else: ?>
                                5
                            <?php endif; ?>
                        </div>
                        <span class="step-label">Inspection</span>
                    </div>
                    <div class="step-connector <?php echo ($payment_success) ? 'completed' : ''; ?>"></div>
                    
                    <!-- Stage 6: Complete - Future -->
                    <div class="step future">
                        <div class="step-circle">6</div>
                        <span class="step-label">Complete</span>
                    </div>
                </div>

                <!-- Payment Section -->
                <div class="payment-section">
                    <h5 class="mb-3"><i class="fas fa-clipboard-check me-2"></i>Inspection Payment</h5>
                    
                    <div class="payment-detail">
                        <div class="payment-detail-item">
                            <span>Project #<?php echo $orderid; ?></span>
                            <span><?php echo date('Y-m-d', strtotime($order['odate'])); ?></span>
                        </div>
                        
                        <!-- Show breakdown of fees -->
                        <div class="fee-breakdown">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Inspection Fee (<?php echo number_format($inspection_pct, 0); ?>%):</span>
                                <span>HK$<?php echo number_format($inspection_fee, 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">Total Inspection Payment:</span>
                            <span class="fw-bold">HK$<?php echo number_format($total_to_pay, 2); ?></span>
                        </div>
                        
                        <div class="payment-detail-item">
                            <span class="fw-bold">Amount to Pay:</span>
                            <span class="payment-amount fw-bold">HK$<?php echo number_format($total_to_pay, 2); ?></span>
                        </div>
                    </div>      
                </div>

                <hr>

                <h5>Payment Method</h5>
                <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                    <div class="mb-3">
                        <p>Using: <strong><?php echo htmlspecialchars($paymentMethodData['method']); ?></strong></p>
                        <p class="small text-muted">If you want to change the payment method, update it in your <a href="profile.php">profile</a>.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">No payment method configured. Please add one in your <a href="profile.php">profile</a> before proceeding.</div>
                <?php endif; ?>

                <form method="post">
                    <div class="d-flex gap-2">
                        <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                            <?php if ($payment_success): ?>
                                <a href="order_history.php" class="btn btn-secondary">Back to Project History</a>
                            <?php else: ?>
                                <button type="submit" name="proceed_pay" class="btn btn-success" 
                                        <?php echo $can_pay_inspection ? '' : 'disabled'; ?>
                                        onclick="return confirm('Confirm payment HK$<?php echo number_format($total_to_pay, 2); ?>?');">
                                    <i class="fas fa-credit-card me-1"></i>
                                    Pay HK$<?php echo number_format($total_to_pay, 2); ?>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-success" disabled>Proceed to Pay</button>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- Hidden form for reject action -->
                <form id="rejectForm" method="post" style="display: none;">
                    <input type="hidden" name="reject_payment" value="1">
                </form>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>