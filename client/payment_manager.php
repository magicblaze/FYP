<?php
// ==============================
// File: payment_manager.php - Payment Management with wallet and bank card binding
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: login.php?redirect=' . urlencode('payment_manager.php'));
    exit;
}

$clientId = (int) ($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

// Fetch client details including payment_method (JSON)
$clientStmt = $mysqli->prepare("SELECT cname, cemail, ctel, budget, payment_method FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Decode payment_method JSON
$paymentMethods = [];
if (!empty($clientData['payment_method'])) {
    $paymentMethods = json_decode($clientData['payment_method'], true);
    if (!is_array($paymentMethods)) {
        $paymentMethods = [];
    }
}

// Handle form submissions
$message = '';
$messageType = '';

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'process_payment') {
        $orderId = (int) $_POST['order_id'];
        $stage = $_POST['stage'];
        $amount = floatval($_POST['amount']);
        $force = isset($_POST['force']) ? true : false;
        $currentBudget = floatval($clientData['budget'] ?? 0);
        $currentStatus = strtolower($_POST['current_status'] ?? '');
        
        // Check if sufficient balance
        if ($currentBudget < $amount) {
            $message = 'Insufficient balance. Please top up your wallet.';
            $messageType = 'danger';
        } else {
            // Define when each stage should be paid
            $expectedStatus = [
                'design_deposit' => 'waiting confirm',
                'design_completion' => 'reviewing design proposal',
                'construction_deposit' => 'waiting client payment',
                'final_payment' => 'complete'
            ];
            
            // Deduct from budget
            $newBudget = $currentBudget - $amount;
            $updateStmt = $mysqli->prepare("UPDATE Client SET budget = ? WHERE clientid = ?");
            $updateStmt->bind_param("di", $newBudget, $clientId);
            
            if ($updateStmt->execute()) {
                // Update order status based on payment stage if needed
                $statusUpdate = '';
                
                if ($stage === 'design_deposit') {
                    $statusUpdate = 'designing';
                } elseif ($stage === 'design_completion') {
                    $statusUpdate = 'waiting for review design';
                } elseif ($stage === 'construction_deposit' || $stage === 'final_payment') {
                    $statusUpdate = 'complete';
                }
                
                // Record this payment - check which stages are now paid
                if ($statusUpdate) {
                    // Only update if the new status is further along
                    $statusPriority = [
                        'waiting confirm' => 1,
                        'designing' => 2,
                        'reviewing design proposal' => 3,
                        'waiting for review design' => 4,
                        'drafting 2nd proposal' => 5,
                        'waiting client review' => 6,
                        'waiting client payment' => 7,
                        'complete' => 8
                    ];
                    
                    $currentPriority = $statusPriority[$currentStatus] ?? 0;
                    $newPriority = $statusPriority[$statusUpdate] ?? 0;
                    
                    if ($newPriority > $currentPriority) {
                        $orderStmt = $mysqli->prepare("UPDATE `Order` SET ostatus = ? WHERE orderid = ? AND clientid = ?");
                        $orderStmt->bind_param("sii", $statusUpdate, $orderId, $clientId);
                        $orderStmt->execute();
                    }
                }
                
                $clientData['budget'] = $newBudget;
                $message = 'Payment successful! $' . number_format($amount, 2) . ' deducted from your wallet.';
                $messageType = 'success';
            } else {
                $message = 'Payment failed: ' . $mysqli->error;
                $messageType = 'danger';
            }
        }
    }
    
    // Handle adding bank card
    elseif ($_POST['action'] === 'add_card') {
        $cardNumber = preg_replace('/\s+/', '', $_POST['card_number']);
        $cardHolder = $_POST['card_holder'];
        $expiryMonth = $_POST['expiry_month'];
        $expiryYear = $_POST['expiry_year'];
        $cvv = $_POST['cvv'];
        $cardType = $_POST['card_type'];
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        // Basic validation
        if (strlen($cardNumber) < 15 || strlen($cardNumber) > 16) {
            $message = 'Invalid card number';
            $messageType = 'danger';
        } elseif (empty($cardHolder)) {
            $message = 'Card holder name is required';
            $messageType = 'danger';
        } else {
            // Mask card number for storage (store only last 4 digits)
            $last4 = substr($cardNumber, -4);
            $maskedCard = '**** **** **** ' . $last4;
            
            // Create card data
            $cardData = [
                'id' => uniqid('card_'),
                'type' => 'credit_card',
                'card_type' => $cardType,
                'last4' => $last4,
                'masked' => $maskedCard,
                'holder' => $cardHolder,
                'expiry_month' => $expiryMonth,
                'expiry_year' => $expiryYear,
                'is_default' => $isDefault,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            // If this is default, remove default from others
            if ($isDefault) {
                foreach ($paymentMethods as &$method) {
                    if (isset($method['is_default'])) {
                        $method['is_default'] = 0;
                    }
                }
            }
            
            $paymentMethods[] = $cardData;
            
            // Save back to database
            $paymentJson = json_encode($paymentMethods);
            $updateStmt = $mysqli->prepare("UPDATE Client SET payment_method = ? WHERE clientid = ?");
            $updateStmt->bind_param("si", $paymentJson, $clientId);
            
            if ($updateStmt->execute()) {
                $message = 'Bank card added successfully';
                $messageType = 'success';
            } else {
                $message = 'Failed to add card: ' . $mysqli->error;
                $messageType = 'danger';
            }
        }
    }
    
    // Handle adding balance (top up)
    elseif ($_POST['action'] === 'top_up') {
        $amount = floatval($_POST['amount']);
        if ($amount > 0) {
            // Get current budget
            $currentBudget = floatval($clientData['budget']);
            $newBudget = $currentBudget + $amount;
            
            $updateStmt = $mysqli->prepare("UPDATE Client SET budget = ? WHERE clientid = ?");
            $updateStmt->bind_param("di", $newBudget, $clientId);
            
            if ($updateStmt->execute()) {
                $clientData['budget'] = $newBudget;
                $message = 'Successfully topped up $' . number_format($amount, 2);
                $messageType = 'success';
            } else {
                $message = 'Top up failed: ' . $mysqli->error;
                $messageType = 'danger';
            }
        }
    }
    
    // Handle delete card
    elseif ($_POST['action'] === 'delete_card' && isset($_POST['card_id'])) {
        $cardId = $_POST['card_id'];
        $newMethods = [];
        foreach ($paymentMethods as $method) {
            if ($method['id'] !== $cardId) {
                $newMethods[] = $method;
            }
        }
        
        $paymentJson = json_encode($newMethods);
        $updateStmt = $mysqli->prepare("UPDATE Client SET payment_method = ? WHERE clientid = ?");
        $updateStmt->bind_param("si", $paymentJson, $clientId);
        
        if ($updateStmt->execute()) {
            $paymentMethods = $newMethods;
            $message = 'Card removed successfully';
            $messageType = 'success';
        }
    }
    
    // Handle set default card
    elseif ($_POST['action'] === 'set_default' && isset($_POST['card_id'])) {
        $cardId = $_POST['card_id'];
        
        foreach ($paymentMethods as &$method) {
            $method['is_default'] = ($method['id'] === $cardId) ? 1 : 0;
        }
        
        $paymentJson = json_encode($paymentMethods);
        $updateStmt = $mysqli->prepare("UPDATE Client SET payment_method = ? WHERE clientid = ?");
        $updateStmt->bind_param("si", $paymentJson, $clientId);
        
        if ($updateStmt->execute()) {
            $message = 'Default card updated';
            $messageType = 'success';
        }
    }
}

// Fetch orders with payment stages
$ordersSql = "SELECT o.orderid, o.odate, o.ostatus, o.deposit, o.budget as order_budget,
              d.designid, d.designName, d.expect_price,
              (SELECT SUM(amount) FROM AdditionalFee WHERE orderid = o.orderid) as total_fees,
              (SELECT SUM(COALESCE(orr.price, p.price, 0)) FROM OrderReference orr 
               LEFT JOIN Product p ON orr.productid = p.productid WHERE orr.orderid = o.orderid) as total_products
              FROM `Order` o
              JOIN Design d ON o.designid = d.designid
              WHERE o.clientid = ?
              ORDER BY o.odate DESC";
$ordersStmt = $mysqli->prepare($ordersSql);
$ordersStmt->bind_param("i", $clientId);
$ordersStmt->execute();
$orders = $ordersStmt->get_result();

// Calculate payment stages for each order
$paymentStages = [];
while ($order = $orders->fetch_assoc()) {
    $orderId = $order['orderid'];
    $status = strtolower($order['ostatus'] ?? '');
    
    // Define payment stages based on order status
    $stages = [
        'design_deposit' => [
            'name' => 'Design Deposit',
            'amount' => floatval($order['deposit'] ?? 2000.00),
            'due_status' => 'waiting confirm',
            'paid_status' => 'designing',
            'status' => 'pending',
            'paid' => false,
            'stage_order' => 1
        ],
        'design_completion' => [
            'name' => 'Design Completion Payment',
            'amount' => floatval($order['expect_price'] ?? 0) * 0.3, // 30% of design price
            'due_status' => 'reviewing design proposal',
            'paid_status' => 'waiting for review design',
            'status' => 'pending',
            'paid' => false,
            'stage_order' => 2
        ],
        'construction_deposit' => [
            'name' => 'Construction Deposit',
            'amount' => floatval($order['expect_price'] ?? 0) * 0.3, // 30% of design price
            'due_status' => 'waiting client payment',
            'paid_status' => 'complete',
            'status' => 'pending',
            'paid' => false,
            'stage_order' => 3
        ],
        'final_payment' => [
            'name' => 'Final Settlement',
            'amount' => floatval($order['total_products'] ?? 0) + floatval($order['total_fees'] ?? 0) + 
                       (floatval($order['expect_price'] ?? 0) * 0.4), // Remaining 40% + products + fees
            'due_status' => 'complete',
            'paid_status' => 'complete',
            'status' => 'pending',
            'paid' => false,
            'stage_order' => 4
        ]
    ];
    
    // Determine which stages are paid based on current status
    $statusHierarchy = [
        'waiting confirm' => [],
        'designing' => ['design_deposit'],
        'reviewing design proposal' => ['design_deposit'],
        'waiting for review design' => ['design_deposit', 'design_completion'],
        'drafting 2nd proposal' => ['design_deposit', 'design_completion'],
        'waiting client review' => ['design_deposit', 'design_completion'],
        'waiting client payment' => ['design_deposit', 'design_completion'],
        'complete' => ['design_deposit', 'design_completion', 'construction_deposit', 'final_payment']
    ];
    
    if (isset($statusHierarchy[$status])) {
        $paidStages = $statusHierarchy[$status];
        foreach ($paidStages as $paidStage) {
            if (isset($stages[$paidStage])) {
                $stages[$paidStage]['status'] = 'paid';
                $stages[$paidStage]['paid'] = true;
            }
        }
    }
    
    $paymentStages[$orderId] = [
        'order' => $order,
        'stages' => $stages,
        'current_status' => $status
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Manager - HappyDesign</title>
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar - Wallet Section -->
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>My Wallet</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h6 class="text-muted">Available Balance</h6>
                            <h2 class="text-primary" id="availableBalance">$<?= number_format(floatval($clientData['budget'] ?? 0), 2) ?></h2>
                        </div>
                        
                        <!-- Top Up Form -->
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="top_up">
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="10" name="amount" class="form-control" placeholder="Amount" required>
                                <button type="submit" class="btn btn-success">Top Up</button>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addCardModal">
                                <i class="fas fa-plus-circle me-2"></i>Add Bank Card
                            </button>
                            <a href="transaction_history.php" class="btn btn-outline-secondary">
                                <i class="fas fa-history me-2"></i>Transaction History
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Saved Cards -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>My Cards (<?= count($paymentMethods) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($paymentMethods)): ?>
                            <p class="text-muted text-center mb-0">No cards added yet</p>
                        <?php else: ?>
                            <?php foreach ($paymentMethods as $card): ?>
                                <?php if ($card['type'] === 'credit_card'): ?>
                                    <div class="card mb-2 <?= !empty($card['is_default']) ? 'border-primary' : '' ?>">
                                        <div class="card-body p-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="fab fa-cc-<?= strtolower($card['card_type']) ?> me-2"></i>
                                                    <strong><?= $card['masked'] ?></strong>
                                                    <?php if (!empty($card['is_default'])): ?>
                                                        <span class="badge bg-primary">Default</span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small><?= $card['holder'] ?> | Exp: <?= $card['expiry_month'] ?>/<?= $card['expiry_year'] ?></small>
                                                </div>
                                                <div>
                                                    <?php if (empty($card['is_default'])): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="set_default">
                                                            <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Set as default">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this card?')">
                                                        <input type="hidden" name="action" value="delete_card">
                                                        <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Main Content - Payment Stages -->
            <div class="col-md-9">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Order Payment Stages</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($paymentStages)): ?>
                            <p class="text-muted text-center">No orders found</p>
                        <?php else: ?>
                            <?php foreach ($paymentStages as $orderId => $data): 
                                $order = $data['order'];
                                $stages = $data['stages'];
                                $currentStatus = $data['current_status'];
                            ?>
                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>Order #<?= $orderId ?></strong> - <?= htmlspecialchars($order['designName']) ?>
                                                <span class="badge bg-info ms-2"><?= $order['ostatus'] ?></span>
                                            </div>
                                            <div>
                                                <small class="text-muted"><?= date('Y-m-d', strtotime($order['odate'])) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-bordered">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Payment Stage</th>
                                                    <th>Amount</th>
                                                    <th>Due When</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($stages as $stageKey => $stage): ?>
                                                    <tr>
                                                        <td><?= $stage['name'] ?></td>
                                                        <td>$<?= number_format($stage['amount'], 2) ?></td>
                                                        <td><span class="badge bg-secondary"><?= $stage['due_status'] ?></span></td>
                                                        <td>
                                                            <?php if ($stage['paid']): ?>
                                                                <span class="badge bg-success">Paid</span>
                                                            <?php else: ?>
                                                                <?php if ($currentStatus === $stage['due_status']): ?>
                                                                    <span class="badge bg-warning text-dark">Due Now</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">Pending</span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!$stage['paid']): ?>
                                                                <?php if ($currentStatus === $stage['due_status']): ?>
                                                                    <!-- Normal payment - stage is due -->
                                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Pay $<?= number_format($stage['amount'], 2) ?> from your wallet?')">
                                                                        <input type="hidden" name="action" value="process_payment">
                                                                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                                                        <input type="hidden" name="stage" value="<?= $stageKey ?>">
                                                                        <input type="hidden" name="amount" value="<?= $stage['amount'] ?>">
                                                                        <input type="hidden" name="current_status" value="<?= $currentStatus ?>">
                                                                        <input type="hidden" name="force" value="0">
                                                                        <button type="submit" class="btn btn-sm btn-primary" 
                                                                                <?= (floatval($clientData['budget'] ?? 0) < $stage['amount']) ? 'disabled' : '' ?>>
                                                                            Pay Now
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <!-- Early payment - stage is not due yet -->
                                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                                            onclick="confirmEarlyPayment(<?= $orderId ?>, '<?= $stageKey ?>', <?= $stage['amount'] ?>, '<?= $currentStatus ?>', '<?= $stage['due_status'] ?>')"
                                                                            <?= (floatval($clientData['budget'] ?? 0) < $stage['amount']) ? 'disabled' : '' ?>>
                                                                        Pay Early
                                                                    </button>
                                                                <?php endif; ?>
                                                                
                                                                <?php if (floatval($clientData['budget'] ?? 0) < $stage['amount']): ?>
                                                                    <small class="text-danger d-block">Insufficient balance</small>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <th colspan="2">Total Order Value</th>
                                                    <th colspan="3">$<?= number_format(
                                                        $stages['design_deposit']['amount'] + 
                                                        $stages['design_completion']['amount'] + 
                                                        $stages['construction_deposit']['amount'] + 
                                                        $stages['final_payment']['amount'], 2) ?>
                                                    </th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        
                                        <!-- Quick Pay All Button -->
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-success" onclick="payAllStages(<?= $orderId ?>)">
                                                <i class="fas fa-bolt me-2"></i>Pay All Remaining Stages
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hidden form for early payment -->
    <form id="earlyPaymentForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="process_payment">
        <input type="hidden" name="order_id" id="early_order_id">
        <input type="hidden" name="stage" id="early_stage">
        <input type="hidden" name="amount" id="early_amount">
        <input type="hidden" name="current_status" id="early_current_status">
        <input type="hidden" name="force" value="1">
    </form>
    
    <!-- Add Card Modal -->
    <div class="modal fade" id="addCardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_card">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Bank Card</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Card Type</label>
                            <select name="card_type" class="form-select" required>
                                <option value="visa">Visa</option>
                                <option value="mastercard">MasterCard</option>
                                <option value="amex">American Express</option>
                                <option value="unionpay">UnionPay</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Card Number</label>
                            <input type="text" name="card_number" class="form-control" 
                                   placeholder="1234 5678 9012 3456" maxlength="19" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Card Holder Name</label>
                            <input type="text" name="card_holder" class="form-control" 
                                   placeholder="Name on card" required>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Expiry Month</label>
                                <select name="expiry_month" class="form-select" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>">
                                            <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Expiry Year</label>
                                <select name="expiry_year" class="form-select" required>
                                    <?php for ($y = date('Y'); $y <= date('Y') + 10; $y++): ?>
                                        <option value="<?= $y ?>"><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">CVV</label>
                                <input type="text" name="cvv" class="form-control" 
                                       placeholder="123" maxlength="4" required>
                            </div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_default" class="form-check-input" id="isDefault">
                            <label class="form-check-label" for="isDefault">Set as default payment method</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Card</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function confirmEarlyPayment(orderId, stage, amount, currentStatus, dueStatus) {
        let message = `This payment stage ($${amount.toFixed(2)}) is not due yet.\n`;
        message += `Current order status: "${currentStatus}"\n`;
        message += `This stage is normally due when status is: "${dueStatus}"\n\n`;
        message += `Are you sure you want to pay early?`;
        
        if (confirm(message)) {
            document.getElementById('early_order_id').value = orderId;
            document.getElementById('early_stage').value = stage;
            document.getElementById('early_amount').value = amount;
            document.getElementById('early_current_status').value = currentStatus;
            document.getElementById('earlyPaymentForm').submit();
        }
    }
    
    function payAllStages(orderId) {
        let stages = [];
        let totalAmount = 0;
        
        <?php foreach ($paymentStages as $oid => $data): ?>
            if (<?= $oid ?> === orderId) {
                <?php foreach ($data['stages'] as $sk => $st): ?>
                    <?php if (!$st['paid']): ?>
                        stages.push({
                            stage: '<?= $sk ?>',
                            amount: <?= $st['amount'] ?>,
                            dueStatus: '<?= $st['due_status'] ?>'
                        });
                        totalAmount += <?= $st['amount'] ?>;
                    <?php endif; ?>
                <?php endforeach; ?>
            }
        <?php endforeach; ?>
        
        if (stages.length === 0) {
            alert('All stages for this order are already paid.');
            return;
        }
        
        let stageList = stages.map(s => `- ${s.stage}: $${s.amount.toFixed(2)} (normally due when: ${s.dueStatus})`).join('\n');
        
        let message = `You are about to pay all remaining stages for this order.\n`;
        message += `Total amount: $${totalAmount.toFixed(2)}\n\n`;
        message += `Stages to pay:\n${stageList}\n\n`;
        message += `Note: Some stages may not be due yet based on current order status.\n`;
        message += `Are you sure you want to proceed?`;
        
        if (confirm(message)) {
            // Process stages one by one with a slight delay
            let processed = 0;
            
            function processNextStage() {
                if (processed < stages.length) {
                    let stage = stages[processed];
                    
                    let stageMessage = `Paying stage: ${stage.stage}\n`;
                    stageMessage += `Amount: $${stage.amount.toFixed(2)}\n`;
                    stageMessage += `This stage is normally due when status is: "${stage.dueStatus}"\n\n`;
                    stageMessage += `Continue with this payment?`;
                    
                    if (confirm(stageMessage)) {
                        document.getElementById('early_order_id').value = orderId;
                        document.getElementById('early_stage').value = stage.stage;
                        document.getElementById('early_amount').value = stage.amount;
                        document.getElementById('early_current_status').value = '<?= $currentStatus ?>';
                        document.getElementById('earlyPaymentForm').submit();
                    }
                    processed++;
                }
            }
            
            // Start processing
            processNextStage();
        }
    }
    </script>
</body>
</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>