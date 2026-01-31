function initApp(config = {}) {
  const API = config.apiPath || (location.pathname + '?action=');
  // expose the resolved API base so non-widget helpers (handleChat) can reuse it
  try { window.chatApiBase = API; } catch (e) {}
  const userId = config.userId ?? 0;
  const userType = (config.userType || 'client').toLowerCase().trim();
  const userName = config.userName || '';
  // expose current user globally so URL handlers can access it
  try { window.chatUserId = userId; window.chatUserType = userType; } catch (e) {}
  const items = config.items || [];

  // Optionally namespace element ids when used as an embeddable widget.
  const prefix = config.rootId ? (config.rootId + '_') : '';
  const el = id => document.getElementById(prefix + id);
  const elements = {
    agentsList: el('agentsList'),
    agentsListOff: el('agentsListOffcanvas'),
    cardsGrid: el('cardsGrid'),
    cardsGridOff: el('cardsGridOffcanvas'),
    messages: el('messages'),
    input: el('input'),
    send: el('send'),
    campaign: el('campaignInput'),
    campaignOff: el('campaignInputOff'),
    connectionStatus: el('connectionStatus'),
    openCatalogBtn: el('openCatalogBtn'),
    catalogOffcanvasEl: el('catalogOffcanvas'),
  };

  // Ensure any attach preview columns are hidden by default (remove visibility classes)
  try {
    ['chatwidget_attachPreviewColumn','attachPreviewColumn'].forEach(id => {
      const el1 = document.getElementById(prefix + id);
      const el2 = document.getElementById(id);
      const node = el1 || el2;
      if (node) {
        try { node.classList.remove('visible','show'); } catch(e) {}
        try { node.style.display = 'none'; } catch(e) {}
      }
    });
  } catch(e) {}

  let currentAgent = null;
  let currentRoomId = null;
  // cache of references for current order room
  let currentOrderReferences = { messageIds: new Set(), designIds: new Set() };
  let lastMessageId = 0;
  let widgetPendingFile = null;
  let pendingFile = null;
  let pollTimer = null;
  let agentsPollTimer = null;
  let typingThrottle = null;
  const bsOff = (elements.catalogOffcanvasEl) ? new bootstrap.Offcanvas(elements.catalogOffcanvasEl) : null;
  if (elements.openCatalogBtn && bsOff) {
    elements.openCatalogBtn.addEventListener('click', () => bsOff.show());
  }
  // multipart/form-data POST helper (returns parsed JSON or text)
  async function apiPostForm(path, formData) {
    try {
      const res = await fetch(API + path, { method: 'POST', body: formData });
      const txt = await res.text();
      if (!res.ok) {
        let msg = txt || ('Status ' + res.status);
        try { const j = JSON.parse(txt); msg = j.message || j.error || JSON.stringify(j); } catch(e){}
        throw new Error('Network error: ' + res.status + ' - ' + msg);
      }
      try { return JSON.parse(txt); } catch (e) { return txt; }
    } catch (e) {
      console.error('apiPostForm error', e, API + path, formData);
      throw e;
    }
  }

  // JSON GET helper
  async function apiGet(path) {
    try {
      const res = await fetch(API + path, { method: 'GET' });
      const txt = await res.text();
      if (!res.ok) {
        let msg = txt || ('Status ' + res.status);
        try { const j = JSON.parse(txt); msg = j.message || j.error || JSON.stringify(j); } catch(e){}
        throw new Error('Network error: ' + res.status + ' - ' + msg);
      }
      try { return JSON.parse(txt); } catch (e) { return txt; }
    } catch (e) {
      console.error('apiGet error', e, API + path);
      throw e;
    }
  }

  // JSON POST helper
  async function apiPost(path, obj) {
    try {
      const res = await fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(obj) });
      const txt = await res.text();
      if (!res.ok) {
        let msg = txt || ('Status ' + res.status);
        try { const j = JSON.parse(txt); msg = j.message || j.error || JSON.stringify(j); } catch(e){}
        throw new Error('Network error: ' + res.status + ' - ' + msg);
      }
      try { return JSON.parse(txt); } catch (e) { return txt; }
    } catch (e) {
      console.error('apiPost error', e, API + path, obj);
      throw e;
    }
  }

  // Add a reference for an order (designer action)
  async function addReference(roomId, messageId, designId, btn) {
    if (!confirm('Add this item to the order references?')) return;
    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }
      const payload = { room: roomId };
      if (messageId) payload.messageid = messageId;
      if (designId) payload.designid = designId;
      const res = await apiPost('addReference', payload);
      if (res && res.ok) {
        if (res.already) {
          if (btn) { btn.disabled = true; btn.textContent = 'Added'; btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-secondary'); }
          return res;
        }
        // mark caches so subsequent messages/buttons show added state
        try {
          if (designId && currentOrderReferences && currentOrderReferences.designIds) currentOrderReferences.designIds.add(String(designId));
          if (messageId && currentOrderReferences && currentOrderReferences.messageIds) currentOrderReferences.messageIds.add(String(messageId));
        } catch (e) {}
        if (btn) { btn.textContent = 'Added'; btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-success'); }
        // update any other matching buttons in the DOM
        try {
          if (designId) {
            document.querySelectorAll('.add-ref-btn[data-did="' + String(designId) + '"]').forEach(b => { b.disabled = true; b.textContent = 'Added'; b.classList.remove('btn-outline-secondary'); b.classList.add('btn-secondary'); });
          }
          if (messageId) {
            document.querySelectorAll('.add-ref-btn[data-mid="' + String(messageId) + '"]').forEach(b => { b.disabled = true; b.textContent = 'Added'; b.classList.remove('btn-outline-secondary'); b.classList.add('btn-secondary'); });
          }
        } catch (e) {}
        return res;
      } else {
        if (btn) { btn.disabled = false; btn.textContent = 'Add to Reference'; }
        alert('Failed to add reference: ' + (res && res.message ? res.message : JSON.stringify(res)));
        return res;
      }
    } catch (e) {
      console.error('addReference error', e);
      if (btn) { btn.disabled = false; btn.textContent = 'Add to Reference'; }
      alert('Request failed: ' + e.message);
      throw e;
    }
  }

  function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function stripHtml(html) { return String(html || '').replace(/<[^>]*>?/gm, ''); }

  // Format timestamps: prefix with "Today" or MM/DD, then show the time (English)
  function formatMessageTimestamp(ts) {
    try {
      const d = new Date(ts);
      if (isNaN(d.getTime())) return '';
      const now = new Date();
      const time = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
      if (d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate()) {
        return 'Today ' + time;
      }
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const dd = String(d.getDate()).padStart(2, '0');
      return mm + '/' + dd + ' ' + time;
    } catch (e) { return ''; }
  }

  // --- Unread helpers: store simple per-room unread counts in localStorage and update UI badges
  function getUnreadKey(roomId) {
    return 'chat_unread_' + roomId;
  }
  function getUnreadCountFromStorage(roomId) {
    try { const v = localStorage.getItem(getUnreadKey(roomId)); return v ? parseInt(v,10) || 0 : 0; } catch (e) { return 0; }
  }
  function setUnreadCount(roomId, count) {
    try { localStorage.setItem(getUnreadKey(roomId), String(Math.max(0, parseInt(count||0,10)||0))); } catch(e){}
    try { updateAgentBadge(roomId, Math.max(0, parseInt(count||0,10)||0)); } catch(e){}
    try { updateTotalUnreadBadge(); } catch(e){}
  }
  function incrementUnread(roomId, by) {
    try { const cur = getUnreadCountFromStorage(roomId); setUnreadCount(roomId, cur + (parseInt(by||1,10) || 1)); } catch(e){}
  }
  function clearUnread(roomId) { setUnreadCount(roomId, 0); }
  function getAllLocalUnreadTotal() {
    try {
      let total = 0;
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (!key) continue;
        if (key.indexOf('chat_unread_') === 0) {
          const v = parseInt(localStorage.getItem(key) || '0', 10) || 0;
          total += v;
        }
      }
      return total;
    } catch (e) { return 0; }
  }

  function updateTotalUnreadBadge(total) {
    try {
      const t = (typeof total === 'undefined' || total === null) ? getAllLocalUnreadTotal() : (parseInt(total||0,10) || 0);
      try { localStorage.setItem('chat_total_unread', String(t)); } catch(e){}
      console.debug('chatwidget: updateTotalUnreadBadge called, total=', t);
      // find toggle element (support optional prefix)
      const toggle = document.getElementById((prefix || '') + 'toggle') || document.getElementById('chatwidget_toggle') || document.querySelector('[data-chat-toggle]');
      if (!toggle) { console.debug('chatwidget: toggle element not found when updating total badge'); }
      if (!toggle) return;
      let badge = toggle.querySelector('.chatwidget_total_unread');
      if (!badge && t > 0) {
        // ensure toggle is positioned so absolute badge can be placed
        try { if (toggle && window.getComputedStyle(toggle).position === 'static') toggle.style.position = 'relative'; } catch(e){}
        badge = document.createElement('span');
        badge.className = 'chatwidget_total_unread';
        // inline styles so badge shows even when Bootstrap CSS is not present
        badge.style.position = 'absolute';
        badge.style.top = '-6px';
        badge.style.left = '-6px';
        badge.style.minWidth = '20px';
        badge.style.display = 'inline-block';
        badge.style.textAlign = 'center';
        badge.style.padding = '2px 6px';
        badge.style.borderRadius = '999px';
        badge.style.backgroundColor = '#dc3545';
        badge.style.color = '#fff';
        badge.style.fontSize = '12px';
        badge.style.lineHeight = '1';
        badge.style.fontWeight = '600';
        badge.style.boxShadow = '0 1px 2px rgba(0,0,0,0.2)';
        badge.style.zIndex = '9999';
        toggle.appendChild(badge);
      }
      if (badge) {
        if (t > 0) badge.textContent = (t > 99 ? '99+' : String(t)); else badge.remove();
      }
    } catch (e) { console.error('updateTotalUnreadBadge error', e); }
  }
  function updateAgentBadge(roomId, count) {
    try {
      const sel = document.querySelectorAll('[data-room-id="' + roomId + '"]');
      sel.forEach(btn => {
        // find existing badge
        let badge = btn.querySelector('.chat-unread-badge');
        if (!badge && count > 0) {
          badge = document.createElement('span');
          badge.className = 'badge bg-danger ms-2 chat-unread-badge';
          btn.querySelector('.fw-semibold') && btn.querySelector('.fw-semibold').appendChild(badge);
        }
        if (badge) {
          if (count > 0) badge.textContent = String(count);
          else badge.remove();
        }
      });
    } catch (e) { console.error('updateAgentBadge error', e); }
  }

  function renderCards(list) {
    [elements.cardsGrid, elements.cardsGridOff].forEach(root => {
      if (!root) return;
      root.innerHTML = '';
      list.forEach(it => {
        const col = document.createElement('div');
        col.className = 'col-6';
        col.innerHTML = `
          <div class="card h-100 catalog-item" role="button" tabindex="0"
               data-likes="${it.likes}" data-price="${it.price}" data-title="${escapeHtml(it.title)}">
            <div class="card-body p-2">
              <div class="thumb mb-2">Living room image</div>
              <div class="d-flex justify-content-between text-muted small">
                <div>${it.likes} Likes</div>
                <div class="fw-semibold">${it.price}</div>
              </div>
            </div>
          </div>`;
        root.appendChild(col);
      });
    });
  }

  function renderAgents(agents, container, isOff) {
    if (!container) return;
    container.innerHTML = '';
    agents.forEach(a => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'list-group-item list-group-item-action d-flex align-items-center';
      // ChatApi returns ChatRoom rows; map fields
      btn.dataset.roomId = a.ChatRoomid || a.ChatRoomId || a.id;
      const roomKey = a.ChatRoomid || a.ChatRoomId || a.id;
      const storedOther = (roomKey ? localStorage.getItem('chat_other_name_' + roomKey) : null);
      // Determine role label (human friendly)
      const roleRaw = a.other_type || a.otherType || a.member_type || a.role || a.type || '';
      let roleLabel = '';
      try {
        switch ((roleRaw || '').toString().toLowerCase()) {
          case 'client': roleLabel = 'Client'; break;
          case 'designer': roleLabel = 'Designer'; break;
          case 'manager': roleLabel = 'Manager'; break;
          case 'supplier': roleLabel = 'Supplier'; break;
          case 'contractors': roleLabel = 'Contractor'; break;
          default: roleLabel = ''; break;
        }
      } catch(e) { roleLabel = ''; }
      const isGroup = ((a.room_type || a.roomType || a.type || '') + '').toString().toLowerCase() === 'group';
      let name = '';
      let subtitle = '';
      if (isGroup) {
        name = a.roomname || storedOther || a.name || `Room ${btn.dataset.roomId}`;
        subtitle = a.description || a.title || '';
      } else {
        name = a.other_name || a.otherName || storedOther || a.roomname || a.name || `Room ${btn.dataset.roomId}`;
        subtitle = roleLabel || (a.description || a.title || '');
      }
      const unreadCount = (a.unread || a.unread_count || getUnreadCountFromStorage(roomKey) || 0);
      const unreadHtml = unreadCount ? ('<span class="badge bg-danger ms-2 chat-unread-badge">' + escapeHtml(String(unreadCount)) + '</span>') : '';
      btn.innerHTML = `<div class="me-2"><div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px">${escapeHtml((name||'')[0]||'R')}</div></div>
        <div class="flex-grow-1 text-start"><div class="fw-semibold">${escapeHtml(name)}${unreadHtml}</div><div class="small text-muted">${escapeHtml(subtitle)}</div></div>`;
      btn.addEventListener('click', () => {
        selectAgent(a);
        if (isOff && bsOff) bsOff.hide();
      });
      container.appendChild(btn);
      // ensure badge exists in DOM after render (in case storage sync happened earlier)
      try { if (roomKey) updateAgentBadge(roomKey, unreadCount); } catch(e) { console.debug('updateAgentBadge post-render failed', e); }
    });
  }

  function loadAgents() {
    // Use ChatApi listRooms endpoint
    return apiPost('listRooms', { user_type: userType, user_id: userId }).then(data => {
      const rooms = Array.isArray(data) ? data : (data.rooms || []);
      console.debug('chatwidget: loadAgents fetched', rooms.length, 'rooms');
      // sync server-provided unread counts into local cache so UI can display consistently
      try {
        rooms.forEach(r => {
          const rid = r.ChatRoomid || r.ChatRoomId || r.id || r.roomId;
          const serverCnt = (r.unread || r.unread_count || 0) || 0;
          if (rid != null) setUnreadCount(rid, serverCnt);
        });
      } catch(e){}
      renderAgents(rooms, elements.agentsList, false);
      renderAgents(rooms, elements.agentsListOff, true);
      // update total unread badge on widget toggle (log computed total)
      try {
        const total = rooms.reduce((s,r) => s + (parseInt(r.unread || r.unread_count || getUnreadCountFromStorage(r.ChatRoomid || r.id || r.roomId) || 0,10) || 0), 0);
        console.debug('chatwidget: total unread computed', total);
        updateTotalUnreadBadge(total);
      } catch(e){ console.debug('chatwidget: update total unread failed', e); }
      return rooms;
    }).catch(err => { console.error('Failed to load rooms', err); return []; });
  }

  function conversationIdFor(agentId) { return agentId; }

  function loadMessages(roomId) {
    if (elements.messages) elements.messages.innerHTML = '';
    return apiPost('getMessages', { room: roomId }).then(async data => {
      const messages = Array.isArray(data) ? data : (data.messages || []);
      // preload references when this is an order room
      currentOrderReferences = { messageIds: new Set(), designIds: new Set() };
      try {
        const roomName = currentAgent && (currentAgent.roomname || currentAgent.roomName || '');
        let orderId = null;
        if (roomName && /^order-\d+/i.test(roomName)) {
          const m = (roomName || '').match(/^order-(\d+)/i);
          if (m) orderId = m[1];
        }
        if (!orderId && messages && messages.length) {
          // fallback: inspect first message for order.id
          const mm = messages.find(x => x.order && (x.order.id || x.order.orderid));
          if (mm) orderId = mm.order.id || mm.order.orderid;
        }
        if (orderId) {
          const refs = await apiPost('listReferences', { orderid: orderId });
          const list = (refs && refs.references) ? refs.references : (refs || []);
          list.forEach(r => {
            if (r.messageid) currentOrderReferences.messageIds.add(String(r.messageid));
            if (r.designid) currentOrderReferences.designIds.add(String(r.designid));
          });
        }
      } catch (e) { /* ignore ref load errors */ }

      messages.forEach(m => {
        const who = (m.sender_type === userType && String(m.sender_id) == String(userId)) ? 'me' : 'them';
        appendMessageToUI(m, who);
        const mid = m.id || m.messageid || m.messageId || 0;
        if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
      });
      if (elements.messages) elements.messages.dataset.roomId = roomId;
      if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
      // Mark this room as read on the server (best-effort) and clear local unread counter
      try {
        const room = roomId;
        if (room) {
          apiPost('markRead', { room: room, user_type: userType, user_id: userId }).catch(() => {});
          try { clearUnread(room); } catch(e){}
        }
      } catch(e){}
      return messages;
    }).catch(err => { console.error(err); return []; });
  }

  function startPolling(roomId) {
    if (pollTimer) clearInterval(pollTimer);
    currentRoomId = roomId;
    pollTimer = setInterval(() => {
          apiPost('getMessages', { room: roomId, since: lastMessageId }).then(ms => {
            const messages = Array.isArray(ms) ? ms : (ms.messages || []);
            messages.forEach(m => {
              const who = (m.sender_type === userType && String(m.sender_id) == String(userId)) ? 'me' : 'them';
              appendMessageToUI(m, who);
              const mid = m.id || m.messageid || m.messageId || 0;
              if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
            });
            if (elements.messages && messages.length) {
              try { elements.messages.scrollTop = elements.messages.scrollHeight; } catch(e){}
            }
            // Mark messages read for currently opened room and clear local unread counter
            try {
              if (messages && messages.length) {
                apiPost('markRead', { room: roomId, user_type: userType, user_id: userId }).catch(() => {});
                try { clearUnread(roomId); } catch(e){}
              }
            } catch(e) {}
          }).catch(() => {});

      // typing status
      apiPost('getTyping', { room: roomId }).then(tp => {
        const el = document.getElementById('typingIndicator');
        if (!el) return;
        if (tp && tp.typing && !(tp.sender === userType && String(tp.sender_id) === String(userId))) {
          el.textContent = 'typing...';
        } else {
          el.textContent = '';
        }
      }).catch(() => {});

    }, 1500);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function appendMessageToUI(msgObj, who) {
    if (!elements.messages) return;
    // Avoid adding duplicate messages: if this message has an id and
    // we've already rendered it, skip appending. This prevents duplicates
    // when a poll and a send response arrive around the same time.
    const existingId = msgObj.id || msgObj.messageid || msgObj.messageId || msgObj.messageID || msgObj.id;
    if (existingId) {
      try {
        if (elements.messages.querySelector('[data-mid="' + existingId + '"]')) return;
      } catch (e) {}
    }
    const senderName = msgObj.sender_name || msgObj.sender || (msgObj.sender_type ? (msgObj.sender_type + ' ' + (msgObj.sender_id||'')) : '');
    // Determine a room name candidate (used as fallback). Prefer showing the actual
    // sender's name for message lines in group rooms; use roomname for lists/headers.
    let roomNameCandidate = '';
    let isGroup = false;
    try {
      roomNameCandidate = msgObj.roomname || msgObj.room_name || (msgObj.room && (msgObj.room.roomname || msgObj.room.name)) || (currentAgent && (currentAgent.roomname || currentAgent.roomName)) || '';
      const msgRoomType = msgObj.room_type || (msgObj.room && msgObj.room.room_type) || null;
      const curRoomType = (currentAgent && (currentAgent.room_type || currentAgent.roomType || currentAgent.type)) || null;
      isGroup = (msgRoomType && String(msgRoomType).toLowerCase() === 'group') || (curRoomType && String(curRoomType).toLowerCase() === 'group');
    } catch (e) { roomNameCandidate = ''; isGroup = false; }
    const finalSenderName = senderName || '';
    const displayNameForList = (isGroup && roomNameCandidate) ? roomNameCandidate : (senderName || roomNameCandidate || '');
    const content = msgObj.content || msgObj.body || '';
    // render URL content as clickable link
    let contentHtml = '';
    try {
      const txt = String(content || '');
      if (/^https?:\/\//i.test(txt)) {
        // If message also has an uploaded file, show a friendly label instead of raw URL
        const fname = (msgObj.uploaded_file && msgObj.uploaded_file.filename) ? msgObj.uploaded_file.filename : (msgObj.attachment ? (msgObj.attachment.split('/').pop() || txt) : null);
        const label = fname || txt;
        contentHtml = `<a href="${escapeHtml(txt)}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>`;
      } else {
        contentHtml = escapeHtml(stripHtml(txt));
      }
    } catch (e) { contentHtml = escapeHtml(stripHtml(content)); }
    const time = msgObj.timestamp || msgObj.created_at || new Date().toISOString();

    const wrapper = document.createElement('div');
    wrapper.className = 'message mb-3 d-flex ' + (who === 'me' ? 'justify-content-end' : 'justify-content-start');

    const avatarWrap = document.createElement('div');
    avatarWrap.style.width = '48px'; avatarWrap.style.flex = '0 0 48px'; avatarWrap.style.display = 'flex'; avatarWrap.style.alignItems = 'flex-start'; avatarWrap.style.justifyContent = 'center';
    const avatar = document.createElement('div');
    avatar.className = 'rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center';
    avatar.style.width = '36px'; avatar.style.height = '36px'; avatar.style.fontSize = '14px'; avatar.textContent = (finalSenderName || ' ')[0] || '?';
    avatarWrap.appendChild(avatar);

    const bodyCol = document.createElement('div');
    bodyCol.style.maxWidth = '100%';
    // allow the column to shrink so long names / timestamps don't force the bubble wider
    bodyCol.style.flex = '1';
    bodyCol.style.minWidth = '0';
    const nameEl = document.createElement('div');
    nameEl.className = 'small text-muted mb-1';
    // For message entries always show the actual sender's name (no roomname fallback).
    nameEl.textContent = finalSenderName || '';
    // prevent the name from widening the message column
    nameEl.style.maxWidth = '100%';
    nameEl.style.overflow = 'hidden';
    nameEl.style.textOverflow = 'ellipsis';
    // capture name color class (e.g., 'text-muted' or 'text-white') to reuse for timestamp
    const nameColorClass = (nameEl.className && (nameEl.className.match(/\btext-[^\s]+\b/) || [null])[0]) || null;
    // Align sender name according to message side to avoid layout glitches
    if (who === 'me') {
      nameEl.classList.add('text-end');
      // ensure the body column aligns its content to the right for `me` messages
      try { bodyCol.style.textAlign = 'right'; } catch (e) {}
    } else {
      nameEl.classList.remove('text-end');
      try { bodyCol.style.textAlign = 'left'; } catch (e) {}
    }

    // For the current user (`me`) hide the avatar and do not render the name element
    if (who === 'me') {
      try {
        // Remove the avatar node and collapse the avatar column so the bubble
        // sits 2px away from the right edge.
        avatarWrap.innerHTML = '';
        avatarWrap.style.width = '0px';
        avatarWrap.style.flex = '0 0 0px';
        avatarWrap.style.marginRight = '2px';
      } catch (e) {}
    }

    const bubble = document.createElement('div');
    bubble.className = who === 'me' ? 'bg-primary text-white rounded p-2' : 'bg-light rounded p-2';
    // Ensure bubble width is constrained and independent from name/time
    bubble.style.display = 'inline-block';
    bubble.style.maxWidth = '420px';
    bubble.style.wordBreak = 'break-word';
    const campaignHtml = msgObj.campaign ? `<div class="small text-muted mt-1">Campaign: ${escapeHtml(msgObj.campaign)}</div>` : '';
    // Special rendering for `design` share messages: single white card merged into bubble
    let designHtml = '';
    // Special rendering for `order` messages: show order card with link/status instead of raw id
    let orderHtml = '';
    try {
      if ((msgObj.message_type && msgObj.message_type === 'design') || msgObj.share) {
        const share = msgObj.share || {};
        const uploaded = msgObj.uploaded_file || null;
        const imgSrc = (uploaded && uploaded.filepath) || share.image || msgObj.attachment || '';
        let imgUrl = imgSrc || '';
        if (imgUrl && !/^https?:\/\//.test(imgUrl)) imgUrl = (location.origin + '/' + imgUrl.replace(/^\/+/, ''));
        const title = share.title || (uploaded && uploaded.filename) || msgObj.content || '';
        const url = share.url || msgObj.content || imgUrl || '';
        // Determine whether to show "Add to Reference" button: only for designers viewing an order room
        let refButtonHtml = '';
        try {
          const isDesigner = (typeof userType !== 'undefined' && String(userType).toLowerCase() === 'designer');
          const roomName = (currentAgent && (currentAgent.roomname || currentAgent.roomName || '')) || '';
          const isOrderRoom = String(roomName).toLowerCase().startsWith('order-');
          const mid = existingId || msgObj.messageid || msgObj.id || '';
          const did = (msgObj.share && msgObj.share.designid) ? msgObj.share.designid : (msgObj.content && /^\d+$/.test(String(msgObj.content).trim()) ? String(msgObj.content).trim() : '');
          if (isOrderRoom) {
            // determine initial button state using preloaded references
            const midStr = String(mid || '');
            const didStr = String(did || '');
            const already = (midStr && currentOrderReferences && currentOrderReferences.messageIds && currentOrderReferences.messageIds.has(midStr)) || (didStr && currentOrderReferences && currentOrderReferences.designIds && currentOrderReferences.designIds.has(didStr));
            if (already) {
              // show added to all roles
              refButtonHtml = `<div style="margin-top:6px"><button class="btn btn-sm btn-secondary" disabled>Added</button></div>`;
            } else {
              if (isDesigner) {
                // designers can add
                refButtonHtml = `<div style="margin-top:6px"><button class="btn btn-sm btn-outline-secondary add-ref-btn" data-mid="${escapeHtml(midStr)}" data-did="${escapeHtml(didStr)}">Add to Reference</button></div>`;
              } else {
                // clients/managers see the status but cannot add
                refButtonHtml = `<div style="margin-top:6px"><span class="small text-muted">Not added</span></div>`;
              }
            }
          }
        } catch (e) { refButtonHtml = ''; }

        designHtml = `<div class=" p-2 border rounded bg-white" style="display:flex;gap:12px;align-items:center">
            <div style="flex:0 0 72px"><img src="${escapeHtml(imgUrl)}" style="width:72px;height:72px;object-fit:cover;border-radius:6px"/></div>
            <div style="flex:1;min-width:0">
              <div class="fw-semibold" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(title)}</div>
              <div><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="small text-muted">Open</a></div>
              ${refButtonHtml}
            </div>
          </div>`;
      }
    } catch (e) { designHtml = ''; }

    try {
      if ((msgObj.message_type && msgObj.message_type === 'order') || msgObj.order) {
        const o = msgObj.order || {};
        const uploaded = msgObj.uploaded_file || null;
        const imgSrc = (uploaded && uploaded.filepath) || o.image || msgObj.attachment || '';
        let imgUrl = imgSrc || '';
        if (imgUrl && !/^https?:\/\//.test(imgUrl)) imgUrl = imgUrl; // keep relative paths as-is
        const title = o.title || o.designName || o.name || ('Order #' + (o.id || o.orderid || ''));
        const status = o.ostatus || o.status || '';
        const date = o.odate || o.date || '';
        let url = o.url || o.link || '';
        // prepare a role-aware view URL (used for top-right button on group rooms)
        let viewUrl = url || '';
        try {
          if (!viewUrl && o.id) viewUrl = '/client/order_detail.php?orderid=' + encodeURIComponent(o.id);
          if (o.id && typeof userType !== 'undefined') {
            if (String(userType).toLowerCase() === 'designer') {
              if (viewUrl && viewUrl.indexOf('/client/order_detail.php') !== -1) {
                viewUrl = viewUrl.replace('/client/order_detail.php', '/designer/design_orders.php');
                if (viewUrl.indexOf('?') === -1) viewUrl += '?orderid=' + encodeURIComponent(o.id);
              } else {
                viewUrl = '/designer/design_orders.php?orderid=' + encodeURIComponent(o.id);
              }
            } else if (String(userType).toLowerCase() === 'manager') {
              if (viewUrl && viewUrl.indexOf('/client/order_detail.php?orderid=') !== -1) {
                viewUrl = viewUrl.replace('/client/order_detail.php?orderid=', '/Manager/Order_Edit.php?id=');
              } else if (viewUrl && viewUrl.indexOf('/client/order_detail.php') !== -1) {
                viewUrl = viewUrl.replace('/client/order_detail.php', '/Manager/Order_Edit.php') + '?id=' + encodeURIComponent(o.id);
              } else {
                viewUrl = '/Manager/Order_Edit.php?id=' + encodeURIComponent(o.id);
              }
            }
          }
        } catch (e) {}
        const orderIdLabel = (o.id || o.orderid) ? ('Order placed: #' + (o.id || o.orderid)) : '';
        orderHtml = `
          <div class="p-2 border rounded bg-white" style="display:flex;gap:12px;align-items:center;position:relative">
            <div style="flex:0 0 72px">${imgUrl ? ('<img src="' + escapeHtml(imgUrl) + '" style="width:72px;height:72px;object-fit:cover;border-radius:6px"/>') : ('<div style="width:72px;height:72px;border-radius:6px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#666">ORD</div>') }</div>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:start;justify-content:space-between;gap:8px">
                <div class="fw-semibold" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(title)}</div>
                <div style="display:flex;align-items:center;gap:8px;flex:0 0 auto">
                  ${ orderIdLabel ? ('<div class="small text-primary ms-2" style="white-space:nowrap">' + escapeHtml(orderIdLabel) + '</div>') : '' }
                  ${ (isGroup && viewUrl) ? ('<a href="' + escapeHtml(viewUrl) + '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">View</a>') : '' }
                </div>
              </div>
              <div class="small text-muted">${escapeHtml((status || '') + (date ? (' · ' + date) : ''))}</div>
              ${ (function(){
                  // For group rooms we display the view button in the title area already.
                  if (isGroup) return '';
                  if (!url && o.id) url = '/client/order_detail.php?orderid=' + encodeURIComponent(o.id);
                  // Adjust the link for non-group viewers per role
                  try {
                    if (o.id && typeof userType !== 'undefined') {
                      if (String(userType).toLowerCase() === 'designer') {
                        if (url && url.indexOf('/client/order_detail.php') !== -1) {
                          url = url.replace('/client/order_detail.php', '/designer/design_orders.php');
                          if (url.indexOf('?') === -1) url += '?orderid=' + encodeURIComponent(o.id);
                        } else {
                          url = '/designer/design_orders.php?orderid=' + encodeURIComponent(o.id);
                        }
                      } else if (String(userType).toLowerCase() === 'manager') {
                        if (url && url.indexOf('/client/order_detail.php?orderid=') !== -1) {
                          url = url.replace('/client/order_detail.php?orderid=', '/Manager/Order_Edit.php?id=');
                        } else if (url && url.indexOf('/client/order_detail.php') !== -1) {
                          url = url.replace('/client/order_detail.php', '/Manager/Order_Edit.php') + '?id=' + encodeURIComponent(o.id);
                        } else {
                          url = '/Manager/Order_Edit.php?id=' + encodeURIComponent(o.id);
                        }
                      }
                    }
                  } catch(e) {}
                  return url ? ('<div style="margin-top:8px"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">View order</a></div>') : '';
                })() }
            </div>
          </div>`;
      }
    } catch (e) { orderHtml = ''; }
    // Attachment rendering: prefer `uploaded_file` metadata when available.
    let attachmentHtml = '';
    let isImageMessage = false;
    try {
      const uploaded = msgObj.uploaded_file || null;
      // prefer filepath from uploaded_file, fall back to legacy `attachment` field
      const att = String((uploaded && uploaded.filepath) || msgObj.attachment || '');
      if (att) {
        // build absolute-ish URL if relative
        let url = att;
        if (!/^https?:\/\//.test(att)) {
          url = (location.origin + '/' + att.replace(/^\/+/, ''));
        }
        const lower = (uploaded && uploaded.filename ? uploaded.filename.toLowerCase() : att.toLowerCase());
        const mime = uploaded && uploaded.mime ? String(uploaded.mime || '') : '';
        const looksLikeImage = (mime && mime.indexOf('image/') === 0) || /(\.png|\.jpe?g|\.gif|\.webp|\.bmp)$/i.test(lower);
        if (looksLikeImage) {
          isImageMessage = true;
          attachmentHtml = `<img class="chat-attachment-img" src="${escapeHtml(url)}"/>`;
        } else {
          const fname = (uploaded && uploaded.filename) ? uploaded.filename : att.split('/').pop();
          attachmentHtml = `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(fname)}</a>`;
        }
      }
    } catch (e) { attachmentHtml = ''; }
    // If we rendered a design card, avoid rendering the separate contentHtml (which may be a duplicate URL link)
    if (orderHtml) {
      bubble.className = 'bg-white text-dark rounded';
      bubble.innerHTML = `${orderHtml}${campaignHtml}`;
    } else if (designHtml) {
      // Override bubble styling so the message bubble is white (not the usual colored bubble)
      bubble.className = 'bg-white text-dark rounded';
      bubble.innerHTML = `${designHtml}${campaignHtml}`;
    } else {
      // For plain image attachments, use primary bubble for sender 'me', white for others
      if (isImageMessage) {
        if (who === 'me') {
          bubble.className = 'bg-primary text-white rounded p-2';
        } else {
          bubble.className = 'bg-white text-dark rounded p-2';
        }
      }
      // Choose time text color: if the bubble uses `text-white` (blue bubble), make the time white for readability
      let timeClass = 'text-muted';
      try { if (bubble.className && bubble.className.indexOf('text-white') !== -1) timeClass = 'text-white'; } catch (e) {}
      const timeText = formatMessageTimestamp(time);
      // If image message, render image first, then text below
      if (isImageMessage && attachmentHtml) {
        bubble.innerHTML = `${attachmentHtml}<div>${contentHtml}</div>${campaignHtml}`;
      } else {
        bubble.innerHTML = `<div>${contentHtml}</div>${attachmentHtml}${campaignHtml}`;
      }
      // create time element as a sibling outside the bubble (keeps date outside message box)
      var timeEl = document.createElement('div');
      timeEl.className = timeClass + ' small mt-1 message-time';
      timeEl.textContent = timeText;
      // prevent timestamp from forcing bubble width
      timeEl.style.maxWidth = '100%';
      timeEl.style.overflow = 'hidden';
      timeEl.style.textOverflow = 'ellipsis';
    }

    // Only append the sender name for non-self messages
    if (who !== 'me') bodyCol.appendChild(nameEl);
    bodyCol.appendChild(bubble);

    if (who === 'me') {
      wrapper.appendChild(bodyCol);
      wrapper.appendChild(avatarWrap);
    } else {
      wrapper.appendChild(avatarWrap);
      wrapper.appendChild(bodyCol);
    }

    // store sender, side and timestamp on wrapper for grouping checks
    try { wrapper.dataset.senderId = msgObj.sender_id || msgObj.senderId || msgObj.sender || ''; } catch(e) {}
    try { wrapper.dataset.who = who; } catch(e) {}
    try { wrapper.dataset.timestamp = time; } catch(e) {}

    // Determine whether this message should be visually grouped with the previous message
    let grouped = false;
    try {
      const last = elements.messages && elements.messages.lastElementChild;
      // Only group when previous message is from same sender AND on same side (who)
      if (last && last.dataset && last.dataset.senderId && String(last.dataset.senderId) === String(wrapper.dataset.senderId) && String(last.dataset.who) === String(wrapper.dataset.who)) {
        const prevT = new Date(last.dataset.timestamp || 0);
        const curT = new Date(time);
        if (!isNaN(prevT.getTime()) && !isNaN(curT.getTime())) {
          const diff = Math.abs(curT - prevT);
          if (diff <= 60000) grouped = true; // within 1 minute
        }
      }
    } catch(e) { grouped = false; }

    if (existingId) wrapper.setAttribute('data-mid', existingId);
    // If grouped, hide avatar and name and omit timestamp when appending
    if (grouped) {
      try {
        // Remove avatar and name nodes from DOM for grouped messages, but keep a spacer
        try { if (avatar && avatar.parentNode) avatar.parentNode.removeChild(avatar); } catch(e) {}
        // Ensure avatarWrap keeps the same column width so bubbles don't shift
        try {
          if (who !== 'me') {
            avatarWrap.style.flex = '0 0 48px'; avatarWrap.style.width = '48px';
          } else {
            // For self messages keep the avatar column collapsed with 2px margin
            avatarWrap.style.flex = '0 0 0px'; avatarWrap.style.width = '0px'; avatarWrap.style.marginRight = '2px';
          }
        } catch(e) {}
        // Add a tiny spacer to keep vertical alignment predictable
        try {
          if (!avatarWrap.querySelector('.avatar-spacer')) {
            const sp = document.createElement('div');
            sp.className = 'avatar-spacer';
            sp.style.width = '36px'; sp.style.height = '36px'; sp.style.display = 'inline-block';
            avatarWrap.innerHTML = '';
            avatarWrap.appendChild(sp);
          }
        } catch(e) {}
        // Remove the name element entirely so it doesn't take up visual space
        try { if (nameEl && nameEl.parentNode) nameEl.parentNode.removeChild(nameEl); } catch(e) {}
      } catch(e) {}
    }

    elements.messages.appendChild(wrapper);
    // wire up Add to Reference button if present
    try {
      const refBtn = wrapper.querySelector('.add-ref-btn');
      if (refBtn) {
        refBtn.addEventListener('click', function (e) {
          const mid = refBtn.getAttribute('data-mid') || (existingId || msgObj.messageid || msgObj.id || '');
          const did = refBtn.getAttribute('data-did') || (msgObj.share && msgObj.share.designid ? msgObj.share.designid : null);
          const roomId = currentAgent && (currentAgent.ChatRoomid || currentAgent.id || currentAgent.roomId) ? (currentAgent.ChatRoomid || currentAgent.id || currentAgent.roomId) : (elements.messages && elements.messages.dataset.roomId ? elements.messages.dataset.roomId : null);
          try {
            addReference(roomId, mid || null, did || null, refBtn).catch(() => {});
          } catch (e) { console.error('add-ref click handler failed', e); }
        });
      }
    } catch (e) {}
    // Append timestamp to the current message. If this message is grouped with the previous
    // one, remove the previous message's timestamp so the latest in the group shows the date.
    try {
      // determine time class: prefer the name's color class when present, otherwise fall back to bubble text color
      var timeClassFinal = 'text-muted';
      try {
        if (nameColorClass) timeClassFinal = nameColorClass;
        else if (bubble.className && bubble.className.indexOf('text-white') !== -1) timeClassFinal = 'text-white';
      } catch (e) {}
      if (typeof timeEl !== 'undefined' && timeEl) {
        timeEl.className = timeClassFinal + ' small mt-1 message-time';
        bodyCol.appendChild(timeEl);
      } else {
        var designTimeEl = document.createElement('div');
        designTimeEl.className = timeClassFinal + ' small mt-1 message-time';
        designTimeEl.textContent = formatMessageTimestamp(time);
        bodyCol.appendChild(designTimeEl);
      }
      // If grouped, remove previous message's timestamp so only latest shows
      if (grouped) {
        try {
          const prev = wrapper.previousElementSibling;
          if (prev) {
            const prevTime = prev.querySelector && prev.querySelector('.message-time');
            if (prevTime) prevTime.remove();
          }
        } catch(e) {}
      }
    } catch (e) {}
    // wire up image click-to-enlarge for any rendered attachment images
    try {
      const imgEl = wrapper.querySelector('.chat-attachment-img');
      if (imgEl) {
        imgEl.addEventListener('click', () => {
          try {
            // If message content is a URL, navigate to it. Otherwise open preview modal.
            const msgContent = msgObj.content || msgObj.body || '';
            if (typeof msgContent === 'string' && /^https?:\/\//i.test(msgContent.trim())) {
              window.open(msgContent.trim(), '_blank', 'noopener');
            } else {
              openPreviewModal(imgEl.src);
            }
          } catch (e) { openPreviewModal(imgEl.src); }
        });
      }
    } catch (e) {}
  }

  // Return the browser/device preferred locale (language tag)
  function getPreferredLocale() {
    try {
      if (navigator.languages && navigator.languages.length) return navigator.languages[0];
      return navigator.language || navigator.userLanguage || 'en-US';
    } catch (e) { return 'en-US'; }
  }

  // Detect a preferred speech synthesis voice that best matches the browser/device locale.
  // Returns a Promise that resolves to a SpeechSynthesisVoice or null if unavailable.
  function detectPreferredVoice() {
    const langs = [];
    try {
      if (navigator.languages && navigator.languages.length) langs.push(...navigator.languages);
      if (navigator.language) langs.push(navigator.language);
    } catch (e) {}
    // ensure at least one entry
    if (!langs.length) langs.push('en-US');

    return new Promise(resolve => {
      if (!('speechSynthesis' in window)) return resolve(null);
      function choose(voices) {
        if (!voices || !voices.length) return resolve(null);
        // exact locale match
        for (const l of langs) {
          if (!l) continue;
          const found = voices.find(v => v.lang && v.lang.toLowerCase() === l.toLowerCase());
          if (found) return resolve(found);
        }
        // primary language match (e.g., 'en' matches 'en-US')
        for (const l of langs) {
          const primary = (l || '').split('-')[0];
          if (!primary) continue;
          const found = voices.find(v => ((v.lang||'').split('-')[0]) === primary);
          if (found) return resolve(found);
        }
        // fallback to any English voice
        const en = voices.find(v => (v.lang || '').toLowerCase().startsWith('en'));
        if (en) return resolve(en);
        // last resort: first available voice
        return resolve(voices[0]);
      }

      const voices = speechSynthesis.getVoices();
      if (voices && voices.length) return choose(voices);
      const onVoices = () => { speechSynthesis.removeEventListener('voiceschanged', onVoices); choose(speechSynthesis.getVoices()); };
      speechSynthesis.addEventListener('voiceschanged', onVoices);
      // safety timeout in case event doesn't fire
      setTimeout(() => { choose(speechSynthesis.getVoices()); }, 800);
    });
  }

  function selectAgent(agent) {
    currentAgent = agent;
    const rid = agent.ChatRoomid || agent.id || agent.roomId;
    const stored = (rid ? localStorage.getItem('chat_other_name_' + rid) : null);
    // For group rooms prefer the room's name (roomname) in the header; for private rooms show the other participant's name.
    let isGroupAgent = false;
    try { isGroupAgent = ((agent.room_type || agent.roomType || agent.type) + '').toString().toLowerCase() === 'group'; } catch(e) { isGroupAgent = false; }
    let displayName = '';
    if (isGroupAgent) {
      displayName = agent.roomname || agent.roomName || agent.name || agent.other_name || agent.otherName || stored || '';
    } else {
      displayName = agent.other_name || agent.otherName || stored || agent.roomname || agent.name || '';
    }
    if (elements.connectionStatus) elements.connectionStatus.textContent = displayName;
    document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
      el.classList.toggle('active', el.dataset.roomId == (agent.ChatRoomid || agent.id));
    });
    const roomId = agent.ChatRoomid || agent.id || agent.roomId;
    lastMessageId = 0;
    startPolling(roomId);
    if (elements.messages) elements.messages.dataset.roomId = roomId;
    // Load messages and ensure the view scrolls to the newest message after load.
    const p = loadMessages(roomId);
    p.then((messages) => {
      try { if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight; } catch (e) {}
      // second delayed scroll to handle images or layout shifts
      setTimeout(() => { try { if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight; } catch (e) {} }, 250);
      // Clear unread counter for this room since user viewed its messages
      try { if (roomId) { clearUnread(roomId); updateAgentBadge(roomId, 0); } } catch(e){}
      // After loading messages, if this room has an 'order' message, expose header View button
      try {
        const prefixLocal = prefix; // closure
        const headerViewBtn = document.getElementById(prefixLocal + 'view_btn');
        if (headerViewBtn) headerViewBtn.style.display = 'none';
        if (Array.isArray(messages)) {
          let foundOrder = null;
          for (const m of messages) {
            try {
              if ((m.message_type && m.message_type === 'order') || m.order) {
                if (m.order && (m.order.id || m.order.orderid)) { foundOrder = m.order; break; }
                if (m.content && String(m.content).match(/^\d+$/)) { foundOrder = { id: parseInt(m.content,10) }; break; }
              }
            } catch(e){}
          }
          if (foundOrder && headerViewBtn) {
            // compute role-aware URL (same logic used to render order card)
            let viewUrl = foundOrder.url || '';
            try { if (!viewUrl && foundOrder.id) viewUrl = '/client/order_detail.php?orderid=' + encodeURIComponent(foundOrder.id); } catch(e){}
            try {
              if (foundOrder.id && typeof userType !== 'undefined') {
                if (String(userType).toLowerCase() === 'designer') {
                  if (viewUrl && viewUrl.indexOf('/client/order_detail.php') !== -1) {
                    viewUrl = viewUrl.replace('/client/order_detail.php', '/designer/design_orders.php');
                    if (viewUrl.indexOf('?') === -1) viewUrl += '?orderid=' + encodeURIComponent(foundOrder.id);
                  } else {
                    viewUrl = '/designer/design_orders.php?orderid=' + encodeURIComponent(foundOrder.id);
                  }
                } else if (String(userType).toLowerCase() === 'manager') {
                  if (viewUrl && viewUrl.indexOf('/client/order_detail.php?orderid=') !== -1) {
                    viewUrl = viewUrl.replace('/client/order_detail.php?orderid=', '/Manager/Order_Edit.php?id=');
                  } else if (viewUrl && viewUrl.indexOf('/client/order_detail.php') !== -1) {
                    viewUrl = viewUrl.replace('/client/order_detail.php', '/Manager/Order_Edit.php') + '?id=' + encodeURIComponent(foundOrder.id);
                  } else {
                    viewUrl = '/Manager/Order_Edit.php?id=' + encodeURIComponent(foundOrder.id);
                  }
                }
              }
            } catch(e){}
            if (viewUrl) {
              headerViewBtn.href = viewUrl;
              headerViewBtn.style.display = '';
            }
          }
        }
      } catch(e){}
    }).catch(() => {});
    return p;
  }

  // Resolve currently selected room id from `currentAgent` or DOM fallback
  function getSelectedRoomId() {
    let roomId = currentAgent?.ChatRoomid || currentAgent?.id || currentAgent?.roomId;
    if (roomId) return roomId;
    // fallback to polling state
    if (currentRoomId) return currentRoomId;
    if (roomId) return roomId;
    // try active element in main list
    const sel = document.querySelector('#agentsList .list-group-item.active') || document.querySelector('#agentsListOffcanvas .list-group-item.active');
    if (sel && sel.dataset && sel.dataset.roomId) return sel.dataset.roomId;
    // fallback to first available room in list
    const first = document.querySelector('#agentsList .list-group-item') || document.querySelector('#agentsListOffcanvas .list-group-item');
    if (first && first.dataset && first.dataset.roomId) return first.dataset.roomId;
    // Additional fallback: scan children of agentsList for hrefs or text containing an id
    try {
      const scan = (container) => {
        if (!container) return null;
        const nodes = container.querySelectorAll('a,button,div,li');
        for (let n of nodes) {
          // data attributes
          const ds = n.dataset || {};
          if (ds.roomId) return ds.roomId;
          if (ds.roomid) return ds.roomid;
          // href query like ?room=3 or ChatRoomid=3
          const href = n.getAttribute && n.getAttribute('href');
          if (href) {
            const m = href.match(/(?:room|ChatRoomid|ChatRoomId)=?(\d+)/i);
            if (m) return m[1];
            const m2 = href.match(/\/(?:chatroom|room)\/(\d+)/i);
            if (m2) return m2[1];
          }
          // inner text numeric heuristic
          const txt = (n.textContent || '').match(/\b(\d{1,6})\b/);
          if (txt) return txt[1];
        }
        return null;
      };
      const scanned = scan(document.getElementById('agentsList')) || scan(document.getElementById('agentsListOffcanvas')) || null;
      if (scanned) return scanned;
    } catch (e) { console.warn('room scan failed', e); }
    return null;
  }

  function openRoom(roomId) {
    try {
      // Accept either a numeric id or an object with hints (e.g., { designerid: 12 })
      if (!roomOrId) return Promise.resolve();
      if (typeof roomOrId === 'object') {
        const obj = roomOrId;
        // If caller provided a designer id, attempt to create/open a room via handleChat
        if (obj.designerid || obj.designerId) {
          const did = obj.designerid || obj.designerId;
          if (window.handleChat) {
            return window.handleChat(did, { creatorId: window.chatUserId || 0, otherName: obj.otherName || obj.name || '' })
              .then(res => {
                const rid = res && (res.roomId || (res.room && (res.room.ChatRoomid || res.room.id)));
                if (rid) return openRoom(rid);
                return Promise.resolve();
              }).catch(() => Promise.resolve());
          }
          return Promise.resolve();
        }
        // If object already contains room id fields, extract and continue
        if (obj.roomId || obj.ChatRoomid || obj.id) roomOrId = obj.roomId || obj.ChatRoomid || obj.id;
        else return Promise.resolve();
      }

      const id = parseInt(roomOrId, 10);
      if (!id) return Promise.resolve();

      // try to find the room in loaded agents, otherwise select a minimal placeholder
      return loadAgents().then(rooms => {
        let found = null;
        try { found = rooms && rooms.find(r => String(r.ChatRoomid || r.id || r.roomId) === String(id)); } catch(e){}
        if (found) return selectAgent(found);
        return selectAgent({ ChatRoomid: id, roomname: 'Room ' + id });
      }).catch(err => { return selectAgent({ ChatRoomid: id, roomname: 'Room ' + id }); });
    } catch (e) { return Promise.resolve(); }
  }

  async function sendMessage() {
    // determine room: prefer currentAgent, fall back to active DOM selection or first room
    let roomId = getSelectedRoomId();
    if (!roomId) { alert('Please select a person to chat with.'); return; }
    const text = (elements.input && elements.input.value || '').trim();
    // If there's a pending file selection (widget or main), upload that file instead of sending text immediately
    const fileToUpload = widgetPendingFile || pendingFile;
    if (fileToUpload) {
      // ensure we have roomId
      if (!roomId) { alert('Please select a person to chat with.'); return; }
      // prepare FormData and upload
      const file = fileToUpload;
      // clear pending references immediately to avoid double-send
      widgetPendingFile = null; pendingFile = null;
      if (elements.send) elements.send.disabled = true;
      try {
        const fd = new FormData();
        fd.append('sender_type', userType);
        fd.append('sender_id', userId);
        fd.append('room', roomId);
        fd.append('message_type', file.type && file.type.startsWith('image/') ? 'image' : 'file');
        // include any typed text so the attachment can be sent together with a message
        try { const txt = (elements.input && elements.input.value || '').trim(); if (txt) fd.append('content', txt); } catch(e) {}
        fd.append('attachment', file, file.name);
        const resp = await apiPostForm('sendMessage', fd);
        if (resp && resp.ok) {
          const created = resp.message || { content: '', created_at: new Date().toISOString(), id: resp.id };
          // canonicalize possible attachment path from server response
          try {
            const msg = resp.message || {};
            const cand = msg.attachment || msg.filepath || msg.file || resp.attachmentPath || resp.filepath || null;
            if (cand) {
              // if cand is object with filepath, prefer that
              if (typeof cand === 'object' && cand.filepath) created.attachment = cand.filepath;
              else created.attachment = cand;
            }
          } catch(e){}
          appendMessageToUI(created, 'me');
          const mid = created.id || created.messageid || created.messageId || resp.id || 0;
          if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
          if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
          try { apiPost('markRead', { room: roomId, user_type: userType, user_id: userId }).catch(()=>{}); } catch(e){}
          try { clearUnread(roomId); } catch(e){}
        } else {
          alert('Failed to upload file');
        }
      } catch (err) {
        console.error('file upload error', err);
        alert('Upload error: ' + (err && err.message ? err.message : ''));
      } finally {
        // clear preview & re-enable send
          try { clearSelectedPreview(document.getElementById(prefix + 'attachPreviewColumn') || document.getElementById('attachPreviewColumn') || document.getElementById('chatwidget_attachPreviewColumn')); } catch(e){}
        if (elements.send) elements.send.disabled = false;
      }
      return;
    }
    if (!text) return;
    const campaignVal = (elements.campaign && elements.campaign.value.trim()) || null;
    // ensure currentAgent is set for UI state
    if (!currentAgent) {
      currentAgent = { ChatRoomid: roomId, roomname: '' };
      document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
        el.classList.toggle('active', el.dataset.roomId == roomId);
      });
      lastMessageId = 0;
      startPolling(roomId);
      // do not await loadMessages here to avoid blocking send
    }
    // disable send while in-flight
    if (elements.send) elements.send.disabled = true;
    try {
      const res = await apiPost('sendMessage', { sender_type: userType, sender_id: userId, content: text, room: roomId });
      if (res && (res.ok || res.id || res.message)) {
        const created = (res.message) ? res.message : { content: text, created_at: new Date().toISOString(), id: res.id };
        // ensure sender_name is present
        if (!created.sender_name) created.sender_name = userName || (userType + ' ' + userId);
        appendMessageToUI(created, 'me');
        const mid = created.id || created.messageid || created.messageId || res.id || 0;
        if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
        if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
        try { apiPost('markRead', { room: roomId, user_type: userType, user_id: userId }).catch(()=>{}); } catch(e){}
        try { clearUnread(roomId); } catch(e){}
      } else {
        console.warn('sendMessage failed response', res);
        alert('Failed to send message');
      }
    } catch (err) {
      console.error('sendMessage error', err);
      alert('Error sending message: ' + (err && err.message ? err.message : '')); 
    } finally {
      // clear input regardless (improves UX) and re-enable send
      if (elements.input) elements.input.value = '';
      if (elements.send) elements.send.disabled = false;
    }
  }

  function onCatalogItemClick(ev) {
    const card = ev.target.closest('.catalog-item');
    if (!card) return;
    const likes = card.dataset.likes || '—';
    const price = card.dataset.price || '—';
    const title = card.dataset.title || 'Design';
    const campaignVal = (elements.campaign && elements.campaign.value.trim()) || '';
    const campaign = campaignVal ? `Campaign: ${campaignVal} · ` : '';
    const previewHtml = `<div class="product-preview d-flex align-items-center"><div class="thumb me-2">Living room</div><div><div class="fw-bold">${escapeHtml(title)}</div><div class="text-muted small">${escapeHtml(campaign)}${likes} Likes · <span class="fw-semibold">${price}</span></div></div></div>`;
    if (currentAgent) {
      const roomId = currentAgent?.ChatRoomid || currentAgent?.id || currentAgent?.roomId;
      apiPost('sendMessage', { sender_type: userType, sender_id: userId, content: previewHtml, room: roomId }).then(resp => {
        if (resp.ok) {
          const created = resp.message || { content: previewHtml, created_at: new Date().toISOString(), id: resp.id };
          if (!created.sender_name) created.sender_name = userName || (userType + ' ' + userId);
          appendMessageToUI(created, 'me');
        }
      }).catch(console.error);
    } else {
      if (elements.messages) {
        const tmp = { content: previewHtml, sender_name: userName || (userType + ' ' + userId), timestamp: new Date().toISOString() };
        appendMessageToUI(tmp, 'me');
      }
    }
  }

  // Attach input handling: wire attach button to hidden file input (desktop & widget)
  (function wireAttachInputs(){
    // main composer
    const attachBtn = document.getElementById('attach');
    const attachInput = document.getElementById('attachInput');
    let attachPreview = document.getElementById('attachPreviewColumn');
    if (attachBtn && attachInput) {
      let attachClickGuard = false;
      const isPreviewVisibleMain = () => {
        const pv = document.getElementById(prefix + 'attachPreviewColumn') || document.getElementById('attachPreviewColumn') || document.getElementById('chatwidget_attachPreviewColumn') || attachPreview;
        if (!pv) return false;
        try {
          if (pv.classList && pv.classList.contains('visible')) return true;
          const holder = pv.querySelector && (pv.querySelector('#chatwidget_attachPreview_content') || pv.querySelector('.message-preview-content'));
          return !!(holder && holder.innerHTML && holder.innerHTML.trim());
        } catch (e) { return false; }
      };

      attachBtn.addEventListener('click', (ev) => {
        if (attachClickGuard) return; attachClickGuard = true; setTimeout(() => attachClickGuard = false, 600);
        try {
          const pv = document.getElementById(prefix + 'attachPreviewColumn') || document.getElementById('attachPreviewColumn') || document.getElementById('chatwidget_attachPreviewColumn') || attachPreview;
          if (pv) {
            const holder = pv.querySelector && (pv.querySelector('#chatwidget_attachPreview_content') || pv.querySelector('.message-preview-content'));
            const hasContent = !!(holder && holder.innerHTML && holder.innerHTML.trim());
            const isVisible = pv.classList && pv.classList.contains('visible');
            if (isVisible) { try { clearSelectedPreview(pv); } catch (e) {} ; return; }
            if (!isVisible && hasContent) { try { setPreviewVisibility(pv, true); } catch (e) {} ; return; }
          }
        } catch (e) {}
        attachInput.click();
      });
      attachInput.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        // send as attachment in current conversation
        // ensure preview container exists
        try {
            if (!attachPreview) {
            console.warn('attachPreview missing, creating temporary preview element');
            attachPreview = document.createElement('div');
            attachPreview.id = 'attachPreviewColumn';
            attachPreview.className = 'd-flex justify-content-around';
            // Insert preview as a full-width row above the composer (if available)
            const composer = elements.input ? elements.input.closest('.composer') : null;
            if (composer && composer.parentNode) {
              // create a separate row before the composer so layout stays stable
              composer.parentNode.insertBefore(attachPreview, composer);
            } else if (attachBtn && attachBtn.parentNode) {
              attachBtn.parentNode.insertBefore(attachPreview, attachBtn);
            }
            }
          showSelectedPreview(attachPreview, file);
        } catch(ex){ console.error('preview error', ex); }
        let roomId = getSelectedRoomId();
        // as extra fallback, try to find any data-room attributes in the DOM
        if (!roomId) {
          const any = document.querySelector('[data-roomid],[data-room-id]');
          if (any && any.dataset) {
            roomId = any.dataset.roomid || any.dataset.roomId || any.getAttribute('data-room-id');
            console.info('fallback roomId from any element', roomId);
          }
        }
        // If still not found, and there is exactly one agent in the list, auto-select it
        if (!roomId) {
          try {
            const list = document.getElementById('agentsList');
            if (list) {
              const items = list.querySelectorAll('.list-group-item');
              if (items.length === 1) {
                const it = items[0];
                roomId = it.dataset.roomId || it.getAttribute('data-room-id') || it.getAttribute('data-roomid') || null;
                if (roomId) {
                  // silently use the single room for upload without forcing UI highlight
                  currentAgent = { ChatRoomid: roomId, roomname: (it.querySelector('.fw-semibold') ? it.querySelector('.fw-semibold').textContent : '') };
                  lastMessageId = 0; startPolling(roomId);
                  console.info('using single agent roomId (no UI auto-select)', roomId);
                }
              }
            }
          } catch(e) { console.warn('auto-select failed', e); }
        }
        if (!roomId) {
          console.info('attach aborted: no roomId found', {currentAgent,currentRoomId,messagesRoom: elements.messages && elements.messages.dataset && elements.messages.dataset.roomId});
          alert('Select a conversation first'); clearSelectedPreview(attachPreview); return; }
        if (!currentAgent) {
          currentAgent = { ChatRoomid: roomId, roomname: '' };
          document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
            el.classList.toggle('active', el.dataset.roomId == roomId);
          });
          lastMessageId = 0; startPolling(roomId);
        }
        // Defer upload: store file until user presses Send
        pendingFile = file;
        // keep preview visible; clear native input so the same file can be re-selected later
        attachInput.value = '';
      });
    }
    // widget composer
    const wab = document.getElementById('chatwidget_attach');
    const waist = document.getElementById('chatwidget_attachInput');
    let widgetPreview = document.getElementById('chatwidget_attachPreviewColumn');
    if (wab && waist) {
      let widgetClickGuard = false;
      wab.addEventListener('click', () => {
        if (widgetClickGuard) return; widgetClickGuard = true; setTimeout(() => widgetClickGuard = false, 600);
        try {
          const pv = document.getElementById(prefix + 'chatwidget_attachPreviewColumn') || document.getElementById('chatwidget_attachPreviewColumn') || widgetPreview;
          let visible = false;
          if (pv) {
            if (pv.classList && pv.classList.contains('visible')) visible = true;
            else {
              const holder = pv.querySelector && (pv.querySelector('#chatwidget_attachPreview_content') || pv.querySelector('.message-preview-content'));
              visible = !!(holder && holder.innerHTML && holder.innerHTML.trim());
            }
          }
          if (visible) { try { clearSelectedPreview(pv || document.getElementById('chatwidget_attachPreviewColumn')); } catch (e) {} ; return; }
        } catch (e) {}
        waist.click();
      });
      waist.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        try {
            if (!widgetPreview) {
            console.warn('widgetPreview missing, creating temporary element');
            widgetPreview = document.createElement('div');
            widgetPreview.id = 'chatwidget_attachPreviewColumn';
            widgetPreview.className = 'd-flex justify-content-around';
            // Try to insert preview as a separate full-width row above the composer for the widget
            const composer = elements.input ? elements.input.closest('.composer') : null;
            if (composer && composer.parentNode) {
              composer.parentNode.insertBefore(widgetPreview, composer);
            } else if (wab && wab.parentNode) {
              wab.parentNode.insertBefore(widgetPreview, wab);
            }
          }
          showSelectedPreview(widgetPreview, file);
        } catch(ex){ console.error('widget preview error', ex); }
        // using widget IDs: currentAgent context from app.selectAgent isn't available here
        // try to use selected agent in agents list; if none selected, auto-select the first available
        let sel = document.querySelector('#chatwidget_agentsList .list-group-item.active');
        if (!sel) {
          const list = document.getElementById('chatwidget_agentsList');
          if (list) {
            const items = list.querySelectorAll('.list-group-item');
            if (items.length >= 1) {
              sel = items[0];
              const rid = sel.dataset.roomId || sel.getAttribute('data-room-id') || sel.getAttribute('data-roomid');
              // silently use first room but do not mutate active UI state
              currentAgent = { ChatRoomid: rid, roomname: (sel.querySelector('.fw-semibold') ? sel.querySelector('.fw-semibold').textContent : '') };
              lastMessageId = 0; startPolling(rid);
              console.debug('chatwidget: using first room silently', rid);
            }
          }
        }
        if (!sel) { alert('Select a conversation first'); clearSelectedPreview(widgetPreview); return; }
        const roomId = sel.dataset.roomId || sel.getAttribute('data-room-id') || sel.getAttribute('data-roomid');
        console.debug('chatwidget: attaching to roomId', roomId);
        if (!currentAgent) {
          const rn = sel.querySelector('.fw-semibold') ? sel.querySelector('.fw-semibold').textContent : '';
          currentAgent = { ChatRoomid: roomId, roomname: rn };
          lastMessageId = 0; startPolling(roomId);
        }
        // Defer upload: store file until user presses Send (widget)
        widgetPendingFile = file;
        // keep preview visible; clear native input so the same file can be re-selected later
        waist.value = '';
      });
    }
  })();

  // preview helpers
  function setPreviewVisibility(container, visible) {
    if (!container) return;
    const panelClose = container.querySelector('#chatwidget_attachPreview_close') || document.getElementById('chatwidget_attachPreview_close');
    const panelEl = document.getElementById(prefix + 'panel') || document.getElementById('chatwidget_panel');
    if (visible) {
      try { container.classList.add('attached'); } catch (e) {}
      setTimeout(() => { try { container.classList.add('visible'); } catch (e) {} }, 24);
      try { container.style.display = ''; } catch (e) {}
      if (panelClose) try { panelClose.style.display = 'flex'; } catch (e) {}
      if (panelEl) {
        try { panelEl.classList.add('preview-visible'); } catch (e) {}
        try { panelEl.style.setProperty('--preview-height', Math.round(container.getBoundingClientRect().height) + 'px'); } catch (e) {}
      }
    } else {
      try { container.classList.remove('visible','attached'); } catch (e) {}
      try { container.style.display = 'none'; } catch (e) {}
      if (panelClose) try { panelClose.style.display = 'none'; } catch (e) {}
      if (panelEl) {
        try { panelEl.classList.remove('preview-visible'); } catch (e) {}
        try { panelEl.style.setProperty('--preview-height', '0px'); } catch (e) {}
      }
    }
  }

  function showSelectedPreview(container, file) {
    if (!container || !file) return;

    // Clear any other preview content holders to avoid duplicate thumbnails (widget vs main composer)
    try {
      document.querySelectorAll('#chatwidget_attachPreview_content').forEach(ch => {
        const parent = ch.parentElement;
        if (parent && parent !== container) {
          try { clearSelectedPreview(parent); } catch (e) {}
        }
      });
    } catch (e) {}

    // Remove any stray preview nodes already present directly inside this container
    try {
      const stray = container.querySelectorAll(':scope > .preview-img, :scope > .file-badge, :scope > .file-meta, :scope > .preview-remove');
      stray.forEach(n => { try { n.remove(); } catch(e){} });
    } catch (e) {}

    const canonicalId = 'chatwidget_attachPreview_content';
    // Prefer a single canonical content holder. Consolidate any stray '*_content' nodes into it.
    let contentHolder = document.getElementById(canonicalId);
    // find any nodes that look like legacy per-container content holders (e.g., '*_content')
    try {
      const legacy = Array.from(document.querySelectorAll('[id$="_content"]'));
      // create canonical if missing
      if (!contentHolder) {
        contentHolder = document.createElement('div');
        contentHolder.className = 'message-preview-content d-flex align-items-center justify-content-between p-2';
        try { contentHolder.id = canonicalId; } catch (e) {}
        // attach temporarily to document body; we'll move into container below
        (document.body || document.documentElement).appendChild(contentHolder);
      }
      // move children from legacy holders into canonical, then remove legacy nodes
      legacy.forEach(node => {
        try {
          if (!node || node.id === canonicalId) return;
          while (node.firstChild) contentHolder.appendChild(node.firstChild);
          node.parentElement && node.parentElement.removeChild(node);
        } catch (e) {}
      });
    } catch (e) {}
    // ensure canonical is inside the active container
    try {
      if (contentHolder.parentElement !== container) container.appendChild(contentHolder);
    } catch (e) {}
    // clear previous content
    contentHolder.innerHTML = '';

    // render thumbnail (image) or badge (other files)
    if (file.type && file.type.startsWith('image/')) {
      const img = document.createElement('img');
      const objUrl = URL.createObjectURL(file);
      container._objUrl = objUrl;
      img.src = objUrl;
      img.className = 'preview-img me-2';
      img.addEventListener('click', () => openPreviewModal(objUrl));
      contentHolder.appendChild(img);
    } else {
      const ext = (file.name || '').split('.').pop() || '';
      const badge = document.createElement('div');
      badge.className = 'file-badge file-badge-inline me-2';
      badge.textContent = ext.toUpperCase();
      contentHolder.appendChild(badge);
    }

    // metadata
    const meta = document.createElement('div');
    meta.className = 'file-meta preview-grow d-flex flex-column';
    const name = document.createElement('div');
    name.className = 'name';
    name.textContent = file.name || '';
    meta.appendChild(name);
    if (file.size) {
      const size = document.createElement('div');
      size.className = 'size';
      size.textContent = Math.round((file.size || 0) / 1024) + ' KB';
      meta.appendChild(size);
    }
    contentHolder.appendChild(meta);

    // remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-link preview-remove';
    removeBtn.setAttribute('aria-label', 'Remove attachment');
    removeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    removeBtn.addEventListener('click', () => {
      clearSelectedPreview(container);
      try { widgetPendingFile = null; pendingFile = null; } catch (e) {}
      try { const a = document.getElementById(prefix + 'attachInput') || document.getElementById('attachInput'); if (a) a.value = ''; } catch (e) {}
      try { const b = document.getElementById(prefix + 'chatwidget_attachInput') || document.getElementById('chatwidget_attachInput'); if (b) b.value = ''; } catch (e) {}
    });
    contentHolder.appendChild(removeBtn);

    // wire panel close control once
    const panelClose = container.querySelector('#chatwidget_attachPreview_close') || document.getElementById('chatwidget_attachPreview_close');
    if (panelClose && !panelClose._wired) {
      panelClose.addEventListener('click', () => clearSelectedPreview(container));
      panelClose._wired = true;
    }

    // show preview and update panel CSS var for animation
    const hasContent = contentHolder.innerHTML && contentHolder.innerHTML.trim();
    if (hasContent) {
      setPreviewVisibility(container, true);
    } else {
      setPreviewVisibility(container, false);
    }
  }

  function clearSelectedPreview(container) {
    if (!container) return;
    // remove any recognized preview content nodes (new canonical id first)
    const contentHolder = document.querySelector('#chatwidget_attachPreview_content') || container.querySelector('#chatwidget_attachPreview_content') || container.querySelector('.message-preview-content');
    if (contentHolder) contentHolder.innerHTML = '';
    // remove any stray preview elements that may have been appended directly to the container
    try {
      const stray = container.querySelectorAll('.preview-img, .file-badge, .file-meta, .preview-remove, .message-preview-content');
      stray.forEach(n => { if (n && n.parentNode) n.parentNode.removeChild(n); });
    } catch (e) {}
    // hide via centralized helper to keep CSS var & classes consistent
    try { setPreviewVisibility(container, false); } catch (e) {}
    try {
      const pc = container.querySelector('#chatwidget_attachPreview_close') || document.getElementById('chatwidget_attachPreview_close');
      if (pc) pc.style.display = 'none';
    } catch (e) {}
    if (container._objUrl) {
      try { URL.revokeObjectURL(container._objUrl); } catch (e) {}
      delete container._objUrl;
    }
    try { widgetPendingFile = null; pendingFile = null; } catch (e) {}
    const panelEl = document.getElementById(prefix + 'panel') || document.getElementById('chatwidget_panel');
    if (panelEl) {
      panelEl.classList.remove('preview-visible');
      try { panelEl.style.setProperty('--preview-height', '0px'); } catch (e) {}
    }
  }

  // create/open a simple modal overlay for previewing images larger
  function openPreviewModal(src) {
    if (!src) return;
    const id = prefix + 'previewModal';
    let modal = document.getElementById(id);
    if (!modal) {
      modal = document.createElement('div'); modal.id = id;
      modal.style.position = 'fixed'; modal.style.left = '0'; modal.style.top = '0'; modal.style.right = '0'; modal.style.bottom = '0';
      modal.style.display = 'flex'; modal.style.alignItems = 'center'; modal.style.justifyContent = 'center'; modal.style.background = 'rgba(0,0,0,0.85)'; modal.style.zIndex = 20000;
      modal.style.cursor = 'zoom-out';
      const img = document.createElement('img'); img.id = prefix + 'previewModalImg'; img.style.maxWidth = '90vw'; img.style.maxHeight = '90vh'; img.style.boxShadow = '0 6px 30px rgba(0,0,0,0.6)'; img.style.borderRadius = '6px'; img.style.objectFit = 'contain';
      modal.appendChild(img);
      modal.addEventListener('click', () => { try { modal.style.display = 'none'; const i = document.getElementById(prefix + 'previewModalImg'); if (i && i.dataset && i.dataset.objurl) { try { URL.revokeObjectURL(i.dataset.objurl); } catch(e){} delete i.dataset.objurl; } } catch(e){} });
      document.body.appendChild(modal);
    }
    const img = document.getElementById(prefix + 'previewModalImg');
    if (!img) return;
    img.src = src;
    try { img.dataset.objurl = (src && src.indexOf('blob:') === 0) ? src : ''; } catch(e){}
    modal.style.display = 'flex';
  }


  if (elements.send) elements.send.addEventListener('click', sendMessage);
  if (elements.input) {
    elements.input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
    elements.input.addEventListener('input', () => {
      if (!currentRoomId && currentAgent) currentRoomId = currentAgent.ChatRoomid || currentAgent.id || currentAgent.roomId;
      if (!currentRoomId) return;
      if (typingThrottle) return;
      typingThrottle = setTimeout(() => { clearTimeout(typingThrottle); typingThrottle = null; }, 1800);
      apiPost('typing', { room: currentRoomId, sender_type: userType, sender_id: userId }).catch(() => {});
    });
  }
  document.addEventListener('click', onCatalogItemClick);

  const campaignDesktop = elements.campaign;
  const campaignMobile = elements.campaignOff;
  if (campaignDesktop && campaignMobile) {
    campaignDesktop.addEventListener('input', () => { campaignMobile.value = campaignDesktop.value; });
    campaignMobile.addEventListener('input', () => { campaignDesktop.value = campaignMobile.value; });
  }

  renderCards(items);
  loadAgents();
  // Periodically refresh the agents/rooms list so newly created group rooms
  // show up for other members without requiring a manual reload.
  try {
    if (agentsPollTimer) clearInterval(agentsPollTimer);
    agentsPollTimer = setInterval(() => { try { loadAgents(); } catch(e){} }, 10000);
  } catch(e) {}

  // Restore persisted total unread badge immediately (in case widget was closed or toggle not present earlier)
  try { const persisted = parseInt(localStorage.getItem('chat_total_unread') || '0', 10) || 0; if (persisted) updateTotalUnreadBadge(persisted); } catch(e){}

  // Short-poll for total unread to reduce latency (lightweight endpoint)
  let totalPollTimer = null;
  try {
    if (totalPollTimer) clearInterval(totalPollTimer);
    totalPollTimer = setInterval(() => {
      try {
        // Use ChatApi.getTotalUnread which is optimized to return a single count
        apiPost('getTotalUnread', { user_type: userType, user_id: userId }).then(resp => {
          try {
            if (resp && resp.ok) {
              const tot = parseInt(resp.total || 0, 10) || 0;
              updateTotalUnreadBadge(tot);
            }
          } catch (e) { console.debug('fast unread parse failed', e); }
        }).catch(() => {});
      } catch(e){}
    }, 3000);
  } catch(e) {}

  // Make the agents column resizable via the right-edge resizer
  (function enableAgentsResizer(){
    const col = document.getElementById('agentsColumn');
    const resizer = document.getElementById('agentsResizer');
    if (!col || !resizer) return;
    let dragging = false, startX = 0, startWidth = 0;
    const min = 180; // px
    const maxPct = 0.75; // max fraction of container
    resizer.addEventListener('pointerdown', (e) => {
      dragging = true; startX = e.clientX; startWidth = col.getBoundingClientRect().width;
      resizer.setPointerCapture && resizer.setPointerCapture(e.pointerId);
      document.addEventListener('pointermove', onMove);
      document.addEventListener('pointerup', onUp);
      e.preventDefault();
    });
    function onMove(e){ if(!dragging) return; const dx = e.clientX - startX; const newW = Math.max(min, startWidth + dx); const containerW = col.parentElement.getBoundingClientRect().width; const maxW = Math.round(containerW * maxPct); const finalW = Math.min(newW, maxW); col.style.flex = '0 0 ' + finalW + 'px'; }
    function onUp(e){ if(!dragging) return; dragging = false; document.removeEventListener('pointermove', onMove); document.removeEventListener('pointerup', onUp); }
  })();

  // Create a visible center divider (between left/right columns) that can be grabbed to resize
  (function enableCenterDivider(){
    const col = document.getElementById('agentsColumn');
    if (!col) return;
    const container = col.parentElement; // card-body
    if (!container) return;

    // hide the old edge resizer to avoid duplication
    const old = document.getElementById('agentsResizer'); if (old) old.style.display = 'none';

    // create divider
    let div = document.getElementById('columnDivider');

    function updateDividerPos(){
      const crect = container.getBoundingClientRect();
      const crectLeft = crect.left;
      const cres = col.getBoundingClientRect();
      const left = Math.round(cres.right - crectLeft);
      div.style.left = left + 'px';
    }

    let dragging = false, startX = 0, startWidth = 0;
    function onPointerDown(e){
      dragging = true; startX = e.clientX; startWidth = col.getBoundingClientRect().width;
      div.setPointerCapture && div.setPointerCapture(e.pointerId);
      document.addEventListener('pointermove', onPointerMove);
      document.addEventListener('pointerup', onPointerUp);
      e.preventDefault();
    }
    function onPointerMove(e){ if(!dragging) return; const crect = container.getBoundingClientRect(); const dx = e.clientX - crect.left; const min = 160; const max = Math.round(crect.width * 0.8); const finalW = Math.max(min, Math.min(dx, max)); col.style.flex = '0 0 ' + finalW + 'px'; updateDividerPos(); }
    function onPointerUp(e){ if(!dragging) return; dragging = false; document.removeEventListener('pointermove', onPointerMove); document.removeEventListener('pointerup', onPointerUp); }

    div.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('resize', updateDividerPos);
    // initial position
    setTimeout(updateDividerPos, 50);
  })();

  const app = {
    apiGet, apiPost, renderCards, renderAgents, loadAgents, loadMessages, appendMessageToUI, sendMessage, selectAgent, getSelectedRoomId, openRoom,
    getPreferredLocale, // returns string like 'en-US'
    getPreferredVoice: detectPreferredVoice // returns Promise<SpeechSynthesisVoice|null>
  };
  // expose app instances globally for pages that embed the widget
  try {
    window.chatApps = window.chatApps || {};
    const key = config.rootId || 'default';
    window.chatApps[key] = app;
  } catch (e) {}
  return app;
}

window.handleChat = function(designerid, options = {}) {
  // prefer the API base resolved by initApp; fallback to current page query handler
  const API = (typeof window.chatApiBase !== 'undefined') ? window.chatApiBase : (location.pathname + '?action=');
  // Use actual user's role if available, otherwise fall back to options or default to 'client'
  const creatorType = options.creatorType || (typeof window.chatUserType !== 'undefined' ? window.chatUserType : 'client');
  const creatorId = (typeof options.creatorId !== 'undefined') ? options.creatorId : (window.chatUserId ?? 0);
  const otherType = options.otherType || 'designer';
  const otherId = designerid;

  if (!creatorId) {
    // Not authenticated — redirect to login and preserve designer intent in the redirect query
    const redirectTarget = location.pathname + (location.pathname.indexOf('?') === -1 ? '?' : '&') + 'designerid=' + encodeURIComponent(designerid);
    window.location.href = '../login.php?redirect=' + encodeURIComponent(redirectTarget);
    return Promise.resolve({ ok: false, reason: 'not_authenticated' });
  }

  // If caller provided an orderId, prefer to open or create an order-specific room named `order-<id>`
  const orderId = options.orderId || null;
  if (orderId) {
    // try to find existing room by name via listRooms
    try {
      return fetch(API + 'listRooms', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ user_type: creatorType, user_id: creatorId }) })
        .then(r => r.json())
        .then(list => {
          const rooms = Array.isArray(list) ? list : (list.rooms || []);
          const targetName = 'Chatroom order#' + String(orderId);
          const found = rooms && rooms.find(r => (r.roomname || r.roomName || '').toString() === targetName);
          if (found) {
            const roomId = found.ChatRoomid || found.ChatRoomId || found.id || found.roomId;
            // open widget if present
            try { if (window.chatWidgetOpenPanel) window.chatWidgetOpenPanel(); } catch(e){}
            try { if (window.handleChatOpenRoom) window.handleChatOpenRoom(roomId); } catch(e){}
            // fallback to reload with room query so included widget picks it up
            const newSearch = (location.pathname || '') + (location.pathname.indexOf('?') === -1 ? '?' : '&') + 'room=' + encodeURIComponent(roomId);
            window.location.href = newSearch;
            return { ok: true, roomId };
          }
          // not found, fall through to create with roomname set
          const bodyObj = Object.assign({ creator_type: creatorType, creator_id: creatorId, other_type: otherType, other_id: otherId }, (options.otherName ? { other_name: options.otherName } : {}), { roomname: 'order-' + String(orderId), room_type: 'group' });
          return fetch(API + 'createRoom', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyObj)
          })
          .then(r => r.json())
          .then(data => data)
          .catch(err => { throw err; });
        }).catch(err => {
          console.error('listRooms error', err);
          // fallback to createRoom without roomname
          return fetch(API + 'createRoom', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ creator_type: creatorType, creator_id: creatorId, other_type: otherType, other_id: otherId }, (options.otherName ? { other_name: options.otherName } : {})))
          }).then(r => r.json()).then(data => data).catch(e=>({ok:false,error:e}));
        });
    } catch (ex) {
      console.error('handleChat order lookup failed', ex);
    }
  }

  return fetch(API + 'createRoom', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ creator_type: creatorType, creator_id: creatorId, other_type: otherType, other_id: otherId }, (options.otherName ? { other_name: options.otherName } : {})))
  })
  .then(r => r.json())
  .then(data => {
    if (data && data.ok && data.room) {
      const roomId = data.room.ChatRoomid || data.room.ChatRoomId || data.room.id || data.room.roomId;
      // persist otherName locally as a fallback so the chat UI can display the other participant's name
      try { if (roomId && options.otherName) localStorage.setItem('chat_other_name_' + roomId, options.otherName); } catch(e){}
      if (roomId) {
        // If an embedded chat widget is present, open it and select the created room instead of navigating away
          try {
            // Prefer any registered chat app instance rather than assuming a specific key
            let instance = null;
            try {
              if (window.chatApps) {
                const vals = Object.values(window.chatApps || {});
                if (vals.length) instance = vals[0];
              }
            } catch (e) { instance = null; }
            const panel = document.getElementById('chatwidget_panel');
            const toggle = document.getElementById('chatwidget_toggle');
            // Prefer using the widget's open hook so animations and toggle visibility are handled consistently
            try {
              if (typeof window.chatWidgetOpenPanel === 'function') {
                window.chatWidgetOpenPanel();
              } else {
                if (panel) { panel.style.display = 'flex'; panel.setAttribute('aria-hidden','false'); }
                if (toggle) { try { toggle.style.display = 'none'; } catch(e) {} }
              }
            } catch (e) { /* ignore */ }

            if (instance && typeof instance.selectAgent === 'function') {
              // Try to load agents then select the newly created room if present
              instance.loadAgents().then(rooms => {
                let found = null;
                try { found = rooms && rooms.find(r => String(r.ChatRoomid || r.id || r.roomId) === String(roomId)); } catch(e){}
                if (found) return instance.selectAgent(found);
                // fallback: create a minimal agent and select
                const minimal = { ChatRoomid: roomId, roomname: options.otherName || (data.room && (data.room.roomname || data.room.name)) || ('User ' + otherId) };
                return instance.selectAgent(minimal);
              }).catch(err => {
                // last-resort: directly load messages for the room
                try { instance.loadMessages && instance.loadMessages(roomId); } catch(e){}
              });
              return { ok: true, roomId };
            }
          } catch (e) { console.warn('handleChat widget open failed', e); }
          // fallback: reload current page with ?room= so the included chat code can pick it up
          const newSearch = (location.pathname || '') + (location.pathname.indexOf('?') === -1 ? '?' : '&') + 'room=' + encodeURIComponent(roomId);
          window.location.href = newSearch;
          return { ok: true, roomId };
      }
    }
    // fallback: reload current page with designerid param so URL handler will attempt to open chat
    const newSearchFail = (location.pathname || '') + (location.pathname.indexOf('?') === -1 ? '?' : '&') + 'designerid=' + encodeURIComponent(designerid);
    window.location.href = newSearchFail;
    return { ok: false };
  })
  .catch(err => {
    console.error('handleChat createRoom error', err);
    const newSearchErr = (location.pathname || '') + (location.pathname.indexOf('?') === -1 ? '?' : '&') + 'designerid=' + encodeURIComponent(designerid);
    window.location.href = newSearchErr;
    return { ok: false, error: err };
  });
};

// If page URL contains designerid or room parameters, process them automatically
(function(){
  try {
    const qs = new URLSearchParams(location.search);
    const did = qs.get('designerid');
    const rid = qs.get('room');
    // prefer designerid (intent to start chat)
    if (did && !isNaN(parseInt(did,10))) {
      // delay slightly to allow pages to call initApp and register chatApps
      setTimeout(() => {
        if (window.handleChat) {
          window.handleChat(parseInt(did,10), { creatorId: window.chatUserId || 0 });
        }
      }, 250);
    } else if (rid && !isNaN(parseInt(rid,10))) {
      setTimeout(() => {
        const inst = window.chatApps && (window.chatApps['chatwidget'] || window.chatApps['default'] || window.chatApps['chatpage']);
        if (inst && typeof inst.selectAgent === 'function') return inst.selectAgent({ ChatRoomid: parseInt(rid,10) });
        if (inst && typeof inst.openRoom === 'function') return inst.openRoom(parseInt(rid,10));
      }, 250);
    }
  } catch(e) { /* ignore */ }
})();
