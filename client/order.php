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

$ds = $mysqli->prepare("SELECT d.designid, d.price, dz.dname, d.tag FROM Design d JOIN Designer dz ON d.designerid = dz.designerid WHERE d.designid=?");
$ds->bind_param("i", $designid);
$ds->execute();
$design = $ds->get_result()->fetch_assoc();
if (!$design) { http_response_code(404); die('Design not found.'); }

$clientId = (int)($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) { http_response_code(403); die('Invalid session.'); }

// Fetch client details (phone and address) from the Client table
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $budget = (int)($_POST['budget'] ?? $design['price']);
    $requirements = trim($_POST['requirements'] ?? '');

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
                    $dir = __DIR__ . '/uploads/floor_plan';
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $newName = 'fp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $dir . '/' . $newName;
                    if (move_uploaded_file($_FILES['floorplan']['tmp_name'], $dest)) {
                        $uploadPath = 'uploads/floor_plan/' . $newName;
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
    }
    if (!$error) {
        $stmt = $mysqli->prepare("INSERT INTO `Order` (odate, clientid, budget, Floor_Plan, Requirements, designid, ostatus) VALUES (NOW(), ?, ?, ?, ?, ?, 'Designing')");
        $stmt->bind_param("iissi", $clientId, $budget, $uploadPath, $requirements, $designid);
        if ($stmt->execute()) {
            $success = 'Order created successfully. Order ID: ' . $stmt->insert_id;
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

                        <!-- Upload Floor Plan Section -->
                        <div class="order-section">
                            <h3 class="section-title">Upload Floor Plan</h3>
                            <p class="text-muted mb-3">Upload your floor plan to help us customize the design for your space</p>
                            <label for="floorplanUpload" class="file-input-label" id="uploadLabel">
                                <div class="floorplan-upload-area" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-file-upload"></i>
                                    </div>
                                    <p>Click to upload</p>
                                    <p class="text-muted small">PDF, JPG, PNG up to 10MB</p>
                                </div>
                            </label>
                            <input type="file" id="floorplanUpload" name="floorplan" class="file-input" accept=".pdf,.jpg,.jpeg,.png">
                            
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
                                    <label for="alipayHKEmail" class="form-label">AlipayHK Account Email</label>
                                    <input type="email" class="form-control" id="alipayHKEmail" name="alipay_hk_email" placeholder="your.email@example.com">
                                </div>
                                <div class="mb-3">
                                    <label for="alipayHKPhone" class="form-label">AlipayHK Phone Number</label>
                                    <input type="tel" class="form-control" id="alipayHKPhone" name="alipay_hk_phone" placeholder="+852 XXXX XXXX">
                                </div>
                            </div>

                            <!-- PayPal Form -->
                            <div class="payment-form" id="paypalForm" style="display: none;">
                                <h4 class="payment-form-title">PayPal Information</h4>
                                <div class="mb-3">
                                    <label for="paypalEmail" class="form-label">PayPal Email</label>
                                    <input type="email" class="form-control" id="paypalEmail" name="paypal_email" placeholder="your.email@example.com">
                                </div>
                            </div>

                            <!-- FPS Form -->
                            <div class="payment-form" id="fpsForm" style="display: none;">
                                <h4 class="payment-form-title">FPS Information</h4>
                                <div class="mb-3">
                                    <label for="fpsId" class="form-label">FPS ID</label>
                                    <input type="text" class="form-control" id="fpsId" name="fps_id" placeholder="Your FPS ID (Phone/Email/ID Number)">
                                </div>
                                <div class="mb-3">
                                    <label for="fpsName" class="form-label">Account Holder Name</label>
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
                                <span>$<?= number_format((float)$design['price'], 2) ?></span>
                            </div>
                            <div class="summary-item summary-total">
                                <span>Total:</span>
                                <span>$<?= number_format((float)$design['price'], 2) ?></span>
                            </div>

                            <div class="mt-3 mb-3">
                                <label for="budget" class="form-label fw-bold">Budget</label>
                                <input class="form-control" type="number" id="budget" name="budget" min="0" value="<?= (int)$design['price'] ?>">
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
                alipayHKForm.style.display = 'none';
                paypalForm.style.display = 'none';
                fpsForm.style.display = 'none';
                if (selectedMethod === 'alipay_hk') {
                    alipayHKForm.style.display = 'block';
                } else if (selectedMethod === 'paypal') {
                    paypalForm.style.display = 'block';
                } else if (selectedMethod === 'fps') {
                    fpsForm.style.display = 'block';
                }
            }

            paymentRadios.forEach(radio => {
                radio.addEventListener('change', updatePaymentForm);
            });
        });

        // File upload functionality
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('floorplanUpload');
            const uploadArea = document.getElementById('uploadArea');
            const uploadLabel = document.getElementById('uploadLabel');
            const imagePreviewContainer = document.getElementById('imagePreviewContainer');
            const pdfPreviewContainer = document.getElementById('pdfPreviewContainer');
            const floorplanPreview = document.getElementById('floorplanPreview');
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            const pdfFileName = document.getElementById('pdfFileName');
            const pdfFileSize = document.getElementById('pdfFileSize');
            const removeFileBtn = document.getElementById('removeFileBtn');
            const removePdfBtn = document.getElementById('removePdfBtn');

            // Format file size
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // Handle file selection
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                
                if (file) {
                    const fileType = file.type;
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    
                    // Hide both preview containers first
                    imagePreviewContainer.classList.remove('show');
                    pdfPreviewContainer.classList.remove('show');
                    
                    // Check if it's an image
                    if (fileType.startsWith('image/') || ['jpg', 'jpeg', 'png'].includes(fileExtension)) {
                        // Show image preview
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            floorplanPreview.src = e.target.result;
                            fileName.textContent = file.name;
                            fileSize.textContent = formatFileSize(file.size);
                            imagePreviewContainer.classList.add('show');
                            uploadArea.classList.add('has-file');
                        };
                        reader.readAsDataURL(file);
                    } else if (fileType === 'application/pdf' || fileExtension === 'pdf') {
                        // Show PDF info
                        pdfFileName.textContent = file.name;
                        pdfFileSize.textContent = formatFileSize(file.size);
                        pdfPreviewContainer.classList.add('show');
                        uploadArea.classList.add('has-file');
                    }
                }
            });

            // Remove file function
            function removeFile() {
                fileInput.value = '';
                imagePreviewContainer.classList.remove('show');
                pdfPreviewContainer.classList.remove('show');
                uploadArea.classList.remove('has-file');
                floorplanPreview.src = '';
            }

            // Remove file button handlers
            removeFileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                removeFile();
            });

            removePdfBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                removeFile();
            });
        });
    </script>
</body>
</html>
