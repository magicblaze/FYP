<?php
// ==============================
// File: profile.php - User profile page with floor plan, budget, and payment method
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
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
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
    } elseif ($action === 'update_floor_plan') {
        // Handle floor plan upload
        $uploadPath = null;
        if (!empty($_FILES['floorplan']['name'])) {
            $allowedExt = ['pdf','jpg','jpeg','png'];
            $allowedMimes = ['application/pdf','image/jpeg','image/png'];
            $maxSize = 10 * 1024 * 1024;

            if ($_FILES['floorplan']['error'] === UPLOAD_ERR_OK && $_FILES['floorplan']['size'] <= $maxSize) {
                $ext = strtolower(pathinfo($_FILES['floorplan']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt, true)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $_FILES['floorplan']['tmp_name']);
                    finfo_close($finfo);
                    if (!in_array($mime, $allowedMimes, true)) {
                        $error = 'Unsupported file format. Only PDF, JPG, and PNG are allowed.';
                    } else {
                        $dir = __DIR__ . '/../uploads/floor_plan';
                        if (!is_dir($dir)) @mkdir($dir, 0777, true);
                        $newName = 'fp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dest = $dir . '/' . $newName;
                        if (move_uploaded_file($_FILES['floorplan']['tmp_name'], $dest)) {
                            @chmod($dest, 0644);
                            $uploadPath = 'uploads/floor_plan/' . $newName;
                            
                            // Update client floor plan in database
                            $updateStmt = $mysqli->prepare("UPDATE Client SET floor_plan = ? WHERE clientid = ?");
                            $updateStmt->bind_param("si", $uploadPath, $clientId);
                            if ($updateStmt->execute()) {
                                $success = 'Floor plan uploaded successfully!';
                            } else {
                                $error = 'Failed to save floor plan. Please try again.';
                            }
                        } else {
                            $error = 'Failed to save the uploaded file. Please try again.';
                        }
                    }
                } else {
                    $error = 'Invalid file type.';
                }
            } elseif ($_FILES['floorplan']['error'] !== UPLOAD_ERR_NO_FILE) {
                $error = 'Upload error. Please try again.';
            }
        } else {
            $error = 'Please select a file to upload.';
        }
    } elseif ($action === 'update_budget') {
        $budget = (int)($_POST['budget'] ?? 0);
        
        if ($budget <= 0) {
            $error = 'Budget must be greater than 0.';
        } else {
            // Update client budget in database
            $updateStmt = $mysqli->prepare("UPDATE Client SET budget = ? WHERE clientid = ?");
            $updateStmt->bind_param("ii", $budget, $clientId);
            if ($updateStmt->execute()) {
                $success = 'Budget updated successfully!';
            } else {
                $error = 'Failed to update budget. Please try again.';
            }
        }
    } elseif ($action === 'update_payment_method') {
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        
        // Validate payment method
        if (empty($paymentMethod)) {
            $error = 'Payment method is required.';
        } else {
            // Validate payment method specific fields
            if ($paymentMethod === 'alipay_hk') {
                $alipayEmail = trim($_POST['alipay_hk_email'] ?? '');
                $alipayPhone = trim($_POST['alipay_hk_phone'] ?? '');
                if (empty($alipayEmail)) {
                    $error = 'AlipayHK Account Email is required.';
                } elseif (empty($alipayPhone)) {
                    $error = 'AlipayHK Phone Number is required.';
                }
            } elseif ($paymentMethod === 'paypal') {
                $paypalEmail = trim($_POST['paypal_email'] ?? '');
                if (empty($paypalEmail)) {
                    $error = 'PayPal Email is required.';
                }
            } elseif ($paymentMethod === 'fps') {
                $fpsId = trim($_POST['fps_id'] ?? '');
                $fpsName = trim($_POST['fps_name'] ?? '');
                if (empty($fpsId)) {
                    $error = 'FPS ID is required.';
                } elseif (empty($fpsName)) {
                    $error = 'Account Holder Name is required.';
                }
            } else {
                $error = 'Invalid payment method selected.';
            }
            
            // If no validation error, save payment method
            if (!$error) {
                $paymentData = json_encode([
                    'method' => $paymentMethod,
                    'alipay_hk_email' => trim($_POST['alipay_hk_email'] ?? ''),
                    'alipay_hk_phone' => trim($_POST['alipay_hk_phone'] ?? ''),
                    'paypal_email' => trim($_POST['paypal_email'] ?? ''),
                    'fps_id' => trim($_POST['fps_id'] ?? ''),
                    'fps_name' => trim($_POST['fps_name'] ?? '')
                ]);
                
                $updateStmt = $mysqli->prepare("UPDATE Client SET payment_method = ? WHERE clientid = ?");
                $updateStmt->bind_param("si", $paymentData, $clientId);
                if ($updateStmt->execute()) {
                    $success = 'Payment method saved successfully!';
                } else {
                    $error = 'Failed to save payment method. Please try again.';
                }
            }
        }
    }
}

// Fetch client details
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address, floor_plan, budget, payment_method FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

// Format phone number for display
$phoneDisplay = '';
if (!empty($clientData['ctel'])) {
    $phoneDisplay = (string)$clientData['ctel'];
}

// Format floor plan display
$floorPlanDisplay = $clientData['floor_plan'] ?? null;
$floorPlanFileName = '';
if ($floorPlanDisplay) {
    $floorPlanFileName = basename($floorPlanDisplay);
}

// Format budget display
$budgetDisplay = $clientData['budget'] ?? 0;

// Parse payment method data
$paymentMethodData = [];
if (!empty($clientData['payment_method'])) {
    $paymentMethodData = json_decode($clientData['payment_method'], true) ?? [];
}
$selectedPaymentMethod = $paymentMethodData['method'] ?? 'alipay_hk';
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
            max-width: 900px;
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
            margin-bottom: 2rem;
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
        /* Floor plan upload styles */
        .floorplan-upload-area {
            border: 2px dashed #3498db;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f0f7ff;
        }
        .floorplan-upload-area:hover {
            border-color: #2980b9;
            background-color: #e8f4ff;
        }
        .floorplan-upload-area .upload-icon {
            font-size: 2.5rem;
            color: #3498db;
            margin-bottom: 0.5rem;
        }
        .floorplan-upload-area p {
            margin: 0.25rem 0;
            color: #2c3e50;
        }
        .floorplan-upload-area .text-muted {
            font-size: 0.9rem;
        }
        .floorplan-preview-container {
            margin-top: 1rem;
            display: none;
        }
        .floorplan-preview-container.show {
            display: block;
        }
        .floorplan-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            object-fit: contain;
        }
        .floorplan-file-info {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 1rem;
        }
        .floorplan-file-info .file-icon {
            font-size: 2rem;
            color: #e74c3c;
            margin-right: 1rem;
        }
        .floorplan-file-info .file-details {
            flex: 1;
        }
        .floorplan-file-info .file-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        .floorplan-file-info .file-size {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        .remove-file-btn {
            background: none;
            border: none;
            color: #e74c3c;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.5rem;
        }
        .remove-file-btn:hover {
            color: #c0392b;
        }
        .file-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }
        .current-floorplan {
            background: #e8f8f0;
            border: 1px solid #27ae60;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .current-floorplan .file-icon {
            font-size: 1.5rem;
            color: #27ae60;
            margin-right: 0.5rem;
        }
        .current-floorplan a {
            color: #27ae60;
            text-decoration: none;
            font-weight: 600;
        }
        .current-floorplan a:hover {
            text-decoration: underline;
        }
        /* Payment method styles */
        .payment-methods {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .payment-option {
            flex: 1;
            min-width: 150px;
        }
        .payment-option input[type="radio"] {
            display: none;
        }
        .payment-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 0;
        }
        .payment-option input[type="radio"]:checked + .payment-label {
            border-color: #3498db;
            background-color: #e3f2fd;
            color: #3498db;
        }
        .payment-label i {
            font-size: 1.5rem;
        }
        .payment-form {
            background: #f0f7ff;
            border-left: 4px solid #3498db;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .payment-form-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .payment-form .form-label {
            font-weight: 600;
            color: #2c3e50;
        }
        .payment-form .form-control {
            border-radius: 6px;
        }
        .current-payment-method {
            background: #e8f5e9;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .current-payment-method .payment-method-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .current-payment-method .payment-method-details {
            flex: 1;
        }
        .current-payment-method .payment-method-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        .current-payment-method .payment-method-value {
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>
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
                <!-- Personal Information Section -->
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
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-edit" onclick="toggleEditMode('profile')">
                            <i class="fas fa-edit me-2"></i>Edit Profile
                        </button>
                    </div>
                </div>

                <!-- Floor Plan Section -->
                <div class="profile-section">
                    <h3 class="section-title"><i class="fas fa-file-pdf me-2"></i>Floor Plan</h3>
                    <div class="profile-info-card">
                        <?php if ($floorPlanDisplay): ?>
                            <div class="current-floorplan">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-file-pdf file-icon"></i>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($floorPlanFileName) ?></strong>
                                        <br>
                                        <small class="text-muted">Current floor plan on file</small>
                                    </div>
                                    <a href="../<?= htmlspecialchars($floorPlanDisplay) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-download me-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-3"><i class="fas fa-info-circle me-2"></i>No floor plan uploaded yet.</p>
                        <?php endif; ?>
                        <button type="button" class="btn btn-edit" onclick="toggleEditMode('floorplan')">
                            <i class="fas fa-upload me-2"></i><?= $floorPlanDisplay ? 'Update' : 'Upload' ?> Floor Plan
                        </button>
                    </div>
                </div>

                <!-- Budget Section -->
                <div class="profile-section">
                    <h3 class="section-title"><i class="fas fa-money-bill-wave me-2"></i>Budget</h3>
                    <div class="profile-info-card">
                        <div class="info-row">
                            <div class="info-icon"><i class="fas fa-dollar-sign"></i></div>
                            <div class="info-label">Default budget:</div>
                            <div class="info-value">
                                <?= $budgetDisplay > 0 ? 'HK$' . number_format($budgetDisplay) : 'Not set' ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-edit" onclick="toggleEditMode('budget')">
                            <i class="fas fa-edit me-2"></i>Set Budget
                        </button>
                    </div>
                </div>

                <!-- Payment Method Section -->
                <div class="profile-section">
                    <h3 class="section-title"><i class="fas fa-credit-card me-2"></i>Payment Method</h3>
                    <div class="profile-info-card">
                        <?php if (!empty($paymentMethodData) && !empty($paymentMethodData['method'])): ?>
                            <div class="current-payment-method">
                                <div class="payment-method-info">
                                    <div class="payment-method-details">
                                        <div class="payment-method-label">
                                            <?php 
                                                $methodLabels = [
                                                    'alipay_hk' => 'AlipayHK',
                                                    'paypal' => 'PayPal',
                                                    'fps' => 'FPS (Faster Payment System)'
                                                ];
                                                echo htmlspecialchars($methodLabels[$paymentMethodData['method']] ?? 'Unknown');
                                            ?>
                                        </div>
                                        <div class="payment-method-value">
                                            <?php 
                                                if ($paymentMethodData['method'] === 'alipay_hk') {
                                                    echo 'Email: ' . htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '');
                                                } elseif ($paymentMethodData['method'] === 'paypal') {
                                                    echo 'Email: ' . htmlspecialchars($paymentMethodData['paypal_email'] ?? '');
                                                } elseif ($paymentMethodData['method'] === 'fps') {
                                                    echo 'ID: ' . htmlspecialchars($paymentMethodData['fps_id'] ?? '');
                                                }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-3"><i class="fas fa-info-circle me-2"></i>No payment method set yet.</p>
                        <?php endif; ?>
                        <button type="button" class="btn btn-edit" onclick="toggleEditMode('payment')">
                            <i class="fas fa-edit me-2"></i><?= !empty($paymentMethodData) ? 'Update' : 'Add' ?> Payment Method
                        </button>
                    </div>
                </div>
            </div>

            <!-- Edit Mode -->
            <div id="editMode" style="display: none;">
                <!-- Edit Profile Form -->
                <div id="editProfileForm" style="display: none;">
                    <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="update_profile">
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
                            <button type="button" class="btn btn-cancel btn-lg px-4" onclick="toggleEditMode('profile')">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-success btn-lg px-4">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Upload Floor Plan Form -->
                <div id="editFloorPlanForm" style="display: none;">
                    <form method="post" enctype="multipart/form-data" class="edit-form">
                        <input type="hidden" name="action" value="update_floor_plan">
                        <div class="profile-section">
                            <h3 class="section-title"><i class="fas fa-file-upload me-2"></i>Floor Plan</h3>
                            <p class="text-muted mb-3">The floor plan has to be correct and accurate, Otherwise designs might not be suitable for your space.</p>
                            
                            <label for="floorplanUpload" class="file-input-label" id="uploadLabel">
                                <div class="floorplan-upload-area" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-file-upload"></i>
                                    </div>
                                    <p>Click to upload or drag and drop</p>
                                    <p class="text-muted small">PDF, JPG, PNG up to 10MB</p>
                                </div>
                            </label>
                            <input type="file" id="floorplanUpload" name="floorplan" class="file-input" accept=".pdf,.jpg,.jpeg,.png" required>
                            
                            <!-- Preview container for images -->
                            <div class="floorplan-preview-container" id="imagePreviewContainer">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-2"><i class="fas fa-check-circle text-success me-1"></i> File selected:</p>
                                        <img id="floorplanPreview" class="floorplan-preview" src="" alt="Floor plan preview">
                                    </div>
                                    <button type="button" class="remove-file-btn" id="removeFileBtn" title="Remove file">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                                <div class="floorplan-file-info mt-2">
                                    <div class="file-details">
                                        <div class="file-name" id="fileName"></div>
                                        <div class="file-size" id="fileSize"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Preview container for PDF files -->
                            <div class="floorplan-preview-container" id="pdfPreviewContainer">
                                <div class="floorplan-file-info">
                                    <div class="file-icon">
                                        <i class="fas fa-file-pdf"></i>
                                    </div>
                                    <div class="file-details">
                                        <div class="file-name" id="pdfFileName"></div>
                                        <div class="file-size" id="pdfFileSize"></div>
                                    </div>
                                    <button type="button" class="remove-file-btn" id="removePdfBtn" title="Remove file">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-3 justify-content-center mt-4">
                            <button type="button" class="btn btn-cancel btn-lg px-4" onclick="toggleEditMode('floorplan')">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-success btn-lg px-4">
                                <i class="fas fa-save me-2"></i>Confirm
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Edit Budget Form -->
                <div id="editBudgetForm" style="display: none;">
                    <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="update_budget">
                        <div class="profile-section">
                            <h3 class="section-title"><i class="fas fa-money-bill-wave me-2"></i>Set Budget</h3>
                            
                            <div class="mb-3">
                                <label for="budget" class="form-label"><i class="fas fa-dollar-sign me-2"></i>Budget (HK$) *</label>
                                <input type="number" class="form-control form-control-lg" id="budget" name="budget" step="1000"
                                       value="<?= $budgetDisplay ?>" min="1" placeholder="Enter your budget" required>
                                <small class="text-muted">This budget will be used as the default when placing orders.</small>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-3 justify-content-center mt-4">
                            <button type="button" class="btn btn-cancel btn-lg px-4" onclick="toggleEditMode('budget')">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-success btn-lg px-4">
                                <i class="fas fa-save me-2"></i>Save Budget
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Edit Payment Method Form -->
                <div id="editPaymentMethodForm" style="display: none;">
                    <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="update_payment_method">
                        <div class="profile-section">
                            <h3 class="section-title"><i class="fas fa-credit-card me-2"></i>Payment Method</h3>
                            
                            <div class="mb-3">
                                <label class="form-label">Select Payment Method <span style="color: #e74c3c;">*</span></label>
                                <div class="payment-methods">
                                    <div class="payment-option">
                                        <input type="radio" id="alipayHK" name="payment_method" value="alipay_hk" <?= $selectedPaymentMethod === 'alipay_hk' ? 'checked' : '' ?>>
                                        <label for="alipayHK" class="payment-label">
                                            <i class="fab fa-alipay"></i>
                                            <span>AlipayHK</span>
                                        </label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" id="paypal" name="payment_method" value="paypal" <?= $selectedPaymentMethod === 'paypal' ? 'checked' : '' ?>>
                                        <label for="paypal" class="payment-label">
                                            <i class="fab fa-paypal"></i>
                                            <span>PayPal</span>
                                        </label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" id="fps" name="payment_method" value="fps" <?= $selectedPaymentMethod === 'fps' ? 'checked' : '' ?>>
                                        <label for="fps" class="payment-label">
                                            <i class="fas fa-mobile-alt"></i>
                                            <span>FPS</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- AlipayHK Form -->
                            <div class="payment-form" id="alipayHKForm">
                                <h4 class="payment-form-title">AlipayHK Information</h4>
                                <div class="mb-3">
                                    <label for="alipayHKEmail" class="form-label">AlipayHK Account Email <span style="color: #e74c3c;">*</span></label>
                                    <input type="email" class="form-control" id="alipayHKEmail" name="alipay_hk_email" 
                                           value="<?= htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '') ?>" 
                                           placeholder="your.email@example.com">
                                </div>
                                <div class="mb-3">
                                    <label for="alipayHKPhone" class="form-label">AlipayHK Phone Number <span style="color: #e74c3c;">*</span></label>
                                    <input type="tel" class="form-control" id="alipayHKPhone" name="alipay_hk_phone" 
                                           value="<?= htmlspecialchars($paymentMethodData['alipay_hk_phone'] ?? '') ?>" 
                                           placeholder="+852 XXXX XXXX">
                                </div>
                            </div>

                            <!-- PayPal Form -->
                            <div class="payment-form" id="paypalForm" style="display: none;">
                                <h4 class="payment-form-title">PayPal Information</h4>
                                <div class="mb-3">
                                    <label for="paypalEmail" class="form-label">PayPal Email <span style="color: #e74c3c;">*</span></label>
                                    <input type="email" class="form-control" id="paypalEmail" name="paypal_email" 
                                           value="<?= htmlspecialchars($paymentMethodData['paypal_email'] ?? '') ?>" 
                                           placeholder="your.email@example.com">
                                </div>
                            </div>

                            <!-- FPS Form -->
                            <div class="payment-form" id="fpsForm" style="display: none;">
                                <h4 class="payment-form-title">FPS Information</h4>
                                <div class="mb-3">
                                    <label for="fpsId" class="form-label">FPS ID <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" class="form-control" id="fpsId" name="fps_id" 
                                           value="<?= htmlspecialchars($paymentMethodData['fps_id'] ?? '') ?>" 
                                           placeholder="Your FPS ID (Phone/Email/ID Number)">
                                </div>
                                <div class="mb-3">
                                    <label for="fpsName" class="form-label">Account Holder Name <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" class="form-control" id="fpsName" name="fps_name" 
                                           value="<?= htmlspecialchars($paymentMethodData['fps_name'] ?? '') ?>" 
                                           placeholder="John Doe">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-3 justify-content-center mt-4">
                            <button type="button" class="btn btn-cancel btn-lg px-4" onclick="toggleEditMode('payment')">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-success btn-lg px-4">
                                <i class="fas fa-save me-2"></i>Save Payment Method
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleEditMode(mode) {
            const viewMode = document.getElementById('viewMode');
            const editMode = document.getElementById('editMode');
            const editProfileForm = document.getElementById('editProfileForm');
            const editFloorPlanForm = document.getElementById('editFloorPlanForm');
            const editBudgetForm = document.getElementById('editBudgetForm');
            const editPaymentMethodForm = document.getElementById('editPaymentMethodForm');
            
            if (viewMode.style.display === 'none') {
                // Close edit mode
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
                editProfileForm.style.display = 'none';
                editFloorPlanForm.style.display = 'none';
                editBudgetForm.style.display = 'none';
                editPaymentMethodForm.style.display = 'none';
            } else {
                // Open edit mode for specific section
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
                editProfileForm.style.display = mode === 'profile' ? 'block' : 'none';
                editFloorPlanForm.style.display = mode === 'floorplan' ? 'block' : 'none';
                editBudgetForm.style.display = mode === 'budget' ? 'block' : 'none';
                editPaymentMethodForm.style.display = mode === 'payment' ? 'block' : 'none';
            }
        }

        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        const uploadLabel = document.getElementById('uploadLabel');
        const fileInput = document.getElementById('floorplanUpload');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');
        const pdfPreviewContainer = document.getElementById('pdfPreviewContainer');
        const floorplanPreview = document.getElementById('floorplanPreview');
        const removeFileBtn = document.getElementById('removeFileBtn');
        const removePdfBtn = document.getElementById('removePdfBtn');

        if (uploadArea && fileInput) {
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '#2980b9';
                uploadArea.style.backgroundColor = '#e8f4ff';
            });
            
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.style.borderColor = '#3498db';
                uploadArea.style.backgroundColor = '#f0f7ff';
            });
            
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '#3498db';
                uploadArea.style.backgroundColor = '#f0f7ff';
                fileInput.files = e.dataTransfer.files;
                handleFileSelect();
            });

            fileInput.addEventListener('change', handleFileSelect);
        }

        function handleFileSelect() {
            const file = fileInput.files[0];
            if (!file) return;

            const ext = file.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png'].includes(ext);
            const isPdf = ext === 'pdf';

            if (isImage) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    floorplanPreview.src = e.target.result;
                    imagePreviewContainer.classList.add('show');
                    pdfPreviewContainer.classList.remove('show');
                    document.getElementById('fileName').textContent = file.name;
                    document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(2) + ' KB';
                };
                reader.readAsDataURL(file);
            } else if (isPdf) {
                imagePreviewContainer.classList.remove('show');
                pdfPreviewContainer.classList.add('show');
                document.getElementById('pdfFileName').textContent = file.name;
                document.getElementById('pdfFileSize').textContent = (file.size / 1024).toFixed(2) + ' KB';
            }
        }

        if (removeFileBtn) {
            removeFileBtn.addEventListener('click', (e) => {
                e.preventDefault();
                fileInput.value = '';
                imagePreviewContainer.classList.remove('show');
            });
        }

        if (removePdfBtn) {
            removePdfBtn.addEventListener('click', (e) => {
                e.preventDefault();
                fileInput.value = '';
                pdfPreviewContainer.classList.remove('show');
            });
        }

        // Payment method form switching
        document.addEventListener('DOMContentLoaded', function() {
            const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
            const alipayHKForm = document.getElementById('alipayHKForm');
            const paypalForm = document.getElementById('paypalForm');
            const fpsForm = document.getElementById('fpsForm');

            function updatePaymentForm() {
                const selectedMethod = document.querySelector('input[name="payment_method"]:checked').value;
                
                // Remove required from all payment fields
                document.getElementById('alipayHKEmail').removeAttribute('required');
                document.getElementById('alipayHKPhone').removeAttribute('required');
                document.getElementById('paypalEmail').removeAttribute('required');
                document.getElementById('fpsId').removeAttribute('required');
                document.getElementById('fpsName').removeAttribute('required');
                
                // Hide all payment forms
                alipayHKForm.style.display = 'none';
                paypalForm.style.display = 'none';
                fpsForm.style.display = 'none';
                
                // Show selected form and add required
                if (selectedMethod === 'alipay_hk') {
                    alipayHKForm.style.display = 'block';
                    document.getElementById('alipayHKEmail').setAttribute('required', 'required');
                    document.getElementById('alipayHKPhone').setAttribute('required', 'required');
                } else if (selectedMethod === 'paypal') {
                    paypalForm.style.display = 'block';
                    document.getElementById('paypalEmail').setAttribute('required', 'required');
                } else if (selectedMethod === 'fps') {
                    fpsForm.style.display = 'block';
                    document.getElementById('fpsId').setAttribute('required', 'required');
                    document.getElementById('fpsName').setAttribute('required', 'required');
                }
            }

            paymentRadios.forEach(radio => {
                radio.addEventListener('change', updatePaymentForm);
            });

            // Initialize on page load
            updatePaymentForm();
        });
    </script>
</body>
</html>
