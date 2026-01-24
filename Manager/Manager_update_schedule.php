<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// 检查用户是否以经理身份登录
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

$message = '';
$error = '';

// 检查是否收到订单ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: Manager_Schedule.php');
    exit();
}

$orderid = intval($_GET['id']);

// 检查订单是否属于当前经理（使用预处理语句）
$check_manager_sql = "SELECT COUNT(*) as count FROM `OrderProduct` op 
                      JOIN `Manager` m ON op.managerid = m.managerid 
                      WHERE op.orderid = ? AND m.managerid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
mysqli_stmt_bind_param($check_stmt, "ii", $orderid, $user_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$manager_check = mysqli_fetch_assoc($check_result);

if ($manager_check['count'] == 0) {
    die("You don't have permission to update this order's schedule.");
}

// 获取订单和安排信息（使用预处理语句）- 修正字段名
$sql = "SELECT o.*, c.cname, c.cemail, c.ctel, 
               s.OrderFinishDate, s.DesignFinishDate, s.scheduleid
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ?";
        
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $orderid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    die("Order not found.");
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 验证并清理输入
    if (empty($_POST['order_finish_date'])) {
        $error = "Order finish date is required.";
    } else {
        $order_finish_date = $_POST['order_finish_date'];
        
        // 验证日期格式
        if (!strtotime($order_finish_date)) {
            $error = "Invalid date format.";
        } else {
            // 格式化日期
            $order_finish_date = date('Y-m-d', strtotime($order_finish_date));
            
            // 如果有设计完成日期，也处理
            $design_finish_date = '';
            if (!empty($_POST['design_finish_date']) && strtotime($_POST['design_finish_date'])) {
                $design_finish_date = date('Y-m-d', strtotime($_POST['design_finish_date']));
            }
            
            // 检查是否已存在安排记录
            $check_sql = "SELECT scheduleid FROM `Schedule` WHERE orderid = ?";
            $check_stmt = mysqli_prepare($mysqli, $check_sql);
            mysqli_stmt_bind_param($check_stmt, "i", $orderid);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                // 更新现有记录
                if (!empty($design_finish_date)) {
                    $update_sql = "UPDATE `Schedule` 
                                  SET OrderFinishDate = ?, DesignFinishDate = ?
                                  WHERE orderid = ?";
                    $update_stmt = mysqli_prepare($mysqli, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "ssi", $order_finish_date, $design_finish_date, $orderid);
                } else {
                    $update_sql = "UPDATE `Schedule` 
                                  SET OrderFinishDate = ?
                                  WHERE orderid = ?";
                    $update_stmt = mysqli_prepare($mysqli, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "si", $order_finish_date, $orderid);
                }
                $result = mysqli_stmt_execute($update_stmt);
            } else {
                // 插入新记录
                if (!empty($design_finish_date)) {
                    $update_sql = "INSERT INTO `Schedule` (orderid, OrderFinishDate, DesignFinishDate, managerid) 
                                  VALUES (?, ?, ?, ?)";
                    $update_stmt = mysqli_prepare($mysqli, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "issi", $orderid, $order_finish_date, $design_finish_date, $user_id);
                } else {
                    $update_sql = "INSERT INTO `Schedule` (orderid, OrderFinishDate, managerid) 
                                  VALUES (?, ?, ?)";
                    $update_stmt = mysqli_prepare($mysqli, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "isi", $orderid, $order_finish_date, $user_id);
                }
                $result = mysqli_stmt_execute($update_stmt);
            }
            
            if ($result) {
                $message = "Schedule updated successfully!";
                
                // 更新订单状态为 Designing（如果还是 Pending）
                if (strtolower($order['ostatus']) == 'pending') {
                    $status_sql = "UPDATE `Order` SET ostatus = 'Designing' WHERE orderid = ?";
                    $status_stmt = mysqli_prepare($mysqli, $status_sql);
                    mysqli_stmt_bind_param($status_stmt, "i", $orderid);
                    mysqli_stmt_execute($status_stmt);
                }
                
                // 重新获取更新后的数据
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $order = mysqli_fetch_assoc($result);
            } else {
                $error = "Error updating schedule: " . mysqli_error($mysqli);
            }
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
    <style>
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-designing {
            background-color: #17a2b8;
            color: white;
        }
        .status-completed {
            background-color: #28a745;
            color: white;
        }
        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .status-inprogress {
            background-color: #007bff;
            color: white;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .justify-between {
            display: flex;
            justify-content: space-between;
        }
        .mb-4 {
            margin-bottom: 1.5rem;
        }
        .mt-4 {
            margin-top: 1.5rem;
        }
        .mb-0 {
            margin-bottom: 0;
        }
        .text-muted {
            color: #6c757d;
        }
        .text-success {
            color: #28a745;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }
        .date-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .date-col {
            flex: 1;
            min-width: 250px;
        }
        @media (max-width: 768px) {
            .date-row {
                flex-direction: column;
                gap: 1rem;
            }
            .date-col {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.php">Introduct</a>
                <a href="Manager_MyOrder.php">MyOrder</a>
                 <a href="Manager_Schedule.php">Schedule</a>
            </div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="page-container">
        <h1 class="page-title">Update Schedule for Order #<?php echo htmlspecialchars($orderid); ?></h1>
        
        <!-- 消息显示 -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <div>
                    <strong>Success!</strong>
                    <p class="mb-0"><?php echo htmlspecialchars($message); ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <div>
                    <strong>Error!</strong>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
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
                            <td><?php echo !empty($order['odate']) ? date('Y-m-d H:i', strtotime($order['odate'])) : 'N/A'; ?></td>
                            <th>Status</th>
                            <td>
                                <?php
                                $status_class = 'status-pending';
                                $status = strtolower($order['ostatus']);
                                if ($status == 'completed') {
                                    $status_class = 'status-completed';
                                } elseif ($status == 'designing') {
                                    $status_class = 'status-designing';
                                } elseif ($status == 'inprogress') {
                                    $status_class = 'status-inprogress';
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($order['ostatus']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Budget</th>
                            <td><strong class="text-success">HK$<?php echo isset($order['budget']) ? number_format($order['budget'], 2) : '0.00'; ?></strong></td>
                            <th>Client Email</th>
                            <td><?php echo htmlspecialchars($order['cemail']); ?></td>
                        </tr>
                        <?php if (isset($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00'): ?>
                        <tr>
                            <th>Current Order Finish Date</th>
                            <td colspan="3">
                                <strong><?php echo date('Y-m-d', strtotime($order['OrderFinishDate'])); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00'): ?>
                        <tr>
                            <th>Current Design Finish Date</th>
                            <td colspan="3">
                                <strong><?php echo date('Y-m-d', strtotime($order['DesignFinishDate'])); ?></strong>
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
                    <div class="date-row">
                        <div class="date-col">
                            <div class="form-group">
                                <label for="order_finish_date" class="form-label">Order Finish Date *</label>
                                <input type="date" id="order_finish_date" name="order_finish_date" 
                                       class="form-control" 
                                       value="<?php 
                                           if (isset($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00') {
                                               echo date('Y-m-d', strtotime($order['OrderFinishDate']));
                                           }
                                       ?>"
                                       required>
                                <small class="text-muted">Set the expected completion date for the entire order</small>
                            </div>
                        </div>
                        
                        <div class="date-col">
                            <div class="form-group">
                                <label for="design_finish_date" class="form-label">Design Finish Date</label>
                                <input type="date" id="design_finish_date" name="design_finish_date" 
                                       class="form-control" 
                                       value="<?php 
                                           if (isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00') {
                                               echo date('Y-m-d', strtotime($order['DesignFinishDate']));
                                           }
                                       ?>">
                                <small class="text-muted">Set the expected completion date for design phase (optional)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-between mt-4">
                        <div>
                            <button type="button" onclick="window.location.href='Manager_Schedule_detail.php'" class="btn btn-secondary">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Schedule</button>
                        </div>
                        <div>
                            <a href="Manager_view_order.php?id=<?php echo $orderid; ?>" class="btn btn-outline">View Order Details</a>
                            <a href="Manager_Schedule_detail.php?orderid=<?php echo $orderid; ?>" class="btn btn-info">View Calendar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const orderFinishDateInput = document.getElementById('order_finish_date');
        const designFinishDateInput = document.getElementById('design_finish_date');
        
        // 设置默认日期为7天后
        if (!orderFinishDateInput.value) {
            const nextWeek = new Date();
            nextWeek.setDate(nextWeek.getDate() + 7);
            const year = nextWeek.getFullYear();
            const month = String(nextWeek.getMonth() + 1).padStart(2, '0');
            const day = String(nextWeek.getDate()).padStart(2, '0');
            orderFinishDateInput.value = `${year}-${month}-${day}`;
        }
        
        // 设置设计完成日期为3天后（如果为空）
        if (!designFinishDateInput.value) {
            const threeDays = new Date();
            threeDays.setDate(threeDays.getDate() + 3);
            const year = threeDays.getFullYear();
            const month = String(threeDays.getMonth() + 1).padStart(2, '0');
            const day = String(threeDays.getDate()).padStart(2, '0');
            designFinishDateInput.value = `${year}-${month}-${day}`;
        }
        
        // 验证：设计完成日期不应晚于订单完成日期
        function validateDates() {
            if (orderFinishDateInput.value && designFinishDateInput.value) {
                const orderDate = new Date(orderFinishDateInput.value);
                const designDate = new Date(designFinishDateInput.value);
                
                if (designDate > orderDate) {
                    alert('Warning: Design finish date should not be later than order finish date.');
                    return false;
                }
            }
            return true;
        }
        
        // 表单提交前验证
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!validateDates()) {
                e.preventDefault();
            }
        });
        
        // 键盘快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl+S 保存
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                if (validateDates()) {
                    document.querySelector('form').submit();
                }
            }
            
            // ESC 返回
            if (e.key === 'Escape') {
                window.location.href = 'Manager_Schedule_detail.php';
            }
            
            // Alt+O 聚焦订单完成日期
            if (e.altKey && e.key === 'o') {
                e.preventDefault();
                orderFinishDateInput.focus();
            }
            
            // Alt+D 聚焦设计完成日期
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                designFinishDateInput.focus();
            }
        });
    });
    </script>
</body>
</html>