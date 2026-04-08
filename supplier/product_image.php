<?php
// ==============================
// File: supplier/product_image.php
// ==============================
// 因為在 supplier 資料夾內，config.php 在上一層
require_once __DIR__ . '/../config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 查詢圖片檔名
$stmt = $mysqli->prepare("SELECT image FROM Product WHERE productid=? LIMIT 1");
if (!$stmt) {
    // 簡單的錯誤處理圖片 (1x1 像素)
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    exit;
}
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($imageName);
$stmt->fetch();
$stmt->close();

// 設定圖片路徑 (uploads 資料夾在上一層的 uploads/products/)
$baseDir = __DIR__ . '/../uploads/products/';
$fallback = $baseDir . 'placeholder.jpg';
$file = $baseDir . ($imageName ?? 'default');

// 檢查檔案是否存在
if (empty($imageName) || !file_exists($file)) {
    if (file_exists($fallback)) {
        $file = $fallback;
    } else {
        // 如果連預設圖都沒有，產生透明圖
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        exit;
    }
}

// 輸出圖片
$mime = mime_content_type($file);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
readfile($file);
?>