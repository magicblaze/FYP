<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// 获取订单ID
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($order_id <= 0) {
    header('Location: Manager_MyOrder_AwaitingConfirm.php');
    exit;
}

// 获取订单详情
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.Floor_Plan, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone,
               d.designid, d.design as design_image, d.price as design_price, d.tag as design_tag,
               s.FinishDate,
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

// 处理表单提交
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_approval'])) {
    $status = mysqli_real_escape_string($mysqli, $_POST['status']);
    $manager_reply = mysqli_real_escape_string($mysqli, $_POST['manager_reply']);
    $additional_notes = mysqli_real_escape_string($mysqli, $_POST['additional_notes']);
    $estimated_completion = mysqli_real_escape_string($mysqli, $_POST['estimated_completion']);
    
    // 开始事务
    mysqli_begin_transaction($mysqli);
    
    try {
        // 1. 更新订单状态
        $update_order_sql = "UPDATE `Order` SET ostatus = '$status' WHERE orderid = $order_id";
        if(!mysqli_query($mysqli, $update_order_sql)) {
            throw new Exception("Failed to update order status: " . mysqli_error($mysqli));
        }
        
        // 2. 如果有回复消息，更新到Requirements中
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
        
        // 3. 更新或创建Schedule记录
        $manager_id = $_SESSION['user_id'] ?? 1;
        
        if(!empty($estimated_completion)) {
            $check_schedule_sql = "SELECT scheduleid FROM `Schedule` WHERE orderid = $order_id";
            $schedule_result = mysqli_query($mysqli, $check_schedule_sql);
            
            if(mysqli_num_rows($schedule_result) > 0) {
                $update_schedule_sql = "UPDATE `Schedule` SET FinishDate = '$estimated_completion', managerid = $manager_id WHERE orderid = $order_id";
            } else {
                $update_schedule_sql = "INSERT INTO `Schedule` (managerid, FinishDate, orderid) VALUES ($manager_id, '$estimated_completion', $order_id)";
            }
            
            if(!mysqli_query($mysqli, $update_schedule_sql)) {
                throw new Exception("Failed to update schedule: " . mysqli_error($mysqli));
            }
        }
        
        // 提交事务
        mysqli_commit($mysqli);
        
        // 发送邮件给客户
        $email_sent = sendApprovalEmail($order, $status, $manager_reply, $additional_notes, $estimated_completion);
        
        // 重定向回主页面
        $redirect_url = "Manager_MyOrder_AwaitingConfirm.php?msg=approved&id=" . $order_id;
        if($email_sent) {
            $redirect_url .= "&email=sent";
        } else {
            $redirect_url .= "&email=failed";
        }
        
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        // 回滚事务
        mysqli_rollback($mysqli);
        $error_message = $e->getMessage();
    }
}

// 发送邮件函数
function sendApprovalEmail($order, $status, $manager_reply, $additional_notes, $estimated_completion) {
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
        $message .= "Estimated Completion Date: " . date('Y-m-d H:i', strtotime($estimated_completion)) . "\n";
    }
    
    $message .= "\nNEXT STEPS\n";
    if($status == 'Designing') {
        $message .= "Your order has been approved and is now in the designing phase. Our design team will contact you shortly.\n";
    } elseif($status == 'Cancelled') {
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
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Approve Order #<?php echo $order_id; ?> - HappyDesign</title>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.html">Introduct</a>
                <a href="Manager_MyOrder.html">MyOrder</a>
                <a href="Manager_Massage.html">Massage</a>
                <a href="Manager_Schedule.html">Schedule</a>
            </div>
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Approve Order #<?php echo htmlspecialchars($order['orderid']); ?></h1>
        
        <?php if(isset($error_message)): ?>
            <div class="alert alert-error">
                <div>
                    <strong>Error: <?php echo htmlspecialchars($error_message); ?></strong>
                    <p class="mb-0">Please try again or contact support if the issue persists.</p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- 订单信息卡片 -->
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Order Details</h2>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <tr>
                            <th width="20%">Order ID</th>
                            <td width="30%"><?php echo htmlspecialchars($order['orderid']); ?></td>
                            <th width="20%">Order Date</th>
                            <td width="30%"><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
                        </tr>
                        <tr>
                            <th>Client</th>
                            <td colspan="3">
                                <div class="d-flex flex-column">
                                    <strong><?php echo htmlspecialchars($order['client_name']); ?></strong>
                                    <span>Email: <?php echo htmlspecialchars($order['client_email']); ?></span>
                                    <?php if(!empty($order['client_phone'])): ?>
                                        <span>Phone: <?php echo htmlspecialchars($order['client_phone']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>Budget</th>
                            <td><strong class="text-success">$<?php echo number_format($order['budget'], 2); ?></strong></td>
                            <th>Current Status</th>
                            <td>
                                <span class="status-badge status-pending">
                                    <?php echo htmlspecialchars($order['ostatus']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Design</th>
                            <td colspan="3">
                                <div class="d-flex flex-column">
                                    <span>Design #<?php echo htmlspecialchars($order['designid']); ?></span>
                                    <span>Price: $<?php echo number_format($order['design_price'], 2); ?></span>
                                    <?php if(!empty($order['design_tag'])): ?>
                                        <span>Tags: <?php echo htmlspecialchars(substr($order['design_tag'], 0, 100)); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>Requirements</th>
                            <td colspan="3">
                                <div class="requirements-box">
                                    <?php echo nl2br(htmlspecialchars($order['Requirements'] ?? 'No requirements specified')); ?>
                                </div>
                            </td>
                        </tr>
                        <?php if(!empty($order['Floor_Plan'])): ?>
                        <tr>
                            <th>Floor Plan</th>
                            <td colspan="3"><?php echo htmlspecialchars($order['Floor_Plan']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- 审批表单卡片 -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Approval Form</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="form-container">
                    <div class="form-group">
                        <label for="status" class="form-label">Update Status</label>
                        <select name="status" id="status" class="form-control" required onchange="updateStatusDescription()">
                            <option value="">Select Status</option>
                            <option value="Designing">Designing - Approve and proceed to design phase</option>
                            <option value="Cancelled">Cancelled - Reject/Cancel this order</option>
                        </select>
                        <small id="status-description" class="text-muted"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="manager_reply" class="form-label">Manager's Response</label>
                        <textarea name="manager_reply" id="manager_reply" class="form-control" rows="4" required 
                                  placeholder="Enter your response to the client regarding this order..."></textarea>
                        <small class="text-muted">This response will be sent to the client via email and added to order notes.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="additional_notes" class="form-label">Additional Notes (Optional)</label>
                        <textarea name="additional_notes" id="additional_notes" class="form-control" rows="3"
                                  placeholder="Any additional notes or instructions..."></textarea>
                        <small class="text-muted">These notes will be included in the email to the client.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="estimated_completion" class="form-label">Estimated Completion Date (Optional)</label>
                        <input type="datetime-local" name="estimated_completion" id="estimated_completion" class="form-control"
                               value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                        <small class="text-muted">If approved, when do you estimate this order will be completed?</small>
                    </div>
                    
                    <div class="alert alert-warning mt-4">
                        <div>
                            <strong>Important Notes:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Upon submission, the order status will be updated immediately</li>
                                <li>An email notification will be sent to: <strong><?php echo htmlspecialchars($order['client_email']); ?></strong></li>
                                <li>Your response will be added to the order requirements as a note</li>
                                <li>This action cannot be undone</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-between mt-4">
                        <div class="btn-group">
                            <button type="submit" name="submit_approval" class="btn btn-success btn-lg">
                                Submit Approval & Send Email
                            </button>
                            <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function updateStatusDescription() {
        const statusSelect = document.getElementById('status');
        const description = document.getElementById('status-description');
        
        if(statusSelect.value === 'Designing') {
            description.innerHTML = 'Order will proceed to design phase. Client will be notified.';
            description.className = 'text-success';
        } else if(statusSelect.value === 'Cancelled') {
            description.innerHTML = 'Order will be cancelled. Client will be notified.';
            description.className = 'text-danger';
        } else {
            description.innerHTML = '';
            description.className = 'text-muted';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        updateStatusDescription();
        
        // 自动填充回复示例
        const replyTextarea = document.getElementById('manager_reply');
        if(replyTextarea) {
            replyTextarea.addEventListener('focus', function() {
                if(this.value === '') {
                    this.value = "Dear " + "<?php echo addslashes($order['client_name']); ?>,\n\n" +
                                "Thank you for your order. We have reviewed your request and ";
                }
            });
        }
        
        // 表单验证
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
            
            // 确认提交
            if(status === 'Cancelled') {
                if(!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                    e.preventDefault();
                }
            }
        });
        
        // 快捷键支持
        document.addEventListener('keydown', function(e) {
            // Ctrl+Enter 提交表单
            if(e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                form.submit();
            }
            
            // Esc键取消
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder_AwaitingConfirm.php';
            }
        });
    });
    </script>
    
    <style>
        .requirements-box {
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        textarea.form-control {
            min-height: 100px;
        }
        
        @media (max-width: 768px) {
            .table th, .table td {
                padding: 10px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                width: 100%;
                margin: 5px 0;
            }
        }
    </style>
</body>
</html>