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
$user_name = $user['name'];

// 定义一个安全的查询函数，自动检查连接
function safe_mysqli_query($mysqli, $sql) {
    // 检查连接是否有效
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        die("数据库连接无效");
    }
    
    // 检查连接是否已关闭
    if (@mysqli_ping($mysqli) === false) {
        // 尝试重新连接
        require_once dirname(__DIR__) . '/config.php'; // 重新包含配置文件
        global $mysqli; // 获取全局的 $mysqli
    }
    
    $result = mysqli_query($mysqli, $sql);
    if(!$result) {
        die("Database Error: " . mysqli_error($mysqli));
    }
    return $result;
}

// 获取搜索参数
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// 构建查询条件 - 只显示当前经理的待处理订单
$where_conditions = array(
    "(o.ostatus = 'Pending' OR o.ostatus = 'pending')",
    "EXISTS (SELECT 1 FROM OrderProduct op WHERE op.orderid = o.orderid AND op.managerid = $user_id)"
);

if(!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "c.cemail LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $search_conditions[] = "d.tag LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 获取所有待处理订单 - UPDATED FOR NEW DATE STRUCTURE
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone, c.budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY o.odate DESC";

// 使用安全的查询函数
$result = safe_mysqli_query($mysqli, $sql);

// 计算统计信息 - 只统计当前经理的订单
$stats_sql = "SELECT 
                COUNT(*) as total_pending,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              WHERE (o.ostatus = 'Pending' OR o.ostatus = 'pending')
              AND EXISTS (SELECT 1 FROM OrderProduct op WHERE op.orderid = o.orderid AND op.managerid = $user_id)";
$stats_result = safe_mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Pending Orders - HappyDesign</title>
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
        <h1 class="page-title">Pending Orders - <?php echo htmlspecialchars($user_name); ?></h1>
        
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
            <div class="alert alert-success">
                <div>
                    <strong>Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been processed successfully!</strong>
                    <?php if(isset($_GET['email']) && $_GET['email'] == 'sent'): ?>
                        <p class="mb-0">Email notification has been sent to the client.</p>
                    <?php elseif(isset($_GET['email']) && $_GET['email'] == 'failed'): ?>
                        <p class="mb-0 text-danger">Warning: Email notification failed to send.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- 搜索框 -->
        <div class="search-box">
            <form method="GET" action="" class="d-flex align-center">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search by Order ID, Client Name, Email, Requirements or Tags..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-button">Search</button>
                <?php if(!empty($search)): ?>
                    <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-outline ml-2">Clear Search</a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php
        if(mysqli_num_rows($result) == 0){
            echo '<div class="alert alert-info">
                <div>
                    <strong>No pending orders found' . (!empty($search) ? ' matching your search criteria.' : ' at the moment.') . '</strong>
                    <p class="mb-0">All new orders will appear here when they are submitted by clients.</p>
                </div>
            </div>';
        } else {
            $total_pending = $stats['total_pending'] ?? 0;
        ?>
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_pending; ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['total_budget'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?></div>
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
                        <th>Order Finish Date</th>
                        <th>Design Finish Date</th>
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
                                <?php if(!empty($row["client_email"])): ?>
                                    <small class="text-muted">Email: <?php echo htmlspecialchars($row["client_email"]); ?></small>
                                <?php endif; ?>
                                <?php if(!empty($row["client_phone"])): ?>
                                    <small class="text-muted">Phone: <?php echo htmlspecialchars($row["client_phone"]); ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><strong class="text-success">$<?php echo number_format($row["budget"], 2); ?></strong></td>
                        <td>
                            <div class="d-flex flex-column">
                                <span>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></span>
                                <small>Price: $<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                                <small>Tags: <?php echo htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)); ?></small>

                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
                        <td>
                            <span class="status-badge status-pending">
                                <?php echo htmlspecialchars($row["ostatus"] ?? 'Pending'); ?>
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
                            <div class="btn-group">
                                <button onclick="viewOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">View</button>
                                <button onclick="approveOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-success">Approve</button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 分页信息 -->
        <div class="card mt-3">
            <div class="card-body d-flex justify-between align-center">
                <div>
                    <strong>Showing <?php echo mysqli_num_rows($result); ?> pending orders</strong>
                    <p class="text-muted mb-0">Total: <?php echo $total_pending; ?> orders awaiting confirmation</p>
                </div>
                <div class="btn-group">
                    
                    <button onclick="printPage()" class="btn btn-outline">Print List</button>
                </div>
            </div>
        </div>
        
        <?php
        }
        ?>
        
        <!-- 返回按钮 -->
        <div class="d-flex justify-between mt-4">
            <div class="btn-group">
                <button onclick="window.location.href='Manager_MyOrder.php'" 
                        class="btn btn-secondary">Back to Orders Manager</button>

            </div>
        </div>
    </div>
    
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + orderId;
    }
    
    function approveOrder(orderId) {
        window.location.href = 'Manager_MyOrder_AwaitingConfirm_Approval.php?id=' + orderId;
    }

    
    function printPage() {
        window.print();
    }
    
    // 快捷键支持
    document.addEventListener('DOMContentLoaded', function() {
        // Ctrl+F 聚焦搜索框
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl+P 打印
            if(e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            // Esc键返回
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder.php';
            }
        });
        
        // 搜索框回车提交
        const searchInput = document.querySelector('input[name="search"]');
        if(searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    document.querySelector('form').submit();
                }
            });
        }
        
        // 高亮搜索结果
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search');
        if(searchTerm) {
            setTimeout(() => {
                highlightSearchTerm(searchTerm);
            }, 100);
        }
        
        // 添加悬停效果
        const tableRows = document.querySelectorAll('.table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    });
    
    function highlightSearchTerm(term) {
        const table = document.querySelector('.table');
        if(!table) return;
        
        const regex = new RegExp(`(${term})`, 'gi');
        const walker = document.createTreeWalker(
            table,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        
        let node;
        const textNodes = [];
        while(node = walker.nextNode()) {
            textNodes.push(node);
        }
        
        textNodes.forEach(node => {
            if(node.parentNode.nodeName !== 'SCRIPT' && node.parentNode.nodeName !== 'STYLE') {
                const newHTML = node.textContent.replace(regex, '<mark class="highlight">$1</mark>');
                if(newHTML !== node.textContent) {
                    const span = document.createElement('span');
                    span.innerHTML = newHTML;
                    node.parentNode.replaceChild(span, node);
                }
            }
        });
    }
    
    // 自动检查新订单（每60秒）
    function checkForNewOrders() {
        fetch('check_new_orders.php?manager_id=<?php echo $user_id; ?>')
            .then(response => response.json())
            .then(data => {
                if(data.newOrders > 0) {
                    showNewOrderNotification(data.newOrders);
                }
            })
            .catch(error => console.error('Error checking for new orders:', error));
    }
    
    function showNewOrderNotification(count) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info notification';
        notification.innerHTML = `
            <div>
                <strong>New Orders Available</strong>
                <p>There are ${count} new pending order(s).</p>
                <button onclick="location.reload()" class="btn btn-sm btn-primary">Refresh Now</button>
                <button onclick="this.parentElement.parentElement.remove()" class="btn btn-sm btn-outline">Dismiss</button>
            </div>
        `;
        
        document.querySelector('.page-container').insertBefore(notification, document.querySelector('.page-title').nextSibling);
        
        // 自动消失
        setTimeout(() => {
            if(notification.parentNode) {
                notification.remove();
            }
        }, 10000);
    }
    
    // 每60秒检查一次新订单
    setInterval(checkForNewOrders, 60000);
    </script>
    
    <style>
        .highlight {
            background-color: #fff3cd;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .notification {
            animation: slideDown 0.5s ease-out;
            position: relative;
            z-index: 1000;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        @media print {
            .nav-bar,
            .search-box,
            .stats-grid,
            .btn-group,
            .notification {
                display: none !important;
            }
            
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .page-title {
                margin-bottom: 20px !important;
            }
        }
    </style>
</body>
</html>