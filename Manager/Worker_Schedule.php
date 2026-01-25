<?php
// Worker_Schedule.php
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
$worker_id = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;

// Validate month and year
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('m');
}
if ($current_year < 2000 || $current_year > 2100) {
    $current_year = date('Y');
}

// Get all workers for selection dropdown
$workers_sql = "SELECT w.workerid, w.name, w.email, w.phone, w.certificate as specialty 
                FROM `worker` w 
                ORDER BY w.name";
$workers_result = mysqli_query($mysqli, $workers_sql);
$all_workers = mysqli_fetch_all($workers_result, MYSQLI_ASSOC);

// If no worker selected, default to first worker
if ($worker_id == 0 && !empty($all_workers)) {
    $worker_id = $all_workers[0]['workerid'];
}

// Get worker details if worker is selected
$worker_details = [];
$worker_schedules = [];
$schedule_by_date = [];
$order_details_cache = []; // 用於緩存訂單詳細信息

if ($worker_id > 0) {
    // Get worker details
    $worker_detail_sql = "SELECT w.workerid, w.name, w.email, w.phone, w.certificate as specialty,
                                 w.work_hours_per_week, w.available_hours_this_week
                          FROM `worker` w 
                          WHERE w.workerid = ?";
    $stmt = mysqli_prepare($mysqli, $worker_detail_sql);
    mysqli_stmt_bind_param($stmt, "i", $worker_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $worker_details = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // Get worker allocations with schedule information
    $allocations_sql = "SELECT wa.*, w.name as worker_name,
                               o.orderid, o.odate, o.ostatus, o.Requirements,
                               c.cname as client_name, c.budget, c.address, c.ctel as client_phone,
                               s.DesignFinishDate, s.OrderFinishDate
                        FROM `workerallocation` wa
                        JOIN `worker` w ON wa.workerid = w.workerid
                        JOIN `Order` o ON wa.orderid = o.orderid
                        JOIN `Client` c ON o.clientid = c.clientid
                        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                        WHERE wa.workerid = ? 
                        AND wa.status != 'Completed' 
                        AND wa.status != 'Cancelled'
                        AND s.DesignFinishDate IS NOT NULL
                        AND s.OrderFinishDate IS NOT NULL
                        ORDER BY s.DesignFinishDate ASC";
    $stmt = mysqli_prepare($mysqli, $allocations_sql);
    mysqli_stmt_bind_param($stmt, "i", $worker_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $worker_schedules = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    // Build order details cache
    foreach ($worker_schedules as $schedule) {
        $order_id = $schedule['orderid'];
        if (!isset($order_details_cache[$order_id])) {
            $order_details_cache[$order_id] = [
                'orderid' => $schedule['orderid'],
                'odate' => $schedule['odate'],
                'ostatus' => $schedule['ostatus'],
                'requirements' => $schedule['Requirements'],
                'client_name' => $schedule['client_name'],
                'client_budget' => $schedule['budget'],
                'client_address' => $schedule['address'],
                'client_phone' => $schedule['client_phone'],
                'design_finish_date' => $schedule['DesignFinishDate'],
                'order_finish_date' => $schedule['OrderFinishDate'],
                'allocation_status' => $schedule['status']
            ];
        }
    }

    // Calculate daily work hours for each date
    $daily_schedule = [];
    
    foreach ($worker_schedules as $schedule) {
        if ($schedule['DesignFinishDate'] && $schedule['OrderFinishDate']) {
            $design_finish = new DateTime($schedule['DesignFinishDate']);
            $order_finish = new DateTime($schedule['OrderFinishDate']);
            
            // Calculate work days between DesignFinishDate and OrderFinishDate
            $interval = DateInterval::createFromDateString('1 day');
            $period = new DatePeriod($design_finish, $interval, $order_finish->modify('+1 day'));
            
            $work_days_count = 0;
            foreach ($period as $day) {
                $day_of_week = $day->format('N'); // 1=Monday, 7=Sunday
                if ($day_of_week <= 5) { // Monday to Friday are work days
                    $work_days_count++;
                }
            }
            
            if ($work_days_count > 0) {
                // Assign tasks to each work day
                foreach ($period as $day) {
                    $day_of_week = $day->format('N');
                    if ($day_of_week <= 5) { // Work days only
                        $date_key = $day->format('Y-m-d');
                        
                        if (!isset($daily_schedule[$date_key])) {
                            $daily_schedule[$date_key] = [
                                'tasks' => []
                            ];
                        }
                        
                        $daily_schedule[$date_key]['tasks'][] = [
                            'order_id' => $schedule['orderid'],
                            'client_name' => $schedule['client_name'],
                            'allocation_status' => $schedule['status'],
                            'order_details' => $order_details_cache[$schedule['orderid']]
                        ];
                    }
                }
            }
        }
    }
    
    // Build schedule by date for calendar display
    foreach ($daily_schedule as $date => $schedule) {
        if (!isset($schedule_by_date[$date])) {
            $schedule_by_date[$date] = [];
        }
        $schedule_by_date[$date][] = $schedule;
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

// Function to get workload color based on number of tasks
function getWorkloadColor($task_count) {
    if ($task_count == 0) return '#e8f4f8'; // No work
    if ($task_count == 1) return '#d4edda'; // Light work (1 task)
    if ($task_count == 2) return '#fff3cd'; // Medium work (2 tasks)
    if ($task_count == 3) return '#f8d7da'; // Heavy work (3 tasks)
    return '#dc3545'; // Very heavy work (>3 tasks)
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Schedule - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
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

        .worker-selector {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .worker-info-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .calendar-nav a {
            background-color: #28a745;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .calendar-nav a:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
            transition: background-color 0.2s;
            position: relative;
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
            background-color: #28a745;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 50%;
            display: inline-block;
        }

        .workload-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            border-radius: 3px 3px 0 0;
        }

        .schedule-item {
            background-color: #e8f4f8;
            border-left: 3px solid #28a745;
            padding: 0.4rem 0.6rem;
            margin: 0.3rem 0;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
        }

        .schedule-item:hover {
            background-color: #d0e8f2;
            transform: translateX(2px);
        }

        .workload-legend {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }

        .workload-summary {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .workload-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-light {
            background-color: #e8f4f8;
            color: #2c3e50;
        }

        @media (max-width: 768px) {
            .calendar-table td {
                height: 120px;
                padding: 0.5rem;
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
        }
        
        /* Modal Styles */
        .order-detail-modal .modal-header {
            background-color: #28a745;
            color: white;
        }
        
        .order-detail-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-detail-item:last-child {
            border-bottom: none;
        }
        
        .order-detail-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 150px;
        }
        
        .order-detail-value {
            color: #495057;
        }
    </style>
</head>
<body>
    
    <!-- Header Navigation -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="Manager_MyOrder.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="Manager_introduct.php">Introduct</a></li>
                    <li class="nav-item"><a class="nav-link active" href="Manager_MyOrder.php">MyOrder</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_Schedule.php">Schedule</a></li>
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

    <!-- Order Detail Modal -->
    <div class="modal fade order-detail-modal" id="orderDetailModal" tabindex="-1" aria-labelledby="orderDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderDetailModalLabel">Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="orderDetailContent">
                    <!-- Order details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title mb-4">
            <i class="fas fa-calendar-alt me-2"></i>Worker Schedule Calendar
        </div>

        <!-- Worker Selection -->
        <div class="worker-selector">
            <h5 class="mb-3"><i class="fas fa-users me-2"></i>Select Worker</h5>
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="workerSelect" class="form-label">Worker:</label>
                    <select class="form-select" id="workerSelect" name="worker_id" onchange="this.form.submit()">
                        <option value="0">-- Select a Worker --</option>
                        <?php foreach ($all_workers as $worker): ?>
                            <option value="<?php echo $worker['workerid']; ?>" 
                                    <?php echo $worker_id == $worker['workerid'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($worker['name']); ?> 
                                (<?php echo htmlspecialchars($worker['specialty'] ?? 'No specialty'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="hidden" name="month" value="<?php echo $current_month; ?>">
                    <input type="hidden" name="year" value="<?php echo $current_year; ?>">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-search me-2"></i>View
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="Worker_Schedule.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <?php if ($worker_id > 0 && !empty($worker_details)): ?>
            <!-- Worker Information -->
            <div class="worker-info-card">
                <div class="row">
                    <div class="col-md-8">
                        <h4 class="mb-2"><?php echo htmlspecialchars($worker_details['name']); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($worker_details['email']); ?></p>
                                <p class="mb-1"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($worker_details['phone']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><i class="fas fa-certificate me-2"></i><?php echo htmlspecialchars($worker_details['specialty']); ?></p>
                                <p class="mb-0"><i class="fas fa-tasks me-2"></i><?php echo count($worker_schedules); ?> Active Assignments</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calendar Navigation -->
            <div class="calendar-nav">
                <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>&worker_id=<?php echo $worker_id; ?>">
                    <i class="fas fa-chevron-left me-2"></i>Previous
                </a>
                <div class="month-year">
                    <?php echo $month_name . ' ' . $current_year; ?>
                </div>
                <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>&worker_id=<?php echo $worker_id; ?>">
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
                                    <?php else: 
                                        $day_tasks = [];
                                        $task_count = 0;
                                        if (!empty($day['schedules'])) {
                                            foreach ($day['schedules'] as $schedule) {
                                                $day_tasks = array_merge($day_tasks, $schedule['tasks']);
                                                $task_count = count($day_tasks);
                                            }
                                        }
                                        $workload_color = getWorkloadColor($task_count);
                                    ?>
                                        <td style="background-color: <?php echo $workload_color; ?>;">
                                            <div class="workload-indicator" style="background-color: <?php echo $workload_color; ?>;"></div>
                                            <div class="day-number <?php echo (date('Y-m-d') === $day['date']) ? 'today' : ''; ?>">
                                                <?php echo $day['day']; ?>
                                            </div>
                                            
                                            <?php foreach ($day_tasks as $task): ?>
                                                <div class="schedule-item" 
                                                     onclick="showOrderDetail(<?php echo htmlspecialchars(json_encode($task['order_details']), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <small>Order #<?php echo $task['order_id']; ?></small>
                                                    <div class="text-muted" style="font-size: 0.65rem;">
                                                        <?php echo htmlspecialchars($task['client_name']); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>


        <?php elseif ($worker_id == 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Please select a worker to view their schedule.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No schedule information found for this worker or worker not found.
            </div>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Function to show order details in modal
    function showOrderDetail(orderDetails) {
        console.log(orderDetails); // 調試用
        
        // Create HTML for order details
        let html = `
            <div class="row">
                <div class="col-md-6">
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Order ID:</span>
                        <span class="order-detail-value">#${orderDetails.orderid}</span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Order Date:</span>
                        <span class="order-detail-value">${new Date(orderDetails.odate).toLocaleDateString()}</span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Order Status:</span>
                        <span class="order-detail-value"><span class="badge bg-success">${orderDetails.ostatus}</span></span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Allocation Status:</span>
                        <span class="order-detail-value"><span class="badge bg-info">${orderDetails.allocation_status}</span></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Client:</span>
                        <span class="order-detail-value">${orderDetails.client_name}</span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Client Phone:</span>
                        <span class="order-detail-value">${orderDetails.client_phone || 'N/A'}</span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Client Address:</span>
                        <span class="order-detail-value">${orderDetails.client_address || 'N/A'}</span>
                    </div>
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Budget:</span>
                        <span class="order-detail-value">$${parseFloat(orderDetails.client_budget).toFixed(2)}</span>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Design Finish Date:</span>
                        <span class="order-detail-value">${new Date(orderDetails.design_finish_date).toLocaleDateString()}</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="order-detail-item d-flex">
                        <span class="order-detail-label">Order Finish Date:</span>
                        <span class="order-detail-value">${new Date(orderDetails.order_finish_date).toLocaleDateString()}</span>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="order-detail-item">
                        <span class="order-detail-label d-block mb-2">Requirements:</span>
                        <div class="order-detail-value p-3 bg-light rounded">
                            ${orderDetails.requirements || 'No requirements specified'}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Set modal content
        document.getElementById('orderDetailContent').innerHTML = html;
        
        // Show modal
        let modal = new bootstrap.Modal(document.getElementById('orderDetailModal'));
        modal.show();
    }
    
    // Auto-refresh calendar every 30 seconds
    setTimeout(function() {
        window.location.reload();
    }, 30000);
    </script>
        <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>