<?php
require_once __DIR__ . '/config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php?redirect=order_full_details.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'];
$userId = $user['id'];
$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

if ($orderId <= 0) {
    die("Invalid Order ID");
}

// Handle Date Updates (Manager Only)
$updateMessage = "";
if ($role === 'manager' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dates'])) {
    $newOrderDate = $_POST['order_finish_date'];
    $newDesignDate = $_POST['design_finish_date'];

    // Check if schedule exists
    $checkSchedule = $mysqli->prepare("SELECT scheduleid FROM Schedule WHERE orderid = ?");
    $checkSchedule->bind_param("i", $orderId);
    $checkSchedule->execute();
    $scheduleResult = $checkSchedule->get_result();

    if ($scheduleResult->num_rows > 0) {
        // Update existing schedule
        $updateStmt = $mysqli->prepare("UPDATE Schedule SET OrderFinishDate = ?, DesignFinishDate = ? WHERE orderid = ?");
        $updateStmt->bind_param("ssi", $newOrderDate, $newDesignDate, $orderId);
    } else {
        // Insert new schedule
        $managerId = $userId; // Use current manager's ID
        $updateStmt = $mysqli->prepare("INSERT INTO Schedule (OrderFinishDate, DesignFinishDate, orderid, managerid) VALUES (?, ?, ?, ?)");
        $updateStmt->bind_param("ssii", $newOrderDate, $newDesignDate, $orderId, $managerId);
    }

    if ($updateStmt->execute()) {
        $updateMessage = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            <i class='fas fa-check-circle me-2'></i>Dates updated successfully!
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
    } else {
        $updateMessage = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                            <i class='fas fa-exclamation-circle me-2'></i>Error updating dates: " . $mysqli->error . "
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
    }
}

// 1. Fetch Basic Order & Client & Design Info
$orderQuery = "SELECT o.*, c.cname, c.ctel, c.cemail, c.address, d.designName, d.description as designDesc, s.OrderFinishDate, s.DesignFinishDate 
               FROM `Order` o
               LEFT JOIN Client c ON o.clientid = c.clientid
               LEFT JOIN Design d ON o.designid = d.designid
               LEFT JOIN Schedule s ON o.orderid = s.orderid
               WHERE o.orderid = ?";
$stmt = $mysqli->prepare($orderQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// 2. Fetch Products/Materials (OrderReference)
$refQuery = "SELECT orf.*, p.pname, p.category, p.price as unit_price
             FROM OrderReference orf
             LEFT JOIN Product p ON orf.productid = p.productid
             WHERE orf.orderid = ?";
$stmt = $mysqli->prepare($refQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$references = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Fetch Additional Fees
$feeQuery = "SELECT * FROM AdditionalFee WHERE orderid = ?";
$stmt = $mysqli->prepare($feeQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Fetch Contractors
$contractorQuery = "SELECT oc.*, con.cname as contractor_name, con.ctel as contractor_tel
                    FROM Order_Contractors oc
                    JOIN Contractors con ON oc.contractorid = con.contractorid
                    WHERE oc.orderid = ?";
$stmt = $mysqli->prepare($contractorQuery);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$contractors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate Totals
$productTotal = 0;
foreach ($references as $ref) {
    $productTotal += $ref['price'] ?? 0;
}
$feeTotal = 0;
foreach ($fees as $fee) {
    $feeTotal += $fee['amount'];
}
$grandTotal = $productTotal + $feeTotal + ($order['cost'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details #<?= $orderId ?> - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body { background-color: #f4f7f6; }
        .detail-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05 );
        }
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-label { color: #7f8c8d; font-size: 13px; margin-bottom: 2px; }
        .info-value { color: #2c3e50; font-weight: 600; margin-bottom: 15px; }
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .table thead th { background-color: #f8f9fa; border-top: none; font-size: 13px; color: #7f8c8d; }
        .total-row { font-size: 16px; font-weight: 700; background: #f8f9fa; }
        .back-btn { 
            margin-bottom: 20px; 
            display: inline-flex; 
            align-items: center;
            color: #7f8c8d; 
            text-decoration: none; 
            font-weight: 600; 
            transition: all 0.3s ease;
            padding: 8px 16px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .back-btn:hover { 
            color: #3498db; 
            transform: translateX(-5px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        /* Embed-specific overrides */
        <?php if ($isEmbed): ?>
        body { background-color: #fff !important; }
        main.container { margin-top: 0 !important; padding: 15px !important; max-width: 100% !important; }
        .detail-card { padding: 15px !important; margin-bottom: 15px !important; box-shadow: 0 1px 5px rgba(0,0,0,0.05) !important; }
        .section-title { font-size: 16px !important; margin-bottom: 15px !important; }
        .info-value { font-size: 14px !important; margin-bottom: 10px !important; }
        .status-badge { padding: 4px 10px !important; font-size: 11px !important; }
        .manager-action-box { padding: 15px !important; margin-bottom: 15px !important; }
        .btn-update-schedule { padding: 0.5rem 1rem !important; font-size: 14px !important; }
        .row > * { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
        <?php endif; ?>
        
        /* Updated Manager Action Box Style */
        .manager-action-box {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.1);
            border: 1px solid rgba(52, 152, 219, 0.2);
        }
        .manager-action-box .section-title {
            border-bottom: 2px solid #eef2f7;
            color: #3498db;
        }
        .manager-action-box .form-label {
            color: #34495e;
            font-weight: 600;
        }
        .manager-action-box .form-control {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            padding: 0.6rem 0.75rem;
            transition: all 0.3s;
        }
        .manager-action-box .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.1);
        }
        .btn-update-schedule {
            background: #3498db;
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(52, 152, 219, 0.2);
        }
        .btn-update-schedule:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.3);
            color: white;
        }
    </style>
</head>
<body>
    <?php if (!$isEmbed): ?>
        <?php include_once __DIR__ . '/includes/header.php'; ?>
    <?php endif; ?>

    <main class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="project_management.php<?= $isEmbed ? '?embed=1' : '' ?>" class="back-btn mb-0">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
            <?php if (!$isEmbed): ?>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="offcanvas"
                  data-bs-target="#projectAppPanel" aria-controls="projectAppPanel">
                  <i class="fas fa-tasks me-1"></i> Project View
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="container-fluid px-0">
            <?= $updateMessage ?>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-3 shadow-sm">
            <div>
                <h2 class="fw-bold mb-1">Order #<?= $orderId ?></h2>
                <p class="text-muted mb-0"><i class="far fa-calendar-alt me-1"></i> Placed on <?= date('F d, Y', strtotime($order['odate'])) ?></p>
            </div>
            <span class="status-badge bg-primary text-white px-4 py-2 fs-6"><?= htmlspecialchars($order['ostatus']) ?></span>
        </div>

        <!-- Manager Exclusive Area - Styled to match system -->
        <?php if ($role === 'manager'): ?>
        <div class="manager-action-box">
            <div class="section-title">
                <i class="fas fa-calendar-alt"></i>Schedule Management
            </div>
            <form method="POST" class="row g-4 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Design Finish Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-pencil-ruler text-muted"></i></span>
                        <input type="date" name="design_finish_date" class="form-control border-start-0" value="<?= $order['DesignFinishDate'] ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Order Finish Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-check-double text-muted"></i></span>
                        <input type="date" name="order_finish_date" class="form-control border-start-0" value="<?= $order['OrderFinishDate'] ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" name="update_dates" class="btn btn-update-schedule w-100">
                        <i class="fas fa-sync-alt me-2"></i>Update Schedule
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column: Order & Client Info -->
            <div class="col-lg-8">
                <!-- Project Overview -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Project Overview
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-label">Design Name</div>
                            <div class="info-value"><?= htmlspecialchars($order['designName'] ?? 'Custom Request') ?></div>
                            
                            <div class="info-label">Order Date</div>
                            <div class="info-value"><?= date('F d, Y', strtotime($order['odate'])) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Requirements</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($order['Requirements'] ?? 'No specific requirements')) ?></div>
                            
                            <div class="info-label">Estimated Completion</div>
                            <div class="info-value text-primary">
                                <?= $order['OrderFinishDate'] ? date('F d, Y', strtotime($order['OrderFinishDate'])) : 'TBD' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products & Materials -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-couch"></i> Products & Materials
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th class="text-end">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($references)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No items added yet</td></tr>
                                <?php else: ?>
                                    <?php foreach ($references as $ref): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($ref['pname'] ?? 'Unknown Item') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($ref['note'] ?? '') ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($ref['category'] ?? 'N/A') ?></span></td>
                                        <td><?= htmlspecialchars($ref['status'] ?? 'Pending') ?></td>
                                        <td class="text-end">$<?= number_format($ref['price'] ?? 0, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Additional Fees -->
                <?php if (!empty($fees)): ?>
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-plus-circle"></i> Additional Fees
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Fee Name</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($fee['fee_name']) ?></td>
                                    <td><?= htmlspecialchars($fee['description']) ?></td>
                                    <td class="text-end">$<?= number_format($fee['amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Client & Financials -->
            <div class="col-lg-4">
                <!-- Client Info -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-user"></i> Client Information
                    </div>
                    <div class="info-label">Name</div>
                    <div class="info-value"><?= htmlspecialchars($order['cname']) ?></div>
                    
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?= htmlspecialchars($order['ctel']) ?></div>
                    
                    <div class="info-label">Email</div>
                    <div class="info-value"><?= htmlspecialchars($order['cemail']) ?></div>
                    
                    <div class="info-label">Address</div>
                    <div class="info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></div>
                </div>

                <!-- Financial Summary -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-file-invoice-dollar"></i> Financial Summary
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Deposit Paid:</span>
                        <span class="fw-bold">$<?= number_format($order['deposit'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Items Total:</span>
                        <span class="fw-bold">$<?= number_format($productTotal, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Additional Fees:</span>
                        <span class="fw-bold">$<?= number_format($feeTotal, 2) ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span class="h5 fw-bold">Grand Total:</span>
                        <span class="h5 fw-bold text-success">$<?= number_format($grandTotal, 2) ?></span>
                    </div>
                </div>

                <!-- Assigned Contractors -->
                <div class="detail-card">
                    <div class="section-title">
                        <i class="fas fa-tools"></i> Assigned Contractors
                    </div>
                    <?php if (empty($contractors)): ?>
                        <p class="text-muted small">No contractors assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($contractors as $con): ?>
                        <div class="mb-3">
                            <div class="fw-bold"><?= htmlspecialchars($con['contractor_name']) ?></div>
                            <div class="small text-muted"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($con['contractor_tel']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/Public/chat_widget.php'; ?>
</body>
</html>
