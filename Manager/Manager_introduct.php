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
$user_name = $user['name'];

// 获取经理详细信息
$manager_sql = "SELECT * FROM `Manager` WHERE managerid = ?";
$manager_stmt = mysqli_prepare($mysqli, $manager_sql);
mysqli_stmt_bind_param($manager_stmt, "i", $user_id);
mysqli_stmt_execute($manager_stmt);
$manager_result = mysqli_stmt_get_result($manager_stmt);
$manager_info = mysqli_fetch_assoc($manager_result);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <title>Worker Homepage - HappyDesign</title>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.php" class="active">Introduct</a>
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
        <h1 class="page-title">Personal Information - <?php echo htmlspecialchars($user_name); ?></h1>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Profile Details</h2>
            </div>
            <div class="card-body">
                <form class="form-container" method="POST" action="update_profile.php">
                    <div class="form-group">
                        <label for="worker-name" class="form-label">Name:</label>
                        <input type="text" id="worker-name" name="name" class="form-control" 
                               value="<?php echo htmlspecialchars($manager_info['mname'] ?? $user_name); ?>" 
                               placeholder="Enter your name">
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="form-label">Email address:</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($manager_info['memail'] ?? ''); ?>" 
                               placeholder="xxxx@xxxx.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="telephone" class="form-label">Telephone Number:</label>
                        <div class="d-flex gap-2">
                            <select id="phone_num" name="phone_code" class="form-control" style="width: 120px;">
                                <option value="+852" <?php echo ($manager_info['phone_code'] ?? '') == '+852' ? 'selected' : ''; ?>>+852</option>
                                <option value="+86" <?php echo ($manager_info['phone_code'] ?? '') == '+86' ? 'selected' : ''; ?>>+86</option>
                                <option value="+1" <?php echo ($manager_info['phone_code'] ?? '') == '+1' ? 'selected' : ''; ?>>+1</option>
                                <option value="+44" <?php echo ($manager_info['phone_code'] ?? '') == '+44' ? 'selected' : ''; ?>>+44</option>
                            </select>
                            <input type="tel" id="telephone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($manager_info['mtel'] ?? ''); ?>" 
                                   placeholder="1111 2222">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">New Password:</label>
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Enter new password (leave blank to keep current)">
                    </div>
                    
                    <div class="form-group">
                        <label for="company" class="form-label">Company Name:</label>
                        <input type="text" id="company" name="company" class="form-control" 
                               value="<?php echo htmlspecialchars($manager_info['company'] ?? ''); ?>" 
                               placeholder="Enter company name">
                    </div>
                </form>
            </div>
            <div class="card-footer">
                <button id="update-button" class="btn btn-primary">Update Profile</button>
                <button onclick="window.location.href='Manager_MyOrder.php'" class="btn btn-secondary">Cancel</button>
            </div>
        </div>
        
        <!-- 统计信息 -->
        <div class="stats-grid mt-4">
            <div class="stat-card">
                <div class="stat-value">
                    <?php
                    $order_count_sql = "SELECT COUNT(DISTINCT op.orderid) as count 
                                       FROM OrderProduct op 
                                       WHERE op.managerid = ?";
                    $order_stmt = mysqli_prepare($mysqli, $order_count_sql);
                    mysqli_stmt_bind_param($order_stmt, "i", $user_id);
                    mysqli_stmt_execute($order_stmt);
                    $order_result = mysqli_stmt_get_result($order_stmt);
                    $order_count = mysqli_fetch_assoc($order_result)['count'] ?? 0;
                    echo $order_count;
                    ?>
                </div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php
                    $pending_sql = "SELECT COUNT(DISTINCT op.orderid) as count 
                                   FROM OrderProduct op 
                                   JOIN `Order` o ON op.orderid = o.orderid
                                   WHERE op.managerid = ? AND o.ostatus = 'Pending'";
                    $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
                    mysqli_stmt_bind_param($pending_stmt, "i", $user_id);
                    mysqli_stmt_execute($pending_stmt);
                    $pending_result = mysqli_stmt_get_result($pending_stmt);
                    $pending_count = mysqli_fetch_assoc($pending_result)['count'] ?? 0;
                    echo $pending_count;
                    ?>
                </div>
                <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php
                    $completed_sql = "SELECT COUNT(DISTINCT op.orderid) as count 
                                     FROM OrderProduct op 
                                     JOIN `Order` o ON op.orderid = o.orderid
                                     WHERE op.managerid = ? AND o.ostatus = 'Completed'";
                    $completed_stmt = mysqli_prepare($mysqli, $completed_sql);
                    mysqli_stmt_bind_param($completed_stmt, "i", $user_id);
                    mysqli_stmt_execute($completed_stmt);
                    $completed_result = mysqli_stmt_get_result($completed_stmt);
                    $completed_count = mysqli_fetch_assoc($completed_result)['count'] ?? 0;
                    echo $completed_count;
                    ?>
                </div>
                <div class="stat-label">Completed Orders</div>
            </div>
        </div>
    </div>

    <script>
        // 表单验证
        document.getElementById('update-button').addEventListener('click', function() {
            const name = document.getElementById('worker-name').value;
            const email = document.getElementById('email').value;
            
            if (!name || !email) {
                alert('Please fill in all required fields');
                return;
            }
            
            if (!validateEmail(email)) {
                alert('Please enter a valid email address');
                return;
            }
            
            // 提交表单
            document.querySelector('form').submit();
        });
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    </script>
</body>
</html>