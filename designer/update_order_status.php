<?php
// Designer endpoint to update Order. Expects JSON { orderid, action }
header('Content-Type: application/json; charset=utf-8');
try {
    session_start();
    require_once __DIR__ . '/../config.php';
    if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer') {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'message'=>'Method Not Allowed']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['orderid']) || empty($data['action'])) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Missing parameters']);
        exit;
    }
    $orderId = (int)$data['orderid'];
    $action = strtolower(trim($data['action']));
    $map = ['confirm'=>'Confirmed','reject'=>'Rejected'];
    if (!isset($map[$action])) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invalid action']);
        exit;
    }
    $newStatus = $map[$action];
    $designerId = (int)($_SESSION['user']['designerid'] ?? 0);

    // verify ownership: order belongs to a design by this designer
    $verify = $mysqli->prepare("SELECT o.orderid FROM `Order` o JOIN Design d ON o.designid = d.designid WHERE o.orderid = ? AND d.designerid = ? LIMIT 1");
    $verify->bind_param('ii', $orderId, $designerId);
    $verify->execute();
    $vr = $verify->get_result();
    if (!$vr || $vr->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Order not found or access denied']);
        exit;
    }
    $verify->close();

    $upd = $mysqli->prepare("UPDATE `Order` SET ostatus = ? WHERE orderid = ?");
    $upd->bind_param('si', $newStatus, $orderId);
    if (!$upd->execute()) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Update failed']);
        exit;
    }
    $upd->close();
    echo json_encode(['success'=>true,'message'=>'Status updated','status'=>$newStatus]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
