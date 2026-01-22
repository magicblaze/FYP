<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// 检查用户是否以经理身份登录
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];

if(isset($_GET['id'])) {
    $orderid = mysqli_real_escape_string($mysqli, $_GET['id']);
    
    // 检查订单是否属于当前经理
    $check_manager_sql = "SELECT COUNT(*) as count FROM `OrderProduct` op 
                          JOIN `Manager` m ON op.managerid = m.managerid 
                          WHERE op.orderid = ? AND m.managerid = ?";
    $check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $orderid, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $manager_check = mysqli_fetch_assoc($check_result);
    
    if ($manager_check['count'] == 0) {
        die("You don't have permission to view this order.");
    }
    
    $sql = "SELECT o.*, c.*, d.*, s.*
            FROM `Order` o
            LEFT JOIN `Client` c ON o.clientid = c.clientid
            LEFT JOIN `Design` d ON o.designid = d.designid
            LEFT JOIN `Schedule` s ON o.orderid = s.orderid
            WHERE o.orderid = '$orderid'";
    
    $result = mysqli_query($mysqli, $sql);
    $order = mysqli_fetch_assoc($result);
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Order Details #<?php echo $orderid ?? 'N/A'; ?> - HappyDesign</title>
    <style>
        .print-header {
            display: none;
        }
        @media print {
            .print-header {
                display: block;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #333;
                padding-bottom: 10px;
            }
            .no-print {
                display: none !important;
            }
            body {
                font-size: 12pt;
            }
            .table th, .table td {
                padding: 8px;
            }
        }
        body {
            color: #000000;
        }
        
        .text-muted {
            color: #333333 !important;
        }
        
        .card-body,
        .table td,
        .table th,
        .info-label,
        .info-value,
        .btn,
        .alert,
        .small,
        small {
            color: #000000 !important;
        }
        
        .text-success {
            color: #006400 !important;
        }
        
        .text-danger {
            color: #8B0000 !important;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="nav-bar no-print">
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
        <div class="print-header">
            <h1>Order Details #<?php echo $orderid ?? 'N/A'; ?></h1>
            <p>HappyDesign Order Management System</p>
            <p>Printed on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <h1 class="page-title no-print">Order Details #<?php echo $orderid ?? 'N/A'; ?></h1>
        
        <?php if(isset($order) && $order): ?>
        
        <div class="table-container">
            <table class="table">
                <tr>
                    <th width="20%">Order ID</th>
                    <td width="30%"><?php echo htmlspecialchars($order['orderid']); ?></td>
                    <th width="20%">Order Date</th>
                    <td width="30%"><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
                </tr>
                <tr>
                    <th>Client</th>
                    <td colspan="3">
                        <div class="d-flex flex-column">
                            <strong><?php echo htmlspecialchars($order['cname']); ?></strong>
                            <span>Email: <?php echo htmlspecialchars($order['cemail']); ?></span>
                            <span>Phone: <?php echo htmlspecialchars($order['ctel']); ?></span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Budget</th>
                    <td><strong class="text-success">$<?php echo number_format($order['budget'], 2); ?></strong></td>
                    <th>Status</th>
                    <td>
                        <?php 
                        $status_class = '';
                        switch($order['ostatus']) {
                            case 'Completed': $status_class = 'status-completed'; break;
                            case 'Designing': $status_class = 'status-designing'; break;
                            case 'Pending': $status_class = 'status-pending'; break;
                            default: $status_class = 'status-pending';
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($order['ostatus']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Design Details</th>
                    <td colspan="3">
                        <div class="d-flex flex-column">
                            <span>Design ID: <?php echo htmlspecialchars($order['designid']); ?></span>
                            <span>Price: $<?php echo number_format($order['price'], 2); ?></span>
                            <span>Tag: <?php echo htmlspecialchars($order['tag']); ?></span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Requirements</th>
                    <td colspan="3"><?php echo nl2br(htmlspecialchars($order['Requirements'])); ?></td>
                </tr>
                <tr>
                    <th>Order Finish Date</th>
                    <td>
                        <?php 
                        if(isset($order["OrderFinishDate"]) && $order["OrderFinishDate"] != '0000-00-00 00:00:00'){
                            echo date('Y-m-d H:i', strtotime($order["OrderFinishDate"]));
                        } else {
                            echo '<span class="text-muted">N/A</span>';
                        }
                        ?>
                    </td>
                    <th>Design Finish Date</th>
                    <td>
                        <?php 
                        if(isset($order["DesignFinishDate"]) && $order["DesignFinishDate"] != '0000-00-00 00:00:00'){
                            echo date('Y-m-d H:i', strtotime($order["DesignFinishDate"]));
                        } else {
                            echo '<span class="text-muted">N/A</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            <div>
                <strong>Order not found.</strong>
                <p>The requested order does not exist or has been removed.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 操作按钮 -->
        <div class="d-flex justify-between mt-4 no-print">
            <div class="btn-group">
                <button onclick="window.print()" class="btn btn-primary print-btn">Print This Page</button>
                <button onclick="goBack()" class="btn btn-secondary">Close</button>
            </div>
            <div class="d-flex align-center">
                <span class="text-muted">Order #<?php echo $orderid ?? 'N/A'; ?></span>
            </div>
        </div>
    </div>
    
    <?php
    if(isset($result)) mysqli_free_result($result);
    mysqli_close($mysqli);
    ?>

    <script>

        function goBack() {
            window.history.back();
        }
        
        // 打印时添加页眉页脚
        window.addEventListener('beforeprint', function() {
            document.title = 'Order Details #' + '<?php echo $orderid ?? "N/A"; ?>';
        });
        
        // 添加键盘快捷键支持
        document.addEventListener('keydown', function(e) {
            // ESC键关闭页面
            if (e.key === 'Escape') {
                goBack();
            }
            // Ctrl+P 打印
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>