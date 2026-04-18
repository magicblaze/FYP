<?php
// client/construction_payment_installment.php
// Handles milestone installment payments for construction
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$client_id = $user['clientid'];

$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;
$milestone = isset($_GET['milestone']) ? $_GET['milestone'] : '';
$percentage = isset($_GET['percentage']) ? intval($_GET['percentage']) : 0;
$record_id = isset($_GET['record_id']) ? intval($_GET['record_id']) : 0;

if ($order_id <= 0 || $amount <= 0) {
    header('Location: order_history.php');
    exit;
}

// Load order and payment data from OrderPayment
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, o.deposit, o.final_payment,
               d.expect_price as design_price, d.tag,
               c.clientid, c.cname, c.payment_method, c.budget,
           op.total_cost,
               o.payment_plan
        FROM `Order` o
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `OrderPayment` op ON o.payment_id = op.payment_id
        WHERE o.orderid = ? AND o.clientid = ? LIMIT 1";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "ii", $order_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);
if (!$order) {
    die('Project not found or access denied.');
}

$total_cost = floatval($order['total_cost'] ?? 0);
$total_paid_sql = "SELECT IFNULL(SUM(amount), 0) AS total_paid FROM ConstructionPaymentRecord WHERE orderid = ? AND status = 'paid'";
$total_paid_stmt = mysqli_prepare($mysqli, $total_paid_sql);
mysqli_stmt_bind_param($total_paid_stmt, "i", $order_id);
mysqli_stmt_execute($total_paid_stmt);
$total_paid_result = mysqli_stmt_get_result($total_paid_stmt);
$total_paid_row = mysqli_fetch_assoc($total_paid_result);
$total_paid = floatval($total_paid_row['total_paid'] ?? 0);
mysqli_stmt_close($total_paid_stmt);
$payment_plan = $order['payment_plan'] ?? 'full';
$current_budget = floatval($order['budget'] ?? 0);

// Calculate remaining amount after this payment
$remaining_after = $total_cost - ($total_paid + $amount);
$remaining_budget = $current_budget - ($total_paid + $amount);

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Get the pending payment record
$pending_sql = "SELECT * FROM ConstructionPaymentRecord 
                WHERE record_id = ? AND status = 'pending'";
$pending_stmt = mysqli_prepare($mysqli, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, "i", $record_id);
mysqli_stmt_execute($pending_stmt);
$pending_result = mysqli_stmt_get_result($pending_stmt);
$pending_record = mysqli_fetch_assoc($pending_result);
mysqli_stmt_close($pending_stmt);

if (!$pending_record) {
    // If no pending record found by record_id, try by orderid and percentage
    $pending_sql2 = "SELECT * FROM ConstructionPaymentRecord 
                     WHERE orderid = ? AND percentage = ? AND status = 'pending'
                     ORDER BY record_id ASC LIMIT 1";
    $pending_stmt2 = mysqli_prepare($mysqli, $pending_sql2);
    mysqli_stmt_bind_param($pending_stmt2, "ii", $order_id, $percentage);
    mysqli_stmt_execute($pending_stmt2);
    $pending_result2 = mysqli_stmt_get_result($pending_stmt2);
    $pending_record = mysqli_fetch_assoc($pending_result2);
    mysqli_stmt_close($pending_stmt2);
    
    if (!$pending_record) {
        header('Location: view_weekly_reports.php?orderid=' . $order_id);
        exit;
    }
    $record_id = $pending_record['record_id'];
    // Update percentage from database record if provided
    if (isset($pending_record['percentage'])) {
        $percentage = intval($pending_record['percentage']);
    }
}

// Payment success flag
$payment_success = isset($_GET['success']) ? true : false;
$payment_rejected = isset($_GET['rejected']) ? true : false;

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reject_payment'])) {
        $_SESSION['payment_rejected_' . $order_id] = true;
        $payment_rejected = true;
        header('Location: construction_payment_installment.php?orderid=' . $order_id . '&amount=' . $amount . '&milestone=' . urlencode($milestone) . '&percentage=' . $percentage . '&record_id=' . $record_id . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        if (empty($paymentMethodData) || empty($paymentMethodData['method'])) {
            $error = 'Please configure a payment method in your profile first.';
        } else {
            mysqli_begin_transaction($mysqli);
            
            try {
                // Update payment record status to paid
                $update_record_sql = "UPDATE ConstructionPaymentRecord 
                                      SET paid_at = NOW(), status = 'paid'
                                      WHERE record_id = ?";
                $update_record_stmt = mysqli_prepare($mysqli, $update_record_sql);
                mysqli_stmt_bind_param($update_record_stmt, "i", $record_id);
                mysqli_stmt_execute($update_record_stmt);
                mysqli_stmt_close($update_record_stmt);
                
                // Check if there are more pending payments for other milestones
                $check_pending_sql = "SELECT COUNT(*) as pending_count FROM ConstructionPaymentRecord 
                                      WHERE orderid = ? AND status = 'pending'";
                $check_pending_stmt = mysqli_prepare($mysqli, $check_pending_sql);
                mysqli_stmt_bind_param($check_pending_stmt, "i", $order_id);
                mysqli_stmt_execute($check_pending_stmt);
                $check_pending_result = mysqli_stmt_get_result($check_pending_stmt);
                $check_pending_row = mysqli_fetch_assoc($check_pending_result);
                $has_more_pending = ($check_pending_row['pending_count'] > 0);
                mysqli_stmt_close($check_pending_stmt);
                
                // Update order status based on remaining payments
                if (!$has_more_pending) {
                    $order_update_sql = "UPDATE `Order` SET ostatus = 'In construction' WHERE orderid = ?";
                } else {
                    $order_update_sql = "UPDATE `Order` SET ostatus = 'Waiting for construction payment' WHERE orderid = ?";
                }
                $order_update_stmt = mysqli_prepare($mysqli, $order_update_sql);
                mysqli_stmt_bind_param($order_update_stmt, "i", $order_id);
                mysqli_stmt_execute($order_update_stmt);
                mysqli_stmt_close($order_update_stmt);
                
                mysqli_commit($mysqli);
                
                // Set session flag for successful payment
                $_SESSION['payment_milestone_success_' . $order_id] = true;
                
                // Redirect to same page with success parameter
                header('Location: construction_payment_installment.php?orderid=' . $order_id . '&amount=' . $amount . '&milestone=' . urlencode($milestone) . '&percentage=' . $percentage . '&record_id=' . $record_id . '&success=1');
                exit;
                
            } catch (Exception $e) {
                mysqli_rollback($mysqli);
                error_log("Payment error: " . $e->getMessage());
                $error = "Payment processing failed. Please try again.";
            }
        }
    }
}

// ============================================
// FIXED: Get milestone display name based on actual percentage
// ============================================
// First try to get from passed parameter, then from milestone string
$milestone_display = '';

if ($percentage > 0) {
    // Based on actual percentage value
    if ($percentage == 25) {
        $milestone_display = '25% Milestone Payment (0-25% Completion)';
    } elseif ($percentage == 50) {
        $milestone_display = '50% Milestone Payment (25-50% Completion)';
    } elseif ($percentage == 75) {
        $milestone_display = '75% Milestone Payment (50-75% Completion)';
    } elseif ($percentage == 100) {
        $milestone_display = '100% Final Payment (75-100% Completion)';
    } else {
        // Fallback to milestone string if percentage is not standard
        $milestone_display = $milestone;
    }
} else {
    // If no percentage, try to parse from milestone string
    $milestone_display = $milestone;
}

// If still empty, use a default based on payment plan and amount
if (empty($milestone_display)) {
    if ($payment_plan == 'installment_25') {
        // For 25% installment plan, determine which payment this is
        if ($amount == $total_cost * 0.25) {
            $milestone_display = '25% Milestone Payment (Deposit)';
        } elseif ($amount == $total_cost * 0.5) {
            $milestone_display = '50% Milestone Payment';
        } elseif ($amount == $total_cost * 0.75) {
            $milestone_display = '75% Milestone Payment';
        } else {
            $milestone_display = 'Final Payment';
        }
    } elseif ($payment_plan == 'installment_50') {
        if ($amount == $total_cost * 0.5) {
            $milestone_display = '50% Milestone Payment';
        } else {
            $milestone_display = 'Final Payment';
        }
    } else {
        $milestone_display = 'Full Payment';
    }
}

// Calculate progress steps based on payment plan
if ($payment_plan == 'installment_25') {
    $total_installments = 4;
    if ($percentage == 25) $current_step = 1;
    elseif ($percentage == 50) $current_step = 2;
    elseif ($percentage == 75) $current_step = 3;
    elseif ($percentage == 100) $current_step = 4;
    else $current_step = 1;
    
    $step_labels = ['25% Payment', '50% Payment', '75% Payment', '100% Final Payment'];
} elseif ($payment_plan == 'installment_50') {
    $total_installments = 2;
    if ($percentage == 50) $current_step = 1;
    elseif ($percentage == 100) $current_step = 2;
    else $current_step = 1;
    
    $step_labels = ['50% Payment', 'Final Payment'];
} else {
    $total_installments = 1;
    $current_step = 1;
    $step_labels = ['Full Payment'];
}

// For the confirmation message, generate a clear description
$confirm_message = "Confirm payment HK$" . number_format($amount, 2) . " for " . $milestone_display . "?";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Construction Milestone Payment - Project #<?php echo $order_id; ?></title>
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
            background-color: #e67e22;
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
        
        .negative-amount {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <div class="payment-type-badge">
                    <i class="fas fa-hard-hat me-1"></i> Construction Milestone Payment
                </div>
                
                <h4>Payment for Project #<?php echo $order_id; ?></h4>
                
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
                                <?php elseif ($i == $current_step): ?>
                                    <?php echo $i; ?>
                                <?php else: ?>
                                    <?php echo $i; ?>
                                <?php endif; ?>
                            </div>
                            <span class="step-label"><?php echo $step_labels[$i-1]; ?></span>
                        </div>
                        <?php if ($i < $total_installments): ?>
                            <div class="step-connector <?php echo ($i < $current_step) ? 'completed' : ''; ?>"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>

                <!-- Payment Section -->
                <div class="payment-section">
                    <h5 class="mb-3"><i class="fas fa-credit-card me-2"></i>Milestone Payment</h5>
                    
                    <div class="payment-detail">
                        <div class="payment-detail-item">
                            <span>Project #<?php echo $order_id; ?></span>
                            <span><?php echo date('Y-m-d', strtotime($order['odate'])); ?></span>
                        </div>
                        
                        <!-- Show breakdown of fees -->
                        <div class="fee-breakdown">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Total Construction Cost:</span>
                                <span>HK$<?php echo number_format($total_cost, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 text-info">
                                <span>Already Paid:</span>
                                <span>- HK$<?php echo number_format($total_paid, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 text-warning">
                                <span>This Milestone Amount:</span>
                                <span>HK$<?php echo number_format($amount, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Remaining After Payment:</span>
                                <?php if ($remaining_after < 0): ?>
                                    <span class="negative-amount">HK$<?php echo number_format($remaining_after, 2); ?></span>
                                <?php else: ?>
                                    <span>HK$<?php echo number_format($remaining_after, 2); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #e67e22; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">Amount to Pay:</span>
                            <span class="payment-amount fw-bold">HK$<?php echo number_format($amount, 2); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($remaining_budget >= 0): ?>
                        <div class="budget-info">
                            <i class="fas fa-wallet me-2"></i>
                            Remaining Budget after this payment: <strong>HK$<?php echo number_format($remaining_budget, 2); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="budget-info" style="background: #fff3cd; border-left-color: #ffc107;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Budget Alert:</strong> This payment exceeds your remaining budget by <strong>HK$<?php echo number_format(abs($remaining_budget), 2); ?></strong>
                        </div>
                    <?php endif; ?>
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
                            <?php if (isset($_GET['success'])): ?>
                                <a href="view_weekly_reports.php?orderid=<?php echo $order_id; ?>" class="btn btn-secondary">Back to Reports</a>
                            <?php else: ?>
                                <button type="submit" name="proceed_pay" class="btn btn-success" 
                                        onclick="return confirm('<?php echo addslashes($confirm_message); ?>');">
                                    <i class="fas fa-credit-card me-1"></i>
                                    Pay HK$<?php echo number_format($amount, 2); ?>
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