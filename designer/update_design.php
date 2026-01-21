<?php
// ==============================
// File: designer/update_design.php
// Handle design update/edit operations
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
    $price = isset($_POST['price']) ? intval($_POST['price']) : 0;
    $tag = isset($_POST['tag']) ? trim($_POST['tag']) : '';

    if (empty($designId) || $price < 0) {
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

    // Handle image upload if provided
    $newImagePath = null;
    if (isset($_FILES['design']) && $_FILES['design']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/designs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $file = $_FILES['design'];
        
        // Validate file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
            throw new Exception('Invalid design image file type');
        }
        
        if ($file['size'] > 50 * 1024 * 1024) {
            throw new Exception('Design image size exceeds 50MB limit');
        }
        
        // Generate unique filename
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $designImageName = uniqid('design_', true) . '.' . $ext;
        $designImagePath = $uploadDir . $designImageName;
        
        // Move file
        if (!move_uploaded_file($file['tmp_name'], $designImagePath)) {
            throw new Exception('Failed to upload design image');
        }

        // Delete old image
        $getOldSql = "SELECT design FROM Design WHERE designid = ?";
        $getOldStmt = $mysqli->prepare($getOldSql);
        if ($getOldStmt) {
            $getOldStmt->bind_param("i", $designId);
            $getOldStmt->execute();
            $getOldResult = $getOldStmt->get_result();
            if ($getOldResult->num_rows > 0) {
                $oldRow = $getOldResult->fetch_assoc();
                if (!empty($oldRow['design'])) {
                    $oldImagePath = $uploadDir . $oldRow['design'];
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
            }
            $getOldStmt->close();
        }
        
        $newImagePath = $designImageName;
    }

    // Update design
    if ($newImagePath !== null) {
        $updateSql = "UPDATE Design SET design = ?, price = ?, tag = ? WHERE designid = ? AND designerid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        $updateStmt->bind_param("sisii", $newImagePath, $price, $tag, $designId, $designerId);
    } else {
        $updateSql = "UPDATE Design SET price = ?, tag = ? WHERE designid = ? AND designerid = ?";
        $updateStmt = $mysqli->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Prepare failed (update): ' . $mysqli->error);
        }
        $updateStmt->bind_param("isii", $price, $tag, $designId, $designerId);
    }

    if (!$updateStmt->execute()) {
        throw new Exception('Execute failed (update): ' . $updateStmt->error);
    }

    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Design updated successfully'
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
