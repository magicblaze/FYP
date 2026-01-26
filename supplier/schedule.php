<?php
// ==============================
// File: schedule.php
// Calendar Schedule viewing for Designer, Supplier, and Manager
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$user_type = $user['role']; // 'designer', 'supplier', or 'manager'
$user_id = null;
$user_name = '';
$schedules = [];

// Determine user ID based on role and dashboard link
// Note: schedule.php is in the supplier folder, so use ../ to go up one level
if ($user_type === 'designer') {
    $user_id = $user['designerid'];
    $user_name = $user['name'];
    $dashboardLink = '../designer/designer_dashboard.php';
} elseif ($user_type === 'supplier') {
    $user_id = $user['supplierid'];
    $user_name = $user['name'];
    $dashboardLink = 'dashboard.php';
} elseif ($user_type === 'manager') {
    $user_id = $user['managerid'];
    $user_name = $user['name'];
    $dashboardLink = '../manager/Manager_MyOrder.html';
} else {
    header('Location: login.php');
    exit;
}

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

// Fetch schedules based on user type
if ($user_type === 'designer') {
    // Designer sees schedules with design finish dates from Schedule table
    $sql = "
        SELECT 
            s.scheduleid,
            s.DesignFinishDate as FinishDate,
            o.orderid,
            o.odate as OrderDate,
            o.ostatus,
            c.budget,
            o.Requirements,
            c.cname as ClientName,
            d.designid,
            m.mname as ManagerName
        FROM `Schedule` s
        JOIN `Order` o ON s.orderid = o.orderid
        JOIN `Design` d ON o.designid = d.designid
        JOIN `Client` c ON o.clientid = c.clientid
        JOIN `Manager` m ON s.managerid = m.managerid
        WHERE d.designerid = ?
        ORDER BY s.DesignFinishDate ASC
    ";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} elseif ($user_type === 'supplier') {
    // Supplier sees schedules for orders that include their products
    $sql = "
        SELECT 
            op.orderdeliveryid,
            op.deliverydate as FinishDate,
            o.orderid,
            o.odate as OrderDate,
            op.status as ProductStatus,
            o.ostatus,
            c.budget,
            c.cname as ClientName,
            m.mname as ManagerName,
            p.pname as ProductName,
            op.quantity
        FROM `OrderDelivery` op
        JOIN `Order` o ON op.orderid = o.orderid
        JOIN `Product` p ON op.productid = p.productid
        JOIN `Client` c ON o.clientid = c.clientid
        JOIN `Manager` m ON op.managerid = m.managerid
        WHERE p.supplierid = ?
        ORDER BY op.deliverydate ASC
    ";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} elseif ($user_type === 'manager') {
    // Manager sees schedules for orders they manage from Schedule table
    $sql = "
        SELECT 
            s.scheduleid,
            s.OrderFinishDate as FinishDate,
            o.orderid,
            o.odate as OrderDate,
            o.ostatus,
            c.budget,
            o.Requirements,
            c.cname as ClientName,
            d.designid,
            des.dname as DesignerName
        FROM `Schedule` s
        JOIN `Order` o ON s.orderid = o.orderid
        JOIN `Design` d ON o.designid = d.designid
        JOIN `Designer` des ON d.designerid = des.designerid
        JOIN `Client` c ON o.clientid = c.clientid
        WHERE s.managerid = ?
        ORDER BY s.OrderFinishDate ASC
    ";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Build schedule array indexed by date
$schedule_by_date = [];
foreach ($schedules as $schedule) {
    if (!empty($schedule['FinishDate'])) {
        $date = date('Y-m-d', strtotime($schedule['FinishDate']));
        if (!isset($schedule_by_date[$date])) {
            $schedule_by_date[$date] = [];
        }
        $schedule_by_date[$date][] = $schedule;
    }
}

// Function to get status badge color
function getStatusBadgeClass($status) {
    switch(strtolower($status)) {
        case 'delivered':
        case 'completed':
            return 'bg-success';
        case 'shipped':
        case 'manufacturing':
            return 'bg-info';
        case 'processing':
        case 'designing':
            return 'bg-primary';
        case 'pending':
            return 'bg-warning';
        case 'cancelled':
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
    
    // Add empty cells for days before the first day of the month
    for ($i = 0; $i < $first_day_of_week; $i++) {
        $week[] = null;
    }
    
    // Add days of the month
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
    
    // Add remaining cells
    if (!empty($week)) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $calendar[] = $week;
    }
    
    return $calendar;
}

$calendar = generateCalendar($current_month, $current_year, $schedule_by_date);

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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar - <?php echo ucfirst($user_type); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .dashboard-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }

        .dashboard-header h2 {
            margin: 0;
            font-weight: 600;
        }

        .dashboard-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 3rem;
            margin-bottom: 2rem;
        }

        .calendar-nav a {
            background-color: #3498db;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .calendar-nav a:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            color: white;
        }

        .month-year {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .calendar-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .calendar-table table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
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
            transition: background-color 0.2s;
        }

        .calendar-table td:hover {
            background-color: #f8f9fa;
        }

        .calendar-table td.other-month {
            background-color: #f8f9fa;
            color: #ccc;
        }

        .day-number {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .day-number.other-month {
            color: #ccc;
        }

        .day-number.today {
            background-color: #3498db;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 50%;
            display: inline-block;
        }

        .schedule-item {
            background-color: #e8f4f8;
            border-left: 3px solid #3498db;
            padding: 0.4rem 0.6rem;
            margin: 0.3rem 0;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            display: block;
        }

        .schedule-item:hover {
            background-color: #d0e8f2;
            transform: translateX(2px);
            text-decoration: none;
            color: #2c3e50;
        }

        .schedule-item.bg-success {
            border-left-color: #155724;
            background-color: #d4edda;
        }

        .schedule-item.bg-info {
            border-left-color: #0c5460;
            background-color: #d1ecf1;
        }

        .schedule-item.bg-primary {
            border-left-color: #084298;
            background-color: #cfe2ff;
        }

        .schedule-item.bg-warning {
            border-left-color: #856404;
            background-color: #fff3cd;
        }

        .schedule-item.bg-danger {
            border-left-color: #721c24;
            background-color: #f8d7da;
        }

        .schedule-item-text {
            font-weight: 500;
            color: #2c3e50;
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
            border-bottom: 1px solid #ecf0f1;
            background-color: #f8f9fa;
            border-radius: 15px 15px 0 0;
        }

        .modal-title {
            color: #2c3e50;
            font-weight: 600;
        }

        .schedule-detail {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .schedule-detail:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: #3498db;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
        }

        .detail-value {
            color: #2c3e50;
            font-size: 1rem;
        }

        .badge {
            padding: 0.5rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge.bg-success {
            background-color: #155724 !important;
            color: white;
        }

        .badge.bg-info {
            background-color: #0c5460 !important;
            color: white;
        }

        .badge.bg-primary {
            background-color: #084298 !important;
            color: white;
        }

        .badge.bg-warning {
            background-color: #856404 !important;
            color: white;
        }

        .badge.bg-danger {
            background-color: #721c24 !important;
            color: white;
        }

        @media (max-width: 768px) {
            .calendar-table td {
                height: 100px;
                padding: 0.5rem;
                font-size: 0.85rem;
            }

            .calendar-table th {
                padding: 0.75rem 0.5rem;
                font-size: 0.9rem;
            }

            .day-number {
                font-size: 0.9rem;
            }

            .schedule-item {
                font-size: 0.7rem;
                padding: 0.3rem 0.4rem;
            }

            .dashboard-header h2 {
                font-size: 1.5rem;
            }

            .month-year {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    
    <!-- Navbar -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="<?= $dashboardLink ?>" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="<?= $dashboardLink ?>">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($user_name) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">

        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Schedules Found</h3>
                <p>You don't have any schedules assigned yet.</p>
            </div>
        <?php else: ?>
            <!-- Calendar Navigation -->
            <div class="calendar-nav">
                <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>">
                    <i class="fas fa-chevron-left me-2"></i>Previous
                </a>
                <div class="month-year">
                    <?php echo $month_name . ' ' . $current_year; ?>
                </div>
                <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>">
                    Next<i class="fas fa-chevron-right ms-2"></i>
                </a>
            </div>

            <!-- Calendar Table -->
            <div class="calendar-table">
                <table>
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
                                            <?php foreach ($day['schedules'] as $schedule): ?>
                                                <?php if ($user_type === 'designer'): ?>
                                                    <a class="schedule-item" 
                                                       data-bs-toggle="modal" 
                                                       data-bs-target="#scheduleModal<?php echo $schedule['scheduleid']; ?>"
                                                       title="Order #<?php echo $schedule['orderid']; ?> - <?php echo htmlspecialchars($schedule['ClientName']); ?>"
                                                       href="javascript:void(0);">
                                                        <span class="schedule-item-text">
                                                            Order #<?php echo $schedule['orderid']; ?> - <?php echo htmlspecialchars($schedule['ClientName']); ?>
                                                        </span>
                                                    </a>
                                                <?php elseif ($user_type === 'manager'): ?>
                                                    <a class="schedule-item <?php echo getStatusBadgeClass($schedule['ostatus']); ?>" 
                                                       data-bs-toggle="modal" 
                                                       data-bs-target="#scheduleModal<?php echo $schedule['scheduleid']; ?>"
                                                       title="Order #<?php echo $schedule['orderid']; ?> - <?php echo htmlspecialchars($schedule['ClientName']); ?>"
                                                       href="javascript:void(0);">
                                                        <span class="schedule-item-text">
                                                            Order #<?php echo $schedule['orderid']; ?> - <?php echo htmlspecialchars($schedule['ClientName']); ?>
                                                        </span>
                                                    </a>
                                                <?php else: ?>
                                                                     <a class="schedule-item <?php echo getStatusBadgeClass($schedule['ProductStatus']); ?>" 
                                                                         data-bs-toggle="modal" 
                                                                         data-bs-target="#scheduleModal<?php echo $schedule['orderdeliveryid']; ?>"
                                                                         title="<?php echo htmlspecialchars($schedule['ProductName']); ?>"
                                                                         href="javascript:void(0);">
                                                        <span class="schedule-item-text">
                                                            Delivery #<?php echo $schedule['orderdeliveryid']; ?> - <?php echo htmlspecialchars($schedule['ProductName']); ?>
                                                        </span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
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

    <!-- Schedule Detail Modals -->
    <?php foreach ($schedules as $schedule): ?>
        <?php if ($user_type === 'manager'): ?>
            <!-- Manager Modal: Show Order and Schedule information -->
            <div class="modal fade" id="scheduleModal<?php echo $schedule['scheduleid']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-calendar-check me-2"></i>Order #<?php echo $schedule['orderid']; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">

                            <div class="schedule-detail">
                                <div class="detail-label">Order ID</div>
                                <div class="detail-value">#<?php echo htmlspecialchars($schedule['orderid']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Client Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ClientName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Designer</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['DesignerName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Order Status</div>
                                <div class="detail-value">
                                    <span class="badge <?php echo getStatusBadgeClass($schedule['ostatus']); ?>">
                                        <?php echo htmlspecialchars($schedule['ostatus']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Order Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d H:i', strtotime($schedule['OrderDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Order Finish Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d', strtotime($schedule['FinishDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Budget</div>
                                <div class="detail-value">HK$<?php echo number_format($schedule['budget']); ?></div>
                            </div>

                            <?php if (!empty($schedule['Requirements'])): ?>
                                <div class="schedule-detail">
                                    <div class="detail-label">Requirements</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($schedule['Requirements']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($user_type === 'designer'): ?>
            <!-- Designer Modal: Show Design and Schedule information -->
            <div class="modal fade" id="scheduleModal<?php echo $schedule['scheduleid']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-calendar-check me-2"></i>Order #<?php echo $schedule['orderid']; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">

                            <div class="schedule-detail">
                                <div class="detail-label">Order ID</div>
                                <div class="detail-value">#<?php echo htmlspecialchars($schedule['orderid']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Client Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ClientName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Manager</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ManagerName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Order Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d H:i', strtotime($schedule['OrderDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Design Finish Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d', strtotime($schedule['FinishDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Budget</div>
                                <div class="detail-value">HK$<?php echo number_format($schedule['budget']); ?></div>
                            </div>

                            <?php if (!empty($schedule['Requirements'])): ?>
                                <div class="schedule-detail">
                                    <div class="detail-label">Requirements</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($schedule['Requirements']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Designer/Supplier Modal: Show Order Delivery information -->
            <div class="modal fade" id="scheduleModal<?php echo $schedule['orderdeliveryid']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-calendar-check me-2"></i>Delivery #<?php echo $schedule['orderdeliveryid']; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="schedule-detail">
                                <div class="detail-label">Order Delivery ID</div>
                                <div class="detail-value">#<?php echo htmlspecialchars($schedule['orderdeliveryid']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Product Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ProductName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Quantity</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['quantity']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Product Status</div>
                                <div class="detail-value">
                                    <span class="badge <?php echo getStatusBadgeClass($schedule['ProductStatus']); ?>">
                                        <?php echo htmlspecialchars($schedule['ProductStatus']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Client Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ClientName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Manager</div>
                                <div class="detail-value"><?php echo htmlspecialchars($schedule['ManagerName']); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Order Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d H:i', strtotime($schedule['OrderDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Delivery Date</div>
                                <div class="detail-value"><?php echo date('Y-m-d', strtotime($schedule['FinishDate'])); ?></div>
                            </div>

                            <div class="schedule-detail">
                                <div class="detail-label">Budget</div>
                                <div class="detail-value">HK$<?php echo number_format($schedule['budget']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
