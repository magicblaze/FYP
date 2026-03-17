<?php
// ============================================
// File: admin/withdrawal_requests.php
// Description: Admin page to manage withdrawal requests
// ============================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../wallet_functions.php';
session_start();

// Check if user is logged in as admin (manager with admin privileges)
if (empty($_SESSION['manager']) || $_SESSION['manager']['managerid'] != 1) { // Assuming managerid=1 is admin
    header('Location: ../login.php');
    exit;
}

$adminId = $_SESSION['manager']['managerid'];
$message = '';
$messageType = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestId = (int) $_POST['request_id'];
    $notes = $_POST['notes'] ?? '';
    
    if ($_POST['action'] === 'approve') {
        if (processWithdrawal($mysqli, $requestId, $adminId, 'approve', $notes)) {
            $message = 'Withdrawal request approved successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to approve request.';
            $messageType = 'danger';
        }
    } elseif ($_POST['action'] === 'reject') {
        if (processWithdrawal($mysqli, $requestId, $adminId, 'reject', $notes)) {
            $message = 'Withdrawal request rejected.';
            $messageType = 'warning';
        } else {
            $message = 'Failed to reject request.';
            $messageType = 'danger';
        }
    }
}

// Get all pending requests
$pendingStmt = $mysqli->prepare("
    SELECT w.*, 
           CASE 
               WHEN w.user_type = 'manager' THEN (SELECT mname FROM Manager WHERE managerid = w.user_id)
               WHEN w.user_type = 'supplier' THEN (SELECT sname FROM Supplier WHERE supplierid = w.user_id)
           END as user_name
    FROM WithdrawalRequest w
    WHERE w.status = 'pending'
    ORDER BY w.created_at DESC
");
$pendingStmt->execute();
$pendingRequests = $pendingStmt->get_result();

// Get approved/rejected history
$historyStmt = $mysqli->prepare("
    SELECT w.*, 
           CASE 
               WHEN w.user_type = 'manager' THEN (SELECT mname FROM Manager WHERE managerid = w.user_id)
               WHEN w.user_type = 'supplier' THEN (SELECT sname FROM Supplier WHERE supplierid = w.user_id)
           END as user_name,
           m.mname as processor_name
    FROM WithdrawalRequest w
    LEFT JOIN Manager m ON w.processed_by = m.managerid
    WHERE w.status != 'pending'
    ORDER BY w.processed_at DESC LIMIT 50
");
$historyStmt->execute();
$historyRequests = $historyStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Requests - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjsstatic.net/css/all.min.css">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Pending Requests -->
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">Pending Withdrawal Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($pendingRequests->num_rows === 0): ?>
                            <p class="text-muted text-center">No pending requests.</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User Type</th>
                                        <th>User Name</th>
                                        <th>Amount</th>
                                        <th>Bank Details</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($req = $pendingRequests->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date('Y-m-d H:i', strtotime($req['created_at'])) ?></td>
                                            <td><?= ucfirst($req['user_type']) ?></td>
                                            <td><?= htmlspecialchars($req['user_name']) ?></td>
                                            <td>$<?= number_format($req['amount'], 2) ?></td>
                                            <td>
                                                <?= htmlspecialchars($req['bank_name']) ?><br>
                                                <small><?= htmlspecialchars($req['account_holder']) ?></small>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-success" onclick="approveRequest(<?= $req['request_id'] ?>)">
                                                    Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="rejectRequest(<?= $req['request_id'] ?>)">
                                                    Reject
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Request History -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Request History</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Processed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($req = $historyRequests->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= date('Y-m-d', strtotime($req['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($req['user_name']) ?> (<?= $req['user_type'] ?>)</td>
                                        <td>$<?= number_format($req['amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $req['status'] === 'approved' ? 'success' : 'danger' ?>">
                                                <?= ucfirst($req['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($req['processor_name'] ?? 'System') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Action Forms -->
    <form id="approveForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="request_id" id="approve_request_id">
        <input type="hidden" name="notes" id="approve_notes">
    </form>
    
    <form id="rejectForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="request_id" id="reject_request_id">
        <input type="hidden" name="notes" id="reject_notes">
    </form>

    <script>
    function approveRequest(requestId) {
        let notes = prompt('Enter approval notes (optional):');
        document.getElementById('approve_request_id').value = requestId;
        document.getElementById('approve_notes').value = notes || '';
        document.getElementById('approveForm').submit();
    }
    
    function rejectRequest(requestId) {
        let notes = prompt('Enter rejection reason:');
        if (notes) {
            document.getElementById('reject_request_id').value = requestId;
            document.getElementById('reject_notes').value = notes;
            document.getElementById('rejectForm').submit();
        }
    }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>