function initApp(config = {}) {
  const API = config.apiPath || (location.pathname + '?action=');
  const userId = config.userId ?? 0;
  const userType = config.userType || 'client';
  const userName = config.userName || '';
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

  let currentAgent = null;
  let currentRoomId = null;
  let lastMessageId = 0;
  let widgetPendingFile = null;
  let pendingFile = null;
  let pollTimer = null;
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
      const name = a.other_name || a.otherName || storedOther || a.roomname || a.name || `Room ${btn.dataset.roomId}`;
      const title = a.description || a.title || '';
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
      btn.innerHTML = `<div class="me-2"><div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px">${escapeHtml((name||'')[0]||'R')}</div></div>
        <div class="flex-grow-1 text-start"><div class="fw-semibold">${escapeHtml(name)}</div><div class="small text-muted">${escapeHtml(roleLabel || title)}</div></div>`;
      btn.addEventListener('click', () => {
        selectAgent(a);
        if (isOff && bsOff) bsOff.hide();
      });
      container.appendChild(btn);
    });
  }

  function loadAgents() {
    // Use ChatApi listRooms endpoint
    const qs = `listRooms${userType?('&user_type=' + encodeURIComponent(userType)):''}${userId?('&user_id=' + encodeURIComponent(userId)):''}`;
    return apiGet(qs).then(data => {
      const rooms = Array.isArray(data) ? data : (data.rooms || []);
      renderAgents(rooms, elements.agentsList, false);
      renderAgents(rooms, elements.agentsListOff, true);
      return rooms;
    }).catch(err => { console.error('Failed to load rooms', err); return []; });
  }

  function conversationIdFor(agentId) { return agentId; }

  function loadMessages(roomId) {
    if (elements.messages) elements.messages.innerHTML = '';
    return apiGet('getMessages&room=' + encodeURIComponent(roomId)).then(data => {
      const messages = Array.isArray(data) ? data : (data.messages || []);
      messages.forEach(m => {
        const who = (m.sender_type === userType && String(m.sender_id) == String(userId)) ? 'me' : 'them';
        appendMessageToUI(m, who);
        const mid = m.id || m.messageid || m.messageId || 0;
        if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
      });
      if (elements.messages) elements.messages.dataset.roomId = roomId;
      if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
      return messages;
    }).catch(err => { console.error(err); return []; });
  }

  function startPolling(roomId) {
    if (pollTimer) clearInterval(pollTimer);
    currentRoomId = roomId;
    pollTimer = setInterval(() => {
          apiGet('getMessages&room=' + encodeURIComponent(roomId) + '&since=' + encodeURIComponent(lastMessageId)).then(ms => {
            const messages = Array.isArray(ms) ? ms : (ms.messages || []);
            messages.forEach(m => {
              const who = (m.sender_type === userType && String(m.sender_id) == String(userId)) ? 'me' : 'them';
              appendMessageToUI(m, who);
              const mid = m.id || m.messageid || m.messageId || 0;
              if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
            });
            if (elements.messages && messages.length) elements.messages.scrollTop = elements.messages.scrollHeight;
          }).catch(() => {});

      // typing status
      apiGet('getTyping&room=' + encodeURIComponent(roomId)).then(tp => {
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
    avatar.style.width = '36px'; avatar.style.height = '36px'; avatar.style.fontSize = '14px'; avatar.textContent = (senderName||' ')[0] || '?';
    avatarWrap.appendChild(avatar);

    const bodyCol = document.createElement('div');
    bodyCol.style.maxWidth = '100%';
    const nameEl = document.createElement('div');
    nameEl.className = 'small text-muted mb-1';
    nameEl.textContent = senderName;
    // Align sender name according to message side to avoid layout glitches
    if (who === 'me') {
      nameEl.classList.add('text-end');
      // ensure the body column aligns its content to the right for `me` messages
      try { bodyCol.style.textAlign = 'right'; } catch (e) {}
    } else {
      nameEl.classList.remove('text-end');
      try { bodyCol.style.textAlign = 'left'; } catch (e) {}
    }

    const bubble = document.createElement('div');
    bubble.className = who === 'me' ? 'bg-primary text-white rounded p-2' : 'bg-light rounded p-2';
    const campaignHtml = msgObj.campaign ? `<div class="small text-muted mt-1">Campaign: ${escapeHtml(msgObj.campaign)}</div>` : '';
    // Special rendering for `design` share messages: single white card merged into bubble
    let designHtml = '';
    try {
      if ((msgObj.message_type && msgObj.message_type === 'design') || msgObj.share) {
        const share = msgObj.share || {};
        const uploaded = msgObj.uploaded_file || null;
        const imgSrc = (uploaded && uploaded.filepath) || share.image || msgObj.attachment || '';
        let imgUrl = imgSrc || '';
        if (imgUrl && !/^https?:\/\//.test(imgUrl)) imgUrl = (location.origin + '/' + imgUrl.replace(/^\/+/, ''));
        const title = share.title || (uploaded && uploaded.filename) || msgObj.content || '';
        const url = share.url || msgObj.content || imgUrl || '';
        designHtml = `<div class=" p-2 border rounded bg-white" style="display:flex;gap:12px;align-items:center">
            <div style="flex:0 0 72px"><img src="${escapeHtml(imgUrl)}" style="width:72px;height:72px;object-fit:cover;border-radius:6px"/></div>
            <div style="flex:1;min-width:0">
              <div class="fw-semibold" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(title)}</div>
              <div><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="small text-muted">Open design page</a></div>
            </div>
          </div>`;
      }
    } catch (e) { designHtml = ''; }
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
          attachmentHtml = `<div class="mt-2"><img class="chat-attachment-img" src="${escapeHtml(url)}" style="max-width:420px;max-height:60vh;border-radius:8px;display:block;cursor:pointer"/></div>`;
        } else {
          const fname = (uploaded && uploaded.filename) ? uploaded.filename : att.split('/').pop();
          attachmentHtml = `<div class="mt-2"><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(fname)}</a></div>`;
        }
      }
    } catch (e) { attachmentHtml = ''; }
    // If we rendered a design card, avoid rendering the separate contentHtml (which may be a duplicate URL link)
    if (designHtml) {
      // Override bubble styling so the message bubble is white (not the usual colored bubble)
      bubble.className = 'bg-white text-dark rounded';
      bubble.innerHTML = `${designHtml}${campaignHtml}<div class="text-muted small mt-1">${formatMessageTimestamp(time)}</div>`;
    } else {
      // For plain image attachments, prefer a white bubble instead of a blue one for sender 'me'
      if (isImageMessage) {
        bubble.className = 'bg-white text-dark rounded p-2';
      }
      // Choose time text color: if the bubble uses `text-white` (blue bubble), make the time white for readability
      let timeClass = 'text-muted';
      try { if (bubble.className && bubble.className.indexOf('text-white') !== -1) timeClass = 'text-white'; } catch (e) {}
      const timeHtml = `<div class="${timeClass} small mt-1">${formatMessageTimestamp(time)}</div>`;
      bubble.innerHTML = `<div>${contentHtml}</div>${attachmentHtml}${campaignHtml}${timeHtml}`;
    }

    bodyCol.appendChild(nameEl);
    bodyCol.appendChild(bubble);

    if (who === 'me') {
      wrapper.appendChild(bodyCol);
      wrapper.appendChild(avatarWrap);
    } else {
      wrapper.appendChild(avatarWrap);
      wrapper.appendChild(bodyCol);
    }

    if (existingId) wrapper.setAttribute('data-mid', existingId);
    elements.messages.appendChild(wrapper);
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

  function selectAgent(agent) {
    currentAgent = agent;
    const rid = agent.ChatRoomid || agent.id || agent.roomId;
    const stored = (rid ? localStorage.getItem('chat_other_name_' + rid) : null);
    const displayName = agent.other_name || agent.otherName || stored || agent.roomname || agent.name || '';
    if (elements.connectionStatus) elements.connectionStatus.textContent = `${displayName}`;
    document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
      el.classList.toggle('active', el.dataset.roomId == (agent.ChatRoomid || agent.id));
    });
    const roomId = agent.ChatRoomid || agent.id || agent.roomId;
    lastMessageId = 0;
    startPolling(roomId);
    if (elements.messages) elements.messages.dataset.roomId = roomId;
    // Load messages and ensure the view scrolls to the newest message after load.
    const p = loadMessages(roomId);
    p.then(() => {
      try { if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight; } catch (e) {}
      // second delayed scroll to handle images or layout shifts
      setTimeout(() => { try { if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight; } catch (e) {} }, 250);
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
      attachBtn.addEventListener('click', () => attachInput.click());
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
            attachPreview.className = 'message-preview-column';
            attachPreview.style.minWidth = '0';
            attachPreview.style.flex = '0 0 120px';
            attachPreview.style.maxWidth = '160px';
            attachPreview.style.marginRight = '8px';
            // Insert preview as a full-width row above the composer (if available)
            const composer = elements.input ? elements.input.closest('.composer') : null;
            if (composer && composer.parentNode) {
              // create a separate row before the composer so layout stays stable
              attachPreview.style.display = 'block';
              attachPreview.style.width = '100%';
              attachPreview.style.maxWidth = 'none';
              attachPreview.style.marginBottom = '8px';
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
      wab.addEventListener('click', () => { if (widgetClickGuard) return; widgetClickGuard = true; setTimeout(() => widgetClickGuard = false, 600); waist.click(); });
      waist.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        try {
          if (!widgetPreview) {
            console.warn('widgetPreview missing, creating temporary element');
            widgetPreview = document.createElement('div');
            widgetPreview.id = 'chatwidget_attachPreviewColumn';
            widgetPreview.className = 'message-preview-column';
            widgetPreview.style.minWidth = '0';
            widgetPreview.style.flex = '0 0 120px';
            widgetPreview.style.maxWidth = '160px';
            widgetPreview.style.marginRight = '8px';
            // Try to insert preview as a separate full-width row above the composer for the widget
            const composer = elements.input ? elements.input.closest('.composer') : null;
            if (composer && composer.parentNode) {
              widgetPreview.style.display = 'block';
              widgetPreview.style.width = '100%';
              widgetPreview.style.maxWidth = 'none';
              widgetPreview.style.marginBottom = '8px';
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
  function showSelectedPreview(container, file) {
    if (!container) return;
    // ensure the preview row is visible when populated
    try { container.style.display = container.style.display === 'none' ? '' : container.style.display; } catch(e) {}
    container.innerHTML = '';
    const root = document.createElement('div'); root.className = 'message-preview-row';
    const wrap = document.createElement('div'); wrap.className = 'd-flex align-items-center';
    if (file.type && file.type.startsWith('image/')) {
      const img = document.createElement('img');
      const objUrl = URL.createObjectURL(file);
      // keep object URL until preview is cleared so modal enlarge works
      try { container._objUrl = objUrl; } catch(e) {}
      img.src = objUrl;
      img.style.maxWidth = '140px'; img.style.maxHeight = '120px'; img.style.objectFit = 'cover'; img.style.borderRadius = '6px'; img.style.marginRight = '8px';
      img.style.cursor = 'pointer';
      img.addEventListener('click', () => { openPreviewModal(objUrl); });
      wrap.appendChild(img);
    } else {
      const ext = (file.name || '').split('.').pop() || '';
      const badge = document.createElement('div'); badge.className = 'file-badge';
      badge.textContent = ext.toUpperCase(); badge.style.marginRight = '8px';
      wrap.appendChild(badge);
    }
    const meta = document.createElement('div'); meta.className = 'file-meta';
    const name = document.createElement('div'); name.className = 'name'; name.textContent = file.name; meta.appendChild(name);
    if (file.size) { const size = document.createElement('div'); size.className = 'size'; size.textContent = Math.round(file.size/1024) + ' KB'; meta.appendChild(size); }
    wrap.appendChild(meta);
    root.appendChild(wrap);
    // add a small 'x' remove button aligned to the right of the preview row
    try {
      // ensure the preview row lays out horizontally so the button can sit at the right
      root.style.display = 'flex';
      root.style.alignItems = 'center';
      wrap.style.flex = '1';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-sm btn-link';
      btn.setAttribute('aria-label', 'Remove attachment');
      btn.style.marginLeft = 'auto';
      btn.style.padding = '0 6px';
      btn.style.lineHeight = '1';
      btn.textContent = '✕';
      btn.addEventListener('click', function(){
        try { clearSelectedPreview(container); } catch(e){}
        // clear pending references if any
        try { widgetPendingFile = null; pendingFile = null; } catch(e){}
        // clear native file inputs where possible
        try { const a = document.getElementById(prefix + 'attachInput') || document.getElementById('attachInput'); if (a) a.value = ''; } catch(e){}
        try { const b = document.getElementById(prefix + 'chatwidget_attachInput') || document.getElementById('chatwidget_attachInput'); if (b) b.value = ''; } catch(e){}
      });
      root.appendChild(btn);
    } catch(e) {}
    container.appendChild(root);
  }
  function clearSelectedPreview(container){ if (!container) return; try { container.innerHTML = ''; container.style.display = 'none'; if (container._objUrl) { try { URL.revokeObjectURL(container._objUrl); } catch(e){} delete container._objUrl; } } catch(e) {} try { widgetPendingFile = null; pendingFile = null; } catch(e) {} }

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
    if (!div) {
      div = document.createElement('div');
      div.id = 'columnDivider';
      div.title = 'Drag to resize columns';
      div.style.position = 'absolute';
      div.style.top = '0';
      div.style.bottom = '0';
      // make divider area larger for easy grabbing, with a thin visible center handle
      div.style.width = '16px';
      div.style.marginLeft = '-8px';
      div.style.background = 'transparent';
      div.style.cursor = 'col-resize';
      div.style.zIndex = 10002;
      div.style.boxSizing = 'border-box';
      div.style.display = 'flex';
      div.style.alignItems = 'center';
      div.style.justifyContent = 'center';
      div.style.touchAction = 'none';
      // create centered visible handle bar
      const handle = document.createElement('div');
      handle.style.width = '5px';
      handle.style.height = '56px';
      handle.style.background = '#dcdcdc';
      handle.style.borderLeft = '1px solid rgba(0,0,0,0.08)';
      handle.style.borderRight = '1px solid rgba(255,255,255,0.4)';
      handle.style.borderRadius = '3px';
      handle.style.boxShadow = '0 0 0 2px rgba(0,0,0,0.00)';
      handle.style.pointerEvents = 'auto';
      div.appendChild(handle);
      container.appendChild(div);
    }

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
    apiGet, apiPost, renderCards, renderAgents, loadAgents, loadMessages, appendMessageToUI, sendMessage, selectAgent
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
  const creatorType = options.creatorType || 'client';
  const creatorId = (typeof options.creatorId !== 'undefined') ? options.creatorId : (window.chatUserId ?? 0);
  const otherType = 'designer';
  const otherId = designerid;

  if (!creatorId) {
    const target = '../chat.php?designerid=' + encodeURIComponent(designerid);
    window.location.href = '../login.php?redirect=' + encodeURIComponent(target);
    return Promise.resolve({ ok: false, reason: 'not_authenticated' });
  }

  return fetch('../Public/ChatApi.php?action=createRoom', {
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
          const instance = (window.chatApps && window.chatApps['chatwidget']) || null;
          const panel = document.getElementById('chatwidget_panel');
          const toggle = document.getElementById('chatwidget_toggle');
          if (panel) { panel.style.display = 'flex'; panel.setAttribute('aria-hidden','false'); }
          if (toggle) { toggle.style.display = 'none'; }
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
        // fallback: navigate to chat page
        window.location.href = '../chat.php?room=' + encodeURIComponent(roomId);
        return { ok: true, roomId };
      }
    }
    window.location.href = '../chat.php?designerid=' + encodeURIComponent(designerid);
    return { ok: false };
  })
  .catch(err => {
    console.error('handleChat createRoom error', err);
    window.location.href = '../chat.php?designerid=' + encodeURIComponent(designerid);
    return { ok: false, error: err };
  });
};
