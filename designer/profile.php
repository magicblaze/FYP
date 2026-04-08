<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Designer Profile — Blank</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="site-header bg-white shadow-sm">
    <div class="container d-flex align-items-center justify-content-between py-3">
      <div class="brand">Designer Profile</div>
      <div class="actions d-flex gap-2">
        <button id="editToggle" class="btn btn-outline-primary btn-sm">Edit</button>
        <button id="exportBtn" class="btn btn-secondary btn-sm">Export JSON</button>
        <button id="resetBtn" class="btn btn-danger btn-sm">Reset</button>
      </div>
    </div>
  </header>

  <main class="container my-4">
    <div class="profile-cover mb-3 position-relative">
      <img id="cover" src="https://picsum.photos/1200/360?blur=8" alt="Cover" class="cover-photo rounded">
      <input id="coverInput" type="file" accept="image/*" class="d-none">
      <button id="changeCover" class="btn btn-sm btn-light cover-btn d-none">Change cover</button>
    </div>

    <div class="row gx-4">
      <section class="col-lg-8">
        <div class="profile-card d-flex gap-3 p-3 align-items-center mb-3">
          <div class="avatar-wrap position-relative">
            <img id="avatar" src="https://picsum.photos/140" alt="Avatar" class="avatar rounded">
            <input id="avatarInput" type="file" accept="image/*" class="d-none">
            <button id="changeAvatar" class="btn btn-sm btn-light avatar-btn d-none">Change</button>
          </div>

          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-3">
              <h2 id="name" class="mb-0 blank editable">Your Name</h2>
              <span id="title" class="badge bg-secondary blank editable">Title</span>
              <div class="ms-auto">
                <span class="small text-muted">Location</span>
                <div id="location" class="small text-muted blank editable">City, Country</div>
              </div>
            </div>

            <p id="bio" class="text-muted mt-2 blank editable">Short bio — introduce yourself, your style, and specialties.</p>

            <div class="d-flex gap-3 text-muted small mt-2">
              <div><strong id="projectsCount">0</strong> projects</div>
              <div><strong id="clientsCount">0</strong> clients</div>
              <div><strong id="yearsCount">0</strong> years</div>
            </div>
          </div>
        </div>

        <h5 class="mb-2">Portfolio (sample placeholders)</h5>
        <div id="portfolio" class="row g-3 mb-4">
          <!-- JS inserts placeholder cards -->
        </div>
      </section>

      <aside class="col-lg-4">
        <div class="card mb-3 p-3">
          <h6 class="mb-2">Contact</h6>
          <div class="small mb-1">Email</div>
          <div id="email" class="blank editable small text-muted">you@example.com</div>
          <div class="small mt-2 mb-1">Phone</div>
          <div id="phone" class="blank editable small text-muted">+852 1234 5678</div>
        </div>

        <div class="card mb-3 p-3">
          <h6 class="mb-2">Services</h6>
          <ul id="services" class="list-unstyled small mb-0">
            <li class="blank editable">Interior design</li>
            <li class="blank editable">Space planning</li>
            <li class="blank editable">Project management</li>
          </ul>
        </div>

        <div class="card p-3">
          <h6 class="mb-2">Links</h6>
          <div id="website" class="small text-muted blank editable">https://your-website.example</div>
        </div>
      </aside>
    </div>
  </main>

  <footer class="bg-white py-3 border-top text-center small text-muted">Blank profile template — client-side only</footer>

  <script src="profile.js" defer></script>
</body>
</html>
