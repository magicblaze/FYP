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

// Get search parameter
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// Build query conditions - only show cancelled orders for current manager
$where_conditions = array(
    "(o.ostatus = 'Cancelled' OR o.ostatus = 'cancelled')",
    "op.managerid = $user_id"
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

// Get all cancelled orders
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone, c.budget as client_budget,
               d.designid, d.designName as design_image, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($mysqli, $sql);

if(!$result) {
    die("Database Error: " . mysqli_error($mysqli));
}

// Calculate statistics - only for current manager's orders
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as total_cancelled,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget,
                MIN(o.odate) as earliest_cancellation,
                MAX(o.odate) as latest_cancellation
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
              WHERE (o.ostatus = 'Cancelled' OR o.ostatus = 'cancelled')
              AND op.managerid = $user_id";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Cancelled Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>

<body>
    <!-- Header Navigation (matching Manager_MyOrder.php style) -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
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
            <i class="fas fa-times-circle me-2"></i>Cancelled Orders
        </div>

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
                        <a href="Manager_MyOrder_Rejected.php" class="btn btn-secondary">
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
                    <h5 class="text-muted mb-2">No Cancelled Orders Found</h5>
                    <p class="text-muted mb-0">Orders that have been cancelled will appear here.</p>
                </div>
            </div>';
        } else {
            $total_cancelled = $stats['total_cancelled'] ?? 0;
        ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #dc3545; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $total_cancelled; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-times-circle me-2"></i>Cancelled Orders
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #dc3545; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($stats['total_budget'] ?? 0, 2); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-dollar-sign me-2"></i>Lost Revenue
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #dc3545; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-chart-line me-2"></i>Average Value
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2.5rem; color: #dc3545; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php 
                            if(isset($stats['latest_cancellation']) && $stats['latest_cancellation'] != '0000-00-00 00:00:00'){
                                echo date('M d', strtotime($stats['latest_cancellation']));
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500;">
                            <i class="fas fa-calendar me-2"></i>Latest
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
                            <span style="color: #dc3545; font-weight: 600;">$<?php echo number_format($row["client_budget"], 2); ?></span>
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
                            <span class="status-badge" style="background-color: #dc3545; color: white;">
                                <i class="fas fa-times-circle me-1"></i>Cancelled
                            </span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button onclick="viewOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <button onclick="deleteOrder(<?php echo $row['orderid']; ?>)" 
                                        class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash me-1"></i>Delete
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
                    <strong>Showing <?php echo mysqli_num_rows($result); ?> cancelled orders</strong>
                    <p class="text-muted mb-0">Total: <?php echo $total_cancelled; ?> cancelled orders</p>
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
    
    function deleteOrder(orderId) {
        if(confirm('⚠️ WARNING: Are you sure you want to permanently delete order #' + orderId + '? This action cannot be undone!')) {
            window.location.href = 'Manager_delete_order.php?id=' + orderId;
        }
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
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if(isset($stats_result)) {
    mysqli_free_result($stats_result);
}
if(isset($result)) {
    mysqli_free_result($result);
}
if(isset($mysqli) && $mysqli) {
    mysqli_close($mysqli);
}
?>
