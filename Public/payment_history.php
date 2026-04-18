<?php
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/Public/payment_history.php'));
    exit;
}

$role = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));

if (!function_exists('mysqli_stmt_bind_dynamic')) {
    function mysqli_stmt_bind_dynamic($stmt, $types, array &$params)
    {
        if ($types === '') {
            return true;
        }

        $bindArgs = [$stmt, $types];
        foreach ($params as $k => &$v) {
            $bindArgs[] = &$v;
        }

        return call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
    }
}

$scope_sql = '';
$scope_types = '';
$scope_params = [];

switch ($role) {
    case 'client':
        $client_id = (int) ($_SESSION['user']['clientid'] ?? 0);
        if ($client_id <= 0) {
            die('Client account not found.');
        }
        $scope_sql = 'o.clientid = ?';
        $scope_types = 'i';
        $scope_params = [$client_id];
        break;

    case 'designer':
        $designer_id = (int) ($_SESSION['user']['designerid'] ?? 0);
        if ($designer_id <= 0) {
            die('Designer account not found.');
        }
        $scope_sql = 'EXISTS (SELECT 1 FROM `Design` d WHERE d.designid = o.designid AND d.designerid = ?)';
        $scope_types = 'i';
        $scope_params = [$designer_id];
        break;

    case 'supplier':
        $supplier_id = (int) ($_SESSION['user']['supplierid'] ?? 0);
        if ($supplier_id <= 0) {
            die('Supplier account not found.');
        }
        $scope_sql = '(o.supplierid = ? OR EXISTS (SELECT 1 FROM `OrderDelivery` od JOIN `Product` p ON p.productid = od.productid WHERE od.orderid = o.orderid AND p.supplierid = ?))';
        $scope_types = 'ii';
        $scope_params = [$supplier_id, $supplier_id];
        break;

    case 'manager':
        $manager_id = (int) ($_SESSION['user']['managerid'] ?? 0);
        if ($manager_id <= 0) {
            die('Manager account not found.');
        }
        $scope_sql = '(EXISTS (SELECT 1 FROM `OrderDelivery` od WHERE od.orderid = o.orderid AND od.managerid = ?) OR EXISTS (SELECT 1 FROM `workerallocation` wa WHERE wa.orderid = o.orderid AND wa.managerid = ?))';
        $scope_types = 'ii';
        $scope_params = [$manager_id, $manager_id];
        break;

    default:
        die('Access denied for this account role.');
}

$order_id = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;
$has_order_param = isset($_GET['orderid']) && trim((string) $_GET['orderid']) !== '';
$has_order_filter = $has_order_param && $order_id > 0;
$error = '';

$order = null;
$records = [];
$summary = [
    'paid_total' => 0.0,
    'pending_total' => 0.0,
    'paid_before_construction' => 0.0,
    'paid_during_construction' => 0.0,
    'record_count' => 0,
    'order_count' => 0,
];

function classify_payment_stage($milestone, $percentage)
{
    $m = strtolower(trim((string) $milestone));
    $pct = (int) $percentage;

    if ($m !== '' && strpos($m, 'design') !== false) {
        return 'Before Construction (Design)';
    }

    if ($m !== '' && strpos($m, 'construction deposit') !== false) {
        return 'Before Construction (Deposit)';
    }

    if (in_array($pct, [25, 50, 75, 100], true)) {
        return 'During Construction (Milestone)';
    }

    return 'Other';
}

if ($has_order_param && $order_id <= 0) {
    $error = 'Please enter a valid Order ID.';
}

if ($has_order_filter) {
    if ($error === '') {
        $order_sql = "SELECT o.orderid, o.odate, o.ostatus, c.cname, op.total_cost
                      FROM `Order` o
                      LEFT JOIN `Client` c ON c.clientid = o.clientid
                      LEFT JOIN `OrderPayment` op ON op.payment_id = o.payment_id
                      WHERE o.orderid = ?
                        AND {$scope_sql}
                      LIMIT 1";
        $order_stmt = mysqli_prepare($mysqli, $order_sql);

        if ($order_stmt) {
            $order_bind_types = 'i' . $scope_types;
            $order_bind_params = array_merge([$order_id], $scope_params);
            mysqli_stmt_bind_dynamic($order_stmt, $order_bind_types, $order_bind_params);
            mysqli_stmt_execute($order_stmt);
            $order_result = mysqli_stmt_get_result($order_stmt);
            $order = mysqli_fetch_assoc($order_result);
            mysqli_stmt_close($order_stmt);
        }

        if (!$order) {
            $error = 'No accessible project found for this Order ID under your account.';
        }
    }
}

if ($error === '') {
    $records_sql = "SELECT r.record_id, r.orderid, r.installment_number, r.percentage, r.amount, r.milestone, r.status, r.paid_at
                    FROM ConstructionPaymentRecord r
                    INNER JOIN `Order` o ON o.orderid = r.orderid
                    WHERE {$scope_sql}
                                            AND r.status = 'paid'";

    if ($has_order_filter) {
        $records_sql .= " AND r.orderid = ?";
    }

    $records_sql .= " ORDER BY r.paid_at DESC, r.record_id DESC";

    $records_stmt = mysqli_prepare($mysqli, $records_sql);
    if ($records_stmt) {
        $records_bind_types = $scope_types;
        $records_bind_params = $scope_params;

        if ($has_order_filter) {
            $records_bind_types .= 'i';
            $records_bind_params[] = $order_id;
        }

        mysqli_stmt_bind_dynamic($records_stmt, $records_bind_types, $records_bind_params);

        mysqli_stmt_execute($records_stmt);
        $records_result = mysqli_stmt_get_result($records_stmt);

        while ($row = mysqli_fetch_assoc($records_result)) {
            $row['stage_type'] = classify_payment_stage($row['milestone'] ?? '', $row['percentage'] ?? 0);
            $row['payment_channel'] = 'Bank';
            $records[] = $row;
        }

        mysqli_stmt_close($records_stmt);
    }

    $summary['record_count'] = count($records);
    $covered_orders = [];

    foreach ($records as $rec) {
        $amount = isset($rec['amount']) ? (float) $rec['amount'] : 0.0;
        $status = strtolower((string) ($rec['status'] ?? ''));
        $stage = (string) ($rec['stage_type'] ?? '');
        $covered_orders[(int) ($rec['orderid'] ?? 0)] = true;

        if ($status === 'paid') {
            $summary['paid_total'] += $amount;

            if (strpos($stage, 'Before Construction') === 0) {
                $summary['paid_before_construction'] += $amount;
            } elseif (strpos($stage, 'During Construction') === 0) {
                $summary['paid_during_construction'] += $amount;
            }
        } elseif ($status === 'pending') {
            $summary['pending_total'] += $amount;
        }
    }

    $summary['order_count'] = count($covered_orders);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container py-4">
        <div class="card history-card shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="d-flex g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="orderid" class="form-label">Order ID</label>
                        <input
                            type="number"
                            min="1"
                            step="1"
                            class="form-control"
                            id="orderid"
                            name="orderid"
                            value="<?php echo $order_id > 0 ? (int) $order_id : ''; ?>"
                            placeholder="Example: 6">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                        <a href="payment_history.php" class="btn btn-outline-secondary ms-2">Show All</a>
                    </div>
                    <div class="mt-3 text-muted small">
                        Orders Covered: <strong><?php echo (int) $summary['order_count']; ?></strong>
                        &nbsp;|&nbsp;
                        Records: <strong><?php echo (int) $summary['record_count']; ?></strong>
                    </div>
                </form>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-warning mt-3 mb-0"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error === ''): ?>
            <?php if ($order): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-body">

                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="summary-label">Project</div>
                                    <div class="summary-value">#<?php echo (int) $order['orderid']; ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="summary-label">Client</div>
                                    <div class="summary-value"><?php echo htmlspecialchars((string) ($order['cname'] ?? 'N/A')); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="summary-label">Order Status</div>
                                    <div class="summary-value"><?php echo htmlspecialchars((string) ($order['ostatus'] ?? 'N/A')); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="summary-label">Total Budget</div>
                                    <div class="summary-value">HK$<?php echo number_format((float) ($order['total_cost'] ?? 0), 2); ?></div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3">
                        <?php if ($order): ?>
                            Records for Project #<?php echo (int) $order['orderid']; ?>
                        <?php else: ?>
                            Records
                        <?php endif; ?>
                        (<?php echo (int) $summary['record_count']; ?>)
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Record ID</th>
                                    <th>Order ID</th>
                                    <th>Milestone</th>
                                    <th>Stage Type</th>
                                    <th>Amount</th>
                                    <th>Channel</th>
                                    <th>Paid At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($records)): ?>
                                    <?php foreach ($records as $rec): ?>
                                        <tr>
                                            <td><?php echo (int) ($rec['record_id'] ?? 0); ?></td>
                                            <td>#<?php echo (int) ($rec['orderid'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($rec['milestone'] ?? 'N/A')); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($rec['stage_type'] ?? 'Other')); ?></td>
                                            <td>HK$<?php echo number_format((float) ($rec['amount'] ?? 0), 2); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($rec['payment_channel'] ?? 'Bank')); ?></td>
                                            <td><?php echo !empty($rec['paid_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($rec['paid_at']))) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No paid records found for your account.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>