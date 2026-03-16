<?php
require_once __DIR__ . '/config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php?redirect=project_management.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'];
$userId = $user['id'];
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

// Build query based on role
$query = "SELECT o.*, c.cname as client_name, d.designName, s.OrderFinishDate, s.DesignFinishDate 
          FROM `Order` o
          LEFT JOIN Client c ON o.clientid = c.clientid
          LEFT JOIN Design d ON o.designid = d.designid
          LEFT JOIN Schedule s ON o.orderid = s.orderid";

$whereClauses = [];
$params = [];
$types = "";

if ($role === 'client') {
    $whereClauses[] = "o.clientid = ?";
    $params[] = $userId;
    $types .= "i";
} elseif ($role === 'designer') {
    $whereClauses[] = "d.designerid = ?";
    $params[] = $userId;
    $types .= "i";
} elseif ($role === 'contractor') {
    $query .= " JOIN Order_Contractors oc ON o.orderid = oc.orderid";
    $whereClauses[] = "oc.contractorid = ?";
    $params[] = $userId;
    $types .= "i";
} elseif ($role === 'supplier') {
    $query .= " JOIN OrderReference orf ON o.orderid = orf.orderid 
                JOIN Product p ON orf.productid = p.productid";
    $whereClauses[] = "p.supplierid = ?";
    $params[] = $userId;
    $types .= "i";
}

if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}

$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);

// Define display categories for Kanban
$display_categories = [
    'waiting confirm',
    'designing',
    'In Progress',
    'complete',
    'rejected'
];

// Map database statuses to display categories
$status_mapping = [
    'waiting confirm' => 'waiting confirm',
    'designing' => 'designing',
    'reviewing design proposal' => 'In Progress',
    'waiting for review design' => 'In Progress',
    'drafting 2nd proposal' => 'In Progress',
    'waiting client review' => 'In Progress',
    'waiting client payment' => 'In Progress',
    'complete' => 'complete',
    'rejected' => 'rejected'
];

// Group orders by display category for Kanban
$kanbanData = [];
foreach ($display_categories as $cat) {
    $kanbanData[$cat] = [];
}

foreach ($orders as $order) {
    $db_status = $order['ostatus'];
    $display_cat = $status_mapping[$db_status] ?? $db_status;
    
    if (!isset($kanbanData[$display_cat])) {
        $kanbanData[$display_cat] = [];
    }
    $kanbanData[$display_cat][] = $order;
}

// Get statistics for the header cards
$total_orders = count($orders);
$pending_count = 0;
$in_progress_count = 0;
$done_count = 0;

foreach($orders as $o) {
    $s = strtolower($o['ostatus']);
    if (in_array($s, ['waiting confirm', 'designing'])) {
        $pending_count++;
    } elseif (in_array($s, ['reviewing design proposal', 'waiting for review design', 'drafting 2nd proposal', 'waiting client review', 'waiting client payment'])) {
        $in_progress_count++;
    } elseif (in_array($s, ['complete'])) {
        $done_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Project Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        /* Layout: Using standard container width like Manager_dashboard.php */
        main.container {
            max-width: 100%; /* Standard Bootstrap container width */
            margin: 0 auto;
            padding: 20px;
        }

        .stat-card {
            width: 100%;
            border-radius: 6px;
            padding: 10px 14px;
            background-color: white;
            color: #444;
            text-align: center;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08 );
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-number {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .stat-label {
            font-size: 11px;
            opacity: 0.9;
        }

        /* Kanban Container: No horizontal scroll, use flex-wrap or auto-sizing */
        .kanban-container {
            display: flex;
            flex-wrap: wrap; /* Allow columns to wrap if they don't fit */
            gap: 15px;
            padding-bottom: 30px;
            align-items: flex-start;
            justify-content: center; /* Center columns in the middle area */
        }

        .kanban-column {
            background-color: #f8f9fa;
            border-radius: 10px;
            flex: 1; /* Allow columns to grow and shrink equally */
            min-width: 250px; /* Increased minimum width for better stability */
            max-width: 320px; /* Increased maximum width */
            display: flex;
            flex-direction: column;
            max-height: 700px; /* Slightly taller */
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border: 1px solid #dee2e6;
            transition: all 0.3s ease; /* Smooth transition for any layout changes */
        }

        .column-header {
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0;
        }

        .column-title {
            font-weight: 600;
            font-size: 12px;
            color: #2c3e50;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .task-count {
            background: #e9ecef;
            border-radius: 12px;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: bold;
            color: #495057;
        }

        .task-list {
            padding: 10px;
            overflow-y: auto;
            flex-grow: 1;
        }

        /* Sub-group styling for In Progress */
        .sub-group-header {
            font-size: 10px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            margin: 12px 0 6px 0;
            padding-bottom: 3px;
            border-bottom: 1px dashed #ddd;
            display: flex;
            justify-content: space-between;
        }

        .sub-group-header:first-child {
            margin-top: 0;
        }

        .task-card {
            background-color: white;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #dee2e6;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            background: #fcfcfc;
        }

        .task-id {
            font-size: 11px;
            font-weight: 600;
            color: #3498db;
            margin-bottom: 5px;
        }

        .task-title {
            font-weight: 600;
            font-size: 13px;
            color: #2c3e50;
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: #6c757d;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #f1f1f1;
        }

        .task-user {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .user-avatar {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #e9ecef;
            color: #495057;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid #dee2e6;
        }

        .task-date {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .task-date.overdue { color: #dc3545; font-weight: bold; }

        /* Hide horizontal scrollbar if any */
        .kanban-container::-webkit-scrollbar { display: none; }
        .kanban-container { -ms-overflow-style: none; scrollbar-width: none; }

        /* Embed-specific overrides to ensure layout stability */
        <?php if ($isEmbed): ?>
        body {
            background: #fff !important;
            overflow-x: hidden;
        }

        main.container {
            margin-top: 0 !important;
            max-width: 100% !important;
            width: 100% !important;
            padding: 12px !important;
        }

        /* In right-side app panel, stack Kanban columns vertically for readability */
        .kanban-container {
            display: flex !important;
            flex-direction: column !important;
            flex-wrap: nowrap !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
            gap: 15px !important;
            width: 100% !important;
        }

        .kanban-column {
            min-width: 100% !important;
            max-width: 100% !important;
            max-height: none !important;
            flex: none !important;
        }

        .task-list {
            max-height: 400px !important; /* Slightly more space */
        }

        /* Ensure stat cards also look good in embed */
        .stat-card {
            margin-bottom: 15px !important;
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <?php if (!$isEmbed): ?>
        <?php include_once __DIR__ . '/includes/header.php'; ?>
    <?php endif; ?>

    <main class="container mt-4">

        <div class="row mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?= $total_orders ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?= $pending_count ?></div>
                    <div class="stat-label">Pending / Designing</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?= $in_progress_count ?></div>
                    <div class="stat-label">In Process</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?= $done_count ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
        </div>

        <div class="kanban-container">
            <?php foreach ($kanbanData as $display_status => $tasks): ?>
            <div class="kanban-column">
                <div class="column-header">
                    <span class="column-title" title="<?= ucfirst(htmlspecialchars($display_status)) ?>"><?= ucfirst(htmlspecialchars($display_status)) ?></span>
                    <span class="task-count"><?= count($tasks) ?></span>
                </div>
                <div class="task-list">
                    <?php 
                    if ($display_status === 'In Progress') {
                        // Group by original status for In Progress column
                        $subGroups = [];
                        foreach ($tasks as $t) {
                            $subGroups[$t['ostatus']][] = $t;
                        }
                        
                        foreach ($subGroups as $subStatus => $subTasks): ?>
                            <div class="sub-group-header">
                                <span><?= htmlspecialchars($subStatus) ?></span>
                                <span><?= count($subTasks) ?></span>
                            </div>
                            <?php foreach ($subTasks as $task): 
                                $isOverdue = (isset($task['OrderFinishDate']) && strtotime($task['OrderFinishDate']) < time() && !in_array(strtolower($task['ostatus']), ['complete', 'rejected']));
                            ?>
                                <div class="task-card" onclick="location.href='order_full_details.php?id=<?= $task['orderid'] ?><?= $isEmbed ? '&embed=1' : '' ?>'">
                                    <div class="task-id">#<?= $task['orderid'] ?></div>
                                    <div class="task-title"><?= htmlspecialchars($task['designName'] ?? 'Custom Project Request') ?></div>
                                    <div class="task-meta">
                                        <div class="task-user">
                                            <div class="user-avatar"><?= strtoupper(substr($task['client_name'], 0, 1)) ?></div>
                                            <span><?= htmlspecialchars($task['client_name']) ?></span>
                                        </div>
                                        <div class="task-date <?= $isOverdue ? 'overdue' : '' ?>">
                                            <i class="far fa-calendar-alt"></i>
                                            <?= isset($task['OrderFinishDate']) ? date('M d', strtotime($task['OrderFinishDate'])) : 'TBD' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach;
                    } else {
                        // Normal rendering for other columns
                        foreach ($tasks as $task): 
                            $isOverdue = (isset($task['OrderFinishDate']) && strtotime($task['OrderFinishDate']) < time() && !in_array(strtolower($task['ostatus']), ['complete', 'rejected']));
                        ?>
                        <div class="task-card" onclick="location.href='order_full_details.php?id=<?= $task['orderid'] ?><?= $isEmbed ? '&embed=1' : '' ?>'">
                            <div class="task-id">#<?= $task['orderid'] ?></div>
                            <div class="task-title"><?= htmlspecialchars($task['designName'] ?? 'Custom Project Request') ?></div>
                            <div class="task-meta">
                                <div class="task-user">
                                    <div class="user-avatar"><?= strtoupper(substr($task['client_name'], 0, 1)) ?></div>
                                    <span><?= htmlspecialchars($task['client_name']) ?></span>
                                </div>
                                <div class="task-date <?= $isOverdue ? 'overdue' : '' ?>">
                                    <i class="far fa-calendar-alt"></i>
                                    <?= isset($task['OrderFinishDate']) ? date('M d', strtotime($task['OrderFinishDate'])) : 'TBD' ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach;
                    } ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$isEmbed ): ?>
        <?php include __DIR__ . '/Public/chat_widget.php'; ?>
    <?php endif; ?>
</body>
</html>
