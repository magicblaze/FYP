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

if(isset($_GET['id'])) {
    $orderid = mysqli_real_escape_string($mysqli, $_GET['id']);
    
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
        die("You don't have permission to view this order.");
    }
    
    $sql = "SELECT o.*, c.*, d.*, s.*
            FROM `Order` o
            LEFT JOIN `Client` c ON o.clientid = c.clientid
            LEFT JOIN `Design` d ON o.designid = d.designid
            LEFT JOIN `Schedule` s ON o.orderid = s.orderid
            WHERE o.orderid = '$orderid'";
    
    $result = mysqli_query($mysqli, $sql);
    $order = mysqli_fetch_assoc($result);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order Details #<?php echo $orderid ?? 'N/A'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            header {
                display: none !important;
            }
            body {
                font-size: 12pt;
            }
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            .page-title {
                margin-bottom: 20px;
                border-bottom: 2px solid #333;
                padding-bottom: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- Header Navigation (matching Manager_MyOrder.php style) -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center no-print">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="Manager_MyOrder.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="Manager_introduct.php">Introduct</a></li>
                    <li class="nav-item"><a class="nav-link active" href="Manager_MyOrder.php">MyOrder</a></li>
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
            <i class="fas fa-file-alt me-2"></i>Order Details #<?php echo htmlspecialchars($orderid ?? 'N/A'); ?>
        </div>

        <?php if(isset($order) && $order): ?>

        <!-- Order Summary Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Order Summary
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="fw-bold text-muted">Order ID</label>
                        <p class="mb-0"><strong>#<?php echo htmlspecialchars($order['orderid']); ?></strong></p>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="fw-bold text-muted">Order Date</label>
                        <p class="mb-0"><?php echo date('Y-m-d H:i', strtotime($order['odate'])); ?></p>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="fw-bold text-muted">Budget</label>
                        <p class="mb-0"><strong class="text-success">$<?php echo number_format($order['budget'], 2); ?></strong></p>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="fw-bold text-muted">Status</label>
                        <p class="mb-0">
                            <?php 
                            $status_class = '';
                            switch($order['ostatus']) {
                                case 'Completed': $status_class = 'status-completed'; break;
                                case 'Designing': $status_class = 'status-designing'; break;
                                case 'Pending': $status_class = 'status-pending'; break;
                                default: $status_class = 'status-pending';
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($order['ostatus']); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user me-2"></i>Client Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Client Name</label>
                        <p class="mb-0"><strong><?php echo htmlspecialchars($order['cname']); ?></strong></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Email</label>
                        <p class="mb-0"><small><?php echo htmlspecialchars($order['cemail']); ?></small></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Phone</label>
                        <p class="mb-0"><small><?php echo htmlspecialchars($order['ctel']); ?></small></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Design Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-palette me-2"></i>Design Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="fw-bold text-muted">Design ID</label>
                        <p class="mb-0">#<?php echo htmlspecialchars($order['designid']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="fw-bold text-muted">Design Price</label>
                        <p class="mb-0"><strong class="text-success">$<?php echo number_format($order['price'], 2); ?></strong></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="fw-bold text-muted">Design Tag</label>
                        <p class="mb-0"><small><?php echo htmlspecialchars($order['tag']); ?></small></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Requirements Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Requirements
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-light p-3">
                    <?php echo nl2br(htmlspecialchars($order['Requirements'])); ?>
                </div>
            </div>
        </div>

        <!-- Schedule Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-calendar me-2"></i>Schedule Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Order Finish Date</label>
                        <p class="mb-0">
                            <?php 
                            if(isset($order["OrderFinishDate"]) && $order["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($order["OrderFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold text-muted">Design Finish Date</label>
                        <p class="mb-0">
                            <?php 
                            if(isset($order["DesignFinishDate"]) && $order["DesignFinishDate"] != '0000-00-00 00:00:00'){
                                echo date('Y-m-d H:i', strtotime($order["DesignFinishDate"]));
                            } else {
                                echo '<span class="text-muted">Not scheduled</span>';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Order not found.</strong>
            <p class="mb-0">The requested order does not exist or has been removed.</p>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4 no-print">
            <div class="btn-group">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <button onclick="goBack()" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </button>
            </div>
            <small class="text-muted">Order #<?php echo htmlspecialchars($orderid ?? 'N/A'); ?></small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function goBack() {
            window.history.back();
        }
        
        // Add keyboard shortcut support
        document.addEventListener('keydown', function(e) {
            // ESC key to go back
            if (e.key === 'Escape') {
                goBack();
            }
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if(isset($result)) mysqli_free_result($result);
mysqli_close($mysqli);
?>
