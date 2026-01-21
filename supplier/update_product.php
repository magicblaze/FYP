<?php
// ==============================
// File: supplier/update_product.php
// 处理产品编辑更新的后端脚本 - 支持颜色编辑 (改进版本)
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
    $price = isset($_POST['price']) ? intval($_POST['price']) : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $long = isset($_POST['long']) ? trim($_POST['long']) : '';
    $wide = isset($_POST['wide']) ? trim($_POST['wide']) : '';
    $tall = isset($_POST['tall']) ? trim($_POST['tall']) : '';
    $material = isset($_POST['material']) ? trim($_POST['material']) : '';
    $removeImage = isset($_POST['remove_image']) ? intval($_POST['remove_image']) : 0;

    // 处理颜色数据
    $colorsData = isset($_POST['colors_data']) ? json_decode($_POST['colors_data'], true) : [];
    $colorImages = $_FILES;
    $uploadDir = __DIR__ . '/../uploads/products/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

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
    
    if ($removeImage == 1) {
        if (!empty($productRow['image'])) {
            $oldImagePath = __DIR__ . '/../uploads/products/' . $productRow['image'];
            if (file_exists($oldImagePath)) {
                unlink($oldImagePath);
            }
        }
        $newImagePath = '';
    }
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
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
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds 5MB limit');
        }
        
        // 验证文件扩展名
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg'])) {
            throw new Exception('Invalid file extension. Only JPG/JPEG files are allowed');
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
        
        $newImagePath = $fileName;
    }

    // ========== 处理颜色和颜色图片 ==========
    $colorStr = '';
    $newColors = [];
    
    if (!empty($colorsData) && is_array($colorsData)) {
        foreach ($colorsData as $idx => $colorData) {
            $color = isset($colorData['color']) ? trim($colorData['color']) : '';
            if (empty($color)) {
                continue;
            }
            
            $newColors[] = $color;
            
            // 处理新上传的颜色图片
            if (isset($colorData['hasNewImage']) && $colorData['hasNewImage']) {
                $fileKey = 'color_image_' . $idx;
                if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$fileKey];
                    
                    // 验证文件
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    
                    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
                        throw new Exception('Invalid color image file type');
                    }
                    
                    if ($file['size'] > 5 * 1024 * 1024) {
                        throw new Exception('Color image size exceeds 5MB limit');
                    }
                    
                    // 生成唯一的文件名
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $colorImageName = uniqid('colorimg_', true) . '.' . $ext;
                    $colorImagePath = $uploadDir . $colorImageName;
                    
                    // 移动文件
                    if (!move_uploaded_file($file['tmp_name'], $colorImagePath)) {
                        throw new Exception('Failed to upload color image');
                    }
                    
                    // 删除旧的颜色图片
                    $deleteOldSql = "SELECT image FROM ProductColorImage WHERE productid = ? AND color = ?";
                    $deleteOldStmt = $mysqli->prepare($deleteOldSql);
                    if ($deleteOldStmt) {
                        $deleteOldStmt->bind_param("is", $productId, $color);
                        $deleteOldStmt->execute();
                        $deleteOldResult = $deleteOldStmt->get_result();
                        if ($deleteOldResult->num_rows > 0) {
                            $oldRow = $deleteOldResult->fetch_assoc();
                            $oldImagePath = $uploadDir . $oldRow['image'];
                            if (file_exists($oldImagePath)) {
                                unlink($oldImagePath);
                            }
                        }
                        $deleteOldStmt->close();
                    }
                    
                    // 更新或插入颜色-图片映射
                    $upsertSql = "INSERT INTO ProductColorImage (productid, color, image) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE image = ?";
                    $upsertStmt = $mysqli->prepare($upsertSql);
                    if ($upsertStmt) {
                        $upsertStmt->bind_param("isss", $productId, $color, $colorImageName, $colorImageName);
                        $upsertStmt->execute();
                        $upsertStmt->close();
                    }
                }
            }
        }
        
        $colorStr = implode(", ", $newColors);
    }

    // ========== 数据库更新 ==========
    if ($newImagePath !== null) {
        // 如果上传了新图片，更新图片字段
        $updateSql = "UPDATE Product SET pname = ?, category = ?, price = ?, description = ?, `long` = ?, `wide` = ?, `tall` = ?, color = ?, material = ?, image = ? WHERE productid = ? AND supplierid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        $updateStmt->bind_param("ssissssssii", $pname, $category, $price, $description, $long, $wide, $tall, $colorStr, $material, $newImagePath, $productId, $supplierId);
    } else {
        // 不更新图片字段
        $updateSql = "UPDATE Product SET pname = ?, category = ?, price = ?, description = ?, `long` = ?, `wide` = ?, `tall` = ?, color = ?, material = ? WHERE productid = ? AND supplierid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        $updateStmt->bind_param("ssissssssii", $pname, $category, $price, $description, $long, $wide, $tall, $colorStr, $material, $productId, $supplierId);
    }

    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }

    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Product updated successfully with all colors and images',
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
        'trace' => $e->getTraceAsString()
    ]);
} finally {
    if (isset($mysqli) && $mysqli) {
        $mysqli->close();
    }
}
?>
