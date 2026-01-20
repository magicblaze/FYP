<?php
// Return JSON only — prevent PHP notices/warnings being printed as HTML to XHR clients
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
// Buffer output so accidental HTML/warnings don't break JSON.
ob_start();

function send_json($data, $status = 200) {
  if (ob_get_length() !== false) { @ob_clean(); }
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

// Catch fatal errors on shutdown and return JSON-safe error
register_shutdown_function(function() {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    error_log('[ChatApi][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    if (ob_get_length() !== false) @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'fatal', 'message' => $err['message']]);
  }
});
require_once __DIR__ . '/../config.php';

// Ensure a PDO instance is available for this API. `config.php` defines mysqli variables
if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $dsn = "mysql:host={$hostname};dbname={$db};charset=utf8";
    $pdo = new PDO($dsn, $user, $pwd, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (Throwable $e) {
    send_json(['error' => 'Failed to connect to DB', 'message' => $e->getMessage()], 500);
  }
}

$action = $_GET['action'] ?? '';

try {
  switch ($action) {
  case 'listRooms':
    $stmt = $pdo->query("SELECT * FROM ChatRoom ORDER BY ChatRoomid DESC");
    send_json($stmt->fetchAll());
    break;

  case 'getMembers':
    $roomId = (int)($_GET['room'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM ChatRoomMember WHERE ChatRoomid=?");
    $stmt->execute([$roomId]);
    send_json($stmt->fetchAll());
    break;

  case 'getMessages':
    $roomId = (int)($_GET['room'] ?? 0);
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    // Fetch messages for room; if `since` provided, filter in PHP to support different DB schemas
    $stmt = $pdo->prepare("SELECT * FROM Message WHERE ChatRoomid=? ORDER BY timestamp ASC");
    $stmt->execute([$roomId]);
    $rows = $stmt->fetchAll();
    if ($since > 0) {
      $filtered = array_filter($rows, function($m) use ($since) {
        if (isset($m['messageid'])) return (int)$m['messageid'] > $since;
        if (isset($m['id'])) return (int)$m['id'] > $since;
        return false;
      });
      send_json(array_values($filtered));
    } else {
      send_json($rows);
    }
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
    $last = $pdo->lastInsertId();
    // Attempt to return the full inserted row (supporting different PK names)
    $fetch = $pdo->prepare("SELECT * FROM Message WHERE messageid = ? OR id = ? LIMIT 1");
    $fetch->execute([$last, $last]);
    $row = $fetch->fetch();
    send_json(['ok'=>true,'id'=>$last,'message'=>$row]);
    break;

  case 'typing':
    // Simple ephemeral typing indicator stored on disk per room. Expects JSON POST {room, sender_type, sender_id}
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $room = isset($data['room']) ? (int)$data['room'] : 0;
    if ($room <= 0) { send_json(['ok'=>false,'error'=>'missing_room']); break; }
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $payload = ['ts'=>time(), 'sender_type'=>$data['sender_type'] ?? null, 'sender_id'=>$data['sender_id'] ?? null];
    file_put_contents($dir . '/typing_room_' . $room . '.json', json_encode($payload));
    send_json(['ok'=>true]);
    break;

  case 'createRoom':
    // Create or return an existing private room between two members
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $creator_type = $data['creator_type'] ?? null;
    $creator_id = isset($data['creator_id']) ? (int)$data['creator_id'] : 0;
    $other_type = $data['other_type'] ?? null;
    $other_id = isset($data['other_id']) ? (int)$data['other_id'] : 0;
    if (!$creator_type || $creator_id <= 0 || !$other_type || $other_id <= 0) {
      send_json(['ok'=>false,'error'=>'missing_fields'], 400);
      break;
    }

    // Try to find an existing private room containing both members
    $qry = "SELECT cr.* FROM ChatRoom cr
             JOIN ChatRoomMember m1 ON m1.ChatRoomid=cr.ChatRoomid AND m1.member_type=? AND m1.memberid=?
             JOIN ChatRoomMember m2 ON m2.ChatRoomid=cr.ChatRoomid AND m2.member_type=? AND m2.memberid=?
             WHERE cr.room_type='private' LIMIT 1";
    $stmt = $pdo->prepare($qry);
    $stmt->execute([$creator_type, $creator_id, $other_type, $other_id]);
    $room = $stmt->fetch();
    if ($room) { send_json(['ok'=>true,'room'=>$room]); }

    // Create new private room
    $roomname = sprintf('private-%s-%d-%s-%d', $creator_type, $creator_id, $other_type, $other_id);
    $ins = $pdo->prepare("INSERT INTO ChatRoom (roomname,description,room_type,created_by_type,created_by_id) VALUES (?,?,?,?,?)");
    $ins->execute([$roomname, '', 'private', $creator_type, $creator_id]);
    $roomId = $pdo->lastInsertId();

    $insM = $pdo->prepare("INSERT INTO ChatRoomMember (ChatRoomid, member_type, memberid) VALUES (?,?,?)");
    try {
      $insM->execute([$roomId, $creator_type, $creator_id]);
      $insM->execute([$roomId, $other_type, $other_id]);
    } catch (Throwable $e) {
      // If unique constraint or other issue, continue — try to fetch room
    }

    $fetch = $pdo->prepare("SELECT * FROM ChatRoom WHERE ChatRoomid = ? LIMIT 1");
    $fetch->execute([$roomId]);
    $newRoom = $fetch->fetch();
    send_json(['ok'=>true,'room'=>$newRoom]);
    break;

  case 'getTyping':
    $room = (int)($_GET['room'] ?? 0);
    $file = __DIR__ . '/tmp/typing_room_' . $room . '.json';
    if (!file_exists($file)) { send_json(['typing'=>false]); break; }
    $data = json_decode(@file_get_contents($file), true) ?: null;
    if (!$data) { send_json(['typing'=>false]); break; }
    // Consider typing active for 6 seconds
    $active = (time() - (int)($data['ts'] ?? 0)) < 6;
    send_json(['typing'=>$active, 'sender'=>$data['sender_type'] ?? null, 'sender_id'=>$data['sender_id'] ?? null]);
    break;

  default:
    send_json(['error'=>'Unknown action']);
  }
} catch (Throwable $e) {
  // Log the error and return JSON with a message for debugging
  error_log('[ChatApi] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
  send_json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
}
