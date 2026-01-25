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

// Compute application root (useful when app is hosted in a subfolder)
$appRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');

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

// Helper: fetch ENUM values for a column (returns array of values or [] on failure)
function get_enum_values(PDO $pdo, $table, $column) {
  try {
    $q = $pdo->prepare("SHOW COLUMNS FROM `" . addslashes($table) . "` LIKE ?");
    $q->execute([$column]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['Type'])) return [];
    $type = $row['Type']; // e.g. enum('text','image','file')
    if (stripos($type, "enum(") !== 0) return [];
    $inside = substr($type, 5, -1);
    $vals = str_getcsv($inside, ',', "'");
    $clean = array_map(function($v){ return trim($v, "'\" "); }, $vals);
    return $clean;
  } catch (Throwable $e) {
    return [];
  }
}

$action = $_GET['action'] ?? '';

try {
  // Block unauthenticated users from using chat endpoints
  $protected = ['listRooms','getMessages','sendMessage','typing','createRoom','markRead','getTotalUnread','addReference','listReferences'];
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
      $rooms = $stmt->fetchAll();
      // Enrich each room with the other participant's display name when possible
      foreach ($rooms as &$r) {
        $roomId = $r['ChatRoomid'] ?? ($r['ChatRoomId'] ?? ($r['id'] ?? null));
        if (!$roomId) continue;
        try {
          $mstmt = $pdo->prepare('SELECT member_type, memberid FROM ChatRoomMember WHERE ChatRoomid=? AND NOT (member_type=? AND memberid=?) LIMIT 1');
          $mstmt->execute([(int)$roomId, $user_type, $user_id]);
          $other = $mstmt->fetch();
          if ($other && isset($other['member_type']) && isset($other['memberid'])) {
            $otype = $other['member_type'];
            $oid = (int)$other['memberid'];
            $name = null;
            switch ($otype) {
              case 'client': $q = $pdo->prepare('SELECT cname AS name FROM Client WHERE clientid=? LIMIT 1'); break;
              case 'designer': $q = $pdo->prepare('SELECT dname AS name FROM Designer WHERE designerid=? LIMIT 1'); break;
              case 'manager': $q = $pdo->prepare('SELECT mname AS name FROM Manager WHERE managerid=? LIMIT 1'); break;
              case 'Contractors': $q = $pdo->prepare('SELECT cname AS name FROM Contractors WHERE contractorid=? LIMIT 1'); break;
              case 'supplier': $q = $pdo->prepare('SELECT sname AS name FROM Supplier WHERE supplierid=? LIMIT 1'); break;
              default: $q = null; break;
            }
            if ($q) { $q->execute([$oid]); $name = $q->fetchColumn(); }
            $r['other_name'] = $name ?: ($otype . ' ' . $oid);
            $r['other_type'] = $otype;
            $r['other_id'] = $oid;
          }
        } catch (Throwable $e) {
          // ignore enrichment failures
        }
        // attach unread count for this requesting user if provided (use last_opened when available)
        try {
          if ($user_type && $user_id) {
            $mstmt2 = $pdo->prepare('SELECT ChatRoomMemberid, last_opened FROM ChatRoomMember WHERE ChatRoomid=? AND member_type=? AND memberid=? LIMIT 1');
            $mstmt2->execute([(int)$roomId, $user_type, $user_id]);
            $memberRow = $mstmt2->fetch(PDO::FETCH_ASSOC);
            $myMemberId = $memberRow['ChatRoomMemberid'] ?? null;
            $lastOpened = $memberRow['last_opened'] ?? null;
            if ($myMemberId) {
              if ($lastOpened) {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM Message WHERE ChatRoomid = ? AND timestamp > ? AND NOT (sender_type = ? AND sender_id = ?)');
                $cnt->execute([(int)$roomId, $lastOpened, $user_type, $user_id]);
              } else {
                // no last_opened: count all messages not sent by this member
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM Message WHERE ChatRoomid = ? AND NOT (sender_type = ? AND sender_id = ?)');
                $cnt->execute([(int)$roomId, $user_type, $user_id]);
              }
              $r['unread'] = (int)$cnt->fetchColumn();
            } else {
              $r['unread'] = 0;
            }
          }
        } catch (Throwable $_e) { $r['unread'] = 0; }
      }
      send_json($rooms);
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
    // augment with sender display name and attach uploaded file metadata when present
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
      // If messages reference an uploaded file via `fileid`, fetch its metadata and expose a compat `attachment` path
      if (!empty($r['fileid'])) {
        try {
          $fstmt = $pdo->prepare('SELECT fileid, uploader_type, uploader_id, filename, filepath, mime, size, uploaded_at FROM UploadedFiles WHERE fileid=? LIMIT 1');
          $fstmt->execute([(int)$r['fileid']]);
          $frow = $fstmt->fetch();
          if ($frow) {
            $r['uploaded_file'] = $frow;
            // backward compatibility: provide `attachment` containing the web-accessible filepath
            if (!isset($r['attachment']) || empty($r['attachment'])) $r['attachment'] = $frow['filepath'];
            // set message_type to 'image' when mime indicates an image (if not already set)
            if (empty($r['message_type']) && !empty($frow['mime']) && strpos($frow['mime'], 'image/') === 0) {
              $r['message_type'] = 'image';
            }
          }
        } catch (Throwable $e) {
          error_log('[ChatApi] failed to fetch uploaded file for message: ' . $e->getMessage());
        }
      }
      // Prefer DB-stored `message_type` when present. If `message_type` indicates
      // a structured payload (e.g., 'design' or 'order'), attempt to parse `content`
      // to expose structured fields to the client. If `message_type` is missing
      // we fall back to parsing `content` for legacy markers (`__share`, `__order`).
      if (!empty($r['content']) && is_string($r['content'])) {
        $maybe = json_decode($r['content'], true);
        if (is_array($maybe)) {
          // If DB explicitly says this is a design/share, honor it
          if (!empty($r['message_type']) && $r['message_type'] === 'design') {
            if (!empty($maybe['share'])) {
              $r['share'] = $maybe['share'];
              if (empty($r['attachment']) && !empty($r['uploaded_file']['filepath'])) $r['attachment'] = $r['uploaded_file']['filepath'];
            }
          }
          // If DB explicitly marks order, parse order payload
          if (!empty($r['message_type']) && $r['message_type'] === 'order') {
            if (!empty($maybe['order']) && is_array($maybe['order'])) {
              $r['order'] = $maybe['order'];
              if (empty($r['attachment']) && !empty($maybe['order']['image'])) $r['attachment'] = $maybe['order']['image'];
            }
          }
          // Backwards-compat: if DB did not include message_type, inspect markers
          if (empty($r['message_type'])) {
            if (isset($maybe['__share']) && !empty($maybe['share'])) {
              $r['share'] = $maybe['share'];
              $r['message_type'] = 'design';
              if (empty($r['attachment']) && !empty($r['uploaded_file']['filepath'])) $r['attachment'] = $r['uploaded_file']['filepath'];
            }
            if (isset($maybe['__order']) && !empty($maybe['order']) && is_array($maybe['order'])) {
              $r['order'] = $maybe['order'];
              $r['message_type'] = 'order';
              if (empty($r['attachment']) && !empty($maybe['order']['image'])) $r['attachment'] = $maybe['order']['image'];
            }
          }
        } else {
          // Not JSON or unknown format: nothing to extract
        }
      }
      // If message_type indicates an order or design and we didn't find structured
      // data above, try to interpret `content` as an ID and resolve it from the DB.
      try {
        if (empty($r['order']) && !empty($r['message_type']) && $r['message_type'] === 'order') {
          $cid = null;
          if (!empty($r['content']) && is_string($r['content']) && preg_match('/^\d+$/', trim($r['content']))) $cid = (int)trim($r['content']);
          if ($cid) {
            $ost = $pdo->prepare("SELECT o.orderid, o.odate, o.clientid, o.designid, o.ostatus, o.gross_floor_area, d.designName FROM `Order` o LEFT JOIN Design d ON o.designid = d.designid WHERE o.orderid = ? LIMIT 1");
            $ost->execute([$cid]);
            $orow = $ost->fetch();
              if ($orow) {
              $r['order'] = [
                'id' => (int)$orow['orderid'],
                'url' => $appRoot . '/client/order_detail.php?orderid=' . (int)$orow['orderid'],
                'designid' => isset($orow['designid']) ? (int)$orow['designid'] : null,
                'title' => $orow['designName'] ?? '' ,
                'status' => $orow['ostatus'] ?? null,
                'gross_floor_area' => isset($orow['gross_floor_area']) ? (float)$orow['gross_floor_area'] : null
              ];
              // attempt to fetch primary design image when available
              try {
                if (!empty($r['order']['designid'])) {
                  $im = $pdo->prepare('SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1');
                  $im->execute([(int)$r['order']['designid']]);
                  $ir = $im->fetch();
                  if ($ir && !empty($ir['image_filename'])) {
                    $r['order']['image'] = $appRoot . '/uploads/designs/' . ltrim($ir['image_filename'], '/');
                  }
                }
              } catch (Throwable $__e) { /* ignore image failures */ }
            }
          }
        }
      } catch (Throwable $__e) { /* ignore resolve failures */ }
      try {
        if (empty($r['share']) && !empty($r['message_type']) && $r['message_type'] === 'design') {
          $did = null;
          if (!empty($r['content']) && is_string($r['content'])) {
            $trimc = trim($r['content']);
            if (preg_match('/^\d+$/', $trimc)) {
              $did = (int)$trimc;
            } else {
              // If content is a URL or contains a query with design id, try to extract it
              if (stripos($trimc, 'http://') === 0 || stripos($trimc, 'https://') === 0) {
                $u = @parse_url($trimc);
                if ($u && !empty($u['query'])) {
                  parse_str($u['query'], $qs);
                  if (!empty($qs['designid'])) $did = (int)$qs['designid'];
                  elseif (!empty($qs['id'])) $did = (int)$qs['id'];
                }
                // try to match /design_detail.php?designid=123 or paths with numeric id
                if (!$did && !empty($u['path'])) {
                  if (preg_match('/design[_-]?detail(?:\.php)?/i', $u['path']) && !empty($u['query'])) {
                    // already attempted via query, but keep for safety
                    if (!empty($qs['designid'])) $did = (int)$qs['designid'];
                  }
                  if (!$did && preg_match('/\/(?:designs?|design-detail|design_detail|client\/design_detail)\/([^\/?#]+)/i', $u['path'], $m)) {
                    if (isset($m[1]) && preg_match('/^(\d+)$/', $m[1])) $did = (int)$m[1];
                  }
                  // fallback: find any numeric segment in path
                  if (!$did && preg_match('/\/(\d+)(?:\/|$)/', $u['path'], $m2)) $did = (int)$m2[1];
                }
              }
            }
          }
          if ($did) {
            $dstmt = $pdo->prepare('SELECT designid, designName, expect_price FROM Design WHERE designid = ? LIMIT 1');
            $dstmt->execute([$did]);
            $drow = $dstmt->fetch();
            if ($drow) {
              // fetch primary image if available
              $img = null;
              try {
                $im = $pdo->prepare('SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1');
                $im->execute([$did]);
                $ir = $im->fetch(); if ($ir && !empty($ir['image_filename'])) $img = '/uploads/designs/' . ltrim($ir['image_filename'], '/');
              } catch (Throwable $__e) {}
              $r['share'] = [
                'title' => $drow['designName'] ?? '',
                'url' => $appRoot . '/client/design_detail.php?designid=' . (int)$drow['designid'],
                'image' => $img,
                'type' => 'design',
                'price' => isset($drow['expect_price']) ? $drow['expect_price'] : null,
                'designid' => (int)$drow['designid']
              ];
              if (empty($r['attachment']) && !empty($img)) $r['attachment'] = $img;
            }
          }
        }
      } catch (Throwable $__e) { /* ignore */ }
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
      $data = $_POST;
      // Prefer explicit IDs for structured payloads (order/design) if provided
      if (!empty($_POST['order_id'])) {
        $content = (string)(int)$_POST['order_id'];
        $message_type = 'order';
      } elseif (!empty($_POST['design_id'])) {
        $content = (string)(int)$_POST['design_id'];
        $message_type = 'design';
      } else {
        $content = $data['content'] ?? '';
      }
      $room = isset($data['room']) ? (int)$data['room'] : 0;
      // validate room exists to avoid FK errors
      if ($room <= 0) { send_json(['error'=>'invalid_room','message'=>'Missing room id'], 400); }
      $chk = $pdo->prepare('SELECT ChatRoomid FROM ChatRoom WHERE ChatRoomid=? LIMIT 1'); $chk->execute([$room]); if (!$chk->fetchColumn()) { send_json(['error'=>'invalid_room','message'=>'Room not found','room'=>$room], 400); }
      $attachmentPath = null;
      // Allow callers to request structured message types such as 'design' or 'order'.
      $message_type = $_POST['message_type'] ?? null;
      // Ensure we only try to insert message_type values supported by DB enum to avoid SQL warnings
      $supportedTypes = get_enum_values($pdo, 'Message', 'message_type');
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
      // If this send contains share metadata (from widget preview), prefer to persist only the design id or the share URL
      if (!empty($_POST['share_title'])) {
        if (!empty($_POST['design_id'])) {
          $content = (string)(int)$_POST['design_id'];
          $message_type = 'design';
        } else {
          // fallback: store the share URL (less data than full JSON blob)
          $content = $_POST['share_url'] ?? ($data['content'] ?? '');
          if (empty($message_type)) $message_type = 'design';
        }
      }
      // insert with message_type and optional uploaded file reference (fileid)
      $allowed_types = ['text','image','file','design','order'];
      $desired = in_array($message_type, $allowed_types) ? $message_type : null;
      if ($desired && in_array($desired, $supportedTypes)) {
        $safeType = $desired;
      } else {
        if ($uploadedFileId && in_array('file', $supportedTypes)) $safeType = 'file';
        elseif (!empty($supportedTypes)) $safeType = $supportedTypes[0];
        else $safeType = 'text';
      }
      if (!empty($uploadedFileId)) {
        $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid,fileid) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$sender_type, $sender_id, $content, $safeType, $room, $uploadedFileId]);
      } else {
        $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid) VALUES (?,?,?,?,?)");
        $stmt->execute([$sender_type, $sender_id, $content, $safeType, $room]);
      }
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $room = isset($data['room']) ? (int)$data['room'] : 0;
        if ($room <= 0) { send_json(['error'=>'invalid_room','message'=>'Missing room id'], 400); }
        $chk = $pdo->prepare('SELECT ChatRoomid FROM ChatRoom WHERE ChatRoomid=? LIMIT 1'); $chk->execute([$room]); if (!$chk->fetchColumn()) { send_json(['error'=>'invalid_room','message'=>'Room not found','room'=>$room], 400); }
        // Support optional external attachment URL in JSON payload. If provided, create an UploadedFiles record
        $uploadedFileId = null;
        if (!empty($data['attachment_url'])) {
          try {
            $afile = $data['attachment_url'];
            $aname = $data['attachment_name'] ?? basename(parse_url($afile, PHP_URL_PATH) ?: $afile);
            $amime = $data['attachment_mime'] ?? null;
            $asize = isset($data['attachment_size']) ? (int)$data['attachment_size'] : null;
            // Try to probe the remote URL for Content-Type/Length to detect images when possible
            try {
              $hdrs = @get_headers($afile, 1);
              if ($hdrs && is_array($hdrs)) {
                // Content-Type may be present; pick last value if array
                $ct = $hdrs['Content-Type'] ?? $hdrs['content-type'] ?? null;
                if (is_array($ct)) $ct = end($ct);
                if ($ct && !$amime) $amime = $ct;
                $cl = $hdrs['Content-Length'] ?? $hdrs['Content-length'] ?? null;
                if (is_array($cl)) $cl = end($cl);
                if ($cl && !$asize) $asize = (int)$cl;
              }
            } catch (Throwable $__e) {
              // ignore probe failures
            }
            // If filename has no extension but we detected an image mime, append a reasonable ext
            $ext = pathinfo($aname, PATHINFO_EXTENSION);
            if (empty($ext) && $amime && strpos($amime, 'image/') === 0) {
              $e = substr($amime, strlen('image/'));
              if ($e === 'jpeg') $e = 'jpg';
              if (preg_match('/^[a-z0-9]+$/i', $e)) $aname .= '.' . $e;
            }
            // As a last resort, if still no extension and URL has id=NUMBER, craft a filename
            if (empty(pathinfo($aname, PATHINFO_EXTENSION))) {
              $q = parse_url($afile, PHP_URL_QUERY) ?: '';
              if (preg_match('/id=(\d+)/', $q, $m)) {
                $aname = 'design' . $m[1] . '.jpg';
                if (!$amime) $amime = 'image/jpeg';
              }
            }
            $insF = $pdo->prepare("INSERT INTO UploadedFiles (uploader_type, uploader_id, filename, filepath, mime, size) VALUES (?,?,?,?,?,?)");
            $insF->execute([$data['sender_type'] ?? null, $data['sender_id'] ?? null, $aname, $afile, $amime, $asize]);
            $uploadedFileId = $pdo->lastInsertId();
          } catch (Throwable $e) {
            error_log('[ChatApi] failed to record external attachment: ' . $e->getMessage());
            $uploadedFileId = null;
          }
        }
        // Respect caller-provided message_type (e.g., 'design') when present; otherwise derive from mime
        $finalMessageType = $data['message_type'] ?? null;
        // Ensure compatibility with DB enum values to avoid warnings/exceptions
        $supportedTypes = get_enum_values($pdo, 'Message', 'message_type');
        // Prefer explicit IDs when provided for structured payloads
        if (!empty($data['order_id'])) {
          $contentForInsert = (string)(int)$data['order_id'];
          $finalMessageType = 'order';
        } elseif (!empty($data['design_id'])) {
          $contentForInsert = (string)(int)$data['design_id'];
          $finalMessageType = 'design';
        } else {
          // When caller included share metadata, persist it into `content` as JSON so reads can detect it later
          $contentForInsert = $data['content'] ?? '';
        }
        if (!empty($data['share_title'])) {
          if (!empty($data['design_id'])) {
            $contentForInsert = (string)(int)$data['design_id'];
            $finalMessageType = 'design';
          } else {
            // fallback: store the share URL only
            $contentForInsert = $data['share_url'] ?? ($contentForInsert ?? '');
            if (empty($finalMessageType)) $finalMessageType = 'design';
          }
        }
        if (!empty($uploadedFileId)) {
          $msgType = 'file';
          if (!empty($amime) && strpos($amime, 'image/') === 0) $msgType = 'image';
          if (empty($finalMessageType)) $finalMessageType = $msgType;
          $allowed_types = ['text','image','file','design','order'];
          $desiredFinal = in_array($finalMessageType, $allowed_types) ? $finalMessageType : null;
          if ($desiredFinal && in_array($desiredFinal, $supportedTypes)) {
            $safeFinal = $desiredFinal;
          } else {
            if (in_array('file', $supportedTypes)) $safeFinal = 'file';
            elseif (!empty($supportedTypes)) $safeFinal = $supportedTypes[0];
            else $safeFinal = 'text';
          }
          $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid,fileid) VALUES (?,?,?,?,?,?)");
          $stmt->execute([
            $data['sender_type'],
            $data['sender_id'],
            $contentForInsert,
            $safeFinal,
            $room,
            $uploadedFileId
          ]);
        } else {
          $allowed_types = ['text','image','file','design','order'];
            if (!empty($finalMessageType)) {
              $desiredFinal = in_array($finalMessageType, $allowed_types) ? $finalMessageType : null;
              if ($desiredFinal && in_array($desiredFinal, $supportedTypes)) {
                $safeFinal = $desiredFinal;
              } else {
                if (!empty($supportedTypes)) $safeFinal = $supportedTypes[0]; else $safeFinal = 'text';
              }
            $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,message_type,ChatRoomid) VALUES (?,?,?,?,?)");
            $stmt->execute([
              $data['sender_type'],
              $data['sender_id'],
              $contentForInsert,
              $safeFinal,
              $room
            ]);
          } else {
            $stmt = $pdo->prepare("INSERT INTO Message (sender_type,sender_id,content,ChatRoomid) VALUES (?,?,?,?)");
            $stmt->execute([
              $data['sender_type'],
              $data['sender_id'],
              $contentForInsert,
              $room
            ]);
          }
        }
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
        if ($frow) {
          $row['uploaded_file'] = $frow;
          if (!isset($row['attachment']) || empty($row['attachment'])) $row['attachment'] = $frow['filepath'];
        }
      } catch (Throwable $e) { error_log('[ChatApi] fetch uploaded file metadata failed: ' . $e->getMessage()); }
    }
    // If this was a design share, attach share metadata so clients can render a card
    // If share metadata was provided (share_title), attach share info so clients can render a card
    if (!empty($data) && (!empty($data['share_title']) || (!empty($data['message_type']) && $data['message_type'] === 'design'))) {
      $row['share'] = [
        'title' => $data['share_title'] ?? ($row['uploaded_file']['filename'] ?? null),
        'url' => $data['share_url'] ?? ($row['content'] ?? null),
        'image' => $row['uploaded_file']['filepath'] ?? ($data['attachment_url'] ?? null),
        'type' => $data['share_type'] ?? null
      ];
      // Do not override the stored message_type (DB may restrict allowed values)
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

  case 'markRead':
    // Mark messages in a room as read for a particular member (create missing MessageRead rows)
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $room = isset($data['room']) ? (int)$data['room'] : 0;
    $member_type = $data['user_type'] ?? ($data['member_type'] ?? null);
    $member_id = isset($data['user_id']) ? (int)$data['user_id'] : (isset($data['member_id']) ? (int)$data['member_id'] : 0);
    if ($room <= 0 || !$member_type || $member_id <= 0) {
      send_json(['ok' => false, 'error' => 'missing_fields'], 400);
      break;
    }
    // find ChatRoomMemberid for this user in the room
    $mm = $pdo->prepare('SELECT ChatRoomMemberid FROM ChatRoomMember WHERE ChatRoomid = ? AND member_type = ? AND memberid = ? LIMIT 1');
    $mm->execute([$room, $member_type, $member_id]);
    $memberRow = $mm->fetchColumn();
    if (!$memberRow) { send_json(['ok' => false, 'error' => 'not_member'], 400); break; }
    $memberChatId = (int)$memberRow;
    // First, update any existing MessageRead rows to mark read
    try {
      $u = $pdo->prepare('UPDATE MessageRead mr JOIN Message m ON mr.messageid = m.messageid SET mr.is_read = 1, mr.read_at = NOW() WHERE mr.ChatRoomMemberid = ? AND m.ChatRoomid = ? AND (mr.is_read = 0 OR mr.read_at IS NULL)');
      $u->execute([$memberChatId, $room]);
      $updated = $u->rowCount();
    } catch (Throwable $e) { $updated = 0; }
    // Next, insert MessageRead rows for messages that don't have an entry for this member (mark them read)
    try {
      $ins = $pdo->prepare('INSERT INTO MessageRead (messageid, ChatRoomMemberid, is_read, read_at)
        SELECT m.messageid, ?, 1, NOW() FROM Message m
        LEFT JOIN MessageRead mr ON mr.messageid = m.messageid AND mr.ChatRoomMemberid = ?
        WHERE m.ChatRoomid = ? AND mr.messagereadid IS NULL');
      $ins->execute([$memberChatId, $memberChatId, $room]);
      $inserted = $ins->rowCount();
    } catch (Throwable $e) { $inserted = 0; }
    // Also update ChatRoomMember.last_opened so future unread counts can use it
    try {
      // ensure column exists — create safely if missing
      $colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'ChatRoomMember' AND COLUMN_NAME = 'last_opened'");
      $colCheck->execute([$db]);
      $hasCol = (int)$colCheck->fetchColumn();
      if (!$hasCol) {
        try { $pdo->exec("ALTER TABLE ChatRoomMember ADD COLUMN last_opened TIMESTAMP NULL DEFAULT NULL"); } catch (Throwable $__e) { /* ignore alter failures */ }
      }
      $u2 = $pdo->prepare('UPDATE ChatRoomMember SET last_opened = NOW() WHERE ChatRoomMemberid = ?');
      $u2->execute([$memberChatId]);
    } catch (Throwable $__e) { /* ignore */ }
    send_json(['ok' => true, 'updated' => (int)$updated, 'inserted' => (int)$inserted]);
    break;

  case 'getTotalUnread':
    // Return sum of unread messages across all rooms for a given user (uses last_opened when available)
    $user_type = $_GET['user_type'] ?? null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if (!$user_type || $user_id <= 0) {
      send_json(['ok'=>false,'error'=>'missing_fields'], 400);
      break;
    }
    try {
      // Join ChatRoomMember to Message and count messages newer than last_opened (or all not sent by member if last_opened NULL)
      $sql = "SELECT COUNT(*) as cnt FROM Message m JOIN ChatRoomMember crm ON crm.ChatRoomid = m.ChatRoomid AND crm.member_type = ? AND crm.memberid = ? WHERE ( (crm.last_opened IS NOT NULL AND m.timestamp > crm.last_opened) OR (crm.last_opened IS NULL AND NOT (m.sender_type = ? AND m.sender_id = ?)) ) AND NOT (m.sender_type = ? AND m.sender_id = ?)";
      $q = $pdo->prepare($sql);
      $q->execute([$user_type, $user_id, $user_type, $user_id, $user_type, $user_id]);
      $row = $q->fetch();
      $total = $row ? (int)$row['cnt'] : 0;
      send_json(['ok'=>true,'total'=> $total]);
    } catch (Throwable $e) {
      send_json(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], 500);
    }
    break;

  case 'addReference':
    // Designer can add a design/message as a reference to the order associated with this room
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $room = isset($data['room']) ? (int)$data['room'] : 0;
    $messageid = isset($data['messageid']) ? (int)$data['messageid'] : null;
    $designid = isset($data['designid']) ? (int)$data['designid'] : null;
    $note = isset($data['note']) ? (string)$data['note'] : null;
    if ($room <= 0) send_json(['ok'=>false,'error'=>'missing_room'],400);
    // require designer role
    $role = $sessionUser['role'] ?? null;
    if (!$role || strtolower($role) !== 'designer') send_json(['ok'=>false,'error'=>'forbidden','message'=>'Designer role required'],403);
    // verify room exists
    $rstmt = $pdo->prepare('SELECT * FROM ChatRoom WHERE ChatRoomid = ? LIMIT 1'); $rstmt->execute([$room]); $rrow = $rstmt->fetch(PDO::FETCH_ASSOC);
    if (!$rrow) send_json(['ok'=>false,'error'=>'invalid_room'],400);
    $roomname = $rrow['roomname'] ?? '';
    $orderId = null;
    if (preg_match('/^order-(\d+)$/i', $roomname, $m)) $orderId = (int)$m[1];
    if (!$orderId && !empty($data['orderid'])) $orderId = (int)$data['orderid'];
    if (!$orderId) send_json(['ok'=>false,'error'=>'no_order_associated','message'=>'Room is not an order room'],400);
    // If designid not provided, try to resolve from message
    if (!$designid && $messageid) {
      try {
        $mstmt = $pdo->prepare('SELECT * FROM Message WHERE messageid = ? LIMIT 1'); $mstmt->execute([$messageid]); $mrow = $mstmt->fetch(PDO::FETCH_ASSOC);
        if ($mrow) {
          // check content for numeric design id
          if (!empty($mrow['content']) && preg_match('/^\d+$/', trim($mrow['content']))) $designid = (int)trim($mrow['content']);
          else {
            $maybe = @json_decode($mrow['content'], true);
            if (is_array($maybe)) {
              if (!empty($maybe['share']['designid'])) $designid = (int)$maybe['share']['designid'];
              elseif (!empty($maybe['designid'])) $designid = (int)$maybe['designid'];
            }
          }
        }
      } catch (Throwable $__e) {}
    }
    // create storage table if missing (also add useful unique keys to prevent duplicates)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS `OrderReference` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `orderid` INT NOT NULL,
        `messageid` INT DEFAULT NULL,
        `designid` INT DEFAULT NULL,
        `added_by_type` VARCHAR(50) DEFAULT NULL,
        `added_by_id` INT DEFAULT NULL,
        `note` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_order_design (orderid, designid),
        UNIQUE KEY ux_order_message (orderid, messageid)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $__e) { /* ignore create failures */ }
    // Determine added_by_id from session (prefers role-specific id fields)
    $addedByType = $sessionUser['role'] ?? null;
    $addedById = null;
    if (!empty($sessionUser) && is_array($sessionUser)) {
      if (isset($sessionUser[strtolower($addedByType) . 'id'])) $addedById = (int)$sessionUser[strtolower($addedByType) . 'id'];
      elseif (isset($sessionUser[$addedByType . 'id'])) $addedById = (int)$sessionUser[$addedByType . 'id'];
      elseif (isset($sessionUser['id'])) $addedById = (int)$sessionUser['id'];
    }
    // check for existing reference for same order+design or order+message
    try {
      $existsQ = $pdo->prepare('SELECT id FROM OrderReference WHERE orderid = ? AND ((designid IS NOT NULL AND designid = ?) OR (messageid IS NOT NULL AND messageid = ?)) LIMIT 1');
      $existsQ->execute([(int)$orderId, $designid ?: 0, $messageid ?: 0]);
      $ex = $existsQ->fetch(PDO::FETCH_ASSOC);
      if ($ex && !empty($ex['id'])) {
        send_json(['ok'=>true,'already'=>true,'id'=>$ex['id'],'orderid'=>$orderId,'messageid'=>$messageid,'designid'=>$designid]);
      }
    } catch (Throwable $__e) { /* ignore existence check errors and continue to insert */ }

    try {
      $ins = $pdo->prepare('INSERT INTO OrderReference (orderid, messageid, designid, added_by_type, added_by_id, note) VALUES (?,?,?,?,?,?)');
      $ins->execute([(int)$orderId, $messageid ?: null, $designid ?: null, $addedByType ?: null, $addedById ?: null, $note ?: null]);
      $refId = $pdo->lastInsertId();
      send_json(['ok'=>true,'id'=>$refId,'orderid'=>$orderId,'messageid'=>$messageid,'designid'=>$designid]);
    } catch (Throwable $e) {
      // if insert failed due to unique constraint, return existing id (best-effort)
      if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        try {
          $existsQ2 = $pdo->prepare('SELECT id FROM OrderReference WHERE orderid = ? AND ((designid IS NOT NULL AND designid = ?) OR (messageid IS NOT NULL AND messageid = ?)) LIMIT 1');
          $existsQ2->execute([(int)$orderId, $designid ?: 0, $messageid ?: 0]);
          $ex2 = $existsQ2->fetch(PDO::FETCH_ASSOC);
          if ($ex2 && !empty($ex2['id'])) send_json(['ok'=>true,'already'=>true,'id'=>$ex2['id'],'orderid'=>$orderId]);
        } catch (Throwable $_e) {}
      }
      send_json(['ok'=>false,'error'=>'db_error','message'=>$e->getMessage()],500);
    }
    break;

  case 'listReferences':
    // Return references for an order (by orderid or room)
    $data = json_decode(file_get_contents('php://input'), true) ?: $_GET ?: $_POST;
    $room = isset($data['room']) ? (int)$data['room'] : 0;
    $orderId = isset($data['orderid']) ? (int)$data['orderid'] : null;
    if (!$orderId && $room) {
      try {
        $rstmt = $pdo->prepare('SELECT roomname FROM ChatRoom WHERE ChatRoomid = ? LIMIT 1'); $rstmt->execute([$room]); $rr = $rstmt->fetch(PDO::FETCH_ASSOC);
        if ($rr && !empty($rr['roomname']) && preg_match('/^order-(\d+)$/i', $rr['roomname'], $m)) $orderId = (int)$m[1];
      } catch (Throwable $__e) { }
    }
    if (!$orderId) send_json(['ok'=>true,'references'=>[]]);
    try {
      $q = $pdo->prepare('SELECT id, orderid, messageid, designid, added_by_type, added_by_id, note, created_at FROM OrderReference WHERE orderid = ? ORDER BY created_at ASC');
      $q->execute([(int)$orderId]);
      $rows = $q->fetchAll(PDO::FETCH_ASSOC);
      send_json(['ok'=>true,'references'=>$rows]);
    } catch (Throwable $e) {
      // If table missing, return empty list rather than error
      send_json(['ok'=>true,'references'=>[]]);
    }
    break;

  default:
    send_json(['error'=>'Unknown action']);
  }
} catch (Throwable $e) {
  // Log the error and return JSON with a message for debugging
  error_log('[ChatApi] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
  send_json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
}
