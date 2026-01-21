<?php
// Floating chat widget — include this PHP where you want the floating chat button
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$logged = isset($_SESSION['user']) && !empty($_SESSION['user']);
$role = $_SESSION['user']['role'] ?? 'client';
$uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);
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
.message-preview-column{box-sizing:border-box;padding:8px 12px;border-top:1px solid #eef3fb;border-bottom:44px solid #f8f9fb;background:#ffffff;display:flex;flex-direction:column;gap:8px;align-items:flex-start;}
.message-preview-column img{max-width:100%;max-height:360px;border-radius:6px;object-fit:cover}
.message-preview-column .file-badge{width:56px;height:44px;border-radius:6px;background:#6c757d;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:600}
.message-preview-column .file-meta{display:flex;flex-direction:column;min-width:0}
.message-preview-column .file-meta .name{font-size:0.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.message-preview-column .file-meta .size{font-size:0.8rem;color:#6c757d}
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
      <div id="chatwidget_typingIndicator" class="small text-muted"></div>
    </div>
    <div>
      <button id="chatwidget_close" aria-label="Close">✕</button>
    </div>
  </div>
  <div id="chatwidget_body" class="body">
    <div class="left">
      <div id="chatwidget_agentsList" class="list-group mb-2" style="max-height:100%;overflow:auto;"></div>
    </div>
    <div id="chatwidget_divider" role="separator" aria-orientation="vertical" aria-label="Resize chat list"><div class="handle" aria-hidden="true"></div></div>
    <div class="right">
      <div id="chatwidget_messages" class="messages"></div>
      <!-- attachment preview placeholder row (populated by JS) -->
      <div id="chatwidget_attachPreviewColumn" class="message-preview-column" style="display:none;width:100%;margin-bottom:8px"></div>
      <div class="composer">
        <input type="file" id="chatwidget_attachInput" class="d-none" />
        <button id="chatwidget_attach" class="btn btn-light btn-sm" type="button" title="Attach" aria-label="Attach file">
          <i class="bi bi-paperclip" aria-hidden="true" style="font-size:16px;line-height:1"></i>
        </button>
        <button id="chatwidget_share" class="btn btn-outline-secondary btn-sm" type="button" title="Share page" aria-label="Share design" style="display:none;margin-left:4px">
          <i class="bi bi-share" aria-hidden="true" style="font-size:14px"></i>
        </button>
        <input id="chatwidget_input" class="form-control form-control-sm" placeholder="Message..." aria-label="Message input" <?php if (!$logged) echo 'disabled title="Log in to send messages"'; ?> >
        <button id="chatwidget_send" class="btn btn-primary btn-sm" <?php if (!$logged) echo 'disabled title="Log in to send messages"'; ?> aria-label="Send message">
          <i class="bi bi-send-fill" aria-hidden="true" style="font-size:16px;line-height:1;color:#fff"></i>
        </button>
      </div>
    </div>
  </div>
  <div id="chatwidget_resizer" class="resizer" aria-hidden="true"></div>
</div>

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
<!-- Initialize chat widget. Allow overriding paths by setting $CHAT_JS_PATH and $CHAT_API_PATH before include -->
<script src="<?= htmlspecialchars($CHAT_JS_PATH ?? 'Chatfunction.js') ?>"></script>
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
    initApp({ apiPath: <?= json_encode($CHAT_API_PATH ?? 'ChatApi.php?action=') ?>, userType: <?= json_encode($role) ?>, userId: <?= json_encode($uid) ?>, rootId: 'chatwidget', items: [] });
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
        if (!payload) { shareBtn.style.display = 'none'; return; }
        // Prefer the image already rendered on the page (design_detail.php uses `$mainImg`) so
        // the widget preview uses the same resolved src. The DOM `img.src` will be absolute.
        try {
          const pageImg = document.querySelector('.design-image-wrapper img');
          if (pageImg && pageImg.src) {
            payload.image = pageImg.src;
          }
        } catch (e) {}
        shareBtn.style.display = '';
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
              const sendCapture = async function(e){
                if (!previewPendingShare) return; // allow normal send
                e.preventDefault(); e.stopImmediatePropagation();
                try {
                  const p = previewPendingShare;
                  // use message_type 'image' to avoid DB truncation; include share metadata
                  const resp = await inst.apiPost('sendMessage', { sender_type: 'client', sender_id: <?= json_encode($uid) ?>, content: p.share_url || p.attachmentName || '', room: p.roomId, attachment_url: p.attachmentUrl, attachment_name: p.attachmentName, message_type: 'image', share_title: p.share_title, share_url: p.share_url });
                  try { if (resp && (resp.ok || resp.message)) inst.appendMessageToUI(resp.message || resp, 'me'); } catch(e){}
                  try { alert('Design link shared with the designer.'); } catch(e){}
                } catch (ex) { console.error('share send failed', ex); alert('Failed to share design.'); }
                cleanup();
                return false;
              };
              // add as capture listener so it runs before other handlers
              mainSendBtn.addEventListener('click', sendCapture, true);
            }

            // focus on preview for accessibility
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
          } catch (e) { console.error('chatwidget share error', e); alert('Error sharing design.'); }
        });
      };
      // wait briefly for initApp to register instance
      setTimeout(tryWireShare, 200);
    } catch(e) { console.error('share wiring failed', e); }
  });
</script>
