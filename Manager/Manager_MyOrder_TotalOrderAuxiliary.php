<?php
session_start();
if (isset($_GET["orderid"])){
    require_once dirname(__DIR__) . '/config.php';

    // Check if user is logged in as manager
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
        header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    $user = $_SESSION['user'];
    $user_id = $user['managerid'];
    
    // Validate and sanitize input
    $orderid = isset($_GET["orderid"]) ? intval($_GET["orderid"]) : 0;
    
    if($orderid <= 0) {
        header("Location: Manager_MyOrder_TotalOrder.php?msg=invalidid");
        exit();
    }
    
    // Check if order belongs to current manager
    $check_manager_sql = "SELECT COUNT(*) as count FROM `OrderProduct` op 
                          JOIN `Manager` m ON op.managerid = m.managerid 
                          WHERE op.orderid = ? AND m.managerid = ?";
    $check_stmt = mysqli_prepare($mysqli, $check_manager_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $orderid, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $manager_check = mysqli_fetch_assoc($check_result);
    
    if ($manager_check['count'] == 0) {
        header("Location: Manager_MyOrder_TotalOrder.php?msg=nopermission");
        exit();
    }
    
    // Check if order exists
    $check_order_sql = "SELECT orderid FROM `Order` WHERE orderid = $orderid";
    $check_order_result = mysqli_query($mysqli, $check_order_sql);
    
    if(mysqli_num_rows($check_order_result) == 0) {
        header("Location: Manager_MyOrder_TotalOrder.php?msg=notfound");
        exit();
    }
    
    // Use transaction to ensure data consistency
    mysqli_begin_transaction($mysqli);
    
    try {
        // 1. Delete OrderProduct records
        $check_product_sql = "SELECT * FROM `OrderProduct` WHERE orderid = $orderid";
        $check_product_result = mysqli_query($mysqli, $check_product_sql);
        
        if(mysqli_num_rows($check_product_result) > 0){
            $delete_product_sql = "DELETE FROM `OrderProduct` WHERE orderid = $orderid";
            if(!mysqli_query($mysqli, $delete_product_sql)) {
                throw new Exception("Failed to delete OrderProduct records");
            }
        }
        
        // 2. Delete Order_Contractors records
        $check_contractors_sql = "SELECT * FROM `Order_Contractors` WHERE orderid = $orderid";
        $check_contractors_result = mysqli_query($mysqli, $check_contractors_sql);
        
        if(mysqli_num_rows($check_contractors_result) > 0){
            $delete_contractors_sql = "DELETE FROM `Order_Contractors` WHERE orderid = $orderid";
            if(!mysqli_query($mysqli, $delete_contractors_sql)) {
                throw new Exception("Failed to delete Order_Contractors records");
            }
        }
        
        // 3. Delete Schedule records
        $check_schedule_sql = "SELECT * FROM `Schedule` WHERE orderid = $orderid";
        $check_schedule_result = mysqli_query($mysqli, $check_schedule_sql);
        
        if(mysqli_num_rows($check_schedule_result) > 0){
            $delete_schedule_sql = "DELETE FROM `Schedule` WHERE orderid = $orderid";
            if(!mysqli_query($mysqli, $delete_schedule_sql)) {
                throw new Exception("Failed to delete Schedule records");
            }
        }
        
        // 4. Delete Order record
        $delete_order_sql = "DELETE FROM `Order` WHERE orderid = $orderid";
        if(!mysqli_query($mysqli, $delete_order_sql)) {
            throw new Exception("Failed to delete Order record");
        }
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        // Check if deletion was successful
        if(mysqli_affected_rows($mysqli) > 0){
            header("Location: Manager_MyOrder_TotalOrder.php?msg=success");
        } else {
            header("Location: Manager_MyOrder_TotalOrder.php?msg=norows");
        }
        
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($mysqli);
        header("Location: Manager_MyOrder_TotalOrder.php?msg=error&error=" . urlencode($e->getMessage()));
    }
    
    mysqli_close($mysqli);
    exit();
} else {
    header("Location: Manager_MyOrder_TotalOrder.php?msg=noid");
    exit();
}
?>
