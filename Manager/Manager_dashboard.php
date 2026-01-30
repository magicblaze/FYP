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
                COUNT(DISTINCT CASE WHEN o.ostatus = 'waiting confirm' THEN o.orderid END) as pending_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'designing' THEN o.orderid END) as designing_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'complete' THEN o.orderid END) as completed_orders,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'reject' THEN o.orderid END) as rejected_orders,
                COUNT(DISTINCT CASE WHEN d.designerid IS NOT NULL THEN o.orderid END) as assigned_orders,
                COUNT(DISTINCT CASE WHEN d.designerid IS NULL THEN o.orderid END) as unassigned_orders,
                SUM(CASE WHEN o.ostatus IN ('waiting confirm', 'designing') THEN o.budget ELSE 0 END) as active_budget,
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Manager Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .stat-card {
            border-radius: 8px;
            padding: 20px;
            background-color: white;
            color: #444;
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
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['pending_orders']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['designing_orders']; ?></div>
                    <div class="stat-label">Designing</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['completed_orders']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
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