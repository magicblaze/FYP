<?php
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer') {
    header('Location: ../login.php');
    exit;
}

$designerId = $_SESSION['user']['designerid'] ?? 0;
$designerName = $_SESSION['user']['name'] ?? 'Designer';

$sql = "SELECT d.*, (
    SELECT di.image_filename FROM DesignImage di WHERE di.designid = d.designid ORDER BY di.image_order ASC LIMIT 1
) AS image_filename FROM Design d WHERE d.designerid = ? ORDER BY d.designid DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $designerId);
$stmt->execute();
$res = $stmt->get_result();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Designs (Manager)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.thumb{width:64px;height:64px;object-fit:cover;border-radius:6px}</style>
</head>
<body>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3>Design Manager</h3>
      <div>Hello, <?= htmlspecialchars($designerName) ?></div>
    </div>

    <div class="mb-3">
      <a href="add_design.php" class="btn btn-success">Add New Design</a>
    </div>

    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Image</th>
                <th>Design</th>
                <th>Price</th>
                <th>Likes</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($res && $res->num_rows): while ($row = $res->fetch_assoc()): ?>
                <tr>
                  <td>
                    <?php if (!empty($row['image_filename'])): ?>
                      <img src="../uploads/designs/<?= htmlspecialchars($row['image_filename']) ?>" class="thumb" alt="">
                    <?php else: ?>
                      <img src="../uploads/designs/placeholder.jpg" class="thumb" alt="">
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="fw-bold"><?= htmlspecialchars($row['designName'] ?? '') ?></div>
                    <small class="text-muted">ID: <?= (int)$row['designid'] ?></small>
                  </td>
                  <td>HK$<?= number_format($row['expect_price'] ?? 0) ?></td>
                  <td><?= (int)($row['likes'] ?? 0) ?></td>
                  <td class="text-end">
                    <a href="design-detail.php?designid=<?= (int)$row['designid'] ?>" class="btn btn-sm btn-primary">View</a>
                    <a href="update_design.php?designid=<?= (int)$row['designid'] ?>" class="btn btn-sm btn-warning">Edit</a>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No designs found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
