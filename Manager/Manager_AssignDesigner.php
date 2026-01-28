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

// Handle AJAX assignment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_designer') {
    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    $designer_id = isset($_POST['designer_id']) ? (int)$_POST['designer_id'] : 0;
    
    if ($order_id > 0 && $designer_id > 0) {
        // Get the client's budget to create a design
        $budget_sql = "SELECT o.orderid, o.clientid, c.budget, d.designid 
                      FROM `Order` o 
                      LEFT JOIN `Client` c ON o.clientid = c.clientid 
                      LEFT JOIN `Design` d ON o.designid = d.designid 
                      WHERE o.orderid = $order_id";
        $budget_result = mysqli_query($mysqli, $budget_sql);
        $order_info = mysqli_fetch_assoc($budget_result);
        
        if ($order_info) {
            // If design doesn't exist, create one
            if (empty($order_info['designid'])) {
                $insert_design_sql = "INSERT INTO Design (designerid, tag, expect_price, created_date) 
                                     VALUES ($designer_id, 'Order #$order_id Design', " . floatval($order_info['budget']) . ", NOW())";
                if (mysqli_query($mysqli, $insert_design_sql)) {
                    $design_id = mysqli_insert_id($mysqli);
                    
                    // Update order with the new design
                    $update_order_sql = "UPDATE `Order` SET designid = $design_id, ostatus = 'Designing' WHERE orderid = $order_id";
                    if (mysqli_query($mysqli, $update_order_sql)) {
                        // Create schedule record
                        $schedule_sql = "INSERT INTO Schedule (orderid, DesignFinishDate) 
                                        VALUES ($order_id, DATE_ADD(NOW(), INTERVAL 14 DAY))";
                        mysqli_query($mysqli, $schedule_sql);
                        
                        echo json_encode(['success' => true, 'message' => 'Designer assigned successfully']);
                        exit;
                    }
                }
            } else {
                // Design already exists, just update the designer
                $update_design_sql = "UPDATE Design SET designerid = $designer_id WHERE designid = " . $order_info['designid'];
                if (mysqli_query($mysqli, $update_design_sql)) {
                    $update_order_sql = "UPDATE `Order` SET ostatus = 'Designing' WHERE orderid = $order_id";
                    mysqli_query($mysqli, $update_order_sql);
                    
                    echo json_encode(['success' => true, 'message' => 'Designer assigned successfully']);
                    exit;
                }
            }
        }
    }
    echo json_encode(['success' => false, 'message' => 'Failed to assign designer']);
    exit;
}

// Get search and filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'Pending';

// Build where conditions
$where_conditions = array();
$where_conditions[] = "o.designid IS NULL OR d.designerid IS NULL"; // Orders without designer assignment

if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($mysqli, $status_filter);
    $where_conditions[] = "o.ostatus = '$status_filter'";
}

if (!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get orders pending designer assignment
$orders_sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus,
                      c.clientid, c.cname as client_name, c.budget,
                      d.designid, d.designerid, des_assigned.dname as assigned_designer
               FROM `Order` o
               LEFT JOIN `Client` c ON o.clientid = c.clientid
               LEFT JOIN `Design` d ON o.designid = d.designid
               LEFT JOIN `Designer` des_assigned ON d.designerid = des_assigned.designerid
               $where_clause
               ORDER BY o.odate DESC";

$orders_result = mysqli_query($mysqli, $orders_sql);

// Get available designers under this manager
$designers_sql = "SELECT designerid, dname, status FROM Designer WHERE managerid = $user_id ORDER BY status ASC, dname ASC";
$designers_result = mysqli_query($mysqli, $designers_sql);
$designers = array();
while ($designer = mysqli_fetch_assoc($designers_result)) {
    $designers[] = $designer;
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as pending_assignment,
                COUNT(DISTINCT CASE WHEN o.designid IS NOT NULL AND d.designerid IS NOT NULL THEN o.orderid END) as assigned,
                COUNT(DISTINCT CASE WHEN o.ostatus = 'Designing' THEN o.orderid END) as in_progress
               FROM `Order` o
               LEFT JOIN `Design` d ON o.designid = d.designid
               WHERE (o.designid IS NULL OR d.designerid IS NULL)";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Assign Designer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        .order-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .order-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .designer-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            background: #e3f2fd;
            color: #1976d2;
        }
        .designer-badge.assigned {
            background: #c8e6c9;
            color: #388e3c;
        }
        .no-designer {
            background: #fff3cd;
            color: #856404;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .stat-box .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        .stat-box .stat-label {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-user-tie me-2"></i>Assign Designer to Order
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-box" style="background: #fff3cd;">
                    <div class="stat-number" style="color: #ff9800;"><?php echo $stats['pending_assignment'] ?? 0; ?></div>
                    <div class="stat-label">Pending Assignment</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box" style="background: #e8f5e9;">
                    <div class="stat-number" style="color: #4caf50;"><?php echo $stats['assigned'] ?? 0; ?></div>
                    <div class="stat-label">Designer Assigned</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box" style="background: #e3f2fd;">
                    <div class="stat-number" style="color: #2196f3;"><?php echo count($designers); ?></div>
                    <div class="stat-label">Available Designers</div>
                </div>
            </div>
        </div>

        <!-- Search and Filter Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <form method="get" class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Search order ID, client name, or requirements..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <form method="get" class="input-group">
                            <select class="form-select" name="status" onchange="this.form.submit();">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Designing" <?php echo $status_filter === 'Designing' ? 'selected' : ''; ?>>Designing</option>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders List -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Orders Requiring Designer Assignment
                </h5>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($orders_result) > 0): ?>
                    <div class="row">
                        <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card order-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-file-contract me-1"></i>Order #<?php echo $order['orderid']; ?>
                                            </h6>
                                            <span class="badge <?php echo $order['designerid'] ? 'bg-success' : 'bg-warning'; ?>">
                                                <?php echo $order['ostatus'] ?? 'Pending'; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <p class="mb-1">
                                                <strong>Client:</strong> <?php echo htmlspecialchars($order['client_name']); ?>
                                            </p>
                                            <p class="mb-1">
                                                <strong>Budget:</strong> HK$<?php echo number_format($order['budget'], 2); ?>
                                            </p>
                                            <p class="mb-1">
                                                <strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['odate'])); ?>
                                            </p>
                                            <p class="mb-2 small text-muted">
                                                <strong>Requirements:</strong> <?php echo htmlspecialchars(substr($order['Requirements'], 0, 100)) . (strlen($order['Requirements']) > 100 ? '...' : ''); ?>
                                            </p>
                                        </div>

                                        <!-- Current Designer Status -->
                                        <div class="mb-3">
                                            <?php if ($order['designerid']): ?>
                                                <span class="designer-badge assigned">
                                                    <i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($order['assigned_designer']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="designer-badge no-designer">
                                                    <i class="fas fa-exclamation-circle me-1"></i>No Designer Assigned
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Designer Selection Dropdown -->
                                        <div class="mb-3">
                                            <label class="form-label small mb-2">Select Designer:</label>
                                            <div class="input-group">
                                                <select class="form-select form-select-sm designer-select" 
                                                        data-order-id="<?php echo $order['orderid']; ?>">
                                                    <option value="">-- Choose Designer --</option>
                                                    <?php foreach ($designers as $designer): ?>
                                                        <option value="<?php echo $designer['designerid']; ?>" 
                                                                <?php echo ($order['designerid'] == $designer['designerid']) ? 'selected' : ''; ?>
                                                                data-status="<?php echo $designer['status']; ?>">
                                                            <?php echo htmlspecialchars($designer['dname']); ?> 
                                                            (<?php echo $designer['status'] === 'Available' ? '✓ Available' : '⦿ Busy'; ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-primary assign-btn" data-order-id="<?php echo $order['orderid']; ?>">
                                                    <i class="fas fa-check me-1"></i>Assign
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Action Button -->
                                        <a href="Manager_view_order.php?orderid=<?php echo $order['orderid']; ?>" 
                                           class="btn btn-sm btn-outline-primary w-100">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        No orders require designer assignment at this time.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const assignBtns = document.querySelectorAll('.assign-btn');
            
            assignBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const orderId = this.dataset.orderId;
                    const selectElement = document.querySelector(`.designer-select[data-order-id="${orderId}"]`);
                    const designerId = selectElement.value;
                    
                    if (!designerId) {
                        alert('Please select a designer');
                        return;
                    }
                    
                    // Disable button and show loading
                    btn.disabled = true;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Assigning...';
                    
                    // Send AJAX request
                    fetch('Manager_AssignDesigner.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=assign_designer&order_id=${orderId}&designer_id=${designerId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success message
                            const card = btn.closest('.order-card');
                            const alertDiv = document.createElement('div');
                            alertDiv.className = 'alert alert-success alert-dismissible fade show';
                            alertDiv.innerHTML = `
                                <i class="fas fa-check-circle me-2"></i>${data.message}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            `;
                            card.querySelector('.card-body').insertBefore(alertDiv, card.querySelector('.card-body').firstChild);
                            
                            // Refresh page after 2 seconds
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            alert('Error: ' + data.message);
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to assign designer');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
                });
            });
        });
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>
