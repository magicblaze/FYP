<?php
// ==============================
// File: client/approve_picture.php
// Handle approval of designed pictures
// ==============================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

ob_start();

try {
    session_start();
    
    // Check authentication
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
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

    // Validate picture ID
    $pictureId = isset($_POST['pictureid']) ? intval($_POST['pictureid']) : 0;

    if ($pictureId <= 0) {
        throw new Exception('Invalid picture ID');
    }

    // Get client ID
    $clientId = isset($_SESSION['user']['clientid']) ? intval($_SESSION['user']['clientid']) : 0;
    if ($clientId <= 0) {
        throw new Exception('Invalid client ID');
    }

    // Get picture details and verify client owns the order
    $picSql = "SELECT dp.*, o.clientid FROM DesignedPicture dp 
               JOIN `Order` o ON dp.orderid = o.orderid 
               WHERE dp.pictureid = ? AND o.clientid = ?";
    
    $picStmt = $mysqli->prepare($picSql);
    if (!$picStmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $picStmt->bind_param("ii", $pictureId, $clientId);
    if (!$picStmt->execute()) {
        throw new Exception('Execute failed: ' . $picStmt->error);
    }
    
    $picResult = $picStmt->get_result();

    if ($picResult->num_rows === 0) {
        http_response_code(403);
        throw new Exception('You do not have permission to approve this picture');
    }

    $picture = $picResult->fetch_assoc();
    $picStmt->close();

    // Update picture status to approved
    $updateSql = "UPDATE DesignedPicture SET status = 'approved' WHERE pictureid = ?";
    $updateStmt = $mysqli->prepare($updateSql);
    if (!$updateStmt) {
        throw new Exception('Prepare failed (update): ' . $mysqli->error);
    }

    $updateStmt->bind_param("i", $pictureId);
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update picture status: ' . $updateStmt->error);
    }

    $updateStmt->close();

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Picture approved successfully!'
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
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
