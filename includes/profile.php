<?php
// ==============================
// File: includes/profile.php
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

// 1. 获取用户信息 (已删除 company_name)
if ($role === 'client') {
    $id = (int) $user['clientid'];
    $res = $mysqli->query("SELECT cname as name, ctel as tel, cemail as email, budget, Floor_Plan FROM Client WHERE clientid = $id");
} elseif ($role === 'manager') {
    $id = (int) $user['managerid'];
    $res = $mysqli->query("SELECT mname as name, mtel as tel, memail as email FROM Manager WHERE managerid = $id");
} elseif ($role === 'designer') {
    $id = (int) $user['designerid'];
    $res = $mysqli->query("SELECT dname as name, dtel as tel, demail as email FROM Designer WHERE designerid = $id");
} elseif ($role === 'supplier') {
    $id = (int) $user['supplierid'];
    $res = $mysqli->query("SELECT sname as name, stel as tel, semail as email FROM Supplier WHERE supplierid = $id");
}
$userData = $res->fetch_assoc();

// 2. 获取 Contractor 介绍 (仅限 Supplier)
$introduction = "Professional " . ucfirst($role) . " at HappyDesign Platform.";
if ($role === 'supplier') {
    $cStmt = $mysqli->prepare("SELECT introduction FROM Contractors WHERE contractorid = ?");
    $cStmt->bind_param("i", $id);
    $cStmt->execute();
    $cData = $cStmt->get_result()->fetch_assoc();
    if ($cData)
        $introduction = $cData['introduction'];
}

// 3. 处理资料更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newName = $_POST['name'];
    $newTel = $_POST['tel'];

    if ($role === 'client') {
        $stmt = $mysqli->prepare("UPDATE Client SET cname=?, ctel=?, budget=? WHERE clientid=?");
        $stmt->bind_param("siii", $newName, $newTel, $_POST['budget'], $id);
    } elseif ($role === 'manager') {
        $stmt = $mysqli->prepare("UPDATE Manager SET mname=?, mtel=? WHERE managerid=?");
        $stmt->bind_param("sii", $newName, $newTel, $id);
    } else {
        $table = ucfirst($role);
        $nameCol = ($role === 'designer') ? 'dname' : 'sname';
        $telCol = ($role === 'designer') ? 'dtel' : 'stel';
        $idCol = $role . 'id';
        $stmt = $mysqli->prepare("UPDATE $table SET $nameCol=?, $telCol=? WHERE $idCol=?");
        $stmt->bind_param("sii", $newName, $newTel, $id);
    }
    if ($stmt->execute()) {
        $success = "Profile updated!";
        header("Refresh:1");
    }
}

$logoPath = ($role === 'supplier' && $id == 1) ? "../uploads/company/companylogo.jpg" : "../uploads/company/companylogo1.jpg";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($userData['name']) ?> - Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background: #f6f6f7;
            color: #444;
            font-family: 'Segoe UI', sans-serif;
        }

        .banner {
            height: 280px;
            background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover;
        }

        .profile-header-card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            margin-top: -60px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            position: relative;
            z-index: 10;
        }

        .logo-box {
            width: 90px;
            height: 90px;
            border: 1px solid #eee;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 25px;
            padding: 5px;
            background: #fff;
        }

        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            border-radius: 5px;
        }

        .sidebar-box {
            background: #fcfcfc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #f0f0f0;
        }

        .sidebar-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        .sidebar-label {
            color: #aaa;
            font-size: 0.8rem;
            display: block;
            margin-top: 10px;
        }

        .content-section {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .worker-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .worker-img {
            width: 65px;
            height: 65px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 15px;
        }

        .role-tag {
            font-size: 0.75rem;
            background: #3498db;
            color: #fff;
            padding: 2px 8px;
            border-radius: 5px;
            vertical-align: middle;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/header.php'; ?>

    <div class="banner"></div>

    <div class="container mb-5">
        <div class="row">
            <div class="col-lg-8">
                <div class="profile-header-card">
                    <div class="logo-box">
                        <img src="<?= $logoPath ?>" onerror="this.src='https://via.placeholder.com/100?text=User'">
                    </div>
                    <div>
                        <h2 class="fw-bold mb-1"><?= htmlspecialchars($userData['name']) ?> <span
                                class="role-tag"><?= ucfirst($role) ?></span></h2>
                        <p class="text-muted small mb-0"><?= htmlspecialchars($introduction) ?></p>
                    </div>
                    <div class="ms-auto">
                        <button class="btn btn-primary btn-sm px-4" data-bs-toggle="modal"
                            data-bs-target="#editModal">Edit Profile</button>
                    </div>
                </div>

                <div class="content-section">
                    <?php if ($role === 'supplier'): ?>
                        <h5 class="fw-bold mb-3">Worker Team</h5>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div> <?php endif; ?>
                        <?php
                        $w_res = $mysqli->query("SELECT * FROM Worker WHERE supplierid = $id");
                        while ($w = $w_res->fetch_assoc()):
                            $wImg = "../uploads/worker/" . ($w['image'] ?: 'default.jpg');
                            ?>
                            <div class="worker-card">
                                <img src="<?= $wImg ?>" class="worker-img" onerror="this.src='https://via.placeholder.com/65'">
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($w['name']) ?></div>
                                    <div class="text-muted small"><?= $w['email'] ?> | <?= $w['phone'] ?></div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php elseif ($role === 'client'): ?>
                        <h5 class="fw-bold mb-3">Residential Details</h5>
                        <p>Budget: <strong>HK$ <?= number_format($userData['budget']) ?></strong></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4" style="margin-top: 20px;">
                <div class="sidebar-box">
                    <div class="sidebar-title">Contact</div>
                    <span class="sidebar-label">Email</span>
                    <strong><?= htmlspecialchars($userData['email']) ?></strong>
                    <span class="sidebar-label">Phone</span>
                    <strong><?= htmlspecialchars($userData['tel']) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5>Update Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="mb-3"><label class="form-label small fw-bold">Name</label><input type="text" name="name"
                            class="form-control" value="<?= $userData['name'] ?>" required></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Phone</label><input type="text" name="tel"
                            class="form-control" value="<?= $userData['tel'] ?>"></div>
                    <?php if ($role === 'client'): ?>
                        <div class="mb-3"><label class="form-label small fw-bold">Budget</label><input type="number"
                                name="budget" class="form-control" value="<?= $userData['budget'] ?>"></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Changes</button></div>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>