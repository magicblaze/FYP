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

$message = '';
$error = '';
$redirect = '';

// Check if order ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: Manager_Schedule.php');
    exit();
}

$orderid = intval($_GET['id']);

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
    die("You don't have permission to mark this order as complete.");
}

// Get order information
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

// Check if order status is Designing
if (strtolower($order['ostatus']) !== 'designing') {
    $error = "Only orders with 'Designing' status can be marked as complete.";
    $redirect = "Manager_view_order.php?id=$orderid";
}

// Handle mark complete operation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Update order status to Completed
        $update_order_sql = "UPDATE `Order` SET ostatus = 'Completed' WHERE orderid = ?";
        $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
        mysqli_stmt_bind_param($update_order_stmt, "i", $orderid);
        
        if (!mysqli_stmt_execute($update_order_stmt)) {
            throw new Exception("Failed to update order status.");
        }
        
        // Update all related products status to Delivered
        $update_products_sql = "UPDATE `OrderProduct` SET status = 'Delivered' WHERE orderid = ? AND status != 'Delivered'";
        $update_products_stmt = mysqli_prepare($mysqli, $update_products_sql);
        mysqli_stmt_bind_param($update_products_stmt, "i", $orderid);
        
        if (!mysqli_stmt_execute($update_products_stmt)) {
            throw new Exception("Failed to update product status.");
        }
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        // Set success message and redirect
        $message = "Order #$orderid has been successfully marked as completed!";
        $redirect = "Manager_view_order.php?id=$orderid";
        
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($mysqli);
        $error = "Error: " . $e->getMessage();
    }
}

// If redirect needed, go to that page
if (!empty($redirect) && empty($error)) {
    if (!empty($message)) {
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
    <title>HappyDesign - Mark Order Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>

<body>
    <!-- Header Navigation -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="Manager_MyOrder.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="Manager_introduct.php">Introduct</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_MyOrder.php">MyOrder</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_Schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?php echo htmlspecialchars($user_name); ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-check-circle me-2"></i>Mark Order as Complete
        </div>

        <!-- Main Content Card -->
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?>
                    <!-- Error Alert -->
                    <div class="alert alert-danger mb-4" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-3" style="font-size: 1.5rem;"></i>
                            <div>
                                <h5 class="mb-1">Error</h5>
                                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="btn-group">
                        <a href="<?php echo $redirect ?: 'Manager_Schedule.php'; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Go Back
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Order Information -->
                    <h5 class="card-title mb-4">
                        <i class="fas fa-info-circle me-2"></i>Order Information
                    </h5>
                    
                    <div style="background-color: #f8f9fa; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem;">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Order ID:</span>
                                    <span style="color: #495057;">#<?php echo $orderid; ?></span>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Client Name:</span>
                                    <span style="color: #495057;"><?php echo htmlspecialchars($order['cname'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Client Email:</span>
                                    <span style="color: #495057;"><?php echo htmlspecialchars($order['cemail'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Current Status:</span>
                                    <span class="status-badge status-designing">
                                        <i class="fas fa-pencil-alt me-1"></i><?php echo htmlspecialchars($order['ostatus']); ?>
                                    </span>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Client Phone:</span>
                                    <span style="color: #495057;"><?php echo htmlspecialchars($order['ctel'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <span style="font-weight: 600; color: #2c3e50; display: block; margin-bottom: 0.25rem;">Order Date:</span>
                                    <span style="color: #495057;"><?php echo date('Y-m-d', strtotime($order['odate'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Change Preview -->
                    <div style="background-color: #f0f0f0; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; text-align: center;">
                        <p style="color: #7f8c8d; margin-bottom: 1rem; font-size: 0.95rem;">Status will change from:</p>
                        <div style="display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: 1rem;">
                            <span class="status-badge status-designing">
                                <i class="fas fa-pencil-alt me-1"></i>Designing
                            </span>
                            <i class="fas fa-arrow-right" style="color: #7f8c8d; font-size: 1.5rem;"></i>
                            <span class="status-badge status-completed">
                                <i class="fas fa-check-circle me-1"></i>Completed
                            </span>
                        </div>
                    </div>

                    <!-- Confirmation Form -->
                    <form method="POST" class="mb-4">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Please confirm:</strong> Once you mark this order as complete, all related products will be marked as delivered.
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check-circle me-2"></i>Confirm & Mark Complete
                            </button>
                            <a href="Manager_Schedule.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>
