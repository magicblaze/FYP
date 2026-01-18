<?php
session_start();

if (!isset($_SESSION['user'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'designer/chat.php');
    header('Location: ../login.php?redirect=' . $redirect);
    exit;
}

require_once __DIR__ . '/Chatfunction.php';
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action) {
    if ($action === 'get_agents') {
        json_response(['agents' => get_agents()]);
    }

    if ($action === 'get_messages') {
        $conversation = $_GET['conversation'] ?? '';
        $limit = intval($_GET['limit'] ?? 500);
        if ($conversation === '') json_response(['error' => 'Missing conversation'], 400);
        json_response(['messages' => get_messages($conversation, $limit)]);
    }

    if ($action === 'send_message') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $conversation = $input['conversation'] ?? '';
        $sender = $input['sender'] ?? 'user';
        $body = trim($input['body'] ?? '');
        $campaign = isset($input['campaign']) ? trim($input['campaign']) : null;
        if ($conversation === '' || $body === '') json_response(['error' => 'Missing fields'], 400);
        try {
            $res = send_message($conversation, $sender, $body, $campaign);
            json_response($res);
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    }

    json_response(['error' => 'Unknown action'], 404);
}

// Render UI
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>HappyDesign</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--bg:#f5f7fb;--muted:#7b8492;--radius:12px}
    body{background:var(--bg);font-family:Inter,system-ui,Segoe UI,Roboto,Arial;margin:0}
    .catalog-card{border-radius:var(--radius);background:linear-gradient(180deg,#fff,#fbfdff)}
    .thumb{height:92px;border-radius:8px;background:linear-gradient(90deg,#e9eef8,#f9fafc);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px}
    .catalog-item{border-radius:10px;box-shadow:0 2px 8px rgba(11,27,43,0.04);cursor:pointer}
    .product-preview{border:1px solid #eef6ff;border-radius:10px;padding:6px;display:inline-block}
    .campaign-row input{border-radius:10px;padding:6px 8px;background:#f8fafc;border:1px solid #e6edf8}
    .offcanvas-body .list-group{max-height:240px;overflow:auto}
    .offcanvas-body #cardsGridOffcanvas{max-height:380px;overflow:auto}
    /* Ensure chat panel has sensible minimum size for usability */
    .chat-card .card-body { min-height: 480px; height: 680px; }
    .chat-card .card-body > div { min-width: 360px; }
    .chat-card #messages { min-height: 300px; }
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

  <div class="container my-4">
    <div class="row gx-3">
      <div class="col-12">
        <div class="card shadow-sm chat-card">
          <div class="card-body d-flex p-0" style="min-height:480px;">
            <div style="flex:0 0 28%;min-width:200px;max-width:36%;border-right:1px solid #eef3fb;padding:12px 14px;overflow:auto;background:#fbfdff">
              <div class="d-flex align-items-center mb-2">
                <div class="h6 mb-0">Chat Lists</div>
              </div>
              <div id="agentsList" class="list-group mb-3 overflow-auto" style="max-height:240px">
                <?php include __DIR__ . '/chat_list.php'; ?>
              </div>
              <div class="small text-muted mb-2">Saved designs</div>
              <div id="cardsGrid" class="row g-2 overflow-auto" style="max-height:520px"></div>
            </div>

            <!-- Right: messages -->
            <div class="flex-grow-1 d-flex flex-column" style="padding:16px;min-width:220px;">
              <div class="d-flex align-items-start justify-content-between mb-2 border-bottom pb-2">
                <div>
                  <div class="chat-title h6 mb-0" id="chat-name"></div>
                  <div id="typingIndicator" class="small text-muted"></div>
                </div>
              </div>

              <div id="messages" class="flex-grow-1 overflow-auto mb-3 px-1" aria-live="polite"></div>

              <form id="composer" class="d-flex gap-2 align-items-center" role="form" onsubmit="return false;">
                <button type="button" class="btn btn-light btn-sm" id="attach" title="Attach">📎</button>
                <input id="input" class="form-control form-control-sm" placeholder="Write a message or type /share to send a product" aria-label="Message input">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="favorite" title="Quick favorite">♡</button>
                <button type="button" class="btn btn-primary btn-sm" id="send">Send</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-start" tabindex="-1" id="catalogOffcanvas" aria-labelledby="catalogOffcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="catalogOffcanvasLabel">Agents / Catalog</h5>
      <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
      <div class="p-3">
        <div class="d-flex align-items-center mb-2">
          <div class="h6 mb-0">Agents</div>
          <div class="ms-auto small text-muted">Select to chat</div>
        </div>

        <div id="agentsListOffcanvas" class="list-group mb-3 overflow-auto" style="max-height:240px">
          <?php include __DIR__ . '/chat_list.php'; ?>
        </div>

        <div class="d-flex align-items-center mb-3 campaign-row">
          <label for="campaignInputOff" class="form-label mb-0 me-2 fw-semibold">Campaign</label>
          <input id="campaignInputOff" type="text" class="form-control form-control-sm me-2" placeholder="e.g., Sale..." style="min-width:160px">
          <a href="#" id="campaignHelpOff" class="text-primary small">Help</a>
        </div>

        <div class="small text-muted mb-2">Featured designs</div>
        <div id="cardsGridOffcanvas" class="row g-2 overflow-auto"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="Chatfunction.js"></script>
  <script>
    <?php
      $role = $_SESSION['user']['role'] ?? 'client';
      $uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);
    ?>
    document.addEventListener('DOMContentLoaded', function () {
          const app = initApp({
                  apiPath: 'ChatApi.php?action=',
                  userType: <?= json_encode($role) ?>,
                  userId: <?= json_encode($uid) ?>,
                  items: [
                    {likes:277, price:'$50', title:'Modern Living Set'},//php later fetch from DB
                  ]
                });
          const openRoom = <?= json_encode($_GET['open_room'] ?? null) ?>;
          if (openRoom) {
            // Ensure agents loaded then select the room id
            app.loadAgents().then(() => {
              try { app.selectAgent({ ChatRoomid: openRoom, roomname: 'Conversation' }); } catch (e) { console.error(e); }
            }).catch(() => {
              try { app.selectAgent({ ChatRoomid: openRoom, roomname: 'Conversation' }); } catch (e) { console.error(e); }
            });
          }
    });
  </script>
</body>
</html>