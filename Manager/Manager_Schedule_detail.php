<?php
// ==============================
// File: Manager_Schedule_detail.php
// Calendar Schedule View for Manager
// ==============================
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Get current month and year
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('m');
}
if ($current_year < 2000 || $current_year > 2100) {
    $current_year = date('Y');
}

// Get order ID (if provided)
$order_id = isset($_GET['orderid']) ? (int)$_GET['orderid'] : 0;

// Get all schedules managed by current manager
$sql = "
    SELECT DISTINCT
        o.orderid,
        o.odate as OrderDate,
        o.ostatus as OrderStatus,
        o.budget,
        o.Requirements,
        c.cname as ClientName,
        c.ctel as ClientPhone,
        c.cemail as ClientEmail,
        d.designid,
        des.dname as DesignerName,
        des.demail as DesignerEmail,
        des.dtel as DesignerPhone,
        sch.OrderFinishDate as OrderFinishDate,
        sch.DesignFinishDate as DesignFinishDate,
        sch.scheduleid
    FROM `Order` o
    JOIN `Client` c ON o.clientid = c.clientid
    LEFT JOIN `Design` d ON o.designid = d.designid
    LEFT JOIN `Designer` des ON d.designerid = des.designerid
    LEFT JOIN `Schedule` sch ON o.orderid = sch.orderid
    LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
    WHERE sch.managerid = ? 
    AND op.managerid = ?
    AND sch.orderid IS NOT NULL
    AND LOWER(o.ostatus) NOT IN ('pending', 'cancelled')
    " . ($order_id > 0 ? " AND o.orderid = ?" : "") . "
    ORDER BY sch.OrderFinishDate ASC, sch.DesignFinishDate ASC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("SQL Prepare Error: " . $mysqli->error);
}

if ($order_id > 0) {
    $stmt->bind_param("iii", $user_id, $user_id, $order_id);
} else {
    $stmt->bind_param("ii", $user_id, $user_id);
}

if (!$stmt->execute()) {
    die("SQL Execute Error: " . $stmt->error);
}

$result = $stmt->get_result();
$schedules = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organize schedules by date
$order_finish_by_date = [];
$design_finish_by_date = [];

foreach ($schedules as $schedule) {
    // Handle order finish date (green)
    if (!empty($schedule['OrderFinishDate']) && $schedule['OrderFinishDate'] != '0000-00-00') {
        $date = date('Y-m-d', strtotime($schedule['OrderFinishDate']));
        if (!isset($order_finish_by_date[$date])) {
            $order_finish_by_date[$date] = [];
        }
        $order_finish_by_date[$date][] = array_merge($schedule, ['type' => 'order']);
    }
    
    // Handle design finish date (blue)
    if (!empty($schedule['DesignFinishDate']) && $schedule['DesignFinishDate'] != '0000-00-00') {
        $date = date('Y-m-d', strtotime($schedule['DesignFinishDate']));
        if (!isset($design_finish_by_date[$date])) {
            $design_finish_by_date[$date] = [];
        }
        $design_finish_by_date[$date][] = array_merge($schedule, ['type' => 'design']);
    }
}

// Merge arrays for calendar display
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

// Get unscheduled orders (orders without Schedule records)
$unscheduled_sql = "
    SELECT DISTINCT
        o.orderid,
        o.odate as OrderDate,
        o.ostatus as OrderStatus,
        o.budget,
        o.Requirements,
        c.cname as ClientName,
        c.ctel as ClientPhone,
        c.cemail as ClientEmail
    FROM `Order` o
    JOIN `Client` c ON o.clientid = c.clientid
    LEFT JOIN `Schedule` sch ON o.orderid = sch.orderid
    LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
    WHERE op.managerid = ?
    AND sch.orderid IS NULL
    AND LOWER(o.ostatus) NOT IN ('pending', 'cancelled')
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

// Status badge color function
function getStatusBadgeClass($status) {
    $status = strtolower(trim($status));
    switch($status) {
        case 'completed':
            return 'bg-success';
        case 'designing':
            return 'bg-info';
        case 'inprogress':
            return 'bg-primary';
        case 'pending':
            return 'bg-warning';
        case 'cancelled':
        case 'rejected':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

// Generate calendar
function generateCalendar($month, $year, $schedule_by_date) {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $last_day = date('t', $first_day);
    $first_day_of_week = date('w', $first_day);
    
    $calendar = [];
    $week = [];
    
    // Add empty cells before first day
    for ($i = 0; $i < $first_day_of_week; $i++) {
        $week[] = null;
    }
    
    // Add each day of the month
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
    
    // Add empty cells after last day
    if (!empty($week)) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $calendar[] = $week;
    }
    
    return $calendar;
}

$calendar = generateCalendar($current_month, $current_year, $combined_schedule_by_date);

// Navigation
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

// Statistics
$total_orders = count(array_unique(array_column($schedules, 'orderid')));
$completed_orders = count(array_filter($schedules, function($s) {
    return strtolower($s['OrderStatus']) === 'completed';
}));
$designing_orders = count(array_filter($schedules, function($s) {
    return strtolower($s['OrderStatus']) === 'designing';
}));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Schedule Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        /* Calendar styling */
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
        
        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
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
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .calendar-nav a {
            background-color: #3498db;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0.25rem;
        }
        
        .calendar-nav a:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
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
            height: 120px;
            padding: 0.75rem;
            border: 1px solid #ecf0f1;
            vertical-align: top;
            background-color: #fff;
            transition: all 0.2s;
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
            font-size: 1rem;
        }
        
        .day-number.today {
            background-color: #3498db;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #3498db;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .unscheduled-list {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .calendar-table td {
                height: 100px;
                padding: 0.5rem;
                font-size: 0.85rem;
            }
            
            .calendar-order-item,
            .calendar-design-item {
                font-size: 0.65rem;
                padding: 0.25rem 0.4rem;
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
    <!-- Header Navigation (matching Manager_MyOrder.php style) -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="Manager_MyOrder.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="Manager_introduct.php">Introduct</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_MyOrder.php">MyOrder</a></li>
                    <li class="nav-item"><a class="nav-link active" href="Manager_Schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?php echo htmlspecialchars($user_name); ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-calendar-alt me-2"></i>Schedule Calendar
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-number"><?php echo $total_orders; ?></div>
                    <div class="stat-label">
                        <i class="fas fa-list me-1"></i>Total Orders
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-number"><?php echo $completed_orders; ?></div>
                    <div class="stat-label">
                        <i class="fas fa-check-circle me-1"></i>Completed
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-number"><?php echo $designing_orders; ?></div>
                    <div class="stat-label">
                        <i class="fas fa-pencil-alt me-1"></i>Designing
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-number"><?php echo count($unscheduled_orders); ?></div>
                    <div class="stat-label">
                        <i class="fas fa-clock me-1"></i>Unscheduled
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar View -->
        <div class="calendar-container">
            <div class="calendar-header">
                <div class="calendar-title">
                    <?php echo $month_name . ' ' . $current_year; ?>
                    <?php if ($order_id > 0): ?>
                        <small class="text-muted ms-2">- Order #<?php echo $order_id; ?></small>
                    <?php endif; ?>
                </div>
                <div class="calendar-nav">
                    <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?><?php echo $order_id > 0 ? '&orderid=' . $order_id : ''; ?>">
                        <i class="fas fa-chevron-left"></i>Previous
                    </a>
                    <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?><?php echo $order_id > 0 ? '&orderid=' . $order_id : ''; ?>">
                        <i class="fas fa-calendar"></i>Today
                    </a>
                    <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?><?php echo $order_id > 0 ? '&orderid=' . $order_id : ''; ?>">
                        Next<i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>

            <table class="calendar-table">
                <thead>
                    <tr>
                        <th><i class="fas fa-sun"></i> Sun</th>
                        <th><i class="fas fa-moon"></i> Mon</th>
                        <th><i class="fas fa-cloud"></i> Tue</th>
                        <th><i class="fas fa-cloud"></i> Wed</th>
                        <th><i class="fas fa-cloud"></i> Thu</th>
                        <th><i class="fas fa-cloud"></i> Fri</th>
                        <th><i class="fas fa-star"></i> Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calendar as $week): ?>
                    <tr>
                        <?php foreach ($week as $day): ?>
                        <td <?php echo $day === null ? 'class="other-month"' : (date('Y-m-d') === $day['date'] ? 'class="today"' : ''); ?>>
                            <?php if ($day !== null): ?>
                                <div class="day-number <?php echo date('Y-m-d') === $day['date'] ? 'today' : ''; ?>">
                                    <?php echo $day['day']; ?>
                                </div>
                                <?php foreach ($day['schedules'] as $schedule): ?>
                                    <a href="Manager_update_schedule.php?id=<?php echo $schedule['orderid']; ?>" 
                                       class="<?php echo $schedule['type'] === 'order' ? 'calendar-order-item' : 'calendar-design-item'; ?>"
                                       title="Order #<?php echo $schedule['orderid']; ?> - <?php echo htmlspecialchars($schedule['ClientName']); ?>">
                                        <?php echo htmlspecialchars($schedule['ClientName']); ?> (#<?php echo $schedule['orderid']; ?>)
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Unscheduled Orders -->
        <?php if (!empty($unscheduled_orders)): ?>
        <div class="unscheduled-list">
            <h5 class="mb-3">
                <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Unscheduled Orders
            </h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><i class="fas fa-hashtag me-1"></i>Order ID</th>
                            <th><i class="fas fa-user me-1"></i>Client</th>
                            <th><i class="fas fa-calendar me-1"></i>Order Date</th>
                            <th><i class="fas fa-dollar-sign me-1"></i>Budget</th>
                            <th><i class="fas fa-cogs me-1"></i>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unscheduled_orders as $unscheduled): ?>
                        <tr>
                            <td><strong>#<?php echo $unscheduled['orderid']; ?></strong></td>
                            <td><?php echo htmlspecialchars($unscheduled['ClientName']); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($unscheduled['OrderDate'])); ?></td>
                            <td><strong class="text-success">$<?php echo number_format($unscheduled['budget'], 2); ?></strong></td>
                            <td>
                                <a href="Manager_update_schedule.php?id=<?php echo $unscheduled['orderid']; ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus me-1"></i>Schedule
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="unscheduled-list">
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h5>All Orders Scheduled</h5>
                <p class="text-muted mb-0">Great! All your orders have been scheduled.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Legend -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-info-circle me-2"></i>Legend
                </h6>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <span class="calendar-order-item">Order Finish Date</span>
                            <small class="text-muted ms-2">Green - Order completion deadline</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <span class="calendar-design-item">Design Finish Date</span>
                            <small class="text-muted ms-2">Blue - Design completion deadline</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="Manager_Schedule.php" class="btn btn-secondary">
                <i class="fas fa-list me-2"></i>List View
            </a>
            <small class="text-muted">Last updated: <?php echo date('Y-m-d H:i:s'); ?></small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if(isset($result)) mysqli_free_result($result);
if(isset($unscheduled_result)) mysqli_free_result($unscheduled_result);
mysqli_close($mysqli);
?>
