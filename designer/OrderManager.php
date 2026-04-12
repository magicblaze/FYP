<?php
// OrderManager.php - 修改后的完整文件
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer') {
  header('Location: ../login.php');
  exit;
}

$designerId = $_SESSION['user']['designerid'] ?? 0;
$designerName = $_SESSION['user']['name'] ?? 'Designer';

// check for specific order
$orderId = (int) ($_GET['orderid'] ?? 0);
if ($orderId > 0) {
  // redirect to designer's detailed orders page which already handles upload/approve logic
  $target = 'design_orders.php?orderid=' . $orderId;
  header('Location: ' . $target);
  exit;
}

// otherwise show list (existing behavior)
$sql = "SELECT o.orderid, o.odate, o.ostatus, o.clientid, o.designid, o.gross_floor_area, d.designName, c.cname AS clientName
        FROM `Order` o
        LEFT JOIN Design d ON o.designid = d.designid
        LEFT JOIN Client c ON o.clientid = c.clientid
        WHERE o.designid IN (SELECT designid FROM Design WHERE designerid = ?)
        ORDER BY o.orderid DESC";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  error_log('OrderManager prepare failed: ' . ($mysqli->error ?? 'unknown'));
  die('Database error.');
}
if (!$stmt->bind_param('i', $designerId)) {
  error_log('OrderManager bind_param failed: ' . ($stmt->error ?? 'unknown'));
  die('Database error.');
}
if (!$stmt->execute()) {
  error_log('OrderManager execute failed: ' . ($stmt->error ?? 'unknown'));
  die('Database error.');
}
$res = $stmt->get_result();

// Collect all orders first
$orders_list = [];
if ($res && $res->num_rows) {
  while ($row = $res->fetch_assoc()) {
    $orders_list[] = $row;
  }
}

// Check which orders have reports (for eligible statuses)
$orders_with_reports = [];
$order_ids_for_check = [];
foreach ($orders_list as $order) {
  $statusLower = strtolower($order['ostatus'] ?? '');
  // Eligible statuses for showing reports button
  $eligible_statuses = ['construction begins', 'waiting for inspection', 'complete'];
  if (in_array($statusLower, $eligible_statuses)) {
    $order_ids_for_check[] = $order['orderid'];
  }
}

if (!empty($order_ids_for_check)) {
  $ids_str = implode(',', $order_ids_for_check);
  $report_check_sql = "SELECT orderid, COUNT(*) as report_count FROM WeeklyConstructionReport 
                       WHERE orderid IN ($ids_str) AND status = 'submitted'
                       GROUP BY orderid";
  $report_check_result = $mysqli->query($report_check_sql);
  if ($report_check_result) {
    while ($row = $report_check_result->fetch_assoc()) {
      $orders_with_reports[$row['orderid']] = $row['report_count'];
    }
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Project Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../css/styles.css">
    <style>
    .thumb {
      width: 64px;
      height: 64px;
      object-fit: cover;
      border-radius: 6px
    }

    .card {
      transition: none !important;
    }
    .card:hover {
      box-shadow: 0 2px 10px rgba(0,0,0,0.06) !important;
      transform: none !important;
    }
    .btn-report {
      background-color: #20c997;
      color: white;
      margin-left: 5px;
    }
    .btn-report:hover {
      background-color: #1ba87e;
      color: white;
    }
  </style>
</head>

<body>
  <?php include_once __DIR__ . '/../includes/header.php'; ?>
  <main class="container-lg mt-4">
    <h1 class="page-title mb-3">Project Manager</h1>
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table">
              <tr>
                <th>Project ID</th>
                <th>Design</th>
                <th>Client</th>
                <th>Date</th>
                <th>GFA (m²)</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($orders_list)):
                foreach ($orders_list as $row): 
                  $statusLower = strtolower($row['ostatus'] ?? '');
                  // Eligible statuses for showing reports button
                  $eligible_statuses = ['construction begins', 'waiting for inspection', 'complete'];
                  $is_eligible = in_array($statusLower, $eligible_statuses);
                  // Show reports button only if eligible AND reports exist
                  $show_report_btn = ($is_eligible && isset($orders_with_reports[$row['orderid']]));
                ?>
                  <tr>
                    <td>#<?= (int) $row['orderid'] ?></td>
                    <td><?= htmlspecialchars($row['designName'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['clientName'] ?? ('#' . (int) $row['clientid'])) ?></td>
                    <td><?= htmlspecialchars($row['odate'] ?? '') ?></td>
                    <td>
                      <?= isset($row['gross_floor_area']) && $row['gross_floor_area'] > 0 ? htmlspecialchars(number_format((float) $row['gross_floor_area'], 2)) : '&mdash;' ?>
                    </td>
                    <td id="status_<?= (int) $row['orderid'] ?>"><?= htmlspecialchars($row['ostatus'] ?? '') ?></td>
                    <td class="text-end" id="actions_<?= (int) $row['orderid'] ?>">
                      <a href="design_orders.php?orderid=<?= (int) $row['orderid'] ?>" class="btn btn-sm btn-primary">View</a>
                      <?php if ($show_report_btn): ?>
                        <a href="designer_view_reports.php?orderid=<?= (int) $row['orderid'] ?>" class="btn btn-sm btn-primary">
                          Reports
                        </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">No projects found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
  <script>
    async function updateOrder(orderId, action, btn) {
      if (!confirm('Are you sure?')) return;
      try {
        btn.disabled = true;
        const res = await fetch('update_order_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ orderid: orderId, action: action })
        });
        const j = await res.json();
        if (j && j.success) {
          const statusEl = document.getElementById('status_' + orderId);
          const actionsEl = document.getElementById('actions_' + orderId);
          if (statusEl) statusEl.textContent = j.status || (action === 'confirm' ? 'Confirmed' : 'Rejected');
          if (actionsEl) {
            // remove confirm/reject buttons
            Array.from(actionsEl.querySelectorAll('button')).forEach(b => b.remove());
          }
        } else {
          alert('Error: ' + (j && j.message ? j.message : 'Unknown'));
          btn.disabled = false;
        }
      } catch (e) { console.error(e); alert('Request failed'); btn.disabled = false; }
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>