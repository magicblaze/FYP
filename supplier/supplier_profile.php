<?php
// ==============================
// File: S_profile.php - Supplier profile page
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated or not contractor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php?redirect=' . urlencode('supplier/S_profile.php'));
    exit;
}

// Map supplierid to contractorid for demo (in real app, use correct mapping)
$contractorId = (int)($_SESSION['user']['supplierid'] ?? 0);
if ($contractorId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

$success = '';
$error = '';

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $introduction = trim($_POST['introduction'] ?? '');
    if (empty($name)) {
        $error = 'Name is required.';
    } else {
        // Update contractor information
        $updateStmt = $mysqli->prepare("UPDATE Contractors SET cname = ?, ctel = ?, introduction = ? WHERE contractorid = ?");
        $phoneInt = !empty($phone) ? (int)$phone : null;
        $updateStmt->bind_param("sisi", $name, $phoneInt, $introduction, $contractorId);
        if ($updateStmt->execute()) {
            $success = 'Profile updated successfully!';
            $_SESSION['user']['name'] = $name;
        } else {
            $error = 'Failed to update profile. Please try again.';
        }
    }
}

// Fetch contractor details
$contractorStmt = $mysqli->prepare("SELECT cname, ctel, cemail, introduction FROM Contractors WHERE contractorid = ?");
$contractorStmt->bind_param("i", $contractorId);
$contractorStmt->execute();
$contractorData = $contractorStmt->get_result()->fetch_assoc();

// Format phone number for display
$phoneDisplay = '';
if (!empty($contractorData['ctel'])) {
    $phoneDisplay = (string)$contractorData['ctel'];
}

// Default introduction if null
if (empty($contractorData['introduction'])) {
    $contractorData['introduction'] = 'Short bio — introduce yourself, your style, and specialties.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Contractor Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f6f6f7; }
        .profile-container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            padding: 2.5rem 3.5rem;
            margin: 2rem auto;
            width: 100%;
            max-width: 1100px;
            min-width: 350px;
        }
        .profile-header {
            display: flex;
            flex-direction: row;
            align-items: flex-end;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #3498db;
            background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            border-radius: 10px 10px 0 0;
            min-height: 260px;
            justify-content: flex-start;
            padding-left: 2rem;
            position: relative;
        }
        /* Remove company-logo style, not used */
        .profile-header-content {
            margin-left: 0;
            margin-bottom: 0.5rem;
        }
        .profile-name {
            font-size: 2.1rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.25rem;
            background: rgba(44,62,80,0.7);
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            display: inline-block;
        }
        .profile-email {
            color: #fff;
            font-size: 1.1rem;
            background: rgba(127,140,141,0.7);
            padding: 0.25rem 1rem;
            border-radius: 6px;
            display: inline-block;
        }
        .profile-section { margin-bottom: 1.5rem; }
        .section-title { color: #2c3e50; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #3498db; }
        .profile-info-card { background: #f8f9fa; border-radius: 10px; padding: 1.5rem; }
        .edit-form .form-label { font-weight: 600; color: #2c3e50; }
        .edit-form .form-control { border-radius: 8px; }
        .btn-edit { background-color: #3498db; border: none; color: white; }
        .btn-edit:hover { background-color: #2980b9; color: white; }
        .btn-cancel { background-color: #95a5a6; border: none; color: white; }
        .btn-cancel:hover { background-color: #7f8c8d; color: white; }
        /* Worker card style */
        .worker-card { display: flex; align-items: flex-start; background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); padding: 1.2rem 1.5rem; margin-bottom: 1.2rem; min-height: 120px; }
        .worker-avatar { width: 70px; height: 70px; border-radius: 10px; object-fit: cover; margin-right: 1.5rem; background: #eee; }
        .worker-info { flex: 1; }
        .worker-name { font-size: 1.1rem; font-weight: 600; color: #2c3e50; }
        .worker-meta { color: #7f8c8d; font-size: 0.97rem; }
        .worker-cert { color: #2980b9; font-size: 0.97rem; }
    </style>
</head>
<body>
    <!-- Top menu copied from design_dashboard.php -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="../furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="../client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="../client/my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link" href="../client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="../login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>
    <main class="container mt-4">
        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-header-content">
                    <div class="profile-name" style="font-size:2.6rem;line-height:1.2;letter-spacing:1px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding:0.7rem 2.2rem;">  </div>
                    
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- View Mode -->
            <div id="viewMode">
                <div class="profile-section d-flex flex-row gap-4" style="margin-top:2.5rem;">
                    <div style="flex:1;min-width:260px;">
                        <div class="profile-name mb-2" style="color:#2c3e50;background:none;"> <?= htmlspecialchars($contractorData['cname'] ?? '—') ?> </div>
                        <div class="mb-2" style="color:#7f8c8d;"> <?= htmlspecialchars($contractorData['introduction']) ?> </div>
                        <div class="d-flex gap-4 mt-2">
                            <div style="color:#7f8c8d;"><i class="fas fa-map-marker-alt me-1"></i>Location <span style="color:#aaa">City, Country</span></div>
                        </div>
                        <div class="d-flex gap-4 mt-2">
                            <div style="color:#7f8c8d;"><i class="fas fa-briefcase me-1"></i>0 projects</div>
                            <div style="color:#7f8c8d;">0 clients</div>
                            <div style="color:#7f8c8d;">0 years</div>
                        </div>
                    </div>
                    <div class="ms-auto" style="min-width:220px;">
                        <div class="profile-info-card mb-3">
                            <div class="fw-bold mb-2">Contact</div>
                            <div>Email<br><span class="text-muted"> <?= htmlspecialchars($contractorData['cemail'] ?? '—') ?> </span></div>
                            <div class="mt-2">Phone<br><span class="text-muted"> <?= htmlspecialchars($phoneDisplay ?: '—') ?> </span></div>
                        </div>
                        <div class="profile-info-card mb-3">
                            <div class="fw-bold mb-2">Services</div>
                            <div>Interior design<br>Space planning<br>Project management</div>
                        </div>
                        <div class="profile-info-card">
                            <div class="fw-bold mb-2">Links</div>
                            <div><a href="#" class="text-decoration-none">https://your-website.example</a></div>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <h4>Portfolio (Our Team)</h4>
                    <?php
                    // Fetch workers for this contractor
                    $workers = [];

                    $workerSql = "SELECT w.name, w.email, w.phone, w.certificate, c.cname AS belonging
                                FROM `Worker` w
                                JOIN `Contractors` c ON w.contractorid = c.contractorid
                                WHERE w.contractorid = ?";

                    if ($workerStmt = $mysqli->prepare($workerSql)) {
                        $workerStmt->bind_param("i", $contractorId);
                        if ($workerStmt->execute()) {
                            $result = $workerStmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $workers[] = $row;
                            }
                            $result->free();
                        } else {
                            // log/handle execute error
                            error_log("Worker statement execute error: " . $workerStmt->error);
                        }
                        $workerStmt->close();
                    } else {
                        // prepare failed: probably table missing or bad SQL
                        error_log("Worker statement prepare error: " . $mysqli->error);
                    }
                    ?>

                    <?php if (count($workers) > 0): ?>
                        <?php foreach ($workers as $worker): ?>
                            <div class="worker-card">
                                <img src="../uploads/company/workerimg.jpg" alt="Worker Avatar" class="worker-avatar">
                                <div class="worker-info">
                                    <div class="worker-name"><?= htmlspecialchars($worker['name'] ?? '—') ?></div>
                                    <div class="worker-meta">
                                        <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($worker['email'] ?? '—') ?>
                                        &nbsp; <i class="fas fa-phone me-1"></i><?= htmlspecialchars($worker['phone'] ?? '—') ?>
                                    </div>
                                    <div class="worker-cert mt-1"><i class="fas fa-certificate me-1"></i><?= htmlspecialchars($worker['certificate'] ?? '—') ?></div>
                                    <div class="worker-meta mt-1"><i class="fas fa-users me-1"></i>Belonging: <?= htmlspecialchars($worker['belonging'] ?? '—') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">No workers found for this contractor.</div>
                    <?php endif; ?>
            </div>

            <!-- Edit Mode -->
            <div id="editMode" style="display: none;">
                <form method="post" class="edit-form">
                    <div class="profile-section">
                        <h3 class="section-title"><i class="fas fa-edit me-2"></i>Edit Profile</h3>
                        <div class="mb-3">
                            <label for="name" class="form-label"><i class="fas fa-user me-2"></i>Name *</label>
                            <input type="text" class="form-control form-control-lg" id="name" name="name" 
                                   value="<?= htmlspecialchars($contractorData['cname'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-envelope me-2"></i>Email</label>
                            <input type="email" class="form-control form-control-lg" id="email" 
                                   value="<?= htmlspecialchars($contractorData['cemail'] ?? '') ?>" disabled>
                            <small class="text-muted">Email cannot be changed.</small>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label"><i class="fas fa-phone me-2"></i>Phone</label>
                            <input type="tel" class="form-control form-control-lg" id="phone" name="phone" 
                                   value="<?= htmlspecialchars($phoneDisplay) ?>" placeholder="Enter your phone number">
                        </div>
                        <div class="mb-3">
                            <label for="introduction" class="form-label"><i class="fas fa-info-circle me-2"></i>Introduction</label>
                            <textarea class="form-control form-control-lg" id="introduction" name="introduction" rows="3" placeholder="Short bio — introduce yourself, your style, and specialties."><?= htmlspecialchars($contractorData['introduction']) ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex gap-3 justify-content-center mt-4">
                        <button type="button" class="btn btn-cancel btn-lg px-4" onclick="toggleEditMode()">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success btn-lg px-4">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleEditMode() {
            const viewMode = document.getElementById('viewMode');
            const editMode = document.getElementById('editMode');
            
            if (viewMode.style.display === 'none') {
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
            } else {
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
            }
        }
    </script>
</body>
</html>
