<?php
require("conn.php");

$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, 
               c.clientid, c.cname as client_name,
               d.designid, d.price as design_price, d.tag as design_tag,
               s.ostatus, s.FinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE s.ostatus = 'Completed' OR s.ostatus = 'completed'
        ORDER BY s.FinishDate DESC";

$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Orders</title>
</head>
<body>
    <h1>Completed Orders</h1>
    <hr>
    
    <?php
    if(!$result){
        echo "<p>Error: " . mysqli_error($conn) . "</p>";
    } elseif(mysqli_num_rows($result) == 0){
        echo "<p>No completed orders found.</p>";
    } else {
        $total_completed = mysqli_num_rows($result);
        echo "<h2>Total Completed Orders: " . $total_completed . "</h2>";
    ?>
    
    <table border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>Order ID</th>
            <th>Order Date</th>
            <th>Client</th>
            <th>Budget</th>
            <th>Design</th>
            <th>Requirements</th>
            <th>Status</th>
            <th>Completed Date</th>
            <th>Actions</th>
        </tr>
        <?php
        while($row = mysqli_fetch_assoc($result)){
        ?>
        <tr>
            <td><?php echo htmlspecialchars($row["orderid"]); ?></td>
            <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
            <td>
                <?php 
                echo htmlspecialchars($row["client_name"] ?? 'N/A'); 
                echo '<br><small>Client ID: ' . htmlspecialchars($row["clientid"] ?? 'N/A') . '</small>';
                ?>
            </td>
            <td>$<?php echo number_format($row["budget"], 2); ?></td>
            <td>
                <?php 
                echo 'Design #' . htmlspecialchars($row["designid"] ?? 'N/A');
                echo '<br>Price: $' . number_format($row["design_price"] ?? 0, 2);
                echo '<br>Tag: ' . htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30));
                ?>
            </td>
            <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
            <td>
                <strong style="color: green;">
                    <?php echo htmlspecialchars($row["ostatus"] ?? 'Completed'); ?>
                </strong>
            </td>
            <td>
                <?php 
                if(isset($row["FinishDate"]) && $row["FinishDate"] != '0000-00-00 00:00:00'){
                    echo date('Y-m-d H:i', strtotime($row["FinishDate"]));
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
            <td>
                <button onclick="printOrder(<?php echo $row['orderid']; ?>)">Print</button>
                <button onclick="archiveOrder(<?php echo $row['orderid']; ?>)">Archive</button>
            </td>
        </tr>
        <?php
        }
        ?>
    </table>
    
    <h3>Completed Orders Summary</h3>
    <p>Total Completed Orders: <?php echo $total_completed; ?></p>
    
    <?php
    $budget_sql = "SELECT SUM(o.budget) as total_budget 
                   FROM `Order` o 
                   LEFT JOIN `Schedule` s ON o.orderid = s.orderid
                   WHERE s.ostatus = 'Completed' OR s.ostatus = 'completed'";
    $budget_result = mysqli_query($conn, $budget_sql);
    $budget_row = mysqli_fetch_assoc($budget_result);
    $total_budget = $budget_row['total_budget'] ?? 0;
    
    echo "<p>Total Budget of Completed Orders: $" . number_format($total_budget, 2) . "</p>";
    
    mysqli_free_result($budget_result);
    ?>
    
    <?php
    }
    
    mysqli_free_result($result);
    mysqli_close($conn);
    ?>
    
    <script>
    function printOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + orderId;
    }
    
    function archiveOrder(orderId) {
        if(confirm('Are you sure you want to archive order #' + orderId + '?')) {
            window.location.href = 'Manager_archive_order.php?id=' + orderId;
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Completed orders page loaded');
    });
    </script>
    <button onclick="window.print()">Print This Page</button>
    <button onclick="window.location.href='Manager_MyOrder.html'">Back to Orders Manager</button>
</body>
</html>