<?php
// Public/construction_schedule.php
// Client views and confirms/rejects construction schedule
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in as client
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$client_id = (int) ($_SESSION['user']['clientid'] ?? 0);
$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;

if ($order_id <= 0) {
    header('Location: order_history.php');
    exit;
}

// Verify order belongs to this client
$verify_sql = "SELECT orderid FROM `Order` WHERE orderid = ? AND clientid = ?";
$verify_stmt = mysqli_prepare($mysqli, $verify_sql);
mysqli_stmt_bind_param($verify_stmt, "ii", $order_id, $client_id);
mysqli_stmt_execute($verify_stmt);
$verify_result = mysqli_stmt_get_result($verify_stmt);

if (mysqli_num_rows($verify_result) == 0) {
    header('Location: order_history.php');
    exit;
}
mysqli_stmt_close($verify_stmt);

// Handle confirmation or rejection
$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'accept') {
        // Get the current pending version
        $pending_sql = "SELECT * FROM ConstructionScheduleHistory 
                        WHERE orderid = ? AND status = 'pending' 
                        ORDER BY version DESC LIMIT 1";
        $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
        mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
        mysqli_stmt_execute($pending_stmt);
        $pending_result = mysqli_stmt_get_result($pending_stmt);
        $pending_version = mysqli_fetch_assoc($pending_result);
        
        if ($pending_version) {
            // Update the pending version status to accepted
            $update_history_sql = "UPDATE ConstructionScheduleHistory 
                                   SET status = 'accepted', responded_at = NOW() 
                                   WHERE history_id = ?";
            $update_history_stmt = mysqli_prepare($mysqli, $update_history_sql);
            mysqli_stmt_bind_param($update_history_stmt, "i", $pending_version['history_id']);
            mysqli_stmt_execute($update_history_stmt);
            mysqli_stmt_close($update_history_stmt);
            
            // Update main Schedule table
            $update_sql = "UPDATE Schedule SET construction_date_status = 'accepted', current_version = ? WHERE orderid = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            $new_version = $pending_version['version'];
            mysqli_stmt_bind_param($update_stmt, "ii", $new_version, $order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            // Check if construction payment has already been made
            $check_payment_sql = "SELECT o.ostatus, 
                                         (SELECT COUNT(*) FROM ConstructionPaymentRecord WHERE orderid = ?) as payment_count
                                  FROM `Order` o
                                  WHERE o.orderid = ?";
            $check_stmt = mysqli_prepare($mysqli, $check_payment_sql);
            mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $order_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            $payment_check = mysqli_fetch_assoc($check_result);
            mysqli_stmt_close($check_stmt);
            
            $has_paid = ($payment_check && ($payment_check['ostatus'] == 'Construction begins' || $payment_check['payment_count'] > 0));
            
            // Close pending statement
            mysqli_stmt_close($pending_stmt);
            
            if ($has_paid) {
                // Already paid, just update order status message
                $message = "Construction schedule has been updated and accepted. Construction will begin on " . date('F d, Y', strtotime($pending_version['construction_start_date'])) . ".";
                
                // Also update order status if needed
                $order_update = "UPDATE `Order` SET ostatus = 'Construction begins' WHERE orderid = ?";
                $order_stmt = mysqli_prepare($mysqli, $order_update);
                mysqli_stmt_bind_param($order_stmt, "i", $order_id);
                mysqli_stmt_execute($order_stmt);
                mysqli_stmt_close($order_stmt);
            } else {
                // First time accepting - redirect to payment
                header('Location: construction_payment.php?orderid=' . $order_id);
                exit;
            }
        } else {
            $error = "No pending schedule found to accept.";
            if (isset($pending_stmt)) mysqli_stmt_close($pending_stmt);
        }
        
    } elseif ($action === 'reject') {
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        // Get the current pending version
        $pending_sql = "SELECT * FROM ConstructionScheduleHistory 
                        WHERE orderid = ? AND status = 'pending' 
                        ORDER BY version DESC LIMIT 1";
        $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
        mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
        mysqli_stmt_execute($pending_stmt);
        $pending_result = mysqli_stmt_get_result($pending_stmt);
        $pending_version = mysqli_fetch_assoc($pending_result);
        
        if ($pending_version) {
            // Update the pending version status to rejected
            $update_history_sql = "UPDATE ConstructionScheduleHistory 
                                   SET status = 'rejected', responded_at = NOW(), rejection_reason = ? 
                                   WHERE history_id = ?";
            $update_history_stmt = mysqli_prepare($mysqli, $update_history_sql);
            mysqli_stmt_bind_param($update_history_stmt, "si", $rejection_reason, $pending_version['history_id']);
            mysqli_stmt_execute($update_history_stmt);
            mysqli_stmt_close($update_history_stmt);
            
            // Update main Schedule table
            $update_sql = "UPDATE Schedule SET construction_date_status = 'rejected' WHERE orderid = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $order_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $message = "You have rejected the construction schedule. The supplier will be notified to provide alternative dates.";
            } else {
                $error = "Failed to reject schedule. Please try again.";
            }
            mysqli_stmt_close($update_stmt);
        } else {
            $error = "No pending schedule found to reject.";
        }
        mysqli_stmt_close($pending_stmt);
    }
}

// Fetch current schedule data (latest pending version)
$pending_sql = "SELECT h.*, s.construction_date_status as current_status,
                       o.ostatus, c.cname as client_name, sup.sname as supplier_name
                FROM ConstructionScheduleHistory h
                JOIN `Order` o ON h.orderid = o.orderid
                JOIN Client c ON o.clientid = c.clientid
                JOIN Supplier sup ON o.supplierid = sup.supplierid
                JOIN Schedule s ON s.orderid = h.orderid
                WHERE h.orderid = ? AND h.status = 'pending'
                ORDER BY h.version DESC LIMIT 1";
$pending_stmt = mysqli_prepare($mysqli, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
mysqli_stmt_execute($pending_stmt);
$pending_result = mysqli_stmt_get_result($pending_stmt);
$pending_schedule = mysqli_fetch_assoc($pending_result);
mysqli_stmt_close($pending_stmt);

// Fetch accepted version details (if no pending)
$accepted_schedule = null;
if (!$pending_schedule) {
    $accepted_sql = "SELECT h.*, s.construction_date_status as current_status,
                            o.ostatus, c.cname as client_name, sup.sname as supplier_name
                     FROM ConstructionScheduleHistory h
                     JOIN `Order` o ON h.orderid = o.orderid
                     JOIN Client c ON o.clientid = c.clientid
                     JOIN Supplier sup ON o.supplierid = sup.supplierid
                     JOIN Schedule s ON s.orderid = h.orderid
                     WHERE h.orderid = ? AND h.status = 'accepted'
                     ORDER BY h.version DESC LIMIT 1";
    $accepted_stmt = mysqli_prepare($mysqli, $accepted_sql);
    mysqli_stmt_bind_param($accepted_stmt, "i", $order_id);
    mysqli_stmt_execute($accepted_stmt);
    $accepted_result = mysqli_stmt_get_result($accepted_stmt);
    $accepted_schedule = mysqli_fetch_assoc($accepted_result);
    mysqli_stmt_close($accepted_stmt);
}

// Determine which schedule to display
$schedule = $pending_schedule ?: $accepted_schedule;
$is_pending = ($pending_schedule !== null);
$is_accepted = ($accepted_schedule !== null && !$is_pending);
$no_schedule = ($schedule === null);

// Check if payment has been made (for display message)
$has_paid_display = false;
if ($is_pending) {
    $check_payment_sql = "SELECT (SELECT COUNT(*) FROM ConstructionPaymentRecord WHERE orderid = ?) as payment_count FROM `Order` WHERE orderid = ?";
    $check_stmt = mysqli_prepare($mysqli, $check_payment_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $order_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $payment_check = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);
    $has_paid_display = ($payment_check && $payment_check['payment_count'] > 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Construction Schedule - Client Review</title>
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
        .schedule-date { font-size: 1.25rem; font-weight: 600; color: #2c3e50; }
        .btn-accept { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; }
        .btn-accept:hover { background: #229954; color: white; }
        .btn-reject { background: #e74c3c; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; }
        .btn-reject:hover { background: #c0392b; color: white; }
        .version-badge { background: #7f8c8d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container container-custom">
        <a href="order_history.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left me-1"></i>Back to Projects
        </a>
        
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
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Construction Schedule - Project #<?= $order_id ?></h5>
            </div>
            <div class="card-body">
                <?php if (!$no_schedule && $schedule): ?>
                    <div class="mb-4">
                        <h6 class="text-muted mb-3">Project Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div><strong>Client:</strong> <?= htmlspecialchars($schedule['client_name'] ?? 'N/A') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div><strong>Supplier:</strong> <?= htmlspecialchars($schedule['supplier_name'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="text-center mb-4">
                        <h6 class="text-muted mb-3">
                            Proposed Construction Timeline
                            <?php if ($is_pending): ?>
                                <span class="version-badge ms-2">Version #<?= $schedule['version'] ?></span>
                            <?php endif; ?>
                        </h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <i class="fas fa-play-circle fa-2x text-primary mb-2"></i>
                                        <div class="schedule-date"><?= date('F d, Y', strtotime($schedule['construction_start_date'])) ?></div>
                                        <small class="text-muted">Start Date</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <i class="fas fa-flag-checkered fa-2x text-success mb-2"></i>
                                        <div class="schedule-date"><?= date('F d, Y', strtotime($schedule['construction_end_date'])) ?></div>
                                        <small class="text-muted">End Date</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Status:</span>
                            <?php
                            $statusClass = '';
                            $statusText = '';
                            if ($is_accepted):
                                $statusClass = 'status-accepted';
                                $statusText = 'Accepted - Construction Ready';
                            elseif ($is_pending):
                                $statusClass = 'status-pending';
                                $statusText = 'Pending Your Response';
                            else:
                                $statusClass = 'status-pending';
                                $statusText = 'Not Set';
                            endif;
                            ?>
                            <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </div>
                    </div>
                    
                    <?php if ($is_pending): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php if ($has_paid_display): ?>
                                Please review the proposed construction timeline. Accepting will update the construction schedule.
                            <?php else: ?>
                                Please review the proposed construction timeline. Once you accept, you will be redirected to make a payment.
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-center gap-3 mt-4">
                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to ACCEPT this construction schedule?');">
                                <input type="hidden" name="action" value="accept">
                                <button type="submit" class="btn-accept">
                                    <i class="fas fa-check-circle me-2"></i>Accept Schedule
                                </button>
                            </form>
                            
                            <button type="button" class="btn-reject" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="fas fa-times-circle me-2"></i>Reject Schedule
                            </button>
                        </div>
                    <?php elseif ($is_accepted): ?>
                        <div class="alert alert-success text-center">
                            <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                            <strong>Construction schedule confirmed!</strong><br>
                            Construction will begin on <?= date('F d, Y', strtotime($schedule['construction_start_date'])) ?>.
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-clock fa-2x mb-2 d-block"></i>
                        <strong>No construction schedule available.</strong><br>
                        The supplier has not set a construction schedule yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Schedule History -->
        <?php
        $history_sql = "SELECT * FROM ConstructionScheduleHistory 
                        WHERE orderid = ? 
                        ORDER BY version DESC";
        $history_stmt = mysqli_prepare($mysqli, $history_sql);
        mysqli_stmt_bind_param($history_stmt, "i", $order_id);
        mysqli_stmt_execute($history_stmt);
        $history_result = mysqli_stmt_get_result($history_stmt);
        
        if (mysqli_num_rows($history_result) > 0): ?>
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
                                    <th>Response Date</th>
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
                                        <td>
                                            <?= $history['responded_at'] ? date('M d, Y H:i', strtotime($history['responded_at'])) : '-' ?>
                                            <?php if ($history['rejection_reason']): ?>
                                                <br><small class="text-danger">Reason: <?= htmlspecialchars($history['rejection_reason']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (isset($history_stmt)) mysqli_stmt_close($history_stmt); ?>
    </div>
    
    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-times-circle text-danger me-2"></i>Reject Schedule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject">
                        <p>Are you sure you want to reject this construction schedule?</p>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Reason for rejection (optional):</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" placeholder="Please provide a reason for rejecting the schedule..."></textarea>
                        </div>
                        <p class="text-muted small">The supplier will be notified to provide alternative dates.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>