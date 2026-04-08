// portfolio.js — read-only gallery and posts loaded from server
(() => {
  const API = location.pathname + '?action=';

  const gallery = document.getElementById('gallery');
  const lightbox = document.getElementById('lightbox');
  const lbImg = document.getElementById('lbImg');
  const lbCaption = document.getElementById('lbCaption');
  const lbClose = document.getElementById('lbClose');
  const postsList = document.getElementById('postsList');
  const contactForm = document.getElementById('contactForm');
  const contactStatus = document.getElementById('contactStatus');

  function fetchJson(path) {
    return fetch(API + path, { credentials: 'same-origin' }).then(r => r.json());
  }

  function escapeHtml(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  // render projects
  function renderGallery(projects) {
    gallery.innerHTML = '';
    projects.forEach(p => {
      const col = document.createElement('div');
      col.className = 'col-sm-6 col-md-4';
      col.innerHTML = `<div class="gallery-item"><img data-id="${p.id}" data-title="${escapeHtml(p.title)}" data-caption="${escapeHtml(p.caption||'')}" src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.title)}"></div>`;
      gallery.appendChild(col);
    });
  }

  // render posts
  function renderPosts(posts) {
    postsList.innerHTML = '';
    posts.forEach(post => {
      const col = document.createElement('div');
      col.className = 'col-md-6';
      col.innerHTML = `
        <article class="card p-3">
          <div class="d-flex gap-3">
            <img src="${escapeHtml(post.image_url || 'https://picsum.photos/120')}" alt="" style="width:120px;height:80px;object-fit:cover;border-radius:8px">
            <div>
              <div class="fw-semibold">${escapeHtml(post.title)}</div>
              <div class="small text-muted">${escapeHtml(post.excerpt)}</div>
              <div class="small text-muted mt-2">${new Date(post.published_at).toLocaleDateString()}</div>
            </div>
          </div>
        </article>`;
      postsList.appendChild(col);
    });
  }

  // lightbox
  function openLightbox(src, title, caption) {
    lbImg.src = src;
    lbCaption.textContent = `${title}${caption ? ' — ' + caption : ''}`;
    lightbox.classList.remove('d-none');
  }
  function closeLightbox() { lbImg.src = ''; lightbox.classList.add('d-none'); }

  gallery.addEventListener('click', (e) => {
    const img = e.target.closest('img');
    if (!img) return;
    openLightbox(img.src, img.dataset.title, img.dataset.caption);
  });
  lbClose.addEventListener('click', closeLightbox);
  lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeLightbox(); });

  // contact form (client-only stub)
  contactForm.addEventListener('submit', (e) => {
    e.preventDefault();
    contactStatus.textContent = 'Sending…';
    setTimeout(() => {
      contactStatus.textContent = 'Message sent — we will reply within 48 hours';
      contactForm.reset();
    }, 700);
  });

  // initial load
  fetchJson('get_projects&limit=12').then(data => {
    if (data.projects) renderGallery(data.projects);
  }).catch(console.error);

  fetchJson('get_posts&limit=6').then(data => {
    if (data.posts) renderPosts(data.posts);
  }).catch(console.error);

})();
