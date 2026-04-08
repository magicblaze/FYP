<?php
// supplier/construction_stage.php
// Supplier sets construction start and end dates for a project
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as supplier
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
$verify_result = mysqli_stmt_get_result($verify_stmt);

if (mysqli_num_rows($verify_result) == 0) {
    header('Location: ProjectWorkerManagement.php');
    exit;
}

// Handle form submission
$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $construction_start_date = $_POST['construction_start_date'] ?? '';
    $construction_end_date = $_POST['construction_end_date'] ?? '';
    
    if (empty($construction_start_date) || empty($construction_end_date)) {
        $error = "Please fill in both start date and end date.";
    } elseif (strtotime($construction_start_date) >= strtotime($construction_end_date)) {
        $error = "Start date must be before end date.";
    } else {
        // Check if ConstructionScheduleHistory table exists, if not create it
        $table_check = "SHOW TABLES LIKE 'ConstructionScheduleHistory'";
        $table_result = mysqli_query($mysqli, $table_check);
        if (mysqli_num_rows($table_result) == 0) {
            // Create the history table if it doesn't exist
            $create_table = "
            CREATE TABLE IF NOT EXISTS `ConstructionScheduleHistory` (
                `history_id` INT NOT NULL AUTO_INCREMENT,
                `orderid` INT NOT NULL,
                `construction_start_date` DATE NOT NULL,
                `construction_end_date` DATE NOT NULL,
                `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
                `version` INT NOT NULL,
                `rejection_reason` TEXT DEFAULT NULL,
                `rejected_by` INT DEFAULT NULL,
                `created_by` VARCHAR(50) NOT NULL,
                `created_by_id` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `responded_at` TIMESTAMP NULL,
                PRIMARY KEY (`history_id`),
                KEY `orderid_idx` (`orderid`),
                FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            mysqli_query($mysqli, $create_table);
        }
        
        // Get current version
        $version_sql = "SELECT IFNULL(MAX(version), 0) + 1 as new_version FROM ConstructionScheduleHistory WHERE orderid = ?";
        $version_stmt = mysqli_prepare($mysqli, $version_sql);
        mysqli_stmt_bind_param($version_stmt, "i", $order_id);
        mysqli_stmt_execute($version_stmt);
        $version_result = mysqli_stmt_get_result($version_stmt);
        $version_row = mysqli_fetch_assoc($version_result);
        $new_version = $version_row['new_version'] ?? 1;
        mysqli_stmt_close($version_stmt);
        
        // Insert into history table - FIXED: 7 placeholders (?, ?, ?, ?, ?, ?, ?)
        $insert_sql = "INSERT INTO ConstructionScheduleHistory 
                       (orderid, construction_start_date, construction_end_date, status, version, created_by, created_by_id) 
                       VALUES (?, ?, ?, 'pending', ?, ?, ?)";
        $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
        // Bind: orderid(i), start_date(s), end_date(s), version(i), created_by(s), created_by_id(i)
        $created_by = 'supplier';
        mysqli_stmt_bind_param($insert_stmt, "issisi", $order_id, $construction_start_date, $construction_end_date, $new_version, $created_by, $supplier_id);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            // Update main Schedule table with latest dates and pending status
            $check_sql = "SELECT scheduleid FROM Schedule WHERE orderid = ?";
            $check_stmt = mysqli_prepare($mysqli, $check_sql);
            mysqli_stmt_bind_param($check_stmt, "i", $order_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                $update_sql = "UPDATE Schedule 
                               SET construction_start_date = ?, 
                                   construction_end_date = ?,
                                   construction_date_status = 'pending'
                               WHERE orderid = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "ssi", $construction_start_date, $construction_end_date, $order_id);
            } else {
                $insert_schedule_sql = "INSERT INTO Schedule (orderid, managerid, construction_start_date, construction_end_date, construction_date_status) 
                                        SELECT ?, managerid, ?, ?, 'pending' FROM `Order` WHERE orderid = ?";
                $update_stmt = mysqli_prepare($mysqli, $insert_schedule_sql);
                mysqli_stmt_bind_param($update_stmt, "issi", $order_id, $construction_start_date, $construction_end_date, $order_id);
            }
            
            if (mysqli_stmt_execute($update_stmt)) {
                $message = "Construction dates have been set and sent to client for confirmation.";
            } else {
                $error = "Failed to update schedule.";
            }
            mysqli_stmt_close($update_stmt);
        } else {
            $error = "Failed to save construction dates. Please try again.";
        }
        mysqli_stmt_close($insert_stmt);
    }
}

// Fetch current schedule data (latest pending version if any)
$schedule_sql = "SELECT s.construction_start_date, s.construction_end_date, s.construction_date_status,
                        (SELECT status FROM ConstructionScheduleHistory 
                         WHERE orderid = s.orderid AND status = 'accepted' 
                         ORDER BY version DESC LIMIT 1) as accepted_status_exists
                 FROM Schedule s
                 WHERE s.orderid = ?";
$schedule_stmt = mysqli_prepare($mysqli, $schedule_sql);
mysqli_stmt_bind_param($schedule_stmt, "i", $order_id);
mysqli_stmt_execute($schedule_stmt);
$schedule_result = mysqli_stmt_get_result($schedule_stmt);
$schedule = mysqli_fetch_assoc($schedule_result);
mysqli_stmt_close($schedule_stmt);

// Fetch pending version details
$pending_sql = "SELECT * FROM ConstructionScheduleHistory 
                WHERE orderid = ? AND status = 'pending' 
                ORDER BY version DESC LIMIT 1";
$pending_stmt = mysqli_prepare($mysqli, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
mysqli_stmt_execute($pending_stmt);
$pending_version = mysqli_fetch_assoc(mysqli_stmt_get_result($pending_stmt));
mysqli_stmt_close($pending_stmt);

// Fetch accepted version details
$accepted_sql = "SELECT * FROM ConstructionScheduleHistory 
                 WHERE orderid = ? AND status = 'accepted' 
                 ORDER BY version DESC LIMIT 1";
$accepted_stmt = mysqli_prepare($mysqli, $accepted_sql);
mysqli_stmt_bind_param($accepted_stmt, "i", $order_id);
mysqli_stmt_execute($accepted_stmt);
$accepted_version = mysqli_fetch_assoc(mysqli_stmt_get_result($accepted_stmt));
mysqli_stmt_close($accepted_stmt);

// Fetch order info
$order_sql = "SELECT o.orderid, o.ostatus, c.cname as client_name 
              FROM `Order` o 
              JOIN Client c ON o.clientid = c.clientid 
              WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_info = mysqli_fetch_assoc(mysqli_stmt_get_result($order_stmt));
mysqli_stmt_close($order_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Construction Stage - Set Schedule</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background-color: #f4f7f6; }
        .container-custom { max-width: 800px; margin: 2rem auto; }
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: none; margin-bottom: 1.5rem; }
        .card-header { background: #2c3e50; color: white; border-radius: 12px 12px 0 0 !important; padding: 1rem 1.5rem; }
        .status-badge { padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .status-pending { background: #f39c12; color: white; }
        .status-accepted { background: #27ae60; color: white; }
        .status-rejected { background: #e74c3c; color: white; }
        .accepted-schedule { background: #e8f8f5; border-left: 4px solid #27ae60; }
        .pending-schedule { background: #fef9e7; border-left: 4px solid #f39c12; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container container-custom">
        <a href="ProjectWorkerManagement.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left me-1"></i>Back to Projects
        </a>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-hard-hat me-2"></i>Construction Stage - Project #<?= $order_id ?></h5>
                <small>Client: <?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></small>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Previously Accepted Schedule (if exists) -->
                <?php if ($accepted_version): ?>
                    <div class="alert alert-success mb-3 accepted-schedule">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Previously Confirmed Schedule:</strong>
                        <div class="mt-2">
                            <strong>Start Date:</strong> <?= date('F d, Y', strtotime($accepted_version['construction_start_date'])) ?><br>
                            <strong>End Date:</strong> <?= date('F d, Y', strtotime($accepted_version['construction_end_date'])) ?>
                        </div>
                        <small class="text-muted">Confirmed on: <?= $accepted_version['responded_at'] ? date('M d, Y H:i', strtotime($accepted_version['responded_at'])) : 'N/A' ?></small>
                    </div>
                <?php endif; ?>
                
                <!-- Current Pending Schedule -->
                <?php if ($pending_version && $schedule && $schedule['construction_date_status'] == 'pending'): ?>
                    <div class="alert alert-info mb-3 pending-schedule">
                        <i class="fas fa-hourglass-half me-2"></i>
                        <strong>Pending Client Confirmation:</strong>
                        <div class="mt-2">
                            <strong>Proposed Start Date:</strong> <?= date('F d, Y', strtotime($pending_version['construction_start_date'])) ?><br>
                            <strong>Proposed End Date:</strong> <?= date('F d, Y', strtotime($pending_version['construction_end_date'])) ?>
                        </div>
                        <small class="text-muted">Version #<?= $pending_version['version'] ?> - Waiting for client response</small>
                    </div>
                <?php elseif ($schedule && $schedule['construction_date_status'] == 'rejected'): ?>
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-clock me-2"></i>
                        <strong>Client rejected the previous schedule.</strong> Please set new dates for confirmation.
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="construction_start_date" class="form-label fw-bold">
                            <i class="fas fa-calendar-alt me-1 text-primary"></i>Construction Start Date
                        </label>
                        <input type="date" class="form-control" id="construction_start_date" name="construction_start_date"
                               value="<?= htmlspecialchars(($pending_version ? $pending_version['construction_start_date'] : ($schedule['construction_start_date'] ?? ''))) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                        <small class="text-muted">Select the date when construction will begin.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="construction_end_date" class="form-label fw-bold">
                            <i class="fas fa-calendar-check me-1 text-success"></i>Construction End Date
                        </label>
                        <input type="date" class="form-control" id="construction_end_date" name="construction_end_date"
                               value="<?= htmlspecialchars(($pending_version ? $pending_version['construction_end_date'] : ($schedule['construction_end_date'] ?? ''))) ?>"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        <small class="text-muted">Select the expected completion date.</small>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-paper-plane me-2"></i>
                            <?= ($pending_version && $schedule && $schedule['construction_date_status'] == 'pending') ? 'Resend Schedule' : 'Send for Client Confirmation' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($schedule && $schedule['construction_date_status']): ?>
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Schedule Status</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Current Client Confirmation Status:</span>
                        <?php
                        $statusClass = '';
                        $statusText = '';
                        switch ($schedule['construction_date_status']) {
                            case 'pending':
                                $statusClass = 'status-pending';
                                $statusText = 'Pending Confirmation';
                                break;
                            case 'accepted':
                                $statusClass = 'status-accepted';
                                $statusText = 'Accepted - Construction Ready';
                                break;
                            case 'rejected':
                                $statusClass = 'status-rejected';
                                $statusText = 'Rejected - Reschedule Required';
                                break;
                            default:
                                $statusClass = 'status-pending';
                                $statusText = 'Not Set';
                        }
                        ?>
                        <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Schedule History -->
        <?php
        $history_sql = "SELECT * FROM ConstructionScheduleHistory 
                        WHERE orderid = ? 
                        ORDER BY version DESC";
        $history_stmt = mysqli_prepare($mysqli, $history_sql);
        mysqli_stmt_bind_param($history_stmt, "i", $order_id);
        mysqli_stmt_execute($history_stmt);
        $history_result = mysqli_stmt_get_result($history_stmt);
        
        if (mysqli_num_rows($history_result) > 1): ?>
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-history me-2"></i>Schedule History</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Version</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Date Sent</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($history = mysqli_fetch_assoc($history_result)): ?>
                                    <tr>
                                        <td>#<?= $history['version'] ?></td>
                                        <td><?= date('M d, Y', strtotime($history['construction_start_date'])) ?></td>
                                        <td><?= date('M d, Y', strtotime($history['construction_end_date'])) ?></td>
                                        <td>
                                            <?php if ($history['status'] == 'accepted'): ?>
                                                <span class="badge bg-success">Accepted</span>
                                            <?php elseif ($history['status'] == 'rejected'): ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('M d, Y H:i', strtotime($history['created_at'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        mysqli_stmt_close($history_stmt);
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>