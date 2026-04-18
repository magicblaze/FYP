<?php
// supplier/construction_calendar.php
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

// Create weekly report table if not exists
$create_report_table = "
CREATE TABLE IF NOT EXISTS `WeeklyConstructionReport` (
    `report_id` INT NOT NULL AUTO_INCREMENT,
    `orderid` INT NOT NULL,
    `week_number` INT NOT NULL,
    `week_start_date` DATE NOT NULL,
    `week_end_date` DATE NOT NULL,
    `work_completed` TEXT,
    `work_planned` TEXT,
    `progress_percentage` INT DEFAULT 0,
    `image_paths` TEXT,
    `request_extra_fee` TINYINT DEFAULT 0,
    `client_feedback` TEXT,
    `client_feedback_at` TIMESTAMP NULL,
    `designer_feedback` TEXT,
    `designer_feedback_at` TIMESTAMP NULL,
    `supplier_response` TEXT,
    `supplier_response_at` TIMESTAMP NULL,
    `status` ENUM('draft', 'submitted') DEFAULT 'draft',
    `submitted_at` TIMESTAMP NULL,
    `created_by_id` INT NOT NULL,
    `created_by_type` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`report_id`),
    KEY `orderid_idx` (`orderid`),
    KEY `week_number_idx` (`week_number`),
    FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($mysqli, $create_report_table);

// Handle Mark as Complete
$early_complete_message = '';
$early_complete_error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["mark_complete"])) {
    $actual_end_date = date('Y-m-d');
    
    $update_sql = "UPDATE `Order` SET ostatus = 'Waiting for inspection', actual_completion_date = ? WHERE orderid = ?";
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "si", $actual_end_date, $order_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $early_complete_message = "Construction marked as completed! Status changed to 'Waiting for inspection'. Client has been notified.";
        
        $client_sql = "SELECT clientid FROM `Order` WHERE orderid = ?";
        $client_stmt = mysqli_prepare($mysqli, $client_sql);
        mysqli_stmt_bind_param($client_stmt, "i", $order_id);
        mysqli_stmt_execute($client_stmt);
        $client_result = mysqli_stmt_get_result($client_stmt);
        $client_row = mysqli_fetch_assoc($client_result);
        $client_id = $client_row['clientid'];
        mysqli_stmt_close($client_stmt);
        
        $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                       VALUES ('client', ?, ?, 'Construction work has been completed for Order #" . $order_id . ". Please conduct inspection and approve the completion.', 'construction_complete', NOW())";
        $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
        mysqli_stmt_bind_param($notify_stmt, "ii", $client_id, $order_id);
        mysqli_stmt_execute($notify_stmt);
        mysqli_stmt_close($notify_stmt);
        
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
        $version_sql = "SELECT IFNULL(MAX(extension_version), 0) + 1 as new_version FROM ConstructionExtension WHERE orderid = ?";
        $version_stmt = mysqli_prepare($mysqli, $version_sql);
        mysqli_stmt_bind_param($version_stmt, "i", $order_id);
        mysqli_stmt_execute($version_stmt);
        $version_result = mysqli_stmt_get_result($version_stmt);
        $version_row = mysqli_fetch_assoc($version_result);
        $new_version = $version_row['new_version'] ?? 1;
        mysqli_stmt_close($version_stmt);
        
        $extend_sql = "INSERT INTO ConstructionExtension 
                       (orderid, requested_end_date, reason, status, extension_version, requested_by, requested_by_id, requested_at) 
                       VALUES (?, ?, ?, 'pending', ?, 'supplier', ?, NOW())";
        $extend_stmt = mysqli_prepare($mysqli, $extend_sql);
        mysqli_stmt_bind_param($extend_stmt, "issiis", $order_id, $new_end_date, $extension_reason, $new_version, $supplier_id);
        
        if (mysqli_stmt_execute($extend_stmt)) {
            $extension_message = "Extension request sent to client for approval.";
            
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

// Handle Weekly Report Save/Submit
$report_message = '';
$report_error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_report'])) {
    $week_number = intval($_POST['week_number'] ?? 0);
    $week_start = $_POST['week_start'] ?? '';
    $week_end = $_POST['week_end'] ?? '';
    $work_completed = $_POST['work_completed'] ?? '';
    $work_planned = $_POST['work_planned'] ?? '';
    $progress_percentage = intval($_POST['progress_percentage'] ?? 0);
    $request_extra_fee = isset($_POST['request_extra_fee']) ? 1 : 0;
    $action = $_POST['action_type'] ?? 'save';
    
    // Handle image uploads
    $image_paths = [];
    if (!empty($_FILES['report_images']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/weekly_reports/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['report_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['report_images']['error'][$key] == 0) {
                $filename = time() . '_' . $order_id . '_' . $week_number . '_' . $key . '_' . basename($_FILES['report_images']['name'][$key]);
                $target_path = $upload_dir . $filename;
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $image_paths[] = 'uploads/weekly_reports/' . $filename;
                }
            }
        }
    }
    
    // Get existing images if any
    $existing_sql = "SELECT image_paths FROM WeeklyConstructionReport WHERE orderid = ? AND week_number = ?";
    $existing_stmt = mysqli_prepare($mysqli, $existing_sql);
    mysqli_stmt_bind_param($existing_stmt, "ii", $order_id, $week_number);
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
    $existing_row = mysqli_fetch_assoc($existing_result);
    if ($existing_row && !empty($existing_row['image_paths'])) {
        $existing_images = json_decode($existing_row['image_paths'], true);
        if ($existing_images) {
            $image_paths = array_merge($existing_images, $image_paths);
        }
    }
    mysqli_stmt_close($existing_stmt);
    
    $image_paths_str = !empty($image_paths) ? json_encode($image_paths) : null;
    
    // Determine status based on action
    $status = ($action === 'submit') ? 'submitted' : 'draft';
    
    // Check if report already exists for this week
    $check_sql = "SELECT report_id, status FROM WeeklyConstructionReport 
                  WHERE orderid = ? AND week_number = ?";
    $check_stmt = mysqli_prepare($mysqli, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $week_number);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $existing = mysqli_fetch_assoc($check_result);
    
    if ($existing) {
        // Update existing report
        $update_sql = "UPDATE WeeklyConstructionReport 
                       SET work_completed = ?, work_planned = ?, progress_percentage = ?,
                           request_extra_fee = ?, image_paths = ?, status = ?, 
                           submitted_at = CASE WHEN ? = 'submitted' THEN NOW() ELSE submitted_at END
                       WHERE report_id = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ssiisssi", 
            $work_completed, $work_planned, $progress_percentage,
            $request_extra_fee, $image_paths_str, $status, $status, $existing['report_id']);
        
        if (mysqli_stmt_execute($update_stmt)) {
            if ($status == 'submitted') {
                $report_message = "Weekly report for Week $week_number submitted to client and designer!";
                
                // Notify client
                $client_sql = "SELECT clientid FROM `Order` WHERE orderid = ?";
                $client_stmt = mysqli_prepare($mysqli, $client_sql);
                mysqli_stmt_bind_param($client_stmt, "i", $order_id);
                mysqli_stmt_execute($client_stmt);
                $client_result = mysqli_stmt_get_result($client_stmt);
                $client_row = mysqli_fetch_assoc($client_result);
                $client_id = $client_row['clientid'];
                mysqli_stmt_close($client_stmt);
                
                $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                               VALUES ('client', ?, ?, 'Weekly construction report for Week $week_number has been submitted. Progress: $progress_percentage%', 'weekly_report', NOW())";
                $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
                mysqli_stmt_bind_param($notify_stmt, "ii", $client_id, $order_id);
                mysqli_stmt_execute($notify_stmt);
                mysqli_stmt_close($notify_stmt);
                
                // Notify designer
                $designer_sql = "SELECT d.designerid FROM Design d JOIN `Order` o ON o.designid = d.designid WHERE o.orderid = ?";
                $designer_stmt = mysqli_prepare($mysqli, $designer_sql);
                mysqli_stmt_bind_param($designer_stmt, "i", $order_id);
                mysqli_stmt_execute($designer_stmt);
                $designer_result = mysqli_stmt_get_result($designer_stmt);
                $designer_row = mysqli_fetch_assoc($designer_result);
                if ($designer_row) {
                    $designer_id = $designer_row['designerid'];
                    $notify_designer_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                                            VALUES ('designer', ?, ?, 'Weekly construction report for Week $week_number has been submitted. Progress: $progress_percentage%', 'weekly_report', NOW())";
                    $notify_designer_stmt = mysqli_prepare($mysqli, $notify_designer_sql);
                    mysqli_stmt_bind_param($notify_designer_stmt, "ii", $designer_id, $order_id);
                    mysqli_stmt_execute($notify_designer_stmt);
                    mysqli_stmt_close($notify_designer_stmt);
                }
                mysqli_stmt_close($designer_stmt);
                
                // Check if payment needs to be triggered based on progress
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
                
                // Get current total amount paid
                $paid_sql = "SELECT IFNULL(SUM(amount), 0) AS total_amount_paid
                             FROM ConstructionPaymentRecord
                             WHERE orderid = ? AND status = 'paid'";
                $paid_stmt = mysqli_prepare($mysqli, $paid_sql);
                mysqli_stmt_bind_param($paid_stmt, "i", $order_id);
                mysqli_stmt_execute($paid_stmt);
                $paid_result = mysqli_stmt_get_result($paid_stmt);
                $paid_row = mysqli_fetch_assoc($paid_result);
                $total_paid = floatval($paid_row['total_amount_paid'] ?? 0);
                mysqli_stmt_close($paid_stmt);
                
                // Get maximum progress from all submitted reports
                $max_progress_sql = "SELECT MAX(progress_percentage) as max_progress 
                                     FROM WeeklyConstructionReport 
                                     WHERE orderid = ? AND status = 'submitted'";
                $max_progress_stmt = mysqli_prepare($mysqli, $max_progress_sql);
                mysqli_stmt_bind_param($max_progress_stmt, "i", $order_id);
                mysqli_stmt_execute($max_progress_stmt);
                $max_progress_result = mysqli_stmt_get_result($max_progress_stmt);
                $max_progress_row = mysqli_fetch_assoc($max_progress_result);
                $current_max_progress = intval($max_progress_row['max_progress'] ?? 0);
                mysqli_stmt_close($max_progress_stmt);
                
                // Determine if payment is needed based on payment plan
                $amount_to_pay = 0;
                $milestone = '';
                $should_trigger_payment = false;
                
                if ($payment_plan == 'installment_25') {
                    if ($current_max_progress >= 25 && $total_paid < $total_cost * 0.25) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = 'Initial Payment (25%)';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 50 && $total_paid < $total_cost * 0.5) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = '50% Completion Payment';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 75 && $total_paid < $total_cost * 0.75) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = '75% Completion Payment';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 100 && $total_paid < $total_cost) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = 'Final Payment (100%)';
                        $should_trigger_payment = true;
                    }
                } elseif ($payment_plan == 'installment_50') {
                    if ($current_max_progress >= 50 && $total_paid < $total_cost) {
                        $amount_to_pay = $total_cost * 0.5;
                        $milestone = '50% Completion Payment';
                        $should_trigger_payment = true;
                    }
                }
                
                if ($should_trigger_payment && $amount_to_pay > 0) {
    // Create pending payment record
    $payment_record_sql = "INSERT INTO ConstructionPaymentRecord 
                           (orderid, installment_number, percentage, amount, milestone, paid_at, status)
                           VALUES (?, ?, ?, ?, ?, NULL, 'pending')";
    $installment_number = ceil($current_max_progress / 25);
    $payment_record_stmt = mysqli_prepare($mysqli, $payment_record_sql);
    mysqli_stmt_bind_param($payment_record_stmt, "iiids", 
        $order_id, $installment_number, $current_max_progress, $amount_to_pay, $milestone);
    mysqli_stmt_execute($payment_record_stmt);
    mysqli_stmt_close($payment_record_stmt);
    
    
    $report_message .= " A payment of $" . number_format($amount_to_pay, 2) . " is now required for the next construction phase.";
    
    // Notify client about required payment
    $payment_notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                           VALUES ('client', ?, ?, 'Construction has reached $current_max_progress% completion. A payment of $" . number_format($amount_to_pay, 2) . " is required to continue.', 'payment_required', NOW())";
    $payment_notify_stmt = mysqli_prepare($mysqli, $payment_notify_sql);
    mysqli_stmt_bind_param($payment_notify_stmt, "ii", $client_id, $order_id);
    mysqli_stmt_execute($payment_notify_stmt);
    mysqli_stmt_close($payment_notify_stmt);
}
                
            } else {
                $report_message = "Weekly report for Week $week_number saved as draft.";
            }
        } else {
            $report_error = "Failed to save report.";
        }
        mysqli_stmt_close($update_stmt);
    } else {
        // Insert new report
        $insert_sql = "INSERT INTO WeeklyConstructionReport 
                       (orderid, week_number, week_start_date, week_end_date, work_completed, work_planned, 
                        progress_percentage, image_paths, request_extra_fee, status, created_by_id, created_by_type)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'supplier')";
        $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
        mysqli_stmt_bind_param($insert_stmt, "iissssisisi", 
            $order_id, $week_number, $week_start, $week_end,
            $work_completed, $work_planned, $progress_percentage,
            $image_paths_str, $request_extra_fee, $status, $supplier_id);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            if ($status == 'submitted') {
                $report_message = "Weekly report for Week $week_number submitted to client and designer!";
                
                // Notify client
                $client_sql = "SELECT clientid FROM `Order` WHERE orderid = ?";
                $client_stmt = mysqli_prepare($mysqli, $client_sql);
                mysqli_stmt_bind_param($client_stmt, "i", $order_id);
                mysqli_stmt_execute($client_stmt);
                $client_result = mysqli_stmt_get_result($client_stmt);
                $client_row = mysqli_fetch_assoc($client_result);
                $client_id = $client_row['clientid'];
                mysqli_stmt_close($client_stmt);
                
                $notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                               VALUES ('client', ?, ?, 'Weekly construction report for Week $week_number has been submitted. Progress: $progress_percentage%', 'weekly_report', NOW())";
                $notify_stmt = mysqli_prepare($mysqli, $notify_sql);
                mysqli_stmt_bind_param($notify_stmt, "ii", $client_id, $order_id);
                mysqli_stmt_execute($notify_stmt);
                mysqli_stmt_close($notify_stmt);
                
                // Notify designer
                $designer_sql = "SELECT d.designerid FROM Design d JOIN `Order` o ON o.designid = d.designid WHERE o.orderid = ?";
                $designer_stmt = mysqli_prepare($mysqli, $designer_sql);
                mysqli_stmt_bind_param($designer_stmt, "i", $order_id);
                mysqli_stmt_execute($designer_stmt);
                $designer_result = mysqli_stmt_get_result($designer_stmt);
                $designer_row = mysqli_fetch_assoc($designer_result);
                if ($designer_row) {
                    $designer_id = $designer_row['designerid'];
                    $notify_designer_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                                            VALUES ('designer', ?, ?, 'Weekly construction report for Week $week_number has been submitted. Progress: $progress_percentage%', 'weekly_report', NOW())";
                    $notify_designer_stmt = mysqli_prepare($mysqli, $notify_designer_sql);
                    mysqli_stmt_bind_param($notify_designer_stmt, "ii", $designer_id, $order_id);
                    mysqli_stmt_execute($notify_designer_stmt);
                    mysqli_stmt_close($notify_designer_stmt);
                }
                mysqli_stmt_close($designer_stmt);
                
                // Check if payment needs to be triggered based on progress
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
                
                // Get current total amount paid
                $paid_sql = "SELECT IFNULL(SUM(amount), 0) AS total_amount_paid
                             FROM ConstructionPaymentRecord
                             WHERE orderid = ? AND status = 'paid'";
                $paid_stmt = mysqli_prepare($mysqli, $paid_sql);
                mysqli_stmt_bind_param($paid_stmt, "i", $order_id);
                mysqli_stmt_execute($paid_stmt);
                $paid_result = mysqli_stmt_get_result($paid_stmt);
                $paid_row = mysqli_fetch_assoc($paid_result);
                $total_paid = floatval($paid_row['total_amount_paid'] ?? 0);
                mysqli_stmt_close($paid_stmt);
                
                // Get maximum progress from all submitted reports
                $max_progress_sql = "SELECT MAX(progress_percentage) as max_progress 
                                     FROM WeeklyConstructionReport 
                                     WHERE orderid = ? AND status = 'submitted'";
                $max_progress_stmt = mysqli_prepare($mysqli, $max_progress_sql);
                mysqli_stmt_bind_param($max_progress_stmt, "i", $order_id);
                mysqli_stmt_execute($max_progress_stmt);
                $max_progress_result = mysqli_stmt_get_result($max_progress_stmt);
                $max_progress_row = mysqli_fetch_assoc($max_progress_result);
                $current_max_progress = intval($max_progress_row['max_progress'] ?? 0);
                mysqli_stmt_close($max_progress_stmt);
                
                // Determine if payment is needed based on payment plan
                $amount_to_pay = 0;
                $milestone = '';
                $should_trigger_payment = false;
                
                if ($payment_plan == 'installment_25') {
                    if ($current_max_progress >= 25 && $total_paid < $total_cost * 0.25) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = 'Initial Payment (25%)';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 50 && $total_paid < $total_cost * 0.5) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = '50% Completion Payment';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 75 && $total_paid < $total_cost * 0.75) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = '75% Completion Payment';
                        $should_trigger_payment = true;
                    } elseif ($current_max_progress >= 100 && $total_paid < $total_cost) {
                        $amount_to_pay = $total_cost * 0.25;
                        $milestone = 'Final Payment (100%)';
                        $should_trigger_payment = true;
                    }
                } elseif ($payment_plan == 'installment_50') {
                    if ($current_max_progress >= 50 && $total_paid < $total_cost) {
                        $amount_to_pay = $total_cost * 0.5;
                        $milestone = '50% Completion Payment';
                        $should_trigger_payment = true;
                    }
                }
                
                if ($should_trigger_payment && $amount_to_pay > 0) {
                    // Create pending payment record
                    $payment_record_sql = "INSERT INTO ConstructionPaymentRecord 
                                           (orderid, installment_number, percentage, amount, milestone, paid_at, status)
                                           VALUES (?, ?, ?, ?, ?, NULL, 'pending')";
                    $installment_number = ceil($current_max_progress / 25);
                    $payment_record_stmt = mysqli_prepare($mysqli, $payment_record_sql);
                    mysqli_stmt_bind_param($payment_record_stmt, "iiids", 
                        $order_id, $installment_number, $current_max_progress, $amount_to_pay, $milestone);
                    mysqli_stmt_execute($payment_record_stmt);
                    mysqli_stmt_close($payment_record_stmt);
                    
                    $report_message .= " A payment of $" . number_format($amount_to_pay, 2) . " is now required for the next construction phase.";
                    
                    // Notify client about required payment
                    $payment_notify_sql = "INSERT INTO Notification (user_type, user_id, orderid, message, type, created_at) 
                                           VALUES ('client', ?, ?, 'Construction has reached $current_max_progress% completion. A payment of $" . number_format($amount_to_pay, 2) . " is required to continue.', 'payment_required', NOW())";
                    $payment_notify_stmt = mysqli_prepare($mysqli, $payment_notify_sql);
                    mysqli_stmt_bind_param($payment_notify_stmt, "ii", $client_id, $order_id);
                    mysqli_stmt_execute($payment_notify_stmt);
                    mysqli_stmt_close($payment_notify_stmt);
                }
                
            } else {
                $report_message = "Weekly report for Week $week_number saved as draft.";
            }
        } else {
            $report_error = "Failed to save report.";
        }
        mysqli_stmt_close($insert_stmt);
    }
    mysqli_stmt_close($check_stmt);
}

// Handle Supplier Response to Feedback
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['respond_feedback'])) {
    $week_number = intval($_POST['week_number'] ?? 0);
    $supplier_response = $_POST['supplier_response'] ?? '';
    
    $response_sql = "UPDATE WeeklyConstructionReport 
                     SET supplier_response = ?, supplier_response_at = NOW()
                     WHERE orderid = ? AND week_number = ?";
    $response_stmt = mysqli_prepare($mysqli, $response_sql);
    mysqli_stmt_bind_param($response_stmt, "sii", $supplier_response, $order_id, $week_number);
    
    if (mysqli_stmt_execute($response_stmt)) {
        $report_message = "Response sent to client and designer.";
    } else {
        $report_error = "Failed to send response.";
    }
    mysqli_stmt_close($response_stmt);
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

// Fetch order info
$order_sql = "SELECT o.orderid, o.ostatus, o.odate, o.actual_completion_date, o.Requirements,
                     c.cname as client_name, c.clientid,
                     IFNULL(op.total_cost, 0) AS total_amount_due,
                     (SELECT IFNULL(SUM(cpr.amount), 0) FROM ConstructionPaymentRecord cpr WHERE cpr.orderid = o.orderid AND cpr.status = 'paid') AS total_amount_paid,
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
$designer_id = 0;
if (!empty($order_info['designid'])) {
    $designer_sql = "SELECT designerid, dname FROM Designer WHERE designerid = (SELECT designerid FROM Design WHERE designid = ?)";
    $designer_stmt = mysqli_prepare($mysqli, $designer_sql);
    mysqli_stmt_bind_param($designer_stmt, "i", $order_info['designid']);
    mysqli_stmt_execute($designer_stmt);
    $designer_result = mysqli_stmt_get_result($designer_stmt);
    $designer_row = mysqli_fetch_assoc($designer_result);
    $designer_name = $designer_row['dname'] ?? 'N/A';
    $designer_id = $designer_row['designerid'] ?? 0;
    mysqli_stmt_close($designer_stmt);
}

$today = date('Y-m-d');
$start_date = $schedule['construction_start_date'] ?? null;
$end_date = $schedule['construction_end_date'] ?? null;
$design_finish_date = $schedule['DesignFinishDate'] ?? null;
$order_finish_date = $schedule['OrderFinishDate'] ?? null;

// Calculate total weeks and generate week data
$total_weeks = 0;
$weeks_data = [];

if ($start_date && $end_date) {
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $total_days = ($end_ts - $start_ts) / 86400;
    $total_weeks = ceil(($total_days + 1) / 7);
    
    for ($w = 1; $w <= $total_weeks; $w++) {
        $week_start_ts = $start_ts + (($w - 1) * 7 * 86400);
        $week_end_ts = min($week_start_ts + (6 * 86400), $end_ts);
        $week_start = date('Y-m-d', $week_start_ts);
        $week_end = date('Y-m-d', $week_end_ts);
        
        // Fetch existing report for this week
        $report_sql = "SELECT * FROM WeeklyConstructionReport WHERE orderid = ? AND week_number = ?";
        $report_stmt = mysqli_prepare($mysqli, $report_sql);
        mysqli_stmt_bind_param($report_stmt, "ii", $order_id, $w);
        mysqli_stmt_execute($report_stmt);
        $report_result = mysqli_stmt_get_result($report_stmt);
        $existing_report = mysqli_fetch_assoc($report_result);
        mysqli_stmt_close($report_stmt);
        
        $is_past_week = ($week_end_ts < strtotime($today));
        $is_current_week = ($week_start_ts <= strtotime($today) && $week_end_ts >= strtotime($today));
        $is_future_week = ($week_start_ts > strtotime($today));
        
        // Get max progress from previous weeks for validation
        $max_prev_progress = 0;
        if ($w > 1) {
            $prev_sql = "SELECT MAX(progress_percentage) as max_progress FROM WeeklyConstructionReport 
                         WHERE orderid = ? AND week_number < ? AND status = 'submitted'";
            $prev_stmt = mysqli_prepare($mysqli, $prev_sql);
            mysqli_stmt_bind_param($prev_stmt, "ii", $order_id, $w);
            mysqli_stmt_execute($prev_stmt);
            $prev_result = mysqli_stmt_get_result($prev_stmt);
            $prev_row = mysqli_fetch_assoc($prev_result);
            $max_prev_progress = $prev_row['max_progress'] ?? 0;
            mysqli_stmt_close($prev_stmt);
        }
        
        $weeks_data[] = [
            'week_number' => $w,
            'week_start' => $week_start,
            'week_end' => $week_end,
            'is_past' => $is_past_week,
            'is_current' => $is_current_week,
            'is_future' => $is_future_week,
            'existing_report' => $existing_report,
            'max_prev_progress' => $max_prev_progress
        ];
    }
}

$is_construction_ongoing = ($order_info['ostatus'] == 'In construction' || $order_info['ostatus'] == 'preparing');
$is_waiting_inspection = ($order_info['ostatus'] == 'Waiting for inspection');
$is_complete = ($order_info['ostatus'] == 'complete');
$can_mark_complete = $is_construction_ongoing && !$is_complete && !$is_waiting_inspection;
$can_request_extension = $end_date && $today > $end_date && !$pending_extension && $is_construction_ongoing;

// Calendar generation
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

if ($current_month < 1 || $current_month > 12) $current_month = date('m');
if ($current_year < 2000 || $current_year > 2100) $current_year = date('Y');

function generateCalendar($month, $year, $start_date, $end_date, $today) {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $last_day = date('t', $first_day);
    $first_day_of_week = date('w', $first_day);
    
    $calendar = [];
    $week = [];
    
    for ($i = 0; $i < $first_day_of_week; $i++) {
        $week[] = null;
    }
    
    for ($day = 1; $day <= $last_day; $day++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
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
    
    if (!empty($week)) {
        while (count($week) < 7) $week[] = null;
        $calendar[] = $week;
    }
    
    return $calendar;
}

$calendar = generateCalendar($current_month, $current_year, $start_date, $end_date, $today);

$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

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
        body { background-color: #f0f0f0; }
        .dashboard-header { background: linear-gradient(135deg, #2c3e50, #3498db); color: white; padding: 2rem 0; margin-bottom: 2rem; border-radius: 0 0 15px 15px; }
        .dashboard-header h2 { margin: 0; font-weight: 600; }
        .dashboard-header p { margin: 0.5rem 0 0 0; opacity: 0.9; }
        .calendar-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; margin-bottom: 2rem; }
        .calendar-nav a { background-color: #3498db; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; transition: all 0.3s ease; font-weight: 600; }
        .calendar-nav a:hover { background-color: #2980b9; transform: translateY(-2px); text-decoration: none; color: white; }
        .month-year { font-size: 1.8rem; font-weight: 600; color: #2c3e50; }
        .calendar-table { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 2rem; }
        .calendar-table table { width: 100%; border-collapse: collapse; margin: 0; }
        .calendar-table th { background-color: #f8f9fa; color: #2c3e50; padding: 1rem; text-align: center; font-weight: 600; border-bottom: 2px solid #ecf0f1; }
        .calendar-table td { width: 14.28%; height: 100px; padding: 0.75rem; border: 1px solid #ecf0f1; vertical-align: top; background-color: #fff; transition: background-color 0.2s; cursor: pointer; }
        .calendar-table td:hover { background-color: #f8f9fa; }
        .day-number { font-weight: 600; color: #2c3e50; margin-bottom: 0.5rem; font-size: 1rem; }
        .calendar-table td.cell-start { background: linear-gradient(135deg, #d4edda, #a8e6a8); border-left: 4px solid #28a745; }
        .calendar-table td.cell-end { background: linear-gradient(135deg, #f8d7da, #f5b8be); border-left: 4px solid #dc3545; }
        .calendar-table td.cell-today { background: linear-gradient(135deg, #cce5ff, #99c9ff); border: 2px solid #007bff; box-shadow: 0 0 8px rgba(0,123,255,0.3); }
        .calendar-table td.cell-past { background-color: #e9ecef; }
        .calendar-table td.cell-past .day-number { color: #adb5bd; }
        .calendar-table td.cell-before_start { background-color: #f8f9fa; }
        .calendar-table td.cell-before_start .day-number { color: #dee2e6; }
        .calendar-table td.cell-after_end { background-color: #f8f9fa; }
        .calendar-table td.cell-after_end .day-number { color: #dee2e6; }
        .day-number.start-mark { background-color: #28a745; color: white; padding: 0.25rem 0.5rem; border-radius: 20px; display: inline-block; }
        .day-number.end-mark { background-color: #dc3545; color: white; padding: 0.25rem 0.5rem; border-radius: 20px; display: inline-block; }
        .day-number.today-mark { background-color: #007bff; color: white; padding: 0.25rem 0.5rem; border-radius: 50%; display: inline-block; width: 32px; text-align: center; }
        .construction-badge { font-size: 0.7rem; background-color: rgba(52, 152, 219, 0.1); padding: 0.2rem 0.4rem; border-radius: 4px; margin-top: 0.3rem; text-align: center; }
        .legend { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; padding: 1rem; background: white; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .legend-item { display: flex; align-items: center; gap: 0.5rem; }
        .legend-color { width: 30px; height: 30px; border-radius: 6px; }
        .badge { padding: 0.5rem 0.8rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .modal-content { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); }
        .modal-header { border-bottom: 1px solid #ecf0f1; background-color: #f8f9fa; border-radius: 15px 15px 0 0; }
        .modal-title { color: #2c3e50; font-weight: 600; }
        .order-detail { margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #ecf0f1; }
        .order-detail:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { font-weight: 600; color: #3498db; font-size: 0.85rem; text-transform: uppercase; margin-bottom: 0.3rem; }
        .detail-value { color: #2c3e50; font-size: 1rem; }
        .info-message { background-color: #e8f4f8; border-left: 4px solid #3498db; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
        .week-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 1.5rem; overflow: hidden; }
        .week-header { padding: 1rem 1.5rem; cursor: pointer; transition: background-color 0.2s; }
        .week-header.bg-success-light { background-color: #d4edda; }
        .week-header.bg-warning-light { background-color: #fff3cd; }
        .week-header.bg-secondary-light { background-color: #e9ecef; }
        .week-header:hover { filter: brightness(0.97); }
        .week-body { padding: 1.5rem; border-top: 1px solid #dee2e6; }
        .report-label { font-weight: 600; color: #2c3e50; margin-bottom: 0.5rem; }
        .report-textarea { width: 100%; border: 1px solid #dee2e6; border-radius: 8px; padding: 0.75rem; resize: vertical; }
        .image-preview { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .image-preview img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #dee2e6; cursor: pointer; }
        .progress-bar-custom { height: 8px; border-radius: 4px; background-color: #e9ecef; overflow: hidden; margin: 0.5rem 0; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
        .btn-add-week { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 50px; font-weight: 600; margin-top: 1rem; }
        .btn-add-week:hover { background: linear-gradient(135deg, #218838, #1ba87e); color: white; }
        .feedback-box { background-color: #f8f9fa; border-left: 3px solid #17a2b8; padding: 0.75rem; margin-top: 0.75rem; border-radius: 6px; }
        .client-feedback { border-left-color: #28a745; }
        .designer-feedback { border-left-color: #fd7e14; }
        .supplier-response { border-left-color: #6c757d; background-color: #e9ecef; }
        .upload-area { border: 2px dashed #3498db; border-radius: 8px; padding: 2rem; text-align: center; background: #f8f9fa; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { background: #e8f4f8; border-color: #2980b9; }
        .upload-area.dragover { background: #d4e9f7; border-color: #2980b9; }
        .preview-section { border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem; background: #f8f9fa; margin-top: 1rem; }
        .preview-image-item { position: relative; display: inline-block; margin: 5px; }
        
        @media (max-width: 768px) {
            .calendar-table td { height: 70px; padding: 0.4rem; }
            .day-number { font-size: 0.8rem; }
            .calendar-nav a { padding: 0.5rem 1rem; font-size: 0.9rem; }
            .month-year { font-size: 1.3rem; }
            .legend { gap: 0.8rem; }
            .legend-item span { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    
    <div class="dashboard-header">
        <div class="container">
            <h2><i class="fas fa-calendar-alt me-2"></i>Construction Calendar</h2>
            <p>Project #<?= $order_id ?> - <?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></p>
        </div>
    </div>
    
    <div class="container mb-5">
        <a href="ProjectWorkerManagement.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left"></i> Back to Projects
        </a>
        
        <?php if ($early_complete_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?= $early_complete_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($early_complete_error): ?>
            <div class="alert alert-danger"><?= $early_complete_error ?></div>
        <?php endif; ?>
        <?php if ($extension_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-paper-plane me-2"></i><?= $extension_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($extension_error): ?>
            <div class="alert alert-danger"><?= $extension_error ?></div>
        <?php endif; ?>
        <?php if ($report_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-file-alt me-2"></i><?= $report_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($report_error): ?>
            <div class="alert alert-danger"><?= $report_error ?></div>
        <?php endif; ?>
        
        <?php if ($is_waiting_inspection): ?>
            <div class="info-message"><i class="fas fa-clock me-2 text-primary"></i><strong>Waiting for Client Inspection</strong> - The construction has been marked as complete. The client has been notified and will inspect the work.</div>
        <?php endif; ?>
        
        <!-- Project Status Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Project Information</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3"><strong>Status:</strong><br><span class="badge bg-<?= $is_complete ? 'success' : ($is_waiting_inspection ? 'info' : 'primary') ?>"><?= htmlspecialchars($order_info['ostatus'] ?? 'N/A') ?></span></div>
                    <div class="col-md-3"><strong>Payment:</strong><br><span class="text-success">$<?= number_format($order_info['total_amount_paid'] ?? 0, 2) ?></span> / <span class="text-muted">$<?= number_format($order_info['total_amount_due'] ?? 0, 2) ?></span></div>
                    <div class="col-md-3"><strong>Client:</strong><br><?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></div>
                    <div class="col-md-3"><strong>Order Date:</strong><br><?= date('Y-m-d', strtotime($order_info['odate'] ?? $today)) ?></div>
                </div>
            </div>
        </div>
        
        <!-- Legend -->
        <div class="legend">
            <div class="legend-item"><div class="legend-color" style="background: linear-gradient(135deg, #d4edda, #a8e6a8); border-left: 4px solid #28a745;"></div><span>Construction Start Date</span></div>
            <div class="legend-item"><div class="legend-color" style="background: linear-gradient(135deg, #cce5ff, #99c9ff); border: 2px solid #007bff;"></div><span>Today</span></div>
            <div class="legend-item"><div class="legend-color" style="background: linear-gradient(135deg, #f8d7da, #f5b8be); border-left: 4px solid #dc3545;"></div><span>Construction End Date</span></div>
            <div class="legend-item"><div class="legend-color" style="background-color: #e9ecef;"></div><span>Past Days</span></div>
            <div class="legend-item"><div class="legend-color" style="background-color: #fff; border: 1px solid #dee2e6;"></div><span>Future Days</span></div>
            <div class="legend-item"><div class="legend-color" style="background-color: #fff; border: 1px solid #3498db;"></div><span><i class="fas fa-mouse-pointer"></i> Click for Details</span></div>
        </div>
        
        <!-- Calendar Navigation -->
        <div class="calendar-nav">
            <a href="?orderid=<?= $order_id ?>&month=<?= $prev_month ?>&year=<?= $prev_year ?>"><i class="fas fa-chevron-left me-2"></i>Previous</a>
            <div class="month-year"><?= $month_name . ' ' . $current_year ?></div>
            <a href="?orderid=<?= $order_id ?>&month=<?= $next_month ?>&year=<?= $next_year ?>">Next<i class="fas fa-chevron-right ms-2"></i></a>
        </div>
        
        <!-- Calendar Table -->
        <div class="calendar-table">
            <table>
                <thead><tr><th>Sunday</th><th>Monday</th><th>Tuesday</th><th>Wednesday</th><th>Thursday</th><th>Friday</th><th>Saturday</th></tr></thead>
                <tbody>
                    <?php foreach ($calendar as $week): ?>
                    <tr>
                        <?php foreach ($week as $day): ?>
                            <?php if ($day === null): ?>
                                <td class="cell-before_start"></td>
                            <?php else: ?>
                                <td class="cell-<?= $day['cell_type'] ?>" onclick="showOrderDetails()">
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
                                        <div class="construction-badge"><i class="fas fa-hard-hat"></i></div>
                                    <?php endif; ?>
                                    
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
            <div class="card-header bg-secondary text-white"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Construction Progress</h5></div>
            <div class="card-body">
                <?php
                $total_days = (new DateTime($end_date))->diff(new DateTime($start_date))->days + 1;
                $elapsed_days = 0;
                $today_obj = new DateTime($today);
                $start_obj = new DateTime($start_date);
                $end_obj = new DateTime($end_date);
                
                if ($today_obj >= $start_obj) {
                    if ($today_obj <= $end_obj) $elapsed_days = $today_obj->diff($start_obj)->days + 1;
                    else $elapsed_days = $total_days;
                }
                
                $percent = $total_days > 0 ? round(($elapsed_days / $total_days) * 100) : 0;
                $is_overdue = $today_obj > $end_obj && !$is_complete && !$is_waiting_inspection;
                ?>
                
                <div class="progress mb-3" style="height: 35px;">
                    <div class="progress-bar <?= $is_overdue ? 'bg-danger' : 'bg-success' ?>" style="width: <?= min(100, $percent) ?>%;"><?= $percent ?>% Complete</div>
                </div>
                
                <div class="row text-center">
                    <div class="col-md-4"><strong>Construction Start:</strong><br><?= date('Y-m-d', strtotime($start_date)) ?></div>
                    <div class="col-md-4"><strong>Elapsed Days:</strong><br><?= $elapsed_days ?> / <?= $total_days ?> days</div>
                    <div class="col-md-4"><strong>Construction End:</strong><br><?php if ($pending_extension && $pending_extension['status'] == 'pending'): ?><span class="text-warning"><?= date('Y-m-d', strtotime($pending_extension['requested_end_date'])) ?></span><br><small>(Pending Approval)</small><?php else: ?><?= date('Y-m-d', strtotime($end_date)) ?><?php endif; ?></div>
                </div>
                
                <?php if ($is_overdue && !$pending_extension): ?>
                    <div class="alert alert-warning text-center mt-3"><i class="fas fa-exclamation-triangle me-2"></i>Construction has exceeded the scheduled end date! Please request an extension.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- WEEKLY REPORTS SECTION -->
        <div class="mt-4">
            <h4><i class="fas fa-calendar-week me-2"></i>Weekly Construction Reports</h4>
            <p class="text-muted">Submit weekly progress reports for each week of construction.</p>
            
            <?php 
            $displayed_weeks = 0;
            $max_weeks_to_show = min(5, $total_weeks);
            for ($i = 0; $i < $max_weeks_to_show && $i < count($weeks_data); $i++):
                $week = $weeks_data[$i];
                $existing = $week['existing_report'];
                $is_locked = $existing && $existing['status'] == 'submitted';
                $can_edit = !$is_locked && !$is_complete && !$is_waiting_inspection;
                $min_progress = $week['max_prev_progress'];
                $current_progress = $existing ? $existing['progress_percentage'] : $min_progress;
                $displayed_weeks++;
            ?>
            <div class="week-card">
                <div class="week-header <?= $existing && $existing['status'] == 'submitted' ? 'bg-success-light' : ($week['is_current'] ? 'bg-warning-light' : 'bg-secondary-light') ?>" onclick="toggleWeek(<?= $week['week_number'] ?>)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="fas fa-calendar-week me-2"></i>Week <?= $week['week_number'] ?></strong>
                            <span class="text-muted ms-2"><?= date('M d', strtotime($week['week_start'])) ?> - <?= date('M d', strtotime($week['week_end'])) ?></span>
                            <?php if ($existing && $existing['status'] == 'submitted'): ?>
                                <span class="badge bg-success ms-2"><i class="fas fa-check-circle"></i> Submitted</span>
                            <?php elseif ($existing && $existing['status'] == 'draft'): ?>
                                <span class="badge bg-warning ms-2"><i class="fas fa-pencil-alt"></i> Draft</span>
                            <?php elseif (!$existing): ?>
                                <span class="badge bg-secondary ms-2"><i class="fas fa-plus"></i> Not Started</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($existing && $existing['progress_percentage'] > 0): ?>
                                <span class="badge bg-primary"><?= $existing['progress_percentage'] ?>% Complete</span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-down ms-2"></i>
                        </div>
                    </div>
                    <div class="progress-bar-custom mt-2">
                        <div class="progress-fill bg-primary" style="width: <?= $current_progress ?>%;"></div>
                    </div>
                </div>
                <div class="week-body" id="week-body-<?= $week['week_number'] ?>" style="display: none;">
                    <?php if ($can_edit): ?>
                        <form method="POST" enctype="multipart/form-data" id="reportForm_<?= $week['week_number'] ?>">
                            <input type="hidden" name="week_number" value="<?= $week['week_number'] ?>">
                            <input type="hidden" name="week_start" value="<?= $week['week_start'] ?>">
                            <input type="hidden" name="week_end" value="<?= $week['week_end'] ?>">
                            <input type="hidden" name="action_type" id="action_type_<?= $week['week_number'] ?>" value="save">
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-percent me-1 text-primary"></i>Work Progress (%)</label>
                                <input type="range" class="form-range" name="progress_percentage" min="<?= $min_progress ?>" max="100" step="1" value="<?= $current_progress ?>" oninput="this.nextElementSibling.value = this.value">
                                <output><?= $current_progress ?>%</output>
                                <small class="text-muted d-block">Previous week progress: <?= $min_progress ?>% | Must be >= <?= $min_progress ?>%</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-check-circle me-1 text-success"></i>Work Completed This Week</label>
                                <textarea class="report-textarea" name="work_completed" rows="4" placeholder="Describe what has been accomplished this week..."><?= htmlspecialchars($existing['work_completed'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-tasks me-1 text-info"></i>Planned Work for Next Week</label>
                                <textarea class="report-textarea" name="work_planned" rows="4" placeholder="Describe what will be done next week..."><?= htmlspecialchars($existing['work_planned'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-image me-1 text-secondary"></i>Upload Images (Optional)</label>
                                <div id="uploadContainer_<?= $week['week_number'] ?>" class="upload-section">
                                    <label class="upload-area" id="uploadArea_<?= $week['week_number'] ?>">
                                        <div>
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                            <strong>Click to upload or drag & drop</strong>
                                            <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP (Max 10MB)</p>
                                        </div>
                                        <input type="file" id="fileInput_<?= $week['week_number'] ?>" name="report_images[]" multiple accept="image/*" style="display: none;" onchange="previewImages(<?= $week['week_number'] ?>, this.files)">
                                    </label>
                                    <div id="previewSection_<?= $week['week_number'] ?>" style="display: none;">
                                        <div class="preview-section">
                                            <p style="margin-bottom: 0.5rem; color: #6c757d; font-size: 0.9rem;">
                                                <strong>Preview:</strong>
                                            </p>
                                            <div id="imagePreviewList_<?= $week['week_number'] ?>" class="image-preview"></div>
                                            <div class="text-muted small">Images will be uploaded when you click Save Draft or Submit.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($existing['image_paths'])): 
                                    $saved_images = json_decode($existing['image_paths'], true);
                                    if ($saved_images): ?>
                                    <div class="image-preview mt-2">
                                        <?php foreach ($saved_images as $img): ?>
                                            <img src="../<?= $img ?>" alt="Progress Image" onclick="window.open('../<?= $img ?>', '_blank')">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; endif; ?>
                            </div>
                            
                            <div class="mb-3 border rounded p-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="request_extra_fee" id="request_extra_fee_<?= $week['week_number'] ?>" value="1" <?= ($existing && $existing['request_extra_fee']) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="request_extra_fee_<?= $week['week_number'] ?>">
                                        <i class="fas fa-dollar-sign me-1 text-warning"></i>Request Additional Construction Fee
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <?php if (!$existing || $existing['status'] != 'submitted'): ?>
                                    <button type="submit" name="save_report" class="btn btn-secondary" onclick="setActionTypeAndSubmit(<?= $week['week_number'] ?>, 'save')">
                                        <i class="fas fa-save me-1"></i>Save Draft
                                    </button>
                                    <button type="submit" name="save_report" class="btn btn-primary" onclick="return confirmSubmitAndSetAction(<?= $week['week_number'] ?>)">
                                        <i class="fas fa-paper-plane me-1"></i>Submit to Client & Designer
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                        
                        <?php if ($existing && (!empty($existing['client_feedback']) || !empty($existing['designer_feedback']))): ?>
                            <div class="mt-3">
                                <hr>
                                <h6><i class="fas fa-comments me-2"></i>Feedback Received</h6>
                                
                                <?php if (!empty($existing['client_feedback'])): ?>
                                    <div class="feedback-box client-feedback">
                                        <strong><i class="fas fa-user me-1"></i>Client Feedback:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($existing['client_feedback'])) ?></p>
                                        <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['client_feedback_at'])) ?></small>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($existing['designer_feedback'])): ?>
                                    <div class="feedback-box designer-feedback">
                                        <strong><i class="fas fa-user-tie me-1"></i>Designer Feedback:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($existing['designer_feedback'])) ?></p>
                                        <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['designer_feedback_at'])) ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($is_locked): ?>
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-percent me-1 text-primary"></i>Progress</label>
                            <div class="progress-bar-custom">
                                <div class="progress-fill bg-primary" style="width: <?= $existing['progress_percentage'] ?>%;"></div>
                            </div>
                            <p class="mt-2"><?= $existing['progress_percentage'] ?>% Complete</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-check-circle me-1 text-success"></i>Work Completed</label>
                            <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($existing['work_completed'] ?? '')) ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-tasks me-1 text-info"></i>Planned Work</label>
                            <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($existing['work_planned'] ?? '')) ?></p>
                        </div>
                        
                        <?php if (!empty($existing['image_paths'])): 
                            $view_images = json_decode($existing['image_paths'], true);
                            if ($view_images): ?>
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-image me-1"></i>Images</label>
                                <div class="image-preview">
                                    <?php foreach ($view_images as $img): ?>
                                        <img src="../<?= $img ?>" alt="Progress Image" style="cursor: pointer;" onclick="window.open('../<?= $img ?>', '_blank')">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; endif; ?>
                        
                        <?php if ($existing['request_extra_fee']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-dollar-sign me-2"></i>
                                <strong>Additional Fee Requested</strong> - Client will be notified.
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <hr>
                            <h6><i class="fas fa-comments me-2"></i>Feedback & Responses</h6>
                            
                            <?php if (!empty($existing['client_feedback'])): ?>
                                <div class="feedback-box client-feedback">
                                    <strong><i class="fas fa-user me-1"></i>Client Feedback:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['client_feedback'])) ?></p>
                                    <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['client_feedback_at'])) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($existing['designer_feedback'])): ?>
                                <div class="feedback-box designer-feedback">
                                    <strong><i class="fas fa-user-tie me-1"></i>Designer Feedback:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['designer_feedback'])) ?></p>
                                    <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['designer_feedback_at'])) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($existing['supplier_response'])): ?>
                                <div class="feedback-box supplier-response">
                                    <strong><i class="fas fa-building me-1"></i>Your Response:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['supplier_response'])) ?></p>
                                    <small class="text-muted">Responded: <?= date('M d, Y H:i', strtotime($existing['supplier_response_at'])) ?></small>
                                </div>
                            <?php else: ?>
                                <?php if (!empty($existing['client_feedback']) || !empty($existing['designer_feedback'])): ?>
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="week_number" value="<?= $week['week_number'] ?>">
                                        <div class="mb-2">
                                            <label class="report-label"><i class="fas fa-reply me-1"></i>Your Response to Feedback</label>
                                            <textarea class="report-textarea" name="supplier_response" rows="3" placeholder="Respond to client/designer feedback..."></textarea>
                                        </div>
                                        <button type="submit" name="respond_feedback" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane me-1"></i>Send Response
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>This report has been submitted and is no longer editable.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock me-2"></i>This report cannot be edited because the project is completed or under inspection.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
            
            <!-- Add More Weeks Button -->
            <?php if ($displayed_weeks < count($weeks_data) && $is_construction_ongoing && !$is_complete): ?>
            <div class="text-center mt-3">
                <button class="btn-add-week" onclick="showAllWeeks()">
                    <i class="fas fa-plus me-2"></i>Show More Weeks (<?= count($weeks_data) - $displayed_weeks ?> more)
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Hidden weeks for JavaScript to show -->
            <?php for ($i = $max_weeks_to_show; $i < count($weeks_data); $i++):
                $week = $weeks_data[$i];
                $existing = $week['existing_report'];
                $is_locked = $existing && $existing['status'] == 'submitted';
                $can_edit = !$is_locked && !$is_complete && !$is_waiting_inspection;
                $min_progress = $week['max_prev_progress'];
                $current_progress = $existing ? $existing['progress_percentage'] : $min_progress;
            ?>
            <div class="week-card extra-week" style="display: none;">
                <div class="week-header <?= $existing && $existing['status'] == 'submitted' ? 'bg-success-light' : ($week['is_current'] ? 'bg-warning-light' : 'bg-secondary-light') ?>" onclick="toggleWeek(<?= $week['week_number'] ?>)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="fas fa-calendar-week me-2"></i>Week <?= $week['week_number'] ?></strong>
                            <span class="text-muted ms-2"><?= date('M d', strtotime($week['week_start'])) ?> - <?= date('M d', strtotime($week['week_end'])) ?></span>
                            <?php if ($existing && $existing['status'] == 'submitted'): ?>
                                <span class="badge bg-success ms-2"><i class="fas fa-check-circle"></i> Submitted</span>
                            <?php elseif ($existing && $existing['status'] == 'draft'): ?>
                                <span class="badge bg-warning ms-2"><i class="fas fa-pencil-alt"></i> Draft</span>
                            <?php elseif (!$existing): ?>
                                <span class="badge bg-secondary ms-2"><i class="fas fa-plus"></i> Not Started</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($existing && $existing['progress_percentage'] > 0): ?>
                                <span class="badge bg-primary"><?= $existing['progress_percentage'] ?>% Complete</span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-down ms-2"></i>
                        </div>
                    </div>
                    <div class="progress-bar-custom mt-2">
                        <div class="progress-fill bg-primary" style="width: <?= $current_progress ?>%;"></div>
                    </div>
                </div>
                <div class="week-body" id="week-body-<?= $week['week_number'] ?>" style="display: none;">
                    <?php if ($can_edit): ?>
                        <form method="POST" enctype="multipart/form-data" id="reportForm_<?= $week['week_number'] ?>">
                            <input type="hidden" name="week_number" value="<?= $week['week_number'] ?>">
                            <input type="hidden" name="week_start" value="<?= $week['week_start'] ?>">
                            <input type="hidden" name="week_end" value="<?= $week['week_end'] ?>">
                            <input type="hidden" name="action_type" id="action_type_<?= $week['week_number'] ?>" value="save">
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-percent me-1 text-primary"></i>Work Progress (%)</label>
                                <input type="range" class="form-range" name="progress_percentage" min="<?= $min_progress ?>" max="100" step="1" value="<?= $current_progress ?>" oninput="this.nextElementSibling.value = this.value">
                                <output><?= $current_progress ?>%</output>
                                <small class="text-muted d-block">Previous week progress: <?= $min_progress ?>% | Must be >= <?= $min_progress ?>%</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-check-circle me-1 text-success"></i>Work Completed This Week</label>
                                <textarea class="report-textarea" name="work_completed" rows="4" placeholder="Describe what has been accomplished this week..."><?= htmlspecialchars($existing['work_completed'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-tasks me-1 text-info"></i>Planned Work for Next Week</label>
                                <textarea class="report-textarea" name="work_planned" rows="4" placeholder="Describe what will be done next week..."><?= htmlspecialchars($existing['work_planned'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-image me-1 text-secondary"></i>Upload Images (Optional)</label>
                                <div id="uploadContainer_<?= $week['week_number'] ?>" class="upload-section">
                                    <label class="upload-area" id="uploadArea_<?= $week['week_number'] ?>">
                                        <div>
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                            <strong>Click to upload or drag & drop</strong>
                                            <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP (Max 10MB)</p>
                                        </div>
                                        <input type="file" id="fileInput_<?= $week['week_number'] ?>" name="report_images[]" multiple accept="image/*" style="display: none;" onchange="previewImages(<?= $week['week_number'] ?>, this.files)">
                                    </label>
                                    <div id="previewSection_<?= $week['week_number'] ?>" style="display: none;">
                                        <div class="preview-section">
                                            <p style="margin-bottom: 0.5rem; color: #6c757d; font-size: 0.9rem;">
                                                <strong>Preview:</strong>
                                            </p>
                                            <div id="imagePreviewList_<?= $week['week_number'] ?>" class="image-preview"></div>
                                            <div class="text-muted small">Images will be uploaded when you click Save Draft or Submit.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($existing['image_paths'])): 
                                    $saved_images = json_decode($existing['image_paths'], true);
                                    if ($saved_images): ?>
                                    <div class="image-preview mt-2">
                                        <?php foreach ($saved_images as $img): ?>
                                            <img src="../<?= $img ?>" alt="Progress Image" onclick="window.open('../<?= $img ?>', '_blank')">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; endif; ?>
                            </div>
                            
                            <div class="mb-3 border rounded p-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="request_extra_fee" id="request_extra_fee_<?= $week['week_number'] ?>" value="1" <?= ($existing && $existing['request_extra_fee']) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="request_extra_fee_<?= $week['week_number'] ?>">
                                        <i class="fas fa-dollar-sign me-1 text-warning"></i>Request Additional Construction Fee
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <?php if (!$existing || $existing['status'] != 'submitted'): ?>
                                    <button type="submit" name="save_report" class="btn btn-secondary" onclick="setActionTypeAndSubmit(<?= $week['week_number'] ?>, 'save')">
                                        <i class="fas fa-save me-1"></i>Save Draft
                                    </button>
                                    <button type="submit" name="save_report" class="btn btn-primary" onclick="return confirmSubmitAndSetAction(<?= $week['week_number'] ?>)">
                                        <i class="fas fa-paper-plane me-1"></i>Submit to Client & Designer
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                        
                        <?php if ($existing && (!empty($existing['client_feedback']) || !empty($existing['designer_feedback']))): ?>
                            <div class="mt-3">
                                <hr>
                                <h6><i class="fas fa-comments me-2"></i>Feedback Received</h6>
                                
                                <?php if (!empty($existing['client_feedback'])): ?>
                                    <div class="feedback-box client-feedback">
                                        <strong><i class="fas fa-user me-1"></i>Client Feedback:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($existing['client_feedback'])) ?></p>
                                        <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['client_feedback_at'])) ?></small>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($existing['designer_feedback'])): ?>
                                    <div class="feedback-box designer-feedback">
                                        <strong><i class="fas fa-user-tie me-1"></i>Designer Feedback:</strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($existing['designer_feedback'])) ?></p>
                                        <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['designer_feedback_at'])) ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($is_locked): ?>
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-percent me-1 text-primary"></i>Progress</label>
                            <div class="progress-bar-custom">
                                <div class="progress-fill bg-primary" style="width: <?= $existing['progress_percentage'] ?>%;"></div>
                            </div>
                            <p class="mt-2"><?= $existing['progress_percentage'] ?>% Complete</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-check-circle me-1 text-success"></i>Work Completed</label>
                            <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($existing['work_completed'] ?? '')) ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="report-label"><i class="fas fa-tasks me-1 text-info"></i>Planned Work</label>
                            <p class="border rounded p-2 bg-light"><?= nl2br(htmlspecialchars($existing['work_planned'] ?? '')) ?></p>
                        </div>
                        
                        <?php if (!empty($existing['image_paths'])): 
                            $view_images = json_decode($existing['image_paths'], true);
                            if ($view_images): ?>
                            <div class="mb-3">
                                <label class="report-label"><i class="fas fa-image me-1"></i>Images</label>
                                <div class="image-preview">
                                    <?php foreach ($view_images as $img): ?>
                                        <img src="../<?= $img ?>" alt="Progress Image" style="cursor: pointer;" onclick="window.open('../<?= $img ?>', '_blank')">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; endif; ?>
                        
                        <?php if ($existing['request_extra_fee']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-dollar-sign me-2"></i>
                                <strong>Additional Fee Requested</strong> - Client will be notified.
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <hr>
                            <h6><i class="fas fa-comments me-2"></i>Feedback & Responses</h6>
                            
                            <?php if (!empty($existing['client_feedback'])): ?>
                                <div class="feedback-box client-feedback">
                                    <strong><i class="fas fa-user me-1"></i>Client Feedback:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['client_feedback'])) ?></p>
                                    <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['client_feedback_at'])) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($existing['designer_feedback'])): ?>
                                <div class="feedback-box designer-feedback">
                                    <strong><i class="fas fa-user-tie me-1"></i>Designer Feedback:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['designer_feedback'])) ?></p>
                                    <small class="text-muted">Submitted: <?= date('M d, Y H:i', strtotime($existing['designer_feedback_at'])) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($existing['supplier_response'])): ?>
                                <div class="feedback-box supplier-response">
                                    <strong><i class="fas fa-building me-1"></i>Your Response:</strong>
                                    <p class="mb-1"><?= nl2br(htmlspecialchars($existing['supplier_response'])) ?></p>
                                    <small class="text-muted">Responded: <?= date('M d, Y H:i', strtotime($existing['supplier_response_at'])) ?></small>
                                </div>
                            <?php else: ?>
                                <?php if (!empty($existing['client_feedback']) || !empty($existing['designer_feedback'])): ?>
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="week_number" value="<?= $week['week_number'] ?>">
                                        <div class="mb-2">
                                            <label class="report-label"><i class="fas fa-reply me-1"></i>Your Response to Feedback</label>
                                            <textarea class="report-textarea" name="supplier_response" rows="3" placeholder="Respond to client/designer feedback..."></textarea>
                                        </div>
                                        <button type="submit" name="respond_feedback" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane me-1"></i>Send Response
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>This report has been submitted and is no longer editable.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock me-2"></i>This report cannot be edited because the project is completed or under inspection.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        
        <!-- Action Buttons -->
        <?php if ( $can_request_extension): ?>
        <div class="card mt-4">
            <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="fas fa-tools me-2"></i>Actions</h5></div>
            <div class="card-body">
                <div class="row">
                    <?php if ($can_request_extension): ?>
                    <div class="col-md-6 mb-3">
                        <button type="button" class="btn btn-warning btn-lg w-100" data-bs-toggle="modal" data-bs-target="#extensionModal"><i class="fas fa-clock me-2"></i>Request Extension</button>
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
                        <div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Request Construction Extension</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <input type="hidden" name="request_extension" value="1">
                            <div class="mb-3"><label class="form-label">Current End Date</label><input type="text" class="form-control" value="<?= $end_date ?>" readonly></div>
                            <div class="mb-3"><label class="form-label">New End Date <span class="text-danger">*</span></label><input type="date" class="form-control" name="new_end_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>"><small class="text-muted">Select new completion date</small></div>
                            <div class="mb-3"><label class="form-label">Reason for Extension <span class="text-danger">*</span></label><textarea class="form-control" name="extension_reason" rows="3" required placeholder="Please explain why extension is needed..."></textarea></div>
                            <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>Client will be notified and must approve this extension.</div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Send Extension Request</button></div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Order Details Modal -->
        <div class="modal fade" id="orderDetailsModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Order Details - #<?= $order_id ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Order ID</div><div class="detail-value">#<?= htmlspecialchars($order_info['orderid'] ?? 'N/A') ?></div></div></div>
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Order Status</div><div class="detail-value"><span class="badge bg-<?= $is_complete ? 'success' : ($is_waiting_inspection ? 'info' : 'primary') ?>"><?= htmlspecialchars($order_info['ostatus'] ?? 'N/A') ?></span></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Client Name</div><div class="detail-value"><?= htmlspecialchars($order_info['client_name'] ?? 'N/A') ?></div></div></div>
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Designer</div><div class="detail-value"><?= htmlspecialchars($designer_name) ?></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Order Date</div><div class="detail-value"><?= date('Y-m-d H:i', strtotime($order_info['odate'] ?? $today)) ?></div></div></div>
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Design Finish Date</div><div class="detail-value"><?= $design_finish_date ? date('Y-m-d', strtotime($design_finish_date)) : 'Not Set' ?></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Construction Start Date</div><div class="detail-value"><?= $start_date ? date('Y-m-d', strtotime($start_date)) : 'Not Set' ?></div></div></div>
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Construction End Date</div><div class="detail-value"><?php if ($pending_extension && $pending_extension['status'] == 'pending'): ?><span class="text-warning"><?= date('Y-m-d', strtotime($pending_extension['requested_end_date'])) ?></span><br><small>(Pending Approval)</small><?php else: ?><?= $end_date ? date('Y-m-d', strtotime($end_date)) : 'Not Set' ?><?php endif; ?></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Order Finish Date</div><div class="detail-value"><?= $order_finish_date ? date('Y-m-d', strtotime($order_finish_date)) : 'Not Set' ?></div></div></div>
                            <div class="col-md-6"><div class="order-detail"><div class="detail-label">Budget</div><div class="detail-value">HK$<?= number_format($order_info['total_amount_due'] ?? 0, 2) ?></div></div></div>
                        </div>
                        <?php if (!empty($order_info['Requirements'])): ?>
                        <div class="order-detail"><div class="detail-label">Requirements</div><div class="detail-value"><?= htmlspecialchars($order_info['Requirements']) ?></div></div>
                        <?php endif; ?>
                        <?php if ($is_waiting_inspection): ?>
                        <div class="alert alert-info mt-3"><i class="fas fa-info-circle me-2"></i><strong>Status: Waiting for Inspection</strong><br>The client has been notified to inspect the completed construction.</div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
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
            <div class="card-header bg-secondary text-white"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Extension History</h5></div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-light"><tr><th>Version</th><th>Requested End Date</th><th>Reason</th><th>Status</th><th>Requested Date</th></tr></thead>
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
        // Store selected images for each week
        let selectedImages = {};
        
        // Function to set action type and submit form
        function setActionTypeAndSubmit(weekNumber, action) {
            document.getElementById('action_type_' + weekNumber).value = action;
            const form = document.getElementById('reportForm_' + weekNumber);
            if (form) {
                form.submit();
            }
            return true;
        }
        
        // Function to confirm submission and set action type to submit
        function confirmSubmitAndSetAction(weekNumber) {
            if (confirm('Submit this weekly report to client and designer? Once submitted, you cannot edit it again.')) {
                document.getElementById('action_type_' + weekNumber).value = 'submit';
                const form = document.getElementById('reportForm_' + weekNumber);
                if (form) {
                    form.submit();
                }
                return true;
            }
            return false;
        }
        
        function showOrderDetails() {
            new bootstrap.Modal(document.getElementById('orderDetailsModal')).show();
        }
        
        function toggleWeek(weekNumber) {
            const body = document.getElementById('week-body-' + weekNumber);
            if (body.style.display === 'none') {
                body.style.display = 'block';
            } else {
                body.style.display = 'none';
            }
        }
        
        function showAllWeeks() {
            const extraWeeks = document.querySelectorAll('.extra-week');
            extraWeeks.forEach(week => {
                week.style.display = 'block';
            });
            const button = document.querySelector('.btn-add-week');
            if (button) button.style.display = 'none';
        }
        
        // Image preview function
        function previewImages(weekNumber, files) {
            if (!files || files.length === 0) return;
            
            if (!selectedImages[weekNumber]) {
                selectedImages[weekNumber] = [];
            }
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please upload valid image files (JPG, PNG, GIF, WebP)');
                    continue;
                }
                
                if (file.size > 10 * 1024 * 1024) {
                    alert('File ' + file.name + ' exceeds 10MB limit');
                    continue;
                }
                
                selectedImages[weekNumber].push(file);
            }
            
            // Update preview
            const previewContainer = document.getElementById('imagePreviewList_' + weekNumber);
            const previewSection = document.getElementById('previewSection_' + weekNumber);
            const uploadArea = document.getElementById('uploadArea_' + weekNumber);
            
            if (previewContainer) {
                previewContainer.innerHTML = '';
                selectedImages[weekNumber].forEach((file, idx) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const imgDiv = document.createElement('div');
                        imgDiv.className = 'preview-image-item';
                        imgDiv.innerHTML = `
                            <img src="${e.target.result}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                            <button type="button" class="btn btn-danger btn-sm" style="position: absolute; top: -8px; right: -8px; border-radius: 50%; padding: 2px 6px;" onclick="removeImage(${weekNumber}, ${idx})">&times;</button>
                        `;
                        previewContainer.appendChild(imgDiv);
                    };
                    reader.readAsDataURL(file);
                });
            }
            
            if (previewSection) previewSection.style.display = 'block';
            if (uploadArea) uploadArea.style.display = 'none';
        }
        
        function removeImage(weekNumber, index) {
            if (selectedImages[weekNumber]) {
                selectedImages[weekNumber].splice(index, 1);
                if (selectedImages[weekNumber].length === 0) {
                    delete selectedImages[weekNumber];
                    const previewSection = document.getElementById('previewSection_' + weekNumber);
                    const uploadArea = document.getElementById('uploadArea_' + weekNumber);
                    if (previewSection) previewSection.style.display = 'none';
                    if (uploadArea) uploadArea.style.display = 'block';
                } else {
                    const previewContainer = document.getElementById('imagePreviewList_' + weekNumber);
                    if (previewContainer) {
                        previewContainer.innerHTML = '';
                        selectedImages[weekNumber].forEach((file, idx) => {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                const imgDiv = document.createElement('div');
                                imgDiv.className = 'preview-image-item';
                                imgDiv.innerHTML = `
                                    <img src="${e.target.result}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                                    <button type="button" class="btn btn-danger btn-sm" style="position: absolute; top: -8px; right: -8px; border-radius: 50%; padding: 2px 6px;" onclick="removeImage(${weekNumber}, ${idx})">&times;</button>
                                `;
                                previewContainer.appendChild(imgDiv);
                            };
                            reader.readAsDataURL(file);
                        });
                    }
                }
            }
        }
        
        // Initialize drag and drop upload
        document.querySelectorAll('[id^="uploadArea_"]').forEach(area => {
            area.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragover');
            });
        
            area.addEventListener('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
            });
        
            area.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
                
                const weekNumber = this.id.replace('uploadArea_', '');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    previewImages(weekNumber, files);
                    const fileInput = document.getElementById('fileInput_' + weekNumber);
                    if (fileInput) {
                        const dt = new DataTransfer();
                        if (selectedImages[weekNumber]) {
                            selectedImages[weekNumber].forEach(f => dt.items.add(f));
                        }
                        for (let i = 0; i < files.length; i++) {
                            dt.items.add(files[i]);
                        }
                        fileInput.files = dt.files;
                    }
                }
            });
        
            area.addEventListener('click', function (e) {
                if (e.target === this) {
                    e.preventDefault();
                    const weekNumber = this.id.replace('uploadArea_', '');
                    document.getElementById('fileInput_' + weekNumber).click();
                }
            });
        });
        
        // Initialize progress range displays
        document.querySelectorAll('input[type="range"]').forEach(range => {
            range.addEventListener('input', function() {
                this.nextElementSibling.value = this.value;
            });
        });
    </script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>