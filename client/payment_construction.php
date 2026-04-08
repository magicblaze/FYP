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
               op.design_fee_designer_1st, op.design_fee_designer_2nd,
               op.design_fee_manager_1st, op.design_fee_manager_2nd,
               op.commission_1st, op.commission_final,
               op.construction_main_price, op.construction_deposit,
               op.materials_cost, op.inspection_fee, op.contractor_fee,
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
    die('Project not found or access denied.');
}

// Get payment values from OrderPayment
$construction_deposit = isset($order['construction_deposit']) ? floatval($order['construction_deposit']) : 0;
$materials_cost_allocated = isset($order['materials_cost']) ? floatval($order['materials_cost']) : 0;
$design_fee_manager_1st = isset($order['design_fee_manager_1st']) ? floatval($order['design_fee_manager_1st']) : 0;
$design_fee_designer_2nd = isset($order['design_fee_designer_2nd']) ? floatval($order['design_fee_designer_2nd']) : 0;
$design_fee_manager_2nd = isset($order['design_fee_manager_2nd']) ? floatval($order['design_fee_manager_2nd']) : 0;
$commission_1st = isset($order['commission_1st']) ? floatval($order['commission_1st']) : 0;
$commission_final = isset($order['commission_final']) ? floatval($order['commission_final']) : 0;
$construction_main_price = isset($order['construction_main_price']) ? floatval($order['construction_main_price']) : 0;
$inspection_fee = isset($order['inspection_fee']) ? floatval($order['inspection_fee']) : 0;
$contractor_fee = isset($order['contractor_fee']) ? floatval($order['contractor_fee']) : 0;
$total_amount_due = isset($order['total_amount_due']) ? floatval($order['total_amount_due']) : 0;
$total_amount_paid = isset($order['total_amount_paid']) ? floatval($order['total_amount_paid']) : 0;
$design_price = isset($order['design_price']) ? floatval($order['design_price']) : 0;
$current_budget = isset($order['budget']) ? floatval($order['budget']) : 0;

// Calculate actual material cost from confirmed order references
$actual_materials_cost = 0.0;
$materials_sql = "SELECT IFNULL(SUM(COALESCE(orr.price, p.price, 0)), 0) AS material_total
                                    FROM `OrderReference` orr
                                    LEFT JOIN `Product` p ON orr.productid = p.productid
                                    WHERE orr.orderid = ?
                                        AND (orr.status IS NULL OR LOWER(orr.status) <> 'rejected')";
$materials_stmt = mysqli_prepare($mysqli, $materials_sql);
if ($materials_stmt) {
        mysqli_stmt_bind_param($materials_stmt, "i", $orderid);
        mysqli_stmt_execute($materials_stmt);
        $materials_result = mysqli_stmt_get_result($materials_stmt);
        $materials_row = mysqli_fetch_assoc($materials_result);
        $actual_materials_cost = isset($materials_row['material_total']) ? (float) $materials_row['material_total'] : 0.0;
        mysqli_stmt_close($materials_stmt);
}

// Calculate total for this payment stage (Construction Deposit + Actual Materials Cost)
$total_to_pay = $construction_deposit + $actual_materials_cost;

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

// Payment status - from session or URL
$payment_success = isset($_GET['success']) ? true : (isset($_SESSION['payment_success_' . $orderid]) ? $_SESSION['payment_success_' . $orderid] : false);
$payment_rejected = isset($_GET['rejected']) ? true : (isset($_SESSION['payment_rejected_' . $orderid]) ? $_SESSION['payment_rejected_' . $orderid] : false);

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reject_payment'])) {
        $_SESSION['payment_rejected_' . $orderid] = true;
        $payment_rejected = true;
        header('Location: payment_construction.php?orderid=' . $orderid . '&rejected=1');
        exit;
    }
    
    if (isset($_POST['proceed_pay'])) {
        // Begin transaction
        mysqli_begin_transaction($mysqli);
        
        try {
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
            mysqli_stmt_bind_param($op_stmt, "ddi", $total_to_pay, $total_to_pay, $orderid);
            mysqli_stmt_execute($op_stmt);
            mysqli_stmt_close($op_stmt);
            
            // Update order status to Coordinating Contractors (like original payment_construction.php)
            $u_sql = "UPDATE `Order` SET ostatus = 'Coordinating Contractors' WHERE orderid = ? AND clientid = ?";
            $u_stmt = mysqli_prepare($mysqli, $u_sql);
            mysqli_stmt_bind_param($u_stmt, "ii", $orderid, $client_id);
            mysqli_stmt_execute($u_stmt);
            mysqli_stmt_close($u_stmt);
            
            mysqli_commit($mysqli);
            
            // Set session flag for successful payment
            $payment_success = isset($_GET['success']) ? true : (isset($_SESSION['payment_construction_success_' . $orderid]) ? $_SESSION['payment_construction_success_' . $orderid] : false);

            // 修改设置成功时的代码
            $_SESSION['payment_construction_success_' . $orderid] = true;
            
            // Redirect to same page with success parameter
            header('Location: payment_construction.php?orderid=' . $orderid . '&amount=' . $total_to_pay . '&success=1');
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            error_log("Payment error: " . $e->getMessage());
            die("Payment processing failed. Please try again.");
        }
    }
}

$stage_title = 'Construction Deposit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Construction Deposit - Project #<?php echo $orderid; ?></title>
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
                    <i class="fas fa-hard-hat me-1"></i> Construction Deposit
                </div>
                
                <h4>Payment for Project #<?php echo $orderid; ?></h4>
                
                <!-- Budget check removed for this payment stage -->
                
                <!-- Success Message -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-message">
                        <i class="fas fa-check-circle me-2"></i>
                        Construction deposit completed successfully! HK$<?php echo number_format($total_to_pay, 2); ?> received.
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
                    
                    <!-- Stage 4: Const. Deposit - Current (Yellow) -->
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
                                4
                            <?php endif; ?>
                        </div>
                        <span class="step-label">Const. Deposit</span>
                    </div>
                    <div class="step-connector <?php echo ($payment_success) ? 'completed' : ''; ?>"></div>
                    
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
                    <h5 class="mb-3"><i class="fas fa-hard-hat me-2"></i>Construction Deposit</h5>
                    
                    <div class="payment-detail">
                        <div class="payment-detail-item">
                            <span>Project #<?php echo $orderid; ?></span>
                            <span><?php echo date('Y-m-d', strtotime($order['odate'])); ?></span>
                        </div>
                        
                        <!-- Show breakdown of fees -->
                        <div class="fee-breakdown">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Construction Deposit:</span>
                                <span>HK$<?php echo number_format($construction_deposit, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Materials Cost (Actual Price):</span>
                                <span>HK$<?php echo number_format($actual_materials_cost, 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="payment-detail-item" style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">Total Construction Deposit:</span>
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