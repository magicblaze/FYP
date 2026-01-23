<?php
require_once __DIR__ . '/../config.php';
session_start();

// Check if designer is logged in
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

$designerId = $_SESSION['user']['designerid'];
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $design_name = trim($_POST['design_name'] ?? '');
    $expect_price = intval($_POST['expect_price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $tag = trim($_POST['tag'] ?? '');
    
    // Handle design image uploads (multiple files)
    $designImages = $_FILES['design'] ?? null;
    $uploadedImages = [];
    $uploadDir = __DIR__ . '/../uploads/designs/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Process design images
    if ($designImages && is_array($designImages['name'])) {
        $imageCount = count($designImages['name']);
        
        for ($i = 0; $i < $imageCount; $i++) {
            if ($designImages['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($designImages['name'][$i], PATHINFO_EXTENSION);
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array(strtolower($ext), $allowedExts)) {
                    $imageFileName = uniqid('design_', true) . '.' . $ext;
                    if (move_uploaded_file($designImages['tmp_name'][$i], $uploadDir . $imageFileName)) {
                        $uploadedImages[] = $imageFileName;
                    }
                }
            }
        }
    }
    
    // Validate form data
    if (!$design_name) {
        $error = 'Please enter a design name.';
    } elseif ($expect_price <= 0) {
        $error = 'Please enter a valid price.';
    } elseif (!$tag) {
        $error = 'Please enter design tags.';
    } elseif (empty($uploadedImages)) {
        $error = 'Please upload at least one design image.';
    } else {
        // Insert design into database
        $stmt = $mysqli->prepare("INSERT INTO Design (designName, design, expect_price, description, tag, likes, designerid) VALUES (?, ?, ?, ?, ?, 0, ?)");
        if (!$stmt) {
            $error = 'Database error: ' . $mysqli->error;
        } else {
            // Use the first image as the primary design image
            $primaryImage = $uploadedImages[0];
            $stmt->bind_param("ssissi", $design_name, $primaryImage, $expect_price, $description, $tag, $designerId);
            
            if ($stmt->execute()) {
                $designId = $stmt->insert_id;
                $stmt->close();
                
                // Insert all uploaded images into DesignImage table
                $imageStmt = $mysqli->prepare("INSERT INTO DesignImage (designid, image_filename, image_order) VALUES (?, ?, ?)");
                if ($imageStmt) {
                    $imageOrder = 1;
                    foreach ($uploadedImages as $imageName) {
                        $imageStmt->bind_param("isi", $designId, $imageName, $imageOrder);
                        $imageStmt->execute();
                        $imageOrder++;
                    }
                    $imageStmt->close();
                    $success = true;
                } else {
                    $error = 'Error saving image records: ' . $mysqli->error;
                }
            } else {
                $error = 'Database error: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Design</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <style>
        .form-section {
            background: #f8fafd;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(44, 62, 80, 0.07);
            padding: 1.25rem 1.5rem 1rem 1.5rem;
            margin-bottom: 1.25rem;
            transition: box-shadow 0.2s;
        }
        .form-section:hover {
            box-shadow: 0 4px 16px rgba(52, 152, 219, 0.13);
        }
        .form-section label {
            font-weight: 500;
        }
        .form-section input,
        .form-section textarea,
        .form-section select {
            background: #fff;
            border-radius: 8px;
        }
        
        .image-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .image-preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #f0f0f0;
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #3498db;
        }
        
        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-preview-item .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(231, 76, 60, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .image-preview-item:hover .remove-btn {
            opacity: 1;
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }
        
        .file-input-wrapper input[type="file"] {
            display: none;
        }
        
        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            border: 2px dashed #3498db;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .file-input-label:hover {
            background: #e8f4f8;
            border-color: #2980b9;
        }
        
        .file-input-label.dragover {
            background: #d4e9f7;
            border-color: #2980b9;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="designer_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="designer_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../supplier/schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'Designer') ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <div class="container mb-5">
        <div class="dashboard-header text-center mb-4" style="background: linear-gradient(135deg, #2c3e50, #3498db); color: white; padding: 2rem 0; border-radius: 0 0 15px 15px;">
            <h2 class="mb-1"><i class="fas fa-plus-circle me-2"></i>Add New Design</h2>
            <p class="mb-0">Upload your design to the portfolio</p>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Success!</strong> Design added successfully with multiple images.
                                <a href="designer_dashboard.php" class="alert-link">Back to Dashboard</a>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" id="addDesignForm">
                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-heading"></i> Design Name *</label>
                                        <input type="text" name="design_name" class="form-control" placeholder="e.g. Modern Living Room Design" required>
                                        <small class="text-muted">Give your design a descriptive name</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-images"></i> Design Images (Multiple) *</label>
                                        <div class="file-input-wrapper">
                                            <label class="file-input-label" id="fileInputLabel">
                                                <div>
                                                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                                    <strong>Click to upload or drag & drop</strong>
                                                    <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF, WebP (Max 10 images)</p>
                                                </div>
                                            </label>
                                            <input type="file" name="design[]" id="designInput" class="form-control" accept="image/*" multiple required>
                                        </div>
                                        <div class="image-preview-container" id="imagePreviewContainer"></div>
                                        <small class="text-muted d-block mt-2">Upload high-quality design images. The first image will be used as the main design thumbnail.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-dollar-sign"></i> Expected Price (HK$) *</label>
                                        <input type="number" name="expect_price" class="form-control" min="1" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-file-alt"></i> Design Description</label>
                                        <textarea name="description" class="form-control" rows="4" placeholder="Describe your design, style, materials, and any special features..."></textarea>
                                        <small class="text-muted">Optional: Provide details about your design to help clients understand your work</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-tags"></i> Design Tags *</label>
                                        <input type="text" name="tag" class="form-control" placeholder="e.g. modern, minimalist, luxury, residential" required>
                                        <small class="text-muted">Separate multiple tags with commas</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 d-flex gap-2 justify-content-end">
                                    <button type="submit" class="btn btn-success px-4"><i class="fas fa-check"></i> Add Design</button>
                                    <a href="designer_dashboard.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left"></i> Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        const designInput = document.getElementById('designInput');
        const fileInputLabel = document.getElementById('fileInputLabel');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');
        let selectedFiles = [];

        // Handle file selection via input
        designInput.addEventListener('change', function(e) {
            selectedFiles = Array.from(this.files);
            updatePreviews();
        });

        // Handle drag and drop
        fileInputLabel.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        fileInputLabel.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        fileInputLabel.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            selectedFiles = Array.from(files);
            
            // Update the file input
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designInput.files = dataTransfer.files;
            
            updatePreviews();
        });

        function updatePreviews() {
            imagePreviewContainer.innerHTML = '';
            
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'image-preview-item';
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview ${index + 1}">
                        <button type="button" class="remove-btn" onclick="removeImage(${index})" title="Remove image">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    imagePreviewContainer.appendChild(previewItem);
                };
                reader.readAsDataURL(file);
            });
        }

        function removeImage(index) {
            selectedFiles.splice(index, 1);
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designInput.files = dataTransfer.files;
            updatePreviews();
        }

        // Click on label to trigger file input
        fileInputLabel.addEventListener('click', function() {
            designInput.click();
        });
    </script>
</body>
</html>
                            