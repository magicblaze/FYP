<?php
// ==============================
// File: designer/delete_designed_picture.php
// Delete designed picture from order
// ==============================

session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['pictureid'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Picture ID is required']);
    exit;
}

$pictureId = intval($input['pictureid']);
$designerId = intval($_SESSION['user']['designerid']);

// Verify that this picture belongs to an order for this designer's design
$checkSql = "
    SELECT dp.pictureid, dp.filename, dp.orderid
    FROM DesignedPicture dp
    JOIN `Order` o ON dp.orderid = o.orderid
    JOIN Design d ON o.designid = d.designid
    WHERE dp.pictureid = ? AND d.designerid = ?
    LIMIT 1
";

$stmt = $mysqli->prepare($checkSql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param("ii", $pictureId, $designerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Picture not found or unauthorized']);
    exit;
}

$picture = $result->fetch_assoc();
$stmt->close();

// Delete the file from filesystem
$uploadDir = __DIR__ . '/../uploads/designed_Picture/';
$filePath = $uploadDir . $picture['filename'];

if (file_exists($filePath)) {
    if (!unlink($filePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete file from server']);
        exit;
    }
}

// Delete from database
$deleteSql = "DELETE FROM DesignedPicture WHERE pictureid = ?";
$deleteStmt = $mysqli->prepare($deleteSql);

if (!$deleteStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$deleteStmt->bind_param("i", $pictureId);

if ($deleteStmt->execute()) {
    $deleteStmt->close();
    echo json_encode(['success' => true, 'message' => 'Picture deleted successfully']);
} else {
    $deleteStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete picture: ' . $mysqli->error]);
}
