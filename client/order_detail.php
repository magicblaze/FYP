<?php
// ==============================
// File: order_detail.php - Display detailed order information
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: login.php?redirect=' . urlencode('order_detail.php'));
    exit;
}

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

// Get order ID from URL
$orderId = (int)($_GET['orderid'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    die('Invalid order ID.');
}

// Fetch order details with verification that it belongs to the client
$orderSql = "SELECT o.orderid, o.odate, o.budget, o.Floor_Plan, o.Requirements, o.ostatus, 
                    d.designid, d.price, d.tag, dz.dname, dz.designerid
             FROM `Order` o
             JOIN Design d ON o.designid = d.designid
             JOIN Designer dz ON d.designerid = dz.designerid
             WHERE o.orderid = ? AND o.clientid = ?";
$orderStmt = $mysqli->prepare($orderSql);
$orderStmt->bind_param("ii", $orderId, $clientId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();

if ($orderResult->num_rows === 0) {
    http_response_code(404);
    die('Order not found or access denied.');
}

$order = $orderResult->fetch_assoc();

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Fetch products/materials for this order
$productsSql = "SELECT op.orderproductid, op.productid, op.quantity, op.managerid,
                       p.pname, p.price, p.category, p.description,
                       m.mname as manager_name
                FROM OrderProduct op
                JOIN Product p ON op.productid = p.productid
                JOIN Manager m ON op.managerid = m.managerid
                WHERE op.orderid = ?
                ORDER BY op.orderproductid ASC";
$productsStmt = $mysqli->prepare($productsSql);
$productsStmt->bind_param("i", $orderId);
$productsStmt->execute();
$products = $productsStmt->get_result();

// Fetch contractors assigned to this order
$contractorsSql = "SELECT oc.order_Contractorid, oc.contractorid, oc.managerid,
                          c.cname as contractor_name, c.ctel, c.cemail, c.price,
                          m.mname as manager_name
                   FROM Order_Contractors oc
                   JOIN Contractors c ON oc.contractorid = c.contractorid
                   JOIN Manager m ON oc.managerid = m.managerid
                   WHERE oc.orderid = ?
                   ORDER BY oc.order_Contractorid ASC";
$contractorsStmt = $mysqli->prepare($contractorsSql);
$contractorsStmt->bind_param("i", $orderId);
$contractorsStmt->execute();
$contractors = $contractorsStmt->get_result();

// Fetch schedule/timeline information
$scheduleSql = "SELECT s.scheduleid, s.managerid, s.FinishDate,
                       m.mname as manager_name
                FROM Schedule s
                JOIN Manager m ON s.managerid = m.managerid
                WHERE s.orderid = ?
                ORDER BY s.scheduleid ASC";
$scheduleStmt = $mysqli->prepare($scheduleSql);
$scheduleStmt->bind_param("i", $orderId);
$scheduleStmt->execute();
$schedules = $scheduleStmt->get_result();

// Calculate totals
$productTotal = 0;
$productsTemp = $products->fetch_all(MYSQLI_ASSOC);
foreach ($productsTemp as $product) {
    $productTotal += $product['price'] * $product['quantity'];
}
$products->data_seek(0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order Detail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-detail-container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin: 1rem auto;
            max-width: 1400px;
        }
        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #3498db;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        .section-title {
            color: #2c3e50;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ecf0f1;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border-left: 4px solid #3498db;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #ecf0f1;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 150px;
        }
        .info-value {
            color: #555;
            text-align: right;
            flex: 1;
        }
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        .status-designing {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-pending {
            background-color: #cce5ff;
            color: #004085;
        }
        .product-table, .contractor-table, .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        .product-table thead, .contractor-table thead, .schedule-table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #ecf0f1;
        }
        .product-table th, .contractor-table th, .schedule-table th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
        }
        .product-table td, .contractor-table td, .schedule-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #ecf0f1;
        }
        .product-table tbody tr:hover, .contractor-table tbody tr:hover, .schedule-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .manager-badge {
            display: inline-block;
            background-color: #e8f4f8;
            color: #2c3e50;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .empty-message {
            text-align: center;
            padding: 2rem;
            color: #7f8c8d;
            background-color: #f8f9fa;
            border-radius: 10px;
        }
        .empty-message i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #bdc3c7;
        }
        .price-highlight {
            color: #27ae60;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .design-image-container {
            text-align: center;
            margin-bottom: 1rem;
        }
        .design-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .info-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .info-value {
                text-align: left;
                margin-top: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="../client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="../client/my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link active" href="../client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                  <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="container mt-4">
        <div class="order-detail-container">
            <a href="order_history.php" class="back-link">
                <i class="fas fa-arrow-left me-1"></i>Back to Order History
            </a>

            <h1 class="page-title">
                <i class="fas fa-receipt me-2"></i>Order #<?= (int)$order['orderid'] ?> Details
            </h1>

            <!-- Order Overview Section -->
            <div class="section-title">
                <i class="fas fa-info-circle me-2"></i>Order Overview
            </div>
            <div class="grid-2">
                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Order ID:</span>
                        <span class="info-value">#<?= (int)$order['orderid'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Order Date:</span>
                        <span class="info-value"><?= date('M d, Y H:i', strtotime($order['odate'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <?php
                            $statusLower = strtolower($order['ostatus'] ?? '');
                            $statusClass = 'status-pending';
                            if (strpos($statusLower, 'design') !== false) {
                                $statusClass = 'status-designing';
                            } elseif (strpos($statusLower, 'complet') !== false) {
                                $statusClass = 'status-completed';
                            } elseif (strpos($statusLower, 'cancel') !== false) {
                                $statusClass = 'status-cancelled';
                            }
                            ?>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= htmlspecialchars($order['ostatus'] ?? 'Pending') ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Budget:</span>
                        <span class="info-value price-highlight">$<?= number_format((float)$order['budget'], 2) ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Designer:</span>
                        <span class="info-value"><?= htmlspecialchars($order['dname']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Design Price:</span>
                        <span class="info-value price-highlight">$<?= number_format((float)$order['price'], 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Design Tags:</span>
                        <span class="info-value">
                            <?php
                            $tags = array_filter(array_map('trim', explode(',', $order['tag'] ?? '')));
                            foreach ($tags as $tag) {
                                echo '<span class="badge bg-primary me-1">' . htmlspecialchars($tag) . '</span>';
                            }
                            ?>
                        </span>
                    </div>
                    <?php if (!empty($order['Floor_Plan'])): ?>
                    <div class="info-row">
                        <span class="info-label">Floor Plan:</span>
                        <span class="info-value">
                            <a href="<?= htmlspecialchars($order['Floor_Plan']) ?>" target="_blank" class="text-decoration-none">
                                <i class="fas fa-file-image me-1"></i>View Floor Plan
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Requirements Section -->
            <?php if (!empty($order['Requirements'])): ?>
            <div class="section-title">
                <i class="fas fa-clipboard-list me-2"></i>Requirements
            </div>
            <div class="info-card">
                <p class="mb-0"><?= htmlspecialchars($order['Requirements']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Products/Materials Section -->
            <div class="section-title">
                <i class="fas fa-box me-2"></i>Products & Materials
            </div>
            <?php if ($products->num_rows > 0): ?>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th>Assigned Manager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($product = $products->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($product['pname']) ?></strong>
                                    <?php if (!empty($product['description'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 60)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td class="price-highlight">$<?= number_format((float)$product['price'], 2) ?></td>
                                <td><?= (int)$product['quantity'] ?></td>
                                <td class="price-highlight">$<?= number_format((float)$product['price'] * $product['quantity'], 2) ?></td>
                                <td>
                                    <span class="manager-badge">
                                        <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($product['manager_name']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Products Total:</span>
                        <span class="info-value price-highlight">$<?= number_format($productTotal, 2) ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-box"></i>
                    <p>No products or materials assigned yet.</p>
                </div>
            <?php endif; ?>

            <!-- Contractors Section -->
            <div class="section-title">
                <i class="fas fa-users me-2"></i>Assigned Contractors
            </div>
            <?php if ($contractors->num_rows > 0): ?>
                <table class="contractor-table">
                    <thead>
                        <tr>
                            <th>Contractor Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Service Price</th>
                            <th>Assigned Manager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($contractor = $contractors->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($contractor['contractor_name']) ?></strong></td>
                                <td><?= htmlspecialchars($contractor['ctel']) ?></td>
                                <td>
                                    <a href="mailto:<?= htmlspecialchars($contractor['cemail']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($contractor['cemail']) ?>
                                    </a>
                                </td>
                                <td class="price-highlight">$<?= number_format((float)$contractor['price'], 2) ?></td>
                                <td>
                                    <span class="manager-badge">
                                        <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($contractor['manager_name']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-users"></i>
                    <p>No contractors assigned yet.</p>
                </div>
            <?php endif; ?>

            <!-- Schedule/Timeline Section -->
            <div class="section-title">
                <i class="fas fa-calendar-check me-2"></i>Project Timeline
            </div>
            <?php if ($schedules->num_rows > 0): ?>
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th>Scheduled Finish Date</th>
                            <th>Manager in Charge</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($schedule = $schedules->fetch_assoc()): ?>
                            <?php
                            $finishDate = strtotime($schedule['FinishDate']);
                            $today = strtotime(date('Y-m-d'));
                            $isOverdue = $finishDate < $today;
                            $isUpcoming = $finishDate >= $today && $finishDate <= strtotime('+7 days');
                            ?>
                            <tr>
                                <td>
                                    <strong><?= date('M d, Y H:i', $finishDate) ?></strong>
                                    <?php if ($isOverdue): ?>
                                        <br><small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Overdue</small>
                                    <?php elseif ($isUpcoming): ?>
                                        <br><small class="text-warning"><i class="fas fa-clock me-1"></i>Upcoming</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="manager-badge">
                                        <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($schedule['manager_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isOverdue): ?>
                                        <span class="badge bg-danger">Overdue</span>
                                    <?php elseif ($isUpcoming): ?>
                                        <span class="badge bg-warning">Upcoming</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">On Track</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-calendar-check"></i>
                    <p>No schedule information available yet.</p>
                </div>
            <?php endif; ?>

            <!-- Summary Section -->
            <div class="section-title">
                <i class="fas fa-chart-bar me-2"></i>Order Summary
            </div>
            <div class="info-card">
                <div class="info-row">
                    <span class="info-label">Design Cost:</span>
                    <span class="info-value price-highlight">$<?= number_format((float)$order['price'], 2) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Products/Materials:</span>
                    <span class="info-value price-highlight">$<?= number_format($productTotal, 2) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Budget Allocated:</span>
                    <span class="info-value price-highlight">$<?= number_format((float)$order['budget'], 2) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Remaining Budget:</span>
                    <span class="info-value price-highlight">
                        $<?= number_format((float)$order['budget'] - $order['price'] - $productTotal, 2) ?>
                    </span>
                </div>
            </div>

            <!-- Back Button -->
            <div style="margin-top: 2rem; text-align: center;">
                <a href="order_history.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Order History
                </a>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
