<?php
require_once './config.php';

$action = $_GET['action'] ?? '';

switch ($action) {
  case 'listRooms':
    $stmt = $pdo->query("SELECT * FROM ChatRoom ORDER BY ChatRoomid DESC");
    echo json_encode($stmt->fetchAll());
    break;

  case 'getMembers':
    $roomId = (int)($_GET['room'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM ChatRoomMember WHERE ChatRoomid=?");
    $stmt->execute([$roomId]);
    echo json_encode($stmt->fetchAll());
    break;

  case 'getMessages':
    $roomId = (int)($_GET['room'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM Message WHERE ChatRoomid=? ORDER BY timestamp ASC");
    $stmt->execute([$roomId]);
    echo json_encode($stmt->fetchAll());
    break;

  case 'sendMessage':
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,ChatRoomid) VALUES (?,?,?,?)");
    $stmt->execute([
      $data['sender_type'],
      $data['sender_id'],
      $data['content'],
      $data['room']
    ]);
    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    break;

  default:
    echo json_encode(['error'=>'Unknown action']);
}
