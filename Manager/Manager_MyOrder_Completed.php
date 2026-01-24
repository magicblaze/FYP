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

// 查询已完成订单 - UPDATED FOR NEW DATE STRUCTURE - 只显示该经理的订单
// FIXED: Removed o.budget from SELECT as it doesn't exist in the Order table
// Budget is stored in the Client table, not the Order table
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.budget as client_budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
        WHERE (o.ostatus = 'Completed' OR o.ostatus = 'completed')
        AND op.managerid = ?
        ORDER BY s.OrderFinishDate DESC";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Completed Orders - HappyDesign</title>
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
        <h1 class="page-title">Completed Orders</h1>
        
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'archived'): ?>
            <div class="alert alert-success">
                <div>
                    <strong>Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been archived successfully!</strong>
                </div>
            </div>
        <?php endif; ?>
        
        <?php
        if(!$result){
            echo '<div class="alert alert-error">
                <div>
                    <strong>Database Error: ' . mysqli_error($mysqli) . '</strong>
                </div>
            </div>';
        } elseif(mysqli_num_rows($result) == 0){
            echo '<div class="alert alert-info">
                <div>
                    <strong>No completed orders found.</strong>
                </div>
            </div>';
        } else {
            $total_completed = mysqli_num_rows($result);
        ?>
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_completed; ?></div>
                <div class="stat-label">Total Completed Orders</div>
            </div>
            <?php
            // FIXED: Updated budget query to use Client table's budget column
            $budget_sql = "SELECT SUM(c.budget) as total_budget 
                           FROM `Order` o
                           LEFT JOIN `Client` c ON o.clientid = c.clientid
                           LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
                           WHERE (o.ostatus = 'Completed' OR o.ostatus = 'completed')
                           AND op.managerid = ?";
            $budget_stmt = mysqli_prepare($mysqli, $budget_sql);
            mysqli_stmt_bind_param($budget_stmt, "i", $user_id);
            mysqli_stmt_execute($budget_stmt);
            $budget_result = mysqli_stmt_get_result($budget_stmt);
            $budget_row = mysqli_fetch_assoc($budget_result);
            $total_budget = $budget_row['total_budget'] ?? 0;
            $avg_budget = $total_completed > 0 ? $total_budget / $total_completed : 0;
            ?>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($total_budget, 2); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($avg_budget, 2); ?></div>
                <div class="stat-label">Average Budget</div>
            </div>
        </div>
        
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
                        <th>Requirements</th>
                        <th>Status</th>
                        <th>Order Completed Date</th>
                        <th>Design Completed Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
                        <td>
                            <div class="d-flex flex-column">
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <small class="text-muted">Client ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td><strong class="text-success">$<?php echo number_format($row["client_budget"], 2); ?></strong></td>
                        <td>
                            <div class="d-flex flex-column">
                                <span>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></span>
                                <small>Price: $<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                                <small>Tag: <?php echo htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)); ?></small>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
                        <td>
                            <span class="status-badge status-completed">
                                Completed
                            </span>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["OrderFinishDate"]));
                            } else {
                                echo '<span class="text-muted">N/A</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["DesignFinishDate"]) && $row["DesignFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["DesignFinishDate"]));
                            } else {
                                echo '<span class="text-muted">N/A</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button onclick="viewOrder('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                        class="btn btn-sm btn-info">View</button>
                                <button onclick="archiveOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-secondary">Archive</button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <?php
        }
        
        mysqli_free_result($result);
        if(isset($budget_result)) mysqli_free_result($budget_result);
        if(isset($stmt)) mysqli_stmt_close($stmt);
        if(isset($budget_stmt)) mysqli_stmt_close($budget_stmt);
        if(isset($mysqli) && $mysqli) {
            mysqli_close($mysqli);
        }
        ?>
        
        <!-- 页面按钮 -->
        <div class="d-flex justify-between mt-4">
            <div class="btn-group">
                <button onclick="printThisPage()" class="btn btn-primary">Print This Page</button>
                <button onclick="window.location.href='Manager_MyOrder.php'" 
                        class="btn btn-secondary">Back to Orders Manager</button>
                        
            </div>
        </div>
    </div>
    
    <script>
        function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + encodeURIComponent(orderId);
    }

    
    function archiveOrder(orderId) {
        if(confirm('Are you sure you want to archive order #' + orderId + '?\n\nThis action cannot be undone.')) {
            window.location.href = 'Manager_archive_order.php?id=' + orderId;
        }
    }
    
    function printThisPage() {
        window.print();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printThisPage();
            }

            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder.php';
            }
        });
    });
    </script>
</body>
</html>
