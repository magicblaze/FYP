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
    } catch (e) { console.error('apiPostForm error', e, API + path); throw e; }
  }

  async function apiGet(path) {
    try {
      const res = await fetch(API + path);
      if (!res.ok) throw new Error('Network error: ' + res.status);
      const txt = await res.text();
      try { return JSON.parse(txt); } catch (e) { return txt; }
    } catch (e) {
      console.error('apiGet error', e, API + path);
      throw e;
    }
  }

  async function apiPost(path, data) {
    try {
      const res = await fetch(API + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      const txt = await res.text();
      if (!res.ok) {
        let msg = txt || ('Status ' + res.status);
        try { const j = JSON.parse(txt); msg = j.message || j.error || JSON.stringify(j); } catch(e){}
        throw new Error('Network error: ' + res.status + ' - ' + msg);
      }
      try { return JSON.parse(txt); } catch (e) { return txt; }
    } catch (e) {
      console.error('apiPost error', e, API + path, data);
      throw e;
    }
  }

  function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function stripHtml(html) { return String(html || '').replace(/<[^>]*>?/gm, ''); }

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
      const name = a.roomname || a.name || `Room ${btn.dataset.roomId}`;
      const title = a.description || a.title || '';
      btn.innerHTML = `<div class="me-2"><div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px">${escapeHtml((name||'')[0]||'R')}</div></div>
        <div class="flex-grow-1 text-start"><div class="fw-semibold">${escapeHtml(name)}</div><div class="small text-muted">${escapeHtml(title)}</div></div>`;
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
    const senderName = msgObj.sender_name || msgObj.sender || (msgObj.sender_type ? (msgObj.sender_type + ' ' + (msgObj.sender_id||'')) : '');
    const content = msgObj.content || msgObj.body || '';
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

    const bubble = document.createElement('div');
    bubble.className = who === 'me' ? 'bg-primary text-white rounded p-2' : 'bg-light rounded p-2';
    const campaignHtml = msgObj.campaign ? `<div class="small text-muted mt-1">Campaign: ${escapeHtml(msgObj.campaign)}</div>` : '';
    // Attachment rendering: if message has an `attachment` path, render a thumbnail for images or link for other files
    let attachmentHtml = '';
    if (msgObj.attachment) {
      const att = String(msgObj.attachment || '');
      const lower = att.toLowerCase();
      // build absolute-ish URL if relative
      let url = att;
      if (!/^https?:\/\//.test(att)) {
        url = (location.origin + '/' + att.replace(/^\/+/, ''));
      }
      if (/(\.png|\.jpe?g|\.gif|\.webp|\.bmp)$/i.test(lower)) {
        attachmentHtml = `<div class="mt-2"><img src="${escapeHtml(url)}" style="max-width:220px;max-height:160px;border-radius:8px;display:block"/></div>`;
      } else {
        const fname = att.split('/').pop();
        attachmentHtml = `<div class="mt-2"><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(fname)}</a></div>`;
      }
    }
    bubble.innerHTML = `<div>${escapeHtml(stripHtml(content))}</div>${attachmentHtml}${campaignHtml}<div class="text-muted small mt-1">${new Date(time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>`;

    bodyCol.appendChild(nameEl);
    bodyCol.appendChild(bubble);

    if (who === 'me') {
      wrapper.appendChild(bodyCol);
      wrapper.appendChild(avatarWrap);
    } else {
      wrapper.appendChild(avatarWrap);
      wrapper.appendChild(bodyCol);
    }

    elements.messages.appendChild(wrapper);
  }

  function selectAgent(agent) {
    currentAgent = agent;
    if (elements.connectionStatus) elements.connectionStatus.textContent = `Connected to ${agent.roomname || agent.name}`;
    document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
      el.classList.toggle('active', el.dataset.roomId == (agent.ChatRoomid || agent.id));
    });
    const roomId = agent.ChatRoomid || agent.id || agent.roomId;
    lastMessageId = 0;
    startPolling(roomId);
    if (elements.messages) elements.messages.dataset.roomId = roomId;
    return loadMessages(roomId);
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
    // If there's a pending widget file selection, upload that file instead of sending text immediately
    if (widgetPendingFile) {
      // ensure we have roomId
      if (!roomId) { alert('Please select a person to chat with.'); return; }
      // prepare FormData and upload
      const file = widgetPendingFile;
      widgetPendingFile = null;
      if (elements.send) elements.send.disabled = true;
      try {
        const fd = new FormData();
        fd.append('sender_type', userType);
        fd.append('sender_id', userId);
        fd.append('room', roomId);
        fd.append('message_type', file.type && file.type.startsWith('image/') ? 'image' : 'file');
        fd.append('attachment', file, file.name);
        const resp = await apiPostForm('sendMessage', fd);
        if (resp && resp.ok) {
          const created = resp.message || { content: '', created_at: new Date().toISOString(), id: resp.id };
          appendMessageToUI(created, 'me');
          const mid = created.id || created.messageid || created.messageId || resp.id || 0;
          if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
          if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
        } else {
          alert('Failed to upload file');
        }
      } catch (err) {
        console.error('widget file upload error', err);
        alert('Upload error: ' + (err && err.message ? err.message : ''));
      } finally {
        // clear preview & re-enable send
        try { clearSelectedPreview(document.getElementById(prefix + 'attachPreview') || document.getElementById('chatwidget_attachPreview')); } catch(e){}
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
    let attachPreview = document.getElementById('attachPreview');
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
            attachPreview = document.createElement('div'); attachPreview.id = 'attachPreview'; attachPreview.style.minWidth = '0'; attachPreview.style.maxWidth = '180px';
            if (attachBtn && attachBtn.parentNode) attachBtn.parentNode.insertBefore(attachPreview, attachBtn);
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
                  // mark active and set currentAgent
                  items.forEach(x => x.classList.remove('active'));
                  it.classList.add('active');
                  currentAgent = { ChatRoomid: roomId, roomname: (it.querySelector('.fw-semibold') ? it.querySelector('.fw-semibold').textContent : '') };
                  lastMessageId = 0; startPolling(roomId);
                  console.info('auto-selected single agent roomId', roomId);
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
        const fd = new FormData();
        fd.append('sender_type', userType);
        fd.append('sender_id', userId);
        fd.append('room', roomId);
        fd.append('message_type', 'file');
        fd.append('attachment', file, file.name);
        try {
          const resp = await apiPostForm('sendMessage', fd);
          if (resp && resp.ok) {
            const created = resp.message || { content: '', created_at: new Date().toISOString(), id: resp.id };
            appendMessageToUI(created, 'me');
            clearSelectedPreview(attachPreview);
          } else {
            alert('Failed to upload file');
            clearSelectedPreview(attachPreview);
          }
        } catch (err) { console.error(err); alert('Upload error: ' + (err && err.message ? err.message : '')); }
        attachInput.value = '';
      });
    }
    // widget composer
    const wab = document.getElementById('chatwidget_attach');
    const waist = document.getElementById('chatwidget_attachInput');
    let widgetPreview = document.getElementById('chatwidget_attachPreview');
    if (wab && waist) {
      let widgetClickGuard = false;
      wab.addEventListener('click', () => { if (widgetClickGuard) return; widgetClickGuard = true; setTimeout(() => widgetClickGuard = false, 600); waist.click(); });
      waist.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        try {
          if (!widgetPreview) {
            console.warn('widgetPreview missing, creating temporary element');
            widgetPreview = document.createElement('div'); widgetPreview.id='chatwidget_attachPreview'; widgetPreview.style.minWidth='0'; widgetPreview.style.maxWidth='120px';
            if (wab && wab.parentNode) wab.parentNode.insertBefore(widgetPreview, wab);
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
              // mark active and set currentAgent
              items.forEach(x => x.classList.remove('active'));
              sel.classList.add('active');
              const rid = sel.dataset.roomId || sel.getAttribute('data-room-id') || sel.getAttribute('data-roomid');
              currentAgent = { ChatRoomid: rid, roomname: (sel.querySelector('.fw-semibold') ? sel.querySelector('.fw-semibold').textContent : '') };
              lastMessageId = 0; startPolling(rid);
              console.debug('chatwidget: auto-selected first room', rid);
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
        const fd = new FormData();
        fd.append('sender_type', userType);
        fd.append('sender_id', userId);
        fd.append('room', roomId);
        fd.append('message_type', 'file');
        fd.append('attachment', file, file.name);
        try {
          const resp = await apiPostForm('sendMessage', fd);
          if (resp && resp.ok) {
            const created = resp.message || { content: '', created_at: new Date().toISOString(), id: resp.id };
            appendMessageToUI(created, 'me');
            clearSelectedPreview(widgetPreview);
          } else {
            alert('Failed to upload file');
            clearSelectedPreview(widgetPreview);
          }
        } catch (err) { console.error(err); alert('Upload error: ' + (err && err.message ? err.message : '')); }
        waist.value = '';
      });
    }
  })();

  // preview helpers
  function showSelectedPreview(container, file) {
    if (!container) return;
    container.innerHTML = '';
    const wrap = document.createElement('div'); wrap.className = 'd-flex align-items-center';
    if (file.type && file.type.startsWith('image/')) {
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.style.maxWidth = '80px'; img.style.maxHeight = '60px'; img.style.objectFit = 'cover'; img.style.borderRadius = '6px'; img.style.marginRight = '8px';
      img.onload = () => { URL.revokeObjectURL(img.src); };
      wrap.appendChild(img);
    } else {
      const ext = (file.name || '').split('.').pop() || '';
      const badge = document.createElement('div'); badge.className = 'bg-secondary text-white d-inline-flex align-items-center justify-content-center';
      badge.style.width = '56px'; badge.style.height = '44px'; badge.style.borderRadius = '6px'; badge.style.marginRight = '8px'; badge.textContent = ext.toUpperCase();
      wrap.appendChild(badge);
    }
    const name = document.createElement('div'); name.className = 'small text-truncate'; name.style.maxWidth = '96px'; name.textContent = file.name;
    wrap.appendChild(name);
    container.appendChild(wrap);
  }
  function clearSelectedPreview(container){ if (!container) return; container.innerHTML = ''; }


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

  return {
    apiGet, apiPost, renderCards, renderAgents, loadAgents, loadMessages, appendMessageToUI, sendMessage, selectAgent
  };
}
