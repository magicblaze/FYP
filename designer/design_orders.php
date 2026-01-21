<?php
// ==============================
// File: designer/design_orders.php
// Display and manage design orders
// ==============================

session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$designerId = intval($_SESSION['user']['designerid']);
$designerName = $_SESSION['user']['name'];

// Get all orders for designs by this designer
$sql = "
    SELECT 
        o.orderid,
        o.odate,
        o.budget,
        o.ostatus,
        o.designid,
        o.Floor_Plan,
        o.Requirements,
        d.design,
        d.price,
        d.tag,
        c.cname,
        c.cemail,
        c.address
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    JOIN Client c ON o.clientid = c.clientid
    WHERE d.designerid = ?
    ORDER BY o.orderid DESC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die('Prepare failed: ' . $mysqli->error);
}

$stmt->bind_param("i", $designerId);
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}

$result = $stmt->get_result();
$orders = [];

while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Design Orders - HappyDesign</title>
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
        .order-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            padding: 1.5rem;
        }
        .order-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .order-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f0f0;
        }
        .order-main-info {
            flex: 1;
        }
        .order-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .order-price {
            color: #3498db;
            font-weight: 500;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .order-id {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .order-details {
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
            <div class="h4 mb-0"><a href="designer_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="designer_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../supplier/schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($designerName) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">
        <div class="dashboard-header text-center">
            <h2>Design Orders</h2>
            <p class="mb-0">View and manage design orders from clients</p>
        </div>

        <?php if (count($orders) > 0): ?>

            <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <!-- Order Header with Image and Main Info -->
                    <div class="order-header">
                        <!-- Design Image -->
                        <div>
                            <?php if (!empty($order['design'])): ?>
                                <img src="../uploads/designs/<?= htmlspecialchars($order['design']) ?>" 
                                     alt="Design #<?= $order['designid'] ?>" 
                                     class="order-image">
                            <?php else: ?>
                                <div class="order-image d-flex align-items-center justify-content-center">
                                    <i class="fas fa-image text-muted" style="font-size: 2rem;"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Order Main Info -->
                        <div class="order-main-info">
                            <div class="order-title">Design Order #<?= $order['orderid'] ?></div>
                            <div class="order-price">HK$<?= number_format($order['budget']) ?> Budget</div>
                            <div class="order-id">Design ID: <?= $order['designid'] ?></div>
                        </div>
                    </div>

                    <!-- Order Details -->
                    <div class="order-details">
                        <div class="detail-item">
                            <div class="detail-label">Order Date</div>
                            <div class="detail-value"><?= date('M d, Y H:i', strtotime($order['odate'])) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Budget</div>
                            <div class="detail-value">HK$<?= number_format($order['budget']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Design Price</div>
                            <div class="detail-value">HK$<?= number_format($order['price']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Tags</div>
                            <div class="detail-value">
                                <small><?= htmlspecialchars(substr($order['tag'], 0, 50)) ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Floor Plan Section -->
                    <?php if (!empty($order['Floor_Plan'])): ?>
                        <div class="order-info" style="background-color: #e3f2fd; border-left: 4px solid #3498db;">
                            <div class="order-info-item">
                                <a href="../<?= htmlspecialchars($order['Floor_Plan']) ?>" style="color: #3498db; text-decoration: none; font-size: 0.85rem;" target="_blank" onclick="event.stopPropagation();">
                                    <i class="fas fa-file-image me-1"></i>View Floor Plan
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Requirements Section -->
                    <?php if (!empty($order['Requirements'])): ?>
                        <div class="order-info" style="background-color: #f3e5f5; border-left: 4px solid #9c27b0;">
                            <div class="order-info-item">
                                <span class="order-info-label"><i class="fas fa-list me-1"></i>Requirements:</span>
                                <span class="order-info-value"><?= htmlspecialchars($order['Requirements']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Client Information -->
                    <div class="order-info">
                        <div class="order-info-item">
                            <span class="order-info-label">Client:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['cname']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Email:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['cemail']) ?></span>
                        </div>
                        <div class="order-info-item">
                            <span class="order-info-label">Address:</span>
                            <span class="order-info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="order-card">
                <div class="no-orders">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem;"></i>
                    <h4>No Design Orders Found</h4>
                    <p>You don't have any design orders yet.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    </script>
</body>
</html>
