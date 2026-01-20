<?php
// ==============================
// File: supplier/update_order_status.php
// 处理订单产品状态更新的后端 API
// ==============================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

ob_start();

try {
    session_start();
    
    $config_path = __DIR__ . '/../config.php';
    if (!file_exists($config_path)) {
        throw new Exception('Config file not found at: ' . $config_path);
    }
    require_once $config_path;
    
    if (!isset($mysqli) || $mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }

    // 检查用户是否登录且是供应商
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
        http_response_code(401);
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    // 获取 JSON 请求体
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['orderproductid']) || !isset($input['status'])) {
        http_response_code(400);
        throw new Exception('Missing orderproductid or status');
    }

    $orderProductId = intval($input['orderproductid']);
    $newStatus = trim($input['status']);
    $supplierId = intval($_SESSION['user']['supplierid']);

    if ($orderProductId <= 0) {
        http_response_code(400);
        throw new Exception('Invalid orderproductid');
    }

    if (empty($newStatus)) {
        http_response_code(400);
        throw new Exception('Status cannot be empty');
    }

    // 验证状态值
    $validStatuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        http_response_code(400);
        throw new Exception('Invalid status value');
    }

    // ========== 验证该订单产品属于当前供应商 ==========
    $verifySql = "
        SELECT op.orderproductid
        FROM OrderProduct op
        JOIN Product p ON op.productid = p.productid
        WHERE op.orderproductid = ? AND p.supplierid = ?
    ";
    $verifyStmt = $mysqli->prepare($verifySql);
    if (!$verifyStmt) {
        throw new Exception('Prepare failed (verify): ' . $mysqli->error);
    }
    $verifyStmt->bind_param("ii", $orderProductId, $supplierId);
    if (!$verifyStmt->execute()) {
        throw new Exception('Execute failed (verify): ' . $verifyStmt->error);
    }
    $verifyResult = $verifyStmt->get_result();
    if ($verifyResult->num_rows === 0) {
        http_response_code(403);
        throw new Exception('You do not have permission to update this order product');
    }
    $verifyStmt->close();

    // ========== 直接更新 OrderProduct 表的 status 欄位 ==========
    $updateSql = "UPDATE OrderProduct SET status = ? WHERE orderproductid = ?";
    $updateStmt = $mysqli->prepare($updateSql);
    if (!$updateStmt) {
        throw new Exception('Prepare failed (update): ' . $mysqli->error);
    }
    $updateStmt->bind_param("si", $newStatus, $orderProductId);
    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }
    
    if ($updateStmt->affected_rows === 0) {
        throw new Exception('No rows were updated. OrderProduct may not exist.');
    }
    
    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($mysqli) && $mysqli) {
        $mysqli->close();
    }
}
?>
