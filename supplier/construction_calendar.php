<?php
// supplier/construction_calendar.php
// Construction calendar with month navigation and order details modal
require_once dirname(__DIR__) . '/config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$supplier_id = $user['supplierid'];
$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;

if ($order_id <= 0) {
    header('Location: ProjectWorkerManagement.php');
    exit;
}

// Verify order belongs to this supplier
$verify_sql = "SELECT orderid FROM `Order` WHERE orderid = ? AND supplierid = ?";
$verify_stmt = mysqli_prepare($mysqli, $verify_sql);
mysqli_stmt_bind_param($verify_stmt, "ii", $order_id, $supplier_id);
mysqli_stmt_execute($verify_stmt);
if (mysqli_num_rows(mysqli_stmt_get_result($verify_stmt)) == 0) {
    header('Location: ProjectWorkerManagement.php');
    exit;
}
mysqli_stmt_close($verify_stmt);

// Create extension table if not exists
$create_ext_table = "
CREATE TABLE IF NOT EXISTS `ConstructionExtension` (
    `extension_id` INT NOT NULL AUTO_INCREMENT,
    `orderid` INT NOT NULL,
    `requested_end_date` DATE NOT NULL,
    `reason` TEXT,
    `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    `extension_version` INT NOT NULL DEFAULT 1,
    `requested_by` VARCHAR(50) NOT NULL,
    `requested_by_id` INT NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `responded_at` TIMESTAMP NULL,
    `response_notes` TEXT,
    PRIMARY KEY (`extension_id`),
    KEY `orderid_idx` (`orderid`),
    FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($mysqli, $create_ext_table);

// Create notification table if not exists
$create_notify_table = "
CREATE TABLE IF NOT EXISTS `Notification` (
    `notification_id` INT NOT NULL AUTO_INCREMENT,
    `user_type` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `orderid` INT DEFAULT NULL,
    `message` TEXT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `is_read` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `user_idx` (`user_type`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($mysqli, $create_notify_table);

// Handle Mark as Complete - Changes status to "Waiting for inspection"
$early_complete_message = '';
$early_complete_error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["mark_complete"])) {
    $actual_end_date = date('Y-m-d');
    
    // Update status to "Waiting for inspection"
    $update_sql = "UPDATE `Order` SET ostatus = 'Waiting for inspection', actual_completion_date = ? WHERE orderid = ?";
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "si", $actual_end_date, $order_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $early_complete_message = "Construction marked as completed! Status changed to 'Waiting for inspection'. Client has been notified.";
        
        // Get client info
        $client_sql = "SELECT clientid FROM `Order` WHERE orderid = ?";
        $client_stmt = mysqli_prepare($mysqli, $client_sql);
        mysqli_stmt_bind_param($client_stmt, "i", $order_id);
        mysqli_stmt_execute($client_stmt);
        $client_result = mysqli_stmt_get_result($client_stmt);
        $client_row = mysqli_fetch_assoc($client_result);
        $client_id = $client_row['clientid'];
        mysqli_stmt_close($client_stmt);
        
        // Notify client that construction is complete and waiting for inspection
        $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                       VALUES ('client', ?, ?, 'Construction work has been completed for Order #" . $order_id . ". Please conduct inspection and approve the completion.', 'construction_complete', NOW())";
        $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
        mysqli_stmt_bind_param($notify_stmt, "ii", $client_id, $order_id);
        mysqli_stmt_execute($notify_stmt);
        mysqli_stmt_close($notify_stmt);
        
        // Also notify manager
        $manager_sql = "SELECT managerid FROM `Schedule` WHERE orderid = ? LIMIT 1";
        $manager_stmt = mysqli_prepare($mysqli, $manager_sql);
        mysqli_stmt_bind_param($manager_stmt, "i", $order_id);
        mysqli_stmt_execute($manager_stmt);
        $manager_result = mysqli_stmt_get_result($manager_stmt);
        $manager_row = mysqli_fetch_assoc($manager_result);
        if ($manager_row) {
            $manager_id = $manager_row['managerid'];
            $notify_manager_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                                   VALUES ('manager', ?, ?, 'Construction completed for Order #" . $order_id . ". Waiting for client inspection.', 'construction_complete', NOW())";
            $notify_manager_stmt = mysqli_prepare($mysqli, $notify_manager_sql);
            mysqli_stmt_bind_param($notify_manager_stmt, "ii", $manager_id, $order_id);
            mysqli_stmt_execute($notify_manager_stmt);
            mysqli_stmt_close($notify_manager_stmt);
        }
        mysqli_stmt_close($manager_stmt);
    } else {
        $early_complete_error = "Failed to update status.";
    }
    mysqli_stmt_close($update_stmt);
}

// Handle Extension Request
$extension_message = '';
$extension_error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["request_extension"])) {
    $new_end_date = $_POST['new_end_date'] ?? '';
    $extension_reason = $_POST['extension_reason'] ?? '';
    
    if (empty($new_end_date)) {
        $extension_error = "Please select a new end date.";
    } elseif (strtotime($new_end_date) <= strtotime(date('Y-m-d'))) {
        $extension_error = "New end date must be after today.";
    } else {
        // Get current version
        $version_sql = "SELECT IFNULL(MAX(extension_version), 0) + 1 as new_version FROM ConstructionExtension WHERE orderid = ?";
        $version_stmt = mysqli_prepare($mysqli, $version_sql);
        mysqli_stmt_bind_param($version_stmt, "i", $order_id);
        mysqli_stmt_execute($version_stmt);
        $version_result = mysqli_stmt_get_result($version_stmt);
        $version_row = mysqli_fetch_assoc($version_result);
        $new_version = $version_row['new_version'] ?? 1;
        mysqli_stmt_close($version_stmt);
        
        // Insert extension request
        $extend_sql = "INSERT INTO ConstructionExtension 
                       (orderid, requested_end_date, reason, status, extension_version, requested_by, requested_by_id, requested_at) 
                       VALUES (?, ?, ?, 'pending', ?, 'supplier', ?, NOW())";
        $extend_stmt = mysqli_prepare($mysqli, $extend_sql);
        mysqli_stmt_bind_param($extend_stmt, "issiis", $order_id, $new_end_date, $extension_reason, $new_version, $supplier_id);
        
        if (mysqli_stmt_execute($extend_stmt)) {
            $extension_message = "Extension request sent to client for approval.";
            
            // Get client info
            $client_sql = "SELECT clientid FROM `Order` WHERE orderid = ?";
            $client_stmt = mysqli_prepare($mysqli, $client_sql);
            mysqli_stmt_bind_param($client_stmt, "i", $order_id);
            mysqli_stmt_execute($client_stmt);
            $client_result = mysqli_stmt_get_result($client_stmt);
            $client_row = mysqli_fetch_assoc($client_result);
            $client_id = $client_row['clientid'];
            mysqli_stmt_close($client_stmt);
            
            $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                           VALUES ('client', ?, ?, 'Supplier requests construction extension to " . $new_end_date . ". Reason: " . substr($extension_reason, 0, 100) . "', 'extension_request', NOW())";
            $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
            mysqli_stmt_bind_param($notify_stmt, "ii", $client_id, $order_id);
            mysqli_stmt_execute($notify_stmt);
            mysqli_stmt_close($notify_stmt);
        } else {
            $extension_error = "Failed to submit extension request.";
        }
        mysqli_stmt_close($extend_stmt);
    }
}

// Fetch current schedule
$schedule_sql = "SELECT s.* FROM Schedule s WHERE s.orderid = ?";
$schedule_stmt = mysqli_prepare($mysqli, $schedule_sql);
mysqli_stmt_bind_param($schedule_stmt, "i", $order_id);
mysqli_stmt_execute($schedule_stmt);
$schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($schedule_stmt));
mysqli_stmt_close($schedule_stmt);

// Check pending extension
$pending_sql = "SELECT * FROM ConstructionExtension WHERE orderid = ? AND status = 'pending' ORDER BY extension_version DESC LIMIT 1";
$pending_stmt = mysqli_prepare($mysqli, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
mysqli_stmt_execute($pending_stmt);
$pending_extension = mysqli_fetch_assoc(mysqli_stmt_get_result($pending_stmt));
mysqli_stmt_close($pending_stmt);

// Fetch order info with more details
$order_sql = "SELECT o.orderid, o.ostatus, o.odate, o.actual_completion_date, o.Requirements,
                     c.cname as client_name, c.clientid,
                     op.total_amount_due, op.total_amount_paid,
                     d.designid
              FROM `Order` o 
              JOIN Client c ON o.clientid = c.clientid 
              LEFT JOIN OrderPayment op ON o.payment_id = op.payment_id
              LEFT JOIN Design d ON o.designid = d.designid
              WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));
mysqli_stmt_close($order_stmt);

// Fetch designer name
$designer_name = '';
if (!empty($order_info['designid'])) {
    $designer_sql = "SELECT dname FROM Designer WHERE designerid = (SELECT designerid FROM Design WHERE designid = ?)";
    $designer_stmt = mysqli_prepare($mysqli, $designer_sql);
    mysqli_stmt_bind_param($designer_stmt, "i", $order_info['designid']);
    mysqli_stmt_execute($designer_stmt);
    $designer_result = mysqli_stmt_get_result($designer_stmt);
    $designer_row = mysqli_fetch_assoc($designer_result);
    $designer_name = $designer_row['dname'] ?? 'N/A';
    mysqli_stmt_close($designer_stmt);
}

$today = date('Y-m-d');
$start_date = $schedule['construction_start_date'] ?? null;
$end_date = $schedule['construction_end_date'] ?? null;
$design_finish_date = $schedule['DesignFinishDate'] ?? null;
$order_finish_date = $schedule['OrderFinishDate'] ?? null;

// Get current month and year for calendar navigation
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('m');
}
if ($current_year < 2000 || $current_year > 2100) {
    $current_year = date('Y');
}

$is_construction_ongoing = ($order_info['ostatus'] == 'Construction begins' || $order_info['ostatus'] == 'preparing');
$is_waiting_inspection = ($order_info['ostatus'] == 'Waiting for inspection');
$is_complete = ($order_info['ostatus'] == 'complete');
$can_mark_complete = $is_construction_ongoing && !$is_complete && !$is_waiting_inspection;
$can_request_extension = $end_date && $today > $end_date && !$pending_extension && $is_construction_ongoing;

// ==================== CALENDAR GENERATION ====================
function generateCalendar($month, $year, $start_date, $end_date, $today) {
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
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
        // Determine cell type
        $cell_type = 'normal';
        if ($date_str == $today) {
            $cell_type = 'today';
        } elseif ($start_date && $date_str == $start_date) {
            $cell_type = 'start';
        } elseif ($end_date && $date_str == $end_date) {
            $cell_type = 'end';
        } elseif ($start_date && $end_date && $date_str > $start_date && $date_str < $today) {
            $cell_type = 'past';
        } elseif ($start_date && $end_date && $date_str >= $today && $date_str <= $end_date) {
            $cell_type = 'future';
        } elseif ($start_date && $date_str < $start_date) {
            $cell_type = 'before_start';
        } elseif ($end_date && $date_str > $end_date) {
            $cell_type = 'after_end';
        }
        
        $week[] = [
            'day' => $day,
            'date' => $date_str,
            'cell_type' => $cell_type,
            'is_start' => ($start_date && $date_str == $start_date),
            'is_end' => ($end_date && $date_str == $end_date),
            'is_today' => ($date_str == $today)
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

$calendar = generateCalendar($current_month, $current_year, $start_date, $end_date, $today);

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
    <title>Construction Calendar - Project #<?= $order_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
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
            margin-top: 1rem;
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
            height: 100px;
            padding: 0.75rem;
            border: 1px solid #ecf0f1;
            vertical-align: top;
            background-color: #fff;
            transition: background-color 0.2s;
            cursor: pointer;
        }

        .calendar-table td:hover {
            background-color: #f8f9fa;
        }

        .day-number {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        /* Cell type styles */
        .calendar-table td.cell-start {
            background: linear-gradient(135deg, #d4edda, #a8e6a8);
            border-left: 4px solid #28a745;
        }

        .calendar-table td.cell-end {
            background: linear-gradient(135deg, #f8d7da, #f5b8be);
            border-left: 4px solid #dc3545;
        }

        .calendar-table td.cell-today {
            background: linear-gradient(135deg, #cce5ff, #99c9ff);
            border: 2px solid #007bff;
            box-shadow: 0 0 8px rgba(0,123,255,0.3);
        }

        .calendar-table td.cell-past {
            background-color: #e9ecef;
        }

        .calendar-table td.cell-past .day-number {
            color: #adb5bd;
        }

        .calendar-table td.cell-before_start {
            background-color: #f8f9fa;
        }

        .calendar-table td.cell-before_start .day-number {
            color: #dee2e6;
        }

        .calendar-table td.cell-after_end {
            background-color: #f8f9fa;
        }

        .calendar-table td.cell-after_end .day-number {
            color: #dee2e6;
        }

        .day-number.start-mark {
            background-color: #28a745;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            display: inline-block;
        }

        .day-number.end-mark {
            background-color: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            display: inline-block;
        }

        .day-number.today-mark {
            background-color: #007bff;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 50%;
            display: inline-block;
            width: 32px;
            text-align: center;
        }

        .construction-badge {
            font-size: 0.7rem;
            background-color: rgba(52, 152, 219, 0.1);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            margin-top: 0.3rem;
            text-align: center;
        }

        .legend {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 30px;
            height: 30px;
            border-radius: 6px;
        }

        .badge {
            padding: 0.5rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
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

        .order-detail {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .order-detail:last-child {
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

        .info-message {
            background-color: #e8f4f8;
            border-left: 4px solid #3498db;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        @media (max-width: 768px) {
            .calendar-table td {
                height: 70px;
                padding: 0.4rem;
            }
            
            .day-number {
                font-size: 0.8rem;
            }
            
            .calendar-nav a {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .month-year {
                font-size: 1.3rem;
            }
            
            .legend {
                gap: 0.8rem;
            }
            
            .legend-item span {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="container">
            <h2><i class="fas fa-calendar-alt me-2"></i>Construction Calendar</h2>
            <p>Project #<?= $order_id ?> - <?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></p>
        </div>
    </div>
    
    <div class="container mb-5">
        <!-- Back Button -->
        <a href="ProjectWorkerManagement.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left"></i> Back to Projects
        </a>
        
        <!-- Messages -->
        <?php if ($early_complete_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= $early_complete_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($early_complete_error): ?>
            <div class="alert alert-danger"><?= $early_complete_error ?></div>
        <?php endif; ?>
        <?php if ($extension_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-paper-plane me-2"></i><?= $extension_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($extension_error): ?>
            <div class="alert alert-danger"><?= $extension_error ?></div>
        <?php endif; ?>
        
        <!-- Waiting for inspection info message -->
        <?php if ($is_waiting_inspection): ?>
            <div class="info-message">
                <i class="fas fa-clock me-2 text-primary"></i>
                <strong>Waiting for Client Inspection</strong> - The construction has been marked as complete. The client has been notified and will inspect the work. Once approved, the project status will be updated automatically.
            </div>
        <?php endif; ?>
        
        <!-- Project Status Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Project Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Status:</strong><br>
                        <span class="badge bg-<?= $is_complete ? 'success' : ($is_waiting_inspection ? 'info' : 'primary') ?>">
                            <?= htmlspecialchars($order_info['ostatus'] ?? 'N/A') ?>
                        </span>
                    </div>
                    <div class="col-md-3">
                        <strong>Payment:</strong><br>
                        <span class="text-success">$<?= number_format($order_info['total_amount_paid'] ?? 0, 2) ?></span> / 
                        <span class="text-muted">$<?= number_format($order_info['total_amount_due'] ?? 0, 2) ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Client:</strong><br>
                        <?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Order Date:</strong><br>
                        <?= date('Y-m-d', strtotime($order_info['odate'] ?? $today)) ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Legend -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #d4edda, #a8e6a8); border-left: 4px solid #28a745;"></div>
                <span>Construction Start Date</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #cce5ff, #99c9ff); border: 2px solid #007bff;"></div>
                <span>Today</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #f8d7da, #f5b8be); border-left: 4px solid #dc3545;"></div>
                <span>Construction End Date</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background-color: #e9ecef;"></div>
                <span>Past Days (Greyed Out)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background-color: #fff; border: 1px solid #dee2e6;"></div>
                <span>Future Days</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background-color: #fff; border: 1px solid #3498db;"></div>
                <span><i class="fas fa-mouse-pointer"></i> Click any date for Order Details</span>
            </div>
        </div>
        
        <!-- Calendar Navigation -->
        <div class="calendar-nav">
            <a href="?orderid=<?= $order_id ?>&month=<?= $prev_month ?>&year=<?= $prev_year ?>">
                <i class="fas fa-chevron-left me-2"></i>Previous
            </a>
            <div class="month-year">
                <?= $month_name . ' ' . $current_year ?>
            </div>
            <a href="?orderid=<?= $order_id ?>&month=<?= $next_month ?>&year=<?= $next_year ?>">
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
                                    <td class="cell-before_start"></td>
                                <?php else: ?>
                                    <?php
                                    $td_class = 'cell-' . $day['cell_type'];
                                    ?>
                                    <td class="<?= $td_class ?>" onclick="showOrderDetails()">
                                        <div class="day-number">
                                            <?php if ($day['is_start']): ?>
                                                <span class="start-mark"><i class="fas fa-play me-1"></i><?= $day['day'] ?></span>
                                            <?php elseif ($day['is_end']): ?>
                                                <span class="end-mark"><i class="fas fa-stop me-1"></i><?= $day['day'] ?></span>
                                            <?php elseif ($day['is_today']): ?>
                                                <span class="today-mark"><?= $day['day'] ?></span>
                                            <?php else: ?>
                                                <?= $day['day'] ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($start_date && $end_date && $day['date'] >= $start_date && $day['date'] <= $end_date && !$day['is_start'] && !$day['is_end'] && !$day['is_today']): ?>
                                            <div class="construction-badge">
                                                <i class="fas fa-hard-hat"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Progress Summary -->
        <?php if ($start_date && $end_date): ?>
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Construction Progress</h5>
            </div>
            <div class="card-body">
                <?php
                $total_days = (new DateTime($end_date))->diff(new DateTime($start_date))->days + 1;
                $elapsed_days = 0;
                $today_obj = new DateTime($today);
                $start_obj = new DateTime($start_date);
                $end_obj = new DateTime($end_date);
                
                if ($today_obj >= $start_obj) {
                    if ($today_obj <= $end_obj) {
                        $elapsed_days = $today_obj->diff($start_obj)->days + 1;
                    } else {
                        $elapsed_days = $total_days;
                    }
                }
                
                $percent = $total_days > 0 ? round(($elapsed_days / $total_days) * 100) : 0;
                $is_overdue = $today_obj > $end_obj && !$is_complete && !$is_waiting_inspection;
                ?>
                
                <div class="progress mb-3" style="height: 35px;">
                    <div class="progress-bar <?= $is_overdue ? 'bg-danger' : 'bg-success' ?>" 
                         style="width: <?= min(100, $percent) ?>%;">
                        <?= $percent ?>% Complete
                    </div>
                </div>
                
                <div class="row text-center">
                    <div class="col-md-4">
                        <strong>Construction Start Date:</strong><br>
                        <?= date('Y-m-d', strtotime($start_date)) ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Elapsed Days:</strong><br>
                        <?= $elapsed_days ?> / <?= $total_days ?> days
                    </div>
                    <div class="col-md-4">
                        <strong>Construction End Date:</strong><br>
                        <?php if ($pending_extension && $pending_extension['status'] == 'pending'): ?>
                            <span class="text-warning"><?= date('Y-m-d', strtotime($pending_extension['requested_end_date'])) ?></span>
                            <br><small>(Pending Client Approval)</small>
                        <?php else: ?>
                            <?= date('Y-m-d', strtotime($end_date)) ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($is_overdue && !$pending_extension): ?>
                    <div class="alert alert-warning text-center mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Construction has exceeded the scheduled end date! Please request an extension.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <?php if ($can_mark_complete || $can_request_extension): ?>
        <div class="card mt-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-tools me-2"></i>Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Mark as Complete Button -->
                    <?php if ($can_mark_complete): ?>
                    <div class="col-md-6 mb-3">
                        <form method="POST" onsubmit="return confirm('Are you sure you want to mark this construction as complete? The status will change to \"Waiting for inspection\" and the client will be notified to inspect the work.');">
                            <input type="hidden" name="mark_complete" value="1">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-check-circle me-2"></i>Mark as Complete
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Extension Button -->
                    <?php if ($can_request_extension): ?>
                    <div class="col-md-6 mb-3">
                        <button type="button" class="btn btn-warning btn-lg w-100" data-bs-toggle="modal" data-bs-target="#extensionModal">
                            <i class="fas fa-clock me-2"></i>Request Extension
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Extension Request Modal -->
        <div class="modal fade" id="extensionModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Request Construction Extension</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="request_extension" value="1">
                            <div class="mb-3">
                                <label class="form-label">Current End Date</label>
                                <input type="text" class="form-control" value="<?= $end_date ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="new_end_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                                <small class="text-muted">Select new completion date</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Reason for Extension <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="extension_reason" rows="3" required placeholder="Please explain why extension is needed..."></textarea>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>Client will be notified and must approve this extension.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning">Send Extension Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Order Details Modal -->
        <div class="modal fade" id="orderDetailsModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-shopping-cart me-2"></i>Order Details - #<?= $order_id ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Order ID</div>
                                    <div class="detail-value">#<?= htmlspecialchars($order_info['orderid'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Order Status</div>
                                    <div class="detail-value">
                                        <span class="badge bg-<?= $is_complete ? 'success' : ($is_waiting_inspection ? 'info' : 'primary') ?>">
                                            <?= htmlspecialchars($order_info['ostatus'] ?? 'N/A') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Client Name</div>
                                    <div class="detail-value"><?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Designer</div>
                                    <div class="detail-value"><?= htmlspecialchars($designer_name) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Order Date</div>
                                    <div class="detail-value"><?= date('Y-m-d H:i', strtotime($order_info['odate'] ?? $today)) ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Design Finish Date</div>
                                    <div class="detail-value"><?= $design_finish_date ? date('Y-m-d', strtotime($design_finish_date)) : 'Not Set' ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Construction Start Date</div>
                                    <div class="detail-value"><?= $start_date ? date('Y-m-d', strtotime($start_date)) : 'Not Set' ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Construction End Date</div>
                                    <div class="detail-value">
                                        <?php if ($pending_extension && $pending_extension['status'] == 'pending'): ?>
                                            <span class="text-warning"><?= date('Y-m-d', strtotime($pending_extension['requested_end_date'])) ?></span>
                                            <br><small>(Pending Approval)</small>
                                        <?php else: ?>
                                            <?= $end_date ? date('Y-m-d', strtotime($end_date)) : 'Not Set' ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Order Finish Date</div>
                                    <div class="detail-value"><?= $order_finish_date ? date('Y-m-d', strtotime($order_finish_date)) : 'Not Set' ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="order-detail">
                                    <div class="detail-label">Budget</div>
                                    <div class="detail-value">HK$<?= number_format($order_info['total_amount_due'] ?? 0, 2) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($order_info['Requirements'])): ?>
                        <div class="order-detail">
                            <div class="detail-label">Requirements</div>
                            <div class="detail-value"><?= htmlspecialchars($order_info['Requirements']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($is_waiting_inspection): ?>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Status: Waiting for Inspection</strong><br>
                            The client has been notified to inspect the completed construction. Once approved, the project will be marked as complete.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Extension History -->
        <?php
        $history_sql = "SELECT * FROM ConstructionExtension WHERE orderid = ? ORDER BY extension_version DESC";
        $history_stmt = mysqli_prepare($mysqli, $history_sql);
        mysqli_stmt_bind_param($history_stmt, "i", $order_id);
        mysqli_stmt_execute($history_stmt);
        $history_result = mysqli_stmt_get_result($history_stmt);
        
        if (mysqli_num_rows($history_result) > 0):
        ?>
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Extension History</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Version</th>
                            <th>Requested End Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Requested Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($ext = mysqli_fetch_assoc($history_result)): ?>
                            <tr>
                                <td>#<?= $ext['extension_version'] ?></td>
                                <td><?= date('Y-m-d', strtotime($ext['requested_end_date'])) ?></td>
                                <td><?= htmlspecialchars(substr($ext['reason'], 0, 50)) ?>...</td>
                                <td>
                                    <?php if ($ext['status'] == 'accepted'): ?>
                                        <span class="badge bg-success">Accepted</span>
                                    <?php elseif ($ext['status'] == 'rejected'): ?>
                                        <span class="badge bg-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('Y-m-d H:i', strtotime($ext['requested_at'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; 
        mysqli_stmt_close($history_stmt);
        ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showOrderDetails() {
            var myModal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            myModal.show();
        }
    </script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>