<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// 检查用户是否以经理身份登录
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];

$orderid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 检查订单是否属于当前经理
$check_manager_sql = "SELECT COUNT(*) as count FROM `OrderProduct` op 
                      JOIN `Manager` m ON op.managerid = m.managerid 
                      WHERE op.orderid = ? AND m.managerid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
mysqli_stmt_bind_param($check_stmt, "ii", $orderid, $user_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$manager_check = mysqli_fetch_assoc($check_result);

if ($manager_check['count'] == 0) {
    die("You don't have permission to edit this order.");
}

// 使用预处理语句防止SQL注入
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.Floor_Plan, o.ostatus,
               c.clientid, c.cname as client_name, c.ctel, c.cemail,
               d.designid, d.design, d.price as design_price, d.tag as design_tag,
               s.scheduleid, s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ?";
        
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $orderid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

$edit_status = isset($_GET['edit']) && $_GET['edit'] == 'status';
$edit_order = isset($_GET['edit']) && $_GET['edit'] == 'order';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['update_status'])) {
        $new_status = mysqli_real_escape_string($mysqli, $_POST['ostatus']);
        $order_finish_date = mysqli_real_escape_string($mysqli, $_POST['OrderFinishDate']);
        $design_finish_date = mysqli_real_escape_string($mysqli, $_POST['DesignFinishDate']);
        
        // 更新订单状态
        $update_order_status_sql = "UPDATE `Order` SET ostatus = '$new_status' WHERE orderid = $orderid";
        
        if(mysqli_query($mysqli, $update_order_status_sql)) {
            // 更新或创建Schedule记录
            if($order['scheduleid']) {
                $update_schedule_sql = "UPDATE `Schedule` SET 
                                        OrderFinishDate = " . (!empty($order_finish_date) ? "'$order_finish_date'" : "NULL") . ",
                                        DesignFinishDate = " . (!empty($design_finish_date) ? "'$design_finish_date'" : "NULL") . ",
                                        managerid = $user_id
                                        WHERE scheduleid = '{$order['scheduleid']}'";
            } else {
                $update_schedule_sql = "INSERT INTO `Schedule` (managerid, OrderFinishDate, DesignFinishDate, orderid) 
                                       VALUES ($user_id, " . 
                                       (!empty($order_finish_date) ? "'$order_finish_date'" : "NULL") . ", " .
                                       (!empty($design_finish_date) ? "'$design_finish_date'" : "NULL") . ", 
                                       '$orderid')";
            }
            mysqli_query($mysqli, $update_schedule_sql);
            
            header("Location: Manager_MyOrder_TotalOrder.php");
            exit();
        }
    }
    
    if(isset($_POST['update_order'])) {
        $budget = floatval($_POST['budget']);
        $requirements = mysqli_real_escape_string($mysqli, $_POST['Requirements']);
        $clientid = intval($_POST['clientid']);
        $designid = intval($_POST['designid']);
        
        $update_order_sql = "UPDATE `Order` SET 
                            budget = $budget,
                            Requirements = '$requirements',
                            clientid = $clientid,
                            designid = $designid
                            WHERE orderid = $orderid";
        
        if(mysqli_query($mysqli, $update_order_sql)) {
            header("Location: Manager_MyOrder_TotalOrder.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Edit Order #<?php echo $orderid; ?> - HappyDesign</title>
        <style>
        body {
            color: #000000;
        }
        
        .text-muted {
            color: #333333 !important;
        }
        
        .card-body,
        .table td,
        .table th,
        .info-label,
        .info-value,
        .btn,
        .alert,
        .small,
        small {
            color: #000000 !important;
        }
        
        .text-success {
            color: #006400 !important;
        }
        
        .text-danger {
            color: #8B0000 !important;
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
                <a href="Manager_Massage.php">Massage</a>
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
        <h1 class="page-title">Edit Order #<?php echo htmlspecialchars($order["orderid"] ?? 'N/A'); ?></h1>
        
        <?php if($order): ?>
        
        <!-- 订单信息 -->
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Order Information</h2>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <tr>
                            <th>Order ID</th>
                            <td><?php echo htmlspecialchars($order["orderid"]); ?></td>
                        </tr>
                        <tr>
                            <th>Order Date</th>
                            <td><?php echo date('Y-m-d H:i', strtotime($order["odate"])); ?></td>
                        </tr>
                        <tr>
                            <th>Client</th>
                            <td>
                                <div class="d-flex flex-column">
                                    <strong><?php echo htmlspecialchars($order["client_name"] ?? 'N/A'); ?></strong>
                                    <span>ID: <?php echo htmlspecialchars($order["clientid"] ?? 'N/A'); ?></span>
                                    <?php if(!empty($order["cemail"])): ?>
                                        <span>Email: <?php echo htmlspecialchars($order["cemail"]); ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($order["ctel"])): ?>
                                        <span>Phone: <?php echo htmlspecialchars($order["ctel"]); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>Budget</th>
                            <td><strong class="text-success">$<?php echo number_format($order["budget"], 2); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Design</th>
                            <td>
                                <div class="d-flex flex-column">
                                    <span>Design #<?php echo htmlspecialchars($order["designid"] ?? 'N/A'); ?></span>
                                    <span>Price: $<?php echo number_format($order["design_price"] ?? 0, 2); ?></span>
                                    <?php if(!empty($order["design_tag"])): ?>
                                        <span>Tags: <?php echo htmlspecialchars(substr($order["design_tag"], 0, 50)); ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($order["design"])): ?>
                                        <span class="text-muted">Image: <?php echo htmlspecialchars($order["design"]); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>Requirements</th>
                            <td>
                                <div class="requirements-box">
                                    <?php echo nl2br(htmlspecialchars($order["Requirements"] ?? '')); ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <?php 
                                $status = $order["ostatus"] ?? 'Pending';
                                $status_class = '';
                                switch($status) {
                                    case 'Completed': $status_class = 'status-completed'; break;
                                    case 'Designing': $status_class = 'status-designing'; break;
                                    case 'Pending': $status_class = 'status-pending'; break;
                                    default: $status_class = 'status-pending';
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Order Finish Date</th>
                            <td>
                                <?php 
                                if(isset($order["OrderFinishDate"]) && $order["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                    echo date('Y-m-d H:i', strtotime($order["OrderFinishDate"]));
                                } else {
                                    echo '<span class="text-muted">Not scheduled</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Design Finish Date</th>
                            <td>
                                <?php 
                                if(isset($order["DesignFinishDate"]) && $order["DesignFinishDate"] != '0000-00-00 00:00:00'){
                                    echo date('Y-m-d H:i', strtotime($order["DesignFinishDate"]));
                                } else {
                                    echo '<span class="text-muted">Not scheduled</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- 双列布局 -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <!-- 更新状态部分 -->
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Update Status & Dates</h3>
                    </div>
                    <div class="card-body">
                        <?php if(!$edit_status): ?>
                            <form method="get">
                                <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                <input type="hidden" name="edit" value="status">
                                <button type="submit" class="btn btn-primary btn-block">Update Status & Dates</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="update_status" value="1">
                                <div class="table-container">
                                    <table class="table">
                                        <tr>
                                            <th>Current Status</th>
                                            <td>
                                                <span class="status-badge status-pending">
                                                    <?php echo htmlspecialchars($order["ostatus"] ?? 'Pending'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>New Status</th>
                                            <td>
                                                <select name="ostatus" class="form-control" required>
                                                    <option value="Pending" <?php echo (($order["ostatus"] ?? 'Pending') == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="Designing" <?php echo (($order["ostatus"] ?? '') == 'Designing') ? 'selected' : ''; ?>>Designing</option>
                                                    <option value="Completed" <?php echo (($order["ostatus"] ?? '') == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="Cancelled" <?php echo (($order["ostatus"] ?? '') == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Order Finish Date</th>
                                            <td>
                                                <input type="datetime-local" name="OrderFinishDate" class="form-control"
                                                       value="<?php echo isset($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00 00:00:00' 
                                                               ? date('Y-m-d\TH:i', strtotime($order['OrderFinishDate'])) 
                                                               : date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Design Finish Date</th>
                                            <td>
                                                <input type="datetime-local" name="DesignFinishDate" class="form-control"
                                                       value="<?php echo isset($order['DesignFinishDate']) && $order['DesignFinishDate'] != '0000-00-00 00:00:00' 
                                                               ? date('Y-m-d\TH:i', strtotime($order['DesignFinishDate'])) 
                                                               : date('Y-m-d\TH:i', strtotime('+3 days')); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="d-flex justify-between mt-3">
                                    <button type="submit" class="btn btn-success">Save Status & Dates</button>
                                    <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <!-- 更新订单信息部分 -->
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Update Order Information</h3>
                    </div>
                    <div class="card-body">
                        <?php if(!$edit_order): ?>
                            <form method="get">
                                <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                                <input type="hidden" name="edit" value="order">
                                <button type="submit" class="btn btn-primary btn-block">Update Order Information</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="update_order" value="1">
                                <div class="table-container">
                                    <table class="table">
                                        <tr>
                                            <th>Budget</th>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" name="budget" class="form-control" step="0.01" min="0" 
                                                           value="<?php echo number_format($order["budget"], 2, '.', ''); ?>" required>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Client</th>
                                            <td>
                                                <select name="clientid" class="form-control" required>
                                                    <option value="">Select Client</option>
                                                    <?php
                                                    $client_sql = "SELECT clientid, cname, cemail FROM Client ORDER BY cname";
                                                    $client_result = mysqli_query($mysqli, $client_sql);
                                                    while($client = mysqli_fetch_assoc($client_result)){
                                                        $selected = ($client['clientid'] == $order['clientid']) ? 'selected' : '';
                                                        echo '<option value="' . $client['clientid'] . '" ' . $selected . '>' . 
                                                             htmlspecialchars($client['cname']) . ' (ID: ' . $client['clientid'] . 
                                                             ' - ' . htmlspecialchars($client['cemail']) . ')</option>';
                                                    }
                                                    mysqli_free_result($client_result);
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Design</th>
                                            <td>
                                                <select name="designid" class="form-control" required>
                                                    <option value="">Select Design</option>
                                                    <?php
                                                    $design_sql = "SELECT designid, price, tag FROM Design ORDER BY designid";
                                                    $design_result = mysqli_query($mysqli, $design_sql);
                                                    while($design = mysqli_fetch_assoc($design_result)){
                                                        $selected = ($design['designid'] == $order['designid']) ? 'selected' : '';
                                                        echo '<option value="' . $design['designid'] . '" ' . $selected . '>' . 
                                                             'Design #' . $design['designid'] . ' - $' . number_format($design['price'], 2) . 
                                                             ' (' . htmlspecialchars(substr($design['tag'], 0, 30)) . '...)' . '</option>';
                                                    }
                                                    mysqli_free_result($design_result);
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Requirements</th>
                                            <td>
                                                <textarea name="Requirements" class="form-control" rows="4" required><?php echo htmlspecialchars($order["Requirements"] ?? ''); ?></textarea>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="d-flex justify-between mt-3">
                                    <button type="submit" class="btn btn-success">Save Order Information</button>
                                    <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="alert alert-error">
            <div>
                <strong>Order not found.</strong>
                <p>The requested order does not exist or has been removed.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 返回按钮 -->
        <div class="d-flex justify-between mt-4">
            <div class="btn-group">
                <button onclick="window.location.href='Manager_MyOrder_TotalOrder.php'" 
                        class="btn btn-secondary">Back to Order List</button>
                <button onclick="window.location.href='Manager_MyOrder.php'" 
                        class="btn btn-outline">Back to Orders Manager</button>
            </div>
        </div>
    </div>
    
    <style>
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 10px;
        }
        
        .card.h-100 {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .card.h-100 .card-body {
            flex: 1;
        }
        
        .requirements-box {
            max-height: 150px;
            overflow-y: auto;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .input-group {
            width: 100%;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #ced4da;
            border-right: none;
            border-radius: 6px 0 0 6px;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 6px 6px 0;
        }
        
        @media (max-width: 768px) {
            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 20px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
        }
    </style>
</body>
</html>

<?php
if(isset($result)) mysqli_free_result($result);
mysqli_close($mysqli);
?>