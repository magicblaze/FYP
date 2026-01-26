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
</head>

<body>
    <!-- Header Navigation (matching design_dashboard.php style) -->
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
                LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
                WHERE o.ostatus = 'Designing'
                AND op.managerid = ?
                ORDER BY o.odate DESC";
        
        $stmt = mysqli_prepare($mysqli, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if(!$result){
            echo '<div class="alert alert-danger mb-4" role="alert">
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
                            <button onclick="buyProduct('<?php echo htmlspecialchars($row['orderid']); ?>')" 
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function buyProduct(orderId) {
        if(confirm('Are you sure you want to buy product for Order ID: ' + orderId + '?\n\nThis will proceed with the product purchase process.')) {
            window.location.href = '../material_dashboard.php?orderid=' + encodeURIComponent(orderId);
        }
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
