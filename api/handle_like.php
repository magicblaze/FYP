<?php
// ==============================
// File: api/handle_like.php
// Purpose: Unified API for handling product and design like/unlike operations
// Replaces: update_likes.php and update_product_likes.php
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$clientid = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientid <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

// Support both JSON and FormData input
$input = [];
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($content_type, 'application/json') !== false) {
    // Handle JSON input
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    // Handle FormData input
    $input = $_POST;
}

// Get parameters
$action = $input['action'] ?? '';
$type = $input['type'] ?? $input['itemtype'] ?? ''; // Support both 'type' and 'itemtype'
$id = (int)($input['id'] ?? $input['designid'] ?? $input['productid'] ?? 0);

// Validate input
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
    exit;
}

// Normalize type
if (empty($type)) {
    // Try to determine type from parameter names
    if (isset($input['designid'])) {
        $type = 'design';
    } elseif (isset($input['productid'])) {
        $type = 'product';
    }
}

if (!in_array($type, ['product', 'design'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid item type']);
    exit;
}

if (!in_array($action, ['like', 'unlike', 'toggle_like', 'remove_like', 'check_like'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

try {
    // Normalize action names for backward compatibility
    if ($action === 'like' || $action === 'toggle_like') {
        $action = 'toggle_like';
    } elseif ($action === 'unlike' || $action === 'remove_like') {
        $action = 'remove_like';
    }

    if ($type === 'product') {
        handleProductLike($mysqli, $clientid, $id, $action);
    } else { // design
        handleDesignLike($mysqli, $clientid, $id, $action);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ==============================
// Product Like Handler
// ==============================
function handleProductLike($mysqli, $clientid, $productid, $action) {
    if ($action === 'toggle_like') {
        // Check if already liked
        $check_sql = "SELECT COUNT(*) as count FROM ProductLike WHERE clientid = ? AND productid = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $clientid, $productid);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();

        if ($check_result['count'] > 0) {
            // Unlike
            $delete_sql = "DELETE FROM ProductLike WHERE clientid = ? AND productid = ?";
            $delete_stmt = $mysqli->prepare($delete_sql);
            $delete_stmt->bind_param("ii", $clientid, $productid);
            $delete_stmt->execute();

            // Decrease like count
            $update_sql = "UPDATE Product SET likes = GREATEST(likes - 1, 0) WHERE productid = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $productid);
            $update_stmt->execute();

            // Get updated likes count
            $get_sql = "SELECT likes FROM Product WHERE productid = ?";
            $get_stmt = $mysqli->prepare($get_sql);
            $get_stmt->bind_param("i", $productid);
            $get_stmt->execute();
            $result = $get_stmt->get_result()->fetch_assoc();

            echo json_encode([
                'success' => true,
                'liked' => false,
                'likes' => (int)$result['likes'],
                'message' => 'Product unliked successfully'
            ]);
        } else {
            // Like
            $insert_sql = "INSERT INTO ProductLike (clientid, productid) VALUES (?, ?)";
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $clientid, $productid);
            $insert_stmt->execute();

            // Increase like count
            $update_sql = "UPDATE Product SET likes = likes + 1 WHERE productid = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $productid);
            $update_stmt->execute();

            // Get updated likes count
            $get_sql = "SELECT likes FROM Product WHERE productid = ?";
            $get_stmt = $mysqli->prepare($get_sql);
            $get_stmt->bind_param("i", $productid);
            $get_stmt->execute();
            $result = $get_stmt->get_result()->fetch_assoc();

            echo json_encode([
                'success' => true,
                'liked' => true,
                'likes' => (int)$result['likes'],
                'message' => 'Product liked successfully'
            ]);
        }
    } elseif ($action === 'remove_like') {
        // Remove like
        $delete_sql = "DELETE FROM ProductLike WHERE clientid = ? AND productid = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $clientid, $productid);
        $delete_stmt->execute();

        // Decrease like count
        $update_sql = "UPDATE Product SET likes = GREATEST(likes - 1, 0) WHERE productid = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $productid);
        $update_stmt->execute();

        // Get updated likes count
        $get_sql = "SELECT likes FROM Product WHERE productid = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $productid);
        $get_stmt->execute();
        $result = $get_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'likes' => (int)$result['likes'],
            'message' => 'Product removed from likes'
        ]);
    } elseif ($action === 'check_like') {
        // Check if user has liked this product
        $check_sql = "SELECT COUNT(*) as count FROM ProductLike WHERE clientid = ? AND productid = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $clientid, $productid);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();

        // Get current likes count
        $get_sql = "SELECT likes FROM Product WHERE productid = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $productid);
        $get_stmt->execute();
        $result = $get_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'liked' => $check_result['count'] > 0,
            'likes' => (int)$result['likes']
        ]);
    }
}

// ==============================
// Design Like Handler
// ==============================
function handleDesignLike($mysqli, $clientid, $designid, $action) {
    if ($action === 'toggle_like') {
        // Check if already liked
        $check_sql = "SELECT COUNT(*) as count FROM DesignLike WHERE clientid = ? AND designid = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $clientid, $designid);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();

        if ($check_result['count'] > 0) {
            // Unlike
            $delete_sql = "DELETE FROM DesignLike WHERE clientid = ? AND designid = ?";
            $delete_stmt = $mysqli->prepare($delete_sql);
            $delete_stmt->bind_param("ii", $clientid, $designid);
            $delete_stmt->execute();

            // Decrease like count
            $update_sql = "UPDATE Design SET likes = GREATEST(likes - 1, 0) WHERE designid = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $designid);
            $update_stmt->execute();

            // Get updated likes count
            $get_sql = "SELECT likes FROM Design WHERE designid = ?";
            $get_stmt = $mysqli->prepare($get_sql);
            $get_stmt->bind_param("i", $designid);
            $get_stmt->execute();
            $result = $get_stmt->get_result()->fetch_assoc();

            echo json_encode([
                'success' => true,
                'liked' => false,
                'likes' => (int)$result['likes'],
                'message' => 'Design unliked successfully'
            ]);
        } else {
            // Like
            $insert_sql = "INSERT INTO DesignLike (clientid, designid) VALUES (?, ?)";
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $clientid, $designid);
            $insert_stmt->execute();

            // Increase like count
            $update_sql = "UPDATE Design SET likes = likes + 1 WHERE designid = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $designid);
            $update_stmt->execute();

            // Get updated likes count
            $get_sql = "SELECT likes FROM Design WHERE designid = ?";
            $get_stmt = $mysqli->prepare($get_sql);
            $get_stmt->bind_param("i", $designid);
            $get_stmt->execute();
            $result = $get_stmt->get_result()->fetch_assoc();

            echo json_encode([
                'success' => true,
                'liked' => true,
                'likes' => (int)$result['likes'],
                'message' => 'Design liked successfully'
            ]);
        }
    } elseif ($action === 'remove_like') {
        // Remove like
        $delete_sql = "DELETE FROM DesignLike WHERE clientid = ? AND designid = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $clientid, $designid);
        $delete_stmt->execute();

        // Decrease like count
        $update_sql = "UPDATE Design SET likes = GREATEST(likes - 1, 0) WHERE designid = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $designid);
        $update_stmt->execute();

        // Get updated likes count
        $get_sql = "SELECT likes FROM Design WHERE designid = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $designid);
        $get_stmt->execute();
        $result = $get_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'likes' => (int)$result['likes'],
            'message' => 'Design removed from likes'
        ]);
    } elseif ($action === 'check_like') {
        // Check if user has liked this design
        $check_sql = "SELECT COUNT(*) as count FROM DesignLike WHERE clientid = ? AND designid = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $clientid, $designid);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();

        // Get current likes count
        $get_sql = "SELECT likes FROM Design WHERE designid = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $designid);
        $get_stmt->execute();
        $result = $get_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'liked' => $check_result['count'] > 0,
            'likes' => (int)$result['likes']
        ]);
    }
}
?>
