<?php
// Floating chat widget — include this PHP where you want the floating chat button
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$logged = isset($_SESSION['user']) && !empty($_SESSION['user']);
$roleRaw = $logged ? trim(strtolower($_SESSION['user']['role'] ?? 'client')) : 'client';
// Get user ID first (before normalizing role for DB)
$uid = $logged ? (int) ($_SESSION['user'][$roleRaw . 'id'] ?? $_SESSION['user']['id'] ?? 0) : 0;
// Normalize role to match DB ENUM: 'Contractors' needs capital C, others lowercase
$role = $roleRaw;
if ($role === 'contractor' || $role === 'contractors') $role = 'Contractors';
// Centralized paths (use absolute project paths so pages don't need to set these)
$CHAT_API_PATH = '/FYP/Public/ChatApi.php?action=';
$CHAT_JS_SRC = '/FYP/Public/Chatfunction.js';
// Suggestions API path (resolve relative to app root)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$parts = explode('/', ltrim($scriptPath, '/'));
$APP_ROOT = isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '';
$SUGGESTIONS_API = $APP_ROOT . '/Public/get_chat_suggestions.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

<?php
  // If not logged in, show button that redirects to login (preserves current page for redirect)
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
  if (!$logged) :
?>
  <a id="chatwidget_toggle" class="btn-link" href="../login.php?redirect=<?php echo $redirect ?>" aria-label="Log in to open chat" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,0.7);color:#fff;">
    <i class="bi bi-chat-dots" aria-hidden="true" style="font-size:22px;color:#ffffff;line-height:1"></i>
  </a>
<?php else: ?>
  <button id="chatwidget_toggle" aria-label="Open chat" style="display:inline-flex;align-items:center;justify-content:center;padding:0;border:0;background:rgba(255,255,255,0.7);">
    <i class="bi bi-chat-dots" aria-hidden="true" style="font-size:22px;color:#8faae3;line-height:1"></i>
  </button>
<?php endif; ?>

<div id="chatwidget_panel" role="dialog" aria-hidden="true" aria-label="Chat widget">
  <div class="header">
    <div>
      <div style="font-weight:600">Message</div>
      <div id="chatwidget_typingIndicator" class="small text-muted"></div>
    </div>
    <div>
      <button id="chatwidget_close" aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
  </div>
  <div id="chatwidget_body" class="body">
    <div class="left">
      <div class="d-flex justify-content-center">
        <button id="chatwidget_new" class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center m-2" type="button" title="New chat" aria-label="Create chat" style="width:44px;height:44px;padding:0;border:0">
          <i class="bi bi-plus-lg" aria-hidden="true" style="font-size:18px;color:#fff;line-height:1"></i>
        </button>
      </div>
      <div id="chatwidget_agentsList" class="list-group mb-2" style="max-height:100%;overflow:auto;"></div>
    </div>
    <div id="chatwidget_divider" role="separator" aria-orientation="vertical" aria-label="Resize chat list"><div class="handle" aria-hidden="true"></div></div>
    <div class="right">
      <div id="chatwidget_Current_Chat" class="d-flex align-items-center justify-content-between m-2" style="gap:8px;display:none" aria-hidden="true">
        <div class="d-flex align-items-center" style="gap:8px">
          <div id="chatwidget_current_avatar" class="rounded-circle" style="width:36px;height:36px;background:#e9edf2;flex:0 0 36px;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;font-weight:600;color:#375a7f">U</div>
          <div>
            <div id="chatwidget_connectionStatus" class="fw-semibold">Select a Chat to start conversation</div>
            <div id="chatwidget_typingIndicator_small" class="small text-muted" style="display:none"></div>
          </div>
        </div>
        <div style="flex:0 0 auto">
          <a id="chatwidget_view_btn" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary" style="display:none">View</a>
        </div>
      </div>
      <div id="chatwidget_messages" class="messages" style="display:none"></div>
           <div class="composer" style="display:none;position:relative;">
        <div id="chatwidget_attachPreviewColumn" class="message-preview-column d-flex justify-content-around">
          <div id="chatwidget_attachPreview" class="message-preview">
              <div id="chatwidget_attachPreview_content" class="message-preview-content d-flex align-items-center"></div>
            </div>
        </div>
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
  <div id="chatwidget_resizer" class="resizer" aria-hidden="true">
    <!-- Four directional transparent edges + corner handles for full-border resizing -->
    <div class="edge edge-top" aria-hidden="true" style="position:absolute;left:0;right:0;top:0;height:8px;cursor:n-resize;background:transparent"></div>
    <div class="edge edge-right" aria-hidden="true" style="position:absolute;top:0;bottom:0;right:0;width:8px;cursor:e-resize;background:transparent"></div>
    <div class="edge edge-bottom" aria-hidden="true" style="position:absolute;left:0;right:0;bottom:0;height:8px;cursor:s-resize;background:transparent"></div>
    <div class="edge edge-left" aria-hidden="true" style="position:absolute;top:0;bottom:0;left:0;width:8px;cursor:w-resize;background:transparent"></div>
    <!-- Corner handles (slightly larger hit area) -->
    <div class="edge edge-top-left" aria-hidden="true" style="position:absolute;left:0;top:0;width:12px;height:12px;cursor:nwse-resize;background:transparent"></div>
    <div class="edge edge-top-right" aria-hidden="true" style="position:absolute;right:0;top:0;width:12px;height:12px;cursor:nesw-resize;background:transparent"></div>
    <div class="edge edge-bottom-left" aria-hidden="true" style="position:absolute;left:0;bottom:0;width:12px;height:12px;cursor:nesw-resize;background:transparent"></div>
    <div class="edge edge-bottom-right" aria-hidden="true" style="position:absolute;right:0;bottom:0;width:12px;height:12px;cursor:nwse-resize;background:transparent"></div>
  </div>
</div>

<link rel="stylesheet" href="/FYP/css/chat.css">
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
          content: (it.designid || it.id) ? (it.designid || it.id) : (it.url || it.title || ''),
          room: roomId,
          attachment_url: it.image || it.url || '',
          attachment_name: (it.title||it.type||'item') + '.jpg',
              message_type: 'design',
              design_id: (it.designid || it.id || null),
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
    let resizing = false, sx = 0, sy = 0, sw = 0, sh = 0, origLeft = 0, origTop = 0;
    let resizeDir = { left: false, right: false, top: false, bottom: false };

    function detectEdge(target) {
      if (!target) return null;
      const edge = target.closest && target.closest('.edge');
      return edge || null;
    }

    function onResizePointerDown(e) {
      if (e.button && e.button !== 0) return;
      const edge = detectEdge(e.target);
      if (!edge) return; // only start when dragging an edge
      e.preventDefault();
      resizing = true;
      sx = e.clientX; sy = e.clientY;
      const rect = panel.getBoundingClientRect(); sw = rect.width; sh = rect.height; origLeft = rect.left; origTop = rect.top;
      // determine which directions to resize
      resizeDir = { left: false, right: false, top: false, bottom: false };
      if (edge.classList.contains('edge-right')) resizeDir.right = true;
      if (edge.classList.contains('edge-left')) resizeDir.left = true;
      if (edge.classList.contains('edge-top')) resizeDir.top = true;
      if (edge.classList.contains('edge-bottom')) resizeDir.bottom = true;
      // legacy single-corner class (kept for compatibility)
      if (edge.classList.contains('edge-corner')) { resizeDir.right = true; resizeDir.bottom = true; }
      // explicit corners
      if (edge.classList.contains('edge-top-right')) { resizeDir.top = true; resizeDir.right = true; }
      if (edge.classList.contains('edge-top-left')) { resizeDir.top = true; resizeDir.left = true; }
      if (edge.classList.contains('edge-bottom-right')) { resizeDir.bottom = true; resizeDir.right = true; }
      if (edge.classList.contains('edge-bottom-left')) { resizeDir.bottom = true; resizeDir.left = true; }
      try { if (edge.setPointerCapture) edge.setPointerCapture(e.pointerId); else if (resizer.setPointerCapture) resizer.setPointerCapture(e.pointerId); } catch (ex) {}
      document.addEventListener('pointermove', onResizePointerMove);
      document.addEventListener('pointerup', onResizePointerUp);
    }

    function onResizePointerMove(e) {
      if (!resizing) return;
      const dx = e.clientX - sx; const dy = e.clientY - sy;
      const minW = 377, minH = 255;
      const maxW = Math.max(minW, window.innerWidth - 16);
      const maxH = Math.max(minH, window.innerHeight - 16);

      let newW = sw, newH = sh, newLeft = origLeft, newTop = origTop;
      if (resizeDir.right) newW = Math.max(minW, Math.min(sw + dx, maxW));
      if (resizeDir.bottom) newH = Math.max(minH, Math.min(sh + dy, maxH));
      if (resizeDir.left) {
        newW = Math.max(minW, Math.min(sw - dx, maxW));
        newLeft = origLeft + dx;
        // constrain left so panel stays within viewport
        newLeft = Math.max(8, Math.min(newLeft, window.innerWidth - newW - 8));
      }
      if (resizeDir.top) {
        newH = Math.max(minH, Math.min(sh - dy, maxH));
        newTop = origTop + dy;
        newTop = Math.max(8, Math.min(newTop, window.innerHeight - newH - 8));
      }

      panel.style.width = newW + 'px';
      panel.style.height = newH + 'px';
      panel.style.left = newLeft + 'px';
      panel.style.top = newTop + 'px';
      panel.style.right = 'auto';
    }

    function onResizePointerUp(e) {
      if (!resizing) return; resizing = false;
      document.removeEventListener('pointermove', onResizePointerMove);
      document.removeEventListener('pointerup', onResizePointerUp);
      const rect = panel.getBoundingClientRect();
      saveSize('chatwidget_panel_size', Math.round(rect.width), Math.round(rect.height), panel);
      try { const edge = detectEdge(e.target); if (edge && edge.releasePointerCapture) edge.releasePointerCapture(e.pointerId); else if (resizer.releasePointerCapture) resizer.releasePointerCapture(e.pointerId); } catch(ex) {}
    }

    // attach pointerdown on the resizer wrapper and let detectEdge decide
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
      // ensure panel is renderable before animating (override CSS display:none)
      panel.style.display = 'flex';
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
        // hide from layout so toggle becomes visible again
        panel.style.display = 'none';
        try { toggle.style.display = 'flex'; } catch(e) {}
        panel.removeEventListener('transitionend', onEndClose);
      };
      panel.addEventListener('transitionend', onEndClose);
    }

    // Expose programmatic open/close hooks so external scripts (e.g., handleChat)
    // can trigger the same animated behavior without manipulating styles directly.
    try {
      window.chatWidgetOpenPanel = openPanel;
      window.chatWidgetClosePanel = closePanel;
    } catch (e) { /* ignore in restricted contexts */ }

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
          const panelEl = document.getElementById('chatwidget_panel');
          // default gap between preview and composer
          const gap = 8;
          if (comp && window.getComputedStyle(comp).display !== 'none' && panelEl) {
            // Let CSS handle horizontal positioning/width/bottom; compute only height
            try {
              panelEl.style.setProperty('--preview-height', Math.round(preview.getBoundingClientRect().height) + 'px');
            } catch(e) { }
          } else if (panelEl) {
            try { panelEl.style.setProperty('--preview-height', '0px'); } catch(e) {}
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
          const headerEl = document.getElementById('chatwidget_Current_Chat');
          // right column / messages area handling
          const rightCol = panelEl ? (panelEl.querySelector('.right') || document.getElementById('chatwidget_right')) : (document.querySelector('.right') || document.getElementById('chatwidget_right'));
          const messagesEl = document.getElementById('chatwidget_messages') || document.getElementById('messages');
          // ensure a persistent placeholder exists to show when no room is selected
          let selectPlaceholder = document.getElementById('chatwidget_select_placeholder');
          if (!selectPlaceholder && panelEl) {
            selectPlaceholder = document.createElement('div');
            selectPlaceholder.id = 'chatwidget_select_placeholder';
            selectPlaceholder.style.display = 'none';
            selectPlaceholder.style.padding = '18px';
            selectPlaceholder.style.flex = '1';
            selectPlaceholder.style.alignItems = 'center';
            selectPlaceholder.style.justifyContent = 'center';
            selectPlaceholder.style.textAlign = 'center';
            selectPlaceholder.style.color = '#666';
            selectPlaceholder.style.fontSize = '14px';
            selectPlaceholder.style.background = 'transparent';
            selectPlaceholder.textContent = 'Select a conversation before starting a chat';
            // try to insert next to right column if available, otherwise append to panel
            try {
              if (rightCol && rightCol.parentNode) rightCol.parentNode.insertBefore(selectPlaceholder, rightCol.nextSibling);
              else panelEl.appendChild(selectPlaceholder);
            } catch(e) { panelEl.appendChild(selectPlaceholder); }
          }
                        if (!roomId) {
                        if (composer) composer.style.display = 'none';
                        if (headerEl) { headerEl.classList.remove('chat-visible'); headerEl.style.display = 'none'; }
                      if (preview) {
                        const holder = preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content');
                        const hasContent = holder && holder.innerHTML && holder.innerHTML.trim() ? true : false;
                        // Use the content-holder to decide visibility so static controls don't trigger the preview
                        preview.style.display = hasContent ? '' : 'none';
                        // keep z-index low while hidden; composer overlay will read --preview-height
                        preview.style.zIndex = hasContent ? 10090 : 10005;
                        // trigger reflow/height recalc for composer overlay
                        try { applyPreviewOffset(); } catch(e) {}
                      }
                      // hide right column messages and show select-placeholder
                      try {
                        if (messagesEl) messagesEl.style.display = 'none';
                        if (rightCol) rightCol.style.display = 'none';
                        if (selectPlaceholder) selectPlaceholder.style.display = 'flex';
                      } catch(e) {}
            // set avatar to user's initial when no room selected
            try {
              const avatarEl = document.getElementById('chatwidget_current_avatar');
              if (avatarEl) {
                const userName = <?= json_encode($_SESSION['user']['name'] ?? '') ?>;
                const initial = (userName && String(userName).trim()) ? String(userName).trim().charAt(0).toUpperCase() : 'U';
                avatarEl.textContent = initial;
                avatarEl.style.backgroundImage = '';
              }
            } catch(e) { }
          } else {
            if (composer) composer.style.display = '';
            if (headerEl) { headerEl.classList.add('chat-visible'); headerEl.style.display = 'flex'; }
            if (preview) {
              const holder = preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content');
              const hasContent = holder && holder.innerHTML && holder.innerHTML.trim() ? true : false;
              preview.style.display = hasContent ? '' : 'none';
              preview.style.zIndex = hasContent ? 10090 : 10005;
              try { applyPreviewOffset(); } catch(e) {}
            }
            // restore right column/messages and hide placeholder
            try {
              if (messagesEl) messagesEl.style.display = '';
              if (rightCol) rightCol.style.display = '';
              if (selectPlaceholder) selectPlaceholder.style.display = 'none';
            } catch(e) {}
            // set avatar to other participant's initial when a room is selected
            try {
              const avatarEl = document.getElementById('chatwidget_current_avatar');
              if (avatarEl) {
                let otherName = null;
                try { otherName = localStorage.getItem('chat_other_name_' + roomId) || null; } catch(e) { otherName = null; }
                if (!otherName && inst && typeof inst.getSelectedRoomName === 'function') {
                  try { otherName = inst.getSelectedRoomName(); } catch(e) { otherName = null; }
                }
                const fallbackUser = <?= json_encode($_SESSION['user']['name'] ?? '') ?>;
                const displayName = (otherName && String(otherName).trim()) ? String(otherName).trim() : (fallbackUser && String(fallbackUser).trim() ? String(fallbackUser).trim() : 'User');
                const initial = displayName.charAt(0).toUpperCase();
                avatarEl.textContent = initial;
                avatarEl.style.backgroundImage = '';
              }
            } catch(e) { }
    }
          try { if (panelEl && preview) { const holder = preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content'); const hasContent = holder && holder.innerHTML && holder.innerHTML.trim() ? true : false; if (hasContent) panelEl.classList.add('preview-visible'); else panelEl.classList.remove('preview-visible'); } } catch(e) {}
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

<div id="chatwidget_chooser_backdrop" class="chat-chooser-backdrop" role="dialog" aria-hidden="true">
  <div class="chat-chooser" role="document" aria-label="Add an user to chat with">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <div style="font-weight:700">Start a chat</div>
      <div><button id="chatwidget_chooser_close" class="btn btn-sm btn-light">Close</button></div>
    </div>
    <div id="chatwidget_chooser_status" class="small text-muted">Showing recommended and other users</div>
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
      // build sections - show both by default
      const hasRecommended = data.recommended && data.recommended.length > 0;
      const hasOthers = data.others && data.others.length > 0;
      
      // Always show Recommended section
      const h1 = document.createElement('div'); h1.className='section-title'; h1.textContent='From content you liked'; chooserList.appendChild(h1);
      if (hasRecommended) {
        data.recommended.forEach(u => chooserList.appendChild(renderUserItem(u, true)));
      } else {
        const empty = document.createElement('div'); empty.style.padding='10px'; empty.style.color='#999'; empty.textContent='No recommendations yet, like some content then come back.'; chooserList.appendChild(empty);
      }
      
      // Always show Users you may like section
      const h2 = document.createElement('div'); h2.className='section-title'; h2.textContent='Users you may like'; chooserList.appendChild(h2);
      if (hasOthers) {
        data.others.forEach(u => chooserList.appendChild(renderUserItem(u, false)));
      } else {
        const empty = document.createElement('div'); empty.style.padding='10px'; empty.style.color='#999'; empty.textContent='No users available'; chooserList.appendChild(empty);
      }
    } catch (e) {
      status.textContent = 'Failed to load suggestions. Please check your connection and try again.';
      console.error('fetchSuggestions error:', e);
      // Show a user-friendly message in the list
      chooserList.innerHTML = '<div style="padding:20px;text-align:center;color:#999;"><i class="bi bi-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:10px;"></i><p>Unable to load users</p><p class="small">Please check your internet connection and try again.</p></div>';
    }
  }

  function renderUserItem(u, recommended){
    const el = document.createElement('div'); el.className='item'; el.tabIndex=0;
    const av = document.createElement('div'); av.className='avatar'; if (u.avatar) av.style.backgroundImage = 'url('+u.avatar+')', av.style.backgroundSize='cover';
    const meta = document.createElement('div'); meta.style.flex='1';

    // Prefer common name fields returned by different APIs/tables
    const displayName = u.name || u.display_name || u.fullname || u.username || u.dname || u.sname || u.cname || u.mname || (u.title ? u.title : null) || ('User ' + (u.id || ''));

    // Normalize and detect ID fields from different tables (clientid, supplierid, managerid, designerid, etc.)
    const uid = u.id || u.clientid || u.designerid || u.supplierid || u.managerid || u.contractorid || u.userid || u.user_id || u.memberid || u.member_id || null;

    // Normalize role label for nicer display and detect role from id fields when missing
    const roleMap = { designer: 'designer', supplier: 'supplier', manager: 'manager', client: 'client', contractor: 'contractor', supplier_company: 'supplier' };
    let rawRole = u.role || u.type || u.member_type || '';
    if (!rawRole) {
      if (u.supplierid) rawRole = 'supplier';
      else if (u.managerid) rawRole = 'manager';
      else if (u.designerid) rawRole = 'designer';
      else if (u.clientid) rawRole = 'client';
      else if (u.contractorid) rawRole = 'contractor';
    }
    // Normalize rawRole to lowercase for consistent handling
    rawRole = (rawRole && String(rawRole).toLowerCase()) || 'client';
    const roleLabel = roleMap[rawRole] ? roleMap[rawRole] : (rawRole ? rawRole : '');

    console.debug('renderUserItem user data:', { u, rawRole, uid, displayName });

    const name = document.createElement('div'); name.className='title'; name.textContent = displayName;
    const role = document.createElement('div'); role.className='subtitle'; role.textContent = roleLabel;
    meta.appendChild(name); meta.appendChild(role);
    const action = document.createElement('div'); action.innerHTML = '<button class="btn btn-sm btn-primary">Add</button>';
    el.appendChild(av); el.appendChild(meta); el.appendChild(action);
    // pass normalized id/name/role to openChatWith so it can create the room with correct display name
    el.addEventListener('click', ()=> openChatWith({ id: uid, role: rawRole, name: displayName }));
    return el;
  }

  async function openChatWith(u){
    try {
      const inst = window.chatApps && window.chatApps['chatwidget'];
      function extractRoomId(obj){
        if (!obj) return null;
        if (typeof obj === 'number') return obj;
        if (typeof obj === 'string' && /^\d+$/.test(obj)) return parseInt(obj,10);
        if (obj.roomId) return obj.roomId;
        if (obj.RoomId) return obj.RoomId;
        if (obj.id) return obj.id;
        if (obj.room) {
          const r = obj.room;
          return r.ChatRoomid || r.ChatRoomId || r.id || null;
        }
        if (obj.chatroom) {
          const c = obj.chatroom;
          return c.ChatRoomid || c.id || null;
        }
        return null;
      }

      // Prefer the shared window.handleChat helper when available
      // Provide a helper to load agents and select the created room so the UI list refreshes
      async function openAndSelect(roomId, otherName) {
        hideChooser();
        try {
          if (inst && typeof inst.loadAgents === 'function') {
            const rooms = await inst.loadAgents();
            let found = null;
            try { found = rooms && rooms.find(r => String(r.ChatRoomid || r.id || r.roomId) === String(roomId)); } catch(e){}
            try { if (roomId && otherName) localStorage.setItem('chat_other_name_' + roomId, otherName); } catch(e){}
            if (found && typeof inst.selectAgent === 'function') return inst.selectAgent(found);
          }
          try { if (roomId && otherName) localStorage.setItem('chat_other_name_' + roomId, otherName); } catch(e){}
          if (inst && typeof inst.selectAgent === 'function') return inst.selectAgent({ ChatRoomid: roomId, roomname: otherName || ('Room ' + roomId) });
          if (typeof window.chatWidgetOpenPanel === 'function') window.chatWidgetOpenPanel();
        } catch (e) { console.warn('openAndSelect failed', e); if (typeof window.chatWidgetOpenPanel === 'function') window.chatWidgetOpenPanel(); }
      }

      // Normalize other_type to match server member_type ENUM values
      // DB ENUM is: 'client','designer','manager','Contractors','supplier'
      const roleToMember = function(r) {
        if (!r) return 'client';
        const rr = String(r).trim().toLowerCase();
        console.debug('roleToMember converting:', { input: r, normalized: rr });
        if (rr === 'designer') return 'designer';
        if (rr === 'supplier' || rr === 'supplier_company') return 'supplier';
        if (rr === 'manager') return 'manager';
        // IMPORTANT: DB ENUM uses 'Contractors' with capital C
        if (rr === 'contractor' || rr === 'contractors') return 'Contractors';
        if (rr === 'client') return 'client';
        return rr; // fallback
      };
      const otherTypeNorm = roleToMember(u.role || 'client');
      const otherId = parseInt(u.id, 10) || 0;

      // Allow all authenticated users to create chat rooms using the shared helper
      if (window.handleChat && u) {
        const res = await window.handleChat(u.id, { creatorType: <?= json_encode($role) ?>, otherType: otherTypeNorm, otherName: u.name, creatorId: <?= json_encode($uid) ?> });
        const roomId = extractRoomId(res);
        if (roomId) {
          await openAndSelect(roomId, u.name);
          return;
        }
      }

      // fallback: try to call ChatApi createRoom if available
      const apiPath = <?= json_encode($CHAT_API_PATH) ?>;
      const createUrl = apiPath + 'createRoom';
      console.debug('openChatWith:', { user: u, otherTypeNorm, otherId, creatorType: <?= json_encode($role) ?>, creatorId: <?= json_encode($uid) ?> });
      if (!otherId) { alert('Unable to determine user id to start chat'); return; }
      const payload = { creator_type: <?= json_encode($role) ?>, creator_id: <?= json_encode($uid) ?>, other_type: otherTypeNorm, other_id: otherId };
      if (payload.creator_id <= 0) {
        console.error('Creator ID is invalid:', payload.creator_id);
        alert('Error: Unable to identify your user ID for chat. Please log out and log in again.');
        return;
      }
      console.debug('Sending createRoom payload:', payload);
      const r = await fetch(createUrl, { method:'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      if (!r.ok) {
        const text = await r.text();
        console.error('createRoom HTTP error:', r.status, text);
        alert(`Error creating chat room: HTTP ${r.status}`);
        return;
      }
      const jr = await r.json();
      console.debug('createRoom response:', jr);
      if (jr.error) {
        console.error('createRoom API error:', jr.error);
        alert(`Error: ${jr.error}`);
        return;
      }
      const roomId = extractRoomId(jr);
      if (roomId) {
        await openAndSelect(roomId, u.name);
        return;
      }
      console.warn('No room ID extracted from response:', jr);
      alert('Unable to open chat with that user. Please try again or contact support if the issue persists.');
    } catch (e) { 
      console.error('openChatWith error:', e); 
      const errorMsg = e && e.message ? e.message : 'Unknown error';
      alert('Failed to open chat: ' + errorMsg + '. Please check your internet connection and try again.');
    }
  }
})();
</script>
<!-- Initialize chat widget. Chatfunction and API paths are centralized here -->
<script src="<?= htmlspecialchars($CHAT_JS_SRC) ?>"></script>
<!-- Set global chatApiBase for handleChat() on all pages that include this widget -->
<script>
  (function(){
    try {
      if (typeof window.chatApiBase === 'undefined') {
        window.chatApiBase = <?= json_encode($CHAT_API_PATH) ?>;
      }
    } catch (e) { console.warn('Failed to set window.chatApiBase', e); }
  })();
</script>
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
        if (preview) {
          try { const holder = preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content'); if (holder) holder.innerHTML = ''; } catch(e) {}
          try { preview.classList.remove('visible','attached'); preview.style.display = 'none'; } catch(e) {}
          try { const panelEl = document.getElementById('chatwidget_panel'); if (panelEl) { panelEl.classList.remove('preview-visible'); panelEl.style.setProperty('--preview-height','0px'); } } catch(e) {}
          try { const pc = preview.querySelector('#chatwidget_attachPreview_close') || document.getElementById('chatwidget_attachPreview_close'); if (pc) pc.style.display = 'none'; } catch(e) {}
        }
      }

      if (attachBtn && attachInput) {
        // Chatfunction.js wires attach/change handling when available. Only bind a fallback
        // preview renderer if the shared helper is not present to avoid duplicate previews.
        if (!(window && typeof window.showSelectedPreview === 'function')) {
          let attachChangeGuard = false;
          attachInput.addEventListener('change', function(e){
            try {
              if (attachChangeGuard) return; attachChangeGuard = true; setTimeout(()=>attachChangeGuard=false, 600);
              if (!preview) return;
              preview.innerHTML = '';
              const file = this.files && this.files[0];
              if (!file) return;
              // render into content holder if present
              const holder = preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content') || preview;
              try { holder.innerHTML = ''; } catch(e) {}
              // Thumbnail for images, filename+icon for others
              if (file.type && file.type.startsWith('image/')){
                const img = document.createElement('img');
                const obj = URL.createObjectURL(file);
                img.src = obj;
                img.style.maxWidth = '120px'; img.style.maxHeight = '90px'; img.style.objectFit = 'cover'; img.alt = file.name; img.className = 'preview-img';
                img.addEventListener('load', () => { try { URL.revokeObjectURL(obj); } catch(e){} });
                holder.appendChild(img);
              } else {
                const badge = document.createElement('div'); badge.className = 'file-badge'; badge.textContent = 'FILE'; holder.appendChild(badge);
                const span = document.createElement('div'); span.textContent = file.name; span.className = 'name small'; holder.appendChild(span);
              }
              // actions
              const rm = document.createElement('button');
              rm.type = 'button';
              rm.className = 'btn btn-sm btn-link preview-remove';
              rm.title = 'Remove';
              rm.setAttribute('aria-label', 'Remove attachment');
              rm.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
              rm.addEventListener('click', function(){ clearPreview(); });
              holder.appendChild(rm);
              // reveal preview now that we have content
              try { preview.classList.add('attached'); preview.style.display = ''; } catch(e) {}
              try { const pc = preview.querySelector('#chatwidget_attachPreview_close') || document.getElementById('chatwidget_attachPreview_close'); if (pc) pc.style.display = 'flex'; } catch(e) {}
              try { const panelEl = document.getElementById('chatwidget_panel'); if (panelEl) { panelEl.classList.add('preview-visible'); panelEl.style.setProperty('--preview-height', Math.round(preview.getBoundingClientRect().height) + 'px'); } } catch(e) {}
              console.debug('chatwidget: preview set for', file.name);
            } catch (ex) { console.error('attach change handler error', ex); }
          });
        }
      }

      // After send, clear preview (send handling lives in Chatfunction.js so we do a best-effort clear)
      if (sendBtn) sendBtn.addEventListener('click', function(){ setTimeout(clearPreview, 200); });
    } catch (e) { console.error('chatwidget preview init error', e); }

    // Force-hide the per-room header and set avatar initial immediately on load
    try {
      const headerEl = document.getElementById('chatwidget_Current_Chat');
      if (headerEl) {
        headerEl.classList.remove('chat-visible');
        headerEl.style.display = 'none';
      }
      const avatarEl = document.getElementById('chatwidget_current_avatar');
      if (avatarEl) {
        const uname = <?= json_encode($_SESSION['user']['name'] ?? '') ?>;
        const initial = (uname && String(uname).trim()) ? String(uname).trim().charAt(0).toUpperCase() : 'U';
        avatarEl.textContent = initial;
        avatarEl.style.backgroundImage = '';
      }
    } catch(e) { console.warn('chatwidget init header hide failed', e); }


    // rootId 'chatwidget' maps to IDs like 'chatwidget_messages', 'chatwidget_input' etc.
    <?php if ($logged): ?>
    try {
      if (typeof initApp === 'function') {
        window.chatApps = window.chatApps || {};
        window.chatApps['chatwidget'] = initApp({ apiPath: <?= json_encode($CHAT_API_PATH) ?>, userType: <?= json_encode($role) ?>, userId: <?= json_encode($uid) ?>, userName: <?= json_encode($_SESSION['user']['name'] ?? '') ?>, rootId: 'chatwidget', items: [] });
      }
    } catch(e) { console.error('chatwidget initApp failed', e); }
    <?php endif; ?>
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
              const resp = await inst.apiPost('sendMessage', { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: (payload.design_id || payload.id) ? (payload.design_id || payload.id) : (payload.url || payload.title || ''), room: roomId, attachment_url: attachmentUrl, attachment_name: attachmentName, message_type: 'design', design_id: (payload.design_id || payload.id) || null, share_title: payload.title || '', share_url: payload.url || '', share_type: 'design' });
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
            const link = document.createElement('a'); link.href = payload.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Open'; link.className = 'small text-muted';
            meta.appendChild(name); meta.appendChild(link);
            imgWrap.appendChild(meta);
            preview.appendChild(imgWrap);

            // Prepare pending share object and show Cancel button; main Send will post it
            previewPendingShare = {
              roomId: roomId,
              attachmentUrl: payload.image || payload.url || null,
              attachmentName: payload.title ? (payload.title + '.jpg') : (payload.image || payload.url || '').split('/').pop() || 'design',
              design_id: payload.design_id || payload.id || null,
              share_title: payload.title || '',
              share_url: payload.url || ''
            };

            const actions = document.createElement('div'); actions.style.marginTop = '8px';
            const cancelBtn = document.createElement('button'); cancelBtn.type = 'button'; cancelBtn.className = 'btn btn-secondary btn-sm'; cancelBtn.textContent = 'Cancel';
            actions.appendChild(cancelBtn);
            preview.appendChild(actions);
            // mark preview as attached to composer area and animate in
            try {
              preview.classList.add('attached');
              preview.style.zIndex = '10056';
              // small delay to allow CSS transition
              setTimeout(() => { try { preview.classList.add('visible'); } catch(e){} }, 24);
            } catch(e) {}

            // Define cleanup to hide/clear the preview and trigger composer collapse
            const cleanup = () => {
              try {
                // animate out then clear
                try { preview.classList.remove('visible'); } catch(e) {}
                setTimeout(() => {
                  try { preview.innerHTML = ''; preview.style.display = 'none'; previewPendingShare = null; preview.classList.remove('attached'); } catch(e) {}
                }, 300);
                const panelEl2 = document.getElementById('chatwidget_panel');
                if (panelEl2) {
                  panelEl2.classList.remove('preview-visible');
                  try { panelEl2.style.setProperty('--preview-height', '0px'); } catch(e) {}
                }
              } catch(e) {}
            };

            cancelBtn.addEventListener('click', () => { cleanup(); });

            // ensure panel knows preview is visible now and set CSS variable for animation
            try {
              const panelEl2 = document.getElementById('chatwidget_panel');
              const holder2 = preview ? (preview.querySelector('#chatwidget_attachPreview_content') || preview.querySelector('.message-preview-content')) : null;
              const hasContent2 = holder2 && holder2.innerHTML && holder2.innerHTML.trim() ? true : false;
              if (panelEl2 && preview && hasContent2) {
                panelEl2.classList.add('preview-visible');
                try { panelEl2.style.setProperty('--preview-height', Math.round(preview.getBoundingClientRect().height) + 'px'); } catch(e) {}
              }
            } catch(e) {}

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
                    const payload = { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: (p.design_id || p.designid) ? (p.design_id || p.designid) : (p.share_url || p.attachmentName || ''), room: p.roomId, attachment_url: p.attachmentUrl, attachment_name: p.attachmentName, message_type: 'design', design_id: p.design_id || null, share_title: p.share_title, share_url: p.share_url, share_type: 'design', text: (document.getElementById('chatwidget_share_preview_text') ? document.getElementById('chatwidget_share_preview_text').value : '') };
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
    // Defensive: ensure preview is hidden on initial load when no content exists
    try {
      const previewColInit = document.getElementById('chatwidget_attachPreviewColumn');
      if (previewColInit) {
        const holder = previewColInit.querySelector('#chatwidget_attachPreview_content') || previewColInit.querySelector('.message-preview-content');
        const hasContent = holder && holder.innerHTML && holder.innerHTML.trim() ? true : false;
        if (!hasContent) {
          try { previewColInit.style.display = 'none'; previewColInit.classList.remove('attached','visible'); } catch(e) {}
          const panelElInit = document.getElementById('chatwidget_panel');
          if (panelElInit) { try { panelElInit.classList.remove('preview-visible'); panelElInit.style.setProperty('--preview-height', '0px'); } catch(e) {} }
        }
      }
    } catch(e) { console.warn('preview defensive init failed', e); }
  });
</script>
<style>
  /* Chat share backdrop & dialog animation */
  #chatwidget_share_backdrop { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background: rgba(0,0,0,0); opacity:0; pointer-events:none; transition: background .22s ease, opacity .22s ease; z-index:100050; }
  #chatwidget_share_backdrop.visible { background: rgba(0,0,0,0.45); opacity:1; pointer-events:auto; }
  .chat-share { transform: translateY(10px) scale(0.98); opacity:0; transition: transform .28s cubic-bezier(.2,.9,.2,1), opacity .18s ease; max-width:920px; width:calc(100% - 48px); max-height:80vh; overflow:auto; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.18); }
  #chatwidget_share_backdrop.visible .chat-share { transform: translateY(0) scale(1); opacity:1; }
  /* make inner grid scroll nicely on small screens */
  #chatwidget_share_grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(140px,1fr)); gap:8px; }
</style>

<div id="chatwidget_share_backdrop" class="chat-share-backdrop" role="dialog" aria-hidden="true">
  <div class="chat-share" role="document" aria-label="Share">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:700">Share</div>
      <div><button id="chatwidget_share_close" class="btn btn-sm btn-light">Close</button></div>
    </div>
    <div id="chatwidget_share_status" class="small text-muted">Your liked designs</div>
    <div id="chatwidget_share_grid" class="grid mt-8 p-2"></div>
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

  function showShare(){
    if (!backdrop) return;
    // reveal with animation class
    backdrop.classList.add('visible');
    backdrop.setAttribute('aria-hidden','false');
    loadLikedDesigns();
  }
  function hideShare(){
    if (!backdrop) return;
    // start hide animation, then cleanup after transition
    backdrop.classList.remove('visible');
    backdrop.setAttribute('aria-hidden','true');
    setTimeout(() => {
      try { grid.innerHTML = ''; selectedDesign = null; if (sendBtn) sendBtn.disabled = true; } catch(e) {}
    }, 360);
  }
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
        const c = document.createElement('div'); c.className='card p-2'; c.tabIndex=0; c.dataset.itemId = d.id || '';
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
              message_type: 'design', share_title: sd.title || '', share_url: sd.url || '', share_type: sd.type === 'product' ? 'product' : 'design',
              item_id: sd.id || sd.productid || sd.productId || sd.designid || sd.designId || sd.id
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
                await inst2.apiPost('sendMessage', { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: sd.url || sd.title || '', room: roomId, attachment_url: sd.image || sd.url || '', attachment_name: (sd.title||sd.type||'item') + '.jpg', message_type: mtype, share_title: sd.title || '', share_url: sd.url || '', item_id: sd.id || sd.productid || sd.designid || sd.id });
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
