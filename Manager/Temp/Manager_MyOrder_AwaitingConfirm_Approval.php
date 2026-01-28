<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Get order ID
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($order_id <= 0) {
    header('Location: Manager_MyOrder_AwaitingConfirm.php');
    exit;
}

// Check if order belongs to current manager - 修复：使用设计师关联逻辑
$check_manager_sql = "SELECT COUNT(*) as count 
                      FROM `Order` o
                      JOIN `Design` d ON o.designid = d.designid
                      JOIN `Designer` des ON d.designerid = des.designerid
                      WHERE o.orderid = ? AND des.managerid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $user_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$manager_check = mysqli_fetch_assoc($check_result);

if ($manager_check['count'] == 0) {
    die("You don't have permission to approve this order.");
}

// Get order details - 修改SQL查询，从Client表获取Floor_Plan字段
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone, c.budget, c.Floor_Plan,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate,
               m.mname as manager_name, m.memail as manager_email
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        LEFT JOIN `Manager` m ON s.managerid = m.managerid
        WHERE o.orderid = $order_id";
        
$result = mysqli_query($mysqli, $sql);
$order = mysqli_fetch_assoc($result);

if(!$order) {
    header('Location: Manager_MyOrder_AwaitingConfirm.php?msg=notfound');
    exit;
}

// Handle form submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_approval'])) {
    $status = mysqli_real_escape_string($mysqli, $_POST['status']);
    $manager_reply = mysqli_real_escape_string($mysqli, $_POST['manager_reply']);
    $additional_notes = mysqli_real_escape_string($mysqli, $_POST['additional_notes']);
    $estimated_completion = mysqli_real_escape_string($mysqli, $_POST['estimated_completion']);
    $design_finish_date = mysqli_real_escape_string($mysqli, $_POST['design_finish_date']);
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // 1. Update order status
        $update_order_sql = "UPDATE `Order` SET ostatus = '$status' WHERE orderid = $order_id";
        if(!mysqli_query($mysqli, $update_order_sql)) {
            throw new Exception("Failed to update order status: " . mysqli_error($mysqli));
        }
        
        // 2. If there's a reply message, update to Requirements
        if(!empty($manager_reply)) {
            $current_time = date('Y-m-d H:i:s');
            $reply_note = "\n\n--- Manager Response (" . $current_time . ") ---\n" . 
                         "Status: " . $status . "\n" .
                         "Reply: " . $manager_reply . "\n" .
                         (!empty($additional_notes) ? "Additional Notes: " . $additional_notes . "\n" : "");
            
            $update_reply_sql = "UPDATE `Order` SET Requirements = CONCAT(IFNULL(Requirements, ''), ?) WHERE orderid = ?";
            $stmt = mysqli_prepare($mysqli, $update_reply_sql);
            mysqli_stmt_bind_param($stmt, "si", $reply_note, $order_id);
            if(!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to update order requirements: " . mysqli_error($mysqli));
            }
        }

        // 3. Update or create Schedule record
        if(!empty($estimated_completion) || !empty($design_finish_date)) {
            $check_schedule_sql = "SELECT scheduleid FROM `Schedule` WHERE orderid = $order_id";
            $schedule_result = mysqli_query($mysqli, $check_schedule_sql);
            
            if(mysqli_num_rows($schedule_result) > 0) {
                $update_schedule_sql = "UPDATE `Schedule` SET 
                                        OrderFinishDate = " . (!empty($estimated_completion) ? "'$estimated_completion'" : "NULL") . ",
                                        DesignFinishDate = " . (!empty($design_finish_date) ? "'$design_finish_date'" : "NULL") . ",
                                        managerid = $user_id 
                                        WHERE orderid = $order_id";
            } else {
                $update_schedule_sql = "INSERT INTO `Schedule` (managerid, OrderFinishDate, DesignFinishDate, orderid) 
                                       VALUES ($user_id, " . 
                                       (!empty($estimated_completion) ? "'$estimated_completion'" : "NULL") . ", " .
                                       (!empty($design_finish_date) ? "'$design_finish_date'" : "NULL") . ", 
                                       $order_id)";
            }
            
            if(!mysqli_query($mysqli, $update_schedule_sql)) {
                throw new Exception("Failed to update schedule: " . mysqli_error($mysqli));
            }
        }
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        // Send email to client
        $email_sent = sendApprovalEmail($order, $status, $manager_reply, $additional_notes, $estimated_completion, $design_finish_date);
        
        // Redirect back to main page
        $redirect_url = "Manager_MyOrder_AwaitingConfirm.php?msg=approved&id=" . $order_id;
        if($email_sent) {
            $redirect_url .= "&email=sent";
        } else {
            $redirect_url .= "&email=failed";
        }
        
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($mysqli);
        $error_message = $e->getMessage();
    }
}

// Send approval email function
function sendApprovalEmail($order, $status, $manager_reply, $additional_notes, $estimated_completion, $design_finish_date) {
    $to = $order['client_email'];
    $subject = "Order #" . $order['orderid'] . " - Status Update";

    $message = "Dear " . $order['client_name'] . ",\n\n";
    $message .= "Your order #" . $order['orderid'] . " has been processed.\n\n";
    
    $message .= "ORDER DETAILS\n";
    $message .= "Order ID: " . $order['orderid'] . "\n";
    $message .= "Order Date: " . date('Y-m-d H:i', strtotime($order['odate'])) . "\n";
    $message .= "Budget: $" . number_format($order['budget'], 2) . "\n";
    $message .= "Design ID: " . $order['designid'] . "\n";
    $message .= "Design Price: $" . number_format($order['design_price'], 2) . "\n";
    $message .= "Requirements: " . substr($order['Requirements'], 0, 200) . "\n\n";
    
    $message .= "PROCESSING RESULT\n";
    $message .= "New Status: " . $status . "\n";
    if(!empty($manager_reply)) {
        $message .= "Manager's Response: " . $manager_reply . "\n";
    }
    if(!empty($additional_notes)) {
        $message .= "Additional Notes: " . $additional_notes . "\n";
    }
    if(!empty($estimated_completion)) {
        $message .= "Estimated Order Completion Date: " . date('Y-m-d H:i', strtotime($estimated_completion)) . "\n";
    }
    if(!empty($design_finish_date)) {
        $message .= "Estimated Design Completion Date: " . date('Y-m-d H:i', strtotime($design_finish_date)) . "\n";
    }
    
    $message .= "\nNEXT STEPS\n";
    if($status == 'designing') {
        $message .= "Your order has been approved and is now in the designing phase. Our design team will contact you shortly.\n";
    } elseif($status == 'reject') {
        $message .= "Your order has been cancelled. If you have any questions, please contact our customer service.\n";
    }
    
    $message .= "\nThank you for choosing our service!\n\n";
    $message .= "Best regards,\n";
    $message .= "The Management Team\n";
    
    $headers = "From: noreply@happydesign.com\r\n";
    $headers .= "Reply-To: noreply@happydesign.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Approve Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>

<body>
    <!-- Header Navigation -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-check me-2"></i>Approve Order #<?php echo htmlspecialchars($order['orderid']); ?>
        </div>

        <?php if(isset($error_message)): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Order Information Card -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-info-circle me-2"></i>Order Details
                </h5>
                
                <div class="table-container">
                    <table class="table">
                        <tbody>
                            <tr>
                                <td style="font-weight: 600; width: 20%;">Order ID</td>
                                <td style="width: 30%;"><?php echo htmlspecialchars($order['orderid']); ?></td>
                                <td style="font-weight: 600; width: 20%;">Order Date</td>
                                <td style="width: 30%;"><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;">Client Name</td>
                                <td colspan="3"><?php echo htmlspecialchars($order['client_name']); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;">Email</td>
                                <td><?php echo htmlspecialchars($order['client_email']); ?></td>
                                <td style="font-weight: 600;">Phone</td>
                                <td><?php echo htmlspecialchars($order['client_phone'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;">Budget</td>
                                <td><span style="color: #27ae60; font-weight: 600;">$<?php echo number_format($order['budget'], 2); ?></span></td>
                                <td style="font-weight: 600;">Current Status</td>
                                <td><span class="status-badge status-pending"><?php echo htmlspecialchars($order['ostatus']); ?></span></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;">Design ID</td>
                                <td><?php echo htmlspecialchars($order['designid']); ?></td>
                                <td style="font-weight: 600;">Design Price</td>
                                <td>$<?php echo number_format($order['design_price'], 2); ?></td>
                            </tr>
                            <?php if(!empty($order['Floor_Plan'])): ?>
                            <tr>
                                <td style="font-weight: 600;">Floor Plan</td>
                                <td colspan="3">
                                    <a href="<?php echo htmlspecialchars($order['Floor_Plan']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i>View Floor Plan
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="font-weight: 600; vertical-align: top;">Requirements</td>
                                <td colspan="3">
                                    <div style="max-height: 150px; overflow-y: auto; background-color: #f8f9fa; padding: 10px; border-radius: 6px; border: 1px solid #dee2e6; white-space: pre-wrap; word-wrap: break-word;">
                                        <?php echo nl2br(htmlspecialchars($order['Requirements'] ?? 'No requirements specified')); ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if(!empty($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00 00:00:00'): ?>
                            <tr>
                                <td style="font-weight: 600;">Current Order Finish Date</td>
                                <td><?php echo date('Y-m-d H:i', strtotime($order['OrderFinishDate'])); ?></td>
                                <td style="font-weight: 600;">Current Design Finish Date</td>
                                <td><?php echo isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00 00:00:00' ? date('Y-m-d H:i', strtotime($order['DesignFinishDate'])) : '<span class="text-muted">Not scheduled</span>'; ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Approval Form Card -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-edit me-2"></i>Approval Form
                </h5>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="status" class="form-label">
                            <i class="fas fa-tasks me-2"></i>Update Status
                        </label>
                        <select name="status" id="status" class="form-control" required onchange="updateStatusDescription()">
                            <option value="">Select Status</option>
                            <option value="designing">Designing - Approve and proceed to design phase</option>
                            <option value="reject">Cancelled - Reject/Cancel this order</option>
                        </select>
                        <small id="status-description" class="text-muted"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="manager_reply" class="form-label">
                            <i class="fas fa-comment me-2"></i>Manager's Response
                        </label>
                        <textarea name="manager_reply" id="manager_reply" class="form-control" rows="4" required 
                                  placeholder="Enter your response to the client regarding this order..."></textarea>
                        <small class="text-muted">This response will be sent to the client via email and added to order notes.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="additional_notes" class="form-label">
                            <i class="fas fa-sticky-note me-2"></i>Additional Notes (Optional)
                        </label>
                        <textarea name="additional_notes" id="additional_notes" class="form-control" rows="3"
                                  placeholder="Any additional notes or instructions..."></textarea>
                        <small class="text-muted">These notes will be included in the email to the client.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="estimated_completion" class="form-label">
                            <i class="fas fa-calendar-check me-2"></i>Estimated Order Completion Date (Optional)
                        </label>
                        <input type="datetime-local" name="estimated_completion" id="estimated_completion" class="form-control"
                               value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                        <small class="text-muted">If approved, when do you estimate this order will be completed?</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="design_finish_date" class="form-label">
                            <i class="fas fa-pencil-alt me-2"></i>Estimated Design Completion Date (Optional)
                        </label>
                        <input type="datetime-local" name="design_finish_date" id="design_finish_date" class="form-control"
                               value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>">
                        <small class="text-muted">If approved, when do you estimate the design will be completed?</small>
                    </div>
                    
                    <div class="alert alert-warning mt-4" role="alert">
                        <h6 class="mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>Important Notes:
                        </h6>
                        <ul class="mb-0">
                            <li>Upon submission, the order status will be updated immediately</li>
                            <li>An email notification will be sent to: <strong><?php echo htmlspecialchars($order['client_email']); ?></strong></li>
                            <li>Your response will be added to the order requirements as a note</li>
                            <li>This action cannot be undone</li>
                        </ul>
                    </div>
                    
                    <div class="btn-group mt-4">
                        <button type="submit" name="submit_approval" class="btn btn-success">
                            <i class="fas fa-paper-plane me-2"></i>Submit Approval & Send Email
                        </button>
                        <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateStatusDescription() {
        const statusSelect = document.getElementById('status');
        const description = document.getElementById('status-description');
        
        if(statusSelect.value === 'designing') {
            description.innerHTML = '<i class="fas fa-check-circle me-1" style="color: #27ae60;"></i>Order will proceed to design phase. Client will be notified.';
            description.className = 'text-success mt-2 d-block';
        } else if(statusSelect.value === 'reject') {
            description.innerHTML = '<i class="fas fa-times-circle me-1" style="color: #e74c3c;"></i>Order will be cancelled. Client will be notified.';
            description.className = 'text-danger mt-2 d-block';
        } else {
            description.innerHTML = '';
            description.className = 'text-muted';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        updateStatusDescription();
        
        // Form validation
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            const status = document.getElementById('status').value;
            const reply = document.getElementById('manager_reply').value.trim();
            
            if(!status) {
                alert('Please select a status for this order.');
                e.preventDefault();
                return;
            }
            
            if(!reply) {
                alert('Please provide a response to the client.');
                e.preventDefault();
                return;
            }
            
            // Confirm submission
            if(status === 'Cancelled') {
                if(!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                    e.preventDefault();
                }
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+Enter to submit form
            if(e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                form.submit();
            }
            
            // Esc key to cancel
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder_AwaitingConfirm.php';
            }
        });
    });
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>