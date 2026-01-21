<?php
// ==============================
// File: designer/delete_design.php
// Handle design deletion
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

    // Check if user is logged in and is a designer
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
        http_response_code(401);
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    // Get JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['designid'])) {
        http_response_code(400);
        throw new Exception('Missing designid');
    }

    $designId = intval($input['designid']);
    $designerId = intval($_SESSION['user']['designerid']);

    if ($designId <= 0 || $designerId <= 0) {
        http_response_code(400);
        throw new Exception('Invalid design ID or designer ID');
    }

    // Check if design exists and belongs to current designer
    $checkSql = "SELECT designid FROM Design WHERE designid = ? AND designerid = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Prepare failed (check): ' . $mysqli->error);
    }
    $checkStmt->bind_param("ii", $designId, $designerId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed (check): ' . $checkStmt->error);
    }
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        throw new Exception('Design not found or you do not have permission to delete it');
    }
    $checkStmt->close();

    // Check if there are any orders associated with this design
    $orderCheckSql = "SELECT COUNT(*) as order_count FROM `Order` WHERE designid = ?";
    $orderCheckStmt = $mysqli->prepare($orderCheckSql);
    if (!$orderCheckStmt) {
        throw new Exception('Prepare failed (order check): ' . $mysqli->error);
    }
    $orderCheckStmt->bind_param("i", $designId);
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
        throw new Exception('This design has ' . $orderCount . ' related ' . $orderText . ' and cannot be deleted.');
    }

    // Delete design image file
    $getImageSql = "SELECT design FROM Design WHERE designid = ?";
    $getImageStmt = $mysqli->prepare($getImageSql);
    if ($getImageStmt) {
        $getImageStmt->bind_param("i", $designId);
        $getImageStmt->execute();
        $getImageResult = $getImageStmt->get_result();
        if ($getImageResult->num_rows > 0) {
            $imageRow = $getImageResult->fetch_assoc();
            if (!empty($imageRow['design'])) {
                $imagePath = __DIR__ . '/../uploads/designs/' . $imageRow['design'];
                if (file_exists($imagePath)) {
                    if (!unlink($imagePath)) {
                        error_log('Warning: Failed to delete image file: ' . $imagePath);
                    }
                }
            }
        }
        $getImageStmt->close();
    }

    // Delete design from database
    $deleteSql = "DELETE FROM Design WHERE designid = ? AND designerid = ?";
    $deleteStmt = $mysqli->prepare($deleteSql);
    if (!$deleteStmt) {
        throw new Exception('Prepare failed (delete): ' . $mysqli->error);
    }
    $deleteStmt->bind_param("ii", $designId, $designerId);
    if (!$deleteStmt->execute()) {
        throw new Exception('Execute failed (delete): ' . $deleteStmt->error);
    }

    if ($deleteStmt->affected_rows === 0) {
        throw new Exception('Failed to delete design');
    }

    $deleteStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Design deleted successfully'
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
