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

// Get final payment value
$final_payment = isset($order['final_payment']) ? floatval($order['final_payment']) : 0;

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

$design_deposit = isset($order['design_price']) ? (float)$order['design_price'] : 0.0;

// --- Calculate totals ---
$order_total = $design_deposit + $refs_total + $fees_total + $final_payment;
$construction_fee = $order_total * 1.5; // 150% of order total
$grand_total = $order_total + $construction_fee;

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Handle construction fee acceptance/rejection
$construction_accepted = isset($_SESSION['construction_accepted_' . $orderid]) ? $_SESSION['construction_accepted_' . $orderid] : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_construction'])) {
        $_SESSION['construction_accepted_' . $orderid] = true;
        $construction_accepted = true;
        header('Location: payment.php?orderid=' . $orderid . '&accepted=1');
        exit;
    }
    
    if (isset($_POST['reject_construction'])) {
        $_SESSION['construction_accepted_' . $orderid] = false;
        $construction_accepted = false;
        header('Location: payment.php?orderid=' . $orderid . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        // In a real integration, you would call the payment provider here.
        // For now mark order as 'complete' to match the DB enum and simulate payment success.
        $u_sql = "UPDATE `Order` SET ostatus = 'complete' WHERE orderid = ? AND clientid = ?";
        $u_stmt = mysqli_prepare($mysqli, $u_sql);
        mysqli_stmt_bind_param($u_stmt, "ii", $orderid, $client_id);
        mysqli_stmt_execute($u_stmt);
        mysqli_stmt_close($u_stmt);

        // Clear session
        unset($_SESSION['construction_accepted_' . $orderid]);

        // Redirect user to order history so they can see the updated status
        header('Location: order_history.php?msg=paid');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Proceed to Payment - Order #<?php echo $orderid; ?></title>
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
        .construction-section {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .grand-total-section {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
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
        .construction-value {
            color: #e67e22;
        }
        .grand-total-value {
            color: #27ae60;
            font-size: 1.3rem;
        }
        .alert-message {
            margin-top: 1rem;
        }
        .payment-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #e74c3c;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <h4>Payment for Order #<?php echo $orderid; ?></h4>
                
                <?php if (isset($_GET['accepted'])): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>Construction fee accepted. Please proceed with payment.
                    </div>
                <?php elseif (isset($_GET['rejected'])): ?>
                    <div class="alert alert-warning alert-message">
                        <i class="fas fa-exclamation-triangle me-2"></i>Construction fee rejected. You may contact support for further assistance.
                    </div>
                <?php endif; ?>

                <!-- Order Total Section -->
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

                <!-- Construction Fee Section -->
                <div class="construction-section">
                    <h5 class="mb-3"><i class="fas fa-hard-hat me-2"></i>Construction Fee (150% of Order Total)</h5>
                    
                    <div class="total-item">
                        <span class="total-label">Order Total:</span>
                        <span class="total-value">HK$<?php echo number_format($order_total, 2); ?></span>
                    </div>
                    
                    <div class="total-item">
                        <span class="total-label">Construction Fee (150%):</span>
                        <span class="total-value construction-value"><strong>HK$<?php echo number_format($construction_fee, 2); ?></strong></span>
                    </div>
                    
                    <?php if (!$construction_accepted): ?>
                        <div class="mt-3 d-flex gap-2">
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="accept_construction" value="1">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Accept the construction fee of HK$<?php echo number_format($construction_fee, 2); ?>?');">
                                    <i class="fas fa-check me-1"></i>Accept Construction Fee
                                </button>
                            </form>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="reject_construction" value="1">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Reject the construction fee? You may need to contact support.');">
                                    <i class="fas fa-times me-1"></i>Reject Construction Fee
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Grand Total Section (shown only if construction accepted) -->
                <?php if ($construction_accepted): ?>
                    <div class="grand-total-section">
                        <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>Payment Summary</h5>
                        
                        <div class="total-item">
                            <span class="total-label">Order Total(Pay off):</span>
                            <span class="total-value">HK$<?php echo number_format($order_total, 2); ?></span>
                        </div>
                        
                        <div class="total-item">
                            <span class="total-label">Construction Fee (150%):</span>
                            <span class="total-value">HK$<?php echo number_format($construction_fee, 2); ?></span>
                        </div>
                        
                        <div class="total-item" style="border-top: 2px solid #4caf50; margin-top: 0.5rem; padding-top: 0.75rem;">
                            <span class="total-label"><strong>Total need to Pay:</strong></span>
                            <span class="total-value payment-amount"><strong>HK$<?php echo number_format($construction_fee, 2); ?></strong></span>
                        </div>
                        
                        <p class="text-muted small mt-2 mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            You only need to pay the construction fee of HK$<?php echo number_format($construction_fee, 2); ?>.
                        </p>
                    </div>
                <?php endif; ?>

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
                    <input type="hidden" name="proceed_pay" value="1">
                    <div class="d-flex gap-2">
                        <a href="order_detail.php?orderid=<?php echo $orderid; ?>" class="btn btn-secondary">Cancel</a>
                        
                        <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                            <?php if ($construction_accepted): ?>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>
                                    Pay Construction Fee HK$<?php echo number_format($construction_fee, 2); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary" disabled>
                                    Please accept construction fee first
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-success" disabled>Proceed to Pay</button>
                        <?php endif; ?>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>