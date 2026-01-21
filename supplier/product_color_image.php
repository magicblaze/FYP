<?php
// ==============================
// File: supplier/product_color_image.php
// 显示产品颜色对应的图片
// ==============================

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=3600');

try {
    require_once __DIR__ . '/../config.php';
    
    $productId = isset($_GET['productid']) ? intval($_GET['productid']) : 0;
    $color = isset($_GET['color']) ? trim($_GET['color']) : '';
    
    if ($productId <= 0 || empty($color)) {
        http_response_code(400);
        die('Invalid parameters');
    }
    
    // 查询颜色图片
    $sql = "SELECT image FROM ProductColorImage WHERE productid = ? AND color = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        die('Database error');
    }
    
    $stmt->bind_param("is", $productId, $color);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        die('Image not found');
    }
    
    $row = $result->fetch_assoc();
    $imageName = $row['image'];
    $stmt->close();
    
    // 构建文件路径
    $uploadDir = __DIR__ . '/../uploads/products/';
    $filePath = $uploadDir . $imageName;
    
    // 检查文件是否存在
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found');
    }
    
    // 检查文件权限
    if (!is_readable($filePath)) {
        http_response_code(403);
        die('File not readable');
    }
    
    // 输出文件
    readfile($filePath);
    
} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
} finally {
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>
