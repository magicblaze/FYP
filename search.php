<?php
// ==============================
// File: search.php - Search results with filters
// ==============================
require_once __DIR__ . '/config.php';
session_start();

// Get search parameters
$searchTag = trim($_GET['tag'] ?? '');
$priceFilter = $_GET['price'] ?? 'all';
$tagFilter = $_GET['filter_tag'] ?? '';

// Build query based on filters
$sql = "SELECT d.designid, d.price, d.likes, d.tag, dz.dname
        FROM Design d
        JOIN Designer dz ON d.designerid = dz.designerid
        WHERE 1=1";

$params = [];
$types = '';

// Search by tag keyword
if (!empty($searchTag)) {
    $sql .= " AND d.tag LIKE ?";
    $params[] = '%' . $searchTag . '%';
    $types .= 's';
}

// Filter by specific tag
if (!empty($tagFilter)) {
    $sql .= " AND d.tag LIKE ?";
    $params[] = '%' . $tagFilter . '%';
    $types .= 's';
}

// Filter by price range
switch ($priceFilter) {
    case '0-500':
        $sql .= " AND d.price >= 0 AND d.price <= 500";
        break;
    case '500-1000':
        $sql .= " AND d.price > 500 AND d.price <= 1000";
        break;
    case '1000-1500':
        $sql .= " AND d.price > 1000 AND d.price <= 1500";
        break;
    case 'all':
    default:
        // No price filter
        break;
}

$sql .= " ORDER BY d.designid ASC";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

// Get all unique tags for filter
$tagSql = "SELECT DISTINCT tag FROM Design";
$tagRes = $mysqli->query($tagSql);
$allTags = [];
while ($row = $tagRes->fetch_assoc()) {
    $tags = array_filter(array_map('trim', explode(',', $row['tag'])));
    foreach ($tags as $t) {
        if (!in_array($t, $allTags)) {
            $allTags[] = $t;
        }
    }
}
sort($allTags);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Search Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-section {
            background: #fff;
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        .filter-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .filter-btn {
            background-color: #7f8c8d;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .filter-btn:hover {
            background-color: #5a6c7d;
            color: white;
        }
        .filter-btn.active {
            background-color: #3498db;
            color: white;
        }
        .tag-filter-btn {
            background-color: #5a9ab8;
            color: white;
        }
        .tag-filter-btn:hover {
            background-color: #4a8aa8;
            color: white;
        }
        .tag-filter-btn.active {
            background-color: #2980b9;
        }
        .search-results-header {
            margin-bottom: 1rem;
            color: #2c3e50;
        }
        .search-results-header span {
            color: #3498db;
            font-weight: 600;
        }
        .no-results {
            text-align: center;
            padding: 3rem;
            color: #7f8c8d;
        }
        .no-results i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #bdc3c7;
        }
        .search-section {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        .search-section .form-control {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
        }
        .search-section .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
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
                        <a class="nav-link text-muted active" href="client/profile.php">
                            <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
                        </a>
                    </li>
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
        <div class="search-section mb-3">
            <form action="search.php" method="get" aria-label="Search">
                <input type="text" name="tag" class="form-control form-control-lg" placeholder="Search designs..." value="<?= htmlspecialchars($searchTag) ?>">
            </form>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-label">Price</div>
            <div class="filter-buttons">
                <?php
                $priceRanges = [
                    'all' => 'All',
                    '0-500' => '0-500',
                    '500-1000' => '500-1000',
                    '1000-1500' => '1000-1500'
                ];
                foreach ($priceRanges as $value => $label):
                    $isActive = ($priceFilter === $value) ? 'active' : '';
                    $url = 'search.php?' . http_build_query(array_merge($_GET, ['price' => $value]));
                ?>
                    <a href="<?= htmlspecialchars($url) ?>" class="filter-btn <?= $isActive ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            
            <div class="filter-label">Tags</div>
            <div class="filter-buttons">
                <?php foreach ($allTags as $tag):
                    $isActive = ($tagFilter === $tag) ? 'active' : '';
                    $url = 'search.php?' . http_build_query(array_merge($_GET, ['filter_tag' => $tag]));
                ?>
                    <a href="<?= htmlspecialchars($url) ?>" class="filter-btn tag-filter-btn <?= $isActive ?>"><?= htmlspecialchars($tag) ?></a>
                <?php endforeach; ?>
                <?php if (!empty($tagFilter)): ?>
                    <?php 
                    $clearUrl = 'search.php?' . http_build_query(array_filter($_GET, function($key) {
                        return $key !== 'filter_tag';
                    }, ARRAY_FILTER_USE_KEY));
                    ?>
                    <a href="<?= htmlspecialchars($clearUrl) ?>" class="filter-btn" style="background-color: #e74c3c;">
                        <i class="fas fa-times me-1"></i>Clear Tag
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Header -->
        <?php if (!empty($searchTag)): ?>
            <div class="search-results-header">
                <h5>Search results for: <span>"<?= htmlspecialchars($searchTag) ?>"</span></h5>
            </div>
        <?php endif; ?>

        <!-- Results Grid -->
        <div class="row g-4">
            <?php if ($res->num_rows > 0): ?>
                <?php while ($row = $res->fetch_assoc()): ?>
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <a href="detail.php?designid=<?= htmlspecialchars($row['designid']) ?>" style="text-decoration: none;">
                        <div class="card h-100">
                            <img src="design_image.php?id=<?= (int)$row['designid'] ?>" class="card-img-top" alt="Design by <?= htmlspecialchars($row['dname']) ?>">
                            <div class="card-body text-center">
                                <p class="text-muted mb-2"><?= htmlspecialchars($row['likes']) ?> Likes</p>
                                <p class="h6 mb-0">$<?= number_format((float)$row['price'], 0) ?></p>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>No Results Found</h3>
                        <p>Try adjusting your search or filters to find what you're looking for.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
