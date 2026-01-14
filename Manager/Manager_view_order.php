<?php
require_once dirname(__DIR__) . '/config.php';

if(isset($_GET['id'])) {
    $orderid = mysqli_real_escape_string($conn, $_GET['id']);
    
    $sql = "SELECT o.*, c.*, d.*, s.*
            FROM `Order` o
            LEFT JOIN `Client` c ON o.clientid = c.clientid
            LEFT JOIN `Design` d ON o.designid = d.designid
            LEFT JOIN `Schedule` s ON o.orderid = s.orderid
            WHERE o.orderid = '$orderid'";
    
    $result = mysqli_query($conn, $sql);
    $order = mysqli_fetch_assoc($result);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order Details</title>
</head>
<body>
    <h1>Order Details #<?php echo $orderid ?? 'N/A'; ?></h1>
    
    <?php if(isset($order) && $order): ?>
    <table border="1" cellpadding="10">
        <tr>
            <th>Order ID</th>
            <td><?php echo htmlspecialchars($order['orderid']); ?></td>
        </tr>
        <tr>
            <th>Order Date</th>
            <td><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></td>
        </tr>
        <tr>
            <th>Client</th>
            <td>
                <?php echo htmlspecialchars($order['cname']); ?><br>
                Email: <?php echo htmlspecialchars($order['cemail']); ?><br>
                Phone: <?php echo htmlspecialchars($order['ctel']); ?>
            </td>
        </tr>
        <tr>
            <th>Budget</th>
            <td>$<?php echo number_format($order['budget'], 2); ?></td>
        </tr>
        <tr>
            <th>Requirements</th>
            <td><?php echo htmlspecialchars($order['Requirements']); ?></td>
        </tr>
        <tr>
            <th>Design</th>
            <td>
                Design ID: <?php echo htmlspecialchars($order['designid']); ?><br>
                Price: $<?php echo number_format($order['price'], 2); ?><br>
                Tag: <?php echo htmlspecialchars($order['tag']); ?>
            </td>
        </tr>
        <tr>
            <th>Status</th>
            <td><?php echo htmlspecialchars($order['ostatus']); ?></td>
        </tr>
        <tr>
            <th>Completed Date</th>
            <td>
                <?php 
                if(isset($order["FinishDate"]) && $order["FinishDate"] != '0000-00-00 00:00:00'){
                    echo date('Y-m-d H:i', strtotime($order["FinishDate"]));
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
        </tr>
    </table>
    <?php else: ?>
    <p>Order not found.</p>
    <?php endif; ?>
    
    <br>
    <button onclick="window.print()">Print This Page</button>
    <button onclick="window.location.href='Manager_MyOrder_Completed.php'">Close</button>
    <br><br>
    
    <?php
    if(isset($result)) mysqli_free_result($result);
    mysqli_close($conn);
    ?>

</body>
</html>