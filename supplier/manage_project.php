<?php
session_start();
require_once __DIR__ . '/../config.php';

// Supplier login check
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php');
    exit;
}

$supplierId = intval($_SESSION['user']['supplierid']);
$orderid = isset($_GET['orderid']) ? intval($_GET['orderid']) : 0;
$searchOrder = isset($_GET['search_order']) ? trim($_GET['search_order']) : '';

// Handle status update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['orderdeliveryid']) && isset($_POST['new_status']) && $orderid > 0) {
    $orderdeliveryid = intval($_POST['orderdeliveryid']);
    $new_status = trim($_POST['new_status']);
    $allowed_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
    if (in_array($new_status, $allowed_statuses)) {
        $update_sql = "UPDATE OrderDelivery SET status = ? WHERE orderdeliveryid = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param('si', $new_status, $orderdeliveryid);
        $update_stmt->execute();
        $update_stmt->close();
        $msg = 'Status updated successfully!';
    } else {
        $msg = 'Invalid status.';
    }
}

// Fetch all orders for dropdown and search
$orders = [];
$order_sql = "SELECT o.orderid, o.odate, c.cname FROM `Order` o JOIN Client c ON o.clientid = c.clientid JOIN OrderDelivery od ON o.orderid = od.orderid JOIN Product p ON od.productid = p.productid WHERE p.supplierid = ?";
if ($searchOrder !== '') {
    $order_sql .= " AND o.orderid = ?";
}
$order_sql .= " GROUP BY o.orderid ORDER BY o.odate DESC";
$order_stmt = $mysqli->prepare($order_sql);
if ($searchOrder !== '') {
    $order_stmt->bind_param('ii', $supplierId, $searchOrder);
} else {
    $order_stmt->bind_param('i', $supplierId);
}
$order_stmt->execute();
$order_res = $order_stmt->get_result();
while ($row = $order_res->fetch_assoc()) {
    $orders[] = $row;
}
$order_stmt->close();

$clientInfo = null;
$items = [];
$orderStatus = '';
if ($orderid > 0) {
    // Fetch client info and order status
    $client_sql = "SELECT c.cname, c.cemail, c.address, o.odate, o.ostatus FROM `Order` o JOIN Client c ON o.clientid = c.clientid WHERE o.orderid = ?";
    $client_stmt = $mysqli->prepare($client_sql);
    $client_stmt->bind_param('i', $orderid);
    $client_stmt->execute();
    $client_res = $client_stmt->get_result();
    $clientInfo = $client_res->fetch_assoc();
    $client_stmt->close();
    if ($clientInfo) {
        $orderStatus = $clientInfo['ostatus'];
    }

    // Fetch products for this order
    $sql = "SELECT od.orderdeliveryid, od.productid, od.quantity, od.status, od.deliverydate, p.pname FROM OrderDelivery od JOIN Product p ON od.productid = p.productid WHERE od.orderid = ? AND p.supplierid = ? ORDER BY od.orderdeliveryid DESC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $orderid, $supplierId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}
$allowed_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Project Delivery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Manage Project Delivery</h2>
    </div>
    <form method="get" class="mb-4 d-flex gap-2 align-items-center">
        <label for="orderid" class="form-label fw-bold mb-0">Select Project:</label>
        <select name="orderid" id="orderid" class="form-select" style="max-width:300px;display:inline-block;" onchange="this.form.submit()">
            <option value="">-- Select Project --</option>
            <?php foreach ($orders as $o): ?>
                <option value="<?= $o['orderid'] ?>" <?= ($orderid == $o['orderid']) ? 'selected' : '' ?>>Order #<?= $o['orderid'] ?> - <?= htmlspecialchars($o['cname']) ?> (<?= $o['odate'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="search_order" class="form-control" placeholder="Search Order Number" value="<?= htmlspecialchars($searchOrder) ?>" style="max-width:180px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    </form>
    <?php if ($orderid > 0 && $clientInfo): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title text-primary"><i class="fas fa-user me-2"></i>Client Information</h5>
                <div class="row mb-2">
                    <div class="col-md-6"><strong>Name:</strong> <?= htmlspecialchars($clientInfo['cname']) ?></div>
                    <div class="col-md-6"><strong>Email:</strong> <?= htmlspecialchars($clientInfo['cemail']) ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-6"><strong>Address:</strong> <?= htmlspecialchars($clientInfo['address']) ?></div>
                    <div class="col-md-6"><strong>Order Date:</strong> <?= htmlspecialchars($clientInfo['odate']) ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-6"><strong>Order Status:</strong> <span class="badge bg-warning text-dark"><?= htmlspecialchars($orderStatus) ?></span></div>
                </div>
            </div>
        </div>
        <?php if (!empty($msg)): ?>
            <div class="alert alert-info mb-3"><i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <?php if (count($items) > 0): ?>
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0 text-dark"><i class="fas fa-boxes me-2"></i>Product List</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-bordered table-black mb-0">
                        <thead class="muted">
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Delivery Date</th>
                                <th>Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['pname']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($item['status']) ?></span></td>
                                <td><?= htmlspecialchars($item['deliverydate']) ?></td>
                                <td>
                                    <?php if ($orderStatus === 'preparing'): ?>
                                        <form method="post" style="display:inline-block">
                                            <input type="hidden" name="orderdeliveryid" value="<?= (int)$item['orderdeliveryid'] ?>">
                                            <select name="new_status" class="form-select form-select-sm" style="width:120px;display:inline-block;">
                                                <option value="">-- Change Status --</option>
                                                <?php foreach ($allowed_statuses as $s): ?>
                                                    <option value="<?= $s ?>" <?= ($item['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary ms-1"><i class="fas fa-sync-alt"></i> Update</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary fw-bold">Reserve</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mt-3"><i class="fas fa-exclamation-circle me-2"></i>No delivery items found for this project.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>