<?php
// ==============================
// File: includes/profile.php
// 修复版：统一列名别名，解决 Undefined array key 警告
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$role = strtolower($user['role']);
$success = ''; $error = '';

$id_key = $role . 'id';
$id = (int)$user[$id_key];

// 1. 获取基础数据 - 使用 AS 统一键名，解决报错问题
if ($role === 'client') {
    $res = $mysqli->query("SELECT cname AS name, ctel AS tel, cemail AS email, address, budget, Floor_Plan FROM Client WHERE clientid = $id");
} elseif ($role === 'manager') {
    $res = $mysqli->query("SELECT mname AS name, mtel AS tel, memail AS email FROM Manager WHERE managerid = $id");
} elseif ($role === 'designer') {
    $res = $mysqli->query("SELECT dname AS name, dtel AS tel, demail AS email, status FROM Designer WHERE designerid = $id");
} elseif ($role === 'supplier') {
    $res = $mysqli->query("SELECT sname AS name, stel AS tel, semail AS email FROM Supplier WHERE supplierid = $id");
}

if ($res) {
    $userData = $res->fetch_assoc();
} else {
    die("Database error.");
}

// 获取介绍 (针对 Supplier)
$introduction = "Professional " . ucfirst($role) . " at HappyDesign Platform.";
if ($role === 'supplier') {
    $cStmt = $mysqli->prepare("SELECT introduction FROM Contractors WHERE contractorid = ?");
    $cStmt->bind_param("i", $id);
    $cStmt->execute();
    $cResult = $cStmt->get_result();
    if ($cRow = $cResult->fetch_assoc()) {
        $introduction = $cRow['introduction'];
    }
}

// 2. 处理所有 POST 请求 (保持原有功能)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // [Supplier] 工人管理
    if (isset($_POST['worker_action'])) {
        $wAction = $_POST['worker_action'];
        $wName = $_POST['w_name'];
        $wEmail = $_POST['w_email'];
        $wPhone = $_POST['w_phone'];
        $wCert = $_POST['w_cert'];

        if ($wAction === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO Worker (name, email, phone, certificate, supplierid, image) VALUES (?, ?, ?, ?, ?, 'default.jpg')");
            $stmt->bind_param("ssssi", $wName, $wEmail, $wPhone, $wCert, $id);
            if ($stmt->execute()) {
                $newWId = $mysqli->insert_id;
                handleFileUpload('w_image', '../uploads/worker/', "worker" . $newWId . ".jpg", "UPDATE Worker SET image = ? WHERE workerid = $newWId");
                $success = "Worker added!";
            }
        } elseif ($wAction === 'edit') {
            $wId = (int)$_POST['workerid'];
            $stmt = $mysqli->prepare("UPDATE Worker SET name=?, email=?, phone=?, certificate=? WHERE workerid=? AND supplierid=?");
            $stmt->bind_param("ssssii", $wName, $wEmail, $wPhone, $wCert, $wId, $id);
            if ($stmt->execute()) {
                handleFileUpload('w_image', '../uploads/worker/', "worker" . $wId . ".jpg", "UPDATE Worker SET image = ? WHERE workerid = $wId");
                $success = "Worker updated!";
            }
        }
    }
    
    // [Client] 平面图上传
    elseif (isset($_POST['upload_floor_plan'])) {
        if (isset($_FILES['fp_file']) && $_FILES['fp_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['fp_file']['name'], PATHINFO_EXTENSION);
            $fpName = "floor_" . $id . "_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES['fp_file']['tmp_name'], "../uploads/floorplans/" . $fpName)) {
                $mysqli->query("UPDATE Client SET Floor_Plan = '$fpName' WHERE clientid = $id");
                $success = "Floor plan uploaded!";
                header("Refresh:1");
            }
        }
    }

    // [通用] 个人资料更新
    elseif (isset($_POST['update_profile'])) {
        $upName = $_POST['name'];
        $upTel = $_POST['tel'];
        $upEmail = $_POST['email'];
        if ($role === 'client') {
            $upAddr = $_POST['address']; $upBudget = (int)$_POST['budget'];
            $stmt = $mysqli->prepare("UPDATE Client SET cname=?, ctel=?, cemail=?, address=?, budget=? WHERE clientid=?");
            $stmt->bind_param("ssssii", $upName, $upTel, $upEmail, $upAddr, $upBudget, $id);
        } elseif ($role === 'manager') {
            $stmt = $mysqli->prepare("UPDATE Manager SET mname=?, mtel=?, memail=? WHERE managerid=?");
            $stmt->bind_param("sssi", $upName, $upTel, $upEmail, $id);
        } elseif ($role === 'designer') {
            $stmt = $mysqli->prepare("UPDATE Designer SET dname=?, dtel=?, demail=? WHERE designerid=?");
            $stmt->bind_param("sssi", $upName, $upTel, $upEmail, $id);
        } elseif ($role === 'supplier') {
            $stmt = $mysqli->prepare("UPDATE Supplier SET sname=?, stel=?, semail=? WHERE supplierid=?");
            $stmt->bind_param("sssi", $upName, $upTel, $upEmail, $id);
        }
        
        if (isset($stmt) && $stmt->execute()) {
            $_SESSION['user']['name'] = $upName;
            $success = "Profile updated!";
            header("Refresh:1");
        }
    }
}

// 上传函数
function handleFileUpload($inputName, $dir, $newName, $sql) {
    global $mysqli;
    if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $dir . $newName)) {
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("s", $newName);
            $stmt->execute();
        }
    }
}

$logoPath = ($role === 'supplier' && $id == 1) ? "../uploads/company/companylogo.jpg" : "../uploads/company/companylogo1.jpg";

$designerDesigns = [];
if ($role === 'designer') {
    $dStmt = $mysqli->prepare("SELECT d.designid, d.designName, d.expect_price, d.likes,
                                      (SELECT image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC, di.imageid ASC LIMIT 1) AS image_filename
                               FROM Design d
                               WHERE d.designerid = ?
                               ORDER BY d.designid DESC");
    if ($dStmt) {
        $dStmt->bind_param("i", $id);
        $dStmt->execute();
        $dResult = $dStmt->get_result();
        while ($dRow = $dResult->fetch_assoc()) {
            $designerDesigns[] = $dRow;
        }
    }
}

$managerStats = [
    'designers' => 0,
    'contractors' => 0,
    'active_orders' => 0
];
$managerOrders = [];
if ($role === 'manager') {
    $st1 = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM Designer WHERE managerid = ?");
    if ($st1) {
        $st1->bind_param("i", $id);
        $st1->execute();
        $r1 = $st1->get_result()->fetch_assoc();
        $managerStats['designers'] = (int)($r1['cnt'] ?? 0);
    }

    $st2 = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM Contractors WHERE managerid = ?");
    if ($st2) {
        $st2->bind_param("i", $id);
        $st2->execute();
        $r2 = $st2->get_result()->fetch_assoc();
        $managerStats['contractors'] = (int)($r2['cnt'] ?? 0);
    }

    $st3 = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM Schedule WHERE managerid = ?");
    if ($st3) {
        $st3->bind_param("i", $id);
        $st3->execute();
        $r3 = $st3->get_result()->fetch_assoc();
        $managerStats['active_orders'] = (int)($r3['cnt'] ?? 0);
    }

    $orderStmt = $mysqli->prepare("SELECT s.orderid, o.ostatus, c.cname AS client_name, s.DesignFinishDate, s.OrderFinishDate
                                   FROM Schedule s
                                   JOIN `Order` o ON o.orderid = s.orderid
                                   JOIN Client c ON c.clientid = o.clientid
                                   WHERE s.managerid = ?
                                   ORDER BY s.scheduleid DESC
                                   LIMIT 6");
    if ($orderStmt) {
        $orderStmt->bind_param("i", $id);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        while ($oRow = $orderResult->fetch_assoc()) {
            $managerOrders[] = $oRow;
        }
    }
}

$supplierStats = [
    'workers' => 0,
    'products' => 0,
    'pending_deliveries' => 0
];
$supplierDeliveries = [];
if ($role === 'supplier') {
    $sp1 = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM Worker WHERE supplierid = ?");
    if ($sp1) {
        $sp1->bind_param("i", $id);
        $sp1->execute();
        $sr1 = $sp1->get_result()->fetch_assoc();
        $supplierStats['workers'] = (int)($sr1['cnt'] ?? 0);
    }

    $sp2 = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM Product WHERE supplierid = ?");
    if ($sp2) {
        $sp2->bind_param("i", $id);
        $sp2->execute();
        $sr2 = $sp2->get_result()->fetch_assoc();
        $supplierStats['products'] = (int)($sr2['cnt'] ?? 0);
    }

    $sp3 = $mysqli->prepare("SELECT COUNT(*) AS cnt
                            FROM OrderDelivery od
                            JOIN Product p ON p.productid = od.productid
                            WHERE p.supplierid = ? AND od.status = 'Pending'");
    if ($sp3) {
        $sp3->bind_param("i", $id);
        $sp3->execute();
        $sr3 = $sp3->get_result()->fetch_assoc();
        $supplierStats['pending_deliveries'] = (int)($sr3['cnt'] ?? 0);
    }

    $sp4 = $mysqli->prepare("SELECT od.orderid, p.pname, od.quantity, od.deliverydate, od.status
                            FROM OrderDelivery od
                            JOIN Product p ON p.productid = od.productid
                            WHERE p.supplierid = ?
                            ORDER BY od.orderdeliveryid DESC
                            LIMIT 6");
    if ($sp4) {
        $sp4->bind_param("i", $id);
        $sp4->execute();
        $dr = $sp4->get_result();
        while ($dRow = $dr->fetch_assoc()) {
            $supplierDeliveries[] = $dRow;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background: #f6f6f7; color: #444; }
        .banner { height: 280px; background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover; }
        .profile-header-card { background: #fff; border-radius: 12px; padding: 25px; margin-top: -60px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); display: flex; align-items: center; position: relative; z-index: 10; }
        .logo-box { width: 90px; height: 90px; border: 1px solid #eee; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 25px; background: #fff; }
        .logo-box img { max-width: 100%; max-height: 100%; }
        
        .content-section { background: #fff; border-radius: 12px; padding: 30px; margin-top: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .blue-section-header { border-bottom: 2px solid #3498db; padding-bottom: 8px; margin-bottom: 20px; color: #2c3e50; font-weight: 700; display: flex; align-items: center; }
        .blue-section-header i { margin-right: 12px; color: #3498db; }

        .sidebar-box { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid #eee; }
        .sidebar-title { font-size: 0.75rem; font-weight: 700; color: #999; text-transform: uppercase; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px; }

        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f8f9fa; align-items: center; }
        .info-label { width: 120px; color: #7f8c8d; font-weight: 600; font-size: 0.9rem; }
        .grey-card-box { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-top: 10px; }

        .worker-card { border: 1px solid #eee; border-radius: 10px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; position: relative; background: #fff; }
        .worker-img { width: 65px; height: 65px; border-radius: 8px; object-fit: cover; margin-right: 15px; }
        .edit-w-btn { position: absolute; right: 15px; top: 15px; color: #3498db; cursor: pointer; }

        .design-item { border: 1px solid #eee; border-radius: 10px; padding: 12px; margin-bottom: 12px; display: flex; align-items: center; background: #fff; }
        .design-thumb { width: 72px; height: 72px; border-radius: 8px; object-fit: cover; margin-right: 14px; border: 1px solid #f0f0f0; }

        .manager-stat { border: 1px solid #eee; border-radius: 10px; padding: 14px; text-align: center; background: #fff; }
        .manager-stat .num { font-size: 1.4rem; font-weight: 700; color: #2c3e50; }
    </style>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>
<div class="banner"></div>

<div class="container mb-5">
    <div class="row">
        <div class="col-lg-8">
            <div class="profile-header-card mb-4">
                <div class="logo-box"><img src="<?= $logoPath ?>" onerror="this.src='https://via.placeholder.com/100?text=User'"></div>
                <div>
                    <h2 class="fw-bold mb-0"><?= htmlspecialchars($userData['name']) ?></h2>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($introduction) ?></p>
                </div>
                <div class="ms-auto"><button class="btn btn-primary btn-sm px-4" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-edit me-1"></i> Edit Profile</button></div>
            </div>

            <?php if($success): ?> <div class="alert alert-success py-2 small"><?= $success ?></div> <?php endif; ?>

            <!-- CLIENT UI -->
            <?php if ($role === 'client'): ?>
                <div class="content-section">
                    <div class="blue-section-header"><i class="fas fa-user-circle"></i> Personal Information</div>
                    <div class="grey-card-box">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user me-2"></i>Name:</div><div><?= htmlspecialchars($userData['name']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i>Email:</div><div><?= htmlspecialchars($userData['email']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i>Phone:</div><div><?= htmlspecialchars($userData['tel']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-map-marker-alt me-2"></i>Address:</div><div><?= htmlspecialchars($userData['address'] ?: 'Not set') ?></div></div>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-file-pdf"></i> Floor Plan</div>
                    <div class="grey-card-box">
                        <?php if($userData['Floor_Plan']): ?>
                            <div class="mb-3"><i class="fas fa-file-alt text-primary me-2"></i><strong>Current:</strong> <a href="../uploads/floorplans/<?= $userData['Floor_Plan'] ?>" target="_blank"><?= $userData['Floor_Plan'] ?></a></div>
                        <?php else: ?>
                            <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> No floor plan uploaded yet.</p>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#fpModal"><i class="fas fa-upload me-1"></i> Upload Floor Plan</button>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-money-bill-wave"></i> Budget</div>
                    <div class="grey-card-box">
                        <p>Default budget: <strong class="text-success">HK$<?= number_format($userData['budget']) ?></strong></p>
                        <button class="btn btn-primary btn-sm px-4" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-cog me-1"></i> Set Budget</button>
                    </div>
                </div>

            <!-- MANAGER UI -->
            <?php elseif ($role === 'manager'): ?>
                <div class="content-section">
                    <div class="blue-section-header"><i class="fas fa-user-tie"></i> Personal Information</div>
                    <div class="grey-card-box">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user me-2"></i>Name:</div><div><?= htmlspecialchars($userData['name']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i>Email:</div><div><?= htmlspecialchars($userData['email']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i>Phone:</div><div><?= htmlspecialchars($userData['tel']) ?></div></div>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-chart-pie"></i>Project Statistics</div>
                    <div class="grey-card-box">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$managerStats['designers'] ?></div><div class="small text-muted">Designers</div></div></div>
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$managerStats['contractors'] ?></div><div class="small text-muted">Contractors</div></div></div>
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$managerStats['active_orders'] ?></div><div class="small text-muted">Project Orders</div></div></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Client</th>
                                    <th>Status</th>
                                    <th>Design Finish</th>
                                    <th>Order Finish</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($managerOrders)): ?>
                                    <?php foreach ($managerOrders as $mOrder): ?>
                                        <tr>
                                            <td>#<?= (int)$mOrder['orderid'] ?></td>
                                            <td><?= htmlspecialchars($mOrder['client_name']) ?></td>
                                            <td><?= htmlspecialchars($mOrder['ostatus']) ?></td>
                                            <td><?= !empty($mOrder['DesignFinishDate']) ? htmlspecialchars($mOrder['DesignFinishDate']) : '-' ?></td>
                                            <td><?= !empty($mOrder['OrderFinishDate']) ? htmlspecialchars($mOrder['OrderFinishDate']) : '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-muted">No Project orders yet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <!-- DESIGNER UI -->
            <?php elseif ($role === 'designer'): ?>
                <div class="content-section">
                    <div class="blue-section-header"><i class="fas fa-user-circle"></i> Personal Information</div>
                    <div class="grey-card-box">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user me-2"></i>Name:</div><div><?= htmlspecialchars($userData['name']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i>Email:</div><div><?= htmlspecialchars($userData['email']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i>Phone:</div><div><?= htmlspecialchars($userData['tel']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-circle-check me-2"></i>Status:</div><div><?= htmlspecialchars($userData['status']) ?></div></div>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-palette"></i> My Designs</div>
                    <div class="grey-card-box">
                        <?php if (!empty($designerDesigns)): ?>
                            <?php foreach ($designerDesigns as $design): ?>
                                <?php $designImg = !empty($design['image_filename']) ? "../uploads/designs/" . $design['image_filename'] : "https://via.placeholder.com/72?text=Design"; ?>
                                <div class="design-item">
                                    <img src="<?= htmlspecialchars($designImg) ?>" class="design-thumb" onerror="this.src='https://via.placeholder.com/72?text=Design'">
                                    <div class="flex-grow-1">
                                        <div class="fw-bold"><?= htmlspecialchars($design['designName']) ?></div>
                                        <div class="small text-muted">Expected Price: HK$<?= number_format((float)$design['expect_price']) ?></div>
                                        <div class="small text-muted">Likes: <?= (int)$design['likes'] ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i> No designs found yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- SUPPLIER UI -->
            <?php elseif ($role === 'supplier'): ?>
                <div class="content-section">
                    <div class="blue-section-header"><i class="fas fa-building"></i> Personal Information</div>
                    <div class="grey-card-box">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user me-2"></i>Name:</div><div><?= htmlspecialchars($userData['name']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i>Email:</div><div><?= htmlspecialchars($userData['email']) ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i>Phone:</div><div><?= htmlspecialchars($userData['tel']) ?></div></div>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-chart-line"></i> Additional Data</div>
                    <div class="grey-card-box">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$supplierStats['workers'] ?></div><div class="small text-muted">Workers</div></div></div>
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$supplierStats['products'] ?></div><div class="small text-muted">Products</div></div></div>
                            <div class="col-md-4"><div class="manager-stat"><div class="num"><?= (int)$supplierStats['pending_deliveries'] ?></div><div class="small text-muted">Pending Deliveries</div></div></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Delivery Date</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($supplierDeliveries)): ?>
                                    <?php foreach ($supplierDeliveries as $sDelivery): ?>
                                        <tr>
                                            <td>#<?= (int)$sDelivery['orderid'] ?></td>
                                            <td><?= htmlspecialchars($sDelivery['pname']) ?></td>
                                            <td><?= (int)$sDelivery['quantity'] ?></td>
                                            <td><?= !empty($sDelivery['deliverydate']) ? htmlspecialchars($sDelivery['deliverydate']) : '-' ?></td>
                                            <td><?= htmlspecialchars($sDelivery['status']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-muted">No delivery data yet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-5 mb-4">
                        <h4 class="fw-bold mb-0">Worker Team</h4>
                        <button class="btn btn-primary btn-sm" onclick="openAddWorker()"><i class="fas fa-plus"></i> Add Worker</button>
                    </div>
                    <?php
                    $w_res = $mysqli->query("SELECT * FROM Worker WHERE supplierid = $id");
                    while ($w = $w_res->fetch_assoc()):
                        $wImg = "../uploads/worker/" . ($w['image'] ?: 'default.jpg');
                    ?>
                        <div class="worker-card">
                            <img src="<?= $wImg . '?t=' . time() ?>" class="worker-img" onerror="this.src='https://via.placeholder.com/65'">
                            <div class="flex-grow-1">
                                <div class="fw-bold"><?= htmlspecialchars($w['name']) ?></div>
                                <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= $w['email'] ?> | <i class="fas fa-phone me-1"></i><?= $w['phone'] ?></div>
                                <div class="small text-primary mt-1 fw-bold"><i class="fas fa-certificate me-1"></i><?= $w['certificate'] ?></div>
                            </div>
                            <i class="fas fa-edit edit-w-btn" onclick='openEditWorker(<?= json_encode($w) ?>)'></i>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>


</div>

<!-- Modal: Edit Profile -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5>Update Personal Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="update_profile" value="1">
                <div class="mb-3"><label class="form-label small fw-bold">Full Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($userData['name']) ?>" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Email Address</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($userData['email']) ?>" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Phone Number</label><input type="text" name="tel" class="form-control" value="<?= htmlspecialchars($userData['tel']) ?>"></div>
                <?php if ($role === 'client'): ?>
                    <div class="mb-3"><label class="form-label small fw-bold">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($userData['address']) ?>"></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Budget (HKD)</label><input type="number" name="budget" class="form-control" step="1000" value="<?= htmlspecialchars($userData['budget']) ?>"></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>

<!-- Modal: Floor Plan -->
<div class="modal fade" id="fpModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header"><h5>Upload Floor Plan</h5></div>
            <div class="modal-body">
                <input type="hidden" name="upload_floor_plan" value="1">
                <input type="file" name="fp_file" class="form-control" required>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Upload</button></div>
        </form>
    </div>
</div>

<!-- Modal: Worker (Supplier Only) -->
<div class="modal fade" id="workerModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header"><h5 id="wModalTitle">Worker Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="worker_action" id="wAction" value="add">
                <input type="hidden" name="workerid" id="wId">
                <div class="mb-3"><label class="form-label small fw-bold">Name</label><input type="text" name="w_name" id="wName" class="form-control" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Email</label><input type="email" name="w_email" id="wEmail" class="form-control" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Phone</label><input type="text" name="w_phone" id="wPhone" class="form-control"></div>
                <div class="mb-3"><label class="form-label small fw-bold">Certificate</label><input type="text" name="w_cert" id="wCert" class="form-control"></div>
                <div class="mb-3"><label class="form-label small fw-bold text-primary">Photo</label><input type="file" name="w_image" class="form-control" accept="image/*"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openAddWorker() {
    document.getElementById('wModalTitle').innerText = 'Add New Worker';
    document.getElementById('wAction').value = 'add';
    document.getElementById('wId').value = '';
    document.getElementById('wName').value = '';
    document.getElementById('wEmail').value = '';
    document.getElementById('wPhone').value = '';
    document.getElementById('wCert').value = '';
    new bootstrap.Modal(document.getElementById('workerModal')).show();
}
function openEditWorker(w) {
    document.getElementById('wModalTitle').innerText = 'Edit Worker';
    document.getElementById('wAction').value = 'edit';
    document.getElementById('wId').value = w.workerid;
    document.getElementById('wName').value = w.name;
    document.getElementById('wEmail').value = w.email;
    document.getElementById('wPhone').value = w.phone;
    document.getElementById('wCert').value = w.certificate;
    new bootstrap.Modal(document.getElementById('workerModal')).show();
}
</script>
    <!-- ==================== Chat Widget Integration ==================== -->
    <?php
    if (isset($_SESSION['user'])) {
        include __DIR__ . '/../Public/chat_widget.php';
    }
    ?>

    <!-- Chatfunction and initialization moved into Public/chat_widget.php -->
    <!-- ==================== End Chat Widget Integration ==================== -->
</body>
</html>