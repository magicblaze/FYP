<?php
// ==============================
// File: order_history.php - Display client's order history
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: login.php?redirect=' . urlencode('order_history.php'));
    exit;
}

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Fetch orders for the logged-in client
$sql = "SELECT o.orderid, o.odate, o.budget, o.Floor_Plan, o.Requirements, o.ostatus, 
               d.designid, d.price, d.tag, dz.dname
        FROM `Order` o
        JOIN Design d ON o.designid = d.designid
        JOIN Designer dz ON d.designerid = dz.designerid
        WHERE o.clientid = ?
        ORDER BY o.odate DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$orders = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-history-container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin: 1rem auto;
            max-width: 1200px;
        }
        .order-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid #ecf0f1;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .order-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
            background: #ffffff;
            border-color: #3498db;
        }
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #ecf0f1;
        }
        .order-id {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        .order-date {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        .order-status {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
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
        .order-body {
            display: flex;
            gap: 1.25rem;
        }
        .order-design-image {
            width: 120px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .order-details {
            flex: 1;
        }
        .order-details .designer-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        .order-details .design-tags {
            margin-bottom: 0.5rem;
        }
        .order-details .badge {
            background-color: #3498db;
            margin-right: 0.25rem;
            font-weight: 500;
        }
        .order-price-info {
            text-align: right;
        }
        .order-price-info .price-label {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        .order-price-info .price-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #27ae60;
        }
        .order-requirements {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #ecf0f1;
            font-size: 0.9rem;
            color: #5a6c7d;
        }
        .empty-orders {
            text-align: center;
            padding: 3rem;
            color: #7f8c8d;
        }
        .empty-orders i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #bdc3c7;
        }
        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #3498db;
        }
        .floor-plan-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.85rem;
        }
        .floor-plan-link:hover {
            text-decoration: underline;
        }
        .view-details-btn {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            background-color: #3498db;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background-color 0.3s ease;
        }
        .view-details-btn:hover {
            background-color: #2980b9;
            color: white;
            text-decoration: none;
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
                        <a class="nav-link text-muted " href="../client/profile.php">
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
        <div class="order-history-container">
            <h1 class="page-title"><i class="fas fa-history me-2"></i>Order History</h1>
            
            <?php if ($orders->num_rows > 0): ?>
                <?php while ($order = $orders->fetch_assoc()): ?>
                    <?php
                    // Parse tags
                    $tags = array_filter(array_map('trim', explode(',', $order['tag'] ?? '')));
                    
                    // Determine status class
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
                    <div class="order-card" onclick="window.location.href='order_detail.php?orderid=<?= (int)$order['orderid'] ?>'">
                        <div class="order-header">
                            <div>
                                <span class="order-id">Order #<?= (int)$order['orderid'] ?></span>
                                <span class="order-date ms-3">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <?= date('M d, Y H:i', strtotime($order['odate'])) ?>
                                </span>
                            </div>
                            <span class="order-status <?= $statusClass ?>">
                                <?= htmlspecialchars($order['ostatus'] ?? 'Pending') ?>
                            </span>
                        </div>
                        <div class="order-body">
                            <img src="../design_image.php?id=<?= (int)$order['designid'] ?>" 
                                 class="order-design-image" 
                                 alt="Design #<?= (int)$order['designid'] ?>">
                            <div class="order-details">
                                <div class="designer-name">
                                    <i class="fas fa-user-tie me-1"></i>
                                    Designer: <?= htmlspecialchars($order['dname']) ?>
                                </div>
                                <div class="design-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="badge"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($order['Floor_Plan'])): ?>
                                    <a href="<?= htmlspecialchars($order['Floor_Plan']) ?>" class="floor-plan-link" target="_blank" onclick="event.stopPropagation();">
                                        <i class="fas fa-file-image me-1"></i>View Floor Plan
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="order-price-info">
                                <div class="price-label">Budget</div>
                                <div class="price-value">$<?= number_format((float)$order['budget'], 2) ?></div>
                            </div>
                        </div>
                        <?php if (!empty($order['Requirements'])): ?>
                            <div class="order-requirements">
                                <strong><i class="fas fa-clipboard-list me-1"></i>Requirements:</strong>
                                <?= htmlspecialchars($order['Requirements']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top: 0.75rem;">
                            <a href="order_detail.php?orderid=<?= (int)$order['orderid'] ?>" class="view-details-btn" onclick="event.stopPropagation();">
                                <i class="fas fa-arrow-right me-1"></i>View Details
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-orders">
                    <i class="fas fa-shopping-bag"></i>
                    <h3>No Orders Yet</h3>
                    <p>You haven't placed any orders yet. Browse our designs and place your first order!</p>
                    <a href="../design_dashboard.php" class="btn btn-primary mt-2">
                        <i class="fas fa-search me-2"></i>Browse Designs
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
