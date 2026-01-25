<?php
require_once __DIR__ . '/../config.php';
session_start();

// Check if designer is logged in
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

$designerId = $_SESSION['user']['designerid'];
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $design_name = trim($_POST['design_name'] ?? '');
    $expect_price = intval($_POST['expect_price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $tag = trim($_POST['tag'] ?? '');

    // Handle design image uploads (multiple files)
    $designImages = $_FILES['design'] ?? null;
    $uploadedImages = [];
    $uploadDir = __DIR__ . '/../uploads/designs/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Process design images
    if ($designImages && is_array($designImages['name'])) {
        $imageCount = count($designImages['name']);

        for ($i = 0; $i < $imageCount; $i++) {
            if ($designImages['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($designImages['name'][$i], PATHINFO_EXTENSION);
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array(strtolower($ext), $allowedExts)) {
                    $imageFileName = uniqid('design_', true) . '.' . $ext;
                    if (move_uploaded_file($designImages['tmp_name'][$i], $uploadDir . $imageFileName)) {
                        $uploadedImages[] = $imageFileName;
                    }
                }
            }
        }
    }

    // Validate form data
    if (!$design_name) {
        $error = 'Please enter a design name.';
    } elseif ($expect_price <= 0) {
        $error = 'Please enter a valid price.';
    } elseif (!$tag) {
        $error = 'Please enter design tags.';
    } elseif (empty($uploadedImages)) {
        $error = 'Please upload at least one design image.';
    } else {
        // Insert design into database
        $stmt = $mysqli->prepare("INSERT INTO Design (designName, design, expect_price, description, tag, likes, designerid) VALUES (?, ?, ?, ?, ?, 0, ?)");
        if (!$stmt) {
            $error = 'Database error: ' . $mysqli->error;
        } else {
            // Use the first image as the primary design image
            $primaryImage = $uploadedImages[0];
            $stmt->bind_param("ssissi", $design_name, $primaryImage, $expect_price, $description, $tag, $designerId);

            if ($stmt->execute()) {
                        $designId = $stmt->insert_id;
                        $stmt->close();

                        // If client provided a primary index, respect it
                        $primaryIndex = isset($_POST['primary_index']) ? (int)$_POST['primary_index'] : 0;
                        if ($primaryIndex < 0 || $primaryIndex >= count($uploadedImages)) $primaryIndex = 0;
                
                        // Use the selected primary image (will be overwritten below after inserting images)
                        $primaryImage = $uploadedImages[$primaryIndex];

                        // Insert all uploaded images into DesignImage table
                        $imageStmt = $mysqli->prepare("INSERT INTO DesignImage (designid, image_filename, image_order) VALUES (?, ?, ?)");
                if ($imageStmt) {
                    $imageOrder = 1;
                            foreach ($uploadedImages as $imageName) {
                                $imageStmt->bind_param("isi", $designId, $imageName, $imageOrder);
                                $imageStmt->execute();
                                $imageOrder++;
                            }
                    $imageStmt->close();

                    // Update Design table to set the chosen primary image as the main `design` field
                    $upd = $mysqli->prepare("UPDATE Design SET design = ? WHERE designid = ?");
                    if ($upd) {
                        $upd->bind_param('si', $primaryImage, $designId);
                        $upd->execute();
                        $upd->close();
                    }

                    // Process references JSON if submitted
                    if (!empty($_POST['references_json'])) {
                        $refs = @json_decode($_POST['references_json'], true);
                        if (is_array($refs) && count($refs) > 0) {
                            // ensure table exists
                            $createSql = "CREATE TABLE IF NOT EXISTS DesignReference (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                designid INT NOT NULL,
                                title VARCHAR(255) DEFAULT NULL,
                                url TEXT DEFAULT NULL,
                                added_by_type VARCHAR(50) DEFAULT NULL,
                                added_by_id INT DEFAULT NULL,
                                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                                INDEX(designid)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                            $mysqli->query($createSql);

                            $ins = $mysqli->prepare("INSERT INTO DesignReference (designid, title, url, added_by_type, added_by_id) VALUES (?, ?, ?, ?, ?)");
                            if ($ins) {
                                foreach ($refs as $r) {
                                    $title = isset($r['title']) ? trim($r['title']) : null;
                                    $url = isset($r['url']) ? trim($r['url']) : null;
                                    $atype = 'designer';
                                    $aid = $designerId;
                                    $ins->bind_param('isssi', $designId, $title, $url, $atype, $aid);
                                    $ins->execute();
                                }
                                $ins->close();
                            }
                        }
                    }

                    $success = true;
                } else {
                    $error = 'Error saving image records: ' . $mysqli->error;
                }
            } else {
                $error = 'Database error: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Add New Design</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/supplier_style.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .form-section {
            background: #f8fafd;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(44, 62, 80, 0.07);
            padding: 1.25rem 1.5rem 1rem 1.5rem;
            margin-bottom: 1.25rem;
            transition: box-shadow 0.2s;
        }

        .form-section:hover {
            box-shadow: 0 4px 16px rgba(52, 152, 219, 0.13);
        }

        .form-section label {
            font-weight: 500;
        }

        .form-section input,
        .form-section textarea,
        .form-section select {
            background: #fff;
            border-radius: 8px;
        }

        .image-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .image-preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #f0f0f0;
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #3498db;
        }

        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-preview-item .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(231, 76, 60, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .image-preview-item:hover .remove-btn {
            opacity: 1;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }

        .file-input-wrapper input[type="file"] {
            display: none;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            border: 2px dashed #3498db;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .file-input-label:hover {
            background: #e8f4f8;
            border-color: #2980b9;
        }

        .file-input-label.dragover {
            background: #d4e9f7;
            border-color: #2980b9;
        }

        .card.shadow-sm.border-0 {
            transition: none !important;
        }

        .card.shadow-sm.border-0:hover {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06) !important;
            transform: none !important;
        }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">
        <div class="mb-4">
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Success!</strong> Design added successfully with multiple images.
                                <a href="designer_dashboard.php" class="alert-link">Back to Dashboard</a>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-images"></i> Image(s) for the design
                                        *</label>
                                    <div class="file-input-wrapper">
                                        <label class="file-input-label" id="fileInputLabel">
                                            <div>
                                                <i class="fas fa-cloud-upload-alt"
                                                    style="font-size: 2rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                                <strong>Click to upload or drag & drop</strong>
                                                <p class="text-muted mb-0" style="font-size: 0.9rem;">JPG, PNG, GIF,
                                                    WebP (Max 10 images)</p>
                                            </div>
                                        </label>
                                        <input type="file" name="design[]" id="designInput" class="form-control"
                                            accept="image/*" multiple required>
                                    </div>
                                    <div class="image-preview-container" id="imagePreviewContainer"></div>
                                    <small class="text-muted d-block mt-2">Upload high-quality design images. The first
                                        image will be used as the main design thumbnail.</small>
                                </div>
                            </div>
                        </div>
                        <form method="post" enctype="multipart/form-data" id="addDesignForm">
                            <input type="hidden" name="primary_index" id="primaryIndex" value="0">
                            <input type="hidden" name="references_json" id="referencesJson" value="">
                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-heading"></i> Design Name *</label>
                                        <input type="text" name="design_name" class="form-control"
                                            placeholder="e.g. Modern Living Room Design" required>
                                        <small class="text-muted">Give your design a descriptive name</small>
                                    </div>
                                </div>
                            </div>



                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-dollar-sign"></i> Expected Price
                                            (HK$) *</label>
                                        <input type="number" name="expect_price" class="form-control" min="1" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-file-alt"></i> Design
                                            Description</label>
                                        <textarea name="description" class="form-control" rows="4"
                                            placeholder="Describe your design, style, materials, and any special features..."></textarea>
                                        <small class="text-muted">Optional: Provide details about your design to help
                                            clients understand your work</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-tags"></i> Design Tags *</label>
                                        <input type="text" name="tag" class="form-control"
                                            placeholder="e.g. modern, minimalist, luxury, residential" required>
                                        <small class="text-muted">Separate multiple tags with commas</small>
                                    </div>
                                </div>
                            </div>

                            <!-- References -->
                            <div class="row g-3 mb-2">
                                <div class="col-md-12">
                                    <div class="form-section">
                                        <label class="form-label"><i class="fas fa-link"></i> References</label>
                                        <div class="input-group mb-2">
                                            <input type="text" id="refTitle" class="form-control" placeholder="Reference title (e.g. Moodboard, Source)" />
                                            <input type="url" id="refUrl" class="form-control" placeholder="https://example.com/image.jpg or page URL" />
                                            <button type="button" class="btn btn-outline-primary" onclick="addReference()">Add</button>
                                            <button type="button" id="addLikedBtn" class="btn btn-outline-secondary ms-2" title="Add from your liked designs">Add from Liked</button>
                                        </div>
                                        <div id="referencesList" style="display:block"></div>
                                        <small class="text-muted d-block mt-2">Add external references or inspiration links for this design.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 d-flex gap-2 justify-content-end">
                                    <button type="submit" class="btn btn-success px-4"><i class="fas fa-check"></i> Add
                                        Design</button>
                                    <a href="designer_dashboard.php" class="btn btn-secondary px-4"><i
                                            class="fas fa-arrow-left"></i> Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        const designInput = document.getElementById('designInput');
        const fileInputLabel = document.getElementById('fileInputLabel');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');
        let selectedFiles = [];

        // Handle file selection via input
        designInput.addEventListener('change', function (e) {
            selectedFiles = Array.from(this.files);
            updatePreviews();
        });

        // Handle drag and drop
        fileInputLabel.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        fileInputLabel.addEventListener('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        fileInputLabel.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');

            const files = e.dataTransfer.files;
            selectedFiles = Array.from(files);

            // Update the file input
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designInput.files = dataTransfer.files;

            updatePreviews();
        });

        function updatePreviews() {
            imagePreviewContainer.innerHTML = '';
            
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'image-preview-item';
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview ${index + 1}">
                        <button type="button" class="remove-btn" onclick="removeImage(${index})" title="Remove image">
                            <i class="fas fa-times"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-light set-default" onclick="setPrimaryIndex(${index})" title="Set as primary" style="position:absolute;left:6px;bottom:6px;"> 
                            <i class="fas fa-star" style="color: #f1c40f"></i>
                        </button>
                        <div class="default-badge" style="position:absolute;top:6px;left:6px;display:none;background:#3498db;color:#fff;padding:2px 6px;border-radius:4px;font-size:12px;">Primary</div>
                    `;
                    imagePreviewContainer.appendChild(previewItem);
                    updateDefaultVisuals();
                };
                reader.readAsDataURL(file);
            });
            renderReferences();
        }

        function removeImage(index) {
            selectedFiles.splice(index, 1);
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designInput.files = dataTransfer.files;
            updatePreviews();
            // update primary index if needed
            let p = parseInt(document.getElementById('primaryIndex').value || '0', 10);
            if (isNaN(p)) p = 0;
            if (index < p) p = p - 1;
            else if (index === p) p = 0;
            document.getElementById('primaryIndex').value = p;
            updateDefaultVisuals();
        }

        // Click on label to trigger file input
        fileInputLabel.addEventListener('click', function () {
            designInput.click();
        });

        function setPrimaryIndex(i) {
            document.getElementById('primaryIndex').value = i;
            updateDefaultVisuals();
        }

        function updateDefaultVisuals() {
            const items = imagePreviewContainer.querySelectorAll('.image-preview-item');
            const p = parseInt(document.getElementById('primaryIndex').value || '0', 10) || 0;
            items.forEach((it, idx) => {
                const badge = it.querySelector('.default-badge');
                if (badge) badge.style.display = (idx === p) ? 'block' : 'none';
            });
        }

        // References logic
        let references = [];
        function addReference() {
            const title = (document.getElementById('refTitle').value || '').trim();
            const url = (document.getElementById('refUrl').value || '').trim();
            if (!title && !url) return alert('Please provide a title or URL for the reference');
            references.push({ title: title, url: url });
            document.getElementById('refTitle').value = '';
            document.getElementById('refUrl').value = '';
            renderReferences();
        }

        function removeReference(i) {
            references.splice(i, 1);
            renderReferences();
        }

        function renderReferences() {
            const container = document.getElementById('referencesList');
            container.innerHTML = '';
            if (references.length === 0) {
                container.innerHTML = '<div class="text-muted">No references added.</div>';
            } else {
                references.forEach((r, idx) => {
                    const div = document.createElement('div');
                    div.className = 'd-flex align-items-center justify-content-between mb-2';
                    const left = document.createElement('div');
                    left.innerHTML = `<div><strong>${escapeHtml(r.title || 'Reference')}</strong><div class="small text-muted">${escapeHtml(r.url || '')}</div></div>`;
                    const right = document.createElement('div');
                    right.innerHTML = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReference(${idx})">Remove</button>`;
                    div.appendChild(left);
                    div.appendChild(right);
                    container.appendChild(div);
                });
            }
            document.getElementById('referencesJson').value = JSON.stringify(references);
        }

        function escapeHtml(s) { return String(s).replace(/[&<>"]/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
    </script>

        <!-- Liked designs modal (fetches from Public/get_chat_suggestions.php and lets user add selections) -->
        <div id="likedModalBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1050;align-items:center;justify-content:center"> 
            <div style="background:#fff;max-width:920px;width:calc(100% - 48px);max-height:80vh;overflow:auto;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.18);padding:16px;margin:auto">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                    <div style="font-weight:700">Your Liked Designs</div>
                    <div><button id="likedClose" class="btn btn-sm btn-light">Close</button></div>
                </div>
                <div id="likedStatus" class="small text-muted mb-2">Loading...</div>
                <div id="likedGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px"></div>
                <div class="mt-3 text-end">
                    <button id="likedAddSelected" class="btn btn-primary" disabled>Add Selected</button>
                </div>
            </div>
        </div>

        <script>
            (function(){
                const addLikedBtn = document.getElementById('addLikedBtn');
                const likedBackdrop = document.getElementById('likedModalBackdrop');
                const likedGrid = document.getElementById('likedGrid');
                const likedStatus = document.getElementById('likedStatus');
                const likedClose = document.getElementById('likedClose');
                const likedAddSelected = document.getElementById('likedAddSelected');
                let likedCurrentList = [];
                let likedSelected = new Set();

                function openLikedModal(){
                    likedBackdrop.style.display = 'flex';
                    likedStatus.textContent = 'Loading...';
                    likedGrid.innerHTML = '';
                    likedSelected.clear();
                    likedAddSelected.disabled = true;
                    loadLikedDesignsForAdd();
                }
                function closeLikedModal(){ likedBackdrop.style.display = 'none'; }

                addLikedBtn && addLikedBtn.addEventListener('click', openLikedModal);
                likedClose && likedClose.addEventListener('click', closeLikedModal);
                likedBackdrop && likedBackdrop.addEventListener('click', (e)=>{ if (e.target===likedBackdrop) closeLikedModal(); });

                async function loadLikedDesignsForAdd(){
                    try{
                        const res = await fetch('../Public/get_chat_suggestions.php');
                        const j = await res.json();
                        likedCurrentList = (j && j.liked_designs) ? j.liked_designs : [];
                        likedGrid.innerHTML = '';
                        if (!likedCurrentList.length) { likedStatus.textContent = 'No liked designs found.'; return; }
                        likedStatus.textContent = 'Click items to select. Then click Add Selected.';
                        likedCurrentList.forEach(d => {
                            const card = document.createElement('div'); card.className='card p-2'; card.tabIndex=0;
                            card.style.cursor='pointer';
                            const thumb = document.createElement('div'); thumb.className='thumb mb-2';
                            const img = d.image || d.url || '';
                            if (img) thumb.style.backgroundImage = 'url("'+img+'")';
                            thumb.style.backgroundSize='cover'; thumb.style.backgroundPosition='center'; thumb.style.height='86px';
                            const title = document.createElement('div'); title.className='meta'; title.textContent = d.title || ('Design #' + (d.designid||d.id||''));
                            const sub = document.createElement('div'); sub.className='small text-muted'; sub.textContent = (d.likes? d.likes + ' likes' : '');
                            const chk = document.createElement('div'); chk.className='mt-2'; chk.innerHTML = '<input type="checkbox"> Select';
                            card.appendChild(thumb); card.appendChild(title); card.appendChild(sub); card.appendChild(chk);
                            card.addEventListener('click', (e)=>{
                                // toggle selection
                                const id = d.designid || d.id || d.design_id || 0;
                                if (likedSelected.has(id)) { likedSelected.delete(id); card.style.outline = ''; card.querySelector('input[type=checkbox]').checked = false; }
                                else { likedSelected.add(id); card.style.outline = '3px solid rgba(52,152,219,0.35)'; card.querySelector('input[type=checkbox]').checked = true; }
                                likedAddSelected.disabled = likedSelected.size === 0;
                            });
                            likedGrid.appendChild(card);
                        });
                    } catch(e){ console.error(e); likedStatus.textContent = 'Failed to load liked designs.'; }
                }

                likedAddSelected && likedAddSelected.addEventListener('click', ()=>{
                    // add selected items to references
                    const toAdd = likedCurrentList.filter(d => likedSelected.has(d.designid || d.id || d.design_id || 0));
                    toAdd.forEach(d => {
                        const title = d.title || ('Design ' + (d.designid || d.id || ''));
                        const url = d.url || ('../client/design_detail.php?designid=' + (d.designid || d.id || ''));
                        references.push({ title: title, url: url });
                    });
                    renderReferences();
                    closeLikedModal();
                });
            })();
        </script>
</body>

</html>