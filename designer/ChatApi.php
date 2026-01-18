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
// Load global config from project root
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
  // fallback to same-dir config for older setups
  $configPath = __DIR__ . '/config.php';
}
require_once $configPath;

// Basic sanity: ensure DB variables exist
if (!isset($hostname, $user, $pwd, $db)) {
  send_json(['error' => 'missing_config', 'message' => 'Database configuration not found'], 500);
}

// Require session for API actions that modify or read user-specific data
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sessionUser = $_SESSION['user'] ?? null;

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
  // Block unauthenticated users from using chat endpoints
  $protected = ['listRooms','getMessages','sendMessage','typing','createRoom'];
  if (in_array($action, $protected, true) && !$sessionUser) {
    send_json(['error' => 'unauthorized', 'message' => 'Authentication required'], 401);
  }
  switch ($action) {
  case 'listRooms':
    $user_type = $_GET['user_type'] ?? null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($user_type && $user_id > 0) {
      $stmt = $pdo->prepare("SELECT cr.* FROM ChatRoom cr JOIN ChatRoomMember m ON m.ChatRoomid=cr.ChatRoomid WHERE m.member_type=? AND m.memberid=? ORDER BY cr.ChatRoomid DESC");
      $stmt->execute([$user_type, $user_id]);
      send_json($stmt->fetchAll());
    } else {
      $stmt = $pdo->query("SELECT * FROM ChatRoom ORDER BY ChatRoomid DESC");
      send_json($stmt->fetchAll());
    }
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
    // augment with sender display name
    foreach ($rows as &$r) {
      $r['sender_name'] = null;
      $sid = isset($r['sender_id']) ? (int)$r['sender_id'] : 0;
      switch ($r['sender_type'] ?? '') {
        case 'client':
          $q = $pdo->prepare('SELECT cname AS name FROM Client WHERE clientid=? LIMIT 1'); $q->execute([$sid]); $n = $q->fetchColumn(); break;
        case 'designer':
          $q = $pdo->prepare('SELECT dname AS name FROM Designer WHERE designerid=? LIMIT 1'); $q->execute([$sid]); $n = $q->fetchColumn(); break;
        case 'manager':
          $q = $pdo->prepare('SELECT mname AS name FROM Manager WHERE managerid=? LIMIT 1'); $q->execute([$sid]); $n = $q->fetchColumn(); break;
        case 'Contractors':
          $q = $pdo->prepare('SELECT cname AS name FROM Contractors WHERE contractorid=? LIMIT 1'); $q->execute([$sid]); $n = $q->fetchColumn(); break;
        case 'supplier':
          $q = $pdo->prepare('SELECT sname AS name FROM Supplier WHERE supplierid=? LIMIT 1'); $q->execute([$sid]); $n = $q->fetchColumn(); break;
        default:
          $n = null; break;
      }
      if ($n) $r['sender_name'] = $n; else $r['sender_name'] = ($r['sender_type'] ?? '') . ' ' . ($r['sender_id'] ?? '');
    }
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
    // Support both JSON POST and multipart/form-data file uploads
    $isMultipart = !empty($_FILES) || (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false);
    if ($isMultipart) {
      $sender_type = $_POST['sender_type'] ?? null;
      $sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;
      $content = $_POST['content'] ?? '';
      $room = isset($_POST['room']) ? (int)$_POST['room'] : 0;
      // validate room exists to avoid FK errors
      if ($room <= 0) { send_json(['error'=>'invalid_room','message'=>'Missing room id'], 400); }
      $chk = $pdo->prepare('SELECT ChatRoomid FROM ChatRoom WHERE ChatRoomid=? LIMIT 1'); $chk->execute([$room]); if (!$chk->fetchColumn()) { send_json(['error'=>'invalid_room','message'=>'Room not found','room'=>$room], 400); }
      $attachmentPath = null;
      $message_type = $_POST['message_type'] ?? 'file';
      if (!empty($_FILES['attachment'])) {
        $up = $_FILES['attachment'];
        if ($up['error'] !== UPLOAD_ERR_OK) {
          $errMap = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
          ];
          $msg = $errMap[$up['error']] ?? 'Unknown upload error';
          send_json(['error'=>'upload_failed','code'=>$up['error'],'message'=>$msg], 400);
        }
        if (is_uploaded_file($up['tmp_name'])) {
          $uploadsDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
          $chatDir = $uploadsDir . '/chat';
          if (!is_dir($chatDir) && !@mkdir($chatDir, 0755, true)) {
            send_json(['error'=>'upload_failed','message'=>'Unable to create upload directory'], 500);
          }
          $ext = pathinfo($up['name'], PATHINFO_EXTENSION);
          $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($up['name'], PATHINFO_FILENAME));
          $fname = time() . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
          $dest = $chatDir . '/' . $fname;
          if (!@move_uploaded_file($up['tmp_name'], $dest)) {
            send_json(['error'=>'upload_failed','message'=>'move_uploaded_file failed'], 500);
          }
          // Compute a web-accessible path relative to document root so clients can fetch the file correctly.
          $abs = str_replace('\\','/', realpath($dest) ?: $dest);
          $docRoot = str_replace('\\','/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
          if ($docRoot && stripos($abs, $docRoot) === 0) {
            $webPath = '/' . ltrim(substr($abs, strlen($docRoot)), '/');
          } else {
            // Fallback: path relative to project root (one level up from __DIR__)
            $projRoot = str_replace('\\','/', realpath(__DIR__ . '/..') ?: '');
            if ($projRoot && stripos($abs, $projRoot) === 0) {
              $webPath = '/' . trim(basename($projRoot) . '/' . ltrim(substr($abs, strlen($projRoot)), '/'), '/');
              $webPath = '/' . ltrim($webPath, '/');
            } else {
              // as last resort use uploads/chat relative path
              $webPath = '/uploads/chat/' . $fname;
            }
          }
          $attachmentPath = $webPath;
          // record uploaded file metadata in UploadedFiles table
          try {
            $insF = $pdo->prepare("INSERT INTO UploadedFiles (uploader_type, uploader_id, filename, filepath, mime, size) VALUES (?,?,?,?,?,?)");
            $insF->execute([$sender_type, $sender_id, $up['name'], $attachmentPath, $up['type'] ?? null, isset($up['size']) ? (int)$up['size'] : null]);
            $uploadedFileId = $pdo->lastInsertId();
          } catch (Throwable $e) {
            // log but do not block the message insert; include no file id
            error_log('[ChatApi] Failed to record uploaded file metadata: ' . $e->getMessage());
            $uploadedFileId = null;
          }
          // infer image type if not provided
          if ($message_type === 'file') {
            $lower = strtolower($ext);
            if (in_array($lower, ['png','jpg','jpeg','gif','webp','bmp'])) $message_type = 'image';
          }
        }
      }
      // insert with message_type and optional attachment
      if ($attachmentPath) {
        $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid,attachment) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$sender_type, $sender_id, $content, $message_type, $room, $attachmentPath]);
      } else {
        $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid) VALUES (?,?,?,?,?)");
        $stmt->execute([$sender_type, $sender_id, $content, ($message_type?:'text'), $room]);
      }
    } else {
      $data = json_decode(file_get_contents('php://input'), true);
      $room = isset($data['room']) ? (int)$data['room'] : 0;
      if ($room <= 0) { send_json(['error'=>'invalid_room','message'=>'Missing room id'], 400); }
      $chk = $pdo->prepare('SELECT ChatRoomid FROM ChatRoom WHERE ChatRoomid=? LIMIT 1'); $chk->execute([$room]); if (!$chk->fetchColumn()) { send_json(['error'=>'invalid_room','message'=>'Room not found','room'=>$room], 400); }
      $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,ChatRoomid) VALUES (?,?,?,?)");
      $stmt->execute([
        $data['sender_type'],
        $data['sender_id'],
        $data['content'],
        $room
      ]);
    }
    $last = $pdo->lastInsertId();
    // Attempt to return the full inserted row (use messageid PK)
    $fetch = $pdo->prepare("SELECT * FROM Message WHERE messageid = ? LIMIT 1");
    $fetch->execute([$last]);
    $row = $fetch->fetch();
    // attach sender_name for client convenience
    if ($row) {
      $sid = isset($row['sender_id']) ? (int)$row['sender_id'] : 0;
      switch ($row['sender_type'] ?? '') {
        case 'client': $q = $pdo->prepare('SELECT cname AS name FROM Client WHERE clientid=? LIMIT 1'); break;
        case 'designer': $q = $pdo->prepare('SELECT dname AS name FROM Designer WHERE designerid=? LIMIT 1'); break;
        case 'manager': $q = $pdo->prepare('SELECT mname AS name FROM Manager WHERE managerid=? LIMIT 1'); break;
        case 'Contractors': $q = $pdo->prepare('SELECT cname AS name FROM Contractors WHERE contractorid=? LIMIT 1'); break;
        case 'supplier': $q = $pdo->prepare('SELECT sname AS name FROM Supplier WHERE supplierid=? LIMIT 1'); break;
        default: $q = null; break;
      }
      if ($q) { $q->execute([$sid]); $n = $q->fetchColumn(); $row['sender_name'] = $n ?: (($row['sender_type'] ?? '') . ' ' . ($row['sender_id'] ?? '')); }
      else { $row['sender_name'] = ($row['sender_type'] ?? '') . ' ' . ($row['sender_id'] ?? ''); }
    }
    // If we recorded an uploaded file id earlier, attach its id and metadata to response
    if (isset($uploadedFileId) && $uploadedFileId) {
      try {
        $fstmt = $pdo->prepare('SELECT fileid, uploader_type, uploader_id, filename, filepath, mime, size, uploaded_at FROM UploadedFiles WHERE fileid=? LIMIT 1');
        $fstmt->execute([$uploadedFileId]);
        $frow = $fstmt->fetch();
        if ($frow) $row['uploaded_file'] = $frow;
      } catch (Throwable $e) { error_log('[ChatApi] fetch uploaded file metadata failed: ' . $e->getMessage()); }
    }
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
