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

// Get status filter parameter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// Build query conditions
$where_conditions = array();

if($status_filter != 'all') {
    $status_filter = mysqli_real_escape_string($mysqli, $status_filter);
    $where_conditions[] = "o.ostatus = '$status_filter'";
}

if(!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "d.tag LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build SQL query - FIXED: Added Designer filter to show only designs linked to this manager
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.budget,
               d.designid, d.expect_price as design_price, d.tag as design_tag,
               s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($mysqli, $sql);

// Get total count - FIXED: Added Designer filter
$count_sql = "SELECT COUNT(DISTINCT o.orderid) as total 
              FROM `Order` o
              LEFT JOIN `Design` d ON o.designid = d.designid
              INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id";
$count_result = mysqli_query($mysqli, $count_sql);
$count_row = mysqli_fetch_assoc($count_result);
$total_orders = $count_row['total'];

// Get statistics - FIXED: Added Designer filter
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as total_orders,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget,
                SUM(CASE WHEN o.ostatus = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN o.ostatus = 'Designing' THEN 1 ELSE 0 END) as designing_count,
                SUM(CASE WHEN o.ostatus = 'Completed' THEN 1 ELSE 0 END) as completed_count
               FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `Design` d ON o.designid = d.designid
              INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Total Orders</title>
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
            <i class="fas fa-list me-2"></i>Total Orders
        </div>

        <!-- Search Card -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-search me-2"></i>Search Orders
                </h5>
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search orders by ID, client name, or tags..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                    <?php if(!empty($search)): ?>
                        <a href="?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Status Filter Card -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-filter me-2"></i>Filter by Status
                </h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="?status=all" class="btn btn-sm <?php echo $status_filter == 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fas fa-list me-1"></i>All Orders
                    </a>
                    <a href="?status=Pending" class="btn btn-sm <?php echo $status_filter == 'Pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                        <i class="fas fa-hourglass-half me-1"></i>Pending
                    </a>
                    <a href="?status=Designing" class="btn btn-sm <?php echo $status_filter == 'Designing' ? 'btn-info' : 'btn-outline-info'; ?>">
                        <i class="fas fa-pencil-alt me-1"></i>Designing
                    </a>
                    <a href="?status=Completed" class="btn btn-sm <?php echo $status_filter == 'Completed' ? 'btn-success' : 'btn-outline-success'; ?>">
                        <i class="fas fa-check-circle me-1"></i>Completed
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #3498db; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $stats['total_orders'] ?? 0; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-list me-1"></i>Total Orders
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($stats['total_budget'] ?? 0, 0); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-dollar-sign me-1"></i>Total Budget
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #f39c12; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $stats['pending_count'] ?? 0; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-hourglass-half me-1"></i>Pending
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #3498db; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $stats['designing_count'] ?? 0; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-pencil-alt me-1"></i>Designing
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #27ae60; font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo $stats['completed_count'] ?? 0; ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-check-circle me-1"></i>Completed
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <div style="font-size: 2rem; color: #9b59b6; font-weight: 700; margin-bottom: 0.5rem;">
                            $<?php echo number_format($stats['avg_budget'] ?? 0, 0); ?>
                        </div>
                        <div style="color: #7f8c8d; font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-chart-line me-1"></i>Average
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if(!$result): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Database Error:</strong> <?php echo htmlspecialchars(mysqli_error($mysqli)); ?>
            </div>
        <?php elseif($total_orders == 0): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem; display: block;"></i>
                    <h5 class="text-muted mb-2">No Orders Found</h5>
                    <p class="text-muted mb-0">Try adjusting your search criteria or clear the search.</p>
                </div>
            </div>
        <?php else: ?>

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
                        <th><i class="fas fa-file-alt me-2"></i>Requirement</th>
                        <th><i class="fas fa-info-circle me-2"></i>Status</th>
                        <th><i class="fas fa-clock me-2"></i>Finish Date</th>
                        <th><i class="fas fa-cogs me-2"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): 
                        // Determine status class
                        $status_class = '';
                        switch($row["ostatus"]) {
                            case 'Completed': $status_class = 'status-completed'; break;
                            case 'Designing': $status_class = 'status-designing'; break;
                            case 'Pending': $status_class = 'status-pending'; break;
                            default: $status_class = 'status-pending';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($row["odate"])); ?></td>
                        <td>
                            <div>
                                <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                <br>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                            </div>
                        </td>
                        <td>
                            <strong class="text-success">$<?php echo number_format($row["budget"], 2); ?></strong>
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
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($row["ostatus"]); ?>
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
                                <a href="Manager_MyOrder_TotalOrder_Edit.php?id=<?php echo $row["orderid"]; ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </a>
                                <button onclick="viewOrder('<?php echo htmlspecialchars($row['orderid']); ?>')" 
                                        class="btn btn-sm btn-secondary">
                                    <i class="fas fa-eye me-1"></i>View
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
                    <strong>Showing <?php echo min($total_orders, 50); ?> of <?php echo $total_orders; ?> orders</strong>
                    <?php if($status_filter != 'all'): ?>
                        <p class="text-muted mb-0">Filtered by status: <?php echo htmlspecialchars($status_filter); ?></p>
                    <?php endif; ?>
                </div>
                <div class="btn-group">
                    <button onclick="printPage()" class="btn btn-outline-primary">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="Manager_MyOrder.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Orders Manager
            </a>
            <small class="text-muted">Last updated: <?php echo date('Y-m-d H:i:s'); ?></small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + encodeURIComponent(orderId);
    }
    
    function exportToCSV() {
        const status = "<?php echo $status_filter; ?>";
        const search = "<?php echo urlencode($search); ?>";
        window.location.href = 'export_orders.php?status=' + status + '&search=' + search;
    }
    
    function printPage() {
        window.print();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('input[name="search"]');
        if(searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    this.form.submit();
                }
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F focus search box
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl+E export
            if(e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
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
        
        // Table row click effect
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
        
        // Highlight search results
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search');
        if(searchTerm) {
            highlightSearchTerm(searchTerm);
        }
    });
    
    function highlightSearchTerm(term) {
        const table = document.querySelector('.table');
        if(!table || !term) return;
        
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
            if(node.parentNode.nodeName !== 'SCRIPT' && node.parentNode.nodeName !== 'STYLE' && 
               node.parentNode.className !== 'btn-group') {
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
if(isset($result)) mysqli_free_result($result);
if(isset($count_result)) mysqli_free_result($count_result);
if(isset($stats_result)) mysqli_free_result($stats_result);
mysqli_close($mysqli);
?>
