<?php
// ==============================
// File: supplier_profile.php
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 1. 权限检查
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode('supplier/supplier_profile.php'));
    exit;
}

$supplierId = (int)($_SESSION['user']['supplierid'] ?? 0);
if ($supplierId <= 0) die('Invalid session.');

$success = '';
$error = '';

// 2. 处理员工的 增加/编辑 (包含数据库 image 字段更新)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name = trim($_POST['w_name'] ?? '');
    $email = trim($_POST['w_email'] ?? '');
    $phone = trim($_POST['w_phone'] ?? '');
    $cert = trim($_POST['w_cert'] ?? '');

    if ($action === 'add') {
        // 插入新员工，默认图片设为 default_worker.jpg 或先留空
        $stmt = $mysqli->prepare("INSERT INTO Worker (name, email, phone, certificate, supplierid, image) VALUES (?, ?, ?, ?, ?, 'default_worker.jpg')");
        $stmt->bind_param("ssssi", $name, $email, $phone, $cert, $supplierId);
        if ($stmt->execute()) {
            $newId = $mysqli->insert_id;
            // 处理图片上传并更新数据库硬编码文件名
            $uploadedFile = handleImageUpload($newId);
            if ($uploadedFile) {
                $mysqli->query("UPDATE Worker SET image = '$uploadedFile' WHERE workerid = $newId");
            }
            $success = "New worker added successfully!";
        } else {
            $error = "Add failed: " . $mysqli->error;
        }
    } 
    elseif ($action === 'edit') {
        $workerId = (int)$_POST['workerid'];
        $stmt = $mysqli->prepare("UPDATE Worker SET name=?, email=?, phone=?, certificate=? WHERE workerid=? AND supplierid=?");
        $stmt->bind_param("ssssii", $name, $email, $phone, $cert, $workerId, $supplierId);
        if ($stmt->execute()) {
            // 如果有新图片上传，更新文件并更新数据库字段
            $uploadedFile = handleImageUpload($workerId);
            if ($uploadedFile) {
                $mysqli->query("UPDATE Worker SET image = '$uploadedFile' WHERE workerid = $workerId");
            }
            $success = "Worker updated successfully!";
        } else {
            $error = "Update failed.";
        }
    }
}

// 图片上传辅助函数
function handleImageUpload($id) {
    if (isset($_FILES['w_image']) && $_FILES['w_image']['error'] === UPLOAD_ERR_OK) {
        $targetDir = "../uploads/worker/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        $fileName = "worker" . $id . ".jpg";
        if (move_uploaded_file($_FILES['w_image']['tmp_name'], $targetDir . $fileName)) {
            return $fileName; // 返回存入数据库的文件名
        }
    }
    return false;
}

// 3. 获取供应商基础信息
$supplierStmt = $mysqli->prepare("SELECT sname, stel, semail FROM Supplier WHERE supplierid = ?");
$supplierStmt->bind_param("i", $supplierId);
$supplierStmt->execute();
$supplierData = $supplierStmt->get_result()->fetch_assoc();
$supplierName = $supplierData['sname'] ?? 'Company';

// 获取介绍信息 (从 Contractors 表获取)
$contractorStmt = $mysqli->prepare("SELECT introduction FROM Contractors WHERE contractorid = ?");
$contractorStmt->bind_param("i", $supplierId);
$contractorStmt->execute();
$cData = $contractorStmt->get_result()->fetch_assoc();
$introduction = $cData['introduction'] ?? 'Welcome to ' . $supplierName . '!!';

// 公司 Logo 逻辑
$logoPath = ($supplierId == 1) ? "../uploads/company/companylogo.jpg" : "../uploads/company/companylogo1.jpg";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HappyDesign - Supplier Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f6f6f7; color: #333; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        /* Banner & Header Card */
        .banner { height: 280px; background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover; }
        .profile-header-card { background: #fff; border-radius: 8px; padding: 25px; margin-top: -60px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; position: relative; z-index: 10; }
        .logo-box { width: 90px; height: 90px; border: 1px solid #eee; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 25px; padding: 5px; background: #fff; }
        .logo-box img { max-width: 100%; max-height: 100%; border-radius: 4px; }
        
        /* Sidebar Styles */
        .sidebar-box { background: #f9f9f9; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .sidebar-title { font-size: 0.75rem; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .sidebar-content { font-size: 0.9rem; color: #555; }
        .sidebar-label { color: #aaa; font-size: 0.8rem; display: block; margin-top: 10px; }

        /* Worker Card */
        .worker-card { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; position: relative; }
        .worker-img { width: 75px; height: 75px; border-radius: 6px; object-fit: cover; margin-right: 20px; background: #eee; }
        .edit-icon { position: absolute; top: 15px; right: 15px; color: #3498db; cursor: pointer; transition: 0.2s; }
        .edit-icon:hover { color: #2980b9; transform: scale(1.1); }
        .cert-tag { font-size: 0.85rem; color: #3498db; margin-top: 5px; font-weight: 500; }
    </style>
</head>
<body>

    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center px-4">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link text-muted" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link text-muted" href="schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="supplier_profile.php">
                        <i class="fas fa-user-circle me-1"></i>Hello <?= htmlspecialchars($supplierName) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link text-muted" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

<div class="banner"></div>

<div class="container mb-5">
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- 公司信息卡片 -->
            <div class="profile-header-card mb-4">
                <div class="logo-box">
                    <img src="<?= $logoPath ?>" onerror="this.src='https://via.placeholder.com/80?text=LOGO'">
                </div>
                <div>
                    <h2 class="mb-1 fw-bold"><?= htmlspecialchars($supplierName) ?></h2>
                    <p class="text-muted small mb-2"><?= htmlspecialchars($introduction) ?></p>
                    <div class="d-flex gap-3 text-muted" style="font-size: 0.85rem;">
                        <span><i class="fas fa-map-marker-alt me-1"></i> Location City</span>
                        <span><i class="fas fa-briefcase me-1"></i> 0 projects</span>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Worker team</h5>
                <button class="btn btn-sm btn-primary px-3" data-bs-toggle="modal" data-bs-target="#workerModal" onclick="openAddModal()">
                    <i class="fas fa-plus me-1"></i> Add Worker
                </button>
            </div>

            <?php if($success): ?> <div class="alert alert-success py-2"><?= $success ?></div> <?php endif; ?>
            <?php if($error): ?> <div class="alert alert-danger py-2"><?= $error ?></div> <?php endif; ?>

            <!-- 员工列表 -->
            <?php
            $stmt = $mysqli->prepare("SELECT * FROM Worker WHERE supplierid = ?");
            $stmt->bind_param("i", $supplierId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($w = $res->fetch_assoc()):
                $imgPath = !empty($w['image']) ? "../uploads/worker/" . $w['image'] : "../uploads/worker/default_worker.jpg";
                $displayImg = $imgPath . "?t=" . time();
            ?>
                <div class="worker-card">
                    <img src="<?= $displayImg ?>" class="worker-img" onerror="this.src='https://via.placeholder.com/75?text=User'">
                    <div class="flex-grow-1">
                        <div class="fw-bold"><?= htmlspecialchars($w['name']) ?></div>
                        <div class="text-muted small">
                            <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($w['email']) ?> | 
                            <i class="fas fa-phone ms-1"></i> <?= htmlspecialchars($w['phone']) ?>
                        </div>
                        <?php if($w['certificate']): ?>
                            <div class="cert-tag"><i class="fas fa-certificate me-1"></i><?= htmlspecialchars($w['certificate']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted mt-1" style="font-size: 0.75rem;">Belonging: <?= htmlspecialchars($supplierName) ?></div>
                    </div>
                    <!-- 编辑图标 -->
                    <div class="edit-icon" onclick='openEditModal(<?= json_encode($w) ?>)'><i class="fas fa-edit"></i></div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Sidebar (侧边栏) -->
        <div class="col-lg-4" style="margin-top: 20px;">
            <div class="sidebar-box">
                <div class="sidebar-title">Contact</div>
                <div class="sidebar-content">
                    <span class="sidebar-label">Email</span>
                    <strong><?= htmlspecialchars($supplierData['semail'] ?? '—') ?></strong>
                    <span class="sidebar-label">Phone</span>
                    <strong><?= htmlspecialchars($supplierData['stel'] ?? '—') ?></strong>
                </div>
            </div>

            <div class="sidebar-box">
                <div class="sidebar-title">Services</div>
                <div class="sidebar-content">
                    <div class="mb-1">Interior design</div>
                    <div class="mb-1">Space planning</div>
                    <div>Project management</div>
                </div>
            </div>

            <div class="sidebar-box">
                <div class="sidebar-title">Links</div>
                <div class="sidebar-content">
                    <a href="#" class="text-primary text-decoration-none">https://your-website.example</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal (弹窗表单) -->
<div class="modal fade" id="workerModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="" method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Worker</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="workerid" id="formWorkerId">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Full Name</label>
                    <input type="text" name="w_name" id="w_name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Email</label>
                    <input type="email" name="w_email" id="w_email" class="form-control form-control-sm" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Phone Number</label>
                    <input type="text" name="w_phone" id="w_phone" class="form-control form-control-sm">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Certificate / Qualification</label>
                    <input type="text" name="w_cert" id="w_cert" class="form-control form-control-sm">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-primary">Worker Photo (Change)</label>
                    <input type="file" name="w_image" class="form-control form-control-sm" accept="image/jpeg, image/png">
                    <div class="form-text text-muted" style="font-size: 0.7rem;">Upload will update the database image record.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Add New Worker';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formWorkerId').value = '';
    document.getElementById('w_name').value = '';
    document.getElementById('w_email').value = '';
    document.getElementById('w_phone').value = '';
    document.getElementById('w_cert').value = '';
}

function openEditModal(worker) {
    document.getElementById('modalTitle').innerText = 'Edit Worker';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formWorkerId').value = worker.workerid;
    document.getElementById('w_name').value = worker.name;
    document.getElementById('w_email').value = worker.email;
    document.getElementById('w_phone').value = worker.phone;
    document.getElementById('w_cert').value = worker.certificate;
    
    var myModal = new bootstrap.Modal(document.getElementById('workerModal'));
    myModal.show();
}
</script>

</body>
</html>