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

// Strategy: build recommendations from the existing schema
// - Designers from designs the user liked (DesignLike)
// - Suppliers from products the user liked (ProductLike)
// - Recommended designs/products from Design/Product tables

// 1) Designers from liked designs
$designerIds = [];
$dl = @$mysqli->prepare("SELECT DISTINCT d.designerid FROM DesignLike dl JOIN Design d ON dl.designid = d.designid WHERE dl.clientid = ? LIMIT 20");
if ($dl) {
  $dl->bind_param('i', $uid);
  $dl->execute();
  $dres = $dl->get_result();
  while ($row = $dres->fetch_assoc()) { $designerIds[] = (int)$row['designerid']; }
  $dl->close();
}

// 2) Suppliers from liked products
$supplierIds = [];
$pl = @$mysqli->prepare("SELECT DISTINCT p.supplierid FROM ProductLike pl JOIN Product p ON pl.productid = p.productid WHERE pl.clientid = ? LIMIT 20");
if ($pl) {
  $pl->bind_param('i', $uid);
  $pl->execute();
  $pres = $pl->get_result();
  while ($row = $pres->fetch_assoc()) { $supplierIds[] = (int)$row['supplierid']; }
  $pl->close();
}

// Build recommended list from gathered designer and supplier ids
if (count($designerIds)) {
  $in = implode(',', array_map('intval', $designerIds));
  $q = "SELECT designerid, dname FROM Designer WHERE designerid IN ($in) LIMIT 20";
  if ($r = $mysqli->query($q)) {
    while ($u = $r->fetch_assoc()) {
      $recommended[] = ['id'=> (int)$u['designerid'], 'name'=>$u['dname'] ?? '', 'role'=>'designer', 'avatar'=> ''];
    }
    $r->close();
  }
}
if (count($supplierIds)) {
  $in = implode(',', array_map('intval', $supplierIds));
  $q = "SELECT supplierid, sname FROM Supplier WHERE supplierid IN ($in) LIMIT 20";
  if ($r = $mysqli->query($q)) {
    while ($u = $r->fetch_assoc()) {
      $recommended[] = ['id'=> (int)$u['supplierid'], 'name'=>$u['sname'] ?? '', 'role'=>'supplier', 'avatar'=> ''];
    }
    $r->close();
  }
}

// 3) Fill 'others' with available designers and suppliers (exclude any already recommended)
$exclude = array_map('intval', array_column($recommended, 'id'));
// Note: $uid is a client id, not comparable with designer/supplier ids, but keep for safety
$exclude = array_filter($exclude);
$othersLimit = 40;
// Query designers
$desWhere = '';
if (count($exclude)) {
  $desWhere = 'WHERE designerid NOT IN (' . implode(',', $exclude) . ')';
}
$qdes = "SELECT designerid AS id, dname AS name, 'designer' AS role FROM Designer $desWhere LIMIT $othersLimit";
if ($r = $mysqli->query($qdes)) {
  while ($row = $r->fetch_assoc()) {
    $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
  }
  $r->close();
}
// If we still need more, query suppliers
if (count($others) < $othersLimit) {
  $need = $othersLimit - count($others);
  $supWhere = '';
  if (count($exclude)) {
    $supWhere = 'WHERE supplierid NOT IN (' . implode(',', $exclude) . ')';
  }
  $qsup = "SELECT supplierid AS id, sname AS name, 'supplier' AS role FROM Supplier $supWhere LIMIT $need";
  if ($r2 = $mysqli->query($qsup)) {
    while ($row = $r2->fetch_assoc()) {
      $others[] = ['id'=> (int)$row['id'], 'name'=>$row['name'] ?? '', 'role'=>$row['role'], 'avatar'=>''];
    }
    $r2->close();
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
    $in = implode(',', array_map('intval', $likedIds));
    $qlike_sql = "SELECT designid, design AS title, expect_price AS price, likes, designerid FROM Design WHERE designid IN ($in) LIMIT 20";
    $qlike = $mysqli->query($qlike_sql);
    if ($qlike) {
      while ($r = $qlike->fetch_assoc()) {
        $id = (int)$r['designid'];
        $liked_designs[] = [
          'designid' => $id,
          'title' => $r['title'] ?? '',
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

  // --- Liked products: similar approach using ProductLike + Product + ProductColorImage ---
  $likedProductIds = [];
  $pl = $mysqli->prepare("SELECT productid FROM ProductLike WHERE clientid = ?");
  if ($pl) {
    $pl->bind_param('i', $clientId);
    $pl->execute();
    $pr = $pl->get_result();
    while ($row = $pr->fetch_assoc()) $likedProductIds[] = (int)$row['productid'];
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
        'title' => $p['pname'] ?? '',
        'price' => $p['price'] ?? null,
        'likes' => (int)($p['likes'] ?? 0),
        'supplierid' => (int)($p['supplierid'] ?? 0),
        'image' => $imgPath ? ($baseUrl . '/uploads/products/' . ltrim($imgPath, '/')) : null,
        'url' => $baseUrl . '/client/product_detail.php?id=' . $pid
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
    $q = "SELECT designid, expect_price AS price, likes FROM Design WHERE $where ORDER BY likes DESC LIMIT 5";
    $resq = $mysqli->query($q);
    if ($resq) {
      while ($d = $resq->fetch_assoc()) $designs[] = $d;
      $resq->close();
    }
  }

  if (!count($designs)) {
    // fallback: popular designs not already liked
    $q2 = "SELECT designid, expect_price AS price, likes FROM Design WHERE 1 $exclClause ORDER BY likes DESC LIMIT 5";
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

