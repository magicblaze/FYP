<?php
// ==============================
// File: Manager_Schedule_detail.php
// Calendar Schedule View for Manager
// ==============================
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

// 获取当前月份和年份
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// 验证月份和年份
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('m');
}
if ($current_year < 2000 || $current_year > 2100) {
    $current_year = date('Y');
}

$sql = "
    SELECT * 
    FROM `Schedule` sch
    WHERE sch.managerid = ?
    ORDER BY sch.OrderFinishDate ASC, sch.DesignFinishDate ASC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("SQL Prepare Error: " . $mysqli->error);
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    die("SQL Execute Error: " . $stmt->error);
}

$result = $stmt->get_result();
$schedules = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 按日期组织排程（订单完成日期和设计完成日期分别处理）
$order_finish_by_date = [];
$design_finish_by_date = [];

foreach ($schedules as $schedule) {
    // 处理订单完成日期（绿色）
    if (!empty($schedule['OrderFinishDate']) && $schedule['OrderFinishDate'] != '0000-00-00') {
        $date = date('Y-m-d', strtotime($schedule['OrderFinishDate']));
        if (!isset($order_finish_by_date[$date])) {
            $order_finish_by_date[$date] = [];
        }
        $order_finish_by_date[$date][] = array_merge($schedule, ['type' => 'order']);
    }
    
    // 处理设计完成日期（蓝色）
    if (!empty($schedule['DesignFinishDate']) && $schedule['DesignFinishDate'] != '0000-00-00') {
        $date = date('Y-m-d', strtotime($schedule['DesignFinishDate']));
        if (!isset($design_finish_by_date[$date])) {
            $design_finish_by_date[$date] = [];
        }
        $design_finish_by_date[$date][] = array_merge($schedule, ['type' => 'design']);
    }
}

// 合并两个数组用于日历显示
$combined_schedule_by_date = [];
foreach ($order_finish_by_date as $date => $items) {
    $combined_schedule_by_date[$date] = array_merge(
        $combined_schedule_by_date[$date] ?? [],
        $items
    );
}
foreach ($design_finish_by_date as $date => $items) {
    $combined_schedule_by_date[$date] = array_merge(
        $combined_schedule_by_date[$date] ?? [],
        $items
    );
}

// 获取未排程的订单（没有Schedule记录的订单）- 修正：使用设计师关联
$unscheduled_sql = "
    SELECT DISTINCT
        o.orderid,
        o.odate as OrderDate,
        o.ostatus as OrderStatus,
        c.budget,
        o.Requirements,
        c.cname as ClientName,
        c.ctel as ClientPhone,
        c.cemail as ClientEmail
    FROM `Order` o
    JOIN `Client` c ON o.clientid = c.clientid
    LEFT JOIN `Design` d ON o.designid = d.designid
    LEFT JOIN `Designer` des ON d.designerid = des.designerid
    LEFT JOIN `Schedule` sch ON o.orderid = sch.orderid
    WHERE des.managerid = ?  -- 设计师表中的经理ID（权限检查）
    AND sch.orderid IS NULL
    AND LOWER(o.ostatus) NOT IN ('waiting confirm', 'cancelled')
    " . ($order_id > 0 ? " AND o.orderid = ?" : "") . "
    ORDER BY o.odate DESC
    LIMIT 6
";

$unscheduled_stmt = $mysqli->prepare($unscheduled_sql);
if ($order_id > 0) {
    $unscheduled_stmt->bind_param("ii", $user_id, $order_id);
} else {
    $unscheduled_stmt->bind_param("i", $user_id);
}

$unscheduled_stmt->execute();
$unscheduled_result = $unscheduled_stmt->get_result();
$unscheduled_orders = $unscheduled_result->fetch_all(MYSQLI_ASSOC);
$unscheduled_stmt->close();

// 状态徽章颜色函数
function getStatusBadgeClass($status) {
    $status = strtolower(trim($status));
    switch($status) {
        case 'complete':
        case 'completed':
            return 'bg-success';
        case 'designing':
            return 'bg-info';
        case 'inprogress':
            return 'bg-primary';
        case 'pending':
        case 'waiting confirm':
            return 'bg-warning';
        case 'cancelled':
        case 'rejected':
        case 'reject':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

// 生成日历
function generateCalendar($month, $year, $schedule_by_date) {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $last_day = date('t', $first_day);
    $first_day_of_week = date('w', $first_day);
    
    $calendar = [];
    $week = [];
    
    // 添加第一周之前的空单元格
    for ($i = 0; $i < $first_day_of_week; $i++) {
        $week[] = null;
    }
    
    // 添加月份中的每一天
    for ($day = 1; $day <= $last_day; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $week[] = [
            'day' => $day,
            'date' => $date,
            'schedules' => isset($schedule_by_date[$date]) ? $schedule_by_date[$date] : []
        ];
        
        if (count($week) == 7) {
            $calendar[] = $week;
            $week = [];
        }
    }
    
    // 添加最后一周之后的空单元格
    if (!empty($week)) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $calendar[] = $week;
    }
    
    return $calendar;
}

$calendar = generateCalendar($current_month, $current_year, $combined_schedule_by_date);

// 导航
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$month_name = date('F', mktime(0, 0, 0, $current_month, 1));

// 统计数据
$total_orders = count(array_unique(array_column($schedules, 'orderid')));
$completed_orders = count(array_filter($schedules, function($s) {
    return isset($s['OrderStatus']) && in_array(strtolower($s['OrderStatus']), ['completed','complete']);
}));
$designing_orders = count(array_filter($schedules, function($s) {
    return isset($s['OrderStatus']) && strtolower($s['OrderStatus']) === 'designing';
}));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar - Manager - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        /* 日期样式 */
        .order-finish-date {
            color: #28a745; /* 绿色 */
            font-weight: bold;
        }
        
        .design-finish-date {
            color: #007bff; /* 蓝色 */
            font-weight: bold;
        }

        .date-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            margin: 2px;
            display: inline-block;
        }
        
        .order-finish-badge {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .design-finish-badge {
            background-color: #cfe2ff;
            color: #084298;
            border: 1px solid #b6d4fe;
        }
        
        /* 日历项目样式 */
        .calendar-order-item {
            background-color: #d4edda;
            border-left: 3px solid #28a745;
            padding: 0.4rem 0.6rem;
            margin: 0.3rem 0;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            text-decoration: none;
            color: #155724;
        }
        
        .calendar-design-item {
            background-color: #cfe2ff;
            border-left: 3px solid #007bff;
            padding: 0.4rem 0.6rem;
            margin: 0.3rem 0;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            text-decoration: none;
            color: #084298;
        }
        
        .calendar-order-item:hover {
            background-color: #c3e6cb;
            transform: translateX(3px);
            text-decoration: none;
            color: #155724;
        }
        
        .calendar-design-item:hover {
            background-color: #b6d4fe;
            transform: translateX(3px);
            text-decoration: none;
            color: #084298;
        }
        
        /* 基础样式 */
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header-section {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: none;
        }
        
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .calendar-container {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .calendar-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .calendar-nav a {
            background-color: #3498db;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .calendar-nav a:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            color: white;
        }
        
        .calendar-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .calendar-table th {
            background-color: #f8f9fa;
            color: #2c3e50;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .calendar-table td {
            width: 14.28%;
            height: 140px;
            padding: 0.75rem;
            border: 1px solid #ecf0f1;
            vertical-align: top;
            background-color: #fff;
            transition: all 0.2s;
            position: relative;
        }
        
        .calendar-table td:hover {
            background-color: #f8f9fa;
        }
        
        .calendar-table td.other-month {
            background-color: #fafafa;
            color: #bdc3c7;
        }
        
        .calendar-table td.today {
            background-color: #e8f4f8;
        }
        
        .day-number {
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .day-number.today {
            background-color: #3498db;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .day-number.other-month {
            color: #bdc3c7;
        }
        
        .unscheduled-list {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 1.5rem;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .detail-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #3498db;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        
        .detail-value {
            color: #2c3e50;
            font-size: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        
        @media (max-width: 768px) {
            .calendar-table td {
                height: 120px;
                padding: 0.5rem;
            }
            
            .calendar-order-item,
            .calendar-design-item {
                font-size: 0.7rem;
                padding: 0.3rem 0.4rem;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .calendar-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    <!-- 头部 -->
    <div class="header-section">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1 class="mb-2"><i class="fas fa-calendar-alt me-2"></i>Schedule Calendar</h1>
                    <p class="mb-0">View all your order schedules in calendar format</p>
                </div>
                <div>
                    <a href="Manager_Schedule.php" class="btn btn-light">
                        <i class="fas fa-list me-2"></i>List View
                    </a>
                    <?php if ($order_id > 0): ?>
                        <a href="Manager_Schedule_detail.php" class="btn btn-outline-light">
                            <i class="fas fa-calendar me-2"></i>All Schedules
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 主要内容 -->
    <div class="container mb-5">
        <!-- 统计卡片 -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $total_orders; ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $completed_orders; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $designing_orders; ?></div>
                        <div class="stat-label">Designing</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($unscheduled_orders); ?></div>
                        <div class="stat-label">Unscheduled</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 日历视图 -->
        <div class="calendar-container">
            <div class="calendar-header">
                <div class="calendar-title">
                    <?php echo $month_name . ' ' . $current_year; ?>
                </div>
                <div class="calendar-nav">
                    <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>">
                        Current Month
                    </a>
                    <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>

            <?php if (empty($schedules)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Schedules Found</h3>
                    <p>You don't have any schedules assigned yet.</p>
                </div>
            <?php else: ?>
                <!-- 日历表格 -->
                <div class="table-responsive">
                    <table class="calendar-table">
                        <thead>
                            <tr>
                                <th>Sunday</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                                <th>Saturday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calendar as $week): ?>
                                <tr>
                                    <?php foreach ($week as $day): ?>
                                        <?php if ($day === null): ?>
                                            <td class="other-month"></td>
                                        <?php else: ?>
                                            <td class="<?php echo (date('Y-m-d') === $day['date']) ? 'today' : ''; ?>">
                                                <div class="day-number <?php echo (date('Y-m-d') === $day['date']) ? 'today' : ''; ?>">
                                                    <?php echo $day['day']; ?>
                                                </div>
                                                <?php 
                                                if (isset($day['schedules'])) {
                                                    foreach ($day['schedules'] as $schedule): 
                                                        if ($schedule['type'] == 'order'): ?>
                                                            <a class="calendar-order-item" 
                                                               data-bs-toggle="modal" 
                                                               data-bs-target="#scheduleModal<?php echo $schedule['orderid'] . '_order'; ?>"
                                                               title="Order #<?php echo $schedule['orderid']; ?> - Order Completion"
                                                               href="javascript:void(0);">
                                                                <small><i class="fas fa-check-circle"></i> Order #<?php echo $schedule['orderid']; ?></small>
                                                            </a>
                                                        <?php else: ?>
                                                            <a class="calendar-design-item" 
                                                               data-bs-toggle="modal" 
                                                               data-bs-target="#scheduleModal<?php echo $schedule['orderid'] . '_design'; ?>"
                                                               title="Order #<?php echo $schedule['orderid']; ?> - Design Completion"
                                                               href="javascript:void(0);">
                                                                <small><i class="fas fa-paint-brush"></i> Order #<?php echo $schedule['orderid']; ?></small>
                                                            </a>
                                                        <?php endif;
                                                    endforeach; 
                                                }
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 未排程的订单 -->
        <?php if (!empty($unscheduled_orders)): ?>
            <div class="unscheduled-list">
                <h3 class="mb-4"><i class="fas fa-clock me-2"></i>Unscheduled Orders (<?php echo count($unscheduled_orders); ?>)</h3>
                <div class="row g-3">
                    <?php foreach ($unscheduled_orders as $order): ?>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Order #<?php echo $order['orderid']; ?></h6>
                                    <p class="card-text text-muted small mb-2">
                                        Client: <?php echo htmlspecialchars($order['ClientName']); ?>
                                        <br>Date: <?php echo date('Y-m-d', strtotime($order['OrderDate'])); ?>
                                    </p>
                                    <span class="badge <?php echo getStatusBadgeClass($order['OrderStatus']); ?>">
                                        <?php echo htmlspecialchars($order['OrderStatus']); ?>
                                    </span>
                                    <div class="mt-2">
                                        <a href="Manager_update_schedule.php?id=<?php echo $order['orderid']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-calendar-plus"></i> Add Schedule
                                        </a>
                                        <a href="Manager_view_order.php?id=<?php echo $order['orderid']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($unscheduled_orders) >= 6): ?>
                    <div class="mt-3 text-center">
                        <a href="Manager_Schedule.php?filter=unscheduled" class="btn btn-outline-secondary">
                            View All Unscheduled Orders
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 排程详情模态框（订单完成） -->
    <?php foreach ($schedules as $schedule): 
        if (!empty($schedule['OrderFinishDate']) && $schedule['OrderFinishDate'] != '0000-00-00'): ?>
            <div class="modal fade" id="scheduleModal<?php echo $schedule['orderid']; ?>_order" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-check-circle me-2"></i>Order Completion - Order #<?php echo $schedule['orderid']; ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-item">
                                        <div class="detail-label">Order Information</div>
                                        <div class="detail-value">
                                            Order #<?php echo $schedule['orderid']; ?>
                                            <br>
                                            <small class="text-muted">Order Date: <?php echo date('Y-m-d H:i', strtotime($schedule['OrderDate'])); ?></small>
                                            <br>
                                            <span class="badge <?php echo getStatusBadgeClass($schedule['OrderStatus']); ?> mt-1">
                                                <?php echo htmlspecialchars($schedule['OrderStatus']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Client Information</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($schedule['ClientName']); ?>
                                            <?php if (!empty($schedule['ClientPhone'])): ?>
                                                <br>
                                                <small class="text-muted">Phone: <?php echo htmlspecialchars($schedule['ClientPhone']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($schedule['ClientEmail'])): ?>
                                                <br>
                                                <small class="text-muted">Email: <?php echo htmlspecialchars($schedule['ClientEmail']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Budget</div>
                                        <div class="detail-value">
                                            <strong>HK$<?php echo isset($schedule['budget']) ? number_format($schedule['budget'], 2) : '0.00'; ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="detail-item">
                                        <div class="detail-label">Order Completion Date</div>
                                        <div class="detail-value">
                                            <span class="order-finish-date">
                                                <i class="fas fa-calendar-check"></i>
                                                <?php echo date('Y-m-d', strtotime($schedule['OrderFinishDate'])); ?>
                                            </span>
                                            <span class="date-badge order-finish-badge">Order Completion</span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($schedule['DesignFinishDate']) && $schedule['DesignFinishDate'] != '0000-00-00'): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Design Completion Date</div>
                                        <div class="detail-value">
                                            <span class="design-finish-date">
                                                <i class="fas fa-paint-brush"></i>
                                                <?php echo date('Y-m-d', strtotime($schedule['DesignFinishDate'])); ?>
                                            </span>
                                            <span class="date-badge design-finish-badge">Design Completion</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Designer Information</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($schedule['DesignerName'] ?? 'Not assigned'); ?>
                                            <?php if (!empty($schedule['DesignerEmail'])): ?>
                                                <br>
                                                <small class="text-muted">Email: <?php echo htmlspecialchars($schedule['DesignerEmail']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($schedule['DesignerPhone'])): ?>
                                                <br>
                                                <small class="text-muted">Phone: <?php echo htmlspecialchars($schedule['DesignerPhone']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($schedule['Requirements'])): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Requirements</div>
                                    <div class="detail-value">
                                        <div class="bg-light p-3 rounded" style="max-height: 150px; overflow-y: auto;">
                                            <?php echo nl2br(htmlspecialchars(substr($schedule['Requirements'], 0, 300))); ?>
                                            <?php if (strlen($schedule['Requirements']) > 300): ?>...<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="action-buttons">
                                <a href="Manager_update_schedule.php?id=<?php echo $schedule['orderid']; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit me-2"></i>Update Schedule
                                </a>
                                <a href="Manager_view_order.php?id=<?php echo $schedule['orderid']; ?>" class="btn btn-info">
                                    <i class="fas fa-eye me-2"></i>View Order Details
                                </a>
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-2"></i>Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- 排程详情模态框（设计完成） -->
    <?php foreach ($schedules as $schedule): 
        if (!empty($schedule['DesignFinishDate']) && $schedule['DesignFinishDate'] != '0000-00-00'): ?>
            <div class="modal fade" id="scheduleModal<?php echo $schedule['orderid']; ?>_design" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-paint-brush me-2"></i>Design Completion - Order #<?php echo $schedule['orderid']; ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-item">
                                        <div class="detail-label">Order Information</div>
                                        <div class="detail-value">
                                            Order #<?php echo $schedule['orderid']; ?>
                                            <br>
                                            <small class="text-muted">Order Date: <?php echo date('Y-m-d H:i', strtotime($schedule['OrderDate'])); ?></small>
                                            <br>
                                            <span class="badge <?php echo getStatusBadgeClass($schedule['OrderStatus']); ?> mt-1">
                                                <?php echo htmlspecialchars($schedule['OrderStatus']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Client Information</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($schedule['ClientName']); ?>
                                            <?php if (!empty($schedule['ClientPhone'])): ?>
                                                <br>
                                                <small class="text-muted">Phone: <?php echo htmlspecialchars($schedule['ClientPhone']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($schedule['ClientEmail'])): ?>
                                                <br>
                                                <small class="text-muted">Email: <?php echo htmlspecialchars($schedule['ClientEmail']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Budget</div>
                                        <div class="detail-value">
                                            <strong>HK$<?php echo isset($schedule['budget']) ? number_format($schedule['budget'], 2) : '0.00'; ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if (!empty($schedule['OrderFinishDate']) && $schedule['OrderFinishDate'] != '0000-00-00'): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Order Completion Date</div>
                                        <div class="detail-value">
                                            <span class="order-finish-date">
                                                <i class="fas fa-calendar-check"></i>
                                                <?php echo date('Y-m-d', strtotime($schedule['OrderFinishDate'])); ?>
                                            </span>
                                            <span class="date-badge order-finish-badge">Order Completion</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Design Completion Date</div>
                                        <div class="detail-value">
                                            <span class="design-finish-date">
                                                <i class="fas fa-paint-brush"></i>
                                                <?php echo date('Y-m-d', strtotime($schedule['DesignFinishDate'])); ?>
                                            </span>
                                            <span class="date-badge design-finish-badge">Design Completion</span>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Designer Information</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($schedule['DesignerName'] ?? 'Not assigned'); ?>
                                            <?php if (!empty($schedule['DesignerEmail'])): ?>
                                                <br>
                                                <small class="text-muted">Email: <?php echo htmlspecialchars($schedule['DesignerEmail']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($schedule['DesignerPhone'])): ?>
                                                <br>
                                                <small class="text-muted">Phone: <?php echo htmlspecialchars($schedule['DesignerPhone']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($schedule['Requirements'])): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Requirements</div>
                                    <div class="detail-value">
                                        <div class="bg-light p-3 rounded" style="max-height: 150px; overflow-y: auto;">
                                            <?php echo nl2br(htmlspecialchars(substr($schedule['Requirements'], 0, 300))); ?>
                                            <?php if (strlen($schedule['Requirements']) > 300): ?>...<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="action-buttons">
                                <a href="Manager_update_schedule.php?id=<?php echo $schedule['orderid']; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit me-2"></i>Update Schedule
                                </a>
                                <a href="Manager_view_order.php?id=<?php echo $schedule['orderid']; ?>" class="btn btn-info">
                                    <i class="fas fa-eye me-2"></i>View Order Details
                                </a>
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-2"></i>Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 键盘快捷键
            document.addEventListener('keydown', function(e) {
                // ESC 关闭所有模态框
                if (e.key === 'Escape') {
                    const modals = document.querySelectorAll('.modal.show');
                    if (modals.length > 0) {
                        bootstrap.Modal.getInstance(modals[0]).hide();
                    }
                }
                
                // 左箭头 - 上个月
                if (e.key === 'ArrowLeft') {
                    const prevLink = document.querySelector('.calendar-nav a:first-child');
                    if (prevLink) window.location.href = prevLink.href;
                }
                
                // 右箭头 - 下个月
                if (e.key === 'ArrowRight') {
                    const nextLink = document.querySelector('.calendar-nav a:last-child');
                    if (nextLink) window.location.href = nextLink.href;
                }
                
                // T - 今天
                if (e.key === 't' || e.key === 'T') {
                    const currentLink = document.querySelector('.calendar-nav a:nth-child(2)');
                    if (currentLink) window.location.href = currentLink.href;
                }
            });
            
            // 高亮今天
            const todayCells = document.querySelectorAll('.today');
            todayCells.forEach(cell => {
                cell.style.backgroundColor = '#e8f4f8';
                cell.style.border = '2px solid #3498db';
            });
            
            // 自动打开模态框（如果有ID参数）
            const urlParams = new URLSearchParams(window.location.search);
            const scheduleId = urlParams.get('scheduleid');
            if (scheduleId) {
                const modal = new bootstrap.Modal(document.getElementById('scheduleModal' + scheduleId));
                modal.show();
            }
        });
    </script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>
<?php
if(isset($mysqli) && $mysqli) {
    $mysqli->close();
}
?>