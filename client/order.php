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

 $ds = $mysqli->prepare("SELECT d.designid, d.expect_price, d.designName, d.designerid, dz.dname, d.tag FROM Design d JOIN Designer dz ON d.designerid = dz.designerid WHERE d.designid=?");
$ds->bind_param("i", $designid);
$ds->execute();
$design = $ds->get_result()->fetch_assoc();
if (!$design) { http_response_code(404); die('Design not found.'); }

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) { http_response_code(403); die('Invalid session.'); }

// Fetch client details (phone, address, floor plan, budget, and payment method) from the Client table
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address, floor_plan, budget, payment_method FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use budget from profile, not from form input
    $budget = (int)($clientData['budget'] ?? $design['expect_price']);
    $requirements = trim($_POST['requirements'] ?? '');
    
    // Parse payment method data from profile
    $paymentMethodData = [];
    if (!empty($clientData['payment_method'])) {
        $paymentMethodData = json_decode($clientData['payment_method'], true) ?? [];
    }

    // 驗證必填字段
    if (empty($clientData['floor_plan'])) {
        $error = 'Please upload a floor plan in your profile before placing an order.';
    } elseif ($budget <= 0) {
        $error = 'Budget must be greater than 0.';
    } elseif (empty($paymentMethodData) || empty($paymentMethodData['method'])) {
        $error = 'Please set a payment method in your profile before placing an order.';
    }

    if (!$error) {
        $stmt = $mysqli->prepare("INSERT INTO `Order` (odate, clientid, Requirements, designid, ostatus) VALUES (NOW(), ?, ?, ?, 'Waiting Confirm')");
        $stmt->bind_param("isi", $clientId, $requirements, $designid);
        if ($stmt->execute()) {
            $orderId = $stmt->insert_id;
            $success = 'Order created successfully. Order ID: ' . $orderId;
            // Send order confirmation message to designer via chat (create/find private room)
            try {
                $designerId = isset($design['designerid']) ? (int)$design['designerid'] : 0;
                if ($designerId > 0) {
                    // For each order, create or reuse an order-specific group room (client + designer + manager)
                    $roomId = 0;
                    $roomname = sprintf('order-%d', $orderId);
                    $chkRoom = $mysqli->prepare("SELECT ChatRoomid FROM ChatRoom WHERE roomname = ? LIMIT 1");
                    if ($chkRoom) {
                        $chkRoom->bind_param('s', $roomname);
                        $chkRoom->execute();
                        $rr = $chkRoom->get_result();
                        $existing = $rr ? $rr->fetch_assoc() : null;
                        $chkRoom->close();
                        if ($existing && !empty($existing['ChatRoomid'])) {
                            $roomId = (int)$existing['ChatRoomid'];
                        }
                    }
                    if (!$roomId) {
                        $insRoom = $mysqli->prepare("INSERT INTO ChatRoom (roomname,description,room_type,created_by_type,created_by_id) VALUES (?,?,?,?,?)");
                        if ($insRoom) {
                            $desc = 'Order room for order #' . $orderId;
                            // room_type must match DB enum (private|group). Use 'group' for order rooms.
                            $room_type = 'group';
                            $created_by_type = 'client';
                            $insRoom->bind_param('ssssi', $roomname, $desc, $room_type, $created_by_type, $clientId);
                            $insRoom->execute();
                            $roomId = $insRoom->insert_id;
                            $insRoom->close();
                            // insert client and designer members
                            $insM = $mysqli->prepare("INSERT INTO ChatRoomMember (ChatRoomid, member_type, memberid) VALUES (?,?,?)");
                            if ($insM) {
                                $mt1 = 'client'; $mid1 = $clientId; $insM->bind_param('isi', $roomId, $mt1, $mid1); $insM->execute();
                                $mt2 = 'designer'; $mid2 = $designerId; $insM->bind_param('isi', $roomId, $mt2, $mid2); $insM->execute();
                                $insM->close();
                            }
                            // attempt to add the designer's manager as a member. If designer has no manager, fallback to first Manager.
                            $mgrId = 0;
                            $mgrStmt = $mysqli->prepare('SELECT managerid FROM Designer WHERE designerid = ? LIMIT 1');
                            if ($mgrStmt) {
                                $mgrStmt->bind_param('i', $designerId);
                                $mgrStmt->execute();
                                $mgrRow = $mgrStmt->get_result()->fetch_assoc();
                                $mgrStmt->close();
                                if ($mgrRow && !empty($mgrRow['managerid'])) {
                                    $mgrId = (int)$mgrRow['managerid'];
                                }
                            }
                            if (empty($mgrId)) {
                                $mgrQ = $mysqli->prepare('SELECT managerid FROM Manager ORDER BY managerid ASC LIMIT 1');
                                if ($mgrQ) {
                                    $mgrQ->execute();
                                    $mgrR = $mgrQ->get_result();
                                    $mgr = $mgrR ? $mgrR->fetch_assoc() : null;
                                    $mgrQ->close();
                                    if ($mgr && !empty($mgr['managerid'])) {
                                        $mgrId = (int)$mgr['managerid'];
                                    }
                                }
                            }
                            if (!empty($mgrId)) {
                                $insMgr = $mysqli->prepare("INSERT INTO ChatRoomMember (ChatRoomid, member_type, memberid) VALUES (?,?,?)");
                                if ($insMgr) {
                                    $mtm = 'manager'; $insMgr->bind_param('isi', $roomId, $mtm, $mgrId); $insMgr->execute(); $insMgr->close();
                                }
                            }
                        }
                    }

                    // Insert message announcing the order
                    if (!empty($roomId)) {
                        // Persist only the order id in `content` and set message_type = 'order'.
                        $orderContent = (string)$orderId;
                        $insMsg = $mysqli->prepare("INSERT INTO Message (sender_type, sender_id, content, message_type, ChatRoomid) VALUES (?,?,?,?,?)");
                        if ($insMsg) {
                            $stype = 'client'; $sId = $clientId; $mtype = 'order';
                            // types: sender_type (s), sender_id (i), content (s), message_type (s), ChatRoomid (i)
                            $insMsg->bind_param('sissi', $stype, $sId, $orderContent, $mtype, $roomId);
                            $insMsg->execute();
                            $msgId = $insMsg->insert_id;
                            $insMsg->close();
                            if (!empty($msgId)) {
                                // create MessageRead rows: mark sender as read, others unread
                                $membersQ = $mysqli->prepare('SELECT ChatRoomMemberid, member_type, memberid FROM ChatRoomMember WHERE ChatRoomid = ?');
                                if ($membersQ) {
                                    $membersQ->bind_param('i', $roomId);
                                    $membersQ->execute();
                                    $mres = $membersQ->get_result();
                                    $insRead = $mysqli->prepare('INSERT INTO MessageRead (messageid, ChatRoomMemberid, is_read, read_at) VALUES (?,?,?,?)');
                                    while ($mr = $mres->fetch_assoc()) {
                                        $crmId = (int)$mr['ChatRoomMemberid'];
                                        if ($mr['member_type'] === 'client' && (int)$mr['memberid'] === $clientId) {
                                            $isr = 1; $rtime = date('Y-m-d H:i:s');
                                        } else {
                                            $isr = 0; $rtime = null;
                                        }
                                        if ($insRead) { $insRead->bind_param('iiis', $msgId, $crmId, $isr, $rtime); $insRead->execute(); }
                                    }
                                    if ($insRead) $insRead->close();
                                    $membersQ->close();
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // non-fatal: order succeeded but chat notification failed
                error_log('[order.php] failed to send chat notification: ' . $e->getMessage());
            }
            // Redirect immediately to the order detail page to avoid timing issues
            header('Location: order_detail.php?orderid=' . $orderId);
            exit;
        } else {
            $error = 'Failed to create order: ' . $stmt->error;
        }
    }
}

$rawTags = (string)($design['tag'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $rawTags)));
$designImgSrc = '../design_image.php?id=' . (int)$design['designid'];
// Try to use the primary DesignImage (if present) and map to uploads path
try {
    $imgStmt = $mysqli->prepare("SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1");
    if ($imgStmt) {
        $imgStmt->bind_param('i', $designid);
        $imgStmt->execute();
        $imgRow = $imgStmt->get_result()->fetch_assoc();
        if ($imgRow && !empty($imgRow['image_filename'])) {
            $designImgSrc = '../uploads/designs/' . ltrim($imgRow['image_filename'], '/');
        }
        $imgStmt->close();
    }
} catch (Throwable $e) {
    // keep fallback to design_image.php if any error occurs
}

// Format phone number for display
$phoneDisplay = '—';
if (!empty($clientData['ctel'])) {
    $phoneDisplay = (string)$clientData['ctel'];
}

// Format budget display
$budgetDisplay = $clientData['budget'] ?? 0;

// Parse payment method data
$paymentMethodData = [];
if (!empty($clientData['payment_method'])) {
    $paymentMethodData = json_decode($clientData['payment_method'], true) ?? [];
}
$selectedPaymentMethod = $paymentMethodData['method'] ?? null;
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
        /* Payment method display styles */
        .payment-method-display {
            background: #e8f5e9;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .payment-method-display .payment-method-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        .payment-method-display .payment-method-value {
            color: #666;
            font-size: 0.95rem;
        }
        .payment-method-display .edit-link {
            display: inline-block;
            margin-top: 0.5rem;
        }
        .payment-method-display .edit-link a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .payment-method-display .edit-link a:hover {
            text-decoration: underline;
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

            <form id="orderForm" method="post" enctype="multipart/form-data">
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

                        <!-- Payment Method Section (Display Only) -->
                        <div class="order-section">
                            <h3 class="section-title">Payment Method</h3>
                            <?php if (!empty($selectedPaymentMethod)): ?>
                                <div class="payment-method-display">
                                    <div class="payment-method-label">
                                        <i class="fas fa-credit-card me-2"></i>
                                        <?php 
                                            $methodLabels = [
                                                'alipay_hk' => 'AlipayHK',
                                                'paypal' => 'PayPal',
                                                'fps' => 'FPS (Faster Payment System)'
                                            ];
                                            echo htmlspecialchars($methodLabels[$selectedPaymentMethod] ?? 'Unknown');
                                        ?>
                                    </div>
                                    <div class="payment-method-value">
                                        <?php 
                                            if ($selectedPaymentMethod === 'alipay_hk') {
                                                echo 'Email: ' . htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '');
                                            } elseif ($selectedPaymentMethod === 'paypal') {
                                                echo 'Email: ' . htmlspecialchars($paymentMethodData['paypal_email'] ?? '');
                                            } elseif ($selectedPaymentMethod === 'fps') {
                                                echo 'ID: ' . htmlspecialchars($paymentMethodData['fps_id'] ?? '');
                                            }
                                        ?>
                                    </div>
                                    <div class="payment-method-display edit-link">
                                        <a href="profile.php">
                                            <i class="fas fa-edit me-1"></i>Update Payment Method
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>No payment method set!</strong> Please set a payment method in your <a href="profile.php">profile</a> before placing an order.
                                </div>
                            <?php endif; ?>
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
                                    <i class="fas fa-check-circle me-2"></i>Place Order
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
        <!-- Terms of Use Modal -->
        <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="termsModalLabel">Terms of Use</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <iframe src="../terms.php" title="Terms of Use" style="border:0;width:100%;height:60vh;display:block;" loading="lazy"></iframe>
                        <div class="p-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="" id="termsAgree">
                                <label class="form-check-label" for="termsAgree">
                                    I have read and agree to the Terms of Use
                                </label>
                            </div>
                            <p class="small text-muted mt-2">You can also open the full terms in a new tab: <a href="../terms.php" target="_blank">Terms of Use</a>.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="termsAcceptBtn" disabled>Agree & Place Order</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
                const orderForm = document.getElementById('orderForm');
                if (!orderForm) return;
                const termsModalEl = document.getElementById('termsModal');
                const termsModal = termsModalEl ? new bootstrap.Modal(termsModalEl) : null;
                const agreeCheckbox = document.getElementById('termsAgree');
                const agreeBtn = document.getElementById('termsAcceptBtn');

                orderForm.addEventListener('submit', function(e) {
                        if (!window.__termsAccepted) {
                                e.preventDefault();
                                if (termsModal) termsModal.show();
                        }
                });

                if (agreeCheckbox && agreeBtn) {
                        agreeCheckbox.addEventListener('change', function() {
                                agreeBtn.disabled = !this.checked;
                        });
                        agreeBtn.addEventListener('click', function() {
                                window.__termsAccepted = true;
                                if (termsModal) termsModal.hide();
                                orderForm.submit();
                        });
                }
        });
        </script>
</body>
</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>
