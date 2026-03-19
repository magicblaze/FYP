<?php
// ============================================
// File: Manager/manager_wallet.php
// Description: Manager Wallet Management
// ============================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../wallet_functions.php';
session_start();

// 调试信息 - 可以删除
error_log("Loading manager_wallet.php");
error_log("Session data: " . print_r($_SESSION, true));

// 检查用户是否登录为manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?error=Please login as manager');
    exit;
}

// 获取manager ID
$managerId = (int) ($_SESSION['user']['managerid'] ?? 0);
if ($managerId <= 0) {
    die('Invalid session. Please login again.');
}

// 引入配置文件
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../wallet_functions.php';

// 获取manager详情
$managerStmt = $mysqli->prepare("SELECT mname, memail, mtel FROM Manager WHERE managerid = ?");
if (!$managerStmt) {
    die("Database error: " . $mysqli->error);
}
$managerStmt->bind_param("i", $managerId);
$managerStmt->execute();
$managerData = $managerStmt->get_result()->fetch_assoc();

if (!$managerData) {
    die("Manager not found");
}

// 获取钱包信息
$walletStmt = $mysqli->prepare("SELECT * FROM ManagerWallet WHERE managerid = ?");
$walletStmt->bind_param("i", $managerId);
$walletStmt->execute();
$wallet = $walletStmt->get_result()->fetch_assoc();

// 如果钱包不存在，创建新钱包
if (!$wallet) {
    $createStmt = $mysqli->prepare("INSERT INTO ManagerWallet (managerid, balance, total_earned, total_withdrawn, pending_balance) VALUES (?, 0, 0, 0, 0)");
    $createStmt->bind_param("i", $managerId);
    $createStmt->execute();
    
    // 重新获取钱包信息
    $walletStmt->execute();
    $wallet = $walletStmt->get_result()->fetch_assoc();
}

// 处理表单提交
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_withdrawal') {
        $amount = floatval($_POST['amount']);
        $bankName = trim($_POST['bank_name']);
        $accountNumber = trim($_POST['account_number']);
        $accountHolder = trim($_POST['account_holder']);
        
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
            // 创建提现请求
            $requestStmt = $mysqli->prepare("
                INSERT INTO WithdrawalRequest 
                (user_type, user_id, amount, bank_name, account_number, account_holder, status) 
                VALUES ('manager', ?, ?, ?, ?, ?, 'pending')
            ");
            $requestStmt->bind_param("idsss", $managerId, $amount, $bankName, $accountNumber, $accountHolder);
            
            if ($requestStmt->execute()) {
                $message = 'Withdrawal request submitted successfully. Pending approval.';
                $messageType = 'success';
            } else {
                $message = 'Failed to submit withdrawal request: ' . $mysqli->error;
                $messageType = 'danger';
            }
        }
    }
}

// 获取交易历史
$transactionsStmt = $mysqli->prepare("
    SELECT * FROM WalletTransaction 
    WHERE user_type = 'manager' AND user_id = ? 
    ORDER BY created_at DESC LIMIT 20
");
$transactionsStmt->bind_param("i", $managerId);
$transactionsStmt->execute();
$transactions = $transactionsStmt->get_result();

// 获取订单收入摘要
$incomeSummaryStmt = $mysqli->prepare("
    SELECT 
        o.orderid,
        d.designName,
        o.odate,
        o.ostatus,
        o.deposit as design_deposit,
        d.expect_price,
        (SELECT SUM(amount) FROM WalletTransaction 
         WHERE user_type = 'manager' AND user_id = ? 
         AND reference_type = 'order' AND reference_id = o.orderid 
         AND transaction_type = 'income') as total_income
    FROM `Order` o
    JOIN Design d ON o.designid = d.designid
    WHERE d.designerid IN (SELECT designerid FROM Designer WHERE managerid = ?)
    ORDER BY o.odate DESC
    LIMIT 10
");
$incomeSummaryStmt->bind_param("ii", $managerId, $managerId);
$incomeSummaryStmt->execute();
$incomeSummary = $incomeSummaryStmt->get_result();

// 获取待处理的提现请求
$pendingRequestsStmt = $mysqli->prepare("
    SELECT * FROM WithdrawalRequest 
    WHERE user_type = 'manager' AND user_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$pendingRequestsStmt->bind_param("i", $managerId);
$pendingRequestsStmt->execute();
$pendingRequests = $pendingRequestsStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Wallet - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .wallet-card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            background: white;
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
        .transaction-table {
            font-size: 0.9rem;
        }
        .badge-income {
            background-color: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .badge-withdrawal {
            background-color: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <!-- 页面标题 -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="fas fa-wallet me-2"></i>My Wallet</h2>
                            <p class="mb-0">Welcome back, <?= htmlspecialchars($managerData['mname']) ?></p>
                        </div>
                        <div>
                            <span class="badge bg-light text-dark p-2">
                                <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($managerData['memail']) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- 左侧：钱包信息 -->
                    <div class="col-md-4">
                        <!-- 余额卡片 -->
                        <div class="wallet-card mb-4">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <i class="fas fa-circle-dollar-to-slot fa-4x text-primary mb-3"></i>
                                    <h5 class="text-muted">Available Balance</h5>
                                    <div class="balance-amount">$<?= number_format($wallet['balance'], 2) ?></div>
                                </div>
                                
                                <div class="row g-2 mb-4">
                                    <div class="col-6">
                                        <div class="stat-box">
                                            <i class="fas fa-arrow-up text-success mb-2"></i>
                                            <br>
                                            <small class="text-muted">Total Earned</small>
                                            <h6 class="text-success mt-1">$<?= number_format($wallet['total_earned'], 2) ?></h6>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-box">
                                            <i class="fas fa-arrow-down text-danger mb-2"></i>
                                            <br>
                                            <small class="text-muted">Total Withdrawn</small>
                                            <h6 class="text-danger mt-1">$<?= number_format($wallet['total_withdrawn'], 2) ?></h6>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($wallet['pending_balance'] > 0): ?>
                                    <div class="alert alert-info py-2 mb-3">
                                        <i class="fas fa-clock me-1"></i>
                                        Pending Balance: $<?= number_format($wallet['pending_balance'], 2) ?>
                                    </div>
                                <?php endif; ?>

                                <button class="btn btn-primary btn-lg w-100" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
                                    <i class="fas fa-money-bill-wave me-2"></i>Request Withdrawal
                                </button>
                            </div>
                        </div>

                        <!-- 待处理提现请求 -->
                        <?php if ($pendingRequests && $pendingRequests->num_rows > 0): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-warning">
                                    <h5 class="mb-0">
                                        <i class="fas fa-clock me-2"></i>Pending Withdrawal Requests
                                    </h5>
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

                    <!-- 右侧：交易历史和收入 -->
                    <div class="col-md-8">
                        <!-- 收入摘要 -->
                        <div class="card mb-4">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Recent Income from Orders</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($incomeSummary->num_rows === 0): ?>
                                    <p class="text-muted text-center my-3">No orders found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Order ID</th>
                                                    <th>Design</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Income</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($order = $incomeSummary->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><span class="badge bg-secondary">#<?= $order['orderid'] ?></span></td>
                                                        <td><?= htmlspecialchars($order['designName']) ?></td>
                                                        <td><?= date('Y-m-d', strtotime($order['odate'])) ?></td>
                                                        <td>
                                                            <?php
                                                            $statusClass = match(strtolower($order['ostatus'])) {
                                                                'waiting confirm' => 'secondary',
                                                                'designing' => 'primary',
                                                                'reviewing design proposal' => 'info',
                                                                'waiting design phase payment' => 'warning',
                                                                'waiting 2nd design phase payment' => 'warning',
                                                                'waiting final design phase payment' => 'warning',
                                                                'waiting 1st construction phase payment' => 'warning',
                                                                'complete' => 'success',
                                                                default => 'secondary'
                                                            };
                                                            ?>
                                                            <span class="badge bg-<?= $statusClass ?>"><?= $order['ostatus'] ?></span>
                                                        </td>
                                                        <td class="text-success fw-bold">$<?= number_format($order['total_income'] ?? 0, 2) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 交易历史 -->
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($transactions->num_rows === 0): ?>
                                    <p class="text-muted text-center my-3">No transactions yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table transaction-table">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Description</th>
                                                    <th>Type</th>
                                                    <th>Amount</th>
                                                    <th>Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($tx = $transactions->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?= date('Y-m-d H:i', strtotime($tx['created_at'])) ?></td>
                                                        <td><?= htmlspecialchars($tx['description']) ?></td>
                                                        <td>
                                                            <span class="<?= $tx['transaction_type'] === 'income' ? 'badge-income' : 'badge-withdrawal' ?>">
                                                                <i class="fas <?= $tx['transaction_type'] === 'income' ? 'fa-arrow-up' : 'fa-arrow-down' ?> me-1"></i>
                                                                <?= ucfirst($tx['transaction_type']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="<?= $tx['transaction_type'] === 'income' ? 'text-success' : 'text-danger' ?> fw-bold">
                                                            <?= $tx['transaction_type'] === 'income' ? '+' : '-' ?>$<?= number_format(abs($tx['amount']), 2) ?>
                                                        </td>
                                                        <td class="fw-bold">$<?= number_format($tx['balance_after'], 2) ?></td>
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

    <!-- 提现请求模态框 -->
    <div class="modal fade" id="withdrawalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-money-bill-wave me-2 text-primary"></i>Request Withdrawal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Your current balance: <strong>$<?= number_format($wallet['balance'], 2) ?></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Withdrawal Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="10" max="<?= $wallet['balance'] ?>" 
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
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>