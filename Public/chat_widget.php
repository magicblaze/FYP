<?php
// Floating chat widget — include this PHP where you want the floating chat button
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$logged = isset($_SESSION['user']) && !empty($_SESSION['user']);
$role = $_SESSION['user']['role'] ?? 'client';
$uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);
// Centralized paths (use absolute project paths so pages don't need to set these)
$CHAT_API_PATH = '/FYP/Public/ChatApi.php?action=';
$CHAT_JS_SRC = '/FYP/Public/Chatfunction.js';
// Suggestions API path (resolve relative to app root)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$parts = explode('/', ltrim($scriptPath, '/'));
$APP_ROOT = isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '';
$SUGGESTIONS_API = $APP_ROOT . '/api/get_chat_suggestions.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
/* Floating chat button and panel styles */
#chatwidget_toggle {position:fixed;right:20px;bottom:20px;z-index:9999;border-radius:50%;width:56px;height:56px;background:#8faae3;color:#fff;border:none;box-shadow:0 6px 18px rgba(11,27,43,0.18);display:flex;align-items:center;justify-content:center;font-size:22px}
#chatwidget_panel {position:fixed;right:20px;z-index:9998;width:520px;max-width:95vw;max-height:95vh;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(11,27,43,0.12);overflow:hidden;display:none;flex-direction:column;min-width:377px;min-height:255px;box-sizing:border-box}
#chatwidget_panel .header{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f7f9fc;border-bottom:1px solid #eef3fb}
#chatwidget_panel .body{display:flex;gap:8px;padding:8px;flex:1 1 auto;min-height:0;box-sizing:border-box}
#chatwidget_panel .body .left{width:160px;min-width:120px;overflow:auto;border-right:1px solid #eef3fb;padding-right:8px}
#chatwidget_panel .body .left{width:160px;min-width:140px;overflow:auto;border-right:1px solid #eef3fb;padding-right:8px}
#chatwidget_panel .composer{display:flex;gap:8px;padding:8px;border-top:1px solid #eef3fb}
#chatwidget_panel .body .right{flex:1;display:flex;flex-direction:column;min-width:220px}
#chatwidget_panel .body .right{flex:1;display:flex;flex-direction:column;min-width:220px;min-height:200px;position:relative}
/* Responsive: stack on small screens */
@media (max-width: 700px) {
  #chatwidget_panel { right:20px; left:20px; top:auto; bottom:20px; width:calc(100% - 40px); max-width:none; border-radius:10px; min-width:unset; min-height:220px; max-height:80vh; }
  #chatwidget_panel .body { flex-direction:column; gap:6px; padding:6px; max-height:calc(80vh - 120px); }
  #chatwidget_panel .body .left { width:100%; min-width:unset; max-width:none; border-right:none; border-bottom:1px solid #eef3fb; padding-bottom:8px; }
  #chatwidget_panel .body .right { width:100%; min-height:140px; }
  #chatwidget_panel .messages { min-height:120px; }
  #chatwidget_toggle { right:20px; bottom:20px; width:48px; height:48px; }
  #chatwidget_attachPreviewColumn { position: absolute !important; left: 0; right: 0; margin-bottom: 0; width: 100%; z-index: 10005 !important;}
  #chatwidget_panel.preview-visible .messages { padding-bottom: 220px; }
  #chatwidget_panel .composer { z-index: 10000; }
}
/* Float preview above composer and stick to it by JS-calculated offset */
@media (min-width: 700px) {
  #chatwidget_attachPreviewColumn {
    position: absolute !important;
    left: 0; right: 0; bottom: 72px; margin-bottom: 0; width: 100%;
    z-index: 10005 !important;
  }
  #chatwidget_panel.preview-visible .messages { padding-bottom: 220px; }
  #chatwidget_panel .composer { z-index: 10000; }
}

@media (max-width: 420px) {
  #chatwidget_panel { padding:0; }
  #chatwidget_panel .header { padding:8px; }
  #chatwidget_panel .composer { padding:6px; }
  #chatwidget_panel .body .left .list-group { max-height:120px; overflow:auto; }
}
#chatwidget_panel .messages{flex:1 1 auto;overflow:auto;padding:6px;min-height:0}
#chatwidget_divider{width:16px;margin-left:-8px;display:flex;align-items:center;justify-content:center;cursor:col-resize}
#chatwidget_divider .handle{width:5px;height:56px;background:#dcdcdc;border-left:1px solid rgba(0,0,0,0.08);border-right:1px solid rgba(255,255,255,0.4);border-radius:3px}
#chatwidget_agentsList { overflow:auto; -ms-overflow-style: none; scrollbar-width: none; }
#chatwidget_agentsList::-webkit-scrollbar { display: none; width: 0; height: 0; }
#chatwidget_panel .composer{display:flex;gap:8px;padding:8px;border-top:1px solid #eef3fb}
#chatwidget_panel .composer{position:absolute;left:0;right:0;bottom:0;background:white;padding:8px 8px 12px 8px;z-index:3}
#chatwidget_panel .messages{padding-bottom:72px}
#chatwidget_panel .composer input{flex:1}
#chatwidget_close{background:transparent;border:0;font-size:18px}
/* Resizer handle */
#chatwidget_panel .resizer{position:absolute;right:8px;bottom:8px;width:18px;height:18px;cursor:se-resize;background:linear-gradient(135deg, rgba(0,0,0,0.06), rgba(0,0,0,0.02));border-radius:4px;z-index:10001}

/* Bottom-sheet helper class applied on small screens by JS for more predictable behavior */
.chatwidget-bottomsheet { left:20px !important; right:20px !important; top:auto !important; bottom:20px !important; width:calc(100% - 40px) !important; height:60vh !important; max-height:80vh !important; border-radius:12px 12px 6px 6px !important; }
.chatwidget-bottomsheet .resizer { display:none !important; }
.chatwidget-bottomsheet .composer { position:sticky !important; bottom:0; background:linear-gradient(to top, rgba(255,255,255,0.9), rgba(255,255,255,0.6)); }
</style>

<!-- Preview row styling inserted by assistant -->
<style>
.message-preview-column{box-sizing:border-box;padding:8px 12px;border-top:1px solid #eef3fb;border-bottom:0px solid #f8f9fb;background:#ffffff;display:flex;flex-direction:column;gap:8px;align-items:flex-start;}
.message-preview-column img{max-width:100%;max-height:360px;border-radius:6px;object-fit:cover}
.message-preview-column .file-badge{width:56px;height:44px;border-radius:6px;background:#6c757d;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:600}
.message-preview-column .file-meta{display:flex;flex-direction:column;min-width:0}
.message-preview-column .file-meta .name{font-size:0.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.message-preview-column .file-meta .size{font-size:0.8rem;color:#6c757d}
/* Chat link styling: bold white by default for visibility on colored bubbles */
#chatwidget_panel a { color: #ffffff !important; font-weight: 700 !important; }
/* Exception: links inside white message cards should remain dark for readability */
#chatwidget_panel .bg-white a, #chatwidget_panel .text-dark a { color: #333333 !important; font-weight:700 !important; }
</style>

<?php
  // If not logged in, show button that redirects to login (preserves current page for redirect)
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
  if (!$logged) :
?>
  <a id="chatwidget_toggle" class="btn-link" href="../login.php?redirect=<?php echo $redirect ?>" aria-label="Log in to open chat" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#8faae3;color:#fff;">
    <i class="bi bi-chat-dots" aria-hidden="true" style="font-size:22px;color:#fff;line-height:1"></i>
  </a>
<?php else: ?>
  <button id="chatwidget_toggle" aria-label="Open chat" style="display:inline-flex;align-items:center;justify-content:center;padding:0;border:0;background:transparent;">
    <i class="bi bi-chat-dots" aria-hidden="true" style="font-size:22px;color:#8faae3;line-height:1"></i>
  </button>
<?php endif; ?>

<div id="chatwidget_panel" role="dialog" aria-hidden="true" aria-label="Chat widget">
  <div class="header">
    <div>
      <div style="font-weight:600">Message</div>
            <div id="chatwidget_connectionStatus" class="small text-muted">Select a conversation</div>
      <div id="chatwidget_typingIndicator" class="small text-muted"></div>
    </div>
    <div>
      <button id="chatwidget_close" aria-label="Close">✕</button>
    </div>
  </div>
  <div id="chatwidget_body" class="body">
    <div class="left">
      <div class="d-flex justify-content-center"><button id="chatwidget_new" class="btn btn-sm btn-outline-primary m-2" type="button" title="New chat" aria-label="Create chat">+ Open Chat</button></div>
      <div id="chatwidget_agentsList" class="list-group mb-2" style="max-height:100%;overflow:auto;"></div>
    </div>
    <div id="chatwidget_divider" role="separator" aria-orientation="vertical" aria-label="Resize chat list"><div class="handle" aria-hidden="true"></div></div>
    <div class="right">
      <div id="chatwidget_messages" class="messages"></div>
      <!-- attachment preview placeholder row (populated by JS) -->
      <div id="chatwidget_attachPreviewColumn" class="message-preview-column" style="display:none;width:100%;margin-bottom:0px;position:relative;z-index:10005"></div>
      <div class="composer">
        <input type="file" id="chatwidget_attachInput" class="d-none" />
        <button id="chatwidget_attach" class="btn btn-light btn-sm" type="button" title="Attach" aria-label="Attach file">
          <i class="bi bi-paperclip" aria-hidden="true" style="font-size:16px;line-height:1"></i>
        </button>
        <button id="chatwidget_share" class="btn btn-outline-secondary btn-sm" type="button" title="Share page" aria-label="Share design" style="margin-left:4px">
          <i class="bi bi-share" aria-hidden="true" style="font-size:14px"></i>
        </button>
        <input id="chatwidget_input" class="form-control form-control-sm" placeholder="Type a message..." aria-label="Message input" <?php if (!$logged) echo 'disabled title="Log in to send messages"'; ?> >
        <button id="chatwidget_send" class="btn btn-primary btn-sm" <?php if (!$logged) echo 'disabled title="Log in to send messages"'; ?> aria-label="Send message">
          <i class="bi bi-send-fill" aria-hidden="true" style="font-size:16px;line-height:1;color:#fff"></i>
        </button>
      </div>
    </div>
  </div>
  <div id="chatwidget_resizer" class="resizer" aria-hidden="true"></div>
</div>
<!-- Chat widget transitions and lightweight animations -->
<style>
  /* Panel open/close animation */
  #chatwidget_panel { transition: transform 260ms cubic-bezier(.2,.9,.2,1), opacity 220ms ease, box-shadow 260ms ease; transform-origin: right bottom; }
  #chatwidget_panel.chatwidget-hidden { opacity: 0; transform: translateY(12px) scale(.98); pointer-events: none; }
  #chatwidget_panel.chatwidget-open { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }

  /* Toggle button subtle pop */
  #chatwidget_toggle { transition: transform 180ms cubic-bezier(.2,.9,.2,1), box-shadow 180ms ease; }
  #chatwidget_toggle.chatwidget-active { transform: scale(1.04); box-shadow: 0 8px 20px rgba(11,27,43,0.18); }

  /* Message-entry animation (applied via JS) */
  @keyframes chat-in { from { opacity: 0; transform: translateY(8px) scale(.995); } to { opacity: 1; transform: translateY(0) scale(1); } }
  .chatmsg-anim { animation: chat-in 260ms cubic-bezier(.2,.9,.2,1) both; }

  /* Composer subtle elevation when panel opens */
  #chatwidget_panel.chatwidget-open .composer { box-shadow: 0 -10px 18px rgba(11,27,43,0.04); transition: box-shadow 260ms ease; }
</style>
<script>
(function(){
  // Toggle panel and add draggable behavior for panel and toggle
  const toggle = document.getElementById('chatwidget_toggle');
  const panel = document.getElementById('chatwidget_panel');
  const closeBtn = document.getElementById('chatwidget_close');

  function clamp(v, lo, hi){ return Math.min(Math.max(v, lo), hi); }

  // Save normalized position (percent of available area) and absolute fallback
  function savePos(key, leftPx, topPx, target){
    try {
      const availW = window.innerWidth - (target?.offsetWidth||0);
      const availH = window.innerHeight - (target?.offsetHeight||0);
      const xPct = availW > 0 ? (leftPx / availW) : 0;
      const yPct = availH > 0 ? (topPx / availH) : 0;
      localStorage.setItem(key, JSON.stringify({left:leftPx,top:topPx,xPct:xPct,yPct:yPct}));
    } catch(e){}
  }
  function loadPos(key){ try { const s = localStorage.getItem(key); return s ? JSON.parse(s) : null; } catch(e){ return null; } }

  function applyPosTo(el, pos){ if(!pos) return; if (pos.xPct !== undefined && pos.yPct !== undefined) {
      const availW = window.innerWidth - el.offsetWidth; const availH = window.innerHeight - el.offsetHeight;
      const nx = Math.round((availW>0?availW:0) * pos.xPct);
      const ny = Math.round((availH>0?availH:0) * pos.yPct);
      el.style.left = nx + 'px'; el.style.top = ny + 'px'; el.style.right = 'auto';
    } else if (pos.left !== undefined && pos.top !== undefined) {
      el.style.left = pos.left + 'px'; el.style.top = pos.top + 'px'; el.style.right = 'auto';
    } else if (pos.x !== undefined && pos.y !== undefined) {
      el.style.left = pos.x + 'px'; el.style.top = pos.y + 'px'; el.style.right = 'auto';
    }
  }
  // Save/load size (percent + absolute) and apply
  function saveSize(key, wPx, hPx, target){
    try {
      const wPct = window.innerWidth > 0 ? (wPx / window.innerWidth) : 0;
      const hPct = window.innerHeight > 0 ? (hPx / window.innerHeight) : 0;
      localStorage.setItem(key, JSON.stringify({w:wPx,h:hPx,wPct:wPct,hPct:hPct}));
    } catch(e){}
  }
  function loadSize(key){ try { const s = localStorage.getItem(key); return s ? JSON.parse(s) : null; } catch(e){ return null; } }
  function applySizeTo(el, size){ if(!size) return; if (size.wPct !== undefined && size.hPct !== undefined) {
      const nw = Math.round((window.innerWidth>0?window.innerWidth:0) * size.wPct);
      const nh = Math.round((window.innerHeight>0?window.innerHeight:0) * size.hPct);
      el.style.width = Math.max(377, Math.min(nw, window.innerWidth - 16)) + 'px';
      el.style.height = Math.max(255, Math.min(nh, window.innerHeight - 16)) + 'px';
    } else if (size.w !== undefined && size.h !== undefined) {
      el.style.width = Math.max(377, Math.min(size.w, window.innerWidth - 16)) + 'px';
      el.style.height = Math.max(255, Math.min(size.h, window.innerHeight - 16)) + 'px';
    }
  }
  function clearPosStyles(el){ el.style.left=''; el.style.top=''; el.style.right=''; el.style.bottom=''; }
  function applyDefaultTogglePos(el){ clearPosStyles(el); el.style.right = '20px'; el.style.bottom = '20px'; }

  // makeDraggable accepts a handle element and a target element to move
  function makeDraggable(handleEl, storageKey, targetEl){
    const target = targetEl || handleEl;
    let dragging=false, startX=0, startY=0, origX=0, origY=0;
    function onPointerDown(e){
      // If the initial target is an interactive control, don't start dragging
      if (e.target && e.target.closest && e.target.closest('button, a, input, textarea, select')) return;
      if (e.button && e.button !== 0) return; // left button only
      e.preventDefault();
      dragging = true;
      if (handleEl.setPointerCapture) handleEl.setPointerCapture(e.pointerId);
      startX = e.clientX; startY = e.clientY;
      const rect = target.getBoundingClientRect();
      origX = rect.left; origY = rect.top;
      document.addEventListener('pointermove', onPointerMove);
      document.addEventListener('pointerup', onPointerUp);
    }
    function onPointerMove(e){
      if(!dragging) return;
      const dx = e.clientX - startX; const dy = e.clientY - startY;
      const nx = clamp(origX + dx, 8, window.innerWidth - target.offsetWidth - 8);
      const ny = clamp(origY + dy, 8, window.innerHeight - target.offsetHeight - 8);
      target.style.left = nx + 'px'; target.style.top = ny + 'px'; target.style.right = 'auto';
    }
    function onPointerUp(e){
      if(!dragging) return; dragging=false;
      document.removeEventListener('pointermove', onPointerMove);
      document.removeEventListener('pointerup', onPointerUp);
      const rect = target.getBoundingClientRect();
      savePos(storageKey, Math.round(rect.left), Math.round(rect.top), target);
    }
    // ensure target is positioned fixed so left/top positioning works
    target.style.position = 'fixed';
    // attach pointer handlers to the handle element (drag handle)
    handleEl.addEventListener('pointerdown', onPointerDown);
    // initialize from storage (apply to target)
    const pos = loadPos(storageKey);
    if (pos) applyPosTo(target, pos);
  }

  function showSharePreviewQueue(items, roomId, inst) {
    // send items one-by-one with preview; provide a light progress indicator
    let idx = 0;
    let failures = 0;
    const total = items.length;
    const statusEl = document.getElementById('chatwidget_share_status');
    if (statusEl) statusEl.textContent = `Sending 0 / ${total}...`;
    async function next() {
      if (idx >= items.length) {
        if (statusEl) statusEl.textContent = `Done. Sent ${total - failures}/${total}. ${failures? failures + ' failed':''}`;
        // small delay then close
        setTimeout(() => { hideShare(); }, 900);
        return;
      }
      const it = items[idx++];
      openPreviewModal(it, async (text) => {
        const payload = {
          sender_type: 'client',
          sender_id: <?= json_encode($uid) ?>,
          content: it.url || it.title || '',
          room: roomId,
          attachment_url: it.image || it.url || '',
          attachment_name: (it.title||it.type||'item') + '.jpg',
          message_type: 'design',
          share_title: it.title || '',
          share_url: it.url || '',
          share_type: it.type === 'product' ? 'product' : 'design',
          text: text || ''
        };
        try {
          console.debug('chatwidget: sending share payload', payload);
          let resp = null;
          if (inst && typeof inst.apiPost === 'function') {
            resp = await inst.apiPost('sendMessage', payload);
          } else {
            // fallback: call API directly
            const apiPath = <?= json_encode($CHAT_API_PATH) ?>;
            const r = await fetch(apiPath + 'sendMessage', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const txt = await r.text();
            if (!r.ok) {
              try { const j = JSON.parse(txt); throw new Error(j.message || j.error || txt || ('Status ' + r.status)); } catch(ex) { throw new Error(txt || ('Status ' + r.status)); }
            }
            try { resp = JSON.parse(txt); } catch(e) { resp = txt; }
          }
          console.debug('chatwidget: send response', resp);
          if (!(resp && (resp.ok || resp.message || resp.id))) {
            failures++;
            console.warn('Failed to send item', resp);
          }
          if (statusEl) statusEl.textContent = `Sending ${Math.min(idx,total)} / ${total}...`;
        } catch(e){ failures++; console.error('send preview failed',e); if (statusEl) statusEl.textContent = `Error sending ${idx} / ${total}`; }
        // yield to the event loop to keep UI responsive
        setTimeout(next, 120);
      });
    }
    next();
  }

  function openPreviewModal(item, onSend) {
    // create a simple preview modal for single item with text input
    let modal = document.getElementById('sharePreviewModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'sharePreviewModal';
      modal.className = 'modal fade';
      modal.innerHTML = `
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Preview Share</h5></div>
            <div class="modal-body">
              <div id="previewItem"></div>
              <div class="form-group mt-2"><textarea id="previewText" class="form-control" placeholder="Add a message (optional)"></textarea></div>
            </div>
            <div class="modal-footer">
              <button id="previewCancel" class="btn btn-sm btn-secondary">Cancel</button>
              <button id="previewSend" class="btn btn-sm btn-primary">Send</button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(modal);
    }
    const previewItem = modal.querySelector('#previewItem');
    previewItem.innerHTML = `<div style="display:flex;gap:8px;align-items:center"><img src="${item.image||item.url||''}" style="width:48px;height:48px;object-fit:cover"/><div><strong>${item.title||'Item'}</strong><div style="font-size:12px;color:#666">${item.type||''}</div></div></div>`;
    const ta = modal.querySelector('#previewText'); ta.value = '';
    const btnSend = modal.querySelector('#previewSend');
    const btnCancel = modal.querySelector('#previewCancel');
    // lightweight modal show/hide without Bootstrap JS
    modal.style.position = 'fixed';
    modal.style.left = '0'; modal.style.top = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.display = 'flex'; modal.style.alignItems = 'center'; modal.style.justifyContent = 'center';
    modal.style.background = 'rgba(0,0,0,0.35)'; modal.style.zIndex = '100040';
    // ensure inner dialog constrained
    const dialog = modal.querySelector('.modal-dialog'); if (dialog) { dialog.style.maxWidth = '420px'; width = dialog.style.width; }
    btnCancel.onclick = ()=>{ modal.style.display = 'none'; };
    btnSend.onclick = ()=>{
      const text = ta.value.trim();
      modal.style.display = 'none';
      onSend(text);
    };
  }

    // expose to global scope for other scripts in this file
    window.showSharePreviewQueue = showSharePreviewQueue;
    window.openPreviewModal = openPreviewModal;

  // Make header the drag handle for the panel (moves the panel)
  const panelHandle = panel.querySelector('.header') || panel;
  // Drag/resize thresholds — on small devices we'll switch to a bottom-sheet
  const DRAG_THRESHOLD = 700;
  function isSmallScreen(){ return window.innerWidth <= DRAG_THRESHOLD; }

  // Apply small-screen bottom-sheet behavior or normal floating behavior
  function applyResponsiveMode(){
    if (isSmallScreen()){
      panel.classList.add('chatwidget-bottomsheet');
      // clear stored left/top styles to allow bottom-sheet positioning
      clearPosStyles(panel);
      panel.style.width = 'calc(100% - 16px)';
      // If a saved size exists, prefer a constrained height otherwise default to 60vh
      const s = loadSize('chatwidget_panel_size');
      if (s && s.hPct) {
        const h = Math.min(Math.round(window.innerHeight * (s.hPct || 0.6)), Math.round(window.innerHeight * 0.8));
        panel.style.height = Math.max(200, Math.min(h, Math.round(window.innerHeight * 0.8))) + 'px';
      } else {
        panel.style.height = '60vh';
      }
      // hide resizer and avoid draggable handles
      const r = document.getElementById('chatwidget_resizer'); if (r) r.style.display = 'none';
    } else {
      panel.classList.remove('chatwidget-bottomsheet');
      const r = document.getElementById('chatwidget_resizer'); if (r) r.style.display = '';
      // reapply saved size/position for desktop
      const ps = loadPos('chatwidget_panel_pos'); if (ps) applyPosTo(panel, ps);
      const s = loadSize('chatwidget_panel_size'); if (s) applySizeTo(panel, s);
    }
  }

  // enable dragging only on larger screens to avoid interfering with touch interactions
  if (!isSmallScreen()) {
    makeDraggable(panelHandle, 'chatwidget_panel_pos', panel);
  }
  // Make the toggle itself draggable on all sizes (small drag threshold handled by saved pos logic)
  makeDraggable(toggle, 'chatwidget_toggle_pos', toggle);

  // Initialize saved size if present
  const savedSize = loadSize('chatwidget_panel_size');
  if (savedSize) applySizeTo(panel, savedSize);

  // Add resizer behavior
  const resizer = document.getElementById('chatwidget_resizer');
  if (resizer) {
    let resizing = false, sx=0, sy=0, sw=0, sh=0;
    function onResizePointerDown(e){
      if (e.button && e.button !== 0) return;
      e.preventDefault();
      resizing = true;
      if (resizer.setPointerCapture) resizer.setPointerCapture(e.pointerId);
      sx = e.clientX; sy = e.clientY;
      const rect = panel.getBoundingClientRect(); sw = rect.width; sh = rect.height;
      document.addEventListener('pointermove', onResizePointerMove);
      document.addEventListener('pointerup', onResizePointerUp);
    }
    function onResizePointerMove(e){
      if (!resizing) return;
      const dx = e.clientX - sx; const dy = e.clientY - sy;
      const newW = Math.max(377, Math.min(sw + dx, window.innerWidth - 16));
      const newH = Math.max(255, Math.min(sh + dy, window.innerHeight - 16));
      panel.style.width = newW + 'px';
      panel.style.height = newH + 'px';
    }
    function onResizePointerUp(e){
      if (!resizing) return; resizing = false;
      document.removeEventListener('pointermove', onResizePointerMove);
      document.removeEventListener('pointerup', onResizePointerUp);
      const rect = panel.getBoundingClientRect();
      saveSize('chatwidget_panel_size', Math.round(rect.width), Math.round(rect.height), panel);
    }
    // enable resizer only on larger screens
    if (!isSmallScreen()) resizer.addEventListener('pointerdown', onResizePointerDown);
  }

  // Apply saved positions to elements (toggle & panel) if present
  const tPos = loadPos('chatwidget_toggle_pos');
  if (tPos) {
    applyPosTo(toggle, tPos);
  } else {
    // keep default anchored bottom-right
    applyDefaultTogglePos(toggle);
  }
  const pPos = loadPos('chatwidget_panel_pos'); if (pPos) applyPosTo(panel, pPos);

  // Apply responsive mode initially and reapply on resize (use saved percent values)
  applyResponsiveMode();
  window.addEventListener('resize', () => {
    applyResponsiveMode();
    const t = loadPos('chatwidget_toggle_pos'); if (t) applyPosTo(toggle, t); else applyDefaultTogglePos(toggle);
    const p = loadPos('chatwidget_panel_pos'); if (p && !isSmallScreen()) applyPosTo(panel, p);
    // reapply saved size to adapt to new viewport
    const s = loadSize('chatwidget_panel_size'); if (s && !isSmallScreen()) applySizeTo(panel, s);
  });

  // Toggle show/hide behavior
  toggle.addEventListener('click', () => {
    // Ensure responsive mode is applied before showing
    applyResponsiveMode();
    panel.style.display = 'flex'; panel.setAttribute('aria-hidden','false'); toggle.style.display='none';
  });
  closeBtn.addEventListener('click', () => {
    panel.style.display = 'none'; panel.setAttribute('aria-hidden','true'); toggle.style.display='flex';
    const st = loadPos('chatwidget_toggle_pos'); if (st) applyPosTo(toggle, st);
  });

  // Enable divider dragging to resize left list in widget
  (function enableWidgetDivider(){
    const bodyEl = document.getElementById('chatwidget_body');
    const left = bodyEl ? bodyEl.querySelector('.left') : null;
    const divider = document.getElementById('chatwidget_divider');
    if (!bodyEl || !left || !divider) return;
    const panelRect = () => panel.getBoundingClientRect();
    const saveKey = 'chatwidget_left_w';
    // apply stored width
    try { const s = localStorage.getItem(saveKey); if (s) left.style.flex = '0 0 ' + parseInt(s,10) + 'px'; } catch(e){}
    let dragging = false;
    function onPointerDown(e){ if (e.button && e.button !== 0) return; dragging = true; divider.setPointerCapture && divider.setPointerCapture(e.pointerId); document.addEventListener('pointermove', onPointerMove); document.addEventListener('pointerup', onPointerUp); e.preventDefault(); }
    function onPointerMove(e){ if(!dragging) return; const crect = panelRect(); const min = 120; const max = Math.round(crect.width * 0.8); const newW = Math.max(min, Math.min(e.clientX - crect.left, max)); left.style.flex = '0 0 ' + newW + 'px'; }
    function onPointerUp(e){ if(!dragging) return; dragging = false; document.removeEventListener('pointermove', onPointerMove); document.removeEventListener('pointerup', onPointerUp); try { const w = left.getBoundingClientRect().width; localStorage.setItem(saveKey, Math.round(w)); } catch(e){} }
    divider.addEventListener('pointerdown', onPointerDown);
    // hide divider on small screens
    function updateDividerVisibility(){ if (window.innerWidth <= 700) divider.style.display = 'none'; else divider.style.display = 'flex'; }
    window.addEventListener('resize', updateDividerVisibility); updateDividerVisibility();
  })();
})();
</script>

<!-- Chat widget transitions and lightweight animations -->
<style>
  /* Panel open/close animation */
  #chatwidget_panel { transition: transform 260ms cubic-bezier(.2,.9,.2,1), opacity 220ms ease, box-shadow 260ms ease; transform-origin: right bottom; }
  #chatwidget_panel.chatwidget-hidden { opacity: 0; transform: translateY(12px) scale(.98); pointer-events: none; }
  #chatwidget_panel.chatwidget-open { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }

  /* Toggle button subtle pop */
  #chatwidget_toggle { transition: transform 180ms cubic-bezier(.2,.9,.2,1), box-shadow 180ms ease; }
  #chatwidget_toggle.chatwidget-active { transform: scale(1.04); box-shadow: 0 8px 20px rgba(11,27,43,0.18); }

  /* Message-entry animation (applied via JS) */
  @keyframes chat-in { from { opacity: 0; transform: translateY(8px) scale(.995); } to { opacity: 1; transform: translateY(0) scale(1); } }
  .chatmsg-anim { animation: chat-in 260ms cubic-bezier(.2,.9,.2,1) both; }

  /* Composer subtle elevation when panel opens */
  #chatwidget_panel.chatwidget-open .composer { box-shadow: 0 -10px 18px rgba(11,27,43,0.04); transition: box-shadow 260ms ease; }
</style>

<script>
// Lightweight enhancement to gracefully animate open/close and message entries
(function(){
  try {
    const toggle = document.getElementById('chatwidget_toggle');
    const panel = document.getElementById('chatwidget_panel');
    const closeBtn = document.getElementById('chatwidget_close');
    const messagesEl = document.getElementById('chatwidget_messages') || document.getElementById('messages');

    if (!panel || !toggle) return;

    // Initialize hidden state if panel is not visible
    if (panel.style.display === 'none' || panel.getAttribute('aria-hidden') === 'true') {
      panel.classList.add('chatwidget-hidden');
    }

    function computeOrigin() {
      try {
        const t = toggle.getBoundingClientRect();
        const p = panel.getBoundingClientRect();
        const cx = t.left + t.width / 2;
        const cy = t.top + t.height / 2;
        const ox = p.width > 0 ? ((cx - p.left) / p.width) * 100 : 50;
        const oy = p.height > 0 ? ((cy - p.top) / p.height) * 100 : 100;
        return { ox: Math.max(0, Math.min(100, ox)), oy: Math.max(0, Math.min(100, oy)) };
      } catch (e) { return { ox: 50, oy: 100 }; }
    }

    function openPanel() {
      // compute origin to make scale appear to come from toggle center
      const o = computeOrigin();
      panel.style.transformOrigin = o.ox + '% ' + o.oy + '%';
      panel.classList.remove('chatwidget-hidden');
      // set starting small scale at the origin, then grow to full size
      panel.style.transition = 'transform 320ms cubic-bezier(.2,.9,.2,1), opacity 220ms ease, box-shadow 260ms ease';
      panel.style.transform = 'translateY(12px) scale(0.28)';
      // force layout then animate to final
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          panel.classList.add('chatwidget-open');
          panel.style.transform = 'translateY(0px) scale(1)';
        });
      });
      toggle.classList.add('chatwidget-active');
      panel.setAttribute('aria-hidden','false');
      // clear inline transform after complete to allow CSS class control
      const onEnd = (e) => {
        if (e && e.propertyName && e.propertyName !== 'transform') return;
        panel.style.transform = '';
        panel.style.transition = '';
        panel.removeEventListener('transitionend', onEnd);
      };
      panel.addEventListener('transitionend', onEnd);
    }

    function closePanel() {
      const o = computeOrigin();
      panel.style.transformOrigin = o.ox + '% ' + o.oy + '%';
      // animate shrinking back to toggle
      panel.style.transition = 'transform 260ms cubic-bezier(.2,.9,.2,1), opacity 180ms ease, box-shadow 200ms ease';
      panel.style.transform = 'translateY(12px) scale(0.28)';
      panel.classList.remove('chatwidget-open');
      toggle.classList.remove('chatwidget-active');
      panel.setAttribute('aria-hidden','true');
      // after animation hide and reset
      const onEndClose = (e) => {
        if (e && e.propertyName && e.propertyName !== 'transform') return;
        panel.classList.add('chatwidget-hidden');
        panel.style.transform = '';
        panel.style.transition = '';
        panel.removeEventListener('transitionend', onEndClose);
      };
      panel.addEventListener('transitionend', onEndClose);
    }

    // Hook into existing controls without replacing their handlers
    toggle.addEventListener('click', (e) => {
      // if other code controls visibility, mirror it
      if (panel.classList.contains('chatwidget-open')) closePanel(); else openPanel();
    });
    if (closeBtn) closeBtn.addEventListener('click', closePanel);

    // Animate new messages using MutationObserver
    if (messagesEl && window.MutationObserver) {
      const mo = new MutationObserver(muts => {
        muts.forEach(m => {
          m.addedNodes && m.addedNodes.forEach(n => {
            if (!(n instanceof Element)) return;
            // apply animation class; remove after animation end
            n.classList.add('chatmsg-anim');
            n.addEventListener('animationend', () => { n.classList.remove('chatmsg-anim'); }, { once: true });
          });
        });
      });
      mo.observe(messagesEl, { childList: true, subtree: false });
    }
  } catch (e) { console.error('chat widget animation init failed', e); }
})();
</script>
<script>
// Hide composer when no room selected and keep preview visible above composer
(function(){
  try {
    setTimeout(() => {
      const inst = window.chatApps && window.chatApps['chatwidget'];
      const composer = document.querySelector('#chatwidget_panel .composer');
      const preview = document.getElementById('chatwidget_attachPreviewColumn');
      // compute and apply bottom offset so preview sticks to composer
      function applyPreviewOffset() {
        try {
          if (!preview) return;
          const comp = document.querySelector('#chatwidget_panel .composer');
          // default gap between preview and composer
          const gap = 8;
          if (comp && window.getComputedStyle(comp).display !== 'none') {
            const ch = comp.getBoundingClientRect().height || 0;
            preview.style.bottom = (ch + gap) + 'px';
          } else {
            preview.style.bottom = (8) + 'px';
          }
        } catch(e) { console.warn('applyPreviewOffset failed', e); }
      }
      // observe composer size changes and preview content changes
      const moTargets = [];
      try {
        if (preview) {
          const m = new MutationObserver(() => { applyPreviewOffset(); });
          m.observe(preview, { childList: true, subtree: true, characterData: true });
          moTargets.push(m);
        }
        if (composer) {
          const m2 = new MutationObserver(() => { applyPreviewOffset(); });
          m2.observe(composer, { attributes: true, childList: true, subtree: true });
          moTargets.push(m2);
        }
      } catch(e){}
      // recalc on resize
      window.addEventListener('resize', applyPreviewOffset);
      // initial apply and periodic safeguard
      applyPreviewOffset();
      const safeIv = setInterval(applyPreviewOffset, 800);
      function update() {
        try {
          const roomId = inst && typeof inst.getSelectedRoomId === 'function' ? inst.getSelectedRoomId() : (document.getElementById('chatwidget_messages') && document.getElementById('chatwidget_messages').dataset && document.getElementById('chatwidget_messages').dataset.roomId);
          const panelEl = document.getElementById('chatwidget_panel');
                    if (!roomId) {
            if (composer) composer.style.display = 'none';
            if (preview) { preview.style.display = preview.innerHTML.trim() ? '' : 'none'; preview.style.zIndex = 10005; }
          } else {
            if (composer) composer.style.display = '';
            if (preview) { preview.style.display = preview.innerHTML.trim() ? '' : 'none'; preview.style.zIndex = 10005; }
    }
          try { if (panelEl && preview) { if (preview.innerHTML.trim()) panelEl.classList.add('preview-visible'); else panelEl.classList.remove('preview-visible'); } } catch(e) {}
        } catch(e){}
      }
      update();
      const iv = setInterval(update, 500);
      window.addEventListener('beforeunload', () => clearInterval(iv));
      window.addEventListener('beforeunload', () => clearInterval(safeIv));
    }, 300);
  } catch(e){}
})();
</script>
<!-- Chat chooser modal -->
<style>
.chat-chooser-backdrop{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.35);display:none;align-items:center;justify-content:center;z-index:100020}
.chat-chooser{background:#fff;border-radius:8px;padding:12px;width:420px;max-width:94vw;box-shadow:0 8px 30px rgba(11,27,43,0.2)}
.chat-chooser .list{max-height:300px;overflow:auto;margin-top:8px}
.chat-chooser .item{display:flex;align-items:center;gap:8px;padding:8px;border-radius:6px;cursor:pointer}
.chat-chooser .item:hover{background:#f7f9fc}
.chat-chooser .avatar{width:36px;height:36px;border-radius:50%;background:#ddd;display:inline-block;flex:0 0 36px}
.chat-chooser .title{font-weight:600}
.chat-chooser .subtitle{font-size:0.85rem;color:#666}
.chat-chooser .section-title{font-size:0.9rem;font-weight:600;margin-top:8px}
</style>

<div id="chatwidget_chooser_backdrop" class="chat-chooser-backdrop" role="dialog" aria-hidden="true">
  <div class="chat-chooser" role="document" aria-label="Choose a user to chat with">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <div style="font-weight:700">Start a chat</div>
      <div><button id="chatwidget_chooser_close" class="btn btn-sm btn-light">Close</button></div>
    </div>
    <div id="chatwidget_chooser_status" class="small text-muted">Recommended users appear first</div>
    <div id="chatwidget_chooser_list" class="list">
      <!-- sections appended here -->
    </div>
  </div>
</div>

<script>
(function(){
  const newBtn = document.getElementById('chatwidget_new');
  const chooser = document.getElementById('chatwidget_chooser_backdrop');
  const chooserList = document.getElementById('chatwidget_chooser_list');
  const chooserClose = document.getElementById('chatwidget_chooser_close');
  const status = document.getElementById('chatwidget_chooser_status');

  function showChooser(){ chooser.style.display='flex'; chooser.setAttribute('aria-hidden','false'); chooserList.innerHTML=''; status.textContent='Loading...'; fetchSuggestions(); }
  function hideChooser(){ chooser.style.display='none'; chooser.setAttribute('aria-hidden','true'); }

  newBtn && newBtn.addEventListener('click', showChooser);
  chooserClose && chooserClose.addEventListener('click', hideChooser);
  chooser.addEventListener('click', (e)=>{ if (e.target === chooser) hideChooser(); });

  async function fetchSuggestions(){
    try {
      const res = await fetch(<?= json_encode($SUGGESTIONS_API) ?>);
      const data = await res.json();
      if (data.error) { status.textContent = 'Error: ' + data.error; return; }
      status.textContent = '';
      // build sections
      if (data.recommended && data.recommended.length){
        const h = document.createElement('div'); h.className='section-title'; h.textContent='Recommended'; chooserList.appendChild(h);
        data.recommended.forEach(u => chooserList.appendChild(renderUserItem(u, true)));
      }
      if (data.others && data.others.length){
        const h2 = document.createElement('div'); h2.className='section-title'; h2.textContent='Users you may like'; chooserList.appendChild(h2);
        data.others.forEach(u => chooserList.appendChild(renderUserItem(u, false)));
      }
      if ((!data.recommended || !data.recommended.length) && (!data.others || !data.others.length)){
        chooserList.textContent = 'No users found.';
      }
    } catch (e) {
      status.textContent = 'Failed to load suggestions';
      console.error(e);
    }
  }

  function renderUserItem(u, recommended){
    const el = document.createElement('div'); el.className='item'; el.tabIndex=0;
    const av = document.createElement('div'); av.className='avatar'; if (u.avatar) av.style.backgroundImage = 'url('+u.avatar+')', av.style.backgroundSize='cover';
    const meta = document.createElement('div'); meta.style.flex='1';
    const name = document.createElement('div'); name.className='title'; name.textContent = u.name || ('User '+u.id);
    const role = document.createElement('div'); role.className='subtitle'; role.textContent = u.role || '';
    meta.appendChild(name); meta.appendChild(role);
    const action = document.createElement('div'); action.innerHTML = '<button class="btn btn-sm btn-primary">Chat</button>';
    el.appendChild(av); el.appendChild(meta); el.appendChild(action);
    el.addEventListener('click', ()=> openChatWith(u));
    return el;
  }

  async function openChatWith(u){
    try {
      // Prefer window.handleChat if available (Chatfunction.js or app provides it)
      if (window.handleChat) {
        const res = await window.handleChat(u.id, { otherName: u.name });
        if (res && (res.ok || res.roomId)) {
          hideChooser();
          // open widget and focus room if chat app instance exists
          const inst = window.chatApps && window.chatApps['chatwidget'];
          if (inst && inst.openRoom) inst.openRoom(res.roomId || res.roomId);
          // ensure widget visible
          document.getElementById('chatwidget_panel').style.display='flex'; document.getElementById('chatwidget_toggle').style.display='none';
          return;
        }
      }
      // fallback: try to call ChatApi createRoom if available
      const apiPath = <?= json_encode($CHAT_API_PATH) ?>;
      const createUrl = apiPath + 'createRoom';
      const payload = { other_id: u.id };
      const r = await fetch(createUrl, { method:'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const jr = await r.json();
      if (jr && (jr.ok || jr.roomId)){
        hideChooser();
        const inst = window.chatApps && window.chatApps['chatwidget'];
        if (inst && inst.openRoom) inst.openRoom(jr.roomId || jr.roomId);
        document.getElementById('chatwidget_panel').style.display='flex'; document.getElementById('chatwidget_toggle').style.display='none';
        return;
      }
      alert('Unable to open chat with that user.');
    } catch (e) { console.error(e); alert('Failed to open chat.'); }
  }
})();
</script>
<!-- Initialize chat widget. Chatfunction and API paths are centralized here -->
<script src="<?= htmlspecialchars($CHAT_JS_SRC) ?>"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    // Wire attachment preview for the widget
    try {
      const attachBtn = document.getElementById('chatwidget_attach');
      const attachInput = document.getElementById('chatwidget_attachInput');
      const preview = document.getElementById('chatwidget_attachPreviewColumn');
      const sendBtn = document.getElementById('chatwidget_send');
      let previewPendingShare = null;

      function clearPreview(){
        if (!attachInput) return;
        attachInput.value = '';
        if (preview) preview.innerHTML = '';
      }

      if (attachBtn && attachInput) {
        // Do NOT rebind the click to avoid opening the file dialog twice (Chatfunction.js already wires this).
        // Only listen for `change` to render the preview and do not stop propagation so the upload handler runs.
        let attachChangeGuard = false;
        attachInput.addEventListener('change', function(e){
          try {
            if (attachChangeGuard) return; attachChangeGuard = true; setTimeout(()=>attachChangeGuard=false, 600);
            if (!preview) return;
            preview.innerHTML = '';
            const file = this.files && this.files[0];
            if (!file) return;

            // Thumbnail for images, filename+icon for others
            if (file.type && file.type.startsWith('image/')){
              const img = document.createElement('img');
              img.src = URL.createObjectURL(file);
              img.style.maxWidth = '100px'; img.style.maxHeight = '60px'; img.style.objectFit = 'cover'; img.alt = file.name;
              img.addEventListener('load', () => URL.revokeObjectURL(img.src));
              preview.appendChild(img);
            } else {
              const icon = document.createElement('i');
              icon.className = 'bi bi-file-earmark';
              icon.style.fontSize = '20px'; icon.style.marginRight = '6px';
              preview.appendChild(icon);
              const span = document.createElement('span');
              span.textContent = file.name;
              span.style.fontSize = '12px';
              preview.appendChild(span);
            }

            // remove button
            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'btn btn-sm btn-link'; rm.title = 'Remove'; rm.style.marginLeft = '8px';
            rm.textContent = '✕';
            rm.addEventListener('click', function(){ clearPreview(); });
            preview.appendChild(rm);

            // do not prevent other listeners from running; Chatfunction.js will handle the upload
            console.debug('chatwidget: preview set for', file.name);
          } catch (ex) { console.error('attach change handler error', ex); }
        });
      }

      // After send, clear preview (send handling lives in Chatfunction.js so we do a best-effort clear)
      if (sendBtn) sendBtn.addEventListener('click', function(){ setTimeout(clearPreview, 200); });
    } catch (e) { console.error('chatwidget preview init error', e); }


    // rootId 'chatwidget' maps to IDs like 'chatwidget_messages', 'chatwidget_input' etc.
    try {
      if (typeof initApp === 'function') {
        window.chatApps = window.chatApps || {};
        window.chatApps['chatwidget'] = initApp({ apiPath: <?= json_encode($CHAT_API_PATH) ?>, userType: <?= json_encode($role) ?>, userId: <?= json_encode($uid) ?>, userName: <?= json_encode($_SESSION['user']['name'] ?? '') ?>, rootId: 'chatwidget', items: [] });
      }
    } catch(e) { console.error('chatwidget initApp failed', e); }
    // After init, wire the share button if a page provided a payload
    try {
      const tryWireShare = () => {
        const inst = window.chatApps && window.chatApps['chatwidget'];
        const shareBtn = document.getElementById('chatwidget_share');
        if (!shareBtn) return;
        // Prefer server-provided payload when this file is included from a page
        <?php if (isset($CHAT_SHARE) && is_array($CHAT_SHARE)): ?>
        const payload = <?= json_encode($CHAT_SHARE) ?>;
        <?php else: ?>
        const payload = window.__chat_share_payload || null;
        <?php endif; ?>
        // Always show the share button so users can open the share chooser on any page.
        shareBtn.style.display = '';
        // If no page-specific payload was provided, leave the default share chooser behavior
        // (the separate share modal script wires `shareBtn` to open the chooser). Only
        // attach the special quick-share handler when a payload exists.
        if (!payload) { return; }
        // Prefer the image already rendered on the page (design_detail.php uses `$mainImg`) so
        // the widget preview uses the same resolved src. The DOM `img.src` will be absolute.
        try {
          const pageImg = document.querySelector('.design-image-wrapper img');
          if (pageImg && pageImg.src) {
            payload.image = pageImg.src;
          }
        } catch (e) {}
        // Avoid adding multiple handlers
        if (!shareBtn.dataset.shareHandler) {
          shareBtn.addEventListener('click', async () => {
          try {
            // create or open room with designer
            const res = await (window.handleChat ? window.handleChat(payload.designerId, { creatorId: <?= json_encode($uid) ?>, otherName: payload.title }) : Promise.resolve({ ok: false }));
            const roomId = res && res.roomId;
            const contentHtml = `<div>Client is interested in this design: <a href="${payload.url}" target="_blank" rel="noopener">${payload.title}</a></div>`;
            if (!roomId) {
              alert('Unable to open chat to share design.');
              return;
            }
            // Show preview in widget preview column and require confirmation before sending
            const preview = document.getElementById('chatwidget_attachPreviewColumn');
            if (!preview) {
              // fallback to immediate send if preview area missing
              const attachmentUrl = payload.image || payload.url || null;
              const attachmentName = payload.title ? (payload.title + '.jpg') : (attachmentUrl ? (attachmentUrl.split('/').pop() || 'design') : 'design');
              const resp = await inst.apiPost('sendMessage', { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: payload.url || payload.title || '', room: roomId, attachment_url: attachmentUrl, attachment_name: attachmentName, message_type: 'design', share_title: payload.title || '', share_url: payload.url || '' });
              try { if (resp && (resp.ok || resp.message)) inst.appendMessageToUI(resp.message || resp, 'me'); } catch(e){}
              return;
            }

            preview.style.display = '';
            preview.innerHTML = '';
            // thumbnail or link
            const imgWrap = document.createElement('div');
            imgWrap.style.display = 'flex'; imgWrap.style.gap = '12px'; imgWrap.style.alignItems = 'center';
            if (payload.image) {
              const img = document.createElement('img'); img.src = payload.image; img.alt = payload.title; img.style.maxWidth = '120px'; img.style.maxHeight = '90px'; img.style.borderRadius = '6px'; imgWrap.appendChild(img);
            } else {
              const badge = document.createElement('div'); badge.className = 'file-badge'; badge.textContent = 'IMG'; imgWrap.appendChild(badge);
            }
            const meta = document.createElement('div'); meta.className = 'file-meta';
            const name = document.createElement('div'); name.className = 'name'; name.textContent = payload.title || 'Design';
            const link = document.createElement('a'); link.href = payload.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Open design page'; link.className = 'small text-muted';
            meta.appendChild(name); meta.appendChild(link);
            imgWrap.appendChild(meta);
            preview.appendChild(imgWrap);

            // Prepare pending share object and show Cancel button; main Send will post it
            previewPendingShare = {
              roomId: roomId,
              attachmentUrl: payload.image || payload.url || null,
              attachmentName: payload.title ? (payload.title + '.jpg') : (payload.image || payload.url || '').split('/').pop() || 'design',
              share_title: payload.title || '',
              share_url: payload.url || ''
            };

            const actions = document.createElement('div'); actions.style.marginTop = '8px';
            const cancelBtn = document.createElement('button'); cancelBtn.type = 'button'; cancelBtn.className = 'btn btn-secondary btn-sm'; cancelBtn.textContent = 'Cancel';
            actions.appendChild(cancelBtn);
            preview.appendChild(actions);

            const cleanup = () => { try { preview.innerHTML = ''; preview.style.display = 'none'; previewPendingShare = null; } catch(e){} };

            cancelBtn.addEventListener('click', () => { cleanup(); });

            // Hook main send button (capture) to send the pending share when user clicks Send
            const mainSendBtn = document.getElementById('chatwidget_send');
            if (mainSendBtn) {
              // ensure we don't add duplicate handlers
              if (!mainSendBtn._shareSendHandler) {
                const sendCapture = async function(e){
                  if (!previewPendingShare) return; // allow normal send
                  e.preventDefault(); e.stopImmediatePropagation();
                  try {
                    const p = previewPendingShare;
                    const payload = { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: p.share_url || p.attachmentName || '', room: p.roomId, attachment_url: p.attachmentUrl, attachment_name: p.attachmentName, message_type: 'design', share_title: p.share_title, share_url: p.share_url, share_type: 'design', text: (document.getElementById('chatwidget_share_preview_text') ? document.getElementById('chatwidget_share_preview_text').value : '') };
                    const resp = (inst && typeof inst.apiPost === 'function') ? await inst.apiPost('sendMessage', payload) : await (fetch(<?= json_encode($CHAT_API_PATH) ?> + 'sendMessage', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r=>r.json()));
                    if (resp && (resp.ok || resp.message || resp.id)) {
                      try { if (inst && inst.appendMessageToUI) inst.appendMessageToUI(resp.message || resp, 'me'); } catch(e){}
                    } else {
                      console.warn('share send failed response', resp);
                    }
                  } catch (ex) { console.error('share send failed', ex); }
                  cleanup();
                  return false;
                };
                mainSendBtn._shareSendHandler = sendCapture;
                mainSendBtn.addEventListener('click', sendCapture, true);
              }
            }

            // focus on preview for accessibility
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
          } catch (e) { console.error('chatwidget share error', e); alert('Error sharing design.'); }
          });
          shareBtn.dataset.shareHandler = '1';
        }
      };
      // wait briefly for initApp to register instance
      setTimeout(tryWireShare, 200);
    } catch(e) { console.error('share wiring failed', e); }
  });
</script>
<!-- Share chooser modal (liked designs grid) -->
<style>
.chat-share-backdrop{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.35);display:none;align-items:center;justify-content:center;z-index:100030}
.chat-share{background:#fff;border-radius:8px;padding:12px;width:640px;max-width:96vw;box-shadow:0 8px 40px rgba(11,27,43,0.25)}
.chat-share .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;max-height:50vh;overflow:auto}
.chat-share .card{border:1px solid #eef3fb;border-radius:8px;padding:8px;display:flex;flex-direction:column;gap:6px;cursor:pointer}
.chat-share .thumb{width:100%;height:96px;background:#eee;border-radius:6px;background-size:cover;background-position:center}
.chat-share .meta{font-size:0.9rem}
.chat-share .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
@media (max-width:640px){ .chat-share .grid{grid-template-columns:repeat(2,1fr)} }
</style>

<div id="chatwidget_share_backdrop" class="chat-share-backdrop" role="dialog" aria-hidden="true">
  <div class="chat-share" role="document" aria-label="Share">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:700">Share</div>
      <div><button id="chatwidget_share_close" class="btn btn-sm btn-light">Close</button></div>
    </div>
    <div id="chatwidget_share_status" class="small text-muted">Your liked designs</div>
    <div id="chatwidget_share_grid" class="grid" style="margin-top:8px"></div>
    <button id="chatwidget_share_send" class="btn btn-primary ms-2" disabled>Send Selected</button></div>
  </div>
</div>

<script>
(function(){
  const shareBtn = document.getElementById('chatwidget_share');
  const backdrop = document.getElementById('chatwidget_share_backdrop');
  const grid = document.getElementById('chatwidget_share_grid');
  const status = document.getElementById('chatwidget_share_status');
  const closeBtn = document.getElementById('chatwidget_share_close');
  const sendBtn = document.getElementById('chatwidget_share_send');
  let selectedDesign = null;
  let selectedDesigns = [];
  let currentShareList = [];

  function showShare(){ backdrop.style.display='flex'; backdrop.setAttribute('aria-hidden','false'); loadLikedDesigns(); }
  function hideShare(){ backdrop.style.display='none'; backdrop.setAttribute('aria-hidden','true'); grid.innerHTML=''; selectedDesign=null; sendBtn.disabled=true; }
  shareBtn && shareBtn.addEventListener('click', showShare);
  closeBtn && closeBtn.addEventListener('click', hideShare);
  backdrop && backdrop.addEventListener('click', (e)=>{ if (e.target===backdrop) hideShare(); });

  async function loadLikedDesigns(){
    try {
      status.textContent = 'Loading...';
      grid.innerHTML = '';
      const res = await fetch(<?= json_encode($SUGGESTIONS_API) ?>);
      const j = await res.json();
      console.debug('chatwidget: suggestions response', j);
      if (j && j.error) {
        status.textContent = (j.error === 'not_logged_in') ? 'Please log in to see your liked designs.' : ('Error: ' + j.error);
        return;
      }
      // normalize liked designs and products into a single list of items
      let list = [];
      if (j && j.liked_designs && j.liked_designs.length) {
        j.liked_designs.forEach(d => {
          list.push({ id: d.designid || d.designId || d.id, type: 'design', title: d.title || '', price: d.price || null, likes: d.likes || 0, image: d.image || null, url: d.url || null, ownerid: d.designerid || d.designerId || 0 });
        });
      } else if (j && j.recommended_designs && j.recommended_designs.length) {
        j.recommended_designs.forEach(d => {
          list.push({ id: d.designid || d.designId || d.id, type: 'design', title: d.title || '', price: d.price || null, likes: d.likes || 0, image: d.image || null, url: d.url || null, ownerid: d.designerid || d.designerId || 0 });
        });
      }
      // append liked products if any
      if (j && j.liked_products && j.liked_products.length) {
        j.liked_products.forEach(p => {
          list.push({ id: p.productid || p.productId || p.id, type: 'product', title: p.title || p.pname || '', price: p.price || null, likes: p.likes || 0, image: p.image || null, url: p.url || null, ownerid: p.supplierid || p.supplierId || 0 });
        });
      }
      if (!list.length) { status.textContent='liked item will display here.'; return; }
      status.textContent='liked item will display here. Click to select.';
      // build cards
      const base = ((location.protocol==='https:')? 'https:' : 'http:') + '//' + (location.host || '');
      // store current list for bulk actions
      currentShareList = list.slice();
      list.forEach(d => {
        const c = document.createElement('div'); c.className='card'; c.tabIndex=0; c.dataset.itemId = d.id || '';
        c.dataset.itemType = d.type || 'design';
        const thumb = document.createElement('div'); thumb.className='thumb';
        // try to use image URL if available, else a placeholder
        const img = (d.image) ? d.image : (d.url ? d.url : null);
        if (img) thumb.style.backgroundImage = 'url("' + img + '")';
        const title = document.createElement('div'); title.className='meta'; title.textContent = d.title || (d.type === 'product' ? ('Product #' + (d.id||'')) : ('Design #' + (d.id||'')));
        const sub = document.createElement('div'); sub.className='small text-muted'; sub.textContent = (d.likes? d.likes + ' likes' : '') + (d.price ? ' · HK$' + d.price : '');
        c.appendChild(thumb); c.appendChild(title); c.appendChild(sub);
        c.addEventListener('click', ()=>{ toggleCardSelection(c,d); });
        grid.appendChild(c);
      });
    } catch (e) { console.error('load liked designs failed', e); status.textContent='Failed to load designs'; }
  }

  function toggleCardSelection(card, data){
    const id = data.id || data.designid || data.productid || data.designId || data.productId || data.id;
    const idx = selectedDesigns.findIndex(s => (s.designid||s.designId||s.id) == id);
    if (idx === -1) {
      // select
      card.style.outline = '2px solid #5b8cff';
      selectedDesigns.push(data);
    } else {
      // deselect
      card.style.outline = '';
      selectedDesigns.splice(idx,1);
    }
    // set single-selection convenience
    selectedDesign = selectedDesigns.length === 1 ? selectedDesigns[0] : null;
    sendBtn.disabled = selectedDesigns.length === 0;
  }

  // select all visible cards
  function selectAllCards(){
    selectedDesigns = [];
    Array.from(grid.children).forEach((ch, i) => {
      ch.style.outline = '2px solid #5b8cff';
      const d = currentShareList[i]; if (d) selectedDesigns.push(d);
    });
    selectedDesign = selectedDesigns.length === 1 ? selectedDesigns[0] : null;
    sendBtn.disabled = selectedDesigns.length === 0;
  }

  sendBtn && sendBtn.addEventListener('click', async ()=>{
    if (!selectedDesigns || !selectedDesigns.length) return;
    try {
      const inst = window.chatApps && window.chatApps['chatwidget'];
      // If a room is open in the widget, send all selected into that room
      let roomId = null;
      if (inst) {
        try { roomId = (typeof inst.getSelectedRoomId === 'function') ? inst.getSelectedRoomId() : (inst.currentRoom || inst.currentRoomId); } catch(e) { roomId = (inst.currentRoom || inst.currentRoomId); }
        // fallback to messages dataset
        if (!roomId && document.getElementById('chatwidget_messages') && document.getElementById('chatwidget_messages').dataset) roomId = document.getElementById('chatwidget_messages').dataset.roomId;
      }
      if (roomId) {
        // send selected items sequentially (one message per item) without per-item modal
        try {
          sendBtn.disabled = true;
          const total = selectedDesigns.length; let sent = 0; let failed = 0;
          const statusEl = document.getElementById('chatwidget_share_status');
          if (statusEl) statusEl.textContent = `Sending 0 / ${total}...`;
          for (const sd of selectedDesigns) {
            const payload = {
              sender_type: 'client', sender_id: <?= json_encode($uid) ?>,
              content: sd.url || sd.title || '', room: roomId,
              attachment_url: sd.image || sd.url || '', attachment_name: (sd.title||sd.type||'item') + '.jpg',
              message_type: 'design', share_title: sd.title || '', share_url: sd.url || '', share_type: sd.type === 'product' ? 'product' : 'design'
            };
            try {
              const resp = (inst && typeof inst.apiPost === 'function') ? await inst.apiPost('sendMessage', payload) : await (fetch(<?= json_encode($CHAT_API_PATH) ?> + 'sendMessage', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r=>r.json()));
              if (resp && (resp.ok || resp.message || resp.id)) {
                try { if (inst && inst.appendMessageToUI) inst.appendMessageToUI(resp.message || resp, 'me'); } catch(e){}
                sent++;
              } else { failed++; }
            } catch(e) { console.error('batch send item failed', e); failed++; }
            if (statusEl) statusEl.textContent = `Sending ${sent} / ${total}...`;
            // yield to UI thread
            await new Promise(res => setTimeout(res, 80));
          }
          if (statusEl) statusEl.textContent = `Done. Sent ${sent}/${total}. ${failed? failed + ' failed':''}`;
        } catch(e) { console.error('batch share error', e); }
        sendBtn.disabled = false; hideShare(); return;
      }
      // No room open: attempt per-designer send using handleChat if available
      if (window.handleChat) {
        for (const sd of selectedDesigns) {
          try {
            const owner = sd.ownerid || sd.designerid || sd.supplierid || sd.ownerId || 0;
            const r = await window.handleChat(owner, { otherName: sd.title });
            const roomId = r && (r.roomId || r.roomid);
            if (roomId) {
              const inst2 = window.chatApps && window.chatApps['chatwidget'];
              if (inst2) {
                const mtype = 'design';
                await inst2.apiPost('sendMessage', { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: sd.url || sd.title || '', room: roomId, attachment_url: sd.image || sd.url || '', attachment_name: (sd.title||sd.type||'item') + '.jpg', message_type: mtype, share_title: sd.title || '', share_url: sd.url || '' });
              }
            }
          } catch(e) { console.error('send to designer failed', e); }
        }
        hideShare();
        return;
      }
      alert('Please open a chat first or enable handleChat to create recipient rooms.');
    } catch (e) { console.error('share send failed', e); alert('Failed to send shared designs.'); }
  });

  // wire select-all button
  const selectAllBtn = document.getElementById('chatwidget_share_select_all');
  if (selectAllBtn) selectAllBtn.addEventListener('click', selectAllCards);
})();
</script>
