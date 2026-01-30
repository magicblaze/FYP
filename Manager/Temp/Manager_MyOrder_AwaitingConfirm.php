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

// Safe query function with connection check
function safe_mysqli_query($mysqli, $sql) {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        die("Database connection invalid");
    }
    
    if (!method_exists($mysqli, 'ping') || $mysqli->ping() === false) {
        // Try reloading config to (re)establish connection if available
        if (file_exists(dirname(__DIR__) . '/config.php')) {
            require_once dirname(__DIR__) . '/config.php';
            global $mysqli;
            if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->ping() === false) {
                die("Database connection lost");
            }
        } else {
            die("Database connection lost");
        }
    }
    
    $result = mysqli_query($mysqli, $sql);
    if(!$result) {
        die("Database Error: " . mysqli_error($mysqli));
    }
    return $result;
}

// Get search parameter
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// Build query conditions - 修复：使用设计师关联代替OrderDelivery
$where_conditions = array(
    "(o.ostatus = 'waiting confirm' OR o.ostatus = 'waiting confirm')",
    "EXISTS (SELECT 1 FROM `Design` d 
             JOIN `Designer` des ON d.designerid = des.designerid 
             WHERE d.designid = o.designid AND des.managerid = $user_id)"
);

if(!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "c.cemail LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $search_conditions[] = "d.tag LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get all pending orders
$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone, c.budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = safe_mysqli_query($mysqli, $sql);

// Calculate statistics - 修复：使用相同的设计师关联逻辑
$stats_sql = "SELECT 
                COUNT(*) as total_pending,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `Design` d ON o.designid = d.designid
              WHERE (o.ostatus = 'waiting confirm' OR o.ostatus = 'waiting confirm')
              AND EXISTS (SELECT 1 FROM `Design` d2 
                         JOIN `Designer` des ON d2.designerid = des.designerid 
                         WHERE d2.designid = o.designid AND des.managerid = $user_id)";
$stats_result = safe_mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Awaiting Confirmation</title>
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
            <i class="fas fa-hourglass-half me-2"></i>Awaiting Confirmation Orders
        </div>

        <!-- Success Message -->
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
            <div class="alert alert-success mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been processed successfully!</strong>
                <?php if(isset($_GET['email']) && $_GET['email'] == 'sent'): ?>
                    <br><small>Email notification has been sent to the client.</small>
                <?php elseif(isset($_GET['email']) && $_GET['email'] == 'failed'): ?>
                    <br><small class="text-danger">Warning: Email notification failed to send.</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Search Card -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-search me-2"></i>Search Orders
                </h5>
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by Order ID, Client Name, Email, Requirements or Tags..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                    <?php if(!empty($search)): ?>
                        <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php
        if(mysqli_num_rows($result) == 0){
            echo '<div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem; display: block;"></i>
                    <h5 class="text-muted mb-2">No Pending Orders Found</h5>
                    <p class="text-muted mb-0">All new orders will appear here when they are submitted by clients.</p>
                </div>
            </div>';
        } else {
            $total_pending = $stats['total_pending'] ?? 0;
        ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #f39c12; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $total_pending; ?>
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
                            $<?php echo number_format($stats['total_budget'] ?? 0, 2); ?>
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
                        <div style="font-size: 2.5rem; color: #3498db; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?>
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
                        <th><i class="fas fa-clock me-2"></i>Finish Date</th>
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
                                <?php if(!empty($row["client_email"])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($row["client_email"]); ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span style="color: #27ae60; font-weight: 600;">$<?php echo number_format($row["budget"], 2); ?></span>
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
                            <span class="status-badge status-pending">
                                <i class="fas fa-hourglass-half me-1"></i><?php echo htmlspecialchars($row["ostatus"] ?? 'waiting confirm'); ?>
                            </span>
                        </td>
                        <td>
                            <small>
                                <?php 
                                if(isset($row["OrderFinishDate"]) && $row["OrderFinishDate"] != '0000-00-00 00:00:00'){
                                    echo date('Y-m-d', strtotime($row["OrderFinishDate"]));
                                } else {
                                    echo '<span class="text-muted">Not scheduled</span>';
                                }
                                ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button onclick="viewOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <button onclick="approveOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-success">
                                    <i class="fas fa-check me-1"></i>Approve
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary Card -->
        <div class="card mt-4">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <strong>Showing <?php echo mysqli_num_rows($result); ?> pending orders</strong>
                    <p class="text-muted mb-0">Total: <?php echo $total_pending; ?> orders awaiting confirmation</p>
                </div>
                <div class="btn-group">
                    <button onclick="printPage()" class="btn btn-outline">
                        <i class="fas fa-print me-2"></i>Print List
                    </button>
                </div>
            </div>
        </div>

        <?php
        }
        ?>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="Manager_MyOrder.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Orders Manager
            </a>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + orderId;
    }
    
    function approveOrder(orderId) {
        window.location.href = 'Manager_MyOrder_AwaitingConfirm_Approval.php?id=' + orderId;
    }

    function printPage() {
        window.print();
    }
    
    // Keyboard shortcuts support
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('keydown', function(e) {
            // Ctrl+F focus search box
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl+P print
            if(e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            // Esc key to go back
            if(e.key === 'Escape') {
                window.location.href = 'Manager_MyOrder.php';
            }
        });
        
        // Search box enter to submit
        const searchInput = document.querySelector('input[name="search"]');
        if(searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    document.querySelector('form').submit();
                }
            });
        }
        
        // Highlight search results
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search');
        if(searchTerm) {
            setTimeout(() => {
                highlightSearchTerm(searchTerm);
            }, 100);
        }
        
        // Add hover effects
        const tableRows = document.querySelectorAll('.table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    });
    
    function highlightSearchTerm(term) {
        const table = document.querySelector('.table');
        if(!table) return;
        
        const regex = new RegExp(`(${term})`, 'gi');
        const walker = document.createTreeWalker(
            table,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        
        let node;
        const textNodes = [];
        while(node = walker.nextNode()) {
            textNodes.push(node);
        }
        
        textNodes.forEach(node => {
            if(node.parentNode.nodeName !== 'SCRIPT' && node.parentNode.nodeName !== 'STYLE') {
                const newHTML = node.textContent.replace(regex, '<mark class="highlight">$1</mark>');
                if(newHTML !== node.textContent) {
                    const span = document.createElement('span');
                    span.innerHTML = newHTML;
                    node.parentNode.replaceChild(span, node);
                }
            }
        });
    }
    
    // Auto-check for new orders every 60 seconds
    function checkForNewOrders() {
        fetch('check_new_orders.php?manager_id=<?php echo $user_id; ?>')
            .then(response => response.json())
            .then(data => {
                if(data.newOrders > 0) {
                    showNewOrderNotification(data.newOrders);
                }
            })
            .catch(error => console.error('Error checking for new orders:', error));
    }
    
    function showNewOrderNotification(count) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info mt-3';
        notification.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-bell me-2"></i>
                    <strong>New Orders Available</strong>
                    <p class="mb-0">There are ${count} new pending order(s).</p>
                </div>
                <div class="btn-group">
                    <button onclick="location.reload()" class="btn btn-sm btn-primary">
                        <i class="fas fa-sync-alt me-1"></i>Refresh Now
                    </button>
                    <button onclick="this.parentElement.parentElement.parentElement.remove()" class="btn btn-sm btn-outline">
                        <i class="fas fa-times me-1"></i>Dismiss
                    </button>
                </div>
            </div>
        `;
        
        const mainElement = document.querySelector('main');
        const pageTitle = document.querySelector('.page-title');
        if(mainElement && pageTitle) {
            mainElement.insertBefore(notification, pageTitle.nextSibling);
        }
        
        // Auto-dismiss after 10 seconds
        setTimeout(() => {
            if(notification.parentNode) {
                notification.remove();
            }
        }, 10000);
    }
    
    // Check for new orders every 60 seconds
    setInterval(checkForNewOrders, 60000);
    </script>

    <style>
        .highlight {
            background-color: #fff3cd;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        @media print {
            header,
            .search-section,
            .stats-grid,
            .btn-group,
            .alert {
                display: none !important;
            }
            
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .page-title {
                margin-bottom: 20px !important;
            }
        }
    </style>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>