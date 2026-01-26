<?php
// ==============================
// File: product-detail.php (UPDATED with Color Selection Feature - Visual Color Blocks)
// Purpose: Display product details with color selection functionality showing color blocks
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// Check if user is logged in
if (empty($_SESSION['user'])) {
    $redirect = 'product_detail.php' . (isset($_GET['id']) ? ('?id=' . urlencode((string)$_GET['id'])) : '');
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$productid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productid <= 0) { http_response_code(404); die('Product not found.'); }

$psql = "SELECT p.*, s.sname, s.semail, s.stel
         FROM Product p
         JOIN Supplier s ON p.supplierid = s.supplierid
         WHERE p.productid = ?";
$stmt = $mysqli->prepare($psql);
$stmt->bind_param("i", $productid);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) { http_response_code(404); die('Product not found.'); }

    // Get color images from ProductColorImage table (regardless of Product.color field)
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
    
    // If no color images found, try to parse colors from Product.color field
    if (empty($colors) && !empty($product['color'])) {
        $colorArray = array_map('trim', explode(',', $product['color']));
        $colors = array_filter($colorArray);
    }

// Get other products from the same supplier
$other_sql = "SELECT productid, pname, price FROM Product WHERE supplierid=? AND productid<>? LIMIT 6";
$other_stmt = $mysqli->prepare($other_sql);
$other_stmt->bind_param("ii", $product['supplierid'], $productid);
$other_stmt->execute();
$others = $other_stmt->get_result();

// Check if current user has liked this product
$clientid = (int)($_SESSION['user']['clientid'] ?? 0);
$liked = false;
if ($clientid > 0) {
    $like_check_sql = "SELECT COUNT(*) as count FROM ProductLike WHERE clientid = ? AND productid = ?";
    $like_check_stmt = $mysqli->prepare($like_check_sql);
    $like_check_stmt->bind_param("ii", $clientid, $productid);
    $like_check_stmt->execute();
    $like_result = $like_check_stmt->get_result()->fetch_assoc();
    $liked = $like_result['count'] > 0;
}

// Determine back button destination based on referrer and category
$backUrl = 'furniture_dashboard.php'; // Default destination

if (isset($_GET['from']) && $_GET['from'] === 'my_likes') {
    // If coming from my_likes, always go back to my_likes
    $backUrl = 'my_likes.php';
} else {
    // Otherwise, determine dashboard based on product category
    $category = strtolower(trim($product['category'] ?? ''));
    if ($category === 'material') {
        $backUrl = 'material_dashboard.php';
    } elseif ($category === 'furniture') {
        $backUrl = 'furniture_dashboard.php';
    } else {
        // Default to design dashboard for other categories or designs
        $backUrl = 'design_dashboard.php';
    }
}

// Get the first color's image from ProductColorImage table
$mainImg = null;
if (!empty($colorImages)) {
    // Get the first image from colorImages array
    $firstImage = reset($colorImages);
        if ($firstImage) {
        $mainImg = 'uploads/products/' . htmlspecialchars($firstImage);
    }
}
// Fallback to placeholder if no image found
if (!$mainImg) {
    $mainImg = 'uploads/products/placeholder.jpg';
}

// Function to convert color name or hex code to hex code
// Supports both formats: "red" -> "#FF0000" and "#FF0000" -> "#FF0000"
function colorNameToHex($colorInput) {
    $colorInput = trim($colorInput);
    
    // If input is already a valid hex code, return it
    if (preg_match('/^#[0-9A-Fa-f]{6}$/i', $colorInput)) {
        return strtoupper($colorInput);
    }
    
    // Otherwise, treat it as a color name
    $colorMap = [
        'red' => '#FF0000',
        'blue' => '#0000FF',
        'green' => '#008000',
        'yellow' => '#FFFF00',
        'black' => '#000000',
        'white' => '#FFFFFF',
        'gray' => '#808080',
        'grey' => '#808080',
        'orange' => '#FFA500',
        'purple' => '#800080',
        'pink' => '#FFC0CB',
        'brown' => '#A52A2A',
        'navy' => '#000080',
        'teal' => '#008080',
        'cyan' => '#00FFFF',
        'magenta' => '#FF00FF',
        'silver' => '#C0C0C0',
        'gold' => '#FFD700',
        'beige' => '#F5F5DC',
        'khaki' => '#F0E68C',
        'maroon' => '#800000',
        'olive' => '#808000',
        'lime' => '#00FF00',
        'aqua' => '#00FFFF',
        'turquoise' => '#40E0D0',
        'coral' => '#FF7F50',
        'salmon' => '#FA8072',
        'peach' => '#FFDAB9',
        'lavender' => '#E6E6FA',
        'plum' => '#DDA0DD',
        'indigo' => '#4B0082',
        'violet' => '#EE82EE',
        'tan' => '#D2B48C',
        'cream' => '#FFFDD0',
        'ivory' => '#FFFFF0',
        'linen' => '#FAF0E6',
        'natural wood' => '#8B7355',
        'oak' => '#8B7355',
        'walnut' => '#5C4033',
        'cherry' => '#8B0000',
        'maple' => '#A0826D',
        'birch' => '#D2B48C',
        'pine' => '#A0826D',
        'ash' => '#B2BEB5',
        'ebony' => '#3B2F2F',
        'mahogany' => '#C04000',
        'teak' => '#B8860B',
        'bamboo' => '#6B8E23',
        'light gray' => '#D3D3D3',
        'light grey' => '#D3D3D3',
        'dark gray' => '#A9A9A9',
        'dark grey' => '#A9A9A9',
        'charcoal' => '#36454F',
        'slate' => '#708090',
        'cream white' => '#FFFDD0',
        'off-white' => '#F5F5F5',
        'warm white' => '#FFF8DC',
        'cool white' => '#F0F8FF',
        'midnight' => '#191970',
        'forest' => '#228B22',
        'sea' => '#2E8B57',
        'sky' => '#87CEEB',
        'sand' => '#C2B280',
        'stone' => '#928E85',
        'concrete' => '#A7A9AC',
        'metal' => '#757575',
        'copper' => '#B87333',
        'bronze' => '#CD7F32',
        'brass' => '#B5A642',
    ];
    
    $colorLower = strtolower($colorInput);
    return isset($colorMap[$colorLower]) ? $colorMap[$colorLower] : '#999999';
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
    <style>
        .product-detail-wrapper {
            display: flex;
            gap: 2rem;
            align-items: stretch;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .product-image-wrapper {
            flex: 0 0 auto;
            width: 500px;
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .product-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-panel {
            flex: 0 0 400px;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            display: flex;
            flex-direction: column;
        }

        .back-button {
            margin-bottom: 1.5rem;
        }

        .product-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .product-price {
            font-size: 1.8rem;
            color: #e74c3c;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .product-stats {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        .likes-count {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .heart-icon {
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
            background: none;
            border: none;
            padding: 0;
            color: #7f8c8d;
        }

        .heart-icon:hover {
            transform: scale(1.2);
        }

        .heart-icon.liked {
            color: #e74c3c;
        }

        .product-meta {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            margin-top: 1.5rem;
        }

        .product-meta div {
            margin-bottom: 0.5rem;
        }

        .product-specs {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .product-specs div {
            margin-bottom: 0.5rem;
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        /* Color Selection Styles */
        .color-selection-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .color-selection-label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .color-options {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .color-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            padding: 0;
            border: 2px solid #ddd;
            background-color: transparent;
            color: #2c3e50;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
            min-width: 40px;
            height: 40px;
            text-align: center;
        }

        .color-button:hover {
            border-color: #3498db;
            background-color: transparent;
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
        }

        .color-button.selected {
            background-color: transparent;
            color: #2c3e50;
            border-color: #3498db;
            border-width: 3px;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.3);
        }

        .color-swatch {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
            display: inline-block;
        }

        .color-swatch.circle {
            border-radius: 50%;
        }

        .color-name {
            display: none;
        }

        .color-select-dropdown {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #bdc3c7;
            border-radius: 6px;
            font-size: 0.95rem;
            color: #2c3e50;
            background-color: white;
            cursor: pointer;
        }

        .color-select-dropdown:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }



        .product-description {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .product-description h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .product-description p {
            color: #5a6c7d;
            line-height: 1.6;
            margin: 0;
        }

        @media (max-width: 768px) {
            .product-detail-wrapper {
                flex-direction: column;
                gap: 1.5rem;
            }

            .product-image-wrapper {
                width: 100%;
                max-width: 400px;
                margin: 0 auto;
            }

            .product-panel {
                padding: 1.5rem;
            }

            .product-stats {
                flex-direction: column;
                gap: 1rem;
            }

            .color-options {
                gap: 0.5rem;
            }

            .color-button {
                flex: 1 1 calc(50% - 0.375rem);
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/includes/header.php'; ?>

    <main>
        <div class="product-detail-wrapper">
            <!-- Product Image -->
            <div class="product-image-wrapper">
                <img src="<?= htmlspecialchars($mainImg) ?>" alt="<?= htmlspecialchars($product['pname']) ?>">
            </div>

            <!-- Product Information Panel -->
            <div class="product-panel">
                <div class="back-button">
                    <button type="button" class="btn btn-light" onclick="handleBack()" aria-label="Back">
                        ← Back
                    </button>
                </div>

                <div class="product-title"><?= htmlspecialchars($product['pname']) ?></div>
                <div class="product-price">HK$<?= number_format((float)$product['price']) ?></div>

                <div class="product-stats">
                    <div class="likes-count">
                        <button class="heart-icon <?= $liked ? 'liked' : '' ?>" id="likeHeart" data-productid="<?= (int)$product['productid'] ?>" title="Like this product">
                            <?= $liked ? '♥' : '♡' ?>
                        </button>
                        <span id="likeCount"><?= (int)$product['likes'] ?></span> Likes
                    </div>
                </div>

                <div class="product-meta">
                    <div><i class="fas fa-store me-2"></i><strong>Supplier:</strong> <?= htmlspecialchars($product['sname']) ?></div>
                    <div><i class="fas fa-tag me-2"></i><strong>Category:</strong> <?= htmlspecialchars($product['category']) ?></div>
                </div>

                <!-- Color Selection Section (if colors are available) -->
                <?php if (!empty($colors)): ?>
                <div class="color-selection-section">
                    <label class="color-selection-label">
                        <i class="fas fa-palette me-2"></i>Select Color:
                    </label>
                    
                    <?php if (count($colors) <= 5): ?>
                        <!-- Display as buttons for 5 or fewer colors -->
                        <div class="color-options" id="colorOptions">
                            <?php foreach ($colors as $index => $color): 
                                $hexColor = colorNameToHex($color);
                            ?>
                                <button type="button" 
                                        class="color-button <?= $index === 0 ? 'selected' : '' ?>" 
                                        data-color="<?= htmlspecialchars($color) ?>"
                                        data-hex="<?= htmlspecialchars($hexColor) ?>"
                                        onclick="selectColor(this)"
                                        title="<?= htmlspecialchars($color) ?>">
                                    <span class="color-swatch" style="background-color: <?= htmlspecialchars($hexColor) ?>;"></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Display as dropdown for more than 5 colors -->
                        <select class="color-select-dropdown" id="colorSelect" onchange="selectColorDropdown(this)">
                            <option value="">-- Choose a color --</option>
                            <?php foreach ($colors as $color): 
                                $hexColor = colorNameToHex($color);
                            ?>
                                <option value="<?= htmlspecialchars($color) ?>" data-hex="<?= htmlspecialchars($hexColor) ?>">
                                    <?= htmlspecialchars($color) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    

                    <input type="hidden" id="selectedColor" value="<?= htmlspecialchars($colors[0]) ?>">
                    <input type="hidden" id="selectedColorHex" value="<?= htmlspecialchars(colorNameToHex($colors[0])) ?>">
                </div>
                <?php endif; ?>

                <div class="product-specs">
                    <?php if (!empty($product['size'])): ?>
                        <div><i class="fas fa-ruler me-2"></i><strong>Size:</strong> <?= htmlspecialchars($product['size']) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['long']) || !empty($product['wide']) || !empty($product['tall'])): ?>
                        <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #ecf0f1;">
                            <div style="font-weight: 600; color: #2c3e50; margin-bottom: 0.75rem;">Size:</div>
                            <?php if (!empty($product['long'])): ?>
                                <div style="margin-bottom: 0.5rem; margin-left: 1.5rem;"><strong>Length:</strong> <?= htmlspecialchars($product['long']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($product['wide'])): ?>
                                <div style="margin-bottom: 0.5rem; margin-left: 1.5rem;"><strong>Width:</strong> <?= htmlspecialchars($product['wide']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($product['tall'])): ?>
                                <div style="margin-left: 1.5rem;"><strong>Height:</strong> <?= htmlspecialchars($product['tall']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['material'])): ?>
                        <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #ecf0f1;"><i class="fas fa-cube me-2"></i><strong>Material:</strong> <?= htmlspecialchars($product['material']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['description'])): ?>
                <div class="product-description">
                    <h6>Description</h6>
                    <p><?= htmlspecialchars($product['description']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($others->num_rows > 0): ?>
    <section class="detail-gallery" aria-label="Other Products from This Supplier" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        <h3 style="color: #2c3e50; font-weight: 600; margin-bottom: 1rem; font-size: 1.3rem;">Other Products from <?= htmlspecialchars($product['sname']) ?></h3>
        <div class="detail-gallery-images">
            <?php while ($r = $others->fetch_assoc()): ?>
                <a href="product_detail.php?id=<?= (int)$r['productid'] ?>">
                    <?php 
                        // Get first color image for related product
                        $relatedColorSql = "SELECT image FROM ProductColorImage WHERE productid = ? ORDER BY id ASC LIMIT 1";
                        $relatedColorStmt = $mysqli->prepare($relatedColorSql);
                        $relatedColorStmt->bind_param("i", $r['productid']);
                        $relatedColorStmt->execute();
                        $relatedColorResult = $relatedColorStmt->get_result();
                        $relatedImage = $relatedColorResult->fetch_assoc();
                        $imageSrc = ($relatedImage && $relatedImage['image']) ? 'uploads/products/' . htmlspecialchars($relatedImage['image']) : 'uploads/products/placeholder.jpg';
                    ?>
                    <img src="<?= $imageSrc ?>" alt="<?= htmlspecialchars($r['pname']) ?>">
                </a>
            <?php endwhile; ?>
        </div>
    </section>
    <?php endif; ?>

    <script>
    // Color selection function for button-based selection
    function selectColor(button) {
        // Remove selected class from all buttons
        const allButtons = document.querySelectorAll('.color-button');
        allButtons.forEach(btn => btn.classList.remove('selected'));
        
        // Add selected class to clicked button
        button.classList.add('selected');
        
        // Update hidden inputs
        const selectedColor = button.dataset.color;
        const selectedHex = button.dataset.hex;
        document.getElementById('selectedColor').value = selectedColor;
        document.getElementById('selectedColorHex').value = selectedHex;
        
        // Update product image based on selected color
        updateProductImage(selectedColor);
    }

    // Color selection function for dropdown-based selection
    function selectColorDropdown(select) {
        const selectedColor = select.value;
        if (selectedColor) {
            const selectedOption = select.options[select.selectedIndex];
            const selectedHex = selectedOption.dataset.hex;
            
            document.getElementById('selectedColor').value = selectedColor;
            document.getElementById('selectedColorHex').value = selectedHex;
            
            // Update product image based on selected color
            updateProductImage(selectedColor);
        }
    }

    // Update product image based on selected color
    function updateProductImage(color) {
        const colorImages = <?= json_encode($colorImages) ?>;
        const productImg = document.querySelector('.product-image-wrapper img');
        
        // Find the image with case-insensitive color matching
        let imageFile = null;
        if (colorImages) {
            // Try exact match first
            if (colorImages[color]) {
                imageFile = colorImages[color];
            } else {
                // Try case-insensitive match
                const colorLower = color.toLowerCase();
                for (const key in colorImages) {
                    if (key.toLowerCase() === colorLower) {
                        imageFile = colorImages[key];
                        break;
                    }
                }
            }
        }
        
        if (imageFile) {
            // If color has a specific image, use it from ProductColorImage table
            const imageUrl = 'uploads/products/' + imageFile;
            productImg.src = imageUrl;
        } else {
            // Otherwise, use placeholder
            productImg.src = 'uploads/products/placeholder.jpg';
        }
    }

    function handleBack() {
        // Use the back URL determined by the server
        window.location.href = '<?= htmlspecialchars($backUrl) ?>';
    }

    // Heart like functionality - Updated for new system
    document.getElementById('likeHeart').addEventListener('click', function(e) {
        e.preventDefault();
        
        const productid = this.dataset.productid;
        const heart = this;
        
        const formData = new FormData();
        formData.append('action', 'toggle_like');
        formData.append('type', 'product');
        formData.append('id', productid);

        fetch('api/handle_like.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update heart icon
                if (data.liked) {
                    heart.classList.add('liked');
                    heart.textContent = '♥';
                } else {
                    heart.classList.remove('liked');
                    heart.textContent = '♡';
                }
                // Update like count
                document.getElementById('likeCount').textContent = data.likes;
            } else {
                alert('Error: ' + (data.message || 'Failed to update like'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the like.');
        });
    });

    // Function to get selected color (useful for order placement)
    function getSelectedColor() {
        return document.getElementById('selectedColor').value;
    }

    // Function to get selected color hex code
    function getSelectedColorHex() {
        return document.getElementById('selectedColorHex').value;
    }
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/Public/chat_widget.php'; ?>
</body>
</html>