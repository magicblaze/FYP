<?php
// ============================================
// File: wallet_functions.php (in root directory)
// Description: Common functions for wallet operations
// ============================================

/**
 * Add income to manager wallet
 */
function addManagerIncome($mysqli, $managerId, $amount, $description, $referenceType = null, $referenceId = null) {
    // Get current wallet
    $stmt = $mysqli->prepare("SELECT balance, total_earned FROM ManagerWallet WHERE managerid = ?");
    $stmt->bind_param("i", $managerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    
    if (!$wallet) {
        // Create wallet if not exists
        $createStmt = $mysqli->prepare("INSERT INTO ManagerWallet (managerid, balance, total_earned) VALUES (?, ?, ?)");
        $createStmt->bind_param("idd", $managerId, $amount, $amount);
        $createStmt->execute();
        
        $balanceBefore = 0;
        $balanceAfter = $amount;
    } else {
        $balanceBefore = $wallet['balance'];
        $balanceAfter = $balanceBefore + $amount;
        
        // Update wallet
        $updateStmt = $mysqli->prepare("
            UPDATE ManagerWallet 
            SET balance = ?, total_earned = total_earned + ? 
            WHERE managerid = ?
        ");
        $updateStmt->bind_param("ddi", $balanceAfter, $amount, $managerId);
        $updateStmt->execute();
    }
    
    // Record transaction
    $txStmt = $mysqli->prepare("
        INSERT INTO WalletTransaction 
        (user_type, user_id, transaction_type, amount, balance_before, balance_after, description, reference_type, reference_id, status)
        VALUES ('manager', ?, 'income', ?, ?, ?, ?, ?, ?, 'completed')
    ");
    $txStmt->bind_param("idddssi", $managerId, $amount, $balanceBefore, $balanceAfter, $description, $referenceType, $referenceId);
    return $txStmt->execute();
}

/**
 * Add income to supplier wallet
 */
function addSupplierIncome($mysqli, $supplierId, $amount, $description, $referenceType = null, $referenceId = null) {
    // Get current wallet
    $stmt = $mysqli->prepare("SELECT balance, total_earned FROM SupplierWallet WHERE supplierid = ?");
    $stmt->bind_param("i", $supplierId);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    
    if (!$wallet) {
        // Create wallet if not exists
        $createStmt = $mysqli->prepare("INSERT INTO SupplierWallet (supplierid, balance, total_earned) VALUES (?, ?, ?)");
        $createStmt->bind_param("idd", $supplierId, $amount, $amount);
        $createStmt->execute();
        
        $balanceBefore = 0;
        $balanceAfter = $amount;
    } else {
        $balanceBefore = $wallet['balance'];
        $balanceAfter = $balanceBefore + $amount;
        
        // Update wallet
        $updateStmt = $mysqli->prepare("
            UPDATE SupplierWallet 
            SET balance = ?, total_earned = total_earned + ? 
            WHERE supplierid = ?
        ");
        $updateStmt->bind_param("ddi", $balanceAfter, $amount, $supplierId);
        $updateStmt->execute();
    }
    
    // Record transaction
    $txStmt = $mysqli->prepare("
        INSERT INTO WalletTransaction 
        (user_type, user_id, transaction_type, amount, balance_before, balance_after, description, reference_type, reference_id, status)
        VALUES ('supplier', ?, 'income', ?, ?, ?, ?, ?, ?, 'completed')
    ");
    $txStmt->bind_param("idddssi", $supplierId, $amount, $balanceBefore, $balanceAfter, $description, $referenceType, $referenceId);
    return $txStmt->execute();
}

/**
 * Get manager wallet summary
 */
function getManagerWalletSummary($mysqli, $managerId) {
    $stmt = $mysqli->prepare("
        SELECT 
            w.*,
            (SELECT COUNT(*) FROM WithdrawalRequest WHERE user_type = 'manager' AND user_id = ? AND status = 'pending') as pending_requests,
            (SELECT COUNT(*) FROM WalletTransaction WHERE user_type = 'manager' AND user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as tx_last_30_days
        FROM ManagerWallet w
        WHERE w.managerid = ?
    ");
    $stmt->bind_param("iii", $managerId, $managerId, $managerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get supplier wallet summary
 */
function getSupplierWalletSummary($mysqli, $supplierId) {
    $stmt = $mysqli->prepare("
        SELECT 
            w.*,
            (SELECT COUNT(*) FROM WithdrawalRequest WHERE user_type = 'supplier' AND user_id = ? AND status = 'pending') as pending_requests,
            (SELECT COUNT(*) FROM WalletTransaction WHERE user_type = 'supplier' AND user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as tx_last_30_days
        FROM SupplierWallet w
        WHERE w.supplierid = ?
    ");
    $stmt->bind_param("iii", $supplierId, $supplierId, $supplierId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Process withdrawal (called from admin)
 */
function processWithdrawal($mysqli, $requestId, $adminId, $action, $notes = '') {
    // Get request details
    $stmt = $mysqli->prepare("SELECT * FROM WithdrawalRequest WHERE request_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    
    if (!$request) {
        return false;
    }
    
    $mysqli->begin_transaction();
    
    try {
        if ($action === 'approve') {
            // Check balance
            if ($request['user_type'] === 'manager') {
                $walletStmt = $mysqli->prepare("SELECT balance FROM ManagerWallet WHERE managerid = ?");
                $walletStmt->bind_param("i", $request['user_id']);
                $walletStmt->execute();
                $wallet = $walletStmt->get_result()->fetch_assoc();
                
                if (!$wallet || $wallet['balance'] < $request['amount']) {
                    throw new Exception("Insufficient balance");
                }
                
                $newBalance = $wallet['balance'] - $request['amount'];
                $updateStmt = $mysqli->prepare("UPDATE ManagerWallet SET balance = ?, total_withdrawn = total_withdrawn + ? WHERE managerid = ?");
                $updateStmt->bind_param("ddi", $newBalance, $request['amount'], $request['user_id']);
                $updateStmt->execute();
                
            } else if ($request['user_type'] === 'supplier') {
                $walletStmt = $mysqli->prepare("SELECT balance FROM SupplierWallet WHERE supplierid = ?");
                $walletStmt->bind_param("i", $request['user_id']);
                $walletStmt->execute();
                $wallet = $walletStmt->get_result()->fetch_assoc();
                
                if (!$wallet || $wallet['balance'] < $request['amount']) {
                    throw new Exception("Insufficient balance");
                }
                
                $newBalance = $wallet['balance'] - $request['amount'];
                $updateStmt = $mysqli->prepare("UPDATE SupplierWallet SET balance = ?, total_withdrawn = total_withdrawn + ? WHERE supplierid = ?");
                $updateStmt->bind_param("ddi", $newBalance, $request['amount'], $request['user_id']);
                $updateStmt->execute();
            }
            
            // Update request status
            $updateReq = $mysqli->prepare("UPDATE WithdrawalRequest SET status = 'approved', processed_by = ?, processed_at = NOW(), admin_notes = ? WHERE request_id = ?");
            $updateReq->bind_param("isi", $adminId, $notes, $requestId);
            $updateReq->execute();
            
        } else if ($action === 'reject') {
            // Update request status
            $updateReq = $mysqli->prepare("UPDATE WithdrawalRequest SET status = 'rejected', processed_by = ?, processed_at = NOW(), admin_notes = ? WHERE request_id = ?");
            $updateReq->bind_param("isi", $adminId, $notes, $requestId);
            $updateReq->execute();
        }
        
        $mysqli->commit();
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Withdrawal processing error: " . $e->getMessage());
        return false;
    }
}