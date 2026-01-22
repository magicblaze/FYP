<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// 检查用户是否以经理身份登录
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>buyProduct - HappyDesign</title>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.php">Introduct</a>
                <a href="Manager_MyOrder.php">MyOrder</a>
                <a href="Manager_Massage.php">Massage</a>
                <a href="Manager_Schedule.php">Schedule</a>
            </div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Buy Product - Designing Orders</h1>
        
        <?php
        // UPDATED SQL FOR NEW DATE STRUCTURE - 只显示该经理的订单
        $sql = "SELECT DISTINCT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
                       c.clientid, c.cname as client_name,
                       d.designid, d.price as design_price, d.tag as design_tag,
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
            echo '<div class="alert alert-error">
                <div>
                    <strong>Database Error: ' . mysqli_error($mysqli) . '</strong>
                </div>
            </div>';
        } else {
            $total_orders = mysqli_num_rows($result);
        ?>
        
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-between align-center">
                    <div>
                        <h3 class="card-title">Designing Orders Available for Purchase</h3>
                        <p class="text-muted mb-0">Total Orders: <?php echo $total_orders; ?></p>
                    </div>
                    <div class="btn-group">
                        <button onclick="refreshPage()" class="btn btn-outline">Refresh</button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if($total_orders == 0): ?>
            <div class="alert alert-info">
                <div>
                    <strong>No designing orders found for product purchase.</strong>
                    <p class="mb-0">All "Designing" orders will appear here when they are ready for product purchase.</p>
                </div>
            </div>
        <?php else: ?>
        
        <!-- 订单表格 -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Order Date</th>
                        <th>Client</th>
                        <th>Budget</th>
                        <th>Design</th>
                        <th>Requirement</th>
                        <th>Status</th>
                        <th>Order Finish Date</th>
                        <th>Design Finish Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row["orderid"]); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
                        <td>
                            <div class="d-flex flex-column">
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td><strong class="text-success">$<?php echo number_format($row["budget"], 2); ?></strong></td>
                        <td>
                            <div class="d-flex flex-column">
                                <span>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></span>
                                <small>Price: $<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                                <small>Tag: <?php echo htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)); ?>...</small>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 50)) . (strlen($row["Requirements"] ?? '') > 50 ? '...' : ''); ?></td>
                        <td>
                            <span class="status-badge status-designing">
                                Designing
                            </span>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["OrderFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["DesignFinishDate"]) && $row["DesignFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["DesignFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <button onclick="buyProduct('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                    class="btn btn-success btn-sm">Buy Product</button>
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
        
        <!-- 返回按钮 -->
        <div class="d-flex justify-between mt-4">
            <button onclick="window.location.href='Manager_MyOrder.php'" 
                    class="btn btn-secondary">Back to MyOrders</button>
            <div class="d-flex align-center">
                <span class="text-muted">Showing <?php echo $total_orders; ?> designing orders</span>
            </div>
        </div>
    </div>
    
    <script>
    function buyProduct(orderId) {
        if(confirm('Are you sure you want to buy product for Order ID: ' + orderId + '?\n\nThis will proceed with the product purchase process.')) {
            window.location.href = '../material_dashboard.php?orderid=' + encodeURIComponent(orderId);
        }
    }
    
    function refreshPage() {
        window.location.reload();
    }
    

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            refreshPage();
        }, 60000); 
    });
    </script>
</body>
</html>