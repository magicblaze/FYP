<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Only allow logged-in clients
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($current_page));
    exit;
}

$user = $_SESSION['user'];
$client_id = $user['clientid'];

$orderid = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;
if ($orderid <= 0) die('Order ID missing');

$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// Load order and ensure ownership
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, o.deposit, o.final_payment,
               d.expect_price as design_price, d.tag, 
               c.clientid, c.cname, c.payment_method
        FROM `Order` o
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        WHERE o.orderid = ? AND o.clientid = ? LIMIT 1";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "ii", $orderid, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);
if (!$order) {
    die('Order not found or access denied.');
}

// Get values
$final_payment = isset($order['final_payment']) ? floatval($order['final_payment']) : 0;
$design_deposit = isset($order['design_price']) ? (float)$order['design_price'] : 0.0;

// Gather references total
$refs_total = 0.0;
$refs_sql = "SELECT IFNULL(SUM(COALESCE(orr.price, p.price, 0)),0) as sum_refs FROM OrderReference orr LEFT JOIN Product p ON orr.productid = p.productid WHERE orr.orderid = ?";
$refs_stmt = mysqli_prepare($mysqli, $refs_sql);
if ($refs_stmt) {
    mysqli_stmt_bind_param($refs_stmt, "i", $orderid);
    mysqli_stmt_execute($refs_stmt);
    $refs_row = mysqli_stmt_get_result($refs_stmt)->fetch_assoc();
    $refs_total = isset($refs_row['sum_refs']) ? (float)$refs_row['sum_refs'] : 0.0;
    mysqli_stmt_close($refs_stmt);
}

// Gather fees total
$fees_total = 0.0;
$fees_sql = "SELECT IFNULL(SUM(amount),0) as sum_fees FROM AdditionalFee WHERE orderid = ?";
$fees_stmt = mysqli_prepare($mysqli, $fees_sql);
if ($fees_stmt) {
    mysqli_stmt_bind_param($fees_stmt, "i", $orderid);
    mysqli_stmt_execute($fees_stmt);
    $fees_row = mysqli_stmt_get_result($fees_stmt)->fetch_assoc();
    $fees_total = isset($fees_row['sum_fees']) ? (float)$fees_row['sum_fees'] : 0.0;
    mysqli_stmt_close($fees_stmt);
}

// Calculate totals
$order_total = $design_deposit + $refs_total + $fees_total + $final_payment;

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Payment status
$deposit_paid = isset($_SESSION['deposit_paid_' . $orderid]) ? $_SESSION['deposit_paid_' . $orderid] : false;
$final_paid = isset($_SESSION['final_paid_' . $orderid]) ? $_SESSION['final_paid_' . $orderid] : false;
$payment_rejected = isset($_SESSION['payment_rejected_' . $orderid]) ? $_SESSION['payment_rejected_' . $orderid] : false;

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reject_payment'])) {
        $_SESSION['payment_rejected_' . $orderid] = true;
        $payment_rejected = true;
        header('Location: payment_final.php?orderid=' . $orderid . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        $_SESSION['final_paid_' . $orderid] = true;
        // 停留在当前页面，显示成功消息
        header('Location: payment_final.php?orderid=' . $orderid . '&amount=' . $amount . '&success=1');
        exit;
    }
}

// Current step (step 2 for final payment page)
$current_step = 2;
if ($final_paid) $current_step = 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Final Payment - Order #<?php echo $orderid; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .total-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .payment-section {
            background: #e8f5e9;
            border-left: 4px solid #27ae60;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .total-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .total-item:last-child {
            border-bottom: none;
        }
        .total-label {
            font-weight: 500;
            color: #2c3e50;
        }
        .total-value {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .payment-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #e74c3c;
        }
        .alert-message {
            margin-top: 1rem;
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
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            flex: 1;
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
            font-size: 0.85rem;
            font-weight: 500;
            text-align: center;
            color: #6c757d;
        }
        
        .step.completed .step-label {
            color: #28a745;
        }
        
        .step.current .step-label {
            color: #ffc107;
            font-weight: 600;
        }
        
        .step.rejected .step-label {
            color: #dc3545;
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
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            background-color: #27ae60;
            color: white;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <div class="payment-type-badge">
                    <i class="fas fa-check-circle me-1"></i> Final Payment
                </div>
                
                <h4>Payment for Order #<?php echo $orderid; ?></h4>
                
                <!-- 成功消息 -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>Final payment completed successfully!
                    </div>
                <?php endif; ?>
                
                <!-- 拒绝消息 -->
                <?php if (isset($_GET['rejected'])): ?>
                    <div class="alert alert-danger alert-message">
                        <i class="fas fa-exclamation-triangle me-2"></i>Payment rejected. You may contact support for further assistance.
                    </div>
                <?php endif; ?>

                <!-- Progress Steps -->
                <div class="progress-steps">
                    <!-- Step 1: Deposit - Completed (Green) -->
                    <div class="step completed">
                        <div class="step-circle">
                            <i class="fas fa-check"></i>
                        </div>
                        <span class="step-label">Deposit</span>
                    </div>
                    <div class="step-connector completed"></div>
                    
                    <!-- Step 2: Final Payment - Current (Yellow) or Completed (Green) -->
                    <div class="step <?php 
                        if ($payment_rejected) echo 'rejected';
                        elseif ($final_paid) echo 'completed';
                        else echo 'current';
                    ?>">
                        <div class="step-circle">
                            <?php if ($final_paid): ?>
                                <i class="fas fa-check"></i>
                            <?php elseif ($payment_rejected): ?>
                                <i class="fas fa-times"></i>
                            <?php else: ?>
                                2
                            <?php endif; ?>
                        </div>
                        <span class="step-label">Final Payment</span>
                    </div>
                    <div class="step-connector <?php echo ($final_paid) ? 'completed' : ''; ?>"></div>
                    
                    <!-- Step 3: Construction Fee - Future (Grey) or Current if final paid -->
                    <div class="step <?php echo ($final_paid) ? 'current' : 'future'; ?>">
                        <div class="step-circle">3</div>
                        <span class="step-label">Construction Fee</span>
                    </div>
                    <div class="step-connector"></div>
                    
                    <!-- Step 4: Complete - Future (Grey) -->
                    <div class="step future">
                        <div class="step-circle">4</div>
                        <span class="step-label">Complete</span>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="total-section">
                    <h5 class="mb-3"><i class="fas fa-shopping-cart me-2"></i>Order Summary</h5>
                    
                    <div class="total-item">
                        <span class="total-label">Design deposit:</span>
                        <span class="total-value">HK$<?php echo number_format($design_deposit, 2); ?></span>
                    </div>
                    
                    <?php if ($refs_total > 0): ?>
                        <div class="total-item">
                            <span class="total-label">Product References:</span>
                            <span class="total-value">HK$<?php echo number_format($refs_total, 2); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($fees_total > 0): ?>
                        <div class="total-item">
                            <span class="total-label">Additional Fees:</span>
                            <span class="total-value">HK$<?php echo number_format($fees_total, 2); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="total-item">
                        <span class="total-label">Final Design payment:</span>
                        <span class="total-value">HK$<?php echo number_format($final_payment, 2); ?></span>
                    </div>
                    
                    <div class="total-item" style="border-top: 2px solid #3498db; margin-top: 0.5rem; padding-top: 0.75rem;">
                        <span class="total-label"><strong>Order Total:</strong></span>
                        <span class="total-value"><strong>HK$<?php echo number_format($order_total, 2); ?></strong></span>
                    </div>
                </div>

                <!-- Final Payment Section -->
                <div class="payment-section">
                    <h5 class="mb-3"><i class="fas fa-check-circle me-2"></i>Final Payment</h5>
                    
                    <div class="total-item">
                        <span class="total-label">Final Design Payment:</span>
                        <span class="total-value">HK$<?php echo number_format($final_payment, 2); ?></span>
                    </div>
                    
                    <?php if ($refs_total > 0): ?>
                        <div class="total-item">
                            <span class="total-label">Product References:</span>
                            <span class="total-value">HK$<?php echo number_format($refs_total, 2); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="total-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 0.75rem;">
                        <span class="total-label"><strong>Total to Pay:</strong></span>
                        <span class="total-value payment-amount"><strong>HK$<?php echo number_format($amount, 2); ?></strong></span>
                    </div>
                    
                    <?php if ($final_paid): ?>
                        <div class="alert alert-success mt-3">
                            <i class="fas fa-check-circle me-2"></i>Final payment completed successfully!
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
                            <?php if ($final_paid): ?>
                                <button type="button" class="btn btn-success" disabled>
                                    <i class="fas fa-check-circle me-1"></i>Final Payment Completed
                                </button>
                            <?php else: ?>
                                <button type="submit" name="proceed_pay" class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>
                                    Pay Final HK$<?php echo number_format($amount, 2); ?>
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