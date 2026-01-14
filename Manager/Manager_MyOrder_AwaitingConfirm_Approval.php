<?php

// Include database connection file
require("config.php");

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
        
$result = mysqli_query($conn, $sql);
$order = mysqli_fetch_assoc($result);

if(!$order) {
    header('Location: Manager_MyOrder_AwaitingConfirm.php?msg=notfound');
    exit;
}

// 处理表单提交
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_approval'])) {
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $manager_reply = mysqli_real_escape_string($conn, $_POST['manager_reply']);
    $additional_notes = mysqli_real_escape_string($conn, $_POST['additional_notes']);
    $estimated_completion = mysqli_real_escape_string($conn, $_POST['estimated_completion']);
    
    // 开始事务
    mysqli_begin_transaction($conn);
    
    try {
        // 1. 更新订单状态
        $update_order_sql = "UPDATE `Order` SET ostatus = '$status' WHERE orderid = $order_id";
        if(!mysqli_query($conn, $update_order_sql)) {
            throw new Exception("Failed to update order status: " . mysqli_error($conn));
        }
        
        // 2. 如果有回复消息，更新到Requirements中
        if(!empty($manager_reply)) {
            $current_time = date('Y-m-d H:i:s');
            $reply_note = "\n\n--- Manager Response (" . $current_time . ") ---\n" . 
                         "Status: " . $status . "\n" .
                         "Reply: " . $manager_reply . "\n" .
                         (!empty($additional_notes) ? "Additional Notes: " . $additional_notes . "\n" : "");
            
            $update_reply_sql = "UPDATE `Order` SET Requirements = CONCAT(IFNULL(Requirements, ''), ?) WHERE orderid = ?";
            $stmt = mysqli_prepare($conn, $update_reply_sql);
            mysqli_stmt_bind_param($stmt, "si", $reply_note, $order_id);
            if(!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to update order requirements: " . mysqli_error($conn));
            }
        }
        
        // 3. 更新或创建Schedule记录
        $manager_id = $_SESSION['user_id'] ?? 1; // 使用当前登录的管理员ID
        
        if(!empty($estimated_completion)) {
            $check_schedule_sql = "SELECT scheduleid FROM `Schedule` WHERE orderid = $order_id";
            $schedule_result = mysqli_query($conn, $check_schedule_sql);
            
            if(mysqli_num_rows($schedule_result) > 0) {
                $update_schedule_sql = "UPDATE `Schedule` SET FinishDate = '$estimated_completion', managerid = $manager_id WHERE orderid = $order_id";
            } else {
                $update_schedule_sql = "INSERT INTO `Schedule` (managerid, FinishDate, orderid) VALUES ($manager_id, '$estimated_completion', $order_id)";
            }
            
            if(!mysqli_query($conn, $update_schedule_sql)) {
                throw new Exception("Failed to update schedule: " . mysqli_error($conn));
            }
        }
        
        // 提交事务
        mysqli_commit($conn);
        
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
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// 发送邮件的函数
function sendApprovalEmail($order, $status, $manager_reply, $additional_notes, $estimated_completion) {
    $to = $order['client_email'];
    $subject = "Order #" . $order['orderid'] . " - Status Update";
    
    // 邮件内容
    $message = "Dear " . $order['client_name'] . ",\n\n";
    $message .= "Your order #" . $order['orderid'] . " has been processed.\n\n";
    
    $message .= "=== ORDER DETAILS ===\n";
    $message .= "Order ID: " . $order['orderid'] . "\n";
    $message .= "Order Date: " . date('Y-m-d H:i', strtotime($order['odate'])) . "\n";
    $message .= "Budget: $" . number_format($order['budget'], 2) . "\n";
    $message .= "Design ID: " . $order['designid'] . "\n";
    $message .= "Design Price: $" . number_format($order['design_price'], 2) . "\n";
    $message .= "Requirements: " . substr($order['Requirements'], 0, 200) . "\n\n";
    
    $message .= "=== PROCESSING RESULT ===\n";
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
    
    $message .= "\n=== NEXT STEPS ===\n";
    if($status == 'Designing') {
        $message .= "Your order has been approved and is now in the designing phase. Our design team will contact you shortly.\n";
    } elseif($status == 'Cancelled') {
        $message .= "Your order has been cancelled. If you have any questions, please contact our customer service.\n";
    }
    
    $message .= "\nThank you for choosing our service!\n\n";
    $message .= "Best regards,\n";
    $message .= "The Management Team\n";
    
    // 邮件头
    $headers = "From: your-gmail-address@gmail.com\r\n";
    $headers .= "Reply-To: your-gmail-address@gmail.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // 使用Gmail SMTP发送邮件（需要配置）
    // 这里使用mail()函数，但实际应用中建议使用PHPMailer或SwiftMailer
    return mail($to, $subject, $message, $headers);
}

// 或者使用更可靠的方法（使用PHPMailer）
function sendEmailWithPHPMailer($order, $status, $manager_reply, $additional_notes, $estimated_completion) {
    // 需要安装PHPMailer: composer require phpmailer/phpmailer
    /*
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    
    require 'vendor/autoload.php';
    
    $mail = new PHPMailer(true);
    
    try {
        // 服务器设置
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-gmail-address@gmail.com';
        $mail->Password   = 'your-app-password'; // 使用Gmail应用专用密码
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // 收件人
        $mail->setFrom('your-gmail-address@gmail.com', 'Management Team');
        $mail->addAddress($order['client_email'], $order['client_name']);
        
        // 内容
        $mail->isHTML(true);
        $mail->Subject = 'Order #' . $order['orderid'] . ' - Status Update';
        
        // HTML邮件内容
        $mail->Body = generateEmailHTML($order, $status, $manager_reply, $additional_notes, $estimated_completion);
        $mail->AltBody = generateEmailText($order, $status, $manager_reply, $additional_notes, $estimated_completion);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
    */
    return true; // 暂时返回true，实际使用时需要实现
}

// 生成HTML邮件内容
function generateEmailHTML($order, $status, $manager_reply, $additional_notes, $estimated_completion) {
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #f8f9fa; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .section { margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #007bff; }
            .status-approved { border-left-color: #28a745; }
            .status-cancelled { border-left-color: #dc3545; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Order Status Update</h1>
            </div>
            
            <div class="content">
                <p>Dear ' . htmlspecialchars($order['client_name']) . ',</p>
                <p>Your order <strong>#' . $order['orderid'] . '</strong> has been processed.</p>
                
                <div class="section">
                    <h3>Order Details</h3>
                    <p><strong>Order ID:</strong> ' . $order['orderid'] . '</p>
                    <p><strong>Order Date:</strong> ' . date('Y-m-d H:i', strtotime($order['odate'])) . '</p>
                    <p><strong>Budget:</strong> $' . number_format($order['budget'], 2) . '</p>
                    <p><strong>Design ID:</strong> ' . $order['designid'] . '</p>
                    <p><strong>Original Requirements:</strong><br>' . nl2br(htmlspecialchars(substr($order['Requirements'], 0, 300))) . '</p>
                </div>
                
                <div class="section ' . ($status == 'Designing' ? 'status-approved' : 'status-cancelled') . '">
                    <h3>Processing Result</h3>
                    <p><strong>New Status:</strong> ' . $status . '</p>';
    
    if(!empty($manager_reply)) {
        $html .= '<p><strong>Manager\'s Response:</strong><br>' . nl2br(htmlspecialchars($manager_reply)) . '</p>';
    }
    
    if(!empty($additional_notes)) {
        $html .= '<p><strong>Additional Notes:</strong><br>' . nl2br(htmlspecialchars($additional_notes)) . '</p>';
    }
    
    if(!empty($estimated_completion)) {
        $html .= '<p><strong>Estimated Completion Date:</strong> ' . date('Y-m-d H:i', strtotime($estimated_completion)) . '</p>';
    }
    
    $html .= '
                </div>
                
                <div class="section">
                    <h3>Next Steps</h3>';
    
    if($status == 'Designing') {
        $html .= '<p>✅ Your order has been approved and is now in the designing phase. Our design team will contact you shortly to discuss the details.</p>';
    } elseif($status == 'Cancelled') {
        $html .= '<p>❌ Your order has been cancelled. If you believe this is a mistake or have any questions, please contact our customer service team.</p>';
    }
    
    $html .= '
                </div>
                
                <p>Thank you for choosing our service!</p>
            </div>
            
            <div class="footer">
                <p><strong>Best regards,</strong><br>The Management Team</p>
                <p>This is an automated email. Please do not reply to this message.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

// 生成纯文本邮件内容
function generateEmailText($order, $status, $manager_reply, $additional_notes, $estimated_completion) {
    $text = "Dear " . $order['client_name'] . ",\n\n";
    $text .= "Your order #" . $order['orderid'] . " has been processed.\n\n";
    
    $text .= "=== ORDER DETAILS ===\n";
    $text .= "Order ID: " . $order['orderid'] . "\n";
    $text .= "Order Date: " . date('Y-m-d H:i', strtotime($order['odate'])) . "\n";
    $text .= "Budget: $" . number_format($order['budget'], 2) . "\n";
    $text .= "Design ID: " . $order['designid'] . "\n";
    $text .= "Requirements: " . substr($order['Requirements'], 0, 200) . "\n\n";
    
    $text .= "=== PROCESSING RESULT ===\n";
    $text .= "New Status: " . $status . "\n";
    if(!empty($manager_reply)) {
        $text .= "Manager's Response: " . $manager_reply . "\n";
    }
    if(!empty($additional_notes)) {
        $text .= "Additional Notes: " . $additional_notes . "\n";
    }
    if(!empty($estimated_completion)) {
        $text .= "Estimated Completion Date: " . date('Y-m-d H:i', strtotime($estimated_completion)) . "\n";
    }
    
    $text .= "\n=== NEXT STEPS ===\n";
    if($status == 'Designing') {
        $text .= "Your order has been approved and is now in the designing phase. Our design team will contact you shortly.\n";
    } elseif($status == 'Cancelled') {
        $text .= "Your order has been cancelled. If you have any questions, please contact our customer service.\n";
    }
    
    $text .= "\nThank you for choosing our service!\n\n";
    $text .= "Best regards,\n";
    $text .= "The Management Team\n";
    
    return $text;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Order #<?php echo $order_id; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .order-info { background-color: #f8f9fa; }
        .approval-form { background-color: #fff3cd; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="datetime-local"], select, textarea { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; 
        }
        textarea { min-height: 100px; }
        .button-group { margin-top: 20px; }
        .btn { 
            padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; 
            margin-right: 10px; text-decoration: none; display: inline-block;
        }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        .error { color: #dc3545; background-color: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .success { color: #155724; background-color: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Approve/Process Order #<?php echo htmlspecialchars($order['orderid']); ?></h1>
        
        <?php if(isset($error_message)): ?>
            <div class="error">Error: <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <!-- 订单信息 -->
        <div class="section order-info">
            <h2>Order Details</h2>
            <table border="1" cellpadding="10" cellspacing="0" width="100%">
                <tr>
                    <th width="20%">Order ID</th>
                    <td width="30%"><?php echo htmlspecialchars($order['orderid']); ?></td>
                    <th width="20%">Order Date</th>
                    <td width="30%"><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
                </tr>
                <tr>
                    <th>Client</th>
                    <td colspan="3">
                        <?php echo htmlspecialchars($order['client_name']); ?>
                        <br><small>Email: <?php echo htmlspecialchars($order['client_email']); ?></small>
                        <?php if(!empty($order['client_phone'])): ?>
                            <br><small>Phone: <?php echo htmlspecialchars($order['client_phone']); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Budget</th>
                    <td>$<?php echo number_format($order['budget'], 2); ?></td>
                    <th>Current Status</th>
                    <td style="color: orange; font-weight: bold;"><?php echo htmlspecialchars($order['ostatus']); ?></td>
                </tr>
                <tr>
                    <th>Design</th>
                    <td colspan="3">
                        Design #<?php echo htmlspecialchars($order['designid']); ?>
                        <br>Price: $<?php echo number_format($order['design_price'], 2); ?>
                        <?php if(!empty($order['design_tag'])): ?>
                            <br>Tags: <?php echo htmlspecialchars(substr($order['design_tag'], 0, 100)); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Requirements</th>
                    <td colspan="3"><?php echo nl2br(htmlspecialchars($order['Requirements'] ?? 'No requirements specified')); ?></td>
                </tr>
                <?php if(!empty($order['Floor_Plan'])): ?>
                <tr>
                    <th>Floor Plan</th>
                    <td colspan="3"><?php echo htmlspecialchars($order['Floor_Plan']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- 审批表单 -->
        <div class="section approval-form">
            <h2>Approval/Processing Form</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="status">Update Status *</label>
                    <select name="status" id="status" required onchange="updateStatusDescription()">
                        <option value="">Select Status</option>
                        <option value="Designing">Designing - Approve and proceed to design phase</option>
                        <option value="Cancelled">Cancelled - Reject/Cancel this order</option>
                    </select>
                    <small id="status-description"></small>
                </div>
                
                <div class="form-group">
                    <label for="manager_reply">Manager's Response/Reply *</label>
                    <textarea name="manager_reply" id="manager_reply" required 
                              placeholder="Enter your response to the client regarding this order..."></textarea>
                    <small>This response will be sent to the client via email and added to order notes.</small>
                </div>
                
                <div class="form-group">
                    <label for="additional_notes">Additional Notes (Optional)</label>
                    <textarea name="additional_notes" id="additional_notes" 
                              placeholder="Any additional notes or instructions..."></textarea>
                    <small>These notes will be included in the email to the client.</small>
                </div>
                
                <div class="form-group">
                    <label for="estimated_completion">Estimated Completion Date (Optional)</label>
                    <input type="datetime-local" name="estimated_completion" id="estimated_completion"
                           value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                    <small>If approved, when do you estimate this order will be completed?</small>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="submit_approval" class="btn btn-success">
                        Submit Approval & Send Email to Client
                    </button>
                    <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-secondary">Cancel</a>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background-color: #e7f3ff; border-radius: 4px;">
                    <h4>⚠️ Important Notes:</h4>
                    <ul>
                        <li>Upon submission, the order status will be updated immediately</li>
                        <li>An email notification will be sent to: <strong><?php echo htmlspecialchars($order['client_email']); ?></strong></li>
                        <li>Your response will be added to the order requirements as a note</li>
                        <li>This action cannot be undone</li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function updateStatusDescription() {
        const statusSelect = document.getElementById('status');
        const description = document.getElementById('status-description');
        
        if(statusSelect.value === 'Designing') {
            description.innerHTML = '✓ Order will proceed to design phase. Client will be notified.';
            description.style.color = '#28a745';
        } else if(statusSelect.value === 'Cancelled') {
            description.innerHTML = '✗ Order will be cancelled. Client will be notified.';
            description.style.color = '#dc3545';
        } else {
            description.innerHTML = '';
        }
    }
    
    // 页面加载时初始化
    document.addEventListener('DOMContentLoaded', function() {
        updateStatusDescription();
        
        // 自动填充回复示例（可选）
        document.getElementById('manager_reply').addEventListener('focus', function() {
            if(this.value === '') {
                this.value = "Dear " + "<?php echo addslashes($order['client_name']); ?>,\n\n" +
                            "Thank you for your order. We have reviewed your request and ";
            }
        });
    });
    </script>
</body>
</html>