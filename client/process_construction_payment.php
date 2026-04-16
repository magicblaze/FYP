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
$sql = "SELECT o.*, op.total_cost, c.payment_method, c.cname, c.budget, op.payment_id
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

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Define milestone messages based on payment plan and installment index
function getMilestoneMessage($plan, $installment_index, $total_installments) {
    if ($plan === 'installment_25') {
        $percentages = [0, 25, 50, 75];
        $current_percent = $percentages[$installment_index];
        return "Installment " . ($installment_index + 1) . " of 4 - {$current_percent}% Complete";
    } elseif ($plan === 'installment_50') {
        $percentages = [0, 50];
        $current_percent = $percentages[$installment_index];
        return "Installment " . ($installment_index + 1) . " of 2 - {$current_percent}% Complete";
    } else {
        return "Full Construction Payment";
    }
}

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
            
            // Store installment payment record (if table exists)
            $installment_sql = "INSERT INTO ConstructionPaymentRecord 
                                (orderid, installment_number, percentage, amount, milestone, paid_at, status)
                                VALUES (?, ?, ?, ?, ?, NOW(), 'paid')";
            $installment_stmt = mysqli_prepare($mysqli, $installment_sql);
            if ($installment_stmt) {
                $current_percentage = $installments[$installment_index]['percentage'];
                $milestone = getMilestoneMessage($plan, $installment_index, count($installments));
                $installment_num = $installment_index + 1;
                mysqli_stmt_bind_param($installment_stmt, "iiids", $order_id, $installment_num, $current_percentage, $amount_to_pay, $milestone);
                mysqli_stmt_execute($installment_stmt);
                mysqli_stmt_close($installment_stmt);
            }
            
            // Update order status
            $order_update = "UPDATE `Order` SET ostatus = 'Construction begins' WHERE orderid = ?";
            $order_stmt = mysqli_prepare($mysqli, $order_update);
            mysqli_stmt_bind_param($order_stmt, "i", $order_id);
            mysqli_stmt_execute($order_stmt);
            mysqli_stmt_close($order_stmt);
            
            mysqli_commit($mysqli);
            
            // Check if this was the last installment
            $is_last = ($installment_index + 1 >= count($installments));
            
            if ($is_last) {
                // Clear session
                unset($_SESSION['construction_payment']);
                $payment_success = true;
                $message = "Payment successful! Your construction will begin as scheduled.";
            } else {
                // Update session for next installment
                $_SESSION['construction_payment']['installment_index'] = $installment_index + 1;
                $_SESSION['construction_payment']['amount'] = $installments[$installment_index + 1]['amount'];
                
                $next_milestone = getMilestoneMessage($plan, $installment_index + 1, count($installments));
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
$total_cost = floatval($order['total_cost']);
$total_paid = $current_total_paid;
$remaining_budget = floatval($order['budget']) - $total_paid;
$current_milestone = getMilestoneMessage($plan, $installment_index, count($installments));

// Determine progress steps based on payment plan
if ($plan === 'installment_25') {
    $total_installments = 4;
    $current_step = $installment_index + 1;
} elseif ($plan === 'installment_50') {
    $total_installments = 2;
    $current_step = $installment_index + 1;
} else {
    $total_installments = 1;
    $current_step = 1;
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
                                <?php 
                                if ($plan === 'installment_25') {
                                    if ($i == 1) echo 'Initial (0%)';
                                    elseif ($i == 2) echo '25% Complete';
                                    elseif ($i == 3) echo '50% Complete';
                                    else echo '75% Complete';
                                } elseif ($plan === 'installment_50') {
                                    if ($i == 1) echo 'Initial (0%)';
                                    else echo '50% Complete';
                                } else {
                                    echo 'Full Payment';
                                }
                                ?>
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
                                <span>Total Construction Cost:</span>
                                <span>HK$<?php echo number_format($total_cost, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 text-info">
                                <span>Already Paid:</span>
                                <span>- HK$<?php echo number_format($total_paid, 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">This Installment Amount:</span>
                            <span class="payment-amount fw-bold">HK$<?php echo number_format($amount_to_pay, 2); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($remaining_budget >= 0): ?>
                        <div class="budget-info">
                            <i class="fas fa-wallet me-2"></i>
                            Remaining Budget after this payment: <strong>HK$<?php echo number_format($remaining_budget - $amount_to_pay, 2); ?></strong>
                        </div>
                    <?php endif; ?>
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