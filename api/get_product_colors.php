<?php
// ==============================
// File: api/get_product_colors.php
// Fetch available colors for a product
// ==============================
require_once __DIR__ . '/../config.php';
$productId = isset($_GET['productid']) ? intval($_GET['productid']) : 0;
if (!$productId) {
    http_response_code(400);
    echo json_encode(['colors' => [], 'error' => 'Missing productid']);
    exit;
}
try {
    $sql = "SELECT DISTINCT color FROM ProductColorImage WHERE productid = ? ORDER BY color ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $colors = [];
    
    while ($row = $result->fetch_assoc()) {
        $colors[] = $row;
    }
    
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['colors' => $colors, 'success' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['colors' => [], 'error' => $e->getMessage()]);
}
?>
