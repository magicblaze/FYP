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
  </style>
</head>
<body>
  <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
      <div class="h4 mb-0">HappyDesign</div>
      <button class="btn btn-outline-primary d-lg-none" id="openCatalogBtn" type="button" aria-controls="catalogOffcanvas">☰ Agents / Catalog</button>
    </div>
    <input type="text" id="globalSearch" class="form-control w-50 d-none d-md-block" placeholder="Search...">
  </header>

  <div class="container my-4">
    <div class="row gx-3">
      <aside class="col-lg-4 d-none d-lg-block" id="catalogColumn">
        <div class="card shadow-sm catalog-card">
          <div class="card-body p-3 d-flex flex-column" style="min-height:680px;">
            <div class="d-flex align-items-center mb-2">
              <div class="h6 mb-0">Chat Lists</div>
            </div>

            <div id="agentsList" class="list-group mb-3 overflow-auto" style="max-height:240px">
              <?php include __DIR__ . '/chat_list.php'; ?>
            </div>

            <div class="small text-muted mb-2">Saved designs</div>
            <div id="cardsGrid" class="row g-2 overflow-auto" style="max-height:520px"></div>
          </div>
        </div>
      </aside>

      <main class="col-lg-8">
        <div class="card shadow-sm chat-card">
          <div class="card-body d-flex flex-column p-3" style="height:680px;">
            <div class="d-flex align-items-start justify-content-between mb-2 border-bottom pb-2">
              <div>
                <div class="chat-title h6 mb-0" id="chat-name"></div>
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
      </main>
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
    document.addEventListener('DOMContentLoaded', function () {
          initApp({
                  apiPath: 'ChatApi.php?action=',
                  userType: 'client',
                  userId: 1,
                  items: [
                    {likes:277, price:'$50', title:'Modern Living Set'},//php later fetch from DB
                  ]
                });
    });
  </script>
</body>
</html>