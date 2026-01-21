<?php
// ==============================
// File: Manager_mark_complete.php
// Mark order as completed - Simplified version
// ==============================
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
$redirect = '';

// 检查是否收到订单ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: Manager_Schedule.php');
    exit();
}

$orderid = intval($_GET['id']);

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
    die("You don't have permission to mark this order as complete.");
}

// 获取订单信息
$sql = "SELECT o.*, c.cname, c.cemail, c.ctel 
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        WHERE o.orderid = ?";
        
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $orderid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    die("Order not found.");
}

// 检查订单状态是否为Designing
if (strtolower($order['ostatus']) !== 'designing') {
    $error = "Only orders with 'Designing' status can be marked as complete.";
    $redirect = "Manager_view_order.php?id=$orderid";
}

// 处理标记完成操作
if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
    // 开始事务
    mysqli_begin_transaction($mysqli);
    
    try {
        // 更新订单状态为Completed
        $update_order_sql = "UPDATE `Order` SET ostatus = 'Completed' WHERE orderid = ?";
        $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
        mysqli_stmt_bind_param($update_order_stmt, "i", $orderid);
        
        if (!mysqli_stmt_execute($update_order_stmt)) {
            throw new Exception("Failed to update order status.");
        }
        
        // 更新所有相关产品的状态为Delivered
        $update_products_sql = "UPDATE `OrderProduct` SET status = 'Delivered' WHERE orderid = ? AND status != 'Delivered'";
        $update_products_stmt = mysqli_prepare($mysqli, $update_products_sql);
        mysqli_stmt_bind_param($update_products_stmt, "i", $orderid);
        
        if (!mysqli_stmt_execute($update_products_stmt)) {
            throw new Exception("Failed to update product status.");
        }
        
        // 提交事务
        mysqli_commit($mysqli);
        
        // 设置成功消息和重定向
        $message = "Order #$orderid has been successfully marked as completed!";
        $redirect = "Manager_view_order.php?id=$orderid";
        
    } catch (Exception $e) {
        // 回滚事务
        mysqli_rollback($mysqli);
        $error = "Error: " . $e->getMessage();
    }
}

// 如果有重定向，则跳转
if (!empty($redirect) && empty($error)) {
    if (!empty($message)) {
        // 存储消息到session用于显示
        $_SESSION['success_message'] = $message;
    }
    header("Location: $redirect");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Order Complete - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .completion-container {
            max-width: 600px;
            width: 100%;
            padding: 20px;
        }
        
        .completion-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .card-header h2 {
            margin: 0;
            font-weight: 600;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .order-info {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            margin-bottom: 0.75rem;
            display: flex;
        }
        
        .info-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 120px;
        }
        
        .info-value {
            color: #495057;
        }
        
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .bg-designing {
            background-color: #17a2b8;
            color: white;
        }
        
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .completion-form {
            text-align: center;
        }
        
        .btn-complete {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-complete:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: 1rem;
        }
        
        .btn-cancel:hover {
            background-color: #5a6268;
            color: white;
        }
        
        .status-change {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin: 2rem 0;
            font-size: 1.2rem;
        }
        
        .status-from {
            color: #17a2b8;
            font-weight: 600;
            padding: 0.5rem 1rem;
            background-color: #e8f4f8;
            border-radius: 8px;
        }
        
        .arrow {
            color: #6c757d;
            font-size: 1.5rem;
        }
        
        .status-to {
            color: #28a745;
            font-weight: 600;
            padding: 0.5rem 1rem;
            background-color: #d4edda;
            border-radius: 8px;
        }
        
        @media (max-width: 576px) {
            .completion-container {
                padding: 15px;
            }
            
            .card-header {
                padding: 1.5rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .status-change {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-cancel {
                margin-right: 0;
                margin-bottom: 1rem;
                width: 100%;
            }
            
            .btn-complete {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="completion-container">
        <div class="completion-card">
            <div class="card-header">
                <h2><i class="fas fa-check-circle me-2"></i>Mark Order as Complete</h2>
            </div>
            
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-3"></i>
                            <div>
                                <h5 class="mb-1">Error</h5>
                                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <a href="<?php echo $redirect ?: 'Manager_Schedule.php'; ?>" class="btn btn-cancel">
                            <i class="fas fa-arrow-left me-2"></i>Go Back
                        </a>
                    </div>
                <?php else: ?>
                    <!-- 订单信息 -->
                    <div class="order-info">
                        <div class="info-item">
                            <div class="info-label">Order ID:</div>
                            <div class="info-value">#<?php echo $orderid; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Client:</div>
                            <div class="info-value"><?php echo htmlspecialchars($order['cname']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Order Date:</div>
                            <div class="info-value"><?php echo date('Y-m-d', strtotime($order['odate'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Budget:</div>
                            <div class="info-value">HK$<?php echo number_format($order['budget'], 2); ?></div>
                        </div>
                    </div>
                    
                    <!-- 状态变化显示 -->
                    <div class="status-change">
                        <div class="status-from">
                            <i class="fas fa-paint-brush me-2"></i>Designing
                        </div>
                        <div class="arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="status-to">
                            <i class="fas fa-check-circle me-2"></i>Completed
                        </div>
                    </div>
                    
                    <!-- 操作按钮 -->
                    <form method="POST" action="" class="completion-form">
                        <div class="mb-4">
                            <p class="text-muted">This will change the order status from "Designing" to "Completed".</p>
                        </div>
                        
                        <div class="d-flex justify-content-center">
                            <a href="Manager_Schedule.php?id=<?php echo $orderid; ?>" class="btn btn-cancel">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-complete">
                                <i class="fas fa-check-circle me-2"></i>Mark as Complete
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 表单提交确认
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    return confirm('Are you sure you want to mark this order as complete?');
                });
            }
            
            // 键盘快捷键
            document.addEventListener('keydown', function(e) {
                // ESC 返回订单详情
                if (e.key === 'Escape') {
                    window.location.href = 'Manager_view_order.php?id=<?php echo $orderid; ?>';
                }
                
                // Enter 提交表单
                if (e.key === 'Enter' && !e.ctrlKey && !e.shiftKey) {
                    if (form) {
                        e.preventDefault();
                        form.submit();
                    }
                }
                
                // Ctrl+Enter 也提交表单
                if (e.ctrlKey && e.key === 'Enter') {
                    if (form) {
                        e.preventDefault();
                        form.submit();
                    }
                }
            });
            
            // 自动聚焦完成按钮
            const completeBtn = document.querySelector('.btn-complete');
            if (completeBtn) {
                completeBtn.focus();
            }
        });
    </script>
</body>
</html>