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

// Load all designs from this designer
$sql = "SELECT * FROM Design WHERE designerid = ? ORDER BY designid DESC";
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
    <link rel="stylesheet" href="../css/supplier_style.css">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
            height: 100%;
        }
        .stat-number { font-size: 2rem; font-weight: bold; color: #3498db; }
        .design-table img {
            width: 50px; height: 50px; object-fit: cover; border-radius: 5px;
        }
        .action-btn { width: 32px; height: 32px; padding: 0; line-height: 32px; border-radius: 50%; text-align: center; }
        
        /* Form Section Styles */
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
        .modal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="h4 mb-0"><a href="designer_dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
            <nav>
                <ul class="nav align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="designer_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../supplier/schedule.php">Schedule</a></li>
                </ul>
            </nav>
        </div>
        <nav>
            <ul class="nav align-items-center">
                <li class="nav-item me-2">
                    <a class="nav-link text-muted" href="#">
                        <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($designerName) ?>
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">
        <div class="dashboard-header text-center">
            <h2>Design Management Console</h2>
            <p class="mb-0">Manage your design portfolio and listings</p>
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
                    <a href="design_orders.php" class="btn btn-primary btn-lg w-100">
                        View Design Orders
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-center flex-column">
                    <a href="add_design.php" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-plus me-2"></i>Add New Design
                    </a>
                </div>
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
                                            if (!empty($row['design'])) {
                                                echo '<img src="../uploads/designs/' . htmlspecialchars($row['design']) . '" alt="Design Image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">';
                                            } else {
                                                echo '<img src="../uploads/designs/placeholder.jpg" alt="No Image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold">Design #<?= $row['designid'] ?></div>
                                        <small class="text-muted">ID: <?= $row['designid'] ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars(substr($row['tag'], 0, 50)) ?></small>
                                    </td>
                                    <td>
                                        HK$<?= number_format($row['price']) ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-heart text-danger me-1"></i><?= $row['likes'] ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="design-detail.php?id=<?= $row['designid'] ?>" class="btn btn-primary action-btn btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-warning action-btn btn-sm text-white" title="Edit" data-bs-toggle="modal" data-bs-target="#editModal" onclick="loadDesignData(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-pen"></i></button>
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
        </div>
    </div>

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
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-image"></i> Design Image</label>
                                    <input type="file" id="designImage" name="design" class="form-control" accept="image/*">
                                    <small class="text-muted">Leave empty to keep current image</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-dollar-sign"></i> Price (HK$) *</label>
                                    <input type="number" id="designPrice" name="price" class="form-control" min="1" required>
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
        /**
         * Load Design Data into Edit Form
         */
        function loadDesignData(designData) {
            document.getElementById('designId').value = designData.designid;
            document.getElementById('designPrice').value = designData.price;
            document.getElementById('designTag').value = designData.tag || '';
        }

        /**
         * Delete Design
         */
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

        /**
         * Save Design Changes
         */
        function saveDesignChanges() {
            const form = document.getElementById('editDesignForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            
            // Show loading state
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
        // Initialize chat widget for designer pages (no pre-filled share payload)
        $CHAT_JS_PATH = '../Public/Chatfunction.js';
        $CHAT_API_PATH = '../Public/ChatApi.php?action=';
        $CHAT_SHARE = null;
        include __DIR__ . '/../Public/chat_widget.php';
    ?>
</body>
</html>
