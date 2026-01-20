<?php
session_start();
require_once __DIR__ . '/config.php';

// Handle fake login / logout for testing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $role = $_POST['role'] ?? 'client';
        $id = (int)($_POST['id'] ?? 1);
        $name = $_POST['name'] ?? ($role === 'client' ? 'Test Client' : 'Test User');
        // set session user with role-specific id key (e.g., clientid)
        $_SESSION['user'] = [
            'role' => $role,
            $role . 'id' => $id,
            'name' => $name
        ];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        unset($_SESSION['user']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Chat Widget Test</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container">
    <h3>Chat Widget Test</h3>

    <?php if (!isset($_SESSION['user'])): ?>
      <div class="card mb-3">
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="login">
            <div class="mb-3">
              <label class="form-label">Choose account</label>
              <select name="role" class="form-select">
                <option value="client">Client</option>
                <option value="designer">Designer</option>
                <option value="contractors">Contractor</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">ID</label>
              <input name="id" type="number" class="form-control" value="1">
            </div>
            <div class="mb-3">
              <label class="form-label">Display name</label>
              <input name="name" type="text" class="form-control" value="Test User">
            </div>
            <button class="btn btn-primary">Set Session / Login</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-success">Logged in as <strong><?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?></strong> (role: <strong><?= htmlspecialchars($_SESSION['user']['role']) ?></strong>)</div>
      <form method="post" class="mb-3"><input type="hidden" name="action" value="logout"><button class="btn btn-outline-secondary">Logout</button></form>
    <?php endif; ?>

    <p>Include the floating chat widget below. Click the blue button at bottom-right to open the chat panel.</p>

    <?php include __DIR__ . '/designer/chat_widget.php'; ?>

    <hr>
    <h5>Database debug: chat-related tables</h5>
    <?php
      require_once __DIR__ . '/config.php';
      function print_table($res) {
        if (!$res) { echo '<div class="text-danger">Query failed</div>'; return; }
        echo '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>';
        $fields = mysqli_fetch_fields($res);
        foreach ($fields as $f) echo '<th>' . htmlspecialchars($f->name) . '</th>';
        echo '</tr></thead><tbody>';
        mysqli_data_seek($res, 0);
        while ($row = mysqli_fetch_assoc($res)) {
          echo '<tr>';
          foreach ($row as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table></div>';
      }

      // ChatRoom
      $r = mysqli_query($mysqli, 'SELECT * FROM ChatRoom');
      echo '<h6>ChatRoom</h6>';
      if ($r && mysqli_num_rows($r)) print_table($r); else echo '<div class="small text-muted">No ChatRoom rows</div>';

      // ChatRoomMember
      $rm = mysqli_query($mysqli, 'SELECT * FROM ChatRoomMember');
      echo '<h6>ChatRoomMember</h6>';
      if ($rm && mysqli_num_rows($rm)) print_table($rm); else echo '<div class="small text-muted">No ChatRoomMember rows</div>';

      // Message
      $m = mysqli_query($mysqli, 'SELECT * FROM Message ORDER BY timestamp DESC LIMIT 200');
      echo '<h6>Message (latest 200)</h6>';
      if ($m && mysqli_num_rows($m)) print_table($m); else echo '<div class="small text-muted">No Message rows</div>';
    ?>

    <hr>
    <p class="small text-muted">This test page sets a fake session and includes the widget from <code>designer/chat_widget.php</code>.</p>
  </div>
</body>
</html>
