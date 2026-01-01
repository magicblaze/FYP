function initApp(config = {}) {
  const API = config.apiPath || (location.pathname + '?action=');
  const userId = config.userId || 'anon';
  const items = config.items || [];

  const elements = {
    agentsList: document.getElementById('agentsList'),
    agentsListOff: document.getElementById('agentsListOffcanvas'),
    cardsGrid: document.getElementById('cardsGrid'),
    cardsGridOff: document.getElementById('cardsGridOffcanvas'),
    messages: document.getElementById('messages'),
    input: document.getElementById('input'),
    send: document.getElementById('send'),
    campaign: document.getElementById('campaignInput'),
    campaignOff: document.getElementById('campaignInputOff'),
    connectionStatus: document.getElementById('connectionStatus'),
    openCatalogBtn: document.getElementById('openCatalogBtn'),
    catalogOffcanvasEl: document.getElementById('catalogOffcanvas'),
  };

  let currentAgent = null;
  const bsOff = (elements.catalogOffcanvasEl) ? new bootstrap.Offcanvas(elements.catalogOffcanvasEl) : null;
  if (elements.openCatalogBtn && bsOff) {
    elements.openCatalogBtn.addEventListener('click', () => bsOff.show());
  }

  function apiGet(path) { return fetch(API + path).then(r => r.json()); }
  function apiPost(path, data) {
    return fetch(location.pathname + '?action=' + path, {
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
      btn.dataset.agentId = a.id;
      btn.innerHTML = `<div class="me-2"><div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px">${escapeHtml((a.name||'')[0]||'A')}</div></div>
        <div class="flex-grow-1 text-start"><div class="fw-semibold">${escapeHtml(a.name)}</div><div class="small text-muted">${escapeHtml(a.title||'')}</div></div>
        <div class="ms-2"><span class="badge ${a.is_online? 'bg-success':'bg-secondary'}">${a.is_online? 'Online':'Offline'}</span></div>`;
      btn.addEventListener('click', () => {
        selectAgent(a);
        if (isOff && bsOff) bsOff.hide();
      });
      container.appendChild(btn);
    });
  }

  function loadAgents() {
    return apiGet('get_agents').then(data => {
      const agents = data.agents || [];
      renderAgents(agents, elements.agentsList, false);
      renderAgents(agents, elements.agentsListOff, true);
      return agents;
    }).catch(err => { console.error('Failed to load agents', err); return []; });
  }

  function conversationIdFor(agentId) { return `user:${userId}:agent:${agentId}`; }

  function loadMessages(agentId) {
    const conv = conversationIdFor(agentId);
    if (elements.messages) elements.messages.innerHTML = '';
    return apiGet('get_messages&conversation=' + encodeURIComponent(conv)).then(data => {
      (data.messages || []).forEach(m => appendMessageToUI(m, m.sender === 'user' ? 'me' : 'them'));
      if (elements.messages) elements.messages.scrollTop = elements.messages.scrollHeight;
      return data.messages || [];
    }).catch(err => { console.error(err); return []; });
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
    if (elements.connectionStatus) elements.connectionStatus.textContent = `Connected to ${agent.name}`;
    document.querySelectorAll('#agentsList .list-group-item, #agentsListOffcanvas .list-group-item').forEach(el => {
      el.classList.toggle('active', el.dataset.agentId == agent.id);
    });
    return loadMessages(agent.id);
  }

  function sendMessage() {
    if (!currentAgent) { alert('Please select a person to chat with.'); return; }
    const text = (elements.input && elements.input.value || '').trim();
    if (!text) return;
    const campaignVal = (elements.campaign && elements.campaign.value.trim()) || null;
    const conv = conversationIdFor(currentAgent.id);
    return apiPost('send_message', { conversation: conv, sender: 'user', body: text, campaign: campaignVal }).then(resp => {
      if (resp.ok) {
        appendMessageToUI({ body: text, campaign: campaignVal, created_at: new Date().toISOString() }, 'me');
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
      const conv = conversationIdFor(currentAgent.id);
      apiPost('send_message', { conversation: conv, sender: 'user', body: previewHtml, campaign: campaignVal || null }).then(resp => {
        if (resp.ok) appendMessageToUI({ body: previewHtml, campaign: campaignVal, created_at: new Date().toISOString() }, 'me');
      }).catch(console.error);
    } else {
      if (elements.messages) elements.messages.insertAdjacentHTML('beforeend', `<div class="message me d-flex justify-content-end mb-3"><div class="bg-primary text-white rounded p-2">${previewHtml}<div class="text-muted small mt-1">${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div></div></div>`);
    }
  }

  if (elements.send) elements.send.addEventListener('click', sendMessage);
  if (elements.input) elements.input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
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
