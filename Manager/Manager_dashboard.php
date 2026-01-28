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
$user_name = $user['name'] ?? 'Manager';

// Get search and filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build where conditions
$where_conditions = array();

if ($status_filter !== 'all') {
    $status_filter_escaped = mysqli_real_escape_string($mysqli, $status_filter);
    $where_conditions[] = "o.ostatus = '$status_filter_escaped'";
}

if (!empty($search)) {
    $search_escaped = mysqli_real_escape_string($mysqli, $search);
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search_escaped%'";
    $search_conditions[] = "c.cname LIKE '%$search_escaped%'";
    $search_conditions[] = "o.Requirements LIKE '%$search_escaped%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total orders count
$total_sql = "SELECT COUNT(DISTINCT o.orderid) as total
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `Design` d ON o.designid = d.designid
              LEFT JOIN `Designer` des ON d.designerid = des.designerid
              $where_clause";
$total_result = mysqli_query($mysqli, $total_sql);
$total_row = mysqli_fetch_assoc($total_result);
$total_orders = $total_row['total'];
$total_pages = ceil($total_orders / $limit);

// Get orders for this manager (with designer assignment)
$orders_sql = "SELECT o.orderid, o.odate, o.ostatus, o.Requirements, o.budget,
                      c.clientid, c.cname as client_name,
                      d.designid, d.designerid, des.dname as designer_name, des.status as designer_status
               FROM `Order` o
               LEFT JOIN `Client` c ON o.clientid = c.clientid
               LEFT JOIN `Design` d ON o.designid = d.designid
               LEFT JOIN `Designer` des ON d.designerid = des.designerid
               $where_clause
               ORDER BY o.odate DESC
               LIMIT $limit OFFSET $offset";

$orders_result = mysqli_query($mysqli, $orders_sql);

if (!$orders_result) {
    die('Database Error: ' . mysqli_error($mysqli));
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as total_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'Pending' THEN o.orderid END) as pending_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'Designing' THEN o.orderid END) as designing_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'Completed' THEN o.orderid END) as completed_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'Rejected' THEN o.orderid END) as rejected_orders,
                COUNT(DISTINCT CASE WHEN d.designerid IS NOT NULL THEN o.orderid END) as assigned_orders,
                COUNT(DISTINCT CASE WHEN d.designerid IS NULL THEN o.orderid END) as unassigned_orders,
                SUM(CASE WHEN o.ostatus IN ('Pending', 'Designing') THEN o.budget ELSE 0 END) as active_budget,
                COUNT(DISTINCT des.designerid) as total_designers
               FROM `Order` o
               LEFT JOIN `Design` d ON o.designid = d.designid
               LEFT JOIN `Designer` des ON d.designerid = des.designerid";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);

// Get designer status counts
$designer_stats_sql = "SELECT 
                        COUNT(CASE WHEN status = 'Available' THEN 1 END) as available_designers,
                        COUNT(CASE WHEN status = 'Busy' THEN 1 END) as busy_designers
                       FROM Designer WHERE managerid = $user_id";
$designer_stats_result = mysqli_query($mysqli, $designer_stats_sql);
$designer_stats = mysqli_fetch_assoc($designer_stats_result);
// Get status color mapping
$status_colors = array(
    'Pending' => 'warning',
    'Designing' => 'info',
    'Completed' => 'success',
    'Rejected' => 'danger',
    'Awaiting Confirmation' => 'secondary'
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Manager Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        .stat-card {
            border-radius: 8px;
            padding: 20px;
            color: white;
            text-align: center;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .stat-bg-primary,
        .stat-bg-success,
        .stat-bg-warning,
        .stat-bg-info,
        .stat-bg-danger {
            background: white;
        }

        .order-table {
            font-size: 0.95rem;
        }

        .order-table thead {
            background: #f8f9fa;
        }

        .order-table tbody tr {
            transition: all 0.3s ease;
        }

        .order-table tbody tr:hover {
            background: #f0f0f0;
        }

        .badge-custom {
            font-size: 0.85rem;
            padding: 6px 10px;
        }

        .no-data-message {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .pagination-custom {
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-xl mt-4">
        <!-- Page Title -->
        <div class="page-title mb-4">
            <i class="fas fa-chart-line me-2"></i>Manager Dashboard
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card stat-bg-primary">
                    <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card stat-bg-warning">
                    <div class="stat-number"><?php echo $stats['pending_orders']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card stat-bg-info">
                    <div class="stat-number"><?php echo $stats['designing_orders']; ?></div>
                    <div class="stat-label">Designing</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card stat-bg-success">
                    <div class="stat-number"><?php echo $stats['completed_orders']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card stat-bg-danger">
                    <div class="stat-number"><?php echo $stats['rejected_orders']; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $designer_stats['available_designers']; ?>/<?php echo ($designer_stats['available_designers'] + $designer_stats['busy_designers']); ?>
                    </div>
                    <div class="stat-label">Available Designers</div>
                </div>
            </div>
        </div>

        <!-- Quick Stats Row -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="card-title">Assigned Orders</h6>
                        <h3 class="text-success"><?php echo $stats['assigned_orders']; ?></h3>
                        <small class="text-muted">Designer Assigned</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="card-title">Unassigned Orders</h6>
                        <h3 class="text-warning"><?php echo $stats['unassigned_orders']; ?></h3>
                        <small class="text-muted">Needs Designer</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="card-title">Active Budget</h6>
                        <h3 class="text-primary">HK$<?php echo number_format($stats['active_budget'] ?? 0, 0); ?></h3>
                        <small class="text-muted">Pending & Designing</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8 mb-2 mb-md-0">
                        <form method="get" class="input-group">
                            <input type="text" class="form-control" name="search"
                                placeholder="Search order ID, client name, or requirements..."
                                value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                            <?php if (!empty($search)): ?>
                                <a href="Manager_Dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-redo me-1"></i>Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <form method="get" class="input-group">
                            <select class="form-select" name="status" onchange="this.form.submit();">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status
                                </option>
                                <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>
                                    Pending</option>
                                <option value="Designing" <?php echo $status_filter === 'Designing' ? 'selected' : ''; ?>>
                                    Designing</option>
                                <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>
                                    Completed</option>
                                <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>
                                    Rejected</option>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Order List
                    <span class="badge bg-light text-dark float-end">Total: <?php echo $total_orders; ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (mysqli_num_rows($orders_result) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover order-table mb-0">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Designer</th>
                                    <th>Budget</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?php echo $order['orderid']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-user-circle text-secondary me-2" style="font-size: 24px;"></i>
                                                <div>
                                                    <div><strong><?php echo htmlspecialchars($order['client_name']); ?></strong>
                                                    </div>
                                                    <small class="text-muted">ID: <?php echo $order['clientid']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($order['odate'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($order['designerid']): ?>
                                                <div>
                                                    <span class="badge bg-success badge-custom">
                                                        <i
                                                            class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($order['designer_name']); ?>
                                                    </span>
                                                    <br>
                                                    <small
                                                        class="badge <?php echo $order['designer_status'] === 'Available' ? 'bg-info' : 'bg-secondary'; ?> mt-1">
                                                        <?php echo $order['designer_status'] === 'Available' ? '<i class="fas fa-circle me-1"></i>Available' : '<i class="fas fa-circle me-1"></i>Busy'; ?>
                                                    </small>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-warning badge-custom">
                                                    <i class="fas fa-exclamation-circle me-1"></i>Unassigned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>HK$<?php echo number_format($order['budget'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <span
                                                class="badge bg-<?php echo $status_colors[$order['ostatus']] ?? 'secondary'; ?> badge-custom">
                                                <?php echo $order['ostatus']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="Manager_view_order.php?orderid=<?php echo $order['orderid']; ?>"
                                                    class="btn btn-outline-primary" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="Order_Management.php?orderid=<?php echo $order['orderid']; ?>"
                                                    class="btn btn-outline-info" title="Manage">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-custom">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?>">
                                            <i class="fas fa-chevron-left"></i> First
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link"
                                            href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?>">
                                            Last <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-data-message">
                        <i class="fas fa-inbox"
                            style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                        <p>No orders found</p>
                        <small class="text-muted">Try adjusting your search or filter criteria</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Action Links -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <i class="fas fa-calendar-alt fa-2x text-info mb-2"></i>
                    <h6 class="card-title">Schedule</h6>
                    <a href="Manager_Schedule.php" class="btn btn-sm btn-info">
                        <i class="fas fa-arrow-right me-1"></i>View Schedule
                    </a>
                </div>
            </div>
        </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>