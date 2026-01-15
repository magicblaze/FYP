<?php
require_once dirname(__DIR__) . '/config.php';

// 根据数据库结构修正查询，ostatus在Order表中
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
    <title>Completed Orders</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .status-completed {
            color: #28a745;
            font-weight: bold;
        }
        .action-buttons button {
            margin: 2px;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .print-btn {
            background-color: #17a2b8;
            color: white;
        }
        .archive-btn {
            background-color: #6c757d;
            color: white;
        }
        .stats {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .page-buttons {
            margin-top: 20px;
        }
        .page-buttons button {
            padding: 10px 20px;
            margin-right: 10px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .print-page-btn {
            background-color: #007bff;
        }
    </style>
</head>
<body>
    <h1>Completed Orders</h1>
    
    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'archived'): ?>
        <div class="success-message">
            Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been archived successfully!
        </div>
    <?php endif; ?>
    
    <?php
    if(!$result){
        echo "<p style='color: #dc3545;'>Error: " . mysqli_error($mysqli) . "</p>";
    } elseif(mysqli_num_rows($result) == 0){
        echo "<p>No completed orders found.</p>";
    } else {
        $total_completed = mysqli_num_rows($result);
    ?>
    
    <div class="stats">
        <h2>Completed Orders Summary</h2>
        <p><strong>Total Completed Orders:</strong> <?php echo $total_completed; ?></p>
        
        <?php
        $budget_sql = "SELECT SUM(o.budget) as total_budget 
                       FROM `Order` o 
                       WHERE o.ostatus = 'Completed' OR o.ostatus = 'completed'";
        $budget_result = mysqli_query($mysqli, $budget_sql);
        $budget_row = mysqli_fetch_assoc($budget_result);
        $total_budget = $budget_row['total_budget'] ?? 0;
        
        echo "<p><strong>Total Budget:</strong> $" . number_format($total_budget, 2) . "</p>";
        
        // 计算平均预算
        $avg_budget = $total_completed > 0 ? $total_budget / $total_completed : 0;
        echo "<p><strong>Average Budget:</strong> $" . number_format($avg_budget, 2) . "</p>";
        
        mysqli_free_result($budget_result);
        ?>
    </div>
    
    <table border="1" cellpadding="10" cellspacing="0">
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
        <?php
        while($row = mysqli_fetch_assoc($result)){
        ?>
        <tr>
            <td><strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong></td>
            <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
            <td>
                <?php 
                echo htmlspecialchars($row["client_name"] ?? 'N/A'); 
                echo '<br><small>Client ID: ' . htmlspecialchars($row["clientid"] ?? 'N/A') . '</small>';
                ?>
            </td>
            <td><strong>$<?php echo number_format($row["budget"], 2); ?></strong></td>
            <td>
                <?php 
                echo 'Design #' . htmlspecialchars($row["designid"] ?? 'N/A');
                echo '<br>Price: $' . number_format($row["design_price"] ?? 0, 2);
                echo '<br>Tag: ' . htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30));
                ?>
            </td>
            <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
            <td>
                <span class="status-completed">
                    True <?php echo htmlspecialchars($row["ostatus"] ?? 'Completed'); ?>
                </span>
            </td>
            <td>
                <?php 
                if(isset($row["FinishDate"]) && $row["FinishDate"] != '0000-00-00 00:00:00'){
                    echo '📅 ' . date('Y-m-d H:i', strtotime($row["FinishDate"]));
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
            <td class="action-buttons">
                <button class="print-btn" onclick="printOrderDetail(<?php echo $row['orderid']; ?>)">Print</button>
                <button class="archive-btn" onclick="archiveOrder(<?php echo $row['orderid']; ?>)">Archive</button>
            </td>
        </tr>
        <?php
        }
        ?>
    </table>
    
    <?php
    }
    
    mysqli_free_result($result);
    mysqli_close($mysqli);
    ?>
    
    <script>
    function printOrderDetail(orderId) {
        console.log('Printing order #' + orderId);
        // 方法1：直接打开打印页面
        window.open('Manager_view_order.php?id=' + orderId, '_blank');
        
        // 方法2：在新标签页打开并自动打印（需要用户允许弹窗）
        /*
        const printWindow = window.open('Manager_view_order.php?id=' + orderId, '_blank');
        if(printWindow) {
            printWindow.onload = function() {
                printWindow.print();
            };
        }
        */
    }
    
    function archiveOrder(orderId) {
        if(confirm('Are you sure you want to archive order #' + orderId + '?\n\nThis action cannot be undone.')) {
            window.location.href = 'Manager_archive_order.php?id=' + orderId;
        }
    }
    
    // 打印整个页面
    function printThisPage() {
        window.print();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Completed orders page loaded');
        
        // 添加快捷键支持
        document.addEventListener('keydown', function(e) {
            // Ctrl+P 打印整个页面
            if(e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printThisPage();
            }
            // Esc键返回
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder.html';
            }
        });
        
        // 为打印按钮添加事件监听器（备用方法）
        document.querySelectorAll('.print-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const orderId = this.getAttribute('onclick').match(/\d+/)[0];
                console.log('Print button clicked for order #' + orderId);
            });
        });
    });
    </script>
    
    <div class="page-buttons">
        <button class="print-page-btn" onclick="printThisPage()">Print This Page</button>
        <button onclick="window.location.href='Manager_MyOrder.html'">← Back to Orders Manager</button>
        <button onclick="window.location.href='index.php'">← Back to Dashboard</button>
    </div>
    
    <!-- 调试信息（开发时使用） -->
    <script>
    // 调试：检查所有打印按钮的点击事件
    document.querySelectorAll('.print-btn').forEach((btn, index) => {
        btn.addEventListener('click', function() {
            console.log('Print button ' + (index + 1) + ' clicked successfully');
        });
    });
    </script>
</body>
</html>