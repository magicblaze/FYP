function initApp(config = {}) {
  const API = config.apiPath || (location.pathname + '?action=');
  const userId = config.userId ?? 0;
  const userType = config.userType || 'client';
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
  let pollTimer = null;
  let typingThrottle = null;
  const bsOff = (elements.catalogOffcanvasEl) ? new bootstrap.Offcanvas(elements.catalogOffcanvasEl) : null;
  if (elements.openCatalogBtn && bsOff) {
    elements.openCatalogBtn.addEventListener('click', () => bsOff.show());
  }

  function apiGet(path) { return fetch(API + path).then(r => r.json()); }
  function apiPost(path, data) {
    return fetch(API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).then(r => r.json());
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
    return apiGet('listRooms').then(data => {
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
        appendMessageToUI({ body: m.content || m.body || '', campaign: m.campaign || null, created_at: m.timestamp || m.created_at || new Date().toISOString() }, who);
        const mid = m.id || m.messageid || m.messageId || 0;
        if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
      });
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
          appendMessageToUI({ body: m.content || m.body || '', campaign: m.campaign || null, created_at: m.timestamp || m.created_at || new Date().toISOString() }, who);
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
    const wrapper = document.createElement('div');
    wrapper.className = 'message mb-3 ' + (who === 'me' ? 'd-flex justify-content-end' : '');
    const bubble = document.createElement('div');
    bubble.className = who === 'me' ? 'bg-primary text-white rounded p-2' : 'bg-light rounded p-2';
    const campaignHtml = msgObj.campaign ? `<div class="small text-muted mt-1">Campaign: ${escapeHtml(msgObj.campaign)}</div>` : '';
    bubble.innerHTML = `<div>${escapeHtml(stripHtml(msgObj.body))}</div>${campaignHtml}<div class="text-muted small mt-1">${new Date(msgObj.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>`;
    wrapper.appendChild(bubble);
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
    return loadMessages(roomId);
  }

  function sendMessage() {
    if (!currentAgent) { alert('Please select a person to chat with.'); return; }
    const text = (elements.input && elements.input.value || '').trim();
    if (!text) return;
    const campaignVal = (elements.campaign && elements.campaign.value.trim()) || null;
    const roomId = currentAgent?.ChatRoomid || currentAgent?.id || currentAgent?.roomId;
    return apiPost('sendMessage', { sender_type: userType, sender_id: userId, content: text, room: roomId }).then(resp => {
      if (resp.ok) {
        const created = (resp.message) ? resp.message : { content: text, created_at: new Date().toISOString(), id: resp.id };
        appendMessageToUI({ body: created.content || text, campaign: campaignVal, created_at: created.timestamp || created.created_at || new Date().toISOString() }, 'me');
        const mid = created.id || created.messageid || created.messageId || resp.id || 0;
        if (mid) lastMessageId = Math.max(lastMessageId, parseInt(mid, 10));
        if (elements.input) elements.input.value = '';
        if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
      } else {
        alert('Failed to send message');
      }
    }).catch(err => console.error(err));
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
        if (resp.ok) appendMessageToUI({ body: previewHtml, campaign: campaignVal, created_at: new Date().toISOString() }, 'me');
      }).catch(console.error);
    } else {
      if (elements.messages) elements.messages.insertAdjacentHTML('beforeend', `<div class="message me d-flex justify-content-end mb-3"><div class="bg-primary text-white rounded p-2">${previewHtml}<div class="text-muted small mt-1">${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div></div></div>`);
    }
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

  return {
    apiGet, apiPost, renderCards, renderAgents, loadAgents, loadMessages, appendMessageToUI, sendMessage, selectAgent
  };
}
