<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// 检查用户是否以经理身份登录
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// 处理搜索功能
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 获取等待确认的订单（Pending 或 Waiting Confirm 状态）
$sql = "SELECT 
            o.orderid, 
            o.odate, 
            o.Requirements, 
            o.ostatus,
            c.clientid, 
            c.cname as client_name, 
            c.cemail as client_email,
            c.budget,
            d.designid, 
            d.expect_price as design_price,
            d.tag as design_tag,
            s.OrderFinishDate,
            s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `OrderProduct` op ON o.orderid = op.orderid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.ostatus IN ('Pending', 'Waiting Confirm') 
        AND (op.managerid = ? OR s.managerid = ?)";

// 添加搜索条件
if (!empty($search)) {
    $search_term = "%" . $search . "%";
    $sql .= " AND (o.orderid LIKE ? 
                OR c.cname LIKE ? 
                OR c.cemail LIKE ? 
                OR o.Requirements LIKE ? 
                OR d.tag LIKE ?)";
    $sql .= " GROUP BY o.orderid";
}

$sql .= " ORDER BY o.orderid DESC";

$stmt = mysqli_prepare($mysqli, $sql);

if (!empty($search)) {
    // 有搜索条件
    mysqli_stmt_bind_param($stmt, "iisssss", 
        $user_id, 
        $user_id, 
        $search_term, 
        $search_term, 
        $search_term, 
        $search_term, 
        $search_term
    );
} else {
    // 无搜索条件
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$orders = [];
while($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}

// 处理消息提示
$message = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'approved':
            $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>Order has been approved successfully!';
            if (isset($_GET['email'])) {
                if ($_GET['email'] == 'sent') {
                    $message .= ' Email notification has been sent to the client.';
                } else {
                    $message .= ' <strong>Note:</strong> Email notification failed to send.';
                }
            }
            $message .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>';
            break;
        case 'notfound':
            $message = '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>Order not found or you don\'t have permission to access it.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>';
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Awaiting Confirmation Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <style>
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status-waiting {
            background-color: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }
        .status-designing {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .order-row:hover {
            background-color: #f8f9fa;
            cursor: pointer;
        }
        .search-form {
            max-width: 600px;
        }
        .table-container {
            overflow-x: auto;
        }
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid #3498db;
        }
    </style>
</head>
<body>
    <!-- 头部导航 -->
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- 页面标题 -->
        <div class="page-title">
            <i class="fas fa-clock me-2"></i>Awaiting Confirmation Orders
        </div>

        <!-- 消息提示 -->
        <?php echo $message; ?>

        <!-- 搜索卡片 -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-search me-2"></i>Search Orders
                </h5>
                <form method="GET" action="" class="search-form">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               name="search"
                               placeholder="Search by Order ID, Client Name, Email, Requirements or Tags..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search me-1"></i>Search
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Clear
                            </a>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted mt-2 d-block">
                        <i class="fas fa-info-circle me-1"></i>Showing orders with status: <strong>Pending</strong>
                    </small>
                </form>
            </div>
        </div>

        <!-- 订单表格卡片 -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-list me-2"></i>Order List
                    <span class="badge bg-primary ms-2"><?php echo count($orders); ?> orders</span>
                </h5>
                
                <?php if (empty($orders)): ?>
                    <!-- 无订单时的显示 -->
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x mb-4" style="color: #dee2e6;"></i>
                        <h4 class="mb-3">No Pending Orders Found</h4>
                        <p class="text-muted mb-4">
                            <?php if (!empty($search)): ?>
                                No orders found matching your search criteria.
                            <?php else: ?>
                                All new orders will appear here when they are submitted by clients.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search)): ?>
                            <a href="Manager_MyOrder_AwaitingConfirm.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1"></i>View All Orders
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- 订单表格 -->
                    <div class="table-container">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Order Date</th>
                                    <th>Client</th>
                                    <th>Budget</th>
                                    <th>Design</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Finish Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <tr class="order-row">
                                    <td>
                                        <strong>#<?php echo htmlspecialchars($order['orderid']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo date('Y-m-d', strtotime($order['odate'])); ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?php echo htmlspecialchars($order['client_name']); ?></div>
                                        <small class="text-muted">ID: <?php echo htmlspecialchars($order['clientid']); ?></small>
                                    </td>
                                    <td>
                                        <span class="text-success fw-bold">
                                            $<?php echo number_format($order['budget'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            Design #<?php echo htmlspecialchars($order['designid']); ?>
                                        </span>
                                        <?php if (!empty($order['design_tag'])): ?>
                                            <div><small class="text-muted"><?php echo htmlspecialchars($order['design_tag']); ?></small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-medium">
                                            $<?php echo number_format($order['design_price'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($order['ostatus'] == 'Pending'): ?>
                                            <span class="status-badge status-pending">
                                                <i class="fas fa-clock me-1"></i>Pending
                                            </span>
                                        <?php elseif ($order['ostatus'] == 'Waiting Confirm'): ?>
                                            <span class="status-badge status-waiting">
                                                <i class="fas fa-hourglass-half me-1"></i>Waiting Confirm
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge">
                                                <?php echo htmlspecialchars($order['ostatus']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($order['OrderFinishDate']) && $order['OrderFinishDate'] != '0000-00-00 00:00:00'): ?>
                                            <?php echo date('Y-m-d', strtotime($order['OrderFinishDate'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not scheduled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="Manager_MyOrder_AwaitingConfirm_Approval.php?id=<?php echo $order['orderid']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="Review and Approve">
                                                <i class="fas fa-edit"></i> Review
                                            </a>
                                            <a href="Manager_MyOrder_View.php?id=<?php echo $order['orderid']; ?>" 
                                               class="btn btn-outline-secondary" 
                                               title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- 需求详情行（可展开） -->
                                <tr class="details-row" style="display: none;">
                                    <td colspan="9">
                                        <div class="p-3 bg-light rounded">
                                            <h6 class="mb-2">
                                                <i class="fas fa-file-alt me-2"></i>Requirements:
                                            </h6>
                                            <div class="bg-white p-3 rounded border">
                                                <?php echo nl2br(htmlspecialchars($order['Requirements'] ?? 'No requirements specified')); ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 订单统计 -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Showing <?php echo count($orders); ?> order(s) requiring your approval
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    Last updated: <?php echo date('Y-m-d H:i:s'); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 快速操作卡片 -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h6>
                <div class="btn-group">
                    <a href="../Manager/Manager_Dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                    <a href="Manager_MyOrder_All.php" class="btn btn-outline-secondary">
                        <i class="fas fa-list-alt me-1"></i>All Orders
                    </a>
                    <a href="../Manager/Manager_ClientList.php" class="btn btn-outline-secondary">
                        <i class="fas fa-users me-1"></i>Clients
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 展开/收起需求详情
        const orderRows = document.querySelectorAll('.order-row');
        orderRows.forEach(row => {
            row.addEventListener('click', function(e) {
                // 防止点击按钮时触发
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || 
                    e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                
                const detailsRow = this.nextElementSibling;
                if (detailsRow && detailsRow.classList.contains('details-row')) {
                    if (detailsRow.style.display === 'none' || detailsRow.style.display === '') {
                        detailsRow.style.display = 'table-row';
                        this.style.backgroundColor = '#f1f8ff';
                    } else {
                        detailsRow.style.display = 'none';
                        this.style.backgroundColor = '';
                    }
                }
            });
        });
        
        // 自动聚焦搜索框
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
        
        // 键盘快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl+F 聚焦搜索框
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Esc 清空搜索框
            if (e.key === 'Escape' && searchInput && searchInput.value) {
                window.location.href = 'Manager_MyOrder_AwaitingConfirm.php';
            }
        });
        
        // 自动关闭警告框
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
        
        // 高亮搜索关键词
        function highlightSearchTerms() {
            const searchTerm = "<?php echo addslashes($search); ?>";
            if (!searchTerm) return;
            
            const table = document.querySelector('table');
            if (!table) return;
            
            const regex = new RegExp(`(${searchTerm})`, 'gi');
            const walker = document.createTreeWalker(
                table,
                NodeFilter.SHOW_TEXT,
                null,
                false
            );
            
            let node;
            while (node = walker.nextNode()) {
                if (node.parentNode.tagName !== 'SCRIPT' && node.parentNode.tagName !== 'STYLE') {
                    const newHTML = node.textContent.replace(regex, '<mark class="bg-warning">$1</mark>');
                    if (newHTML !== node.textContent) {
                        const span = document.createElement('span');
                        span.innerHTML = newHTML;
                        node.parentNode.replaceChild(span, node);
                    }
                }
            }
        }
        
        highlightSearchTerms();
    });
    </script>

    <!-- 包含聊天组件 -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>