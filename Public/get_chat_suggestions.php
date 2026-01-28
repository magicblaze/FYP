<?php
// Returns JSON: { recommended: [...], others: [...] }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
// Determine user id consistently with `Public/chat_widget.php`
$uid = 0;
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
  $roleRaw = $_SESSION['user']['role'] ?? 'client';
  $role = strtolower($roleRaw ?: 'client');
  $uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['clientid'] ?? $_SESSION['user']['id'] ?? 0);
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
$liked_products = [];
$recommended_products = [];

$designerIds = [];
$dl = @$mysqli->prepare("SELECT DISTINCT d.designerid FROM UserLike ul JOIN Design d ON ul.item_id = d.designid WHERE ul.user_type = ? AND ul.user_id = ? AND ul.item_type = 'design' LIMIT 20");
if ($dl) {
  $dl->bind_param('si', $role, $uid);
  $dl->execute();
  $dres = $dl->get_result();
  while ($row = $dres->fetch_assoc()) { $designerIds[] = (int)$row['designerid']; }
  $dl->close();
}

// 2) Suppliers from liked products (use unified UserLike)
$supplierIds = [];
$pl = @$mysqli->prepare("SELECT DISTINCT p.supplierid FROM UserLike ul JOIN Product p ON ul.item_id = p.productid WHERE ul.user_type = ? AND ul.user_id = ? AND ul.item_type = 'product' LIMIT 20");
if ($pl) {
  $pl->bind_param('si', $role, $uid);
  $pl->execute();
  $pres = $pl->get_result();
  while ($row = $pres->fetch_assoc()) { $supplierIds[] = (int)$row['supplierid']; }
  $pl->close();
}

// Deduplicate liked targets to avoid repeated entries
$designerIds = array_values(array_unique(array_filter($designerIds)));
$supplierIds = array_values(array_unique(array_filter($supplierIds)));

// Get users that already have chatrooms with the current user (to exclude from suggestions)
// Store by role for precise exclusion
$existingDesigners = [];
$existingSuppliers = [];
$existingManagers = [];
$existingContractors = [];
$existingClients = [];

try {
  // Query existing chats with all member types
  $q = "SELECT DISTINCT m.member_type, m.memberid FROM ChatRoom cr 
        JOIN ChatRoomMember m1 ON m1.ChatRoomid = cr.ChatRoomid AND m1.member_type = '$role' AND m1.memberid = $uid
        JOIN ChatRoomMember m ON m.ChatRoomid = cr.ChatRoomid AND (m.member_type != '$role' OR m.memberid != $uid)
        WHERE cr.room_type = 'private'";
  
  if ($r = @$mysqli->query($q)) {
    while ($row = $r->fetch_assoc()) {
      $memberId = (int)$row['memberid'];
      $memberType = strtolower((string)$row['member_type']);
      // Normalize Contractors (with capital C from DB) to lowercase for comparison
      if ($memberType === 'contractors') $memberType = 'contractor';
      
      if ($memberType === 'designer') $existingDesigners[] = $memberId;
      else if ($memberType === 'supplier') $existingSuppliers[] = $memberId;
      else if ($memberType === 'manager') $existingManagers[] = $memberId;
      else if ($memberType === 'contractor') $existingContractors[] = $memberId;
      else if ($memberType === 'client') $existingClients[] = $memberId;
    }
    $r->close();
  }
} catch (Exception $e) {
  // Silently ignore if query fails
}



// Build recommended list from gathered designer and supplier ids
if (count($designerIds)) {
  $in = implode(',', array_map('intval', $designerIds));
  $q = "SELECT designerid, dname FROM Designer WHERE designerid IN ($in) LIMIT 20";
  if ($r = $mysqli->query($q)) {
    while ($u = $r->fetch_assoc()) {
      $did = (int)$u['designerid'];
      if (in_array($did, $existingDesigners, true)) continue; // skip if already chatting
      $recommended[] = ['id'=> $did, 'name'=>$u['dname'] ?? '', 'role'=>'designer', 'avatar'=> ''];
    }
    $r->close();
  }
}
if (count($supplierIds)) {
  $in = implode(',', array_map('intval', $supplierIds));
  $q = "SELECT supplierid, sname FROM Supplier WHERE supplierid IN ($in) LIMIT 20";
  if ($r = $mysqli->query($q)) {
    while ($u = $r->fetch_assoc()) {
      $sid = (int)$u['supplierid'];
      if (in_array($sid, $existingSuppliers, true)) continue; // skip if already chatting
      $recommended[] = ['id'=> $sid, 'name'=>$u['sname'] ?? '', 'role'=>'supplier', 'avatar'=> ''];
    }
    $r->close();
  }
}

// 3) Fill 'others' with available users from all roles (exclude recommended, existing chatroom users for that role, and the current user)
$excludeRecommended = array_map('intval', array_column($recommended, 'id'));
$excludeRecommended = array_filter($excludeRecommended);
$othersLimit = 50;

// Query designers (exclude current user if they are a designer, and exclude existing chat designers)
$desWhere = 'WHERE 1=1';
$desExclude = $excludeRecommended;
if (!empty($existingDesigners)) $desExclude = array_merge($desExclude, $existingDesigners);
$desExclude = array_unique(array_filter($desExclude));
if (count($desExclude)) {
  $desWhere .= ' AND designerid NOT IN (' . implode(',', $desExclude) . ')';
}
if ($role === 'designer') {
  $desWhere .= ' AND designerid != ' . (int)$uid;
}
$qdes = "SELECT designerid AS id, dname AS name, status, 'designer' AS role FROM Designer $desWhere ORDER BY RAND() LIMIT $othersLimit";
if ($r = $mysqli->query($qdes)) {
  while ($row = $r->fetch_assoc()) {
    $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
  }
  $r->close();
}

// Query suppliers (exclude current user if they are a supplier)
if (count($others) < $othersLimit) {
  $need = $othersLimit - count($others);
  $supWhere = 'WHERE 1=1';
  $supExclude = $excludeRecommended;
  if (!empty($existingSuppliers)) $supExclude = array_merge($supExclude, $existingSuppliers);
  $supExclude = array_unique(array_filter($supExclude));
  if (count($supExclude)) {
    $supWhere .= ' AND supplierid NOT IN (' . implode(',', $supExclude) . ')';
  }
  if ($role === 'supplier') {
    $supWhere .= ' AND supplierid != ' . (int)$uid;
  }
  $qsup = "SELECT supplierid AS id, sname AS name, 'supplier' AS role FROM Supplier $supWhere ORDER BY RAND() LIMIT $need";
  if ($r2 = $mysqli->query($qsup)) {
    while ($row = $r2->fetch_assoc()) {
      $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
    }
    $r2->close();
  }
}

// Query managers (exclude current user if they are a manager)
if (count($others) < $othersLimit) {
  $need = $othersLimit - count($others);
  $mgWhere = 'WHERE 1=1';
  $mgExclude = $excludeRecommended;
  if (!empty($existingManagers)) $mgExclude = array_merge($mgExclude, $existingManagers);
  $mgExclude = array_unique(array_filter($mgExclude));
  if (count($mgExclude)) {
    $mgWhere .= ' AND managerid NOT IN (' . implode(',', $mgExclude) . ')';
  }
  if ($role === 'manager') {
    $mgWhere .= ' AND managerid != ' . (int)$uid;
  }
  $qmg = "SELECT managerid AS id, mname AS name, 'manager' AS role FROM Manager $mgWhere ORDER BY RAND() LIMIT $need";
  if ($r3 = $mysqli->query($qmg)) {
    while ($row = $r3->fetch_assoc()) {
      $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
    }
    $r3->close();
  }
}

// Query contractors (exclude current user if they are a contractor)
if (count($others) < $othersLimit) {
  $need = $othersLimit - count($others);
  $ctWhere = 'WHERE 1=1';
  $ctExclude = $excludeRecommended;
  if (!empty($existingContractors)) $ctExclude = array_merge($ctExclude, $existingContractors);
  $ctExclude = array_unique(array_filter($ctExclude));
  if (count($ctExclude)) {
    $ctWhere .= ' AND contractorid NOT IN (' . implode(',', $ctExclude) . ')';
  }
  if ($role === 'contractor') {
    $ctWhere .= ' AND contractorid != ' . (int)$uid;
  }
  $qct = "SELECT contractorid AS id, cname AS name, 'contractor' AS role FROM Contractors $ctWhere ORDER BY RAND() LIMIT $need";
  if ($r4 = $mysqli->query($qct)) {
    while ($row = $r4->fetch_assoc()) {
      $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
    }
    $r4->close();
  }
}

// Query clients (exclude current user if they are a client)
if (count($others) < $othersLimit) {
  $need = $othersLimit - count($others);
  $clWhere = 'WHERE 1=1';
  $clExclude = $excludeRecommended;
  if (!empty($existingClients)) $clExclude = array_merge($clExclude, $existingClients);
  $clExclude = array_unique(array_filter($clExclude));
  if (count($clExclude)) {
    $clWhere .= ' AND clientid NOT IN (' . implode(',', $clExclude) . ')';
  }
  if ($role === 'client') {
    $clWhere .= ' AND clientid != ' . (int)$uid;
  }
  $qcl = "SELECT clientid AS id, cname AS name, 'client' AS role FROM Client $clWhere ORDER BY RAND() LIMIT $need";
  if ($r5 = $mysqli->query($qcl)) {
    while ($row = $r5->fetch_assoc()) {
      $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
    }
    $r5->close();
  }
}

// --- Recommended designs logic: return up to 5 designs the user may like ---
try {
  $clientId = (int)$uid; // uid is role-specific per earlier logic
  // build absolute image URLs early so liked_designs can use it
  $baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
  $likedIds = [];
  $dl = $mysqli->prepare("SELECT item_id FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'design'");
  if ($dl) {
    $dl->bind_param('si', $role, $clientId);
    $dl->execute();
    $ldr = $dl->get_result();
    while ($row = $ldr->fetch_assoc()) $likedIds[] = (int)$row['item_id'];
    $dl->close();
  }

  // Fetch liked designs details for use in share grid
  if (count($likedIds)) {
    $in = implode(',', array_map('intval', $likedIds));
    $qlike_sql = "SELECT d.designid, d.designName AS title, d.expect_price AS price, d.likes, d.designerid,
                     (SELECT di.image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC LIMIT 1) AS image_filename
                   FROM Design d WHERE d.designid IN ($in) LIMIT 20";
    $qlike = $mysqli->query($qlike_sql);
    if ($qlike) {
      while ($r = $qlike->fetch_assoc()) {
        $id = (int)$r['designid'];
        $img = trim((string)($r['image_filename'] ?? ''));
        if ($img === '') continue;
          $liked_designs[] = [
            'designid' => $id,
            'id' => $id,
            'design_id' => $id,
            // canonical fields for clients: item_id + item_type
            'item_id' => $id,
            'item_type' => 'design',
            'title' => $r['title'] ?? '',
            'price' => $r['price'] ?? null,
            'likes' => (int)($r['likes'] ?? 0),
            'designerid' => (int)($r['designerid'] ?? 0),
            'image' => $baseUrl . '/uploads/designs/' . ltrim($img, '/'),
            'url' => $baseUrl . '/design_detail.php?designid=' . $id,
            'share' => [
              'design_id' => $id,
              'id' => $id,
              'item_id' => $id,
              'item_type' => 'design',
              'title' => $r['title'] ?? '',
              'image' => $baseUrl . '/uploads/designs/' . ltrim($img, '/'),
              'url' => $baseUrl . '/design_detail.php?designid=' . $id,
              'type' => 'design'
            ]
          ];
      }
      $qlike->close();
    }
  }

  // --- Liked products: similar approach using UserLike + Product + ProductColorImage ---
    $likedProductIds = []; 
  $pl = $mysqli->prepare("SELECT item_id FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'product'");
  if ($pl) {
    $pl->bind_param('si', $role, $clientId);
    $pl->execute();
    $pr = $pl->get_result();
    while ($row = $pr->fetch_assoc()) $likedProductIds[] = (int)$row['item_id'];
    $pl->close();
  }

  if (count($likedProductIds)) {
    $in = implode(',', array_map('intval', $likedProductIds));
    $qprod = $mysqli->query("SELECT productid, pname, price, likes, supplierid FROM Product WHERE productid IN ($in) LIMIT 50");
    $products = [];
    if ($qprod) {
      while ($r = $qprod->fetch_assoc()) $products[] = $r;
      $qprod->close();
    }
    // Get first color image for each product
    $images = [];
    $qimg = $mysqli->query("SELECT productid, image FROM ProductColorImage WHERE productid IN ($in) ORDER BY productid, id ASC");
    if ($qimg) {
      while ($ri = $qimg->fetch_assoc()) {
        $pid = (int)$ri['productid'];
        if (!isset($images[$pid])) $images[$pid] = $ri['image'];
      }
      $qimg->close();
    }
    foreach ($products as $p) {
      $pid = (int)$p['productid'];
      $imgPath = isset($images[$pid]) ? $images[$pid] : null;
      $liked_products[] = [
        'productid' => $pid,
        'id' => $pid,
        'product_id' => $pid,
        // canonical fields for clients: item_id + item_type
        'item_id' => $pid,
        'item_type' => 'product',
        'title' => $p['pname'] ?? '',
        'price' => $p['price'] ?? null,
        'likes' => (int)($p['likes'] ?? 0),
        'supplierid' => (int)($p['supplierid'] ?? 0),
        'image' => $imgPath ? ($baseUrl . '/uploads/products/' . ltrim($imgPath, '/')) : null,
        'url' => $baseUrl . '/product_detail.php?id=' . $pid,
        'share' => [
          'product_id' => $pid,
          'id' => $pid,
          'item_id' => $pid,
          'item_type' => 'product',
          'title' => $p['pname'] ?? '',
          'image' => $imgPath ? ($baseUrl . '/uploads/products/' . ltrim($imgPath, '/')) : null,
          'url' => $baseUrl . '/product_detail.php?id=' . $pid,
          'type' => 'product'
        ]
      ];
    }
  }

  $debugInfo['liked_count_fetched'] = count($likedIds);

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
    $q = "SELECT d.designid, d.designName AS designName, d.designerid, d.expect_price AS price, d.likes,
             (SELECT di.image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC LIMIT 1) AS image_filename
           FROM Design d WHERE $where ORDER BY likes DESC LIMIT 5";
    $resq = $mysqli->query($q);
    if ($resq) {
      while ($d = $resq->fetch_assoc()) $designs[] = $d;
      $resq->close();
    }
  }

  if (!count($designs)) {
    // fallback: popular designs not already liked
    $q2 = "SELECT d.designid, d.designName AS designName, d.designerid, d.expect_price AS price, d.likes,
              (SELECT di.image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC LIMIT 1) AS image_filename
            FROM Design d WHERE 1 $exclClause ORDER BY likes DESC LIMIT 5";
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
    // Only include designs that have an explicit DesignImage (no fallback)
    $img = trim((string)($d['image_filename'] ?? ''));
    if ($img === '') continue;
    // try to fetch designerid if present in row
    $designerid = isset($d['designerid']) ? (int)$d['designerid'] : 0;
    $recommended_designs[] = [
      'designid' => $id,
      'id' => $id,
      'design_id' => $id,
      'designerid' => $designerid,
      'image' => $baseUrl . '/uploads/designs/' . ltrim($img, '/'),
      'price' => $d['price'] ?? null,
      'likes' => (int)($d['likes'] ?? 0),
      'url' => $baseUrl . '/design_detail.php?designid=' . $id
    ];
    // preview-ready share payload
    $recommended_designs[count($recommended_designs)-1]['share'] = [
      'design_id' => $id,
      'id' => $id,
      'item_id' => $id,
      'item_type' => 'design',
      'title' => $d['designName'] ?? ($d['title'] ?? ''),
      'image' => $baseUrl . '/uploads/designs/' . ltrim($img, '/'),
      'url' => $baseUrl . '/design_detail.php?designid=' . $id,
      'type' => 'design'
    ];
  }
} catch (Exception $e) {
  // ignore and return empty
}

$out = ['recommended'=>$recommended, 'others'=>$others, 'recommended_designs'=>$recommended_designs, 'liked_designs'=>$liked_designs, 'liked_products'=>$liked_products, 'recommended_products'=>$recommended_products];
// Optional debug output for troubleshooting from the browser: ?debug=1
if (isset($_GET['debug']) && $_GET['debug']) {
  $out['debug'] = [
    'resolved_uid' => $uid,
    'liked_count' => count($liked_designs),
    'liked_ids' => array_map(function($d){ return isset($d['designid']) ? (int)$d['designid'] : null; }, $liked_designs)
  ];
}
// If no liked or recommended designs/users were found, include a minimal debug block
// so the widget can display why the lists are empty (helpful when called from browser).
// keep response minimal; do not include internal debug data in normal responses

echo json_encode($out);

