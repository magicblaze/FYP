<?php
require_once dirname(__DIR__) . '/config.php';

// 获取状态过滤参数
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// 构建查询条件
$where_conditions = array();

if($status_filter != 'all') {
    $status_filter = mysqli_real_escape_string($mysqli, $status_filter);
    $where_conditions[] = "o.ostatus = '$status_filter'";
}

if(!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "d.tag LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 构建SQL查询 - UPDATED FOR NEW DATE STRUCTURE
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name,
               d.designid, d.price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($mysqli, $sql);

// 获取总数
$count_sql = "SELECT COUNT(*) as total FROM `Order` o $where_clause";
$count_result = mysqli_query($mysqli, $count_sql);
$count_row = mysqli_fetch_assoc($count_result);
$total_orders = $count_row['total'];

// 获取统计数据
$stats_sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(o.budget) as total_budget,
                AVG(o.budget) as avg_budget,
                SUM(CASE WHEN o.ostatus = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN o.ostatus = 'Designing' THEN 1 ELSE 0 END) as designing_count,
                SUM(CASE WHEN o.ostatus = 'Completed' THEN 1 ELSE 0 END) as completed_count
              FROM `Order` o";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Total Orders - HappyDesign</title>
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
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Total Orders</h1>
        
        <!-- 搜索框 -->
        <div class="search-box">
            <form method="GET" action="" class="d-flex align-center">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search orders by ID, client name, or tags..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <button type="submit" class="search-button">Search</button>
                <?php if(!empty($search)): ?>
                    <a href="?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn btn-outline ml-2">Clear Search</a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- 状态过滤器 -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex align-center justify-between">
                    <div class="d-flex align-center gap-2">
                        <strong>Filter by Status:</strong>
                        <a href="?status=all" class="btn btn-sm <?php echo $status_filter == 'all' ? 'btn-primary' : 'btn-outline'; ?>">All Orders</a>
                        <a href="?status=Pending" class="btn btn-sm <?php echo $status_filter == 'Pending' ? 'btn-primary' : 'btn-outline'; ?>">Pending</a>
                        <a href="?status=Designing" class="btn btn-sm <?php echo $status_filter == 'Designing' ? 'btn-primary' : 'btn-outline'; ?>">Designing</a>
                        <a href="?status=Completed" class="btn btn-sm <?php echo $status_filter == 'Completed' ? 'btn-primary' : 'btn-outline'; ?>">Completed</a>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo $total_orders; ?> orders
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['total_budget'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pending_count'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['designing_count'] ?? 0; ?></div>
                <div class="stat-label">Designing</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['completed_count'] ?? 0; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?></div>
                <div class="stat-label">Average Budget</div>
            </div>
        </div>
        
        <?php if(!$result): ?>
            <div class="alert alert-error">
                <div>
                    <strong>Database Error: <?php echo mysqli_error($mysqli); ?></strong>
                </div>
            </div>
        <?php elseif($total_orders == 0): ?>
            <div class="alert alert-info">
                <div>
                    <strong>No orders found.</strong>
                    <?php if(!empty($search)): ?>
                        <p class="mb-0">Try adjusting your search criteria or clear the search.</p>
                    <?php endif; ?>
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
                    <?php while($row = mysqli_fetch_assoc($result)): 
                        // 确定状态类名
                        $status_class = '';
                        switch($row["ostatus"]) {
                            case 'Completed': $status_class = 'status-completed'; break;
                            case 'Designing': $status_class = 'status-designing'; break;
                            case 'Pending': $status_class = 'status-pending'; break;
                            default: $status_class = 'status-pending';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
                        <td>
                            <div class="d-flex flex-column">
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td>
                            <strong class="text-success">$<?php echo number_format($row["budget"], 2); ?></strong>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></span>
                                <small>Price: $<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                                <small>Tag: <?php echo htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)); ?>...</small>
                            </div>
                        </td>
                        <td>
                            <div class="requirements-preview">
                                <?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 50)); ?>
                                <?php if(strlen($row["Requirements"] ?? '') > 50): ?>
                                    <span class="text-muted">...</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $status_class; ?>">
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
                                <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $row["orderid"]; ?>" 
                                   class="btn btn-sm btn-primary">Edit</a>
                                <button onclick="viewOrder('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                        class="btn btn-sm btn-secondary">View</button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 分页和信息 -->
        <div class="card mt-3">
            <div class="card-body d-flex justify-between align-center">
                <div>
                    <strong>Showing <?php echo min($total_orders, 50); ?> of <?php echo $total_orders; ?> orders</strong>
                    <?php if($status_filter != 'all'): ?>
                        <p class="text-muted mb-0">Filtered by status: <?php echo htmlspecialchars($status_filter); ?></p>
                    <?php endif; ?>
                </div>
                <div class="btn-group">
                
                    <button onclick="printPage()" class="btn btn-outline">Print</button>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- 返回按钮 -->
        <div class="d-flex justify-between mt-4">
            <button onclick="window.location.href='Manager_MyOrder.php'" 
                    class="btn btn-secondary">Back to Orders Manager</button>
            <div class="d-flex align-center gap-2">
                <span class="text-muted">Last updated: <?php echo date('Y-m-d H:i:s'); ?></span>
            </div>
        </div>
    </div>
    
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + encodeURIComponent(orderId);
    }
    
    function exportToCSV() {
        const status = "<?php echo $status_filter; ?>";
        const search = "<?php echo urlencode($search); ?>";
        window.location.href = 'export_orders.php?status=' + status + '&search=' + search;
    }
    
    function printPage() {
        window.print();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // 搜索框回车提交
        const searchInput = document.querySelector('.search-input');
        if(searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    this.form.submit();
                }
            });
        }
        
        // 快捷键支持
        document.addEventListener('keydown', function(e) {
            // Ctrl+F 聚焦搜索框
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl+E 导出
            if(e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
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
        
        // 表格行点击效果
        const tableRows = document.querySelectorAll('.table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('click', function(e) {
                if(!e.target.closest('.btn-group')) {
                    const orderId = this.querySelector('td:first-child strong').textContent.replace('#', '');
                    viewOrder(orderId);
                }
            });
            
            row.style.cursor = 'pointer';
        });
        
        // 高亮搜索结果
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search');
        if(searchTerm) {
            highlightSearchTerm(searchTerm);
        }
    });
    
    function highlightSearchTerm(term) {
        const table = document.querySelector('.table');
        if(!table || !term) return;
        
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
            if(node.parentNode.nodeName !== 'SCRIPT' && node.parentNode.nodeName !== 'STYLE' && 
               node.parentNode.className !== 'btn-group') {
                const newHTML = node.textContent.replace(regex, '<mark class="highlight">$1</mark>');
                if(newHTML !== node.textContent) {
                    const span = document.createElement('span');
                    span.innerHTML = newHTML;
                    node.parentNode.replaceChild(span, node);
                }
            }
        });
    }
    
    // 自动刷新页面（每2分钟）
    setInterval(function() {
        const currentTime = new Date().toLocaleTimeString();
        console.log('Auto-refresh scheduled for: ' + currentTime);
    }, 120000);
    </script>
    
    <style>
        .requirements-preview {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .highlight {
            background-color: #fff3cd;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .d-flex.justify-between {
                flex-direction: column;
                gap: 10px;
            }
            
            .d-flex.justify-between > * {
                width: 100%;
            }
        }
        
        @media print {
            .nav-bar,
            .search-box,
            .stats-grid,
            .btn-group,
            .card.mt-3 {
                display: none !important;
            }
            
            .page-title {
                margin-bottom: 10px !important;
            }
            
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
        }
    </style>
</body>
</html>

<?php
if(isset($result)) mysqli_free_result($result);
if(isset($count_result)) mysqli_free_result($count_result);
if(isset($stats_result)) mysqli_free_result($stats_result);
mysqli_close($mysqli);
?>