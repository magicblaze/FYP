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
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.designid, d.expect_price as design_price, d.tag, c.clientid, c.cname, c.payment_method
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

// Calculate construction fee
$design_price = isset($order['design_price']) ? (float)$order['design_price'] : 0.0;
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

$order_total = $design_price + $refs_total + $fees_total;
$constr_fee = $order_total * 1.5;

// Parse saved payment method
$paymentMethodData = [];
if (!empty($order['payment_method'])) {
    $paymentMethodData = json_decode($order['payment_method'], true) ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed_construction_pay'])) {
    // Mark order as 'preparing' after construction payment
    $u_sql = "UPDATE `Order` SET ostatus = 'preparing' WHERE orderid = ? AND clientid = ?";
    $u_stmt = mysqli_prepare($mysqli, $u_sql);
    mysqli_stmt_bind_param($u_stmt, "ii", $orderid, $client_id);
    mysqli_stmt_execute($u_stmt);
    mysqli_stmt_close($u_stmt);

    // Redirect user to order history
    header('Location: order_history.php?msg=construction_paid');
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Construction Payment - Order #<?php echo $orderid; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <h4>Construction Payment for Order #<?php echo $orderid; ?></h4>
                <div class="mb-3">
                    <ul class="list-unstyled">
                        <li class="d-flex justify-content-between"><small>Order Total</small><strong>HK$<?php echo number_format($order_total,2); ?></strong></li>
                        <li class="d-flex justify-content-between"><small>Construction Fee (150%)</small><strong class="text-danger">HK$<?php echo number_format($constr_fee,2); ?></strong></li>
                    </ul>
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
                    <input type="hidden" name="proceed_construction_pay" value="1">
                    <div class="d-flex gap-2">
                        <a href="order_detail.php?orderid=<?php echo $orderid; ?>" class="btn btn-secondary">Cancel</a>
                        <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                            <button type="submit" class="btn btn-success">Pay Construction Fee HK$<?php echo number_format($constr_fee,2); ?></button>
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
