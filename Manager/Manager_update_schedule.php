<?php
require_once dirname(__DIR__) . '/config.php';

$message = '';
$error = '';

// 检查是否收到订单ID
if(!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: Manager_Schedule.php');
    exit();
}

$orderid = mysqli_real_escape_string($mysqli, $_GET['id']);

// 获取订单和安排信息
$sql = "SELECT o.*, c.*, s.*
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = '$orderid'";
        
$result = mysqli_query($mysqli, $sql);
$order = mysqli_fetch_assoc($result);

if(!$order) {
    die("Order not found.");
}

// 处理表单提交
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $finish_date = mysqli_real_escape_string($mysqli, $_POST['finish_date']);
    $notes = mysqli_real_escape_string($mysqli, $_POST['notes']);
    
    // 验证日期
    if(empty($finish_date)) {
        $error = "Finish date is required.";
    } else {
        // 检查是否已存在安排记录
        $check_sql = "SELECT * FROM `Schedule` WHERE orderid = '$orderid'";
        $check_result = mysqli_query($mysqli, $check_sql);
        
        if(mysqli_num_rows($check_result) > 0) {
            // 更新现有记录
            $update_sql = "UPDATE `Schedule` 
                          SET FinishDate = '$finish_date'";
            
            if(!empty($notes)) {
                $update_sql .= ", Notes = '$notes'";
            }
            
            $update_sql .= " WHERE orderid = '$orderid'";
        } else {
            // 插入新记录
            $update_sql = "INSERT INTO `Schedule` (orderid, FinishDate, Notes) 
                          VALUES ('$orderid', '$finish_date', '$notes')";
        }
        
        if(mysqli_query($mysqli, $update_sql)) {
            $message = "Schedule updated successfully!";
            
            // 更新订单状态为 Designing（如果还是 Pending）
            if($order['ostatus'] == 'Pending' || $order['ostatus'] == 'pending') {
                $status_sql = "UPDATE `Order` SET ostatus = 'Designing' WHERE orderid = '$orderid'";
                mysqli_query($mysqli, $status_sql);
            }
            
            // 重新获取更新后的数据
            $result = mysqli_query($mysqli, $sql);
            $order = mysqli_fetch_assoc($result);
        } else {
            $error = "Error updating schedule: " . mysqli_error($mysqli);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Update Schedule - HappyDesign</title>
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
                <a href="Manager_Schedule.php">Schedule</a>
            </div>
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Update Schedule for Order #<?php echo htmlspecialchars($orderid); ?></h1>
        
        <!-- 消息显示 -->
        <?php if($message): ?>
            <div class="alert alert-success">
                <div>
                    <strong>Success!</strong>
                    <p class="mb-0"><?php echo $message; ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <div>
                    <strong>Error!</strong>
                    <p class="mb-0"><?php echo $error; ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- 订单信息卡片 -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Order Information</h3>
                <div class="table-container">
                    <table class="table">
                        <tr>
                            <th width="20%">Order ID</th>
                            <td width="30%">#<?php echo htmlspecialchars($order['orderid']); ?></td>
                            <th width="20%">Client</th>
                            <td width="30%"><?php echo htmlspecialchars($order['cname']); ?></td>
                        </tr>
                        <tr>
                            <th>Order Date</th>
                            <td><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
                            <th>Status</th>
                            <td>
                                <span class="status-badge <?php echo strtolower($order['ostatus']) == 'completed' ? 'status-completed' : ($order['ostatus'] == 'Designing' ? 'status-designing' : 'status-pending'); ?>">
                                    <?php echo htmlspecialchars($order['ostatus']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Budget</th>
                            <td><strong class="text-success">$<?php echo number_format($order['budget'], 2); ?></strong></td>
                            <th>Requirements</th>
                            <td><?php echo htmlspecialchars(substr($order['Requirements'] ?? '', 0, 100)); ?></td>
                        </tr>
                        <?php if(isset($order['FinishDate']) && $order['FinishDate'] != '0000-00-00 00:00:00'): ?>
                        <tr>
                            <th>Current Finish Date</th>
                            <td colspan="3">
                                <?php echo date('Y-m-d H:i', strtotime($order['FinishDate'])); ?>
                                <?php if(!empty($order['Notes'])): ?>
                                    <br><small class="text-muted">Notes: <?php echo htmlspecialchars($order['Notes']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- 更新表单 -->
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">Update Schedule</h3>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="finish_date" class="form-label">Finish Date *</label>
                        <input type="datetime-local" id="finish_date" name="finish_date" 
                               class="form-control" 
                               value="<?php echo isset($order['FinishDate']) && $order['FinishDate'] != '0000-00-00 00:00:00' ? date('Y-m-d\TH:i', strtotime($order['FinishDate'])) : ''; ?>"
                               required>
                        <small class="text-muted">Set the expected completion date for this order</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes" class="form-label">Notes (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" 
                                  placeholder="Add any notes about the schedule..."><?php echo htmlspecialchars($order['Notes'] ?? ''); ?></textarea>
                        <small class="text-muted">Add any additional information or instructions</small>
                    </div>
                    
                    <div class="d-flex justify-between mt-4">
                        <div>
                            <button type="button" onclick="window.history.back()" class="btn btn-secondary">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Schedule</button>
                        </div>
                        <div>
                            <a href="Manager_view_order.php?id=<?php echo $orderid; ?>" class="btn btn-outline">View Order Details</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    // 设置默认日期为明天
    document.addEventListener('DOMContentLoaded', function() {
        const finishDateInput = document.getElementById('finish_date');
        if(!finishDateInput.value) {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(17, 0, 0); // 5:00 PM
            finishDateInput.value = tomorrow.toISOString().slice(0, 16);
        }
        
        // 键盘快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl+S 保存
            if(e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('form').submit();
            }
            
            // ESC 返回
            if(e.key === 'Escape') {
                window.history.back();
            }
        });
    });
    </script>
</body>
</html>