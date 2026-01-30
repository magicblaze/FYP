<?php
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
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Manager</title>
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
  </style>
</head>

<body>
  <?php include_once __DIR__ . '/../includes/header.php'; ?>
  <main class="container-lg mt-4">
    <h1 class="page-title mb-3">Order Manager</h1>
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table">
              <tr>
                <th>Order ID</th>
                <th>Design</th>
                <th>Client</th>
                <th>Date</th>
                <th>GFA (m²)</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($res && $res->num_rows):
                while ($row = $res->fetch_assoc()): ?>
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
                      <a href="design_orders.php?orderid=<?= (int) $row['orderid'] ?>"
                        class="btn btn-sm btn-primary">View</a>
                      <?php if (strtolower(trim($row['ostatus'] ?? '')) === 'waiting confirm'): ?>
                        <button class="btn btn-sm btn-success ms-1"
                          onclick="updateOrder(<?= (int) $row['orderid'] ?>,'confirm', this)">Confirm</button>
                        <button class="btn btn-sm btn-danger ms-1"
                          onclick="updateOrder(<?= (int) $row['orderid'] ?>,'reject', this)">Reject</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile; else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">No orders found.</td>
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