<?php
// ==============================
// File: order.php (layout updated to match Order.html design)
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user'])) {
    $redirect = 'order.php' . (isset($_GET['designid']) ? ('?designid=' . urlencode((string)$_GET['designid'])) : '');
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$designid = isset($_GET['designid']) ? (int)$_GET['designid'] : 0;
if ($designid <= 0) { http_response_code(404); die('Invalid design.'); }

$ds = $mysqli->prepare("SELECT d.designid, d.expect_price, d.designName, dz.dname, d.tag FROM Design d JOIN Designer dz ON d.designerid = dz.designerid WHERE d.designid=?");
$ds->bind_param("i", $designid);
$ds->execute();
$design = $ds->get_result()->fetch_assoc();
if (!$design) { http_response_code(404); die('Design not found.'); }

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) { http_response_code(403); die('Invalid session.'); }

// Fetch client details (phone, address, floor plan, and budget) from the Client table
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address, floor_plan, budget FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use budget from profile, not from form input
    $budget = (int)($clientData['budget'] ?? $design['expect_price']);
    $requirements = trim($_POST['requirements'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $floorPlan = $clientData['floor_plan'] ?? null;

    // 驗證必填字段
    if (empty($floorPlan)) {
        $error = 'Please upload a floor plan in your profile before placing an order.';
    } elseif ($budget <= 0) {
        $error = 'Budget must be greater than 0.';
    } elseif (empty($paymentMethod)) {
        $error = 'Payment method is required.';
    }

    // 驗證支付方式相關字段
    if (!$error) {
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
        }
    }

    if (!$error) {
        $stmt = $mysqli->prepare("INSERT INTO `Order` (odate, clientid, Requirements, designid, ostatus) VALUES (NOW(), ?, ?, ?, 'Designing')");
        $stmt->bind_param("isi", $clientId, $requirements, $designid);
        if ($stmt->execute()) {
            $orderId = $stmt->insert_id;
            $success = 'Order created successfully. Order ID: ' . $orderId;
            // Redirect to order detail page after 1 second
            header('Refresh: 1; url=order_detail.php?orderid=' . $orderId);
        } else {
            $error = 'Failed to create order: ' . $stmt->error;
        }
    }
}

$rawTags = (string)($design['tag'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $rawTags)));
$designImgSrc = '../design_image.php?id=' . (int)$design['designid'];

// Format phone number for display
$phoneDisplay = '—';
if (!empty($clientData['ctel'])) {
    $phoneDisplay = (string)$clientData['ctel'];
}

// Format budget display
$budgetDisplay = $clientData['budget'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Floor plan preview styles */
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
        .floorplan-upload-area.has-file {
            border-color: #27ae60;
            background-color: #e8f8f0;
        }
        .file-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="../design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link active" href="../design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="../material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted " href="../client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
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
        <div class="order-container">
            <div class="mb-3">
                <button type="button" class="btn btn-light" onclick="history.back()" aria-label="Back">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </button>
            </div>
            <h1 class="text-center mb-4">Complete Your Order</h1>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-8">
                        <!-- Design Information Section -->
                        <div class="order-section">
                            <h3 class="section-title">Design Information</h3>
                            <div class="d-flex mb-4">
                                <div class="design-preview me-3" style="max-width: 200px;">
                                    <img src="<?= htmlspecialchars($designImgSrc) ?>" class="img-fluid" alt="Selected Design">
                                </div>
                                <div>
                                    <p class="text-muted mb-1">Designer: <?= htmlspecialchars($design['dname']) ?></p>
                                    <div class="tags mb-2">
                                        <?php if (!empty($tags)): ?>
                                            <?php foreach ($tags as $tg): ?>
                                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($tg) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information Section -->
                        <div class="order-section">
                            <h3 class="section-title">Customer Information</h3>
                            <div class="customer-info-card">
                                <div class="info-row">
                                    <div class="info-label">Name:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['cname'] ?? '—') ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Email:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['cemail'] ?? '—') ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Phone:</div>
                                    <div class="info-value"><?= htmlspecialchars($phoneDisplay) ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Address:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['address'] ?? '—') ?></div>
                                </div>
                            </div>
                            <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> To update your information, please visit your account settings.</p>
                        </div>

                        <!-- Floor Plan Section (Display Only) -->
                        <div class="order-section">
                            <h3 class="section-title">Floor Plan</h3>
                            <?php if (!empty($clientData['floor_plan'])): ?>
                                <div style="background: #e8f8f0; border: 1px solid #27ae60; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-pdf" style="font-size: 1.5rem; color: #27ae60; margin-right: 0.5rem;"></i>
                                            <div>
                                                <strong><?= htmlspecialchars(basename($clientData['floor_plan'])) ?></strong>
                                                <br>
                                                <small class="text-muted">Floor plan on file</small>
                                            </div>
                                        </div>
                                        <a href="../<?= htmlspecialchars($clientData['floor_plan']) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                            <i class="fas fa-download me-1"></i>View
                                        </a>
                                    </div>
                                </div>
                                <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> To update your floor plan, please visit your profile.</p>
                            <?php else: ?>
                                <div class="alert alert-warning" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>No floor plan uploaded!</strong> Please upload a floor plan in your <a href="profile.php">profile</a> before placing an order.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Design Requirements Section -->
                        <div class="order-section">
                            <h3 class="section-title">Design Requirements</h3>
                            <div class="mb-3">
                                <label for="requirements" class="form-label">Special Requirements (Optional)</label>
                                <textarea class="form-control" id="requirements" name="requirements" rows="4" placeholder="Any specific requirements, preferences, or notes for the designer..." maxlength="255"></textarea>
                            </div>
                        </div>

                        <!-- Payment Method Section -->
                        <div class="order-section">
                            <h3 class="section-title">Payment Method</h3>
                            <div class="payment-methods">
                                <div class="payment-option">
                                    <input type="radio" id="alipayHK" name="payment_method" value="alipay_hk" checked>
                                    <label for="alipayHK" class="payment-label">
                                        <i class="fab fa-alipay"></i>
                                        <span>AlipayHK</span>
                                    </label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" id="paypal" name="payment_method" value="paypal">
                                    <label for="paypal" class="payment-label">
                                        <i class="fab fa-paypal"></i>
                                        <span>PayPal</span>
                                    </label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" id="fps" name="payment_method" value="fps">
                                    <label for="fps" class="payment-label">
                                        <i class="fas fa-mobile-alt"></i>
                                        <span>FPS</span>
                                    </label>
                                </div>
                            </div>

                            <!-- AlipayHK Form -->
                            <div class="payment-form" id="alipayHKForm">
                                <h4 class="payment-form-title">AlipayHK Information</h4>
                                <div class="mb-3">
                                    <label for="alipayHKEmail" class="form-label">AlipayHK Account Email <span style="color: #e74c3c;">*</span></label>
                                    <input type="email" class="form-control" id="alipayHKEmail" name="alipay_hk_email" placeholder="your.email@example.com">
                                </div>
                                <div class="mb-3">
                                    <label for="alipayHKPhone" class="form-label">AlipayHK Phone Number <span style="color: #e74c3c;">*</span></label>
                                    <input type="tel" class="form-control" id="alipayHKPhone" name="alipay_hk_phone" placeholder="+852 XXXX XXXX">
                                </div>
                            </div>

                            <!-- PayPal Form -->
                            <div class="payment-form" id="paypalForm" style="display: none;">
                                <h4 class="payment-form-title">PayPal Information</h4>
                                <div class="mb-3">
                                    <label for="paypalEmail" class="form-label">PayPal Email <span style="color: #e74c3c;">*</span></label>
                                    <input type="email" class="form-control" id="paypalEmail" name="paypal_email" placeholder="your.email@example.com">
                                </div>
                            </div>

                            <!-- FPS Form -->
                            <div class="payment-form" id="fpsForm" style="display: none;">
                                <h4 class="payment-form-title">FPS Information</h4>
                                <div class="mb-3">
                                    <label for="fpsId" class="form-label">FPS ID <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" class="form-control" id="fpsId" name="fps_id" placeholder="Your FPS ID (Phone/Email/ID Number)">
                                </div>
                                <div class="mb-3">
                                    <label for="fpsName" class="form-label">Account Holder Name <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" class="form-control" id="fpsName" name="fps_name" placeholder="John Doe">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Order Summary -->
                    <div class="col-md-4">
                        <div class="order-summary">
                            <h3 class="section-title">Order Summary</h3>
                            <div class="summary-item">
                                <span>Design Service:</span>
                                <span>$<?= number_format((float)$design['expect_price'], 2) ?></span>
                            </div>
                            <div class="summary-item summary-total">
                                <span>Total:</span>
                                <span>$<?= number_format((float)$design['expect_price'], 2) ?></span>
                            </div>

                            <div class="mt-3 mb-3">
                                <label for="budget" class="form-label fw-bold">Budget</label>
                                <div class="form-control" style="background-color: #f8f9fa; border-color: #dee2e6; color: #495057; padding: 0.375rem 0.75rem; height: auto;">
                                    <strong>HK$<?= number_format($budgetDisplay > 0 ? $budgetDisplay : (int)$design['expect_price']) ?></strong>
                                </div>
                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i>Budget is set in your profile and cannot be changed during order placement.</small>
                                <!-- Hidden input to preserve budget value for form submission -->
                                <input type="hidden" name="budget" value="<?= $budgetDisplay > 0 ? $budgetDisplay : (int)$design['expect_price'] ?>">
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-success w-100 py-2">
                                    <i class="fas fa-check-circle me-2"></i>Complete Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
        });


    </script>
</body>
</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>
