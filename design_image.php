<?php
// ==============================
// File: design_image.php
// Serve design image using the database (Design.design) as the filename/path.
// Place actual image files under /uploads/designs/
// ==============================
require_once __DIR__ . '/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$stmt = $mysqli->prepare("SELECT design FROM Design WHERE designid=? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('DB error');
}
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($designFile);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Not found');
}
$stmt->close();

// Only allow a safe basename to avoid directory traversal
$base = basename((string)$designFile);
if ($base === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $base) !== 1) {
    http_response_code(404);
    exit('Invalid image');
}

$abs = __DIR__ . '/uploads/designs/' . $base;
if (!is_file($abs)) {
    // Optional fallback image
    $fallback = __DIR__ . '/uploads/designs/placeholder.jpg';
    if (is_file($fallback)) {
        $abs = $fallback;
    } else {
        http_response_code(404);
        exit('Image not found');
    }
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $abs);
finfo_close($finfo);

$allowed = ['image/jpeg','image/png','image/webp','image/gif'];
if (!in_array($mime, $allowed, true)) {
    // Default to JPEG if unknown
    $mime = 'image/jpeg';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=86400, immutable');
readfile($abs);
