<?php
require("config.php");

$orderid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 使用预处理语句防止SQL注入
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.Floor_Plan, o.ostatus,
               c.clientid, c.cname as client_name, c.ctel, c.cemail,
               d.designid, d.design, d.price as design_price, d.tag as design_tag,
               s.scheduleid, s.FinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ?";
        
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $orderid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

$edit_status = isset($_GET['edit']) && $_GET['edit'] == 'status';
$edit_order = isset($_GET['edit']) && $_GET['edit'] == 'order';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['update_status'])) {
        $new_status = mysqli_real_escape_string($conn, $_POST['ostatus']);
        $finish_date = mysqli_real_escape_string($conn, $_POST['FinishDate']);
        
        // 更新Order表的ostatus
        $update_order_status_sql = "UPDATE `Order` SET ostatus = '$new_status' WHERE orderid = $orderid";
        
        if(mysqli_query($conn, $update_order_status_sql)) {
            // 更新或插入Schedule表的FinishDate
            if($order['scheduleid']) {
                $update_schedule_sql = "UPDATE `Schedule` SET FinishDate = '$finish_date' WHERE scheduleid = '{$order['scheduleid']}'";
            } else {
                // 默认managerid为1，可以根据需要修改
                $update_schedule_sql = "INSERT INTO `Schedule` (managerid, FinishDate, orderid) 
                                       VALUES (1, '$finish_date', '$orderid')";
            }
            mysqli_query($conn, $update_schedule_sql);
            
            header("Location: Manager_MyOrder_TotalOrder.php");
            exit();
        }
    }
    
    if(isset($_POST['update_order'])) {
        $budget = floatval($_POST['budget']);
        $requirements = mysqli_real_escape_string($conn, $_POST['Requirements']);
        $clientid = intval($_POST['clientid']);
        $designid = intval($_POST['designid']);
        
        $update_order_sql = "UPDATE `Order` SET 
                            budget = $budget,
                            Requirements = '$requirements',
                            clientid = $clientid,
                            designid = $designid
                            WHERE orderid = $orderid";
        
        if(mysqli_query($conn, $update_order_sql)) {
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
    <title>Edit Order</title>
</head>
<body>
    <h1>Edit Order #<?php echo htmlspecialchars($order["orderid"] ?? 'N/A'); ?></h1>
    
    <?php if($order): ?>
    
    <h2>Order Information</h2>
    <table border="1" cellpadding="10" cellspacing="0">
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
                <?php echo htmlspecialchars($order["client_name"] ?? 'N/A'); ?>
                <br><small>ID: <?php echo htmlspecialchars($order["clientid"] ?? 'N/A'); ?></small>
                <?php if(!empty($order["cemail"])): ?>
                <br><small>Email: <?php echo htmlspecialchars($order["cemail"]); ?></small>
                <?php endif; ?>
                <?php if(!empty($order["ctel"])): ?>
                <br><small>Phone: <?php echo htmlspecialchars($order["ctel"]); ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Budget</th>
            <td>$<?php echo number_format($order["budget"], 2); ?></td>
        </tr>
        <tr>
            <th>Design</th>
            <td>
                Design #<?php echo htmlspecialchars($order["designid"] ?? 'N/A'); ?>
                <br>Price: $<?php echo number_format($order["design_price"] ?? 0, 2); ?>
                <?php if(!empty($order["design_tag"])): ?>
                <br>Tags: <?php echo htmlspecialchars(substr($order["design_tag"], 0, 50)); ?>
                <?php endif; ?>
                <?php if(!empty($order["design"])): ?>
                <br>Image: <?php echo htmlspecialchars($order["design"]); ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Requirements</th>
            <td><?php echo nl2br(htmlspecialchars($order["Requirements"] ?? '')); ?></td>
        </tr>
        <tr>
            <th>Status</th>
            <td>
                <?php 
                $status = $order["ostatus"] ?? 'Pending';
                $status_color = '';
                switch($status) {
                    case 'Completed': $status_color = 'green'; break;
                    case 'Designing': $status_color = 'blue'; break;
                    case 'Pending': $status_color = 'orange'; break;
                    default: $status_color = 'black';
                }
                ?>
                <strong style="color: <?php echo $status_color; ?>;">
                    <?php echo htmlspecialchars($status); ?>
                </strong>
            </td>
        </tr>
        <tr>
            <th>Scheduled Finish Date</th>
            <td>
                <?php 
                if(isset($order["FinishDate"]) && $order["FinishDate"] != '0000-00-00 00:00:00'){
                    echo date('Y-m-d H:i', strtotime($order["FinishDate"]));
                } else {
                    echo 'Not scheduled';
                }
                ?>
            </td>
        </tr>
    </table>
    
    <br><br>
    
    <!-- Two Column Layout for Update Forms -->
    <table width="100%" cellspacing="20">
        <tr>
            <td width="50%" valign="top">
                <!-- Update Status Section -->
                <?php if(!$edit_status): ?>
                    <h3>Update Status</h3>
                    <form method="get">
                        <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                        <input type="hidden" name="edit" value="status">
                        <button type="submit">Update Status</button>
                    </form>
                <?php else: ?>
                    <h3>Update Status</h3>
                    <form method="post">
                        <input type="hidden" name="update_status" value="1">
                        <table border="1" cellpadding="10" cellspacing="0">
                            <tr>
                                <th>Current Status</th>
                                <td><?php echo htmlspecialchars($order["ostatus"] ?? 'Pending'); ?></td>
                            </tr>
                            <tr>
                                <th>New Status</th>
                                <td>
                                    <select name="ostatus" required>
                                        <option value="Pending" <?php echo (($order["ostatus"] ?? 'Pending') == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Designing" <?php echo (($order["ostatus"] ?? '') == 'Designing') ? 'selected' : ''; ?>>Designing</option>
                                        <option value="Completed" <?php echo (($order["ostatus"] ?? '') == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                        <option value="Cancelled" <?php echo (($order["ostatus"] ?? '') == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Finish Date</th>
                                <td>
                                    <input type="datetime-local" name="FinishDate" 
                                           value="<?php echo isset($order['FinishDate']) && $order['FinishDate'] != '0000-00-00 00:00:00' 
                                                   ? date('Y-m-d\TH:i', strtotime($order['FinishDate'])) 
                                                   : date('Y-m-d\TH:i'); ?>"
                                           required>
                                </td>
                            </tr>
                        </table>
                        <br>
                        <button type="submit">Save Status</button>
                        <button type="button" onclick="window.location.href='Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>'">Cancel</button>
                    </form>
                <?php endif; ?>
            </td>
            
            <td width="50%" valign="top">
                <!-- Update Order Information Section -->
                <?php if(!$edit_order): ?>
                    <h3>Update Order Information</h3>
                    <form method="get">
                        <input type="hidden" name="id" value="<?php echo $orderid; ?>">
                        <input type="hidden" name="edit" value="order">
                        <button type="submit">Update Order Information</button>
                    </form>
                <?php else: ?>
                    <h3>Update Order Information</h3>
                    <form method="post">
                        <input type="hidden" name="update_order" value="1">
                        <table border="1" cellpadding="10" cellspacing="0">
                            <tr>
                                <th>Budget</th>
                                <td>
                                    $<input type="number" name="budget" step="0.01" min="0" 
                                           value="<?php echo number_format($order["budget"], 2, '.', ''); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th>Client</th>
                                <td>
                                    <select name="clientid" required>
                                        <option value="">Select Client</option>
                                        <?php
                                        $client_sql = "SELECT clientid, cname, cemail FROM Client ORDER BY cname";
                                        $client_result = mysqli_query($conn, $client_sql);
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
                                    <select name="designid" required>
                                        <option value="">Select Design</option>
                                        <?php
                                        $design_sql = "SELECT designid, price, tag FROM Design ORDER BY designid";
                                        $design_result = mysqli_query($conn, $design_sql);
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
                                    <textarea name="Requirements" rows="6" cols="50" required><?php echo htmlspecialchars($order["Requirements"] ?? ''); ?></textarea>
                                </td>
                            </tr>
                        </table>
                        <br>
                        <button type="submit">Save Order Information</button>
                        <button type="button" onclick="window.location.href='Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $orderid; ?>'">Cancel</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    
    <?php else: ?>
        <p>Order not found.</p>
    <?php endif; ?>
    
    <?php
    if(isset($result)) mysqli_free_result($result);
    mysqli_close($conn);
    ?>
    
    <br><br>
    <button onclick="window.location.href='Manager_MyOrder_TotalOrder.php'">Back to Order List</button>
    <button onclick="window.location.href='Manager_MyOrder.html'">Back to Orders Manager</button>
</body>
</html>