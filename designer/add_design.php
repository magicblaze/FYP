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
    $price = intval($_POST['price'] ?? 0);
    $tag = trim($_POST['tag'] ?? '');
    
    // Handle design image upload
    $designImages = $_FILES['design'] ?? null;
    $designImageName = null;
    $uploadDir = __DIR__ . '/../uploads/designs/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Process design image
    if ($designImages && $designImages['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($designImages['name'], PATHINFO_EXTENSION);
        $designImageName = uniqid('design_', true) . '.' . $ext;
        if (move_uploaded_file($designImages['tmp_name'], $uploadDir . $designImageName)) {
            // Image uploaded successfully
        } else {
            $designImageName = null;
        }
    }
    
    if ($price > 0 && $tag && $designImageName) {
        $stmt = $mysqli->prepare("INSERT INTO Design (design, price, tag, likes, designerid) VALUES (?, ?, ?, 0, ?)");
        if (!$stmt) {
            $error = 'Database error: ' . $mysqli->error;
        } else {
            $stmt->bind_param("sisi", $designImageName, $price, $tag, $designerId);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $error = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        if ($price <= 0) {
            $error = 'Please enter a valid price.';
        } elseif (!$tag) {
            $error = 'Please enter design tags.';
        } elseif (!$designImageName) {
            $error = 'Please upload a design image.';
        } else {
            $error = 'Please fill in all required fields.';
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
                                <strong>Success!</strong> Design added successfully.
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
                                        <label class="form-label"><i class="fas fa-image"></i> Design Image *</label>
                                        <input type="file" name="design" class="form-control" accept="image/*" required>
                                        <small class="text-muted">Upload a high-quality design image (JPG, PNG, GIF)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-dollar-sign"></i> Price (HK$) *</label>
                                        <input type="number" name="price" class="form-control" min="1" required>
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
</body>
</html>
