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

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: Manager_MyOrder_Completed.php?error=no_id');
    exit();
}

$order_id = intval($_GET['id']);

// 检查订单是否属于当前经理
$check_manager_sql = "SELECT COUNT(*) as count FROM `OrderProduct` op 
                      JOIN `Manager` m ON op.managerid = m.managerid 
                      WHERE op.orderid = ? AND m.managerid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $user_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$manager_check = mysqli_fetch_assoc($check_result);

if ($manager_check['count'] == 0) {
    header('Location: Manager_MyOrder_Completed.php?error=nopermission');
    exit();
}

// First, verify the order exists and is completed
$check_sql = "SELECT o.orderid, o.ostatus FROM `Order` o WHERE o.orderid = ?";
$check_stmt = mysqli_prepare($mysqli, $check_sql);

if (!$check_stmt) {
    header('Location: Manager_MyOrder_Completed.php?error=db_error');
    exit();
}

mysqli_stmt_bind_param($check_stmt, "i", $order_id);
mysqli_stmt_execute($check_stmt);
$result = mysqli_stmt_get_result($check_stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    header('Location: Manager_MyOrder_Completed.php?error=not_found');
    exit();
}

// Check if order is actually completed
if (strtolower($order['ostatus']) !== 'complete' && strtolower($order['ostatus']) !== 'completed') {
    header('Location: Manager_MyOrder_Completed.php?error=not_completed');
    exit();
}

mysqli_stmt_close($check_stmt);

// Begin transaction
mysqli_begin_transaction($mysqli);

try {
    // 1. Archive order data to Archive table
    // First check if Archive table exists, if not create it with new structure
    $check_archive_table = "SHOW TABLES LIKE 'Archive'";
    $table_result = mysqli_query($mysqli, $check_archive_table);
    
    if (mysqli_num_rows($table_result) == 0) {
        // Create Archive table with new structure (OrderFinishDate and DesignFinishDate)
        $create_archive_table = "
            CREATE TABLE `Archive` (
                `archiveid` INT AUTO_INCREMENT PRIMARY KEY,
                `orderid` INT NOT NULL,
                `odate` DATETIME NOT NULL,
                `budget` DECIMAL(10,2) NOT NULL,
                `Requirements` TEXT,
                `ostatus` VARCHAR(50) NOT NULL,
                `clientid` INT,
                `designid` INT,
                `client_name` VARCHAR(255),
                `design_price` DECIMAL(10,2),
                `design_tag` VARCHAR(255),
                `order_finish_date` DATETIME,
                `design_finish_date` DATETIME,
                `archived_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `managerid` INT,
                INDEX `idx_orderid` (`orderid`),
                INDEX `idx_archived_date` (`archived_date`),
                INDEX `idx_managerid` (`managerid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        if (!mysqli_query($mysqli, $create_archive_table)) {
            throw new Exception("Failed to create archive table");
        }
    }
    
    // 2. Get complete order details for archiving with new column names
    // Assuming we have both OrderFinishDate and DesignFinishDate in the Schedule table
    $archive_sql = "
        SELECT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
               o.clientid, o.designid,
               c.cname as client_name,
               d.price as design_price, d.tag as design_tag,
               s.OrderFinishDate as order_finish_date,
               s.DesignFinishDate as design_finish_date
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ?
    ";
    
    $archive_stmt = mysqli_prepare($mysqli, $archive_sql);
    mysqli_stmt_bind_param($archive_stmt, "i", $order_id);
    mysqli_stmt_execute($archive_stmt);
    $archive_result = mysqli_stmt_get_result($archive_stmt);
    $order_data = mysqli_fetch_assoc($archive_result);
    
    if (!$order_data) {
        throw new Exception("Failed to retrieve order data");
    }
    
    // 3. Insert into Archive table with new structure
    $insert_archive_sql = "
        INSERT INTO `Archive` 
        (orderid, odate, budget, Requirements, ostatus, clientid, designid, 
         client_name, design_price, design_tag, order_finish_date, design_finish_date, managerid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    
    $insert_stmt = mysqli_prepare($mysqli, $insert_archive_sql);
    
    // Handle null values
    $client_name = $order_data['client_name'] ?? null;
    $design_price = $order_data['design_price'] ?? null;
    $design_tag = $order_data['design_tag'] ?? null;
    $order_finish_date = $order_data['order_finish_date'] ?? null;
    $design_finish_date = $order_data['design_finish_date'] ?? null;
    
    mysqli_stmt_bind_param(
        $insert_stmt, 
        "isdssiisdsssi",
        $order_data['orderid'],
        $order_data['odate'],
        $order_data['budget'],
        $order_data['Requirements'],
        $order_data['ostatus'],
        $order_data['clientid'],
        $order_data['designid'],
        $client_name,
        $design_price,
        $design_tag,
        $order_finish_date,
        $design_finish_date,
        $user_id
    );
    
    if (!mysqli_stmt_execute($insert_stmt)) {
        throw new Exception("Failed to archive order: " . mysqli_error($mysqli));
    }
    
    $archive_id = mysqli_insert_id($mysqli);
    
    mysqli_stmt_close($insert_stmt);
    
    // 4. Delete the order from Order table
    $delete_order_sql = "DELETE FROM `Order` WHERE orderid = ?";
    $delete_stmt = mysqli_prepare($mysqli, $delete_order_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $order_id);
    
    if (!mysqli_stmt_execute($delete_stmt)) {
        throw new Exception("Failed to delete order: " . mysqli_error($mysqli));
    }
    
    // 5. Delete associated OrderProduct records
    $delete_op_sql = "DELETE FROM `OrderProduct` WHERE orderid = ? AND managerid = ?";
    $delete_op_stmt = mysqli_prepare($mysqli, $delete_op_sql);
    mysqli_stmt_bind_param($delete_op_stmt, "ii", $order_id, $user_id);
    
    if (!mysqli_stmt_execute($delete_op_stmt)) {
        throw new Exception("Failed to delete order product records");
    }
    
    // Commit transaction
    mysqli_commit($mysqli);
    
    // Log the archive action (optional)
    error_log("Order #" . $order_id . " archived successfully by manager #" . $user_id . ". Archive ID: " . $archive_id);
    
    // Redirect back with success message
    header('Location: Manager_MyOrder_Completed.php?msg=archived&id=' . $order_id);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (mysqli_get_server_version($mysqli) >= 50500) {
        mysqli_rollback($mysqli);
    }
    
    // Log error
    error_log("Archive Error for Order #" . $order_id . " by manager #" . $user_id . ": " . $e->getMessage());
    
    // Redirect with error
    header('Location: Manager_MyOrder_Completed.php?error=archive_failed&details=' . urlencode($e->getMessage()));
    exit();
}

// Close connection if still open
if ($mysqli) {
    mysqli_close($mysqli);
}
?>
<?php include __DIR__ . '/../Public/chat_widget.php'; ?>
