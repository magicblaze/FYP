<?php
// ==============================
// File: supplier/update_order_details.php
// 处理订单产品详情更新的后端 API（交付日期等）
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
    if (!$input || !isset($input['orderproductid'])) {
        http_response_code(400);
        throw new Exception('Missing orderproductid');
    }

    $orderProductId = intval($input['orderproductid']);
    $supplierId = intval($_SESSION['user']['supplierid']);

    if ($orderProductId <= 0) {
        http_response_code(400);
        throw new Exception('Invalid orderproductid');
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

    // ========== 处理交付日期更新 ==========
    if (isset($input['deliverydate'])) {
        $deliveryDate = $input['deliverydate'];
        
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            http_response_code(400);
            throw new Exception('Invalid date format. Expected YYYY-MM-DD');
        }
        
        // 验证日期有效性
        $dateObj = DateTime::createFromFormat('Y-m-d', $deliveryDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $deliveryDate) {
            http_response_code(400);
            throw new Exception('Invalid date value');
        }

        // 更新交付日期
        $updateSql = "UPDATE OrderProduct SET deliverydate = ? WHERE orderproductid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        $updateStmt->bind_param("si", $deliveryDate, $orderProductId);
        if (!$updateStmt->execute()) {
            throw new Exception('Execute failed (update): ' . $updateStmt->error);
        }
        $updateStmt->close();
    } else {
        http_response_code(400);
        throw new Exception('No update fields provided');
    }

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Order details updated successfully'
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
