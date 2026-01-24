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

// 获取搜索参数
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// 构建查询条件 - 添加manager id过滤
$where_conditions = array(
    "(o.ostatus = 'Cancelled' OR o.ostatus = 'cancelled')",
    "op.managerid = $user_id"
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

// 获取所有已取消订单 - UPDATED FOR NEW DATE STRUCTURE - 只显示该经理的订单
// FIXED: Removed o.budget and added c.budget as client_budget from Client table
// Also fixed d.design and d.price to d.designName and d.expect_price (correct column names)
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone, c.budget as client_budget,
               d.designid, d.designName as design_image, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($mysqli, $sql);

if(!$result) {
    die("Database Error: " . mysqli_error($mysqli));
}

// 计算统计信息 - 添加manager id过滤
// FIXED: Changed SUM(o.budget) to SUM(c.budget) and added Client table JOIN
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as total_cancelled,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget,
                MIN(o.odate) as earliest_cancellation,
                MAX(o.odate) as latest_cancellation
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
              WHERE (o.ostatus = 'Cancelled' OR o.ostatus = 'cancelled')
              AND op.managerid = $user_id";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Cancelled Orders - HappyDesign</title>
    <style>
        .status-cancelled {
            background-color: #dc3545;
            color: white;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #dc3545;
            margin-bottom: 5px;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .date-range {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
        /* 用户信息样式 */
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            margin-left: auto;
        }
        .btn-logout {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        /* 导航栏容器调整 */
        .nav-container {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .nav-links {
            display: flex;
            gap: 20px;
            margin-left: 30px;
        }
    </style>
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
        <h1 class="page-title">Cancelled Orders</h1>

        
        <!-- 搜索框 -->
        <div class="search-box">
            <form method="GET" action="" class="d-flex align-center">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search by Order ID, Client Name, Email, Requirements or Tags..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-button">Search</button>
                <?php if(!empty($search)): ?>
                    <a href="Manager_MyOrder_Rejected.php" class="btn btn-outline ml-2">Clear Search</a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php
        if(mysqli_num_rows($result) == 0){
            echo '<div class="alert alert-info">
                <div>
                    <strong>No cancelled orders found' . (!empty($search) ? ' matching your search criteria.' : ' at the moment.') . '</strong>
                    <p class="mb-0">Orders that have been cancelled will appear here.</p>
                </div>
            </div>';
        } else {
            $total_cancelled = $stats['total_cancelled'] ?? 0;
        ?>
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_cancelled; ?></div>
                <div class="stat-label">Cancelled Orders</div>
                <?php if(isset($stats['earliest_cancellation']) && $stats['earliest_cancellation'] != '0000-00-00 00:00:00'): ?>
                    <div class="date-range">
                        Since: <?php echo date('M Y', strtotime($stats['earliest_cancellation'])); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['total_budget'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Lost Revenue</div>
                <div class="date-range">
                    Potential income from cancelled orders
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?></div>
                <div class="stat-label">Average Order Value</div>
                <div class="date-range">
                    Average value of cancelled orders
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                    if(isset($stats['latest_cancellation']) && $stats['latest_cancellation'] != '0000-00-00 00:00:00'){
                        echo date('M d', strtotime($stats['latest_cancellation']));
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </div>
                <div class="stat-label">Latest Cancellation</div>
                <div class="date-range">
                    Most recent cancelled order
                </div>
            </div>
        </div>
        
        <!-- 订单表格 -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Order Date</th>
                        <th>Cancelled Date</th>
                        <th>Client</th>
                        <th>Budget</th>
                        <th>Design</th>
                        <th>Requirements</th>
                        <th>Order Finish Date</th>
                        <th>Design Finish Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
                        <td>
                            <?php 
                            // Use OrderFinishDate as cancellation date if available
                            if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($row["OrderFinishDate"]));
                            } else {
                                echo date('Y-m-d H:i', strtotime($row["odate"]));
                            }
                            ?>
                        </td>
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
                        <td><strong class="text-danger">$<?php echo number_format($row["client_budget"], 2); ?></strong></td>
                        <td>
                            <div class="d-flex flex-column">
                                <span>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></span>
                                <small>Price: $<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                                <small>Tags: <?php echo htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)); ?></small>
                                <?php if(!empty($row["design_image"])): ?>
                                    <small class="text-muted">Image: <?php echo htmlspecialchars(substr($row["design_image"], 0, 20)); ?>...</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
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
                            <span class="status-badge status-cancelled">
                                <?php echo htmlspecialchars($row["ostatus"] ?? 'Cancelled'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button onclick="viewOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">View</button>
                                <button onclick="deleteOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-danger">Delete</button>
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
                    <strong>Showing <?php echo mysqli_num_rows($result); ?> cancelled orders</strong>
                    <p class="text-muted mb-0">Total: <?php echo $total_cancelled; ?> cancelled orders</p>
                </div>
                <div class="btn-group">
                    <button onclick="printPage()" class="btn btn-outline">Print List</button>
                    <button onclick="deleteAllCancelled()" class="btn btn-danger">Delete All</button>
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
    
    function deleteOrder(orderId) {
        if(confirm('⚠️ WARNING: Are you sure you want to permanently delete order #' + orderId + '? This action cannot be undone!')) {
            window.location.href = 'Manager_delete_order.php?id=' + orderId;
        }
    }
    
    function printPage() {
        window.print();
    }
    
    function deleteAllCancelled() {
        if(confirm('⚠️ DANGER: Are you sure you want to delete ALL cancelled orders? This action cannot be undone!')) {
            window.location.href = 'Manager_delete_all_cancelled.php';
        }
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
    
    // 自动刷新检查（可选）
    function checkForUpdates() {
        // 每30秒检查一次是否有新的取消订单
        setTimeout(() => {
            fetch('check_order_updates.php?type=cancelled')
                .then(response => response.json())
                .then(data => {
                    if(data.newCancellations > 0) {
                        showUpdateNotification(data.newCancellations);
                    }
                });
        }, 30000);
    }
    
    function showUpdateNotification(count) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-warning notification';
        notification.innerHTML = `
            <div>
                <strong>New Cancellations Available</strong>
                <p>There are ${count} new cancelled order(s).</p>
                <button onclick="location.reload()" class="btn btn-sm btn-warning">Refresh Now</button>
                <button onclick="this.parentElement.parentElement.remove()" class="btn btn-sm btn-outline">Dismiss</button>
            </div>
        `;
        
        document.querySelector('.page-container').insertBefore(notification, document.querySelector('.page-title').nextSibling);
    }
    
    // 启动更新检查
    checkForUpdates();
    </script>
    
</body>
</html>

<?php
if(isset($stats_result)) {
    mysqli_free_result($stats_result);
}
if(isset($result)) {
    mysqli_free_result($result);
}
if(isset($mysqli) && $mysqli) {
    mysqli_close($mysqli);
}
?>
