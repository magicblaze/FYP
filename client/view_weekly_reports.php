<?php
// client/view_weekly_reports.php
require_once __DIR__ . '/../config.php';
session_start();

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
$verify_sql = "SELECT orderid, ostatus FROM `Order` WHERE orderid = ? AND clientid = ?";
$verify_stmt = mysqli_prepare($mysqli, $verify_sql);
mysqli_stmt_bind_param($verify_stmt, "ii", $order_id, $client_id);
mysqli_stmt_execute($verify_stmt);
$verify_result = mysqli_stmt_get_result($verify_stmt);
$order_info = mysqli_fetch_assoc($verify_result);

if (!$order_info) {
    header('Location: order_history.php');
    exit;
}
mysqli_stmt_close($verify_stmt);

$order_status = $order_info['ostatus'];

// Handle feedback submission
$feedback_message = '';
$feedback_error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_feedback'])) {
    $week_number = intval($_POST['week_number'] ?? 0);
    $client_feedback = $_POST['client_feedback'] ?? '';
    
    if (empty($client_feedback)) {
        $feedback_error = "Please enter your feedback.";
    } else {
        $update_sql = "UPDATE WeeklyConstructionReport 
                       SET client_feedback = ?, client_feedback_at = NOW()
                       WHERE orderid = ? AND week_number = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "sii", $client_feedback, $order_id, $week_number);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $feedback_message = "Feedback submitted successfully!";
            
            // Notify supplier
            $supplier_sql = "SELECT supplierid FROM `Order` WHERE orderid = ?";
            $supplier_stmt = mysqli_prepare($mysqli, $supplier_sql);
            mysqli_stmt_bind_param($supplier_stmt, "i", $order_id);
            mysqli_stmt_execute($supplier_stmt);
            $supplier_result = mysqli_stmt_get_result($supplier_stmt);
            $supplier_row = mysqli_fetch_assoc($supplier_result);
            $supplier_id = $supplier_row['supplierid'];
            mysqli_stmt_close($supplier_stmt);
            
            if ($supplier_id) {
                $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                               VALUES ('supplier', ?, ?, 'Client has provided feedback on Week $week_number report for Order #$order_id.', 'feedback', NOW())";
                $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
                mysqli_stmt_bind_param($notify_stmt, "ii", $supplier_id, $order_id);
                mysqli_stmt_execute($notify_stmt);
                mysqli_stmt_close($notify_stmt);
            }
        } else {
            $feedback_error = "Failed to submit feedback.";
        }
        mysqli_stmt_close($update_stmt);
    }
}

// Get order payment plan and total cost
$payment_plan_sql = "SELECT payment_plan, total_cost FROM `Order` o 
                     JOIN OrderPayment op ON o.payment_id = op.payment_id 
                     WHERE o.orderid = ?";
$payment_plan_stmt = mysqli_prepare($mysqli, $payment_plan_sql);
mysqli_stmt_bind_param($payment_plan_stmt, "i", $order_id);
mysqli_stmt_execute($payment_plan_stmt);
$payment_plan_result = mysqli_stmt_get_result($payment_plan_stmt);
$payment_plan_row = mysqli_fetch_assoc($payment_plan_result);
$payment_plan = $payment_plan_row['payment_plan'] ?? 'full';
$total_cost = floatval($payment_plan_row['total_cost'] ?? 0);
mysqli_stmt_close($payment_plan_stmt);

// Get all paid milestones from ConstructionPaymentRecord
// For 50% plan, we store percentage as 50 for first milestone and 100 for second milestone
$paid_milestones = [];
$paid_records_sql = "SELECT percentage, milestone, paid_at FROM ConstructionPaymentRecord 
                     WHERE orderid = ? AND status = 'paid'";
$paid_records_stmt = mysqli_prepare($mysqli, $paid_records_sql);
mysqli_stmt_bind_param($paid_records_stmt, "i", $order_id);
mysqli_stmt_execute($paid_records_stmt);
$paid_records_result = mysqli_stmt_get_result($paid_records_stmt);
while ($record = mysqli_fetch_assoc($paid_records_result)) {
    $paid_milestones[] = $record['percentage'];
}
mysqli_stmt_close($paid_records_stmt);

// Get pending payment if any
$pending_payment = null;
$pending_sql = "SELECT * FROM ConstructionPaymentRecord 
                WHERE orderid = ? AND status = 'pending' 
                ORDER BY record_id ASC LIMIT 1";
$pending_stmt = mysqli_prepare($mysqli, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, "i", $order_id);
mysqli_stmt_execute($pending_stmt);
$pending_result = mysqli_stmt_get_result($pending_stmt);
$pending_payment = mysqli_fetch_assoc($pending_result);
mysqli_stmt_close($pending_stmt);

// Function to create pending payment record
function createPendingPayment($order_id, $percentage, $amount, $milestone_name, $mysqli) {
    // Check if pending record already exists for this milestone
    $check_sql = "SELECT record_id FROM ConstructionPaymentRecord 
                  WHERE orderid = ? AND percentage = ? AND status = 'pending'";
    $check_stmt = mysqli_prepare($mysqli, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $percentage);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $existing = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);
    
    if ($existing) {
        return $existing['record_id'];
    }
    
    // Calculate installment number based on percentage
    if ($percentage == 25) $installment_number = 1;
    elseif ($percentage == 50) $installment_number = 2;
    elseif ($percentage == 75) $installment_number = 3;
    elseif ($percentage == 100) $installment_number = 4;
    elseif ($percentage == 50 && $milestone_name == '0-50% Completion Payment') $installment_number = 1;
    elseif ($percentage == 100 && $milestone_name == '50-100% Final Payment') $installment_number = 2;
    else $installment_number = 1;
    
    $insert_sql = "INSERT INTO ConstructionPaymentRecord 
                   (orderid, installment_number, percentage, amount, milestone, paid_at, status)
                   VALUES (?, ?, ?, ?, ?, NULL, 'pending')";
    $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iiids", $order_id, $installment_number, $percentage, $amount, $milestone_name);
    mysqli_stmt_execute($insert_stmt);
    $record_id = mysqli_insert_id($mysqli);
    mysqli_stmt_close($insert_stmt);
    
    return $record_id;
}

// Function to check if payment is needed for a specific milestone
function needsPayment($milestone_percentage, $paid_milestones, $current_week_progress, $payment_plan) {
    if ($payment_plan == 'full') {
        return false;
    }
    
    if ($payment_plan == 'installment_25') {
        if ($milestone_percentage == 25) {
            return (!in_array(25, $paid_milestones) && $current_week_progress >= 0);
        } elseif ($milestone_percentage == 50) {
            return (!in_array(50, $paid_milestones) && $current_week_progress >= 25);
        } elseif ($milestone_percentage == 75) {
            return (!in_array(75, $paid_milestones) && $current_week_progress >= 50);
        } elseif ($milestone_percentage == 100) {
            return (!in_array(100, $paid_milestones) && $current_week_progress >= 75);
        }
    } elseif ($payment_plan == 'installment_50') {
        // For 50% plan: milestone 50 = first payment (0-50%), milestone 100 = second payment (50-100%)
        if ($milestone_percentage == 50) {
            return (!in_array(50, $paid_milestones) && $current_week_progress >= 0);
        } elseif ($milestone_percentage == 100) {
            return (!in_array(100, $paid_milestones) && $current_week_progress >= 50);
        }
    }
    return false;
}

// Function to get milestone display name
function getMilestoneName($milestone_percentage, $payment_plan = 'full') {
    if ($payment_plan == 'installment_50') {
        if ($milestone_percentage == 50) {
            return '0-50% Completion Payment';
        } elseif ($milestone_percentage == 100) {
            return '50-100% Final Payment';
        }
    } elseif ($payment_plan == 'installment_25') {
        switch($milestone_percentage) {
            case 25: return '0-25% Initial Payment';
            case 50: return '25-50% Completion Payment';
            case 75: return '50-75% Completion Payment';
            case 100: return '75-100% Final Payment';
        }
    }
    return $milestone_percentage . '% Payment';
}

// Function to get milestone short name for button
function getMilestoneShortName($milestone_percentage, $payment_plan = 'full') {
    if ($payment_plan == 'installment_50') {
        if ($milestone_percentage == 50) {
            return '0-50%';
        } elseif ($milestone_percentage == 100) {
            return '50-100%';
        }
    } elseif ($payment_plan == 'installment_25') {
        switch($milestone_percentage) {
            case 25: return '0-25%';
            case 50: return '25-50%';
            case 75: return '50-75%';
            case 100: return '75-100%';
        }
    }
    return $milestone_percentage . '%';
}

// Function to calculate payment amount for milestone
function getPaymentAmount($milestone_percentage, $total_cost, $payment_plan) {
    if ($payment_plan == 'installment_25') {
        return $total_cost * 0.25;
    } elseif ($payment_plan == 'installment_50') {
        // Each milestone is 50% of total cost
        return $total_cost * 0.5;
    }
    return 0;
}

// Handle payment request - create pending record and redirect
if (isset($_GET['pay_milestone']) && isset($_GET['percentage'])) {
    $pay_percentage = intval($_GET['percentage']);
    $pay_amount = getPaymentAmount($pay_percentage, $total_cost, $payment_plan);
    $pay_milestone_name = getMilestoneName($pay_percentage, $payment_plan);
    
    // Create pending payment record
    $record_id = createPendingPayment($order_id, $pay_percentage, $pay_amount, $pay_milestone_name, $mysqli);
    
    // Update order status to waiting for payment
    $update_order_sql = "UPDATE `Order` SET ostatus = 'Waiting for construction payment' WHERE orderid = ?";
    $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
    mysqli_stmt_bind_param($update_order_stmt, "i", $order_id);
    mysqli_stmt_execute($update_order_stmt);
    mysqli_stmt_close($update_order_stmt);
    
    // Redirect to payment page
    header("Location: construction_payment_installment.php?orderid={$order_id}&amount={$pay_amount}&milestone=" . urlencode($pay_milestone_name) . "&percentage={$pay_percentage}&record_id={$record_id}");
    exit;
}

// Fetch all weekly reports for this order
$reports_sql = "SELECT * FROM WeeklyConstructionReport 
                WHERE orderid = ? 
                ORDER BY week_number ASC";
$reports_stmt = mysqli_prepare($mysqli, $reports_sql);
mysqli_stmt_bind_param($reports_stmt, "i", $order_id);
mysqli_stmt_execute($reports_stmt);
$reports_result = mysqli_stmt_get_result($reports_stmt);
$reports = [];
while ($row = mysqli_fetch_assoc($reports_result)) {
    $reports[] = $row;
}
mysqli_stmt_close($reports_stmt);

// Fetch order details
$order_detail_sql = "SELECT o.orderid, o.odate, o.Requirements, c.cname as client_name,
                            d.designName, dz.dname as designer_name
                     FROM `Order` o
                     JOIN Client c ON o.clientid = c.clientid
                     JOIN Design d ON o.designid = d.designid
                     JOIN Designer dz ON d.designerid = dz.designerid
                     WHERE o.orderid = ?";
$order_detail_stmt = mysqli_prepare($mysqli, $order_detail_sql);
mysqli_stmt_bind_param($order_detail_stmt, "i", $order_id);
mysqli_stmt_execute($order_detail_stmt);
$order_detail = mysqli_fetch_assoc(mysqli_stmt_get_result($order_detail_stmt));
mysqli_stmt_close($order_detail_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Construction Reports - Project #<?= $order_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background-color: #f4f7f6; }
        .container-custom { max-width: 1000px; margin: 2rem auto; }
        .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: none; margin-bottom: 1.5rem; }
        .card-header { background: #2c3e50; color: white; border-radius: 12px 12px 0 0 !important; padding: 1rem 1.5rem; }
        .report-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 1.5rem; overflow: hidden; }
        .report-header { padding: 1rem 1.5rem; cursor: pointer; transition: background-color 0.2s; background-color: #e9ecef; }
        .report-header:hover { filter: brightness(0.97); }
        .report-body { padding: 1.5rem; border-top: 1px solid #dee2e6; }
        .progress-bar-custom { height: 8px; border-radius: 4px; background-color: #e9ecef; overflow: hidden; margin: 0.5rem 0; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
        .image-preview { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .image-preview img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #dee2e6; cursor: pointer; }
        .feedback-box { background-color: #e8f4f8; border-left: 3px solid #17a2b8; padding: 0.75rem; margin-top: 0.75rem; border-radius: 6px; }
        .supplier-response { background-color: #e9ecef; border-left-color: #6c757d; }
        .badge-status { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; }
        .badge-submitted { background-color: #28a745; color: white; }
        .badge-draft { background-color: #ffc107; color: #212529; }
        .btn-pay-milestone { background-color: #e67e22; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; display: inline-block; font-size: 0.85rem; }
        .btn-pay-milestone:hover { background-color: #d35400; color: white; }
        .milestone-paid { background-color: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; display: inline-block; margin-left: 0.5rem; }
        .milestone-pending { background-color: #e67e22; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; display: inline-block; margin-left: 0.5rem; }
        .payment-alert { background-color: #fef3c7; border-left: 4px solid #e67e22; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .btn-pay { background-color: #e67e22; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; display: inline-block; }
        .btn-pay:hover { background-color: #d35400; color: white; }
        .milestones-container { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container container-custom">
        <a href="order_history.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left me-1"></i>Back to Projects
        </a>
        
        <!-- Payment Required Alert for pending payment -->
        <?php if ($pending_payment): ?>
            <div class="payment-alert">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-credit-card me-2 fa-lg"></i>
                        <strong>Payment Required!</strong> 
                        <?= getMilestoneName($pending_payment['percentage'], $payment_plan) ?> of $<?= number_format($pending_payment['amount'], 2) ?> is due.
                    </div>
                    <a href="construction_payment_installment.php?orderid=<?= $order_id ?>&amount=<?= $pending_payment['amount'] ?>&milestone=<?= urlencode($pending_payment['milestone']) ?>&percentage=<?= $pending_payment['percentage'] ?>&record_id=<?= $pending_payment['record_id'] ?>" 
                       class="btn-pay">
                        <i class="fas fa-credit-card me-1"></i>Pay Now
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Weekly Construction Reports</h5>
                <small>Project #<?= $order_id ?> - <?= htmlspecialchars($order_detail['designName'] ?? 'N/A') ?></small>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Client:</strong> <?= htmlspecialchars($order_detail['client_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Designer:</strong> <?= htmlspecialchars($order_detail['designer_name'] ?? 'N/A') ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Order Date:</strong> <?= date('M d, Y', strtotime($order_detail['odate'] ?? date('Y-m-d'))) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong> 
                        <span class="badge-status <?= $order_status == 'Construction begins' ? 'bg-primary text-white' : (($order_status == 'Waiting for construction payment' ? 'bg-warning' : (($order_status == 'Construction begins' || $order_status == 'preparing') ? 'bg-primary' : ($order_status == 'Waiting for inspection' ? 'bg-info' : 'bg-secondary')))) ?>">
                            <?= htmlspecialchars($order_status) ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($feedback_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= $feedback_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($feedback_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $feedback_error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($reports)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        <strong>No reports available yet.</strong><br>
                        The supplier will submit weekly construction reports once construction begins.
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <?php 
                        $can_feedback = ($report['status'] == 'submitted' && empty($report['client_feedback']) && $order_status != 'complete' && $order_status != 'Waiting for construction payment');
                        $current_progress = $report['progress_percentage'];
                        
                        // Determine which milestones are relevant for this payment plan
                        $all_milestones = [];
                        if ($payment_plan == 'installment_25') {
                            $all_milestones = [25, 50, 75, 100];
                        } elseif ($payment_plan == 'installment_50') {
                            // For 50% payment plan: milestones at 50% (first) and 100% (second)
                            $all_milestones = [50, 100];
                        }
                        
                        // Check which milestones need payment based on current progress
                        $milestone_status = [];
                        foreach ($all_milestones as $milestone) {
                            $is_paid = in_array($milestone, $paid_milestones);
                            $needs_payment = needsPayment($milestone, $paid_milestones, $current_progress, $payment_plan);
                            
                            $milestone_status[] = [
                                'percentage' => $milestone,
                                'name' => getMilestoneName($milestone, $payment_plan),
                                'short_name' => getMilestoneShortName($milestone, $payment_plan),
                                'is_paid' => $is_paid,
                                'needs_payment' => $needs_payment,
                                'amount' => getPaymentAmount($milestone, $total_cost, $payment_plan)
                            ];
                        }
                        ?>
                        <div class="report-card">
                            <div class="report-header" onclick="toggleReport(<?= $report['week_number'] ?>)">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-calendar-week me-2"></i>Week <?= $report['week_number'] ?></strong>
                                        <span class="text-muted ms-2">
                                            <?= date('M d', strtotime($report['week_start_date'])) ?> - <?= date('M d', strtotime($report['week_end_date'])) ?>
                                        </span>
                                        <?php if ($report['status'] == 'submitted'): ?>
                                            <span class="badge-status badge-submitted ms-2"><i class="fas fa-check-circle"></i> Submitted</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-draft ms-2"><i class="fas fa-pencil-alt"></i> Draft</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="badge bg-primary"><?= $report['progress_percentage'] ?>% Complete</span>
                                        <i class="fas fa-chevron-down ms-2"></i>
                                    </div>
                                </div>
                                <div class="progress-bar-custom mt-2">
                                    <div class="progress-fill bg-primary" style="width: <?= $report['progress_percentage'] ?>%;"></div>
                                </div>
                                <!-- Milestone Status Display -->
                                <?php if ($payment_plan != 'full'): ?>
                                    <div class="milestones-container mt-2">
                                        <?php foreach ($milestone_status as $ms): ?>
                                            <?php if ($ms['is_paid']): ?>
                                                <span class="milestone-paid"><i class="fas fa-check-circle"></i> <?= $ms['short_name'] ?> Paid</span>
                                            <?php elseif ($ms['needs_payment'] && $order_status != 'Waiting for construction payment'): ?>
                                                <span class="milestone-pending"><i class="fas fa-hourglass-half"></i> <?= $ms['short_name'] ?> - Pending</span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="report-body" id="report-body-<?= $report['week_number'] ?>" style="display: none;">
                                <div class="mb-3">
                                    <label class="fw-bold"><i class="fas fa-check-circle text-success me-1"></i>Work Completed</label>
                                    <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($report['work_completed'] ?? '')) ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="fw-bold"><i class="fas fa-tasks text-info me-1"></i>Planned Work</label>
                                    <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($report['work_planned'] ?? '')) ?></p>
                                </div>
                                
                                <?php if (!empty($report['image_paths'])): 
                                    $images = json_decode($report['image_paths'], true);
                                    if ($images): ?>
                                    <div class="mb-3">
                                        <label class="fw-bold"><i class="fas fa-image me-1"></i>Images</label>
                                        <div class="image-preview">
                                            <?php foreach ($images as $img): ?>
                                                <img src="../<?= $img ?>" alt="Progress Image" onclick="window.open('../<?= $img ?>', '_blank')">
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; endif; ?>
                                
                                <?php if ($report['request_extra_fee']): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-dollar-sign me-2"></i>
                                        <strong>Additional Fee Requested</strong> - Supplier has requested an additional construction fee.
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Display supplier response if any -->
                                <?php if (!empty($report['supplier_response'])): ?>
                                    <div class="feedback-box supplier-response">
                                        <strong><i class="fas fa-building me-1"></i>Supplier Response:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($report['supplier_response'])) ?></p>
                                        <small class="text-muted">Responded: <?= date('M d, Y H:i', strtotime($report['supplier_response_at'])) ?></small>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Payment Buttons for Milestones that need payment -->
                                <?php 
                                $needs_payment_milestones = array_filter($milestone_status, function($ms) {
                                    return $ms['needs_payment'] && !$ms['is_paid'];
                                });
                                ?>
                                <?php if (!empty($needs_payment_milestones) && $order_status != 'Waiting for construction payment'): ?>
                                    <div class="mt-3">
                                        <hr>
                                        <h6><i class="fas fa-credit-card me-2 text-warning"></i>Milestone Payments Required</h6>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php foreach ($needs_payment_milestones as $ms): ?>
                                                <a href="?orderid=<?= $order_id ?>&pay_milestone=1&percentage=<?= $ms['percentage'] ?>" 
                                                   class="btn-pay-milestone" onclick="event.stopPropagation();">
                                                    <i class="fas fa-credit-card me-1"></i>Pay <?= $ms['short_name'] ?> ($<?= number_format($ms['amount'], 2) ?>)
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                        <small class="text-muted">Payment required when construction reaches the milestone progress.</small>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Client Feedback Section -->
                                <?php if (!empty($report['client_feedback'])): ?>
                                    <div class="feedback-box">
                                        <strong><i class="fas fa-user me-1"></i>Your Feedback:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($report['client_feedback'])) ?></p>
                                        <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($report['client_feedback_at'])) ?></small>
                                    </div>
                                <?php elseif ($can_feedback): ?>
                                    <div class="mt-3 border rounded p-3">
                                        <h6><i class="fas fa-comment-dots me-2"></i>Provide Feedback</h6>
                                        <form method="POST">
                                            <input type="hidden" name="week_number" value="<?= $report['week_number'] ?>">
                                            <div class="mb-2">
                                                <textarea name="client_feedback" rows="3" class="form-control" placeholder="Provide your feedback on this week's report..."></textarea>
                                            </div>
                                            <button type="submit" name="submit_feedback" class="btn btn-primary btn-sm">
                                                <i class="fas fa-paper-plane me-1"></i>Submit Feedback
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="text-muted small mt-3">
                                    <i class="fas fa-clock me-1"></i>Report submitted: <?= date('M d, Y H:i', strtotime($report['submitted_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleReport(weekNumber) {
            const body = document.getElementById('report-body-' + weekNumber);
            if (body.style.display === 'none') {
                body.style.display = 'block';
            } else {
                body.style.display = 'none';
            }
        }
    </script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>