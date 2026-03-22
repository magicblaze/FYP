<?php
// supplier/ProjectWorkerManagement.php
// Dedicated page for suppliers to manage worker allocations across all projects
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$supplier_id = $user['supplierid'];

// Fetch all projects (orders) that contain this supplier's products
$projects_sql = "
    SELECT DISTINCT 
        o.orderid,
        o.odate,
        o.ostatus,
        c.cname as client_name,
        c.cemail,
        c.address,
        COUNT(DISTINCT od.productid) as product_count,
        COUNT(DISTINCT wa.workerid) as allocated_workers
    FROM `Order` o
    JOIN Client c ON o.clientid = c.clientid
    JOIN OrderDelivery od ON o.orderid = od.orderid
    JOIN Product p ON od.productid = p.productid
    LEFT JOIN workerallocation wa ON o.orderid = wa.orderid
    WHERE p.supplierid = ?
    GROUP BY o.orderid
    ORDER BY o.odate DESC
";

$projects_stmt = mysqli_prepare($mysqli, $projects_sql);
mysqli_stmt_bind_param($projects_stmt, "i", $supplier_id);
mysqli_stmt_execute($projects_stmt);
$projects_result = mysqli_stmt_get_result($projects_stmt);
$projects = [];
while ($row = mysqli_fetch_assoc($projects_result)) {
    $projects[] = $row;
}
mysqli_stmt_close($projects_stmt);

// Get total statistics
$stats_sql = "
    SELECT 
        COUNT(DISTINCT o.orderid) as total_projects,
        COUNT(DISTINCT wa.workerid) as total_allocated_workers,
        COUNT(DISTINCT w.workerid) as total_available_workers
    FROM `Order` o
    JOIN OrderDelivery od ON o.orderid = od.orderid
    JOIN Product p ON od.productid = p.productid
    LEFT JOIN workerallocation wa ON o.orderid = wa.orderid
    JOIN Worker w ON w.supplierid = ?
    WHERE p.supplierid = ?
";

$stats_stmt = mysqli_prepare($mysqli, $stats_sql);
mysqli_stmt_bind_param($stats_stmt, "ii", $supplier_id, $supplier_id);
mysqli_stmt_execute($stats_stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt));
mysqli_stmt_close($stats_stmt);

// Get available workers count
$workers_sql = "SELECT COUNT(*) as total_workers FROM Worker WHERE supplierid = ?";
$workers_stmt = mysqli_prepare($mysqli, $workers_sql);
mysqli_stmt_bind_param($workers_stmt, "i", $supplier_id);
mysqli_stmt_execute($workers_stmt);
$workers_count = mysqli_fetch_assoc(mysqli_stmt_get_result($workers_stmt));
mysqli_stmt_close($workers_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Worker Management - Supplier Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background-color: #f4f7f6;
        }

        .page-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }

        .page-header h1 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            margin-bottom: 0;
            opacity: 0.9;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            text-align: center;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            box-shadow: 0 4px 16px rgba(52, 152, 219, 0.15);
            transform: translateY(-2px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #3498db;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .project-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }

        .project-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            transform: translateX(5px);
        }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .project-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .project-client {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }

        .project-date {
            color: #95a5a6;
            font-size: 0.85rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-preparing {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-in-progress {
            background-color: #cfe2ff;
            color: #084298;
        }

        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .project-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            color: #7f8c8d;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: #2c3e50;
            font-weight: 600;
        }

        .worker-badge {
            display: inline-block;
            background: #e8f4f8;
            color: #0c5460;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn-allocate {
            background: #27ae60;
            border: none;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-allocate:hover {
            background: #229954;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
        }

        .btn-view {
            background: #3498db;
            border: none;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-view:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 3rem;
            color: #bdc3c7;
            margin-bottom: 1rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #7f8c8d;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }

        .back-btn:hover {
            color: #3498db;
            transform: translateX(-5px);
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
    </br>
    <div class="container mb-5">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>Back to Dashboard
        </a>

        <!-- Statistics Section -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total_projects'] ?? 0 ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $workers_count['total_workers'] ?? 0 ?></div>
                    <div class="stat-label">Available Workers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total_allocated_workers'] ?? 0 ?></div>
                    <div class="stat-label">Allocated Workers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= ($workers_count['total_workers'] ?? 0) - ($stats['total_allocated_workers'] ?? 0) ?></div>
                    <div class="stat-label">Unallocated Workers</div>
                </div>
            </div>
        </div>

        <!-- Projects List Section -->
        <h3 class="mb-3">Your Projects</h3>
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <div>
                            <div class="project-title">Project #<?= $project['orderid'] ?></div>
                            <div class="project-client"><i class="fas fa-user me-1"></i><?= htmlspecialchars($project['client_name']) ?></div>
                            <div class="project-date"><i class="fas fa-calendar me-1"></i><?= date('M d, Y', strtotime($project['odate'])) ?></div>
                        </div>
                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $project['ostatus'])) ?>">
                            <?= htmlspecialchars($project['ostatus']) ?>
                        </span>
                    </div>

                    <div class="project-info">
                        <div class="info-item">
                            <div class="info-label">Products</div>
                            <div class="info-value"><?= $project['product_count'] ?> items</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Allocated Workers</div>
                            <div class="info-value"><?= $project['allocated_workers'] ?> workers</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Client Email</div>
                            <div class="info-value"><?= htmlspecialchars($project['cemail']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($project['address'] ?? 'N/A') ?></div>
                        </div>
                    </div>

                    <?php if ($project['allocated_workers'] > 0): ?>
                        <div class="worker-badge">
                            <i class="fas fa-check-circle me-1"></i><?= $project['allocated_workers'] ?> worker(s) assigned
                        </div>
                    <?php else: ?>
                        <div class="worker-badge" style="background: #fff3cd; color: #856404;">
                            <i class="fas fa-exclamation-circle me-1"></i>No workers assigned yet
                        </div>
                    <?php endif; ?>

                    <div class="action-buttons">
                        <a href="WorkerAllocation.php?orderid=<?= $project['orderid'] ?>" class="btn-allocate">
                            <i class="fas fa-users"></i>Manage Workers
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="project-card">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>No Projects Found</h4>
                    <p>You don't have any active projects yet.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>
