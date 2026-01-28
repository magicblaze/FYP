<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Get manager info
$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <!-- Page Title -->
        <div class="page-title">
            <i class="fas fa-list me-2"></i>Order Management
        </div>

        <!-- Main Content Card -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-tasks me-2"></i>Select an Order Category
                </h5>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="location.href='Order_Management.php'">
                        <i class="fas fa-list me-2"></i>Total Order
                    </button>
                    <button class="btn btn-warning" onclick="location.href='Manager_MyOrder_AwaitingConfirm.php'">
                        <i class="fas fa-hourglass-half me-2"></i>Awaiting Confirm
                    </button>
                    <button class="btn btn-success" onclick="location.href='Manager_MyOrder_Completed.php'">
                        <i class="fas fa-check-circle me-2"></i>Completed
                    </button>
                    <button class="btn btn-info" onclick="location.href='Manager_MyOrder_buyProduct.php'">
                        <i class="fas fa-pencil-alt me-2"></i>Help to Designing
                    </button>
                    <button class="btn btn-danger" onclick="location.href='Manager_MyOrder_Rejected.php'">
                        <i class="fas fa-times-circle me-2"></i>Rejected
                    </button>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-links a, .nav a');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>
</html>
