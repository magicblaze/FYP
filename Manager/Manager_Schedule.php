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

// 获取搜索参数
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    switch(strtolower($status)) {
        case 'delivered':
        case 'completed':
        case 'complete':
            return 'bg-success';
        case 'shipped':
        case 'manufacturing':
        case 'waiting review':
            return 'bg-info';
        case 'processing':
        case 'designing':
        case 'drafting proposal':
            return 'bg-primary';
        case 'pending':
        case 'waiting confirm':
            return 'bg-warning';
        case 'cancelled':
        case 'reject':
            return 'bg-danger';
        case 'waiting payment':
            return 'bg-dark';
        default:
            return 'bg-secondary';
    }


    if(!empty($status_filter)) {
    if($status_filter == 'designing') {
        $where_conditions[] = "o.ostatus = 'designing'";
    } elseif($status_filter == 'completed') {
        $where_conditions[] = "o.ostatus = 'complete'";
    } elseif($status_filter == 'today') {
        $where_conditions[] = "DATE(s.OrderFinishDate) = CURDATE()";
    } elseif($status_filter == 'overdue') {
        $where_conditions[] = "s.OrderFinishDate < NOW() AND o.ostatus = 'designing'";
    } elseif($status_filter == 'upcoming') {
        $where_conditions[] = "s.OrderFinishDate > NOW() AND o.ostatus = 'designing'";
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 修正：根据数据库结构，Order表没有budget字段，从Client表获取budget
$sql = "SELECT o.orderid, o.odate, c.budget, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone,
               d.designid, d.designName as design_name, d.expect_price as design_price, d.tag as design_tag,
               s.scheduleid, s.OrderFinishDate, s.DesignFinishDate,
               DATEDIFF(s.OrderFinishDate, CURDATE()) as days_remaining
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY s.OrderFinishDate ASC, o.odate DESC";

$result = mysqli_query($mysqli, $sql);

if(!$result) {
    die("Database Error: " . mysqli_error($mysqli));
}

// 统计查询 - 修复：使用相同的设计师关联逻辑
$stats_sql = "SELECT 
                                COUNT(*) as total_scheduled,
                                SUM(CASE WHEN o.ostatus = 'designing' THEN 1 ELSE 0 END) as total_designing,
                                SUM(CASE WHEN o.ostatus = 'complete' THEN 1 ELSE 0 END) as total_completed,
                                SUM(CASE WHEN s.OrderFinishDate < NOW() AND o.ostatus = 'designing' THEN 1 ELSE 0 END) as total_overdue,
                                SUM(CASE WHEN DATE(s.OrderFinishDate) = CURDATE() THEN 1 ELSE 0 END) as total_today
                            FROM `Order` o
                            LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                            LEFT JOIN `Design` d ON o.designid = d.designid
                            WHERE (o.ostatus = 'designing' OR o.ostatus = 'complete')
                            AND EXISTS (SELECT 1 FROM `Design` d2 
                                                 JOIN `Designer` des ON d2.designerid = des.designerid 
                                                 WHERE d2.designid = o.designid AND des.managerid = $user_id)";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);

// 每周完成统计 - 修复：使用相同的设计师关联逻辑
$weekly_completed_sql = "SELECT COUNT(*) as weekly_completed
                        FROM `Order` o
                        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                        LEFT JOIN `Design` d ON o.designid = d.designid
                        WHERE o.ostatus = 'complete' 
                        AND YEARWEEK(s.OrderFinishDate, 1) = YEARWEEK(CURDATE(), 1)
                        AND EXISTS (SELECT 1 FROM `Design` d2 
                                   JOIN `Designer` des ON d2.designerid = des.designerid 
                                   WHERE d2.designid = o.designid AND des.managerid = $user_id)";
$weekly_result = mysqli_query($mysqli, $weekly_completed_sql);
$weekly_stats = mysqli_fetch_assoc($weekly_result);
?>

<!DOCTYPE html>
<html lang="en">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Schedule Management - HappyDesign</title>
    <style>
        /* 状态标签 */
        .status-designing {
            background-color: #17a2b8;
            color: white;
        }
        .status-completed {
            background-color: #28a745;
            color: white;
        }
        
        /* 统计卡片 */
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
            border: 1px solid #e0e0e0;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }
        
        .stat-card.designing .stat-value { color: #17a2b8; }
        .stat-card.completed .stat-value { color: #28a745; }
        .stat-card.overdue .stat-value { color: #dc3545; }
        .stat-card.today .stat-value { color: #ffc107; }
        .stat-card.progress .stat-value { color: #6f42c1; }
        
        /* 过滤器 */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 8px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-tab:hover {
            background: #e9ecef;
        }
        
        .filter-tab.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* 倒计时 */
        .countdown {
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .countdown-overdue {
            background-color: #dc3545;
            color: white;
        }
        
        .countdown-today {
            background-color: #ffc107;
            color: #212529;
        }
        
        .countdown-upcoming {
            background-color: #28a745;
            color: white;
        }
        
        /* 时间线指示器 */
        .timeline-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .timeline-today { background-color: #007bff; }
        .timeline-overdue { background-color: #dc3545; }
        .timeline-upcoming { background-color: #28a745; }
        
        /* 状态指示器 */
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-indicator.designing { background-color: #17a2b8; }
        .status-indicator.completed { background-color: #28a745; }
        
        /* 添加新的样式用于显示两个日期 */
        .date-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .date-label {
            font-size: 11px;
            color: #666;
        }
        .date-value {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Schedule Management - <?php echo htmlspecialchars($user_name); ?></h1
        
        <!-- 过滤器 -->
        <div class="filter-tabs no-print">
            <div class="filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php'">All</div>
            <div class="filter-tab <?php echo $status_filter == 'designing' ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php?status=designing'">
                <span class="status-indicator designing"></span>Designing
            </div>
            <div class="filter-tab <?php echo $status_filter == 'completed' ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php?status=completed'">
                <span class="status-indicator completed"></span>Completed
            </div>
            <div class="filter-tab <?php echo $status_filter == 'today' ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php?status=today'">
                <span class="timeline-indicator timeline-today"></span>Order Due Today
            </div>
            <div class="filter-tab <?php echo $status_filter == 'overdue' ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php?status=overdue'">
                <span class="timeline-indicator timeline-overdue"></span>Order Overdue
            </div>
            <div class="filter-tab <?php echo $status_filter == 'upcoming' ? 'active' : ''; ?>" 
                 onclick="window.location.href='Manager_Schedule.php?status=upcoming'">
                <span class="timeline-indicator timeline-upcoming"></span>Order Upcoming
            </div>
        </div>
        
        <?php
        if(mysqli_num_rows($result) == 0){
            echo '<div class="alert alert-info">
                <div>
                    <strong>No scheduled orders found' . (!empty($search) ? ' matching your search criteria.' : ' at the moment.') . '</strong>
                    <p class="mb-0">Orders that have been approved will appear here with their schedule.</p>
                </div>
            </div>';
        } else {
            $total_scheduled = $stats['total_scheduled'] ?? 0;
        ?>
        
        <!-- 统计卡片 -->
        <div class="stats-grid no-print">
            <div class="stat-card designing">
                <div class="stat-value"><?php echo $stats['total_designing'] ?? 0; ?></div>
                <div class="stat-label">In Progress</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">Active orders being designed</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-value"><?php echo $stats['total_completed'] ?? 0; ?></div>
                <div class="stat-label">Completed</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">Successfully delivered orders</div>
            </div>
            <div class="stat-card overdue">
                <div class="stat-value"><?php echo $stats['total_overdue'] ?? 0; ?></div>
                <div class="stat-label">Order Overdue</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">Past order due date</div>
            </div>
            <div class="stat-card today">
                <div class="stat-value"><?php echo $stats['total_today'] ?? 0; ?></div>
                <div class="stat-label">Order Due Today</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">Orders due today</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $weekly_stats['weekly_completed'] ?? 0; ?></div>
                <div class="stat-label">This Week</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">Completed this week</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_scheduled; ?></div>
                <div class="stat-label">Total Scheduled</div>
                <div style="font-size: 12px; color: #888; margin-top: 5px;">All scheduled orders</div>
            </div>
        </div>
        
        <!-- 订单表格 -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Order Date</th>
                        <th>Order Finish Date</th>
                        <th>Design Finish Date</th>
                        <th>Time Left</th>
                        <th>Budget</th>
                        <th>Design</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    while($row = mysqli_fetch_assoc($result)): 
                        // 确定倒计时样式 - 基于 OrderFinishDate
                        $countdown_class = '';
                        $countdown_text = '';
                        $order_finish_date = $row['OrderFinishDate'];
                        $design_finish_date = $row['DesignFinishDate'];
                        
                        if(strtolower($row['ostatus']) == 'complete' || strtolower($row['ostatus']) == 'completed') {
                            $countdown_class = 'countdown-upcoming';
                            $countdown_text = 'Completed';
                        } elseif(!empty($order_finish_date) && $order_finish_date != '0000-00-00') {
                            $days_remaining = $row['days_remaining'];
                            if($days_remaining < 0) {
                                $countdown_class = 'countdown-overdue';
                                $countdown_text = abs($days_remaining) . ' days overdue';
                            } elseif($days_remaining == 0) {
                                $countdown_class = 'countdown-today';
                                $countdown_text = 'Due today';
                            } elseif($days_remaining > 0) {
                                $countdown_class = 'countdown-upcoming';
                                $countdown_text = $days_remaining . ' days left';
                            }
                        } else {
                            $countdown_class = 'countdown-upcoming';
                            $countdown_text = 'No date set';
                        }
                    ?>
                    <tr>
                        <td><strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong></td>
                        <td>
                            <div class="d-flex flex-column">
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <small class="text-muted">Email: <?php echo htmlspecialchars($row["client_email"]); ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?php echo in_array(strtolower($row["ostatus"]), ['completed','complete']) ? 'status-completed' : 'status-designing'; ?>">
                                <?php echo htmlspecialchars($row["ostatus"]); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["odate"]) && $row["odate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d', strtotime($row["odate"]));
                            } else {
                                echo '<span class="text-muted">Not set</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00'){
                                echo date('Y-m-d', strtotime($row["OrderFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if(isset($row["DesignFinishDate"]) && $row["DesignFinishDate"] != '0000-00-00'){
                                echo date('Y-m-d', strtotime($row["DesignFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="countdown <?php echo $countdown_class; ?>">
                                <?php echo $countdown_text; ?>
                            </span>
                        </td>
                        <td><strong class="text-success">HK$<?php echo isset($row["budget"]) ? number_format($row["budget"], 2) : '0.00'; ?></strong></td>
                        <td>
                        <?php if(!empty($row["designid"])): ?>
                        <small>Design #<?php echo htmlspecialchars($row["designid"]); ?></small><br>
                        <small class="text-muted"><?php echo htmlspecialchars(substr($row["design_name"] ?? $row["design_tag"] ?? '', 0, 20)); ?></small>
                        <?php else: ?>
                        <span class="text-muted">No design</span>
                        <?php endif; ?>
                        </td>
                        <td class="no-print">
                            <div class="btn-group">
                                <button onclick="viewOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">View Order</button>
                                <button onclick="viewSchedule(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">View Schedule</button>
                                <?php if($row['ostatus'] == 'Designing'): ?>
                                <button onclick="markComplete(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-success">Complete</button>
                                <?php endif; ?>
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
                    <strong>Showing <?php echo mysqli_num_rows($result); ?> scheduled orders</strong>
                    <p class="text-muted mb-0">
                        <?php 
                        $completed_count = $stats['total_completed'] ?? 0;
                        $designing_count = $stats['total_designing'] ?? 0;
                        echo "$completed_count completed, $designing_count in progress";
                        ?>
                    </p>
                </div>
                <div class="btn-group no-print">
                    <button onclick="printPage()" class="btn btn-outline">Print Schedule</button>
                </div>
            </div>
        </div>
        
        <?php
        }
        ?>
        
        <!-- 返回按钮 -->
        <div class="d-flex justify-between mt-4 no-print">
            <div class="d-flex align-center">
                <span class="text-muted">Last updated: <?php echo date('Y-m-d H:i'); ?></span>
            </div>
        </div>
    </div>
    
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + orderId;
    }

    function viewSchedule(orderId) {
        window.location.href = 'Manager_Schedule_detail.php?id=' + orderId;
    }

    
    function markComplete(orderId) {
        if(confirm('Are you sure you want to mark order #' + orderId + ' as completed?')) {
            window.location.href = 'Manager_mark_complete.php?id=' + orderId;
        }
    }
    
    function printPage() {
        window.print();
    }
    
    // 快捷键支持
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            if(e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder.php';
            }
        });
    });
    </script>
            <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>

<?php
// 清理资源
if(isset($stats_result)) {
    mysqli_free_result($stats_result);
}
if(isset($weekly_result)) {
    mysqli_free_result($weekly_result);
}
if(isset($result)) {
    mysqli_free_result($result);
}
?>