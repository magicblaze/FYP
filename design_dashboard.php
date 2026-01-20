<?php
// ==============================
// File: design_dashboard.php (Enhanced with Filters)
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// --- 1. 處理過濾邏輯 ---
$search = trim($_GET['tag'] ?? '');
$min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (int)$_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (int)$_GET['max_price'] : 999999;
$designer_id = isset($_GET['designer_id']) && is_numeric($_GET['designer_id']) ? (int)$_GET['designer_id'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'recent';

$sql = "SELECT d.designid, d.price, d.likes, d.tag, dz.dname, dz.designerid
        FROM Design d
        JOIN Designer dz ON d.designerid = dz.designerid
        WHERE 1=1";
$params = [];
$types = "";

// 關鍵字搜尋
if (!empty($search)) {
    $sql .= " AND d.tag LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// 價格範圍過濾
if ($min_price > 0 || $max_price < 999999) {
    $sql .= " AND d.price BETWEEN ? AND ?";
    $params[] = $min_price;
    $params[] = $max_price;
    $types .= "ii";
}

// 設計師過濾
if (!empty($designer_id)) {
    $sql .= " AND d.designerid = ?";
    $params[] = $designer_id;
    $types .= "i";
}

// 排序
switch ($sort_by) {
    case 'price_low':
        $sql .= " ORDER BY d.price ASC";
        break;
    case 'price_high':
        $sql .= " ORDER BY d.price DESC";
        break;
    case 'likes':
        $sql .= " ORDER BY d.likes DESC";
        break;
    case 'recent':
    default:
        $sql .= " ORDER BY d.designid DESC";
        break;
}

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 獲取所有設計師用於過濾下拉菜單
$designer_sql = "SELECT designerid, dname FROM Designer ORDER BY dname ASC";
$designer_result = $mysqli->query($designer_sql);
if (!$designer_result) die('Query error: ' . $mysqli->error);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .search-section {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
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
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
        }
        .filter-panel {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }
        .filter-panel h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        .filter-group {
            margin-bottom: 1.5rem;
        }
        .filter-group label {
            font-weight: 500;
            color: #34495e;
            margin-bottom: 0.5rem;
            display: block;
        }
        .filter-group .form-control,
        .filter-group .form-select {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
        }
        .filter-group .form-control:focus,
        .filter-group .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        .price-inputs {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .price-inputs .form-control {
            flex: 1;
        }
        .price-separator {
            color: #7f8c8d;
            font-weight: 600;
        }
        .filter-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .filter-buttons button {
            flex: 1;
        }
        .btn-apply-filter {
            background: #3498db;
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-apply-filter:hover {
            background: #2980b9;
            color: white;
        }
        .btn-clear-filter {
            background: #ecf0f1;
            border: none;
            color: #7f8c8d;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-clear-filter:hover {
            background: #bdc3c7;
        }
        .results-info {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }
        .container-with-filter {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
        }
        @media (max-width: 768px) {
            .container-with-filter {
                grid-template-columns: 1fr;
            }
            .filter-panel {
                order: 2;
            }
            .main-content {
                order: 1;
            }
        }
    </style>
</head>
<body>
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link active" href="design_dashboard.php">Design</a></li>
                    <li class="nav-item"><a class="nav-link" href="material_dashboard.php">Material</a></li>
                    <li class="nav-item"><a class="nav-link" href="furniture_dashboard.php">Furniture</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <?php if (isset($_SESSION['user'])): ?>
                    <li class="nav-item me-2">
                        <a class="nav-link text-muted" href="client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="client/my_likes.php">My Likes</a></li>
                    <li class="nav-item"><a class="nav-link" href="client/order_history.php">Order History</a></li>
                    <li class="nav-item"><a class="nav-link" href="chat.php">Chatroom</a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="container-lg mt-4">
        <!-- Search Bar -->
        <div class="search-section">
            <form action="design_dashboard.php" method="get" aria-label="Search">
                <div class="input-group">
                    <input type="text" name="tag" class="form-control form-control-lg" placeholder="Search designs by tag..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="page-title">Design</div>

        <div class="container-with-filter">
            <!-- Filter Panel -->
            <aside class="filter-panel">
                <h5><i class="fas fa-filter me-2"></i>Filters</h5>
                <form method="GET" action="design_dashboard.php" id="filterForm">
                    <!-- Search Tag (Hidden) -->
                    <input type="hidden" name="tag" value="<?= htmlspecialchars($search) ?>">

                    <!-- Price Range Filter -->
                    <div class="filter-group">
                        <label>Price Range (HK$)</label>
                        <div class="price-inputs">
                            <input type="number" name="min_price" class="form-control" placeholder="Min" value="<?= $min_price > 0 ? $min_price : '' ?>" min="0">
                            <span class="price-separator">-</span>
                            <input type="number" name="max_price" class="form-control" placeholder="Max" value="<?= $max_price < 999999 ? $max_price : '' ?>" min="0">
                        </div>
                    </div>

                    <!-- Designer Filter -->
                    <div class="filter-group">
                        <label for="designer_id">Designer</label>
                        <select name="designer_id" id="designer_id" class="form-select">
                            <option value="">All Designers</option>
                            <?php while ($designer = $designer_result->fetch_assoc()): ?>
                                <option value="<?= $designer['designerid'] ?>" <?= $designer_id == $designer['designerid'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($designer['dname']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Sort By -->
                    <div class="filter-group">
                        <label for="sort_by">Sort By</label>
                        <select name="sort_by" id="sort_by" class="form-select">
                            <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Newest</option>
                            <option value="price_low" <?= $sort_by === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                            <option value="price_high" <?= $sort_by === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                            <option value="likes" <?= $sort_by === 'likes' ? 'selected' : '' ?>>Most Liked</option>
                        </select>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="filter-buttons">
                        <button type="submit" class="btn-apply-filter">
                            <i class="fas fa-check me-1"></i>Apply
                        </button>
                        <a href="design_dashboard.php" class="btn-clear-filter" style="text-align: center; text-decoration: none;">
                            <i class="fas fa-times me-1"></i>Clear
                        </a>
                    </div>
                </form>
            </aside>

            <!-- Main Content -->
            <div class="main-content">


                <!-- Results Grid -->
                <div class="row g-4">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <div class="col-lg-4 col-md-6 col-sm-12">
                            <a href="client/design_detail.php?designid=<?= htmlspecialchars($row['designid']) ?>" style="text-decoration: none;">
                                <div class="card h-100">
                                    <img src="design_image.php?id=<?= (int)$row['designid'] ?>" class="card-img-top" alt="Design by <?= htmlspecialchars($row['dname']) ?>">
                                    <div class="card-body text-center">
                                        <p class="text-muted mb-2"><?= htmlspecialchars($row['likes']) ?> Likes</p>
                                        <p class="h6 mb-0" style="color: #e74c3c; font-weight: 700;">HK$<?= number_format((int)$row['price']) ?></p>
                                        <small class="text-muted d-block mt-2">by <?= htmlspecialchars($row['dname']) ?></small>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5" style="color: #7f8c8d;">
                                <i class="fas fa-search" style="font-size: 4rem; margin-bottom: 1rem; color: #bdc3c7;"></i>
                                <h3>No Designs Found</h3>
                                <p>Try adjusting your filters to find what you're looking for.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ==================== Chat Widget Integration ==================== -->
    <?php
    // Include floating chat widget for logged-in users only
    if (isset($_SESSION['user'])) {
        include __DIR__ . '/designer/chat_widget.php';
    }
    ?>

    <!-- Include chat functionality JavaScript -->
    <script src="designer/Chatfunction.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['user'])): ?>
        // Initialize chat application
        const chatApp = initApp({
            apiPath: 'designer/ChatApi.php?action=',
            userId: <?= (int)($_SESSION['user']['clientid'] ?? $_SESSION['user']['id'] ?? 0) ?>,
            userType: '<?= htmlspecialchars($_SESSION['user']['role'] ?? 'client') ?>',
            userName: '<?= htmlspecialchars($_SESSION['user']['name'] ?? 'User', ENT_QUOTES) ?>',
            rootId: 'chatwidget',
            items: []
        });
        
        console.log('Chat widget initialized');
        <?php endif; ?>
    });
    </script>
    <!-- ==================== End Chat Widget Integration ==================== -->

</body>
</html>
