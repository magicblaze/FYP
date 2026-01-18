<?php
// Floating chat widget — include this PHP where you want the floating chat button
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$role = $_SESSION['user']['role'] ?? 'client';
$uid = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);
?>
<style>
/* Floating chat button and panel styles */
#chatwidget_toggle {position:fixed;right:20px;bottom:20px;z-index:9999;border-radius:50%;width:56px;height:56px;background:#0d6efd;color:#fff;border:none;box-shadow:0 6px 18px rgba(11,27,43,0.18);display:flex;align-items:center;justify-content:center;font-size:22px}
#chatwidget_panel {position:fixed;right:20px;top:20px;z-index:9998;width:520px;max-width:95vw;max-height:95vh;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(11,27,43,0.12);overflow:hidden;display:none;flex-direction:column;min-width:377px;min-height:255px;box-sizing:border-box}
#chatwidget_panel .header{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f7f9fc;border-bottom:1px solid #eef3fb}
#chatwidget_panel .body{display:flex;gap:8px;padding:8px;flex:1 1 auto;min-height:0;box-sizing:border-box}
#chatwidget_panel .body .left{width:160px;min-width:120px;max-width:220px;overflow:auto;border-right:1px solid #eef3fb;padding-right:8px}
#chatwidget_panel .body .left{width:160px;min-width:140px;max-width:220px;overflow:auto;border-right:1px solid #eef3fb;padding-right:8px}
#chatwidget_panel .composer{display:flex;gap:8px;padding:8px;border-top:1px solid #eef3fb}
#chatwidget_panel .body .right{flex:1;display:flex;flex-direction:column;min-width:220px}
#chatwidget_panel .body .right{flex:1;display:flex;flex-direction:column;min-width:220px;min-height:200px;position:relative}
/* Responsive: stack on small screens */
@media (max-width: 700px) {
  #chatwidget_panel { right:8px; left:8px; top:auto; bottom:8px; width:calc(100% - 16px); max-width:none; border-radius:10px; min-width:unset; min-height:220px; max-height:80vh; }
  #chatwidget_panel .body { flex-direction:column; gap:6px; padding:6px; max-height:calc(80vh - 120px); }
  #chatwidget_panel .body .left { width:100%; min-width:unset; max-width:none; border-right:none; border-bottom:1px solid #eef3fb; padding-bottom:8px; }
  #chatwidget_panel .body .right { width:100%; min-height:140px; }
  #chatwidget_panel .messages { min-height:120px; }
  #chatwidget_toggle { right:12px; bottom:12px; width:48px; height:48px; }
}

@media (max-width: 420px) {
  #chatwidget_panel { padding:0; }
  #chatwidget_panel .header { padding:8px; }
  #chatwidget_panel .composer { padding:6px; }
  #chatwidget_panel .body .left .list-group { max-height:120px; overflow:auto; }
}
#chatwidget_panel .messages{flex:1 1 auto;overflow:auto;padding:6px;min-height:0}
#chatwidget_panel .composer{display:flex;gap:8px;padding:8px;border-top:1px solid #eef3fb}
#chatwidget_panel .composer{position:absolute;left:0;right:0;bottom:0;background:transparent;padding:8px 8px 12px 8px;z-index:3}
#chatwidget_panel .messages{padding-bottom:72px}
#chatwidget_panel .composer input{flex:1}
#chatwidget_close{background:transparent;border:0;font-size:18px}
/* Resizer handle */
#chatwidget_panel .resizer{position:absolute;right:8px;bottom:8px;width:18px;height:18px;cursor:se-resize;background:linear-gradient(135deg, rgba(0,0,0,0.06), rgba(0,0,0,0.02));border-radius:4px;z-index:10001}

/* Bottom-sheet helper class applied on small screens by JS for more predictable behavior */
.chatwidget-bottomsheet { left:8px !important; right:8px !important; top:auto !important; bottom:8px !important; width:calc(100% - 16px) !important; height:60vh !important; max-height:80vh !important; border-radius:12px 12px 6px 6px !important; }
.chatwidget-bottomsheet .resizer { display:none !important; }
.chatwidget-bottomsheet .composer { position:sticky !important; bottom:0; background:linear-gradient(to top, rgba(255,255,255,0.9), rgba(255,255,255,0.6)); }
</style>

<button id="chatwidget_toggle" aria-label="Open chat">💬</button>

<div id="chatwidget_panel" role="dialog" aria-hidden="true" aria-label="Chat widget">
  <div class="header">
    <div>
      <div style="font-weight:600">HappyDesign Chat</div>
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
    <div class="right">
      <div id="chatwidget_messages" class="messages"></div>
      <div class="composer">
        <input id="chatwidget_input" class="form-control form-control-sm" placeholder="Message..." aria-label="Message input">
        <button id="chatwidget_send" class="btn btn-primary btn-sm">Send</button>
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
  // Ensure the element stays fully within the viewport (clamp if needed)
  function ensureInViewport(el){
    if(!el) return;
    const rect = el.getBoundingClientRect();
    const pad = 8;
    let changed = false;
    let left = rect.left, top = rect.top;
    if (rect.right > window.innerWidth - pad) { left = Math.max(pad, window.innerWidth - el.offsetWidth - pad); changed = true; }
    if (rect.left < pad) { left = pad; changed = true; }
    if (rect.bottom > window.innerHeight - pad) { top = Math.max(pad, window.innerHeight - el.offsetHeight - pad); changed = true; }
    if (rect.top < pad) { top = pad; changed = true; }
    if (changed){ el.style.left = left + 'px'; el.style.top = top + 'px'; el.style.right = 'auto'; }
    return changed;
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
})();
</script>

<!-- Initialize chat widget. This assumes Chatfunction.js and ChatApi.php are reachable at the same paths. -->
<script src="designer/Chatfunction.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    // rootId 'chatwidget' maps to IDs like 'chatwidget_messages', 'chatwidget_input' etc.
    initApp({ apiPath: 'designer/ChatApi.php?action=', userType: <?= json_encode($role) ?>, userId: <?= json_encode($uid) ?>, rootId: 'chatwidget', items: [] });
  });
</script>
