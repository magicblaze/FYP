<?php
// ==============================
// File: designer/upload_designed_picture.php
// Handle designed picture uploads
// Only allow one pending picture per order at a time
// ==============================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

ob_start();

try {
    session_start();
    
    // Check authentication
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access'
        ]);
        exit;
    }

    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
        exit;
    }

    // Include config
    $configPath = __DIR__ . '/../config.php';
    if (!file_exists($configPath)) {
        throw new Exception('Config file not found: ' . $configPath);
    }
    require_once $configPath;

    // Validate order ID
    $orderId = isset($_POST['orderid']) ? intval($_POST['orderid']) : 0;
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID');
    }

    // Get designer ID
    $designerId = isset($_SESSION['user']['designerid']) ? intval($_SESSION['user']['designerid']) : 0;
    if ($designerId <= 0) {
        throw new Exception('Invalid designer ID');
    }

    // Verify the order belongs to this designer
    $checkSql = "SELECT o.orderid FROM `Order` o 
                 JOIN Design d ON o.designid = d.designid 
                 WHERE o.orderid = ? AND d.designerid = ?";
    
    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $checkStmt->bind_param("ii", $orderId, $designerId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed: ' . $checkStmt->error);
    }
    
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        http_response_code(403);
        throw new Exception('You do not have permission to upload for this order');
    }
    $checkStmt->close();

    // Check if there's already a pending picture for this order
    $pendingSql = "SELECT pictureid FROM DesignedPicture WHERE orderid = ? AND status = 'pending'";
    $pendingStmt = $mysqli->prepare($pendingSql);
    if (!$pendingStmt) {
        throw new Exception('Prepare failed (pending check): ' . $mysqli->error);
    }
    
    $pendingStmt->bind_param("i", $orderId);
    if (!$pendingStmt->execute()) {
        throw new Exception('Execute failed (pending check): ' . $pendingStmt->error);
    }
    
    $pendingResult = $pendingStmt->get_result();
    
    if ($pendingResult->num_rows > 0) {
        http_response_code(409); // Conflict
        throw new Exception('There is already a pending picture for this order. Please wait for the client to approve or reject it before uploading a new one.');
    }
    $pendingStmt->close();

    // Handle file upload
    if (!isset($_FILES['picture'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['picture'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }

    // Validate file type using finfo
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    } else {
        // Fallback to checking file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowedExts)) {
            throw new Exception('Invalid image file type');
        }
        $mimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception('Invalid image file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    // Validate file size (10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('File size exceeds 10MB limit');
    }

    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/designed_Picture/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'designed_' . $orderId . '_' . uniqid() . '.' . $ext;
    $uploadPath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Insert picture record into database with pending status
    $insertSql = "INSERT INTO DesignedPicture (orderid, filename, status, is_current) VALUES (?, ?, 'pending', TRUE)";
    $insertStmt = $mysqli->prepare($insertSql);
    if (!$insertStmt) {
        throw new Exception('Prepare failed (insert): ' . $mysqli->error);
    }

    $insertStmt->bind_param("is", $orderId, $filename);
    if (!$insertStmt->execute()) {
        throw new Exception('Failed to save picture record: ' . $insertStmt->error);
    }

    $insertStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Picture uploaded successfully. Waiting for client approval.',
        'filename' => $filename
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
