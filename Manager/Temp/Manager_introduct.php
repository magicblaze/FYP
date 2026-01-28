<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Get manager detailed information
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
    <title>HappyDesign - Personal Information</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>

<body>
    <!-- Header Navigation (matching design_dashboard.php style) -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-user-circle me-2"></i>Personal Information
        </div>

        <!-- Profile Card -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-edit me-2"></i>Profile Details
                </h5>
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

                    <div class="btn-group mt-4">
                        <button type="submit" id="update-button" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Profile
                        </button>
                        <button type="button" onclick="window.location.href='Manager_MyOrder.php'" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Statistics Section -->
        <div class="row mt-4">
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #3498db; font-weight: 700; margin-bottom: 0.5rem;">
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
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-list me-2"></i>Total Orders
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #f39c12; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php
                            $pending_sql = "SELECT COUNT(DISTINCT op.orderid) as count 
                                           FROM OrderProduct op 
                                           JOIN `Order` o ON op.orderid = o.orderid
                                           WHERE op.managerid = ? AND o.ostatus = 'Pending'";
                                           WHERE op.managerid = ? AND o.ostatus = 'waiting confirm'";
                            $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
                            mysqli_stmt_bind_param($pending_stmt, "i", $user_id);
                            mysqli_stmt_execute($pending_stmt);
                            $pending_result = mysqli_stmt_get_result($pending_stmt);
                            $pending_count = mysqli_fetch_assoc($pending_result)['count'] ?? 0;
                            echo $pending_count;
                            ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-hourglass-half me-2"></i>Pending Orders
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php
                            $completed_sql = "SELECT COUNT(DISTINCT op.orderid) as count 
                                             FROM OrderProduct op 
                                             JOIN `Order` o ON op.orderid = o.orderid
                                             WHERE op.managerid = ? AND o.ostatus = 'complete'";
                            $completed_stmt = mysqli_prepare($mysqli, $completed_sql);
                            mysqli_stmt_bind_param($completed_stmt, "i", $user_id);
                            mysqli_stmt_execute($completed_stmt);
                            $completed_result = mysqli_stmt_get_result($completed_stmt);
                            $completed_count = mysqli_fetch_assoc($completed_result)['count'] ?? 0;
                            echo $completed_count;
                            ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-check-circle me-2"></i>Completed Orders
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const name = document.getElementById('worker-name').value;
            const email = document.getElementById('email').value;
            
            if (!name || !email) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }
            
            if (!validateEmail(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>
