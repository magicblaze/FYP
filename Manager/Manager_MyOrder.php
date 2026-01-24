<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=Manager/Manager_MyOrder.php');
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
    <!-- Navbar -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="Manager_MyOrder.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="Manager_introduct.php">Introduct</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_MyOrder.php">MyOrder</a></li>
                    <li class="nav-item"><a class="nav-link" href="Manager_Schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?php echo htmlspecialchars($user_name); ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

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
                    <button class="btn btn-primary" onclick="location.href='Manager_MyOrder_TotalOrder.php'">
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
