<?php
// ==============================
// File: designer/get_design_images.php
// Fetch design images for display in edit modal
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

    $designerId = intval($_SESSION['user']['designerid']);
    $designId = isset($_GET['designid']) ? intval($_GET['designid']) : 0;

    if ($designId <= 0) {
        throw new Exception('Invalid design ID');
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

    // Fetch all images for this design
    $sql = "SELECT imageid, designid, image_filename, image_order FROM DesignImage WHERE designid = ? ORDER BY image_order ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param("i", $designId);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $images = [];
    
    while ($row = $result->fetch_assoc()) {
        $images[] = [
            'imageid' => $row['imageid'],
            'image_filename' => $row['image_filename'],
            'image_order' => $row['image_order']
        ];
    }
    
    $stmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'images' => $images
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
