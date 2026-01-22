<?php
// Returns JSON: { recommended: [...], others: [...] }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
// Determine user id consistently with `Public/chat_widget.php`
$uid = 0;
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
  $role = $_SESSION['user']['role'] ?? 'client';
  $uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);
}
if (!$uid) {
  echo json_encode(['error'=>'not_logged_in']);
  exit;
}
// Database connection using config.php if available
// Ensure we don't overwrite a connection created by config.php
$mysqli = null;
@include_once __DIR__ . '/../config.php';
// config.php in this project defines `$mysqli` (see config.php). Prefer that.
if (isset($mysqli) && $mysqli instanceof mysqli) {
  // use the connection from config.php
} else {
  // Try other common connection variables
  if (isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
  } else {
    // Attempt minimal connection if constants exist
    if (function_exists('mysqli_connect')) {
      $host = defined('DB_HOST') ? DB_HOST : ($_ENV['DB_HOST'] ?? '127.0.0.1');
      $user = defined('DB_USER') ? DB_USER : ($_ENV['DB_USER'] ?? 'root');
      $pass = defined('DB_PASS') ? DB_PASS : ($_ENV['DB_PASS'] ?? '');
      $name = defined('DB_NAME') ? DB_NAME : ($_ENV['DB_NAME'] ?? '');
      if ($name !== '') {
        $tmp = @new mysqli($host, $user, $pass, $name);
        if (!($tmp && $tmp->connect_errno)) $mysqli = $tmp;
      }
    }
    // As a final fallback, try including config.php again via path (some environments require it)
    if (!$mysqli) {
      $cfgPath = __DIR__ . '/../config.php';
      if (file_exists($cfgPath)) {
        include_once $cfgPath; // may define $mysqli or $conn
        if (isset($mysqli) && $mysqli instanceof mysqli) {
          // ok
        } elseif (isset($conn) && $conn instanceof mysqli) {
          $mysqli = $conn;
        }
      }
    }
  }
}

if (!$mysqli) {
  echo json_encode(['error'=>'db_connection_failed']); exit;
}

$recommended = [];
$others = [];
$recommended_designs = [];
 $liked_designs = [];


// Strategy:
// 1) Find designers or suppliers associated with user's liked designs/products
//    -> assume likes are stored in 'likes' table with columns: userid, item_type, item_id, target_user_id (best-effort)
// 2) If not available, try to find designers from liked designs via joins

// 1) Try to get distinct target users from likes table
$likesQry = "SELECT DISTINCT target_user_id FROM likes WHERE user_id = ? AND target_user_id IS NOT NULL LIMIT 20";
if ($stmt = $mysqli->prepare($likesQry)) {
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  $ids = [];
  while ($r = $res->fetch_row()) { if ($r[0]) $ids[] = (int)$r[0]; }
  $stmt->close();
  if (count($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    // prepare dynamic statement
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, role, avatar FROM users WHERE id IN ($in) LIMIT 20";
    $stmt2 = $mysqli->prepare($sql);
    if ($stmt2) {
      // bind params dynamically
      $ref = [];
      $ref[] = & $types;
      foreach ($ids as $k => $v) $ref[] = & $ids[$k];
      call_user_func_array([$stmt2, 'bind_param'], $ref);
      $stmt2->execute();
      $r2 = $stmt2->get_result();
      while ($u = $r2->fetch_assoc()) {
        $recommended[] = ['id'=> (int)$u['id'], 'name'=>$u['name'] ?? '', 'role'=>$u['role'] ?? '', 'avatar'=>$u['avatar'] ?? ''];
      }
      $stmt2->close();
    }
  }
}

// 2) Fill 'others' with some users (excluding current user and recommended)
$exclude = array_map('intval', array_column($recommended, 'id'));
$exclude[] = (int)$uid;
$where = '';
if (count($exclude)) {
  $where = 'WHERE id NOT IN (' . implode(',', $exclude) . ')';
}
$sql2 = "SELECT id, name, role, avatar FROM users $where LIMIT 40";
$res2 = $mysqli->query($sql2);
if ($res2) {
  while ($u = $res2->fetch_assoc()) {
    $others[] = ['id'=> (int)$u['id'], 'name'=>$u['name'] ?? '', 'role'=>$u['role'] ?? '', 'avatar'=>$u['avatar'] ?? ''];
  }
}

// --- Recommended designs logic: return up to 5 designs the user may like ---
try {
  $clientId = (int)$uid; // uid is role-specific per earlier logic
  // build absolute image URLs early so liked_designs can use it
  $baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
  $likedIds = [];
  $dl = $mysqli->prepare("SELECT designid FROM DesignLike WHERE clientid = ?");
  if ($dl) {
    $dl->bind_param('i', $clientId);
    $dl->execute();
    $ldr = $dl->get_result();
    while ($row = $ldr->fetch_assoc()) $likedIds[] = (int)$row['designid'];
    $dl->close();
  }

  // Fetch liked designs details for use in share grid
  if (count($likedIds)) {
    $in = implode(',', array_map('intval', $likedIds));
    $qlike = $mysqli->query("SELECT designid, dname, price, likes, designerid FROM Design WHERE designid IN ($in) LIMIT 20");
    if ($qlike) {
      while ($r = $qlike->fetch_assoc()) {
        $id = (int)$r['designid'];
        $liked_designs[] = [
          'designid' => $id,
          'title' => $r['dname'] ?? '',
          'price' => $r['price'] ?? null,
          'likes' => (int)($r['likes'] ?? 0),
          'designerid' => (int)($r['designerid'] ?? 0),
          'image' => $baseUrl . '/design_image.php?id=' . $id,
          'url' => $baseUrl . '/client/design_detail.php?designid=' . $id
        ];
      }
      $qlike->close();
    }
  }

  $tagCandidates = [];
  if (count($likedIds)) {
    $in = implode(',', array_map('intval', $likedIds));
    $qt = $mysqli->query("SELECT tag FROM Design WHERE designid IN ($in)");
    if ($qt) {
      while ($r = $qt->fetch_assoc()) {
        $t = trim($r['tag'] ?? '');
        if ($t !== '') {
          foreach (explode(',', $t) as $tok) {
            $tok = trim($tok);
            if ($tok) $tagCandidates[] = $tok;
          }
        }
      }
      $qt->close();
    }
  }

  // build query for matching tags
  $designs = [];
  $excluded = $likedIds;
  $excluded[] = $clientId; // harmless but keeps array non-empty
  $exclClause = '';
  if (count($likedIds)) $exclClause = 'AND designid NOT IN (' . implode(',', array_map('intval',$likedIds)) . ')';

  if (count($tagCandidates)) {
    // limit to top 5 by likes
    $conds = [];
    foreach (array_slice(array_unique($tagCandidates),0,6) as $tk) {
      $tk = $mysqli->real_escape_string($tk);
      $conds[] = "tag LIKE '%{$tk}%'";
    }
    $where = '(' . implode(' OR ', $conds) . ') ' . $exclClause;
    $q = "SELECT designid, price, likes FROM Design WHERE $where ORDER BY likes DESC LIMIT 5";
    $resq = $mysqli->query($q);
    if ($resq) {
      while ($d = $resq->fetch_assoc()) $designs[] = $d;
      $resq->close();
    }
  }

  if (!count($designs)) {
    // fallback: popular designs not already liked
    $q2 = "SELECT designid, price, likes FROM Design WHERE 1 $exclClause ORDER BY likes DESC LIMIT 5";
    $r2 = $mysqli->query($q2);
    if ($r2) {
      while ($d = $r2->fetch_assoc()) $designs[] = $d;
      $r2->close();
    }
  }

  // build absolute image URLs
  $baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
  foreach ($designs as $d) {
    $id = (int)$d['designid'];
    // try to fetch designerid if present in row
    $designerid = isset($d['designerid']) ? (int)$d['designerid'] : 0;
    $recommended_designs[] = [
      'designid' => $id,
      'designerid' => $designerid,
      'image' => $baseUrl . '/design_image.php?id=' . $id,
      'price' => $d['price'] ?? null,
      'likes' => (int)($d['likes'] ?? 0),
      'url' => $baseUrl . '/client/design_detail.php?designid=' . $id
    ];
  }
} catch (Exception $e) {
  // ignore and return empty
}

$out = ['recommended'=>$recommended, 'others'=>$others, 'recommended_designs'=>$recommended_designs, 'liked_designs'=>$liked_designs];
// Optional debug output for troubleshooting from the browser: ?debug=1
if (isset($_GET['debug']) && $_GET['debug']) {
  $out['debug'] = [
    'resolved_uid' => $uid,
    'liked_count' => count($liked_designs),
    'liked_ids' => array_map(function($d){ return isset($d['designid']) ? (int)$d['designid'] : null; }, $liked_designs)
  ];
}
echo json_encode($out);

