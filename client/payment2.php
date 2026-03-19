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
if ($orderid <= 0) die('Order ID missing');

$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// Load order and payment data
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, o.deposit,
               d.expect_price as design_price, d.tag,
               c.clientid, c.cname, c.payment_method, c.budget,
               op.design_fee_designer_2nd, op.design_fee_manager_1st,
               op.total_amount_due, op.total_amount_paid, op.payment_status
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
    die('Order not found or access denied.');
}

// Get payment values from OrderPayment
$design_fee_designer_2nd = isset($order['design_fee_designer_2nd']) ? floatval($order['design_fee_designer_2nd']) : 0;
$design_fee_manager_1st = isset($order['design_fee_manager_1st']) ? floatval($order['design_fee_manager_1st']) : 0;
$total_design_fees = $design_fee_designer_2nd + $design_fee_manager_1st;
$total_amount_due = isset($order['total_amount_due']) ? floatval($order['total_amount_due']) : 0;
$total_amount_paid = isset($order['total_amount_paid']) ? floatval($order['total_amount_paid']) : 0;

$current_budget = isset($order['budget']) ? floatval($order['budget']) : 0;

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Payment status - 从 session 或 URL 参数获取
$payment_success = isset($_GET['success']) ? true : (isset($_SESSION['payment_success_' . $orderid]) ? $_SESSION['payment_success_' . $orderid] : false);
$payment_rejected = isset($_GET['rejected']) ? true : (isset($_SESSION['payment_rejected_' . $orderid]) ? $_SESSION['payment_rejected_' . $orderid] : false);

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reject_payment'])) {
        $_SESSION['payment_rejected_' . $orderid] = true;
        $payment_rejected = true;
        header('Location: payment2.php?orderid=' . $orderid . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        // Begin transaction
        mysqli_begin_transaction($mysqli);
        
        try {
            // DO NOT update order status - keep original status
            
            // Update OrderPayment
            $op_sql = "UPDATE OrderPayment SET 
                       total_amount_paid = total_amount_paid + ?,
                       payment_status = CASE 
                           WHEN total_amount_paid + ? >= total_amount_due THEN 'settled'
                           ELSE 'partial_paid'
                       END,
                       last_payment_date = NOW()
                       WHERE payment_id = (SELECT payment_id FROM `Order` WHERE orderid = ?)";
            $op_stmt = mysqli_prepare($mysqli, $op_sql);
            mysqli_stmt_bind_param($op_stmt, "ddi", $total_design_fees, $total_design_fees, $orderid);
            mysqli_stmt_execute($op_stmt);
            mysqli_stmt_close($op_stmt);
            
            // Deduct from client budget
            $new_budget = $current_budget - $total_design_fees;
            $b_sql = "UPDATE Client SET budget = ? WHERE clientid = ?";
            $b_stmt = mysqli_prepare($mysqli, $b_sql);
            mysqli_stmt_bind_param($b_stmt, "di", $new_budget, $client_id);
            mysqli_stmt_execute($b_stmt);
            mysqli_stmt_close($b_stmt);
            
            // Update session budget
            $_SESSION['user']['budget'] = $new_budget;
            
            mysqli_commit($mysqli);
            
            // Set session flag for successful payment
            $_SESSION['payment2_success_' . $orderid] = true;
            // Redirect to same page with success parameter (like payment_final.php)
            header('Location: payment2.php?orderid=' . $orderid . '&amount=' . $total_design_fees . '&success=1');
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            error_log("Payment error: " . $e->getMessage());
            die("Payment processing failed. Please try again.");
        }
    }
}

// Force payment stage to be manager_1st for the 2nd Payment
$payment_stage = 'manager_1st';

$stage_titles = [
    'deposit' => 'Project Deposit',
    'manager_1st' => '2nd Payment',
    'designer_2nd' => 'Designer Fee',
    'total_design_fees' => 'Total Design Fees',
    'final_design' => 'Final Design Payment',
    'construction_deposit' => 'Construction Deposit',
    'construction_final' => 'Final Construction Payment'
];

$stage_title = '2nd Payment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>2nd Payment - Order #<?php echo $orderid; ?></title>
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
                    <i class="fas fa-credit-card me-1"></i> 2nd Payment
                </div>
                
                <h4>Payment for Order #<?php echo $orderid; ?></h4>
                
                <!-- Budget Info -->
                <div class="budget-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-wallet me-2"></i>Your Current Budget:</span>
                        <strong class="text-primary">HK$<?php echo number_format($current_budget, 2); ?></strong>
                    </div>
                    <?php if ($total_design_fees > $current_budget && !$payment_success): ?>
                        <div class="alert alert-danger mt-2 mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Insufficient budget! You need HK$<?php echo number_format($total_design_fees - $current_budget, 2); ?> more.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Success Message - 像 payment_final.php 一样 -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>
                        2nd Payment completed successfully! HK$<?php echo number_format($total_design_fees, 2); ?> deducted from your budget.
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
                    
                    <!-- Stage 2: 2nd Payment - 根据支付状态显示不同样式 -->
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
                                2
                            <?php endif; ?>
                        </div>
                        <span class="step-label">2nd Payment</span>
                    </div>
                    <div class="step-connector <?php echo ($payment_success) ? 'completed' : ''; ?>"></div>
                    
                    <!-- Stage 3: Final Design - Future -->
                    <div class="step future">
                        <div class="step-circle">3</div>
                        <span class="step-label">Final Design</span>
                    </div>
                    <div class="step-connector"></div>
                    
                    <!-- Stage 4: Const. Deposit - Future -->
                    <div class="step future">
                        <div class="step-circle">4</div>
                        <span class="step-label">Const. Deposit</span>
                    </div>
                    <div class="step-connector"></div>
                    
                    <!-- Stage 5: Final Const. - Future -->
                    <div class="step future">
                        <div class="step-circle">5</div>
                        <span class="step-label">Final Const.</span>
                    </div>
                    <div class="step-connector"></div>
                    
                    <!-- Stage 6: Complete - Future -->
                    <div class="step future">
                        <div class="step-circle">6</div>
                        <span class="step-label">Complete</span>
                    </div>
                </div>

                <!-- Payment Section -->
                <div class="payment-section">
                    <h5 class="mb-3"><i class="fas fa-check-circle me-2"></i>2nd Payment</h5>
                    
                    <div class="payment-detail">
                        <div class="payment-detail-item">
                            <span>Order #<?php echo $orderid; ?></span>
                            <span><?php echo date('Y-m-d', strtotime($order['odate'])); ?></span>
                        </div>
                        
                        <!-- Show breakdown of both fees -->
                        <div class="fee-breakdown">
                            <div class="d-flex justify-content-between mb-1">
                                <span>1st Design Fee (Manager 2.5%):</span>
                                <span>HK$<?php echo number_format($design_fee_manager_1st, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>2nd Design Fee (Designer 2.5%):</span>
                                <span>HK$<?php echo number_format($design_fee_designer_2nd, 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">Total Design Fees:</span>
                            <span class="fw-bold">HK$<?php echo number_format($total_design_fees, 2); ?></span>
                        </div>
                        
                        <div class="payment-detail-item">
                            <span class="fw-bold">Amount to Pay:</span>
                            <span class="payment-amount fw-bold">HK$<?php echo number_format($total_design_fees, 2); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($payment_success): ?>
                        <div class="alert alert-success mt-3">
                            <i class="fas fa-check-circle me-2"></i>
                            2nd Payment completed successfully! HK$<?php echo number_format($total_design_fees, 2); ?> deducted from your budget.
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
                        <a href="order_history.php" class="btn btn-secondary">Back to Order History</a>
                        
                        <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                            <?php if ($payment_success): ?>
                               
                                <button type="button" class="btn btn-success" disabled>
                                    <i class="fas fa-check-circle me-1"></i>2nd Payment Completed
                                </button>
                            <?php elseif ($total_design_fees > $current_budget): ?>
                                <button type="button" class="btn btn-success" disabled title="Insufficient budget">
                                    <i class="fas fa-exclamation-triangle me-1"></i>Insufficient Budget
                                </button>
                            <?php else: ?>
                                <button type="submit" name="proceed_pay" class="btn btn-success" 
                                        onclick="return confirm('Pay HK$<?php echo number_format($total_design_fees, 2); ?> from your budget? Your remaining budget will be HK$<?php echo number_format($current_budget - $total_design_fees, 2); ?>');">
                                    <i class="fas fa-credit-card me-1"></i>
                                    Pay HK$<?php echo number_format($total_design_fees, 2); ?>
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