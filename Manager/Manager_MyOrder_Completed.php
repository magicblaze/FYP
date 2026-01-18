<?php
require_once dirname(__DIR__) . '/config.php';

// 查询已完成订单
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name,
               d.designid, d.price as design_price, d.tag as design_tag,
               s.FinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.ostatus = 'Completed' OR o.ostatus = 'completed'
        ORDER BY s.FinishDate DESC";

$result = mysqli_query($mysqli, $sql);
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
                <a href="Manager_introduct.html">Introduct</a>
                <a href="Manager_MyOrder.html">MyOrder</a>
                <a href="Manager_Massage.html">Massage</a>
                <a href="Manager_Schedule.html">Schedule</a>
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
            $budget_sql = "SELECT SUM(o.budget) as total_budget 
                           FROM `Order` o 
                           WHERE o.ostatus = 'Completed' OR o.ostatus = 'completed'";
            $budget_result = mysqli_query($mysqli, $budget_sql);
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
                        <th>Completed Date</th>
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
                        <td><strong class="text-success">$<?php echo number_format($row["budget"], 2); ?></strong></td>
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
                            if(isset($row["FinishDate"]) && $row["FinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["FinishDate"]));
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
        mysqli_close($mysqli);
        ?>
        
        <!-- 页面按钮 -->
        <div class="d-flex justify-between mt-4">
            <div class="btn-group">
                <button onclick="printThisPage()" class="btn btn-primary">Print This Page</button>
                <button onclick="window.location.href='Manager_MyOrder.html'" 
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
                window.location.href = 'Manager_MyOrder.html';
            }
        });
    });

    </script>
</body>
</html>