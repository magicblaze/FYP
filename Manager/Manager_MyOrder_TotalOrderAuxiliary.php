<?php
if (isset($_GET["orderid"])){
    require_once dirname(__DIR__) . '/config.php';
    
    // 验证和清理输入
    $orderid = isset($_GET["orderid"]) ? intval($_GET["orderid"]) : 0;
    
    if($orderid <= 0) {
        header("Location: Manager_MyOrder_TotalOrder.php?msg=invalidid");
        exit();
    }
    
    // 检查订单是否存在
    $check_order_sql = "SELECT orderid FROM `Order` WHERE orderid = $orderid";
    $check_order_result = mysqli_query($conn, $check_order_sql);
    
    if(mysqli_num_rows($check_order_result) == 0) {
        header("Location: Manager_MyOrder_TotalOrder.php?msg=notfound");
        exit();
    }
    
    // 使用事务确保数据一致性
    mysqli_begin_transaction($conn);
    
    try {
        // 1. 删除OrderProduct记录（原OrderMaterial）
        $check_product_sql = "SELECT * FROM `OrderProduct` WHERE orderid = $orderid";
        $check_product_result = mysqli_query($conn, $check_product_sql);
        
        if(mysqli_num_rows($check_product_result) > 0){
            $delete_product_sql = "DELETE FROM `OrderProduct` WHERE orderid = $orderid";
            if(!mysqli_query($conn, $delete_product_sql)) {
                throw new Exception("Failed to delete OrderProduct records");
            }
        }
        
        // 2. 删除Order_Contractors记录
        $check_contractors_sql = "SELECT * FROM `Order_Contractors` WHERE orderid = $orderid";
        $check_contractors_result = mysqli_query($conn, $check_contractors_sql);
        
        if(mysqli_num_rows($check_contractors_result) > 0){
            $delete_contractors_sql = "DELETE FROM `Order_Contractors` WHERE orderid = $orderid";
            if(!mysqli_query($conn, $delete_contractors_sql)) {
                throw new Exception("Failed to delete Order_Contractors records");
            }
        }
        
        // 3. 删除Schedule记录
        $check_schedule_sql = "SELECT * FROM `Schedule` WHERE orderid = $orderid";
        $check_schedule_result = mysqli_query($conn, $check_schedule_sql);
        
        if(mysqli_num_rows($check_schedule_result) > 0){
            $delete_schedule_sql = "DELETE FROM `Schedule` WHERE orderid = $orderid";
            if(!mysqli_query($conn, $delete_schedule_sql)) {
                throw new Exception("Failed to delete Schedule records");
            }
        }
        
        // 4. 删除Order记录
        $delete_order_sql = "DELETE FROM `Order` WHERE orderid = $orderid";
        if(!mysqli_query($conn, $delete_order_sql)) {
            throw new Exception("Failed to delete Order record");
        }
        
        // 提交事务
        mysqli_commit($conn);
        
        // 检查是否成功删除
        if(mysqli_affected_rows($conn) > 0){
            header("Location: Manager_MyOrder_TotalOrder.php?msg=success");
        } else {
            header("Location: Manager_MyOrder_TotalOrder.php?msg=norows");
        }
        
    } catch (Exception $e) {
        // 回滚事务
        mysqli_rollback($conn);
        header("Location: Manager_MyOrder_TotalOrder.php?msg=error&error=" . urlencode($e->getMessage()));
    }
    
    mysqli_close($conn);
    exit();
} else {
    header("Location: Manager_MyOrder_TotalOrder.php?msg=noid");
    exit();
}
?>