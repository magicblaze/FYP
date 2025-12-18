<?php
require("conn.php");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Total Order Management</title>
</head>
<body>
    <h2>Total Orders</h2>
    <table border="1">
        <tr>
            <th>Order ID</th>
            <th>Order Date</th>
            <th>Client</th>
            <th>Budget</th>
            <th>Design</th>
            <th>Requirement</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php
        $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
        
        $sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, 
                       c.clientid, c.cname as client_name,
                       d.designid, d.price as design_price, d.tag as design_tag,
                       s.ostatus
                FROM `Order` o
                LEFT JOIN `Client` c ON o.clientid = c.clientid
                LEFT JOIN `Design` d ON o.designid = d.designid
                LEFT JOIN `Schedule` s ON o.orderid = s.orderid";
        
        if($status_filter != 'all') {
            $sql .= " WHERE s.ostatus = '$status_filter'";
            if($status_filter == 'all') {
                $sql .= " OR s.ostatus IS NULL";
            }
        }
        
        $sql .= " ORDER BY o.odate DESC";
        
        $result = mysqli_query($conn, $sql);
        
        if(!$result){
            echo '<tr><td colspan="8">Error: ' . mysqli_error($conn) . '</td></tr>';
        } elseif(mysqli_num_rows($result) == 0){
            echo '<tr><td colspan="8">No orders found.</td></tr>';
        } else {
            while($row = mysqli_fetch_assoc($result)){
        ?>
        <tr>
            <td><?php echo htmlspecialchars($row["orderid"]); ?></td>
            <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
            <td>
                <?php 
                echo htmlspecialchars($row["client_name"] ?? 'N/A'); 
                echo ' (ID: ' . htmlspecialchars($row["clientid"] ?? 'N/A') . ')';
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
                <?php 
                $status = $row["ostatus"] ?? 'Pending';
                switch($status) {
                    case 'Completed':
                        echo 'Completed';
                        break;
                    case 'Designing':
                        echo 'Designing';
                        break;
                    case 'Pending':
                        echo 'Pending';
                        break;
                    case 'Cancelled':
                        echo 'Cancelled';
                        break;
                    default:
                        echo $status;
                }
                ?>
            </td>
            <td>
                <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $row["orderid"]; ?>">Edit</a>
            </td>
        </tr>
        <?php
            }
        }
        
        mysqli_free_result($result);
        mysqli_close($conn);
        ?>
    </table>
    <button onclick="window.location.href='Manager_MyOrder.html'">Back to MyOrders</button>
</body>
</html>

