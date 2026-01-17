<?php
// Designer dashboard with summary cards and upcoming confirmed jobs list.

session_start();
require_once __DIR__ . '/../config.php';
// require a logged-in designer
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'designer') {
  header('Location: ../login.php');
  exit;
}


$designerId = (int) ($_SESSION['user']['designerid'] ?? $_SESSION['user']['id'] ?? 0);

// compatibility: some pages expect $conn; config.php provides $mysqli
$conn = $mysqli;

// Count helper function; supports 'ALL' or a specific status
// Count orders for this designer. Orders link to Design, which has designerid.
function countBy($conn, $designerId, $status)
{
  if ($status === 'ALL') {
    $sql = "SELECT COUNT(*) AS total FROM `Order` o JOIN Design d ON o.designid = d.designid WHERE d.designerid = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param("i", $designerId);
    } else {
      return 0;
    }
  } else {
    $sql = "SELECT COUNT(*) AS total FROM `Order` o JOIN Design d ON o.designid = d.designid WHERE d.designerid = ? AND o.ostatus = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param("is", $designerId, $status);
    } else {
      return 0;
    }
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  return (int) ($row['total'] ?? 0);
}

// Summary stats for this designer
$cntAll = countBy($conn, $designerId, 'ALL');
$cntConfirmed = countBy($conn, $designerId, 'Confirmed');
$cntAwaitReview = countBy($conn, $designerId, 'AwaitingReview');
$cntCompleted = countBy($conn, $designerId, 'Completed');
$cntRejected = countBy($conn, $designerId, 'Rejected');

// Upcoming confirmed jobs (future only, limit 8)
// Upcoming confirmed jobs for orders that belong to this designer
$sql = "SELECT s.scheduleid AS scid,
               s.FinishDate AS RescDate,
               s.orderid,
               o.orderid AS oid,
               o.odate,
               o.budget,
               o.Floor_Plan,
               o.Requirements,
               o.ostatus,
               c.cname AS client_name
        FROM Schedule s
        JOIN `Order` o ON s.orderid = o.orderid
        JOIN Design d ON o.designid = d.designid
        LEFT JOIN Client c ON o.clientid = c.clientid
        WHERE d.designerid = ?
          AND s.FinishDate >= NOW()
        ORDER BY s.FinishDate ASC
        LIMIT 8";
$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param("i", $designerId);
  $stmt->execute();
  $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  // Prepare failed (table missing or SQL error) — fall back to empty list
  $upcoming = [];
}

// Map schedule status to Bootstrap badge classes
function statusBadgeClass($status)
{
  return match ($status) {
    'Pending' => 'bg-warning text-dark',
    'Confirmed' => 'bg-primary',
    'AwaitingReview' => 'bg-info text-dark',
    'Completed' => 'bg-success',
    'Cancelled' => 'bg-danger',
    'Rejected' => 'bg-danger', // NEW
    default => 'bg-secondary',
  };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>HappyDesign Order</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap + shared admin styles -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/staffStyle.css">
  <style>
    /* Highlight the "Rejected" stat if > 0 */
    .stat-danger {
      border: 1px solid #f8d7da;
    }

    .stat-danger .text-muted {
      color: #b02a37 !important;
    }

    .stat-danger .display-6 {
      color: #dc3545;
    }
  </style>
</head>

<body>
  <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
      <div class="h4 mb-0"><a href="design_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a>
      </div>
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
              <i class="fas fa-user me-1"></i>Hello
              <?= htmlspecialchars($clientData['cname'] ?? $_SESSION['user']['name'] ?? 'User') ?>
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
  <div class="container my-4">
    <h2>Welcome, <?= htmlspecialchars($_SESSION['user']['name'] ?? 'Designer') ?></h2>

    <div class="row mt-4 g-3">
      <div class="col-md-3 col-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="text-muted">Total Order</h6>
            <div class="display-6"><?= $cntAll ?></div>
          </div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="text-muted">Total Revenue</h6>
            <div class="display-6"><?= $cntCompleted ?></div>
          </div>
        </div>
      </div>

      <!-- Message -->
      <div class="col-md-3 col-6">
        <a href="chat.php" class="text-decoration-none">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="text-muted">Unread Message(s)</h6>
              <?php
              // Count unread chat messages for this designer (exclude messages the designer sent).
              $stmt = $conn->prepare(
                "SELECT COUNT(DISTINCT m.messageid) AS total
                 FROM ChatRoomMember crm
                 JOIN Message m ON m.ChatRoomid = crm.ChatRoomid
                 LEFT JOIN MessageRead mr ON mr.messageid = m.messageid AND mr.ChatRoomMemberid = crm.ChatRoomMemberid
                 WHERE crm.member_type = 'designer'
                   AND crm.memberid = ?
                   AND (mr.is_read = 0 OR mr.is_read IS NULL)
                   AND NOT (m.sender_type = 'designer' AND m.sender_id = ?)"
              )
              ;
              if ($stmt) {
                $stmt->bind_param("ii", $designerId, $designerId);
                $stmt->execute();
                $result = $stmt->get_result();
                $countNotify = $result ? (int) $result->fetch_assoc()['total'] : 0;
                $stmt->close();
              } else {
                $countNotify = 0;
              }
              ?>
              <p class="display-6"><?= $countNotify ?></p>
            </div>
          </div>
        </a>
      </div>
    </div>

    <!-- schedule list -->
    <div class="card shadow-sm mt-4">
      <div class="card-body">
        <h5 class="mb-3">Schedule</h5>
        <?php if (!$upcoming): ?>
          <div class="text-muted">No upcoming schedule.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Date/Time</th>
                  <th>Address</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($upcoming as $r):
                  // Use Floor_Plan or client name as a simple address/label fallback
                  $addr = trim(($r['Floor_Plan'] ?? '') . ' ' . ($r['client_name'] ?? '')) ?: 'N/A';
                  $badge = statusBadgeClass($r['ostatus'] ?? 'Confirmed');
                  ?>
                  <tr>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['RescDate']))) ?></td>
                    <td><?= htmlspecialchars($addr ?: 'N/A') ?></td>
                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['ostatus'] ?? 'Confirmed') ?></span></td>
                    <td><a class="btn btn-sm btn-primary" href="job_view.php?scid=<?= (int) $r['scid'] ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>

</html>