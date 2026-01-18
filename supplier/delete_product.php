<?php
// ==============================
// File: supplier/delete_product.php
// 处理产品删除的后端脚本
// ==============================

// 设置错误报告以进行调试
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
    if (!$input || !isset($input['productid'])) {
        http_response_code(400);
        throw new Exception('Missing productid');
    }

    $productId = intval($input['productid']);
    $supplierId = intval($_SESSION['user']['supplierid']);

    if ($productId <= 0 || $supplierId <= 0) {
        http_response_code(400);
        throw new Exception('Invalid product ID or supplier ID');
    }

    // ========== 检查产品是否存在且属于当前供应商 ==========
    $checkSql = "SELECT image FROM Product WHERE productid = ? AND supplierid = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Prepare failed (check): ' . $mysqli->error);
    }
    $checkStmt->bind_param("ii", $productId, $supplierId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed (check): ' . $checkStmt->error);
    }
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        throw new Exception('Product not found or you do not have permission to delete it');
    }
    $productRow = $checkResult->fetch_assoc();
    $checkStmt->close();

    // ========== 检查是否有订单关联该产品 ==========
    $orderCheckSql = "SELECT COUNT(*) as order_count FROM OrderProduct WHERE productid = ?";
    $orderCheckStmt = $mysqli->prepare($orderCheckSql);
    if (!$orderCheckStmt) {
        throw new Exception('Prepare failed (order check): ' . $mysqli->error);
    }
    $orderCheckStmt->bind_param("i", $productId);
    if (!$orderCheckStmt->execute()) {
        throw new Exception('Execute failed (order check): ' . $orderCheckStmt->error);
    }
    $orderCheckResult = $orderCheckStmt->get_result();
    $orderRow = $orderCheckResult->fetch_assoc();
    $orderCheckStmt->close();

    if ($orderRow['order_count'] > 0) {
        http_response_code(409); // Conflict
        $orderCount = $orderRow['order_count'];
        $orderText = $orderCount == 1 ? 'order' : 'orders';
        throw new Exception('This product has ' . $orderCount . ' related ' . $orderText . ' and cannot be deleted.');
    }

    // ========== 删除产品图片文件 ==========
    if (!empty($productRow['image'])) {
        $imagePath = __DIR__ . '/../uploads/products/' . $productRow['image'];
        if (file_exists($imagePath)) {
            if (!unlink($imagePath)) {
                // 图片删除失败，但继续删除产品
                error_log('Warning: Failed to delete image file: ' . $imagePath);
            }
        }
    }

    // ========== 从数据库中删除产品 ==========
    $deleteSql = "DELETE FROM Product WHERE productid = ? AND supplierid = ?";
    $deleteStmt = $mysqli->prepare($deleteSql);
    if (!$deleteStmt) {
        throw new Exception('Prepare failed (delete): ' . $mysqli->error);
    }
    $deleteStmt->bind_param("ii", $productId, $supplierId);
    if (!$deleteStmt->execute()) {
        throw new Exception('Execute failed (delete): ' . $deleteStmt->error);
    }

    if ($deleteStmt->affected_rows === 0) {
        throw new Exception('Failed to delete product');
    }

    $deleteStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Product deleted successfully'
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
