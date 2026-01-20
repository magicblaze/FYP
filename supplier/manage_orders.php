<?php
// ==============================
// File: supplier/manage_orders.php
// 显示供应商的订单产品信息，支持状态更新
// ==============================

session_start();

// 检查用户是否登录且是供应商
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$supplierId = intval($_SESSION['user']['supplierid']);
$supplierName = $_SESSION['user']['name'];

// 获取该供应商的所有订单产品及其状态
$sql = "
    SELECT 
        op.orderproductid,
        op.productid,
        op.quantity,
        op.orderid,
        op.deliverydate,
        op.status,
        p.pname,
        p.price,
        p.image,
        o.odate,
        o.ostatus,
        c.cname,
        c.cemail,
        c.address
    FROM OrderProduct op
    JOIN Product p ON op.productid = p.productid
    JOIN `Order` o ON op.orderid = o.orderid
    JOIN Client c ON o.clientid = c.clientid
    WHERE p.supplierid = ?
    ORDER BY op.orderproductid DESC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die('Prepare failed: ' . $mysqli->error);
}

$stmt->bind_param("i", $supplierId);
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}

$result = $stmt->get_result();
$orderProducts = [];

while ($row = $result->fetch_assoc()) {
    $orderProducts[] = $row;
}

$stmt->close();

// 定义可用的状态
$availableStatuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Orders - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        .product-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            padding: 1.5rem;
        }
        .product-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f0f0;
        }
        .product-main-info {
            flex: 1;
        }
        .product-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .product-price {
            color: #3498db;
            font-weight: 500;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .product-id {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .product-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }
        .detail-item {
            font-size: 0.9rem;
        }
        .detail-label {
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .detail-value {
            color: #212529;
            font-weight: 600;
        }
        .status-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-processing {
            background-color: #cfe2ff;
            color: #084298;
        }
        .status-shipped {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-delivered {
            background-color: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-select {
            max-width: 200px;
        }
        .order-info {
            background: #f8fafd;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        .order-info-item {
            margin-bottom: 0.5rem;
        }
        .order-info-item:last-child {
            margin-bottom: 0;
        }
        .order-info-label {
            color: #6c757d;
            font-weight: 500;
        }
        .order-info-value {
            color: #212529;
        }
        .no-orders {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($supplierName) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">
        <div class="dashboard-header text-center">
            <h2>Manage Orders</h2>
            <p class="mb-0">View and update order product status</p>
        </div>

        <?php if (count($orderProducts) > 0): ?>

            <?php foreach ($orderProducts as $product): ?>
                <div class="product-card">
                    <!-- Product Header with Image and Main Info -->
                    <div class="product-header">
                        <!-- Product Image -->
                        <div>
                            <?php if (!empty($product['image'])): ?>
                                <img src="product_image.php?id=<?= $product['productid'] ?>" 
                                     alt="<?= htmlspecialchars($product['pname']) ?>" 
                                     class="product-image">
                            <?php else: ?>
                                <div class="product-image d-flex align-items-center justify-content-center">
                                    <i class="fas fa-image text-muted" style="font-size: 2rem;"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Product Main Info -->
                        <div class="product-main-info">
                            <div class="product-name"><?= htmlspecialchars($product['pname']) ?></div>
                            <div class="product-price">HK$<?= number_format($product['price']) ?></div>
                            <div class="product-id">Product ID: <?= $product['productid'] ?></div>
                        </div>
                    </div>

                    <!-- Product Details -->
                    <div class="product-details">
                        <div class="detail-item">
                            <div class="detail-label">Quantity</div>
                            <div class="detail-value"><?= $product['quantity'] ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Delivery Date</div>
                            <div class="detail-value">
                                <input type="date" class="form-control form-control-sm delivery-date-input" 
                                       data-orderproductid="<?= $product['orderproductid'] ?>"
                                       value="<?= !empty($product['deliverydate']) ? date('Y-m-d', strtotime($product['deliverydate'])) : '' ?>"
                                       onchange="updateDeliveryDate(this)" style="max-width: 150px;">
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">OrderProduct ID</div>
                            <div class="detail-value">#<?= $product['orderproductid'] ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Order Date</div>
                            <div class="detail-value"><?= date('M d, Y H:i', strtotime($product['odate'])) ?></div>
                        </div>
                    </div>

                    <!-- Status Update Section -->
                    <div class="status-section">
                        <div>
                            <label class="form-label mb-2"><strong>Update Status:</strong></label>
                            <select class="form-select status-select status-dropdown" 
                                    data-orderproductid="<?= $product['orderproductid'] ?>"
                                    onchange="updateStatus(this)">
                                <option value="">-- Select Status --</option>
                                <?php foreach ($availableStatuses as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" 
                                        <?= ($product['status'] === $status) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!empty($product['status'])): ?>
                            <div>
                                <label class="form-label mb-2"><strong>Current Status:</strong></label>
                                <span class="status-badge status-<?= strtolower($product['status']) ?>">
                                    <?= htmlspecialchars($product['status']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Order Information -->
                    <div class="order-info">
                        <div class="order-info-item">
                            <span class="order-info-label">Client:</span>
                            <span class="order-info-value"><?= htmlspecialchars($product['cname']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Email:</span>
                            <span class="order-info-value"><?= htmlspecialchars($product['cemail']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Address:</span>
                            <span class="order-info-value"><?= htmlspecialchars($product['address'] ?? 'N/A') ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Order Status:</span>
                            <span class="order-info-value"><?= htmlspecialchars($product['ostatus']) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="product-card">
                <div class="no-orders">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem;"></i>
                    <h4>No Orders Found</h4>
                    <p>You don't have any orders containing your products yet.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateDeliveryDate(inputElement) {
            const orderProductId = inputElement.dataset.orderproductid;
            const newDeliveryDate = inputElement.value;

            if (!newDeliveryDate) {
                alert('Please select a delivery date');
                return;
            }

            inputElement.disabled = true;

            fetch('update_order_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    orderproductid: orderProductId,
                    deliverydate: newDeliveryDate
                })
            })
            .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, data: data })))
            .then(result => {
                if (result.ok && result.data.success) {
                    alert('Delivery date updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.data.message || 'Failed to update delivery date'));
                    inputElement.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
                inputElement.disabled = false;
            });
        }

        function updateStatus(selectElement) {
            const orderProductId = selectElement.dataset.orderproductid;
            const newStatus = selectElement.value;

            if (!newStatus) {
                alert('Please select a status');
                return;
            }

            // 禁用选择框
            selectElement.disabled = true;
            const originalText = selectElement.innerHTML;

            fetch('update_order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    orderproductid: orderProductId,
                    status: newStatus
                })
            })
            .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, data: data })))
            .then(result => {
                if (result.ok && result.data.success) {
                    alert('Status updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.data.message || 'Failed to update status'));
                    selectElement.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
                selectElement.disabled = false;
            });
        }
    </script>
</body>
</html>
