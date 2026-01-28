<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Handle AJAX request for product selection modal
if(isset($_GET['action']) && $_GET['action'] == 'get_products' && isset($_GET['orderid'])) {
    $orderid = intval($_GET['orderid']);
    
    // Get all products with their colors
    $product_sql = "SELECT 
                        p.productid, 
                        p.pname, 
                        p.price, 
                        p.category, 
                        p.description,
                        p.long,
                        p.wide,
                        p.tall,
                        p.material
                    FROM `Product` p
                    ORDER BY p.category, p.pname";
    
    $product_result = mysqli_query($mysqli, $product_sql);
    $products = array();
    
    while($product = mysqli_fetch_assoc($product_result)) {
        // Get colors for this product
        $color_sql = "SELECT color, image FROM `ProductColorImage` WHERE productid = ?";
        $color_stmt = mysqli_prepare($mysqli, $color_sql);
        mysqli_stmt_bind_param($color_stmt, "i", $product['productid']);
        mysqli_stmt_execute($color_stmt);
        $color_result = mysqli_stmt_get_result($color_stmt);
        
        $colors = array();
        while($color = mysqli_fetch_assoc($color_result)) {
            $colors[] = $color;
        }
        $product['colors'] = $colors;
        $products[] = $product;
        mysqli_stmt_close($color_stmt);
    }
    
    // Get client referenced products for this order from OrderReference table
    $ref_sql = "SELECT DISTINCT productid FROM `OrderReference` WHERE orderid = ?";
    $ref_stmt = mysqli_prepare($mysqli, $ref_sql);
    mysqli_stmt_bind_param($ref_stmt, "i", $orderid);
    mysqli_stmt_execute($ref_stmt);
    $ref_result = mysqli_stmt_get_result($ref_stmt);
    
    $referenced_products = array();
    while($ref = mysqli_fetch_assoc($ref_result)) {
        $referenced_products[] = intval($ref['productid']);
    }
    
    // Return JSON response with debug info
    header('Content-Type: application/json');
    echo json_encode([
        'products' => $products,
        'referenced_products' => $referenced_products,
        'debug' => [
            'total_products' => count($products),
            'referenced_count' => count($referenced_products),
            'referenced_ids' => $referenced_products
        ]
    ]);
    exit;
}

// Handle AJAX request to save product to OrderDelivery table
if(isset($_POST['action']) && $_POST['action'] == 'add_product_to_order') {
    $orderid = intval($_POST['orderid']);
    $productid = intval($_POST['productid']);
    $color = mysqli_real_escape_string($mysqli, $_POST['color']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    // Insert into OrderDelivery table
    $insert_sql = "INSERT INTO `OrderDelivery` (productid, quantity, orderid, status, managerid, color) 
                   VALUES (?, ?, ?, 'Pending', ?, ?)";
    
    $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iisii", $productid, $quantity, $orderid, $user_id, $color);
    
    if(mysqli_stmt_execute($insert_stmt)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully',
            'orderproductid' => mysqli_insert_id($mysqli)
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error adding product: ' . mysqli_error($mysqli)
        ]);
    }
    mysqli_stmt_close($insert_stmt);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Buy Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        .product-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .product-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #007bff;
        }
        
        .color-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
        
        .color-block {
            width: 50px;
            height: 50px;
            border: 3px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            color: white;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }
        
        .color-block:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .color-block.selected {
            border: 4px solid #007bff;
            box-shadow: 0 0 10px rgba(0, 123, 255, 0.5);
        }
        
        .color-block.selected::after {
            content: '✓';
            font-size: 20px;
            color: white;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
        }
        
        .color-label {
            font-size: 11px;
            text-align: center;
            margin-top: 5px;
            color: #333;
        }
        
        .product-info {
            font-size: 13px;
            color: #666;
            margin-top: 8px;
        }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .category-header {
            background-color: #f8f9fa;
            padding: 10px 15px;
            margin-top: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #007bff;
            font-weight: bold;
            color: #333;
        }
        
        .add-to-order-btn {
            margin-top: 12px;
            width: 100%;
        }
        
        .quantity-input {
            width: 80px;
            display: inline-block;
            margin-right: 10px;
        }
        
        .section-header {
            background-color: #28a745;
            color: white;
            padding: 12px 15px;
            margin-bottom: 15px;
            margin-top: 20px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .section-header.other {
            background-color: #6c757d;
            margin-top: 30px;
        }
        
        .section-container {
            margin-bottom: 20px;
        }
        
        .empty-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }
    </style>
</head>

<body>
    
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-shopping-cart me-2"></i>Help to Designing - Buy Product
        </div>

        <?php
        // Get designing orders for this manager
       $sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.budget as client_budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.ostatus = 'designing'
        AND EXISTS (SELECT 1 FROM `Design` d2 
                   JOIN `Designer` des ON d2.designerid = des.designerid 
                   WHERE d2.designid = o.designid AND des.managerid = ?)
        ORDER BY o.odate DESC";
    
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
        
        if(!$result){
            echo '<div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Database Error:</strong> ' . htmlspecialchars(mysqli_error($mysqli)) . '
            </div>';
        } else {
            $total_orders = mysqli_num_rows($result);
        ?>
        
        <!-- Information Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-2">
                            <i class="fas fa-tasks me-2"></i>Designing Orders Available for Purchase
                        </h5>
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-1"></i>Total Orders: <strong><?php echo $total_orders; ?></strong>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button onclick="refreshPage()" class="btn btn-outline">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if($total_orders == 0): ?>
            <!-- Empty State -->
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem; display: block;"></i>
                    <h5 class="text-muted mb-2">No Designing Orders Found</h5>
                    <p class="text-muted mb-4">
                        All "Designing" orders will appear here when they are ready for product purchase.
                    </p>
                    <a href="Manager_MyOrder.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Orders
                    </a>
                </div>
            </div>
        <?php else: ?>
        
        <!-- Orders Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th><i class="fas fa-hashtag me-2"></i>Order ID</th>
                        <th><i class="fas fa-calendar me-2"></i>Order Date</th>
                        <th><i class="fas fa-user me-2"></i>Client</th>
                        <th><i class="fas fa-dollar-sign me-2"></i>Budget</th>
                        <th><i class="fas fa-image me-2"></i>Design</th>
                        <th><i class="fas fa-file-alt me-2"></i>Requirements</th>
                        <th><i class="fas fa-info-circle me-2"></i>Status</th>
                        <th><i class="fas fa-clock me-2"></i>Finish Date</th>
                        <th><i class="fas fa-cogs me-2"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong>
                        </td>
                        <td>
                            <?php echo date('Y-m-d', strtotime($row["odate"])); ?>
                        </td>
                        <td>
                            <div>
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <br>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td>
                            <span style="color: #27ae60; font-weight: 600;">$<?php echo number_format($row["client_budget"], 2); ?></span>
                        </td>
                        <td>
                            <div>
                                <small>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></small>
                                <br>
                                <small class="text-muted">$<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                            </div>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 40)) . (strlen($row["Requirements"] ?? '') > 40 ? '...' : ''); ?>
                            </small>
                        </td>
                        <td>
                            <span class="status-badge status-designing">
                                <i class="fas fa-pencil-alt me-1"></i>Designing
                            </span>
                        </td>
                        <td>
                            <small>
                                <?php 
                                if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                    echo date('Y-m-d', strtotime($row["OrderFinishDate"]));
                                } else {
                                    echo '<span class="text-muted">Not scheduled</span>';
                                }
                                ?>
                            </small>
                        </td>
                        <td>
                            <button onclick="openProductModal('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                    class="btn btn-success btn-sm">
                                <i class="fas fa-shopping-bag me-1"></i>Buy Product
                            </button>
                            <button onclick="WorkerAllocation('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                    class="btn btn-success btn-sm">
                                <i class="fas fa-shopping-bag me-1"></i>Worker allocation
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <?php endif; ?>
        
        <?php
        mysqli_free_result($result);
        mysqli_stmt_close($stmt);
        if(isset($mysqli) && $mysqli) {
            mysqli_close($mysqli);
        }
        }
        ?>
        
        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="Manager_MyOrder.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Orders
            </a>
            <div class="text-muted">
                <small>Showing <strong><?php echo $total_orders ?? 0; ?></strong> designing orders</small>
            </div>
        </div>
    </main>

    <!-- Product Selection Modal -->
    <div class="modal fade" id="productModal" tabindex="-1" role="dialog" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalLabel">
                        <i class="fas fa-shopping-bag me-2"></i>Select Products for Order #<span id="modalOrderId"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="productModalBody">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let currentOrderId = null;
    let selectedProducts = {};
    
    function openProductModal(orderId) {
        currentOrderId = orderId;
        selectedProducts = {};
        document.getElementById('modalOrderId').textContent = orderId;
        
        // Fetch products via AJAX
        fetch('Manager_MyOrder_buyProduct.php?action=get_products&orderid=' + orderId)
            .then(response => response.json())
            .then(data => {
                console.log('Data received:', data);
                console.log('Referenced products:', data.referenced_products);
                console.log('Total products:', data.products.length);
                renderProducts(data.products, data.referenced_products);
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('productModalBody').innerHTML = 
                    '<div class="alert alert-danger">Error loading products. Please try again.</div>';
            });
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('productModal'));
        modal.show();
    }
    
    function renderProducts(products, referencedProducts) {
        console.log('renderProducts called');
        console.log('Referenced products array:', referencedProducts);
        console.log('Products array:', products);
        
        let html = '';
        
        // Separate referenced and other products
        const referenced = products.filter(p => {
            const isRef = referencedProducts.includes(parseInt(p.productid));
            console.log('Product ID ' + p.productid + ' (' + p.pname + ') - Referenced: ' + isRef);
            return isRef;
        });
        
        const others = products.filter(p => !referencedProducts.includes(parseInt(p.productid)));
        
        console.log('Referenced products count:', referenced.length);
        console.log('Other products count:', others.length);
        
        // Render referenced products section
        if(referenced.length > 0) {
            html += '<div class="section-container">';
            html += '<div class="section-header"><i class="fas fa-star me-2"></i>Client Referenced Products (' + referenced.length + ')</div>';
            
            let currentCategory = '';
            referenced.forEach(product => {
                if(product.category !== currentCategory) {
                    currentCategory = product.category;
                    html += '<div class="category-header"><i class="fas fa-folder me-2"></i>' + currentCategory + '</div>';
                }
                html += renderProductCard(product);
            });
            
            html += '</div>';
        } else {
            html += '<div class="section-container">';
            html += '<div class="section-header"><i class="fas fa-star me-2"></i>Client Referenced Products</div>';
            html += '<div class="empty-section">No client referenced products for this order.</div>';
            html += '</div>';
        }
        
        // Render other products section
        if(others.length > 0) {
            html += '<div class="section-container">';
            html += '<div class="section-header other"><i class="fas fa-box me-2"></i>Other Products (' + others.length + ')</div>';
            
            let currentCategory = '';
            others.forEach(product => {
                if(product.category !== currentCategory) {
                    currentCategory = product.category;
                    html += '<div class="category-header"><i class="fas fa-folder me-2"></i>' + currentCategory + '</div>';
                }
                html += renderProductCard(product);
            });
            
            html += '</div>';
        } else {
            html += '<div class="section-container">';
            html += '<div class="section-header other"><i class="fas fa-box me-2"></i>Other Products</div>';
            html += '<div class="empty-section">No other products available.</div>';
            html += '</div>';
        }
        
        document.getElementById('productModalBody').innerHTML = html;
    }
    
    function renderProductCard(product) {
        let html = '';
        html += '<div class="product-card">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<div style="flex: 1;">';
        html += '<h6 class="mb-1">' + htmlEscape(product.pname) + '</h6>';
        html += '<div class="product-info">';
        html += '<strong>Price:</strong> $' + product.price + ' | ';
        html += '<strong>Category:</strong> ' + htmlEscape(product.category);
        
        if(product.description) {
            html += '<br><strong>Description:</strong> ' + htmlEscape(product.description);
        }
        
        if(product.long || product.wide || product.tall) {
            html += '<br><strong>Dimensions:</strong> ';
            if(product.long) html += 'L: ' + htmlEscape(product.long) + ' ';
            if(product.wide) html += 'W: ' + htmlEscape(product.wide) + ' ';
            if(product.tall) html += 'H: ' + htmlEscape(product.tall);
        }
        
        if(product.material) {
            html += '<br><strong>Material:</strong> ' + htmlEscape(product.material);
        }
        
        html += '</div>';
        
        // Color selector as color blocks
        if(product.colors && product.colors.length > 0) {
            html += '<div class="color-selector" id="colors_' + product.productid + '">';
            product.colors.forEach(color => {
                const colorId = 'color_' + product.productid + '_' + color.color.replace(/\s+/g, '_');
                const colorHex = getColorHex(color.color);
                html += '<div style="text-align: center;">';
                html += '<div class="color-block" ' +
                        'id="' + colorId + '" ' +
                        'style="background-color: ' + colorHex + ';" ' +
                        'onclick="selectColor(event, ' + product.productid + ', \'' + 
                        htmlEscape(color.color) + '\')" ' +
                        'title="' + htmlEscape(color.color) + '">' +
                        '</div>';
                html += '<div class="color-label">' + htmlEscape(color.color) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }
        
        // Quantity and Add button
        html += '<div style="margin-top: 12px; display: flex; align-items: center;">';
        html += '<label class="me-2" style="font-size: 13px;">Quantity:</label>';
        html += '<input type="number" id="qty_' + product.productid + '" class="form-control quantity-input" value="1" min="1">';
        html += '<button type="button" class="btn btn-success btn-sm add-to-order-btn" style="width: auto; margin-top: 0;" ' +
                'onclick="addToOrder(' + product.productid + ', \'' + htmlEscape(product.pname) + '\')">' +
                '<i class="fas fa-plus me-1"></i>Add' +
                '</button>';
        html += '</div>';
        
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }
    
    function selectColor(event, productId, color) {
        event.preventDefault();
        
        // Deselect previous color for this product
        const colorContainer = document.getElementById('colors_' + productId);
        const previousSelected = colorContainer.querySelector('.color-block.selected');
        if(previousSelected) {
            previousSelected.classList.remove('selected');
        }
        
        // Select new color
        event.target.closest('.color-block').classList.add('selected');
        
        // Store selection
        selectedProducts[productId] = color;
    }
    
    function addToOrder(productId, productName) {
        const selectedColor = selectedProducts[productId];
        if(!selectedColor) {
            alert('Please select a color for ' + productName);
            return;
        }
        
        const quantity = parseInt(document.getElementById('qty_' + productId).value);
        if(quantity < 1) {
            alert('Please enter a valid quantity');
            return;
        }
        
        // Send AJAX request to save to OrderDelivery table
        const formData = new FormData();
        formData.append('action', 'add_product_to_order');
        formData.append('orderid', currentOrderId);
        formData.append('productid', productId);
        formData.append('color', selectedColor);
        formData.append('quantity', quantity);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert('✓ ' + productName + ' (Color: ' + selectedColor + ', Qty: ' + quantity + ') added to Order #' + currentOrderId);
                // Reset the product selection
                delete selectedProducts[productId];
                const colorContainer = document.getElementById('colors_' + productId);
                const selected = colorContainer.querySelector('.color-block.selected');
                if(selected) selected.classList.remove('selected');
                document.getElementById('qty_' + productId).value = 1;
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error adding product. Please try again.');
        });
    }
    
    function getColorHex(colorName) {
        const colorMap = {
            'Grey': '#a9a9a9',
            'Gray': '#a9a9a9',
            'Blue': '#4169e1',
            'Brown': '#8b4513',
            'White': '#f5f5f5',
            'Black': '#2c2c2c',
            'Red': '#dc143c',
            'Green': '#228b22',
            'Yellow': '#ffd700',
            'Orange': '#ff8c00',
            'Purple': '#800080',
            'Pink': '#ffc0cb',
            'Transparent': '#e8f4f8',
            'Beige': '#f5f5dc',
            'Navy': '#000080',
            'Cream': '#fffdd0'
        };
        return colorMap[colorName] || '#cccccc';
    }
    
    function htmlEscape(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    function WorkerAllocation(orderId) {
        if(confirm('Are you sure you want to allocate worker for Order ID: ' + orderId + '?\n\nThis will proceed with the worker allocation process.')) {
            window.location.href = 'WorkerAllocation.php?orderid=' + encodeURIComponent(orderId);
        }
    }
    
    function refreshPage() {
        window.location.reload();
    }
    
    // Auto-refresh every 60 seconds
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            refreshPage();
        }, 60000); 
    });
    </script>
    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>

</body>

</html>
