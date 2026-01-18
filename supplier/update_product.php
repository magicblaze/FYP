<?php
// ==============================
// File: supplier/update_product.php
// 处理产品编辑更新的后端脚本 - 支持图片上传
// ==============================

// 设置错误报告以进行调试
error_reporting(E_ALL);
ini_set('display_errors', 1); // 在开发环境中建议开启以显示错误

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

    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
        http_response_code(401);
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    $supplierId = intval($_SESSION['user']['supplierid']);
    if ($supplierId <= 0) {
        throw new Exception('Invalid supplier ID');
    }

    $productId = isset($_POST['productid']) ? intval($_POST['productid']) : 0;
    $pname = isset($_POST['pname']) ? trim($_POST['pname']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $price = isset($_POST['price']) ? intval($_POST['price']) : 0; // 根据数据库结构，price 是 INT
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $size = isset($_POST['size']) ? trim($_POST['size']) : '';
    $material = isset($_POST['material']) ? trim($_POST['material']) : '';
    $colorStr = isset($_POST['color_str']) ? trim($_POST['color_str']) : '';
    $removeImage = isset($_POST['remove_image']) ? intval($_POST['remove_image']) : 0; // 是否删除当前图片

    if (empty($productId) || empty($pname) || empty($category) || $price < 0) {
        http_response_code(400);
        throw new Exception('Missing or invalid required fields');
    }

    if (!in_array($category, ['Furniture', 'Material'])) {
        http_response_code(400);
        throw new Exception('Invalid category. Must be Furniture or Material');
    }

    // 检查产品是否存在且属于当前供应商
    $checkSql = "SELECT supplierid, image FROM Product WHERE productid = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Prepare failed (check): ' . $mysqli->error);
    }
    $checkStmt->bind_param("i", $productId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed (check): ' . $checkStmt->error);
    }
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        throw new Exception('Product not found');
    }
    $productRow = $checkResult->fetch_assoc();
    if ($productRow['supplierid'] != $supplierId) {
        http_response_code(403);
        throw new Exception('You do not have permission to edit this product');
    }
    $checkStmt->close();

    // ========== 图片处理逻辑 ==========
    $newImagePath = null;
    $deleteCurrentImage = false;
    
    // 检查是否需要删除当前图片
    if ($removeImage == 1) {
        $deleteCurrentImage = true;
    }
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        // 用户上传了新图片
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $_FILES['image']['error']);
        }

        $file = $_FILES['image'];
        
        // 验证文件类型
        $allowedMimes = ['image/jpeg'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('Invalid file type. Only JPG/JPEG files are allowed');
        }
        
        // 验证文件大小（5MB）
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds 5MB limit');
        }
        
        // 验证文件扩展名
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg'])) {
            throw new Exception('Invalid file extension. Only JPG/JPEG files are allowed');
        }
        
        // 创建上传目录
        $uploadDir = __DIR__ . '/../uploads/products/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        
        // 生成唯一的文件名
        $fileName = 'product_' . $productId . '_' . time() . '.jpg';
        $filePath = $uploadDir . $fileName;
        
        // 移动上传的文件
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // 删除旧图片（如果存在）
        if (!empty($productRow['image'])) {
            $oldImagePath = __DIR__ . '/../uploads/products/' . $productRow['image'];
            if (file_exists($oldImagePath)) {
                unlink($oldImagePath);
            }
        }
        
        // 设置新的图片文件名（仅存储文件名，不是完整路径）
        $newImagePath = $fileName;
    } elseif ($deleteCurrentImage && !empty($productRow['image'])) {
        // 用户选择删除当前图片但没有上传新图片
        $oldImagePath = __DIR__ . '/../uploads/products/' . $productRow['image'];
        if (file_exists($oldImagePath)) {
            unlink($oldImagePath);
        }
        // 设置图片路径为空字符串（数据库中存储为NULL）
        $newImagePath = '';
        $deleteCurrentImage = true;
    }

    // ========== 数据库更新 ==========
    if ($newImagePath !== null) {
        // 如果上传了新图片，更新图片字段
        $updateSql = "UPDATE Product SET pname = ?, category = ?, price = ?, description = ?, size = ?, color = ?, material = ?, image = ? WHERE productid = ? AND supplierid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        
        // 类型字符串：s(pname), s(category), i(price), s(description), s(size), s(color), s(material), s(image), i(productid), i(supplierid)
        $updateStmt->bind_param("ssisssssii", $pname, $category, $price, $description, $size, $colorStr, $material, $newImagePath, $productId, $supplierId);
    } else {
        // 不更新图片字段
        $updateSql = "UPDATE Product SET pname = ?, category = ?, price = ?, description = ?, size = ?, color = ?, material = ? WHERE productid = ? AND supplierid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        
        // 类型字符串：s(pname), s(category), i(price), s(description), s(size), s(color), s(material), i(productid), i(supplierid)
        $updateStmt->bind_param("ssisssiii", $pname, $category, $price, $description, $size, $colorStr, $material, $productId, $supplierId);
    }

    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }

    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Product updated successfully',
        'image_path' => $newImagePath
    ]);

} catch (Exception $e) {
    ob_end_clean();
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // 在开发时提供详细追踪信息
    ]);
} finally {
    if (isset($mysqli) && $mysqli) {
        $mysqli->close();
    }
}
?>
