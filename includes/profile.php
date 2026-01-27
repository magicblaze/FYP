<?php
// ==============================
// File: includes/profile.php
// 集成 Client 详情更新与 Supplier 工人管理（支持图片上传）
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$role = strtolower($user['role']);
$success = '';
$error = '';

// 1. 获取 ID 键名
$id_key = $role . 'id';
$id = (int)$user[$id_key];

// 2. 动态获取用户信息
if ($role === 'client') {
    $res = $mysqli->query("SELECT cname as name, ctel as tel, cemail as email, address, budget, Floor_Plan, payment_method FROM Client WHERE clientid = $id");
} elseif ($role === 'manager') {
    $res = $mysqli->query("SELECT mname as name, mtel as tel, memail as email FROM Manager WHERE managerid = $id");
} elseif ($role === 'designer') {
    $res = $mysqli->query("SELECT dname as name, dtel as tel, demail as email FROM Designer WHERE designerid = $id");
} elseif ($role === 'supplier') {
    $res = $mysqli->query("SELECT sname as name, stel as tel, semail as email FROM Supplier WHERE supplierid = $id");
}
$userData = $res->fetch_assoc();

// 获取介绍信息 (仅限 Supplier)
$introduction = "Professional " . ucfirst($role) . " at HappyDesign Platform.";
if ($role === 'supplier') {
    $cStmt = $mysqli->prepare("SELECT introduction FROM Contractors WHERE contractorid = ?");
    $cStmt->bind_param("i", $id);
    $cStmt->execute();
    if ($cData = $cStmt->get_result()->fetch_assoc()) $introduction = $cData['introduction'];
}

// 3. 处理资料更新 (Profile / Floor Plan / Budget)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A. 更新工人逻辑 (Supplier)
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
                if (isset($_FILES['w_image'])) handleUpload('worker', $newWId);
                $success = "Worker added successfully!";
            }
        } elseif ($wAction === 'edit') {
            $wId = (int)$_POST['workerid'];
            $stmt = $mysqli->prepare("UPDATE Worker SET name=?, email=?, phone=?, certificate=? WHERE workerid=? AND supplierid=?");
            $stmt->bind_param("ssssii", $wName, $wEmail, $wPhone, $wCert, $wId, $id);
            if ($stmt->execute()) {
                if (isset($_FILES['w_image'])) handleUpload('worker', $wId);
                $success = "Worker updated successfully!";
            }
        }
    }
    // B. 更新个人主资料 (Client/Manager/Designer)
    elseif (isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $tel = $_POST['tel'];
        if ($role === 'client') {
            $addr = $_POST['address'] ?? '';
            $budget = (int)$_POST['budget'];
            $stmt = $mysqli->prepare("UPDATE Client SET cname=?, ctel=?, address=?, budget=? WHERE clientid=?");
            $stmt->bind_param("sssii", $name, $tel, $addr, $budget, $id);
        } elseif ($role === 'manager') {
            $stmt = $mysqli->prepare("UPDATE Manager SET mname=?, mtel=? WHERE managerid=?");
            $stmt->bind_param("sii", $name, $tel, $id);
        } else {
            $table = ucfirst($role);
            $nameCol = ($role === 'designer' ? 'dname' : 'sname');
            $telCol = ($role === 'designer' ? 'dtel' : 'stel');
            $stmt = $mysqli->prepare("UPDATE $table SET $nameCol=?, $telCol=? WHERE $id_key=?");
            $stmt->bind_param("sii", $name, $tel, $id);
        }
        if ($stmt->execute()) {
            $success = "Profile updated!";
            header("Refresh:1");
        }
    }
}

// 上传处理函数
function handleUpload($type, $targetId) {
    global $mysqli;
    if (isset($_FILES['w_image']) && $_FILES['w_image']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['w_image']['name'], PATHINFO_EXTENSION);
        $fileName = "worker" . $targetId . "." . $ext;
        if (move_uploaded_file($_FILES['w_image']['tmp_name'], "../uploads/worker/" . $fileName)) {
            $mysqli->query("UPDATE Worker SET image = '$fileName' WHERE workerid = $targetId");
        }
    }
}

$logoPath = ($role === 'supplier' && $id == 1) ? "../uploads/company/companylogo.jpg" : "../uploads/company/companylogo1.jpg";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f6f6f7; color: #444; font-family: 'Segoe UI', sans-serif; }
        .banner { height: 280px; background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover; }
        .profile-header-card { background: #fff; border-radius: 12px; padding: 25px; margin-top: -60px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); display: flex; align-items: center; position: relative; z-index: 10; }
        .logo-box { width: 90px; height: 90px; border: 1px solid #eee; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 25px; background: #fff; }
        .logo-box img { max-width: 100%; max-height: 100%; border-radius: 5px; }
        
        .sidebar-box { background: #fcfcfc; border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid #f0f0f0; }
        .sidebar-title { font-size: 0.75rem; font-weight: 700; color: #999; text-transform: uppercase; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .sidebar-label { color: #aaa; font-size: 0.8rem; display: block; margin-top: 10px; }

        .content-section { background: #fff; border-radius: 12px; padding: 30px; margin-top: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .blue-section-header { border-bottom: 2px solid #3498db; padding-bottom: 8px; margin-bottom: 20px; color: #2c3e50; font-weight: 700; display: flex; align-items: center; }
        .blue-section-header i { margin-right: 12px; color: #3498db; }

        /* Worker Card */
        .worker-card { border: 1px solid #eee; border-radius: 10px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; position: relative; background: #fff; }
        .worker-img { width: 65px; height: 65px; border-radius: 8px; object-fit: cover; margin-right: 15px; background: #eee; }
        .edit-w-btn { position: absolute; right: 15px; top: 15px; color: #3498db; cursor: pointer; }

        /* Client Info Style */
        .info-row { display: flex; padding: 12px 0; border-bottom: 1px solid #f8f9fa; align-items: center; }
        .info-label { width: 120px; color: #7f8c8d; font-weight: 600; font-size: 0.95rem; }
        .info-val { color: #333; font-weight: 500; }
        .grey-card-box { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-top: 10px; }
    </style>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>
<div class="banner"></div>

<div class="container mb-5">
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <div class="profile-header-card mb-4">
                <div class="logo-box">
                    <img src="<?= $logoPath ?>" onerror="this.src='https://via.placeholder.com/100?text=User'">
                </div>
                <div>
                    <h2 class="fw-bold mb-0"><?= htmlspecialchars($userData['name']) ?></h2>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($introduction) ?></p>
                </div>
                <div class="ms-auto text-end">
                    <span class="badge bg-primary mb-2"><?= ucfirst($role) ?> Account</span><br>
                    <button class="btn btn-outline-primary btn-sm px-4" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit Profile</button>
                </div>
            </div>

            <!-- Client Specific UI -->
            <?php if ($role === 'client'): ?>
                <div class="content-section">
                    <div class="blue-section-header"><i class="fas fa-user-circle"></i> Personal Information</div>
                    <div class="grey-card-box">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user me-2"></i>Name:</div><div class="info-val"><?= $userData['name'] ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i>Email:</div><div class="info-val"><?= $userData['email'] ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i>Phone:</div><div class="info-val"><?= $userData['tel'] ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-map-marker-alt me-2"></i>Address:</div><div class="info-val"><?= $userData['address'] ?: 'Not set' ?></div></div>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-file-pdf"></i> Floor Plan</div>
                    <div class="grey-card-box">
                        <p class="text-muted small mb-3"><?= $userData['Floor_Plan'] ? "Current Plan: " . $userData['Floor_Plan'] : "No floor plan uploaded yet." ?></p>
                        <button class="btn btn-primary btn-sm"><i class="fas fa-upload me-1"></i> Upload Floor Plan</button>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-money-bill-wave"></i> Budget</div>
                    <div class="grey-card-box">
                        <p class="mb-0">Default budget: <strong>HK$<?= number_format($userData['budget']) ?></strong></p>
                        <button class="btn btn-primary btn-sm mt-3">Set Budget</button>
                    </div>

                    <div class="blue-section-header mt-5"><i class="fas fa-credit-card"></i> Payment Method</div>
                    <div class="grey-card-box">
                        <p class="text-muted small">No payment method set yet.</p>
                        <button class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Payment Method</button>
                    </div>
                </div>

            <!-- Supplier Specific UI -->
            <?php elseif ($role === 'supplier'): ?>
                <div class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">Worker Team</h4>
                        <button class="btn btn-primary btn-sm" onclick="openAddWorker()"><i class="fas fa-plus"></i> Add Worker</button>
                    </div>
                    <?php if ($success): ?><div class="alert alert-success py-2 small"><?= $success ?></div><?php endif; ?>
                    
                    <?php
                    $w_res = $mysqli->query("SELECT * FROM Worker WHERE supplierid = $id");
                    while ($w = $w_res->fetch_assoc()):
                        $wImg = "../uploads/worker/" . ($w['image'] ?: 'default.jpg');
                    ?>
                        <div class="worker-card">
                            <img src="<?= $wImg . '?t=' . time() ?>" class="worker-img" onerror="this.src='https://via.placeholder.com/65'">
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($w['name']) ?></div>
                                <div class="text-muted small"><i class="fas fa-envelope me-1"></i><?= $w['email'] ?> | <i class="fas fa-phone me-1"></i><?= $w['phone'] ?></div>
                                <div class="text-primary small mt-1 fw-bold"><i class="fas fa-certificate me-1"></i><?= $w['certificate'] ?></div>
                                <div class="small text-muted" style="font-size:0.7rem">Belonging: <?= $userData['name'] ?></div>
                            </div>
                            <i class="fas fa-edit edit-w-btn" onclick='openEditWorker(<?= json_encode($w) ?>)'></i>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4" style="margin-top: 20px;">
            <div class="sidebar-box">
                <div class="sidebar-title">Contact</div>
                <span class="sidebar-label">Email</span><strong><?= $userData['email'] ?></strong>
                <span class="sidebar-label">Phone</span><strong><?= $userData['tel'] ?></strong>
            </div>
            <div class="sidebar-box">
                <div class="sidebar-title">Services</div>
                <ul class="list-unstyled mb-0 small">
                    <li><i class="fas fa-check text-success me-2"></i>Interior design</li>
                    <li><i class="fas fa-check text-success me-2"></i>Space planning</li>
                    <li><i class="fas fa-check text-success me-2"></i>Project management</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Edit Profile -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5>Update Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="update_profile" value="1">
                <div class="mb-3"><label class="form-label small fw-bold">Name</label><input type="text" name="name" class="form-control" value="<?= $userData['name'] ?>" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Phone</label><input type="text" name="tel" class="form-control" value="<?= $userData['tel'] ?>"></div>
                <?php if ($role === 'client'): ?>
                    <div class="mb-3"><label class="form-label small fw-bold">Address</label><input type="text" name="address" class="form-control" value="<?= $userData['address'] ?>"></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Budget (HKD)</label><input type="number" name="budget" class="form-control" value="<?= $userData['budget'] ?>"></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>

<!-- Modal: Worker (Add/Edit) -->
<div class="modal fade" id="workerModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header"><h5 id="wModalTitle">Edit Worker</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="worker_action" id="wAction" value="add">
                <input type="hidden" name="workerid" id="wId">
                <div class="mb-3"><label class="form-label small fw-bold">Full Name</label><input type="text" name="w_name" id="wName" class="form-control" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Email</label><input type="email" name="w_email" id="wEmail" class="form-control" required></div>
                <div class="mb-3"><label class="form-label small fw-bold">Phone Number</label><input type="text" name="w_phone" id="wPhone" class="form-control"></div>
                <div class="mb-3"><label class="form-label small fw-bold">Certificate</label><input type="text" name="w_cert" id="wCert" class="form-control"></div>
                <div class="mb-3"><label class="form-label small fw-bold text-primary">Change Photo</label><input type="file" name="w_image" class="form-control" accept="image/*"></div>
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
</body>
</html>