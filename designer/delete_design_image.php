<?php
// ==============================
// File: designer/delete_design_image.php
// Delete a specific design image
// ==============================

error_reporting(E_ALL);
ini_set('display_errors', 0);

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new Exception("[$errno] $errstr in $errfile:$errline");
});

header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    
    $config_path = __DIR__ . '/../config.php';
    if (!file_exists($config_path)) {
        throw new Exception('Config file not found');
    }
    require_once $config_path;
    
    if (!isset($mysqli) || $mysqli->connect_error) {
        throw new Exception('Database connection failed');
    }

    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $imageId = isset($input['imageid']) ? intval($input['imageid']) : 0;
    $designId = isset($input['designid']) ? intval($input['designid']) : 0;
    $designerId = intval($_SESSION['user']['designerid']);

    if ($imageId <= 0 || $designId <= 0) {
        throw new Exception('Invalid image or design ID');
    }

    // Verify design belongs to current designer
    $checkSql = "SELECT designerid FROM Design WHERE designid = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    $checkStmt->bind_param("i", $designId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('Design not found');
    }
    
    $designRow = $checkResult->fetch_assoc();
    if ($designRow['designerid'] != $designerId) {
        http_response_code(403);
        throw new Exception('Permission denied');
    }
    $checkStmt->close();

    // Get image filename to delete file
    $getSql = "SELECT image_filename FROM DesignImage WHERE imageid = ? AND designid = ?";
    $getStmt = $mysqli->prepare($getSql);
    $getStmt->bind_param("ii", $imageId, $designId);
    $getStmt->execute();
    $getResult = $getStmt->get_result();
    
    if ($getResult->num_rows === 0) {
        throw new Exception('Image not found');
    }
    
    $imageRow = $getResult->fetch_assoc();
    $imageFilename = $imageRow['image_filename'];
    $getStmt->close();

    // Delete image record from database
    $deleteSql = "DELETE FROM DesignImage WHERE imageid = ? AND designid = ?";
    $deleteStmt = $mysqli->prepare($deleteSql);
    if (!$deleteStmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $deleteStmt->bind_param("ii", $imageId, $designId);
    if (!$deleteStmt->execute()) {
        throw new Exception('Execute failed: ' . $deleteStmt->error);
    }
    
    $deleteStmt->close();

    // Delete physical file
    $uploadDir = __DIR__ . '/../uploads/designs/';
    $filePath = $uploadDir . $imageFilename;
    
    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            // Log warning but don't fail
            error_log('Warning: Could not delete file: ' . $filePath);
        }
    }

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Image deleted successfully'
    ]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
} finally {
    restore_error_handler();
    ob_end_clean();
    if (isset($mysqli) && $mysqli) {
        $mysqli->close();
    }
}
?>
