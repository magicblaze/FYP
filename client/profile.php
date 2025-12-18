<?php
// ==============================
// File: profile.php - User profile page
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Redirect to login if not authenticated
if (empty($_SESSION['user'])) {
    header('Location: login.php?redirect=' . urlencode('profile.php'));
    exit;
}

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

$success = '';
$error = '';

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if (empty($name)) {
        $error = 'Name is required.';
    } else {
        // Update client information
        $updateStmt = $mysqli->prepare("UPDATE Client SET cname = ?, ctel = ?, address = ? WHERE clientid = ?");
        $phoneInt = !empty($phone) ? (int)$phone : null;
        $updateStmt->bind_param("sisi", $name, $phoneInt, $address, $clientId);
        
        if ($updateStmt->execute()) {
            $success = 'Profile updated successfully!';
            // Update session name
            $_SESSION['user']['name'] = $name;
        } else {
            $error = 'Failed to update profile. Please try again.';
        }
    }
}

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Format phone number for display
$phoneDisplay = '';
if (!empty($clientData['ctel'])) {
    $phoneDisplay = (string)$clientData['ctel'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - My Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin: 1rem auto;
            max-width: 800px;
        }
        .profile-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #3498db;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2c3e50);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 3rem;
            font-weight: 600;
        }
        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        .profile-email {
            color: #7f8c8d;
            font-size: 1rem;
        }
        .profile-section {
            margin-bottom: 1.5rem;
        }
        .section-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #3498db;
        }
        .profile-info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .info-row {
            display: flex;
            margin-bottom: 1rem;
            align-items: center;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            width: 120px;
            color: #2c3e50;
        }
        .info-value {
            flex: 1;
            color: #5a6c7d;
        }
        .info-icon {
            width: 40px;
            color: #3498db;
            font-size: 1.1rem;
        }
        .edit-form .form-label {
            font-weight: 600;
            color: #2c3e50;
        }
        .edit-form .form-control {
            border-radius: 8px;
        }
        .btn-edit {
            background-color: #3498db;
            border: none;
            color: white;
        }
        .btn-edit:hover {
            background-color: #2980b9;
            color: white;
        }
        .btn-cancel {
            background-color: #95a5a6;
            border: none;
            color: white;
        }
        .btn-cancel:hover {
            background-color: #7f8c8d;
            color: white;
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted active" href="../client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="../client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="../chat.php">Chatroom</a></li>
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

                <div class="profile-name"><?= htmlspecialchars($clientData['cname'] ?? 'User') ?></div>
                <div class="profile-email"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($clientData['cemail'] ?? '') ?></div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- View Mode -->
            <div id="viewMode">
                <div class="profile-section">
                    <h3 class="section-title"><i class="fas fa-user-circle me-2"></i>Personal Information</h3>
                    <div class="profile-info-card">
                        <div class="info-row">
                            <div class="info-icon"><i class="fas fa-user"></i></div>
                            <div class="info-label">Name:</div>
                            <div class="info-value"><?= htmlspecialchars($clientData['cname'] ?? '—') ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-icon"><i class="fas fa-envelope"></i></div>
                            <div class="info-label">Email:</div>
                            <div class="info-value"><?= htmlspecialchars($clientData['cemail'] ?? '—') ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                            <div class="info-label">Phone:</div>
                            <div class="info-value"><?= htmlspecialchars($phoneDisplay ?: '—') ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div class="info-label">Address:</div>
                            <div class="info-value"><?= htmlspecialchars($clientData['address'] ?? '—') ?></div>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <button type="button" class="btn btn-edit btn-lg px-4" onclick="toggleEditMode()">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </button>
                </div>
            </div>

            <!-- Edit Mode -->
            <div id="editMode" style="display: none;">
                <form method="post" class="edit-form">
                    <div class="profile-section">
                        <h3 class="section-title"><i class="fas fa-edit me-2"></i>Edit Profile</h3>
                        
                        <div class="mb-3">
                            <label for="name" class="form-label"><i class="fas fa-user me-2"></i>Name *</label>
                            <input type="text" class="form-control form-control-lg" id="name" name="name" 
                                   value="<?= htmlspecialchars($clientData['cname'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-envelope me-2"></i>Email</label>
                            <input type="email" class="form-control form-control-lg" id="email" 
                                   value="<?= htmlspecialchars($clientData['cemail'] ?? '') ?>" disabled>
                            <small class="text-muted">Email cannot be changed.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label"><i class="fas fa-phone me-2"></i>Phone</label>
                            <input type="tel" class="form-control form-control-lg" id="phone" name="phone" 
                                   value="<?= htmlspecialchars($phoneDisplay) ?>" placeholder="Enter your phone number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label"><i class="fas fa-map-marker-alt me-2"></i>Address</label>
                            <textarea class="form-control form-control-lg" id="address" name="address" rows="2" 
                                      placeholder="Enter your address"><?= htmlspecialchars($clientData['address'] ?? '') ?></textarea>
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
