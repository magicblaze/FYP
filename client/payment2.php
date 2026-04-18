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
if ($orderid <= 0)
    die('Project ID missing');

$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// Load order and payment data
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, o.deposit,
               d.expect_price as design_price, d.tag,
               c.clientid, c.cname, c.payment_method, c.budget,
           op.total_cost,
           op.design_fee_designer_2nd_pct, op.design_fee_manager_1st_pct
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

// Calculate payment values from OrderPayment percentage configuration
$total_cost = isset($order['total_cost']) ? floatval($order['total_cost']) : 0;
$design_fee_designer_2nd_pct = isset($order['design_fee_designer_2nd_pct']) ? floatval($order['design_fee_designer_2nd_pct']) : 0;
$design_fee_manager_1st_pct = isset($order['design_fee_manager_1st_pct']) ? floatval($order['design_fee_manager_1st_pct']) : 0;
$design_fee_designer_2nd = $total_cost * ($design_fee_designer_2nd_pct / 100);
$design_fee_manager_1st = $total_cost * ($design_fee_manager_1st_pct / 100);
$total_design_fees = $design_fee_designer_2nd + $design_fee_manager_1st;
$total_amount_due = $total_cost;

$paid_total_sql = "SELECT IFNULL(SUM(amount), 0) AS total_paid FROM ConstructionPaymentRecord WHERE orderid = ? AND status = 'paid'";
$paid_total_stmt = mysqli_prepare($mysqli, $paid_total_sql);
mysqli_stmt_bind_param($paid_total_stmt, "i", $orderid);
mysqli_stmt_execute($paid_total_stmt);
$paid_total_result = mysqli_stmt_get_result($paid_total_stmt);
$paid_total_row = mysqli_fetch_assoc($paid_total_result);
$total_amount_paid = floatval($paid_total_row['total_paid'] ?? 0);
mysqli_stmt_close($paid_total_stmt);

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

            $u_sql = "UPDATE `Order` SET ostatus = 'drafting 2nd proposal' WHERE orderid = ? AND clientid = ?";
            $u_stmt = mysqli_prepare($mysqli, $u_sql);
            mysqli_stmt_bind_param($u_stmt, "ii", $orderid, $client_id);
            mysqli_stmt_execute($u_stmt);
            mysqli_stmt_close($u_stmt);

            $milestone = 'Design Phase Payment 2';
            $installment_number = 2;
            $percentage = intval(round($design_fee_designer_2nd_pct + $design_fee_manager_1st_pct));
            $pay_record_sql = "INSERT INTO ConstructionPaymentRecord (orderid, installment_number, percentage, amount, milestone, paid_at, status) VALUES (?, ?, ?, ?, ?, NOW(), 'paid')";
            $pay_record_stmt = mysqli_prepare($mysqli, $pay_record_sql);
            mysqli_stmt_bind_param($pay_record_stmt, "iiids", $orderid, $installment_number, $percentage, $total_design_fees, $milestone);
            mysqli_stmt_execute($pay_record_stmt);
            mysqli_stmt_close($pay_record_stmt);

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
    <title>2nd Payment - Project #<?php echo $orderid; ?></title>
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

                <h4>Payment for Project #<?php echo $orderid; ?></h4>

                <!-- Budget check removed for this payment stage -->

                <!-- Success Message - 像 payment_final.php 一样 -->
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

                    <!-- Stage 2: 2nd Payment - 根据支付状态显示不同样式 -->
                    <div class="step <?php
                    if ($payment_rejected)
                        echo 'rejected';
                    elseif ($payment_success)
                        echo 'completed';
                    else
                        echo 'current';
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
                            <span>Payment #<?php echo $orderid; ?></span>
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

                        <div class="payment-detail-item"
                            style="border-top: 2px solid #27ae60; margin-top: 0.5rem; padding-top: 1rem;">
                            <span class="fw-bold">Total Design Fees:</span>
                            <span class="fw-bold">HK$<?php echo number_format($total_design_fees, 2); ?></span>
                        </div>

                        <div class="payment-detail-item">
                            <span class="fw-bold">Amount to Pay:</span>
                            <span
                                class="payment-amount fw-bold">HK$<?php echo number_format($total_design_fees, 2); ?></span>
                        </div>
                    </div>
                </div>

                <hr>

                <h5>Payment Method</h5>
                <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                    <div class="mb-3">
                        <p>Using: <strong><?php echo htmlspecialchars($paymentMethodData['method']); ?></strong></p>
                        <p class="small text-muted">If you want to change the payment method, update it in your <a
                                href="profile.php">profile</a>.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">No payment method configured. Please add one in your <a
                            href="profile.php">profile</a> before proceeding.</div>
                <?php endif; ?>

                <form method="post">
                    <div class="d-flex gap-2">
                    <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                        <?php if ($payment_success): ?>
                            <a href="order_history.php" class="btn btn-secondary">Back to Project History</a>
                        <?php else: ?>
                            <button type="submit" name="proceed_pay" class="btn btn-success"
                                onclick="return confirm('Confirm payment HK$<?php echo number_format($total_design_fees, 2); ?>?');">
                                <i class="fas fa-credit-card me-1"></i>
                                Proceed to Pay
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