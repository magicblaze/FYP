<?php
// ==============================
// File: designer/manage_reference.php
// Manage order references (add/delete)
// ==============================

session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

$designerId = intval($_SESSION['user']['designerid']);
$response = ['success' => false, 'message' => 'Unknown error'];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['action'])) {
        throw new Exception('Missing action');
    }

    $action = $data['action'];

    if ($action === 'add') {
        // Add new reference
        if (empty($data['orderid'])) {
            throw new Exception('Missing orderid');
        }

        $orderId = intval($data['orderid']);
        $productId = !empty($data['productid']) ? intval($data['productid']) : null;
        $note = !empty($data['note']) ? trim($data['note']) : null;

        if (empty($productId)) {
            throw new Exception('Missing product selection');
        }

        // Verify the order belongs to this designer
        $verifySQL = "
            SELECT o.orderid FROM `Order` o
            JOIN Design d ON o.designid = d.designid
            WHERE d.designerid = ? AND o.orderid = ?
            LIMIT 1
        ";
        $stmt = $mysqli->prepare($verifySQL);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param("ii", $designerId, $orderId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception('Order not found or access denied');
        }
        $stmt->close();

        // Add the reference
        $insertSQL = "
            INSERT INTO OrderReference (orderid, productid, note, added_by_type, added_by_id, created_at)
            VALUES (?, ?, ?, 'designer', ?, NOW())
        ";
        $stmt = $mysqli->prepare($insertSQL);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param("iisi", $orderId, $productId, $note, $designerId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        $response = ['success' => true, 'message' => 'Reference added successfully'];

    } elseif ($action === 'delete') {
        // Delete reference
        if (empty($data['refid']) || empty($data['orderid'])) {
            throw new Exception('Missing refid or orderid');
        }

        $refId = intval($data['refid']);
        $orderId = intval($data['orderid']);

        // Verify the order belongs to this designer and the reference exists
        $verifySQL = "
            SELECT r.id FROM OrderReference r
            JOIN `Order` o ON r.orderid = o.orderid
            JOIN Design d ON o.designid = d.designid
            WHERE d.designerid = ? AND r.id = ? AND r.orderid = ?
            LIMIT 1
        ";
        $stmt = $mysqli->prepare($verifySQL);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param("iii", $designerId, $refId, $orderId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception('Reference not found or access denied');
        }
        $stmt->close();

        // Delete the reference
        $deleteSQL = "DELETE FROM OrderReference WHERE id = ? LIMIT 1";
        $stmt = $mysqli->prepare($deleteSQL);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param("i", $refId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        $response = ['success' => true, 'message' => 'Reference deleted successfully'];

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>
