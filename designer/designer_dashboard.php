<?php
// ==============================
// File: designer/designer_dashboard.php
// Designer Dashboard - Manage Designs
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

$designerId = $_SESSION['user']['designerid'];
$designerName = $_SESSION['user']['name'];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Designer Dashboard - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        /* Adopt styles from client design_dashboard for consistent look */
        .search-section {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 0.75rem;
        }

        .search-section .form-control {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
        }

        .search-section .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.6rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            text-align: center;
            height: 100%;
        }

        .stat-number {
            font-size: 1.9rem;
            font-weight: 700;
            color: #3498db;
        }

        .design-table img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            padding: 0;
            line-height: 36px;
            border-radius: 50%;
            text-align: center;
        }

        .form-section {
            background: #f8fafd;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(44, 62, 80, 0.06);
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .modal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .image-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.8rem;
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
            border: 2px solid #e9eef2;
        }

        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-preview-item .remove-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(231, 76, 60, 0.95);
            color: white;
            border: none;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            opacity: 0;
            transition: opacity 0.18s;
        }

        .image-preview-item:hover .remove-btn {
            opacity: 1;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            border: 2px dashed #3498db;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .file-input-label:hover {
            background: #eaf4fb;
            border-color: #2980b9;
        }

        .file-input-label.dragover {
            background: #d4e9f7;
            border-color: #2980b9;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- Dashboard Content -->
    <main class="container-lg mt-4">
        <div class="dashboard-header text-left my-4">
            <h2 class="page-title">Hello, <?= htmlspecialchars($designerName) ?></h2>
        </div>
        <div class="stat-card d-flex justify-content-between gap-3 mb-4">
            <a href="OrderManager.php" class="btn btn-primary btn-lg w-100">
                Order Manager
            </a>
            <a href="DesignManager.php" class="btn btn-success btn-lg w-100">
                <i class="fas fa-plus me-2"></i>Design Manager
            </a>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <?php
    $CHAT_SHARE = null;
    include __DIR__ . '/../Public/chat_widget.php';
    ?>
</body>

</html>