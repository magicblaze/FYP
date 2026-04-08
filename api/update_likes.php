<?php
// ==============================
// File: api/update_likes.php
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['designid']) || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$designid = (int)$input['designid'];
$action = $input['action'];

if (!in_array($action, ['like', 'unlike'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Update likes count
if ($action === 'like') {
    $sql = "UPDATE Design SET likes = likes + 1 WHERE designid = ?";
} else {
    $sql = "UPDATE Design SET likes = GREATEST(likes - 1, 0) WHERE designid = ?";
}

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $designid);

if ($stmt->execute()) {
    // Get updated likes count
    $getSql = "SELECT likes FROM Design WHERE designid = ?";
    $getStmt = $mysqli->prepare($getSql);
    $getStmt->bind_param("i", $designid);
    $getStmt->execute();
    $result = $getStmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'likes' => (int)$result['likes'],
        'message' => 'Like updated successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update likes']);
}
?>
