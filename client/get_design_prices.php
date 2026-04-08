<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
try {
    // Accept two optional params: 'designs' and 'products' as comma-separated ids
    $designsRaw = isset($_GET['designs']) ? trim((string)$_GET['designs']) : '';
    $productsRaw = isset($_GET['products']) ? trim((string)$_GET['products']) : '';
    $sum = 0.0;
    // Sum design expect_price
    if ($designsRaw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $designsRaw)));
        $ids = [];
        foreach ($parts as $p) { if (ctype_digit($p)) $ids[] = (int)$p; }
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $sql = "SELECT IFNULL(SUM(expect_price),0) AS refs_sum FROM Design WHERE designid IN ($placeholders)";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $sum += (float)($row['refs_sum'] ?? 0.0);
                $stmt->close();
            }
        }
    }
    // Sum product price; support optional products_qty param with id:qty pairs
    $productsQtyRaw = isset($_GET['products_qty']) ? trim((string)$_GET['products_qty']) : '';
    $productMap = [];
    if ($productsRaw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $productsRaw)));
        foreach ($parts as $p) { if (ctype_digit($p)) $productMap[(int)$p] = ($productMap[(int)$p] ?? 0) + 1; }
    }
    if ($productsQtyRaw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $productsQtyRaw)));
        foreach ($parts as $entry) {
            // expected: id or id:qty
            $bits = explode(':', $entry);
            if (!ctype_digit($bits[0])) continue;
            $id = (int)$bits[0]; $q = isset($bits[1]) && ctype_digit($bits[1]) ? (int)$bits[1] : 1;
            $productMap[$id] = ($productMap[$id] ?? 0) + $q;
        }
    }
    if (!empty($productMap)) {
        $pids = array_keys($productMap);
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $types = str_repeat('i', count($pids));
        $sql = "SELECT productid, IFNULL(price,0) AS price FROM Product WHERE productid IN ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$pids);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $pid = (int)$r['productid'];
                $price = (float)($r['price'] ?? 0);
                $qty = $productMap[$pid] ?? 0;
                $sum += $price * $qty;
            }
            $stmt->close();
        }
    }
    $out = ['ok'=>true,'refs_sum'=>$sum];
    // If detail requested, include line items for designs and products
    $detail = isset($_GET['detail']) && ($_GET['detail'] === '1' || $_GET['detail'] === 'true' || $_GET['detail'] === 'yes');
    if ($detail) {
        $out['designs'] = [];
        $out['products'] = [];
        // fetch designs details
        if ($designsRaw !== '') {
            $parts = array_filter(array_map('trim', explode(',', $designsRaw)));
            $ids = [];
            foreach ($parts as $p) { if (ctype_digit($p)) $ids[] = (int)$p; }
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $sql = "SELECT d.designid, d.designName AS title, d.expect_price AS price, (SELECT di.image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC LIMIT 1) AS image_filename FROM Design d WHERE d.designid IN ($placeholders)";
                $stmt = $mysqli->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param($types, ...$ids);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $img = trim((string)($r['image_filename'] ?? ''));
                        $imageUrl = $img !== '' ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/uploads/designs/' . ltrim($img, '/') : null;
                        $out['designs'][] = [
                            'designid' => (int)$r['designid'],
                            'title' => $r['title'] ?? '',
                            'price' => (float)($r['price'] ?? 0),
                            'image' => $imageUrl
                        ];
                    }
                    $stmt->close();
                }
            }
        }
        // fetch products details
        if (!empty($productMap)) {
            $pids = array_keys($productMap);
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $types = str_repeat('i', count($pids));
            $sql = "SELECT p.productid, p.pname AS title, IFNULL(p.price,0) AS price FROM Product p WHERE p.productid IN ($placeholders)";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$pids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $pid = (int)$r['productid'];
                    $out['products'][] = [
                        'productid' => $pid,
                        'title' => $r['title'] ?? '',
                        'price' => (float)($r['price'] ?? 0),
                        'qty' => (int)($productMap[$pid] ?? 1)
                    ];
                }
                $stmt->close();
            }
        }
    }
    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()]);
}
