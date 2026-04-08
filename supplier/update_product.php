<?php
// ==============================
// File: supplier/update_product.php
// 处理产品编辑更新的后端脚本 - 主圖片自動關聯到第一個顏色 (改进版本)
// 包含刪除顏色的邏輯
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
    $checkSql = "SELECT supplierid FROM Product WHERE productid = ?";
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

    // ========== 图片处理逻辑：主圖片將自動關聯到第一個顏色的圖片 ==========
    $newImagePath = null;

    // ========== 处理颜色和颜色图片 ==========
    $colorStr = '';
    $newColors = [];
    $firstColorImageName = null; // 用於存儲第一個顏色的圖片
    
    // 首先，獲取現有的顏色
    $existingColorsSql = "SELECT DISTINCT color, image FROM ProductColorImage WHERE productid = ?";
    $existingColorsStmt = $mysqli->prepare($existingColorsSql);
    $existingColors = [];
    if ($existingColorsStmt) {
        $existingColorsStmt->bind_param("i", $productId);
        $existingColorsStmt->execute();
        $existingColorsResult = $existingColorsStmt->get_result();
        while ($row = $existingColorsResult->fetch_assoc()) {
            $existingColors[$row['color']] = $row['image'];
        }
        $existingColorsStmt->close();
    }
    
    // 處理新顏色
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
                    
                    if ($file['size'] > 50 * 1024 * 1024) {
                        throw new Exception('Color image size exceeds 50MB limit');
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
                    
                    // 插入新的颜色-图片映射
                    $insertSql = "INSERT INTO ProductColorImage (productid, color, image) VALUES (?, ?, ?)";
                    $insertStmt = $mysqli->prepare($insertSql);
                    if ($insertStmt) {
                        $insertStmt->bind_param("iss", $productId, $color, $colorImageName);
                        if (!$insertStmt->execute()) {
                            throw new Exception('Failed to insert color image mapping: ' . $insertStmt->error);
                        }
                        $insertStmt->close();
                    } else {
                        throw new Exception('Failed to prepare insert statement: ' . $mysqli->error);
                    }
                    
                    // 如果是第一個顏色，記錄其圖片名稱
                    if ($idx == 0) {
                        $firstColorImageName = $colorImageName;
                    }
                }
            }
        }
        
        // ========== 刪除被移除的顏色 ==========
        $colorsToDelete = array_diff(array_keys($existingColors), $newColors);
        foreach ($colorsToDelete as $colorToDelete) {
            // 獲取要刪除的圖片
            $getImageSql = "SELECT image FROM ProductColorImage WHERE productid = ? AND color = ?";
            $getImageStmt = $mysqli->prepare($getImageSql);
            if ($getImageStmt) {
                $getImageStmt->bind_param("is", $productId, $colorToDelete);
                $getImageStmt->execute();
                $getImageResult = $getImageStmt->get_result();
                if ($getImageResult->num_rows > 0) {
                    $imageRow = $getImageResult->fetch_assoc();
                    $imageToDelete = $uploadDir . $imageRow['image'];
                    if (file_exists($imageToDelete)) {
                        unlink($imageToDelete);
                    }
                }
                $getImageStmt->close();
            }
            
            // 刪除資料庫記錄
            $deleteSql = "DELETE FROM ProductColorImage WHERE productid = ? AND color = ?";
            $deleteStmt = $mysqli->prepare($deleteSql);
            if ($deleteStmt) {
                $deleteStmt->bind_param("is", $productId, $colorToDelete);
                if (!$deleteStmt->execute()) {
                    throw new Exception('Failed to delete color: ' . $deleteStmt->error);
                }
                $deleteStmt->close();
            }
        }
        
        $colorStr = implode(", ", $newColors);
        
        // 如果有新的第一個顏色圖片，使用它作為主圖片
        if ($firstColorImageName !== null) {
            $newImagePath = $firstColorImageName;
        } elseif (!empty($newColors)) {
            // 否則，使用現有的第一個顏色圖片作為主圖片
            $firstColor = $newColors[0];
            $getFirstColorImageSql = "SELECT image FROM ProductColorImage WHERE productid = ? AND color = ?";
            $getFirstColorImageStmt = $mysqli->prepare($getFirstColorImageSql);
            if ($getFirstColorImageStmt) {
                $getFirstColorImageStmt->bind_param("is", $productId, $firstColor);
                $getFirstColorImageStmt->execute();
                $getFirstColorImageResult = $getFirstColorImageStmt->get_result();
                if ($getFirstColorImageResult->num_rows > 0) {
                    $firstColorImageRow = $getFirstColorImageResult->fetch_assoc();
                    $newImagePath = $firstColorImageRow['image'];
                }
                $getFirstColorImageStmt->close();
            }
        }
    }

    // ========== 数据库更新 ==========
    // 更新產品信息（不更新image欄位，因為Product表中沒有該欄位）
    // 主圖片自動關聯到第一個顏色的圖片，存儲在ProductColorImage表中
    $updateSql = "UPDATE Product SET pname = ?, category = ?, price = ?, description = ?, `long` = ?, `wide` = ?, `tall` = ?, material = ? WHERE productid = ? AND supplierid = ?";
    $updateStmt = $mysqli->prepare($updateSql);
    if (!$updateStmt) {
        throw new Exception('Prepare failed (update): ' . $mysqli->error);
    }
    $updateStmt->bind_param("ssisssssii", $pname, $category, $price, $description, $long, $wide, $tall, $material, $productId, $supplierId);

    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }

    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Product updated successfully with all colors and images',
        'first_color_image' => $newImagePath
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
