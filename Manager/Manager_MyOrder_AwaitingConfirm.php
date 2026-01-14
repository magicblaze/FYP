<?php
// Include database connection file
require_once dirname(__DIR__) . '/config.php';

// 获取搜索参数
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// 构建查询条件
$where_conditions = array("o.ostatus = 'Pending' OR o.ostatus = 'pending'");

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
$sql = "SELECT o.orderid, o.odate, o.budget, o.Requirements, o.ostatus,
               c.clientid, c.cname as client_name, c.cemail as client_email, c.ctel as client_phone,
               d.designid, d.design as design_image, d.price as design_price, d.tag as design_tag,
               s.FinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($conn, $sql);

// 如果还有错误，显示错误信息
if(!$result) {
    die("Database Error: " . mysqli_error($conn));
}

// Calculate statistics
$stats_sql = "SELECT 
                COUNT(*) as total_pending,
                SUM(o.budget) as total_budget,
                AVG(o.budget) as avg_budget
              FROM `Order` o
              WHERE o.ostatus = 'Pending' OR o.ostatus = 'pending'";
$stats_result = mysqli_query($conn, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Orders</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            margin: 15px 0;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
        }
        .search-box {
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .search-box input[type="text"] {
            width: 400px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .search-box button {
            padding: 8px 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .search-box a {
            margin-left: 10px;
            color: #6c757d;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .status-pending {
            color: orange;
            font-weight: bold;
        }
        .action-buttons button {
            margin: 2px;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .view-btn {
            background-color: #007bff;
            color: white;
        }
        .approve-btn {
            background-color: #28a745;
            color: white;
        }
        .back-buttons {
            margin-top: 20px;
        }
        .back-buttons button {
            padding: 10px 20px;
            margin-right: 10px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .stats {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <h1>Pending Orders</h1>
    
    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
        <div class="success-message">
            ✅ Order #<?php echo htmlspecialchars($_GET['id'] ?? ''); ?> has been processed successfully! 
            <?php if(isset($_GET['email']) && $_GET['email'] == 'sent'): ?>
                Email notification sent to client.
            <?php elseif(isset($_GET['email']) && $_GET['email'] == 'failed'): ?>
                <span style="color: #dc3545;">Warning: Email notification failed to send.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <hr>
    
    <!-- 搜索框 -->
    <div class="search-box">
        <form method="GET" action="">
            <input type="text" name="search" placeholder="Search by Order ID, Client Name, Email, Requirements or Tags..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit">🔍 Search</button>
            <?php if(!empty($search)): ?>
                <a href="Manager_MyOrder_AwaitingConfirm.php">✖ Clear Search</a>
            <?php endif; ?>
        </form>
    </div>
    
    <?php
    if(mysqli_num_rows($result) == 0){
        echo "<p>No pending orders found" . (!empty($search) ? " matching your search criteria." : " at the moment.") . "</p>";
    } else {
        $total_pending = $stats['total_pending'] ?? 0;
    ?>
    
    <div class="stats">
        <h2>📊 Pending Orders Summary</h2>
        <p><strong>Total Pending Orders:</strong> <?php echo $total_pending; ?></p>
        <p><strong>Total Budget:</strong> $<?php echo number_format($stats['total_budget'] ?? 0, 2); ?></p>
        <p><strong>Average Budget:</strong> $<?php echo number_format($stats['avg_budget'] ?? 0, 2); ?></p>
    </div>
    
    <table border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>Order ID</th>
            <th>Order Date</th>
            <th>Client</th>
            <th>Budget</th>
            <th>Design</th>
            <th>Requirements</th>
            <th>Status</th>
            <th>Scheduled Finish Date</th>
            <th>Actions</th>
        </tr>
        <?php
        while($row = mysqli_fetch_assoc($result)){
        ?>
        <tr>
            <td><strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong></td>
            <td><?php echo date('Y-m-d H:i', strtotime($row["odate"])); ?></td>
            <td>
                <?php 
                echo htmlspecialchars($row["client_name"] ?? 'N/A'); 
                echo '<br><small>Client ID: ' . htmlspecialchars($row["clientid"] ?? 'N/A') . '</small>';
                if(!empty($row["client_email"])) {
                    echo '<br><small>📧 ' . htmlspecialchars($row["client_email"]) . '</small>';
                }
                if(!empty($row["client_phone"])) {
                    echo '<br><small>📞 ' . htmlspecialchars($row["client_phone"]) . '</small>';
                }
                ?>
            </td>
            <td><strong>$<?php echo number_format($row["budget"], 2); ?></strong></td>
            <td>
                <?php 
                echo 'Design #' . htmlspecialchars($row["designid"] ?? 'N/A');
                echo '<br>Price: $' . number_format($row["design_price"] ?? 0, 2);
                echo '<br>Tags: ' . htmlspecialchars(substr($row["design_tag"] ?? '', 0, 30));
                if(!empty($row["design_image"])) {
                    echo '<br><small>🖼️ ' . htmlspecialchars(substr($row["design_image"], 0, 20)) . '...</small>';
                }
                ?>
            </td>
            <td><?php echo htmlspecialchars(substr($row["Requirements"] ?? '', 0, 100)); ?></td>
            <td>
                <span class="status-pending">
                    ⏳ <?php echo htmlspecialchars($row["ostatus"] ?? 'Pending'); ?>
                </span>
            </td>
            <td>
                <?php 
                if(isset($row["FinishDate"]) && $row["FinishDate"] != '0000-00-00 00:00:00'){
                    echo '📅 ' . date('Y-m-d H:i', strtotime($row["FinishDate"]));
                } else {
                    echo 'Not scheduled';
                }
                ?>
            </td>
            <td class="action-buttons">
                <button class="view-btn" onclick="viewOrder(<?php echo $row['orderid']; ?>)">👁️ View</button>
                <button class="approve-btn" onclick="approveOrder(<?php echo $row['orderid']; ?>)">✅ Approve/Process</button>
            </td>
        </tr>
        <?php
        }
        ?>
    </table>
    
    <?php
    mysqli_free_result($stats_result);
    }
    
    mysqli_free_result($result);
    mysqli_close($conn);
    ?>
    
    <script>
    function viewOrder(orderId) {
        window.location.href = 'Manager_view_order.php?id=' + orderId;
    }
    
    function approveOrder(orderId) {
        window.location.href = 'Manager_MyOrder_AwaitingConfirm_Approval.php?id=' + orderId;
    }
    
    // 快速搜索功能
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Pending orders page loaded');
        
        // 添加快捷键支持
        document.addEventListener('keydown', function(e) {
            // Ctrl+F 聚焦搜索框
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if(searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            // Enter键在搜索框聚焦时搜索
            const searchInput = document.querySelector('input[name="search"]');
            if(searchInput && document.activeElement === searchInput && e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });
        
        // 高亮搜索结果
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search');
        if(searchTerm) {
            setTimeout(() => {
                highlightSearchTerm(searchTerm);
            }, 100);
        }
    });
    
    function highlightSearchTerm(term) {
        const table = document.querySelector('table');
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
                const newHTML = node.textContent.replace(regex, '<mark>$1</mark>');
                if(newHTML !== node.textContent) {
                    const span = document.createElement('span');
                    span.innerHTML = newHTML;
                    node.parentNode.replaceChild(span, node);
                }
            }
        });
    }
    </script>
    
    <div class="back-buttons">
        <button onclick="window.location.href='Manager_MyOrder.html'">← Back to Orders Manager</button>
        <button onclick="window.location.href='index.php'">← Back to Dashboard</button>
    </div>
</body>
</html>