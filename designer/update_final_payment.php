<?php
// ==============================
// File: designer/update_final_payment.php
// Update final payment amount for an order based on Expected Price
// ==============================

session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['orderid']) ? intval($input['orderid']) : 0;
$finalPayment = isset($input['final_payment']) ? floatval($input['final_payment']) : 0;

if ($orderId <= 0 || $finalPayment <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID or payment amount']);
    exit;
}

$designerId = intval($_SESSION['user']['designerid']);

// First, verify that this order belongs to this designer and get expected price
$verifySql = "
    SELECT o.orderid, d.expect_price 
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    WHERE o.orderid = ? AND d.designerid = ?
";
$stmt = $mysqli->prepare($verifySql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param("ii", $orderId, $designerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
    exit;
}

$order = $result->fetch_assoc();
$expectedPrice = floatval($order['expect_price'] ?? 0);

// Validate that final payment is within allowed range (Expected Price ÷ 0.25)
$maxAllowed = $expectedPrice > 0 ? $expectedPrice / 0.25 : 0;
if ($finalPayment > $maxAllowed) {
    echo json_encode(['success' => false, 'message' => 'Final payment cannot exceed HK$' . number_format($maxAllowed, 2) . ' (Expected Price ÷ 0.25)']);
    exit;
}

// Update the final payment
$updateSql = "UPDATE `Order` SET final_payment = ? WHERE orderid = ?";
$updateStmt = $mysqli->prepare($updateSql);
if (!$updateStmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$updateStmt->bind_param("di", $finalPayment, $orderId);
if ($updateStmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Final payment updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update final payment: ' . $updateStmt->error]);
}

$updateStmt->close();
$stmt->close();
$mysqli->close();
?>