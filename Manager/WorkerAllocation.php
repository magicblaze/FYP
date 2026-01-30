<?php
// WorkerAllocation.php
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

// Get order ID from URL parameter
$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;

if ($order_id == 0) {
    header('Location: Manager_MyOrder.php?error=invalid_order');
    exit;
}

// Initialize variables
$order_info = [];
$workers = [];
$schedule_info = [];
$error_message = '';
$success_message = '';

// 獲取訂單信息
$order_sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus,
                     c.clientid, c.cname as client_name, c.budget as client_budget,
                     d.designid, d.expect_price as design_price, d.tag as design_tag
              FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `Design` d ON o.designid = d.designid
              WHERE o.orderid = ?";
$order_stmt = mysqli_prepare($mysqli, $order_sql);
mysqli_stmt_bind_param($order_stmt, "i", $order_id);
mysqli_stmt_execute($order_stmt);
$order_result = mysqli_stmt_get_result($order_stmt);
$order_info = mysqli_fetch_assoc($order_result);
mysqli_stmt_close($order_stmt);

if (!$order_info) {
    header('Location: Manager_MyOrder.php?error=order_not_found');
    exit;
}

// 獲取訂單的排程信息
$schedule_sql = "SELECT DesignFinishDate, OrderFinishDate FROM `Schedule` WHERE orderid = ?";
$schedule_stmt = mysqli_prepare($mysqli, $schedule_sql);
mysqli_stmt_bind_param($schedule_stmt, "i", $order_id);
mysqli_stmt_execute($schedule_stmt);
$schedule_result = mysqli_stmt_get_result($schedule_stmt);
$schedule_info = mysqli_fetch_assoc($schedule_result);
mysqli_stmt_close($schedule_stmt);

// 計算可用工作日
$work_days = 0;
$work_hours_per_day = 8; // 默認每天工作8小時
$total_available_hours = 0;

if ($schedule_info && $schedule_info['DesignFinishDate'] && $schedule_info['OrderFinishDate']) {
    $design_finish = new DateTime($schedule_info['DesignFinishDate']);
    $order_finish = new DateTime($schedule_info['OrderFinishDate']);
    
    // 計算兩個日期之間的工作日天數（排除週末）
    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($design_finish, $interval, $order_finish->modify('+1 day'));
    
    foreach ($period as $day) {
        $day_of_week = $day->format('N'); // 1=Monday, 7=Sunday
        if ($day_of_week <= 5) { // 週一到週五是工作日
            $work_days++;
        }
    }
    
    $total_available_hours = $work_days * $work_hours_per_day;
}

// 獲取此訂單的現有分配（用於檢查哪些工人已分配）
$existing_allocations_sql = "SELECT wa.workerid 
                             FROM `workerallocation` wa
                             WHERE wa.orderid = ? AND wa.status != 'Completed' AND wa.status != 'Cancelled'";
$existing_stmt = mysqli_prepare($mysqli, $existing_allocations_sql);
mysqli_stmt_bind_param($existing_stmt, "i", $order_id);
mysqli_stmt_execute($existing_stmt);
$existing_allocations_result = mysqli_stmt_get_result($existing_stmt);
$existing_worker_ids = [];
while ($row = mysqli_fetch_assoc($existing_allocations_result)) {
    $existing_worker_ids[] = $row['workerid'];
}
mysqli_stmt_close($existing_stmt);

// 獲取所有工人，但排除已分配給此訂單的工人
$worker_sql = "SELECT w.workerid, w.name as wname, w.email as wemail, w.phone as wphone, 
                      w.certificate as specialty, w.image,
                      (SELECT COUNT(*) FROM `workerallocation` wa WHERE wa.workerid = w.workerid AND wa.status != 'Completed' AND wa.status != 'Cancelled') as current_assignments
               FROM `worker` w
               ORDER BY w.name";
$worker_result = mysqli_query($mysqli, $worker_sql);

if ($worker_result) {
    $all_workers = mysqli_fetch_all($worker_result, MYSQLI_ASSOC);
    mysqli_free_result($worker_result);
    
    // 過濾掉已分配給此訂單的工人
    $workers = array_filter($all_workers, function($worker) use ($existing_worker_ids) {
        return !in_array($worker['workerid'], $existing_worker_ids);
    });
    $workers = array_values($workers); // 重置索引
} else {
    $error_message = 'Database error: ' . mysqli_error($mysqli);
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['allocate_workers'])) {
    if (!isset($_POST['worker_ids']) || empty($_POST['worker_ids'])) {
        $error_message = 'Please select at least one worker.';
    } else {
        $worker_ids = $_POST['worker_ids'];
        $worker_count = count($worker_ids);
        
        // 驗證每個工人的可用性
        $valid_allocations = [];
        
        foreach ($worker_ids as $worker_id) {
            $worker_id = intval($worker_id);
            
            // 檢查是否已分配給此訂單
            $check_allocation_sql = "SELECT * FROM `workerallocation` WHERE orderid = ? AND workerid = ? AND status != 'Completed' AND status != 'Cancelled'";
            $check_stmt = mysqli_prepare($mysqli, $check_allocation_sql);
            mysqli_stmt_bind_param($check_stmt, "ii", $order_id, $worker_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Worker #$worker_id is already allocated to this order.";
                mysqli_stmt_close($check_stmt);
                break;
            }
            mysqli_stmt_close($check_stmt);
            
            $valid_allocations[] = $worker_id;
        }
        
        // 如果沒有錯誤，保存所有分配
        if (empty($error_message)) {
            $allocation_success = true;
            
            // 開始事務
            mysqli_begin_transaction($mysqli);
            
            try {
                foreach ($valid_allocations as $worker_id) {
                    
                    // 插入分配記錄
                    $insert_sql = "INSERT INTO `workerallocation` (orderid, workerid, managerid, allocation_date, estimated_completion, notes, status) 
                                   VALUES (?, ?, ?, NOW(), ?, ?, 'Assigned')";
                    $insert_stmt = mysqli_prepare($mysqli, $insert_sql);
                    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($mysqli, $_POST['notes']) : '';
                    $estimated_completion = $schedule_info['OrderFinishDate'] ?? date('Y-m-d', strtotime('+7 days'));
                    
                    mysqli_stmt_bind_param($insert_stmt, "iiiss", $order_id, $worker_id, $user_id, $estimated_completion, $notes);
                    
                    if (!mysqli_stmt_execute($insert_stmt)) {
                        throw new Exception('Error allocating worker #' . $worker_id . ': ' . mysqli_error($mysqli));
                    }
                    mysqli_stmt_close($insert_stmt);
                }
                
                // 不更新訂單狀態，保持為 'Designing'
                // $update_order_sql = "UPDATE `Order` SET ostatus = 'In Production' WHERE orderid = ?";
                // $update_order_stmt = mysqli_prepare($mysqli, $update_order_sql);
                // mysqli_stmt_bind_param($update_order_stmt, "i", $order_id);
                
                // if (!mysqli_stmt_execute($update_order_stmt)) {
                //     throw new Exception('Error updating order status: ' . mysqli_error($mysqli));
                // }
                // mysqli_stmt_close($update_order_stmt);
                
                // 提交事務
                mysqli_commit($mysqli);
                
                $worker_count = count($valid_allocations);
                $success_message = "Successfully allocated $worker_count workers!";
                
                // 立即刷新工人列表（移除已分配的工人）
                $workers = array_filter($all_workers, function($worker) use ($valid_allocations, $existing_worker_ids) {
                    $combined_ids = array_merge($existing_worker_ids, $valid_allocations);
                    return !in_array($worker['workerid'], $combined_ids);
                });
                $workers = array_values($workers);
                
                // 3秒後重定向
                header("Refresh: 3; url=WorkerAllocation.php?orderid=" . $order_id);
                
            } catch (Exception $e) {
                // 回滾事務
                mysqli_rollback($mysqli);
                $error_message = $e->getMessage();
            }
        }
    }
}

// 獲取此訂單的現有分配（用於顯示表格）
$existing_allocations_display_sql = "SELECT wa.*, w.name as wname, w.certificate as specialty 
                                     FROM `workerallocation` wa
                                     LEFT JOIN `worker` w ON wa.workerid = w.workerid
                                     WHERE wa.orderid = ?
                                     ORDER BY wa.allocation_date DESC";
$existing_display_stmt = mysqli_prepare($mysqli, $existing_allocations_display_sql);
mysqli_stmt_bind_param($existing_display_stmt, "i", $order_id);
mysqli_stmt_execute($existing_display_stmt);
$existing_allocations_display_result = mysqli_stmt_get_result($existing_display_stmt);
$existing_allocations = mysqli_fetch_all($existing_allocations_display_result, MYSQLI_ASSOC);
mysqli_stmt_close($existing_display_stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Worker Allocation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .worker-card {
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }
        .worker-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .worker-card.selected {
            border-color: #28a745;
            background-color: #f8fff9;
        }
        .worker-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .badge-available {
            background-color: #28a745;
            color: white;
        }
        .worker-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
            margin-right: 10px;
        }
        .schedule-info {
            background-color: #f8f9fa;
            border-left: 4px solid #28a745;
        }
        .worker-info {
            font-size: 0.9rem;
        }
        .status-text {
            color: #28a745;
            font-weight: 600;
        }
        .action-btn {
            padding: 4px 10px;
            font-size: 0.85rem;
        }
        .no-workers-message {
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Header Navigation -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-users me-2"></i>Worker Allocation - Order #<?php echo htmlspecialchars($order_id); ?>
        </div>

        <!-- Messages -->
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Order Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>Order Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Order ID:</strong> #<?php echo htmlspecialchars($order_info['orderid']); ?></p>
                        <p><strong>Order Date:</strong> <?php echo date('Y-m-d', strtotime($order_info['odate'])); ?></p>
                        <p><strong>Client:</strong> <?php echo htmlspecialchars($order_info['client_name'] ?? 'N/A'); ?></p>
                        <p><strong>Budget:</strong> $<?php echo number_format($order_info['client_budget'], 2); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Design ID:</strong> #<?php echo htmlspecialchars($order_info['designid'] ?? 'N/A'); ?></p>
                        <p><strong>Design Price:</strong> $<?php echo number_format($order_info['design_price'] ?? 0, 2); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="status-text">
                                <i class="fas fa-circle me-1"></i><?php echo htmlspecialchars($order_info['ostatus']); ?>
                            </span>
                        </p>
                        <p><strong>Requirements:</strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($order_info['Requirements'] ?? 'No requirements specified'); ?></small>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Information -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="fas fa-calendar me-2"></i>Schedule Information
                </h5>
            </div>
            <div class="card-body">
                <?php if ($schedule_info && $schedule_info['DesignFinishDate'] && $schedule_info['OrderFinishDate']): ?>
                    <div class="row">
                        <div class="col-md-4">
                            <p><strong>Design Finish Date:</strong><br>
                                <span class="text-success"><?php echo date('Y-m-d', strtotime($schedule_info['DesignFinishDate'])); ?></span>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Order Finish Date:</strong><br>
                                <span class="text-success"><?php echo date('Y-m-d', strtotime($schedule_info['OrderFinishDate'])); ?></span>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <div class="schedule-info p-3 rounded">
                                <p class="mb-1"><strong>Work Period:</strong></p>
                                <h4 class="text-success mb-1"><?php echo $work_days; ?> Days</h4>
                                <p class="mb-0 text-muted"><?php echo date('M d', strtotime($schedule_info['DesignFinishDate'])); ?> - <?php echo date('M d', strtotime($schedule_info['OrderFinishDate'])); ?></p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No schedule found for this order. Please set up schedule first.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Existing Allocations -->
        <?php if (!empty($existing_allocations)): ?>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="fas fa-list-check me-2"></i>Existing Worker Allocations
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Specialty</th>
                                <th>Allocation Date</th>
                                <th>Estimated Completion</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_allocations as $allocation): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($allocation['wname']); ?></strong><br>
                                    <small class="text-muted">ID: <?php echo htmlspecialchars($allocation['workerid']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($allocation['specialty'] ?? 'N/A'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($allocation['allocation_date'])); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($allocation['estimated_completion'])); ?></td>
                                <td>
                                    <span class="badge bg-success">
                                        <?php echo htmlspecialchars($allocation['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="Worker_Schedule.php?workerid=<?php echo $allocation['workerid']; ?>&orderid=<?php echo $order_id; ?>" 
                                       class="btn btn-sm btn-outline-primary action-btn">
                                        <i class="fas fa-calendar-alt me-1"></i>View Schedule
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="6">
                                    <small class="text-success">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Total Workers Allocated: <?php echo count($existing_allocations); ?>
                                    </small>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Worker Allocation Form -->
        <?php if ($order_info['ostatus'] == 'Designing'): ?>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="fas fa-user-plus me-2"></i>Select Workers (Available Workers Only)
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="allocationForm">
                    <!-- Worker Selection -->
                    <div class="mb-4">
                        <label class="form-label"><strong>Select Workers (click to select/deselect):</strong></label>
                        <?php if (empty($workers)): ?>
                            <div class="no-workers-message">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Available Workers</h5>
                                <p class="text-muted mb-0">
                                    <?php if (!empty($existing_worker_ids)): ?>
                                        All workers have already been allocated to this order.
                                    <?php else: ?>
                                        No workers found in the system.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php elseif (!$schedule_info || !$schedule_info['DesignFinishDate'] || !$schedule_info['OrderFinishDate']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Cannot allocate workers without schedule information. Please set up schedule first.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Click on worker cards to select/deselect. Selected workers will be assigned to this order.
                                <br><small class="text-muted">Note: Workers already allocated to this order are not shown in this list.</small>
                            </div>
                            
                            <div class="row" id="workersContainer">
                                <?php foreach ($workers as $worker): 
                                    $current_assignments = $worker['current_assignments'] ?? 0;
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card worker-card h-100" 
                                         onclick="toggleWorkerSelection(<?php echo $worker['workerid']; ?>)"
                                         id="worker-<?php echo $worker['workerid']; ?>">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start">
                                                <?php if (!empty($worker['image'])): ?>
                                                    <img src="../uploads/workers/<?php echo htmlspecialchars($worker['image']); ?>" 
                                                         alt="<?php echo htmlspecialchars($worker['wname']); ?>" 
                                                         class="worker-img">
                                                <?php else: ?>
                                                    <div class="worker-img bg-success d-flex align-items-center justify-content-center">
                                                        <i class="fas fa-user text-white"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="flex: 1;">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="card-title mb-0">
                                                                <?php echo htmlspecialchars($worker['wname']); ?>
                                                                <input type="checkbox" 
                                                                       name="worker_ids[]" 
                                                                       id="worker_checkbox_<?php echo $worker['workerid']; ?>"
                                                                       value="<?php echo $worker['workerid']; ?>"
                                                                       class="worker-checkbox d-none">
                                                            </h6>
                                                        </div>
                                                        <span class="badge badge-available">
                                                            Available
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="worker-info">
                                                        <p class="mb-1">
                                                            <i class="fas fa-envelope me-1 text-muted"></i>
                                                            <?php echo htmlspecialchars($worker['wemail'] ?? 'N/A'); ?>
                                                        </p>
                                                        <p class="mb-1">
                                                            <i class="fas fa-phone me-1 text-muted"></i>
                                                            <?php echo htmlspecialchars($worker['wphone'] ?? 'N/A'); ?>
                                                        </p>
                                                        <p class="mb-1">
                                                            <i class="fas fa-certificate me-1 text-muted"></i>
                                                            <?php echo htmlspecialchars($worker['specialty'] ?? 'No specialty'); ?>
                                                        </p>
                                                        <p class="mb-0">
                                                            <i class="fas fa-tasks me-1 text-muted"></i>
                                                            Current Tasks: <?php echo htmlspecialchars($current_assignments); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Summary Section -->
                            <div class="card mt-3 border-success" id="summaryCard" style="display: none;">
                                <div class="card-body">
                                    <h6 class="card-title text-success">
                                        <i class="fas fa-clipboard-check me-2"></i>Allocation Summary
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <p><strong>Selected Workers:</strong> <span id="selectedCount" class="text-success">0</span></p>
                                            <p><strong>Work Period:</strong> <span class="text-success"><?php echo $work_days; ?> days (<?php echo date('M d', strtotime($schedule_info['DesignFinishDate'])); ?> - <?php echo date('M d', strtotime($schedule_info['OrderFinishDate'])); ?>)</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Additional Notes -->
                    <div class="mb-4">
                        <label for="notes" class="form-label">
                            <strong>Additional Notes (Optional):</strong>
                        </label>
                        <textarea class="form-control" 
                                  id="notes" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Enter any special instructions or notes for the workers..."></textarea>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-between">
                        <a href="Manager_MyOrder_buyProduct.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2" onclick="resetForm()">
                                <i class="fas fa-redo me-2"></i>Reset
                            </button>
                            <button type="submit" 
                                    name="allocate_workers" 
                                    class="btn btn-success"
                                    id="allocateButton"
                                    <?php echo (empty($workers) || !$schedule_info || !$schedule_info['DesignFinishDate'] || !$schedule_info['OrderFinishDate']) ? 'disabled' : ''; ?>>
                                <i class="fas fa-user-check me-2"></i>
                                Allocate Selected Workers
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
            <div class="alert alert-success">
                <i class="fas fa-info-circle me-2"></i>
                This order is already in <strong class="text-success"><?php echo htmlspecialchars($order_info['ostatus']); ?></strong> status. Worker allocation can only be done for orders in "Designing" status.
            </div>
        <?php endif; ?>

        <!-- Information Note -->
        <div class="alert alert-success">
            <i class="fas fa-lightbulb me-2"></i>
            <strong>Note:</strong> 
            <ul class="mb-0 mt-2">
                <li>Selected workers will be assigned to this order</li>
                <li>Order status will remain as "Designing"</li>
                <li>Once allocated, workers will not appear in the available list</li>
                <li>You can view worker schedules by clicking "View Schedule" button</li>
            </ul>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let selectedWorkers = new Set();
    
    function toggleWorkerSelection(workerId) {
        const checkbox = document.getElementById('worker_checkbox_' + workerId);
        const workerCard = document.getElementById('worker-' + workerId);
        
        if (checkbox.checked) {
            // Deselect worker
            checkbox.checked = false;
            workerCard.classList.remove('selected');
            selectedWorkers.delete(workerId);
        } else {
            // Select worker
            checkbox.checked = true;
            workerCard.classList.add('selected');
            selectedWorkers.add(workerId);
        }
        
        updateSummary();
        validateForm();
    }
    
    function updateSummary() {
        const selectedCount = selectedWorkers.size;
        const summaryCard = document.getElementById('summaryCard');
        
        if (selectedCount > 0) {
            summaryCard.style.display = 'block';
            document.getElementById('selectedCount').textContent = selectedCount;
        } else {
            summaryCard.style.display = 'none';
        }
    }
    
    function validateForm() {
        const allocateButton = document.getElementById('allocateButton');
        const isValid = selectedWorkers.size > 0;
        allocateButton.disabled = !isValid;
    }
    
    function resetForm() {
        // Uncheck all checkboxes
        document.querySelectorAll('.worker-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        
        // Remove selected class from all cards
        document.querySelectorAll('.worker-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Clear selected workers set
        selectedWorkers.clear();
        
        // Hide summary
        document.getElementById('summaryCard').style.display = 'none';
        
        // Disable allocate button
        document.getElementById('allocateButton').disabled = true;
    }
    
    // Initial validation
    document.addEventListener('DOMContentLoaded', function() {
        validateForm();
    });
    </script>
        <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>