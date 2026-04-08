<?php
// ============================================
// File: supplier/supplier_wallet.php
// Description: Supplier Wallet Management
// ============================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../wallet_functions.php';
session_start();


// 检查用户是否登录为supplier - 使用 $_SESSION['user'] 结构
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    // 记录调试信息
    error_log("Supplier wallet: No supplier session found");
    error_log("Session data: " . print_r($_SESSION, true));
    
    // 重定向到登录页
    header('Location: ../login.php?error=Please login as supplier&redirect=' . urlencode('supplier/supplier_wallet.php'));
    exit;
}

// 获取supplier ID - 从会话中正确获取
$supplierId = (int) ($_SESSION['user']['supplierid'] ?? 0);
$supplierName = $_SESSION['user']['name'] ?? 'Unknown';

if ($supplierId <= 0) {
    error_log("Supplier wallet: Invalid supplier ID: " . $supplierId);
    die('Invalid session. Please login again. <a href="../login.php">Login</a>');
}

// 引入配置文件
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../wallet_functions.php';

// 检查数据库连接
if (!isset($mysqli) || $mysqli->connect_error) {
    error_log("Database connection failed: " . ($mysqli->connect_error ?? 'Unknown error'));
    die("Database connection failed");
}

// Get supplier details
$supplierStmt = $mysqli->prepare("SELECT sname, semail, stel FROM Supplier WHERE supplierid = ?");
if (!$supplierStmt) {
    error_log("Prepare failed for supplier details: " . $mysqli->error);
    die("Database error: " . $mysqli->error);
}
$supplierStmt->bind_param("i", $supplierId);
$supplierStmt->execute();
$supplierData = $supplierStmt->get_result()->fetch_assoc();

if (!$supplierData) {
    error_log("Supplier not found for ID: " . $supplierId);
    die("Supplier not found");
}

// Get wallet information
$walletStmt = $mysqli->prepare("SELECT * FROM SupplierWallet WHERE supplierid = ?");
$walletStmt->bind_param("i", $supplierId);
$walletStmt->execute();
$wallet = $walletStmt->get_result()->fetch_assoc();

// If no wallet exists, create one
if (!$wallet) {
    error_log("No wallet found for supplier ID: " . $supplierId . ", creating new wallet");
    $createStmt = $mysqli->prepare("INSERT INTO SupplierWallet (supplierid, balance, total_earned, total_withdrawn, pending_balance) VALUES (?, 0, 0, 0, 0)");
    $createStmt->bind_param("i", $supplierId);
    if ($createStmt->execute()) {
        error_log("New wallet created successfully");
        // Fetch the newly created wallet
        $walletStmt->execute();
        $wallet = $walletStmt->get_result()->fetch_assoc();
    } else {
        error_log("Failed to create wallet: " . $createStmt->error);
    }
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    error_log("POST request received: " . print_r($_POST, true));
    
    if ($_POST['action'] === 'request_withdrawal') {
        $amount = floatval($_POST['amount']);
        $bankName = trim($_POST['bank_name']);
        $accountNumber = trim($_POST['account_number']);
        $accountHolder = trim($_POST['account_holder']);
        
        error_log("Withdrawal request: amount=$amount, bank=$bankName");
        
        if ($amount <= 0) {
            $message = 'Please enter a valid amount.';
            $messageType = 'danger';
        } elseif ($amount > $wallet['balance']) {
            $message = 'Insufficient balance for withdrawal.';
            $messageType = 'danger';
        } elseif (empty($bankName) || empty($accountNumber) || empty($accountHolder)) {
            $message = 'Please fill in all bank details.';
            $messageType = 'danger';
        } else {
            // Create withdrawal request
            $requestStmt = $mysqli->prepare("
                INSERT INTO WithdrawalRequest 
                (user_type, user_id, amount, bank_name, account_number, account_holder, status) 
                VALUES ('supplier', ?, ?, ?, ?, ?, 'pending')
            ");
            $requestStmt->bind_param("idsss", $supplierId, $amount, $bankName, $accountNumber, $accountHolder);
            
            if ($requestStmt->execute()) {
                $message = 'Withdrawal request submitted successfully. Pending approval.';
                $messageType = 'success';
                error_log("Withdrawal request created successfully");
            } else {
                $message = 'Failed to submit withdrawal request: ' . $mysqli->error;
                $messageType = 'danger';
                error_log("Failed to create withdrawal request: " . $mysqli->error);
            }
        }
    }
}

// Get transaction history
$transactionsStmt = $mysqli->prepare("
    SELECT * FROM WalletTransaction 
    WHERE user_type = 'supplier' AND user_id = ? 
    ORDER BY created_at DESC LIMIT 50
");
$transactionsStmt->bind_param("i", $supplierId);
$transactionsStmt->execute();
$transactions = $transactionsStmt->get_result();

// Get pending withdrawal requests
$pendingRequestsStmt = $mysqli->prepare("
    SELECT * FROM WithdrawalRequest 
    WHERE user_type = 'supplier' AND user_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$pendingRequestsStmt->bind_param("i", $supplierId);
$pendingRequestsStmt->execute();
$pendingRequests = $pendingRequestsStmt->get_result();

// Get product sales summary
$productSalesStmt = $mysqli->prepare("
    SELECT 
        p.productid,
        p.pname,
        p.price,
        COUNT(DISTINCT od.orderdeliveryid) as order_count,
        IFNULL(SUM(od.quantity), 0) as total_quantity,
        IFNULL(SUM(p.price * od.quantity), 0) as total_revenue
    FROM Product p
    LEFT JOIN OrderDelivery od ON p.productid = od.productid
    WHERE p.supplierid = ?
    GROUP BY p.productid
    ORDER BY total_revenue DESC
    LIMIT 10
");
$productSalesStmt->bind_param("i", $supplierId);
$productSalesStmt->execute();
$productSales = $productSalesStmt->get_result();

// Get construction projects income
$constructionStmt = $mysqli->prepare("
    SELECT 
        o.orderid,
        d.designName,
        o.odate,
        o.ostatus,
        (SELECT IFNULL(SUM(amount), 0) FROM WalletTransaction 
         WHERE user_type = 'supplier' AND user_id = ? 
         AND reference_type = 'order' AND reference_id = o.orderid 
         AND transaction_type = 'income') as construction_income
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    WHERE o.orderid IN (
        SELECT DISTINCT orderid FROM OrderReference orf
        JOIN Product p ON orf.productid = p.productid
        WHERE p.supplierid = ?
    )
    ORDER BY o.odate DESC
    LIMIT 10
");
$constructionStmt->bind_param("ii", $supplierId, $supplierId);
$constructionStmt->execute();
$constructionProjects = $constructionStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Wallet - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        .wallet-card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            background: white;
            margin-bottom: 20px;
        }
        .wallet-card:hover {
            transform: translateY(-5px);
        }
        .balance-amount {
            font-size: 2.5rem;
            font-weight: bold;
            color: #28a745;
        }
        .stat-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        .badge-income {
            background-color: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .badge-withdrawal {
            background-color: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .page-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
        }
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #1eaa7a 100%);
        }
    </style>
</head>
<body>
    <?php 
    // 确保 header.php 存在
    $headerFile = __DIR__ . '/../includes/header.php';
    if (file_exists($headerFile)) {
        include_once $headerFile;
    } else {
        echo '<div class="alert alert-warning">Header file not found</div>';
    }
    ?>

    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="fas fa-wallet me-2"></i>Supplier Wallet</h2>
                            <p class="mb-0">Welcome back, <?= htmlspecialchars($supplierData['sname'] ?? $supplierName) ?></p>
                        </div>
                        <div>
                            <span class="badge bg-light text-dark p-2">
                                <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($supplierData['semail'] ?? $_SESSION['user']['email'] ?? 'No email') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title opacity-75">Total Products</h6>
                                        <h2 class="mb-0"><?= $productSales->num_rows ?></h2>
                                    </div>
                                    <i class="fas fa-box fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title opacity-75">Active Projects</h6>
                                        <h2 class="mb-0"><?= $constructionProjects->num_rows ?></h2>
                                    </div>
                                    <i class="fas fa-hard-hat fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title opacity-75">Total Earned</h6>
                                        <h2 class="mb-0">$<?= number_format($wallet['total_earned'] ?? 0, 0) ?></h2>
                                    </div>
                                    <i class="fas fa-chart-line fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-warning text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title opacity-75">Pending Requests</h6>
                                        <h2 class="mb-0"><?= $pendingRequests->num_rows ?></h2>
                                    </div>
                                    <i class="fas fa-clock fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column - Wallet Info -->
                    <div class="col-md-4">
                        <!-- Wallet Balance Card -->
                        <div class="wallet-card">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <div class="mb-3">
                                        <i class="fas fa-circle-dollar-to-slot fa-4x text-success"></i>
                                    </div>
                                    <h5 class="text-muted mb-2">Available Balance</h5>
                                    <div class="balance-amount">$<?= number_format($wallet['balance'] ?? 0, 2) ?></div>
                                </div>
                                
                                <div class="row g-2 mb-4">
                                    <div class="col-6">
                                        <div class="stat-box">
                                            <i class="fas fa-arrow-up text-success mb-2"></i>
                                            <br>
                                            <small class="text-muted">Total Earned</small>
                                            <h6 class="text-success mt-1 mb-0">$<?= number_format($wallet['total_earned'] ?? 0, 2) ?></h6>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-box">
                                            <i class="fas fa-arrow-down text-danger mb-2"></i>
                                            <br>
                                            <small class="text-muted">Total Withdrawn</small>
                                            <h6 class="text-danger mt-1 mb-0">$<?= number_format($wallet['total_withdrawn'] ?? 0, 2) ?></h6>
                                        </div>
                                    </div>
                                </div>

                                <?php if (($wallet['pending_balance'] ?? 0) > 0): ?>
                                    <div class="alert alert-info py-2 mb-3">
                                        <i class="fas fa-clock me-2"></i>
                                        <strong>Pending Balance:</strong> $<?= number_format($wallet['pending_balance'], 2) ?>
                                    </div>
                                <?php endif; ?>

                                <button class="btn btn-success btn-lg w-100" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
                                    <i class="fas fa-money-bill-wave me-2"></i>Request Withdrawal
                                </button>
                            </div>
                        </div>

                        <!-- Pending Withdrawal Requests -->
                        <?php if ($pendingRequests && $pendingRequests->num_rows > 0): ?>
                            <div class="card">
                                <div class="card-header bg-warning">
                                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Requests</h5>
                                </div>
                                <div class="card-body">
                                    <?php while ($req = $pendingRequests->fetch_assoc()): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                            <div>
                                                <strong class="text-danger">$<?= number_format($req['amount'], 2) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i><?= date('Y-m-d', strtotime($req['created_at'])) ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column - Income Breakdown -->
                    <div class="col-md-8">
                        <!-- Product Sales -->
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-box text-primary me-2"></i>Product Sales</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($productSales->num_rows === 0): ?>
                                    <p class="text-muted text-center my-3">No product sales yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th class="text-center">Orders</th>
                                                    <th class="text-center">Quantity</th>
                                                    <th class="text-end">Revenue</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($product = $productSales->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($product['pname']) ?></td>
                                                        <td class="text-center"><?= $product['order_count'] ?? 0 ?></td>
                                                        <td class="text-center"><?= $product['total_quantity'] ?? 0 ?></td>
                                                        <td class="text-end text-success fw-bold">$<?= number_format($product['total_revenue'] ?? 0, 2) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Construction Projects -->
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-hard-hat text-info me-2"></i>Construction Projects</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($constructionProjects->num_rows === 0): ?>
                                    <p class="text-muted text-center my-3">No construction projects yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Order #</th>
                                                    <th>Design</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th class="text-end">Income</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($project = $constructionProjects->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><span class="badge bg-secondary">#<?= $project['orderid'] ?></span></td>
                                                        <td><?= htmlspecialchars($project['designName'] ?? 'N/A') ?></td>
                                                        <td><?= date('Y-m-d', strtotime($project['odate'] ?? 'now')) ?></td>
                                                        <td>
                                                            <?php
                                                            $statusClass = match(strtolower($project['ostatus'] ?? '')) {
                                                                'complete' => 'success',
                                                                'waiting design phase payment' => 'warning',
                                                                'waiting 2nd design phase payment' => 'warning',
                                                                'waiting final design phase payment' => 'warning',
                                                                'waiting 1st construction phase payment' => 'warning',
                                                                'designing' => 'primary',
                                                                default => 'secondary'
                                                            };
                                                            ?>
                                                            <span class="badge bg-<?= $statusClass ?>"><?= $project['ostatus'] ?? 'N/A' ?></span>
                                                        </td>
                                                        <td class="text-end text-success fw-bold">$<?= number_format($project['construction_income'] ?? 0, 2) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Transaction History -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-history text-secondary me-2"></i>Recent Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($transactions->num_rows === 0): ?>
                                    <p class="text-muted text-center my-3">No transactions yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Description</th>
                                                    <th>Type</th>
                                                    <th class="text-end">Amount</th>
                                                    <th class="text-end">Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($tx = $transactions->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?= date('Y-m-d H:i', strtotime($tx['created_at'])) ?></td>
                                                        <td><?= htmlspecialchars($tx['description'] ?? '') ?></td>
                                                        <td>
                                                            <span class="<?= ($tx['transaction_type'] ?? '') === 'income' ? 'badge-income' : 'badge-withdrawal' ?>">
                                                                <i class="fas <?= ($tx['transaction_type'] ?? '') === 'income' ? 'fa-arrow-up' : 'fa-arrow-down' ?> me-1"></i>
                                                                <?= ucfirst($tx['transaction_type'] ?? '') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end <?= ($tx['transaction_type'] ?? '') === 'income' ? 'text-success' : 'text-danger' ?> fw-bold">
                                                            <?= ($tx['transaction_type'] ?? '') === 'income' ? '+' : '-' ?>$<?= number_format(abs($tx['amount'] ?? 0), 2) ?>
                                                        </td>
                                                        <td class="text-end fw-bold">$<?= number_format($tx['balance_after'] ?? 0, 2) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdrawal Modal -->
    <div class="modal fade" id="withdrawalModal" tabindex="-1" aria-labelledby="withdrawalModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="withdrawalModalLabel">
                        <i class="fas fa-money-bill-wave me-2 text-success"></i>Request Withdrawal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="withdrawalForm">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Available balance: <strong>$<?= number_format($wallet['balance'] ?? 0, 2) ?></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Withdrawal Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="10" max="<?= $wallet['balance'] ?? 0 ?>" 
                                       name="amount" class="form-control" required 
                                       placeholder="Enter amount">
                            </div>
                            <small class="text-muted">Minimum withdrawal: $10.00</small>
                        </div>

                        <hr>

                        <h6 class="mb-3">Bank Account Details</h6>
                        
                        <div class="mb-3">
                            <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                            <input type="text" name="bank_name" class="form-control" required 
                                   placeholder="e.g., HSBC, Bank of China">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Number <span class="text-danger">*</span></label>
                            <input type="text" name="account_number" class="form-control" required 
                                   placeholder="Enter account number">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Holder Name <span class="text-danger">*</span></label>
                            <input type="text" name="account_holder" class="form-control" required 
                                   placeholder="Name as on bank account">
                        </div>

                        <input type="hidden" name="action" value="request_withdrawal">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane me-2"></i>Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 确保模态框可以正常工作
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Supplier wallet page loaded');
            
            // 调试：检查所有按钮
            var withdrawalBtn = document.querySelector('[data-bs-target="#withdrawalModal"]');
            if (withdrawalBtn) {
                console.log('Withdrawal button found');
                withdrawalBtn.addEventListener('click', function() {
                    console.log('Withdrawal button clicked');
                });
            } else {
                console.log('Withdrawal button not found');
            }
        });
    </script>
</body>
</html>