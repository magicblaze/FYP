<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Get completed orders - 修复：使用设计师关联逻辑
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.budget as client_budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = ?
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE (o.ostatus = 'complete' OR o.ostatus = 'complete')
        ORDER BY s.OrderFinishDate DESC";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Completed Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
</head>

<body>
    <!-- Header Navigation (matching Manager_MyOrder.php style) -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-check-circle me-2"></i>Completed Orders
        </div>

        <!-- Success Message -->
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'archived'): ?>
            <div class="alert alert-success mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been archived successfully!</strong>
            </div>
        <?php endif; ?>

        <?php
        if(!$result){
            echo '<div class="alert alert-danger mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Database Error:</strong> ' . htmlspecialchars(mysqli_error($mysqli)) . '
            </div>';
        } elseif(mysqli_num_rows($result) == 0){
            echo '<div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem; display: block;"></i>
                    <h5 class="text-muted mb-2">No Completed Orders Found</h5>
                    <p class="text-muted mb-0">Completed orders will appear here.</p>
                </div>
            </div>';
        } else {
            $total_completed = mysqli_num_rows($result);
        ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $total_completed; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-check-circle me-2"></i>Total Completed Orders
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $budget_sql = "SELECT SUM(c.budget) as total_budget 
                           FROM `Order` o
                           LEFT JOIN `Client` c ON o.clientid = c.clientid
                           LEFT JOIN `OrderDelivery` op ON o.orderid = op.orderid
                           WHERE (o.ostatus = 'complete' OR o.ostatus = 'complete')
                           AND op.managerid = ?";
            $budget_stmt = mysqli_prepare($mysqli, $budget_sql);
            mysqli_stmt_bind_param($budget_stmt, "i", $user_id);
            mysqli_stmt_execute($budget_stmt);
            $budget_result = mysqli_stmt_get_result($budget_stmt);
            $budget_row = mysqli_fetch_assoc($budget_result);
            $total_budget = $budget_row['total_budget'] ?? 0;
            $avg_budget = $total_completed > 0 ? $total_budget / $total_completed : 0;
            ?>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($total_budget, 2); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-dollar-sign me-2"></i>Total Budget
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($avg_budget, 2); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-chart-line me-2"></i>Average Budget
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th><i class="fas fa-hashtag me-2"></i>Order ID</th>
                        <th><i class="fas fa-calendar me-2"></i>Order Date</th>
                        <th><i class="fas fa-user me-2"></i>Client</th>
                        <th><i class="fas fa-dollar-sign me-2"></i>Budget</th>
                        <th><i class="fas fa-image me-2"></i>Design</th>
                        <th><i class="fas fa-file-alt me-2"></i>Requirements</th>
                        <th><i class="fas fa-info-circle me-2"></i>Status</th>
                        <th><i class="fas fa-clock me-2"></i>Completed Date</th>
                        <th><i class="fas fa-cogs me-2"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong>
                        </td>
                        <td>
                            <?php echo date('Y-m-d', strtotime($row["odate"])); ?>
                        </td>
                        <td>
                            <div>
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <br>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td>
                            <span style="color: #27ae60; font-weight: 600;">$<?php echo number_format($row["client_budget"], 2); ?></span>
                        </td>
                        <td>
                            <div>
                                <small>Design #<?php echo htmlspecialchars($row["designid"] ?? 'N/A'); ?></small>
                                <br>
                                <small class="text-muted">$<?php echo number_format($row["design_price"] ?? 0, 2); ?></small>
                            </div>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 50)) . (strlen($row["Requirements"] ?? '') > 50 ? '...' : ''); ?>
                            </small>
                        </td>
                        <td>
                            <span class="status-badge status-completed">
                                <i class="fas fa-check-circle me-1"></i>Completed
                            </span>
                        </td>
                        <td>
                            <small>
                                <?php 
                                if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                    echo date('Y-m-d', strtotime($row["OrderFinishDate"]));
                                } else {
                                    echo '<span class="text-muted">N/A</span>';
                                }
                                ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button onclick="viewOrder('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                        class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <button onclick="archiveOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-secondary">
                                    <i class="fas fa-archive me-1"></i>Archive
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php
        }
        
        mysqli_free_result($result);
        if(isset($budget_result)) mysqli_free_result($budget_result);
        if(isset($stmt)) mysqli_stmt_close($stmt);
        if(isset($budget_stmt)) mysqli_stmt_close($budget_stmt);
        if(isset($mysqli) && $mysqli) {
            mysqli_close($mysqli);
        }
        ?>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <div class="btn-group">
                <button onclick="printThisPage()" class="btn btn-primary">
                    <i class="fas fa-print me-2"></i>Print This Page
                </button>
                <a href="Manager_MyOrder.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Orders Manager
                </a>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewOrder(orderId) {
            window.location.href = 'Order_Edit.php?id=' + encodeURIComponent(orderId);
        }

        function archiveOrder(orderId) {
            if(confirm('Are you sure you want to archive order #' + orderId + '?\n\nThis action cannot be undone.')) {
                window.location.href = 'Manager_archive_order.php?id=' + orderId;
            }
        }
        
        function printThisPage() {
            window.print();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('keydown', function(e) {
                if(e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printThisPage();
                }

                if(e.key === 'Escape') {
                    window.location.href = 'Manager_MyOrder.php';
                }
            });
        });
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>
