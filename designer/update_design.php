<?php
// ==============================
// File: designer/update_design.php
// Handle design update/edit operations with multiple images
// ==============================

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new Exception("[$errno] $errstr in $errfile:$errline");
});

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

    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
        http_response_code(401);
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    $designerId = intval($_SESSION['user']['designerid']);
    if ($designerId <= 0) {
        throw new Exception('Invalid designer ID');
    }

    $designId = isset($_POST['designid']) ? intval($_POST['designid']) : 0;
    $design_name = isset($_POST['design_name']) ? trim($_POST['design_name']) : '';
    $expect_price = isset($_POST['expect_price']) ? intval($_POST['expect_price']) : 0;
    $tag = isset($_POST['tag']) ? trim($_POST['tag']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if (empty($designId) || $expect_price < 0) {
        http_response_code(400);
        throw new Exception('Missing or invalid required fields');
    }

    // Check if design exists and belongs to current designer
    $checkSql = "SELECT designerid FROM Design WHERE designid = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Prepare failed (check): ' . $mysqli->error);
    }
    $checkStmt->bind_param("i", $designId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed (check): ' . $checkStmt->error);
    }
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        throw new Exception('Design not found');
    }
    $designRow = $checkResult->fetch_assoc();
    if ($designRow['designerid'] != $designerId) {
        http_response_code(403);
        throw new Exception('You do not have permission to edit this design');
    }
    $checkStmt->close();

    // Handle multiple image uploads if provided
    $uploadedImages = [];
    if (isset($_FILES['design']) && is_array($_FILES['design']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/designs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $imageCount = count($_FILES['design']['name']);
        
        for ($i = 0; $i < $imageCount; $i++) {
            if ($_FILES['design']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['design']['name'][$i],
                    'tmp_name' => $_FILES['design']['tmp_name'][$i],
                    'size' => $_FILES['design']['size'][$i]
                ];
                
                // Validate file
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                    throw new Exception('Invalid image file type. Only JPG, PNG, GIF, and WebP are allowed.');
                }
                
                if ($file['size'] > 50 * 1024 * 1024) {
                    throw new Exception('Image size exceeds 50MB limit');
                }
                
                // Generate unique filename
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $designImageName = uniqid('design_', true) . '.' . $ext;
                $designImagePath = $uploadDir . $designImageName;
                
                // Move file
                if (!move_uploaded_file($file['tmp_name'], $designImagePath)) {
                    throw new Exception('Failed to upload image: ' . $file['name']);
                }

                $uploadedImages[] = $designImageName;
            }
        }
    }

    // Update design basic information
    $updateSql = "UPDATE Design SET designName = ?, expect_price = ?, tag = ?, description = ? WHERE designid = ? AND designerid = ?";
    $updateStmt = $mysqli->prepare($updateSql);
    if (!$updateStmt) {
        throw new Exception('Prepare failed (update): ' . $mysqli->error);
    }
    $updateStmt->bind_param("sissii", $design_name, $expect_price, $tag, $description, $designId, $designerId);

    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }

    $updateStmt->close();

    // If new images were uploaded, add them to DesignImage table
    if (!empty($uploadedImages)) {
        $imageStmt = $mysqli->prepare("INSERT INTO DesignImage (designid, image_filename, image_order) VALUES (?, ?, ?)");
        if (!$imageStmt) {
            throw new Exception('Prepare failed (insert images): ' . $mysqli->error);
        }

        // Get the current max image_order for this design
        $maxOrderSql = "SELECT MAX(image_order) as max_order FROM DesignImage WHERE designid = ?";
        $maxOrderStmt = $mysqli->prepare($maxOrderSql);
        $maxOrderStmt->bind_param("i", $designId);
        $maxOrderStmt->execute();
        $maxOrderResult = $maxOrderStmt->get_result();
        $maxOrderRow = $maxOrderResult->fetch_assoc();
        $nextOrder = ($maxOrderRow['max_order'] ?? 0) + 1;
        $maxOrderStmt->close();

        foreach ($uploadedImages as $imageName) {
            $imageStmt->bind_param("isi", $designId, $imageName, $nextOrder);
            if (!$imageStmt->execute()) {
                throw new Exception('Failed to save image record: ' . $imageStmt->error);
            }
            $nextOrder++;
        }
        $imageStmt->close();
    }

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Design updated successfully' . (count($uploadedImages) > 0 ? ' with ' . count($uploadedImages) . ' new image(s)' : '')
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
    restore_error_handler();
    if (isset($mysqli) && $mysqli) {
        $mysqli->close();
    }
}
?>
