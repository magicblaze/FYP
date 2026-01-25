<?php
// ==============================
// File: designer/designer_dashboard.php
// Designer Dashboard - Manage Designs
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// Check if user is logged in and is a designer
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'designer') {
    header('Location: ../login.php');
    exit;
}

$designerId = $_SESSION['user']['designerid'];
$designerName = $_SESSION['user']['name'];


// Load all designs from this designer with their first available image from DesignImage table
// Uses a subquery to get the image with the lowest image_order for each design
$sql = "SELECT d.*, di.image_filename 
        FROM Design d 
        LEFT JOIN DesignImage di ON d.designid = di.designid 
        WHERE d.designerid = ? 
        AND (di.imageid IS NULL OR di.imageid = (
            SELECT imageid FROM DesignImage 
            WHERE designid = d.designid 
            ORDER BY image_order ASC 
            LIMIT 1
        ))
        ORDER BY d.designid DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $designerId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Designer Dashboard - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        /* Adopt styles from client design_dashboard for consistent look */
        .search-section {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 0.75rem;
        }
        .search-section .form-control {
            border: 2px solid #ecf0f1;
            border-radius: 8px;
        }
        .search-section .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.6rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            text-align: center;
            height: 100%;
        }
        .stat-number { font-size: 1.9rem; font-weight: 700; color: #3498db; }

        .design-table img {
            width: 50px; height: 50px; object-fit: cover; border-radius: 5px;
        }
        .action-btn { width: 36px; height: 36px; padding: 0; line-height: 36px; border-radius: 50%; text-align: center; }

        .form-section {
            background: #f8fafd;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(44, 62, 80, 0.06);
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .modal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
        }
        .modal-header .btn-close { filter: brightness(0) invert(1); }

        .image-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.8rem;
            margin-top: 1rem;
        }
        .image-preview-item { position: relative; border-radius: 8px; overflow: hidden; background: #f0f0f0; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border: 2px solid #e9eef2; }
        .image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .image-preview-item .remove-btn { position: absolute; top: 6px; right: 6px; background: rgba(231,76,60,0.95); color: white; border: none; border-radius: 50%; width: 26px; height: 26px; padding: 0; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; opacity: 0; transition: opacity 0.18s; }
        .image-preview-item:hover .remove-btn { opacity: 1; }

        .file-input-label { display: flex; align-items: center; justify-content: center; padding: 1.25rem; border: 2px dashed #3498db; border-radius: 8px; background: #f8f9fa; cursor: pointer; transition: all 0.2s; text-align: center; }
        .file-input-label:hover { background: #eaf4fb; border-color: #2980b9; }
        .file-input-label.dragover { background: #d4e9f7; border-color: #2980b9; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- Dashboard Content -->
    <main class="container-lg mt-4">
        <div class="dashboard-header text-left my-4">
            <h2 class="page-title">Hello, <?= htmlspecialchars($designerName) ?></h2>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-muted mb-2">Total Designs</div>
                    <div class="stat-number"><?= $result->num_rows ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-center flex-column">
                    <a href="OrderManager.php" class="btn btn-primary btn-lg w-100">
                        Order Manager
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-center flex-column">
                    <a href="DesignManager.php" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-plus me-2"></i>Design Manager
                    </a>
                </div>
            </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-palette me-2"></i>My Designs List</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 design-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Image</th>
                                <th>Design Name</th>
                                <th>Tags</th>
                                <th>Price</th>
                                <th>Likes</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php 
                                            if (!empty($row['image_filename'])) {
                                                echo '<img src="../uploads/designs/' . htmlspecialchars($row['image_filename']) . '" alt="Design Image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">';
                                            } elseif (!empty($row['design'])) {
                                                echo '<img src="../uploads/designs/' . htmlspecialchars($row['design']) . '" alt="Design Image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">';
                                            } else {
                                                echo '<img src="../uploads/designs/placeholder.jpg" alt="No Image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['designName']) ?></div>
                                        <small class="text-muted">ID: <?= $row['designid'] ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars(substr($row['tag'], 0, 50)) ?></small>
                                    </td>
                                    <td>
                                        HK$<?= number_format($row['expect_price']) ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-heart text-danger me-1"></i><?= $row['likes'] ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="design-detail.php?id=<?= $row['designid'] ?>" class="btn btn-primary action-btn btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-warning action-btn btn-sm text-white" title="Edit" data-bs-toggle="modal" data-bs-target="#editModal" data-designid="<?= $row['designid'] ?>" data-designname="<?= htmlspecialchars($row['designName'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= $row['expect_price'] ?>" data-tag="<?= htmlspecialchars($row['tag'], ENT_QUOTES, 'UTF-8') ?>" data-description="<?= htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') ?>" onclick="loadDesignData(this)"><i class="fas fa-pen"></i></button>
                                        <button class="btn btn-danger action-btn btn-sm" title="Delete" onclick="deleteDesign(<?= $row['designid'] ?>)"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        You haven't uploaded any designs yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

    <!-- Edit Design Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel"><i class="fas fa-edit me-2"></i>Edit Design</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 80vh; overflow-y: auto;">
                    <form id="editDesignForm" enctype="multipart/form-data">
                        <input type="hidden" id="designId" name="designid">
                        
                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-heading"></i> Design Name *</label>
                                    <input type="text" id="designName" name="design_name" class="form-control" placeholder="e.g. Modern Living Room Design" required>
                                    <small class="text-muted">Give your design a descriptive name</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-image"></i> Current Images</label>
                                    <div class="image-preview-container" id="currentImagesContainer" style="min-height: 100px; border: 1px solid #e0e0e0; border-radius: 8px; padding: 1rem; background: #fafafa;">
                                        <p class="text-muted text-center mb-0">Loading current images...</p>
                                    </div>
                                    <small class="text-muted d-block mt-2">Click the X button to delete an image.</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-plus-circle"></i> Add More Images (Optional)</label>
                                    <label class="file-input-label" id="fileInputLabel">
                                        <div>
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 1.5rem; color: #3498db; margin-bottom: 0.5rem; display: block;"></i>
                                            <strong>Click to upload or drag & drop</strong>
                                            <p class="text-muted mb-0" style="font-size: 0.85rem;">JPG, PNG, GIF, WebP</p>
                                        </div>
                                    </label>
                                    <input type="file" id="designImages" name="design[]" class="form-control" accept="image/*" multiple style="display: none;">
                                    <div class="image-preview-container" id="imagePreviewContainer"></div>
                                    <small class="text-muted d-block mt-2">Upload additional images to add to this design.</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-dollar-sign"></i> Expected Price (HK$) *</label>
                                    <input type="number" id="designPrice" name="expect_price" class="form-control" min="1" required>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-tags"></i> Tags *</label>
                                    <input type="text" id="designTag" name="tag" class="form-control" placeholder="e.g. modern, minimalist, luxury" required>
                                    <small class="text-muted">Separate tags with commas</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-file-alt"></i> Description</label>
                                    <textarea id="designDescription" name="description" class="form-control" rows="4" placeholder="Enter design description..."></textarea>
                                    <small class="text-muted">Optional: Provide details about your design</small>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveDesignChanges()">
                        <i class="fas fa-check me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        let selectedFiles = [];
        const designImages = document.getElementById('designImages');
        const fileInputLabel = document.getElementById('fileInputLabel');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');

        designImages.addEventListener('change', function(e) {
            selectedFiles = Array.from(this.files);
            updatePreviews();
        });

        fileInputLabel.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        fileInputLabel.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        fileInputLabel.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            selectedFiles = Array.from(files);
            
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designImages.files = dataTransfer.files;
            
            updatePreviews();
        });

        function updatePreviews() {
            imagePreviewContainer.innerHTML = '';
            
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'image-preview-item';
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview ${index + 1}">
                        <button type="button" class="remove-btn" onclick="removeImage(${index})" title="Remove image">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    imagePreviewContainer.appendChild(previewItem);
                };
                reader.readAsDataURL(file);
            });
        }

        function removeImage(index) {
            selectedFiles.splice(index, 1);
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            designImages.files = dataTransfer.files;
            updatePreviews();
        }

        fileInputLabel.addEventListener('click', function() {
            designImages.click();
        });

        function loadDesignData(button) {
            const designId = button.dataset.designid;
            const designName = button.dataset.designname;
            const price = button.dataset.price;
            const tag = button.dataset.tag;
            const description = button.dataset.description;
            
            document.getElementById('designId').value = designId;
            document.getElementById('designName').value = designName || '';
            document.getElementById('designPrice').value = price;
            document.getElementById('designTag').value = tag || '';
            document.getElementById('designDescription').value = description || '';
            selectedFiles = [];
            designImages.value = '';
            imagePreviewContainer.innerHTML = '';
            loadCurrentImages(designId);
        }
        
        function loadCurrentImages(designId) {
            const container = document.getElementById('currentImagesContainer');
            container.innerHTML = '<p class="text-muted text-center mb-0">Loading...</p>';
            fetch('get_design_images.php?designid=' + designId)
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(d => {
                    if (d.success && d.images && d.images.length > 0) {
                        container.innerHTML = '';
                        d.images.forEach((img, i) => {
                            const item = document.createElement('div');
                            item.className = 'image-preview-item';
                            item.style.position = 'relative';
                            const imgHTML = '<img src="../uploads/designs/' + img.image_filename + '" alt="Image ' + (i+1) + '" style="width:100%;height:100%;object-fit:cover;">';
                            const labelHTML = '<div style="position:absolute;bottom:5px;right:35px;background:rgba(0,0,0,0.7);color:white;padding:2px 6px;border-radius:4px;font-size:12px;">Image ' + (i+1) + '</div>';
                            const btnHTML = '<button type="button" class="remove-btn" onclick="deleteCurrentImage(\'' + img.imageid + '\',\'' + designId + '\')" title="Delete image" style="position:absolute;top:5px;right:5px;background:rgba(231,76,60,0.9);color:white;border:none;border-radius:50%;width:24px;height:24px;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;"><i class="fas fa-times"><\/i><\/button>';
                            item.innerHTML = imgHTML + labelHTML + btnHTML;
                            container.appendChild(item);
                        });
                    } else {
                        container.innerHTML = '<p class="text-muted text-center mb-0">No images uploaded</p>';
                    }
                })
                .catch(e => {
                    console.error(e);
                    container.innerHTML = '<p class="text-muted text-center mb-0">No images uploaded</p>';
                });
        }
        
        function deleteCurrentImage(imageId, designId) {
            if (!confirm('Are you sure you want to delete this image?')) {
                return;
            }
            fetch('delete_design_image.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({imageid: imageId, designid: designId})
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                if (!text) {
                    throw new Error('Empty response from server');
                }
                return JSON.parse(text);
            })
            .then(d => {
                if (d.success) {
                    loadCurrentImages(designId);
                } else {
                    alert('Error: ' + (d.message || 'Failed to delete'));
                }
            })
            .catch(e => {
                console.error('Error deleting image:', e);
                alert('Error deleting image: ' + e.message);
            });
        }

        function deleteDesign(designId) {
            const confirmDelete = confirm(`Are you sure you want to delete this design?\n\nThis action cannot be undone.`);
            
            if (!confirmDelete) {
                return;
            }
            
            const deleteBtn = event.target.closest('button');
            const originalHTML = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            fetch('delete_design.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ designid: designId })
            })
            .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, data: data })))
            .then(result => {
                if (result.ok && result.data.success) {
                    alert('Design deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.data.message || 'Failed to delete design'));
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalHTML;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalHTML;
            });
        }

        function saveDesignChanges() {
            const form = document.getElementById('editDesignForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            
            const saveBtn = event.target;
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            fetch('update_design.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('Design updated successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update design'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        }
    </script>
    <?php
        $CHAT_SHARE = null;
        include __DIR__ . '/../Public/chat_widget.php';
    ?>
</body>
</html>
