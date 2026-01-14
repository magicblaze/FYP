<?php
require_once dirname(__DIR__) . '/config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Total Order Management</title>
</head>
<body>
    <h2>Total Orders</h2>
    
    <!-- Status Filter -->
    <div>
        <strong>Filter by Status:</strong>
        <a href="?status=all">All</a> |
        <a href="?status=Pending">Pending</a> |
        <a href="?status=Designing">Designing</a> |
        <a href="?status=Completed">Completed</a>
    </div>
    <br>
    
    <?php
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    $sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
                   c.clientid, c.cname as client_name,
                   d.designid, d.price as design_price, d.tag as design_tag,
                   s.FinishDate
            FROM `Order` o
            LEFT JOIN `Client` c ON o.clientid = c.clientid
            LEFT JOIN `Design` d ON o.designid = d.designid
            LEFT JOIN `Schedule` s ON o.orderid = s.orderid";
    
    if($status_filter != 'all') {
        // 使用预处理语句防止SQL注入
        $status_filter = mysqli_real_escape_string($conn, $status_filter);
        $sql .= " WHERE o.ostatus = '$status_filter'";
    }
    
    $sql .= " ORDER BY o.odate DESC";
    
    $result = mysqli_query($conn, $sql);
    
    if(!$result){
        echo '<p>Error: ' . mysqli_error($conn) . '</p>';
    } else {
        $total_orders = mysqli_num_rows($result);
        echo "<p>Total Orders: " . $total_orders . "</p>";
    ?>
    
    <table border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>Order ID</th>
            <th>Order Date</th>
            <th>Client</th>
            <th>Budget</th>
            <th>Design</th>
            <th>Requirement</th>
            <th>Status</th>
            <th>Scheduled Date</th>
            <th>Actions</th>
        </tr>
        <?php
        if($total_orders == 0){
            echo '<tr><td colspan="9">No orders found.</td></tr>';
        } else {
            while($row = mysqli_fetch_assoc($result)){
                $status_color = '';
                switch($row["ostatus"]) {
                    case 'Completed':
                        $status_color = 'green';
                        break;
                    case 'Designing':
                        $status_color = 'blue';
                        break;
                    case 'Pending':
                        $status_color = 'orange';
                        break;
                    default:
                        $status_color = 'black';
                }
        ?>
        <tr>
            <td><?php echo htmlspecialchars($row["orderid"]); ?></td>
            <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
            <td>
                <?php 
                echo htmlspecialchars($row["client_name"] ?? 'N/A'); 
                echo '<br><small>ID: ' . htmlspecialchars($row["clientid"] ?? 'N/A') . '</small>';
                ?>
            </td>
            <td>$<?php echo number_format($row["budget"], 2); ?></td>
            <td>
                <?php 
                echo 'Design #' . htmlspecialchars($row["designid"] ?? 'N/A');
                echo '<br>Price: $' . number_format($row["design_price"] ?? 0, 2);
                echo '<br>Tag: ' . htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30)) . '...';
                ?>
            </td>
            <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 50)) . (strlen($row["Requirements"] ?? '') > 50 ? '...' : ''); ?></td>
            <td>
                <strong style="color: <?php echo $status_color; ?>;">
                    <?php echo htmlspecialchars($row["ostatus"] ?? 'Pending'); ?>
                </strong>
            </td>
            <td>
                <?php 
                if(isset($row["FinishDate"]) && $row["FinishDate"] != '0000-00-00 00:00:00'){
                    echo date('Y-m-d H:i', strtotime($row["FinishDate"]));
                } else {
                    echo 'Not scheduled';
                }
                ?>
            </td>
            <td>
                <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $row["orderid"]; ?>">Edit</a>
                |
                <button onclick="viewOrder(<?php echo json_encode($row['orderid']); ?>)">View</button>
            </td>
        </tr>
        <?php
            }
        }
        ?>
    </table>
    <?php
    }
    
    mysqli_free_result($result);
    mysqli_close($conn);
    ?>
    
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + encodeURIComponent(orderId);
    }
    </script>
    
    <br>
    <button onclick="window.location.href='Manager_MyOrder.html'">Back to MyOrders</button>
</body>
</html>