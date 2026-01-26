<?php
require_once __DIR__ . '/config.php';
session_start();

$productid = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($productid <= 0) {
    http_response_code(404);
    die('Product not found.');
}

$psql = "SELECT p.*, s.sname, s.semail, s.stel
         FROM Product p
         JOIN Supplier s ON p.supplierid = s.supplierid
         WHERE p.productid = ?";
$stmt = $mysqli->prepare($psql);
$stmt->bind_param("i", $productid);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) {
    http_response_code(404);
    die('Product not found.');
}

$colorImages = [];
$colors = [];
$colorImageSql = "SELECT color, image FROM ProductColorImage WHERE productid = ? ORDER BY id ASC";
$colorImageStmt = $mysqli->prepare($colorImageSql);
$colorImageStmt->bind_param("i", $productid);
$colorImageStmt->execute();
$colorImageResult = $colorImageStmt->get_result();
while ($row = $colorImageResult->fetch_assoc()) {
    $colorImages[$row['color']] = $row['image'];
    $colors[] = $row['color'];
}
$colorImageStmt->close();
if (empty($colors) && !empty($product['color'])) {
    $colorArray = array_map('trim', explode(',', $product['color']));
    $colors = array_filter($colorArray);
}

$other_sql = "SELECT productid, pname, price FROM Product WHERE supplierid=? AND productid<>? LIMIT 6";
$other_stmt = $mysqli->prepare($other_sql);
$other_stmt->bind_param("ii", $product['supplierid'], $productid);
$other_stmt->execute();
$others = $other_stmt->get_result();

$liked = false;
$user_type = $_SESSION['user']['role'] ?? null;
$user_id = 0;
if (!empty($user_type)) {
    $user_type = strtolower($user_type);
    if ($user_type === 'client') $user_id = (int)($_SESSION['user']['clientid'] ?? 0);
    elseif ($user_type === 'designer') $user_id = (int)($_SESSION['user']['designerid'] ?? 0);
    elseif ($user_type === 'manager') $user_id = (int)($_SESSION['user']['managerid'] ?? 0);
}

if ($user_id > 0 && !empty($user_type)) {
    $ulike_sql = "SELECT COUNT(*) AS cnt FROM UserLike WHERE user_type = ? AND user_id = ? AND item_type = 'product' AND item_id = ?";
    $ulike_stmt = $mysqli->prepare($ulike_sql);
    if ($ulike_stmt) {
        $ulike_stmt->bind_param("sii", $user_type, $user_id, $productid);
        $ulike_stmt->execute();
        $res = $ulike_stmt->get_result()->fetch_assoc();
        $liked = ($res['cnt'] ?? 0) > 0;
    } else {
        // Fallback to legacy ProductLike for clients
        if ($user_type === 'client') {
            $clientid = (int)($_SESSION['user']['clientid'] ?? 0);
            if ($clientid > 0) {
                $like_check_sql = "SELECT COUNT(*) as count FROM ProductLike WHERE clientid = ? AND productid = ?";
                $like_check_stmt = $mysqli->prepare($like_check_sql);
                if ($like_check_stmt) {
                    $like_check_stmt->bind_param("ii", $clientid, $productid);
                    $like_check_stmt->execute();
                    $like_result = $like_check_stmt->get_result()->fetch_assoc();
                    $liked = ($like_result['count'] ?? 0) > 0;
                }
            }
        }
    }
}

$backUrl = 'furniture_dashboard.php';
if (isset($_GET['from']) && $_GET['from'] === 'my_likes') {
    $backUrl = 'my_likes.php';
} else {
    $category = strtolower(trim($product['category'] ?? ''));
    if ($category === 'material') {
        $backUrl = 'material_dashboard.php';
    } elseif ($category === 'furniture') {
        $backUrl = 'furniture_dashboard.php';
    } else {
        $backUrl = '';
    }
}

$mainImg = null;
if (!empty($colorImages)) {
    $firstImage = reset($colorImages);
    if ($firstImage)
        $mainImg = 'uploads/products/' . htmlspecialchars($firstImage);
}
if (!$mainImg)
    $mainImg = 'uploads/products/placeholder.jpg';

function colorNameToHex($colorInput)
{ /* function body same as client version, omitted for brevity */
    $colorInput = trim($colorInput);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/i', $colorInput))
        return strtoupper($colorInput);
    $colorMap = ['red' => '#FF0000', 'blue' => '#0000FF', 'green' => '#008000', 'yellow' => '#FFFF00', 'black' => '#000000', 'white' => '#FFFFFF', 'gray' => '#808080', 'grey' => '#808080', 'orange' => '#FFA500', 'purple' => '#800080', 'pink' => '#FFC0CB'];
    $colorLower = strtolower($colorInput);
    return $colorMap[$colorLower] ?? '#999999';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - <?= htmlspecialchars($product['pname']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
    <?php include_once __DIR__ . '/includes/header.php'; ?>

    <main>
        <div class="product-detail-wrapper">
            <div class="product-image-wrapper">
                <img src="<?= htmlspecialchars($mainImg) ?>" alt="<?= htmlspecialchars($product['pname']) ?>">
            </div>
            <div class="product-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="handleBack()" aria-label="Back">← Back</button>
                </div>
                <div class="product-title"><?= htmlspecialchars($product['pname']) ?></div>
                <div class="product-price">HK$<?= number_format((float) $product['price']) ?></div>
                <div class="product-stats">
                    <div class="likes-count">
                        <button class="heart-icon <?= $liked ? 'liked' : '' ?>" id="likeHeart"
                            data-productid="<?= (int) $product['productid'] ?>"
                            title="Like this product" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
                            <i class="<?= $liked ? 'fas' : 'far' ?> fa-heart" aria-hidden="true"></i>
                        </button>
                        <span id="likeCount"><?= (int) $product['likes'] ?></span> Likes
                    </div>
                </div>

                <?php if (!empty($colors)): ?>
                    <div class="color-selection-section">
                        <label class="color-selection-label"><i class="fas fa-palette me-2"></i>Select Color:</label>
                        <?php if (count($colors) <= 5): ?>
                            <div class="color-options" id="colorOptions">
                                <?php foreach ($colors as $index => $color):
                                    $hexColor = colorNameToHex($color); ?>
                                    <button type="button" class="color-button <?= $index === 0 ? 'selected' : '' ?>"
                                        data-color="<?= htmlspecialchars($color) ?>" data-hex="<?= htmlspecialchars($hexColor) ?>"
                                        onclick="selectColor(this)" title="<?= htmlspecialchars($color) ?>"><span
                                            class="color-swatch"
                                            style="background-color: <?= htmlspecialchars($hexColor) ?>;"></span></button>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <select class="color-select-dropdown" id="colorSelect" onchange="selectColorDropdown(this)">
                                <option value="">-- Choose a color --</option>
                                <?php foreach ($colors as $color):
                                    $hexColor = colorNameToHex($color); ?>
                                    <option value="<?= htmlspecialchars($color) ?>" data-hex="<?= htmlspecialchars($hexColor) ?>">
                                        <?= htmlspecialchars($color) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input type="hidden" id="selectedColor" value="<?= htmlspecialchars($colors[0] ?? '') ?>">
                        <input type="hidden" id="selectedColorHex"
                            value="<?= htmlspecialchars(colorNameToHex($colors[0] ?? '')) ?>">
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

    <?php if ($others->num_rows > 0): ?>
        <section class="detail-gallery" aria-label="Other Products from This Supplier"
            style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
            <h3 style="color: #2c3e50; font-weight: 600; margin-bottom: 1rem; font-size: 1.3rem;">Other Products from
                <?= htmlspecialchars($product['sname']) ?>
            </h3>
            <div class="detail-gallery-images">
                <?php while ($r = $others->fetch_assoc()): ?>
                    <?php
                    $relatedColorSql = "SELECT image FROM ProductColorImage WHERE productid = ? ORDER BY id ASC LIMIT 1";
                    $relatedColorStmt = $mysqli->prepare($relatedColorSql);
                    $relatedColorStmt->bind_param("i", $r['productid']);
                    $relatedColorStmt->execute();
                    $relatedColorResult = $relatedColorStmt->get_result();
                    $relatedImage = $relatedColorResult->fetch_assoc();
                    $imageSrc = ($relatedImage && $relatedImage['image']) ? 'uploads/products/' . htmlspecialchars($relatedImage['image']) : 'uploads/products/placeholder.jpg';
                    ?>
                    <a href="product_detail.php?id=<?= (int) $r['productid'] ?>">
                        <img src="<?= $imageSrc ?>" alt="<?= htmlspecialchars($r['pname']) ?>">
                    </a>
                <?php endwhile; ?>
            </div>
        </section>
    <?php endif; ?>

    <script>
        function selectColor(button) { const allButtons = document.querySelectorAll('.color-button'); allButtons.forEach(btn => btn.classList.remove('selected')); button.classList.add('selected'); document.getElementById('selectedColor').value = button.dataset.color; document.getElementById('selectedColorHex').value = button.dataset.hex; updateProductImage(button.dataset.color); }
        function selectColorDropdown(select) { const selectedColor = select.value; if (selectedColor) { const selectedOption = select.options[select.selectedIndex]; const selectedHex = selectedOption.dataset.hex; document.getElementById('selectedColor').value = selectedColor; document.getElementById('selectedColorHex').value = selectedHex; updateProductImage(selectedColor); } }
        function updateProductImage(color) { const colorImages = <?= json_encode($colorImages) ?>; const productImg = document.querySelector('.product-image-wrapper img'); let imageFile = null; if (colorImages) { if (colorImages[color]) imageFile = colorImages[color]; else { const colorLower = color.toLowerCase(); for (const key in colorImages) { if (key.toLowerCase() === colorLower) { imageFile = colorImages[key]; break; } } } } if (imageFile) productImg.src = 'uploads/products/' + imageFile; else productImg.src = 'uploads/products/placeholder.jpg'; }
        function handleBack() { window.location.href = '<?= htmlspecialchars($backUrl) ?>'; }
        (function(){
            const heartBtn = document.getElementById('likeHeart');
            if (!heartBtn) return;
            heartBtn.addEventListener('click', function(e){
                e.preventDefault();
                const productid = this.dataset.productid;
                const btn = this;
                const formData = new FormData();
                formData.append('action', 'toggle_like');
                formData.append('type', 'product');
                formData.append('id', productid);

                fetch('api/handle_like.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            const icon = btn.querySelector('i');
                            if (data.liked) {
                                btn.classList.add('liked');
                                if (icon) { icon.classList.remove('far'); icon.classList.add('fas'); }
                                btn.setAttribute('aria-pressed', 'true');
                            } else {
                                btn.classList.remove('liked');
                                if (icon) { icon.classList.remove('fas'); icon.classList.add('far'); }
                                btn.setAttribute('aria-pressed', 'false');
                            }
                            const lc = document.getElementById('likeCount'); if (lc) lc.textContent = data.likes;
                        } else {
                            alert('Error: ' + (data.message || 'Failed to update like'));
                        }
                    }).catch(err => { console.error(err); alert('An error occurred while updating the like.'); });
            });
        })();
    </script>

    <?php include __DIR__ . '/Public/chat_widget.php'; ?>
</body>

</html>