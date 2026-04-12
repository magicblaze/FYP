<?php
// Public/construction_payment.php
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$client_id = (int) ($_SESSION['user']['clientid'] ?? 0);
$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;

if ($order_id <= 0) {
    header('Location: order_history.php');
    exit;
}

// Verify order belongs to this client
$sql = "SELECT o.*, d.designName, op.total_cost, op.total_amount_paid, op.payment_status
        FROM `Order` o
        JOIN Design d ON o.designid = d.designid
        JOIN OrderPayment op ON o.payment_id = op.payment_id
        WHERE o.orderid = ? AND o.clientid = ?";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "ii", $order_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    header('Location: order_history.php');
    exit;
}

// Check if schedule is accepted
$check_sql = "SELECT construction_date_status FROM Schedule WHERE orderid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_sql);
mysqli_stmt_bind_param($check_stmt, "i", $order_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$schedule = mysqli_fetch_assoc($check_result);

if (!$schedule || $schedule['construction_date_status'] !== 'accepted') {
    header('Location: construction_schedule.php?orderid=' . $order_id);
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $payment_plan = $_POST['payment_plan'] ?? '';
    $total_cost = floatval($order['total_cost']);
    
    if ($payment_plan === 'full') {
        $_SESSION['construction_payment'] = [
            'order_id' => $order_id,
            'amount' => $total_cost,
            'plan' => 'full',
            'installment_index' => 0,
            'installments' => [['percentage' => 100, 'amount' => $total_cost]]
        ];
        header('Location: process_construction_payment.php');
        exit;
        
    } elseif ($payment_plan === 'installment_25') {
        $installment_amount = $total_cost * 0.25;
        $_SESSION['construction_payment'] = [
            'order_id' => $order_id,
            'amount' => $installment_amount,
            'plan' => 'installment_25',
            'installment_index' => 0,
            'installments' => [
                ['percentage' => 25, 'amount' => $installment_amount, 'milestone' => 'Initial Payment (0%)'],
                ['percentage' => 25, 'amount' => $installment_amount, 'milestone' => '25% Completion'],
                ['percentage' => 25, 'amount' => $installment_amount, 'milestone' => '50% Completion'],
                ['percentage' => 25, 'amount' => $installment_amount, 'milestone' => '75% Completion']
            ]
        ];
        header('Location: process_construction_payment.php');
        exit;
        
    } elseif ($payment_plan === 'installment_50') {
        $first_payment = $total_cost * 0.5;
        $_SESSION['construction_payment'] = [
            'order_id' => $order_id,
            'amount' => $first_payment,
            'plan' => 'installment_50',
            'installment_index' => 0,
            'installments' => [
                ['percentage' => 50, 'amount' => $first_payment, 'milestone' => 'Initial Payment (0%)'],
                ['percentage' => 50, 'amount' => $total_cost * 0.5, 'milestone' => '50% Completion']
            ]
        ];
        header('Location: process_construction_payment.php');
        exit;
    } else {
        $error = 'Please select a payment plan.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Choose Payment Plan - Project #<?= $order_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Construction Payment - Project #<?= $order_id ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <strong>Total Construction Cost:</strong> $<?= number_format($order['total_cost'], 2) ?>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" id="paymentForm">
                            <h5>Select Payment Plan:</h5>
                            
                            <div class="mb-3">
                                <div class="form-check border p-3 rounded mb-2">
                                    <input class="form-check-input" type="radio" name="payment_plan" value="full" id="plan_full" required>
                                    <label class="form-check-label" for="plan_full">
                                        <strong>Full Payment</strong>
                                        <span class="badge bg-secondary ms-2">100% upfront</span>
                                        <div class="text-muted small">Pay $<?= number_format($order['total_cost'], 2) ?> now</div>
                                    </label>
                                </div>
                                
                                <div class="form-check border p-3 rounded mb-2">
                                    <input class="form-check-input" type="radio" name="payment_plan" value="installment_25" id="plan_25">
                                    <label class="form-check-label" for="plan_25">
                                        <strong>4 Installments (25% each)</strong>
                                        <span class="badge bg-primary ms-2">Pay as you go</span>
                                        <div class="text-muted small">
                                            Pay $<?= number_format($order['total_cost'] * 0.25, 2) ?> now<br>
                                            Then pay at 25%, 50%, and 75% construction completion
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="form-check border p-3 rounded mb-2">
                                    <input class="form-check-input" type="radio" name="payment_plan" value="installment_50" id="plan_50">
                                    <label class="form-check-label" for="plan_50">
                                        <strong>2 Installments (50% each)</strong>
                                        <span class="badge bg-primary ms-2">Half now, half later</span>
                                        <div class="text-muted small">
                                            Pay $<?= number_format($order['total_cost'] * 0.5, 2) ?> now<br>
                                            Then pay $<?= number_format($order['total_cost'] * 0.5, 2) ?> at 50% completion
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success btn-lg w-100">Continue to Payment</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>