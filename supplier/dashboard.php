<?php
// ==============================
// File: supplier/dashboard.php
// 供應商專屬後台首頁 - 最終版本 (已修復)
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 1. 檢查是否登入，且身分必須是 supplier
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php');
    exit;
}

$supplierId = $_SESSION['user']['supplierid'];
$supplierName = $_SESSION['user']['name'];

// 2. 讀取該供應商擁有的產品
$sql = "SELECT * FROM Product WHERE supplierid = ? ORDER BY productid DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $supplierId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Dashboard - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
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
        .product-table img {
            width: 50px; height: 50px; object-fit: cover; border-radius: 5px;
        }
        .action-btn { width: 32px; height: 32px; padding: 0; line-height: 32px; border-radius: 50%; text-align: center; }
        
        /* 編輯彈出表單樣式 - 與 Addpoduct.php 一致 */
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
    <header class="bg-white shadow-sm p-3 d-flex justify-content-between align-items-center">
        <div class="h4 mb-0 text-primary">HappyDesign <span class="text-muted fs-6">| Supplier Portal</span></div>
        <div class="d-flex align-items-center gap-3">
            <a class="nav-link text-muted" href="S_profile.php">
                <i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($supplierName) ?>
            </a>
            <a href="schedule.php" class="nav-link  ">schedule</a>
            <a href="../logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="container mb-5">
        <div class="dashboard-header text-center">
            <h2>Product Management Console</h2>
            <p class="mb-0">Manage your inventory and listings</p>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-muted mb-2">Total Products</div>
                    <div class="stat-number"><?= $result->num_rows ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-muted mb-2">Views (Demo)</div>
                    <div class="stat-number">1,240</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-center flex-column">
                    <a href="Addpoduct.php" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-plus me-2"></i>Add New Product
                    </a>
                </div>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>My Products List</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 product-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Image</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <img src="product_image.php?id=<?= $row['productid'] ?>" alt="img">
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['pname']) ?></div>
                                        <small class="text-muted">ID: <?= $row['productid'] ?></small>
                                    </td>
                                    <td>
                                        <?php
                                            $cat = strtolower($row['category']);
                                            $badgeClass = ($cat === 'furniture') ? 'bg-info text-dark' : (($cat === 'material') ? 'bg-success text-white' : 'bg-secondary');
                                        ?>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= htmlspecialchars($row['category']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        HK$<?= number_format($row['price']) ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="product-detail.php?id=<?= $row['productid'] ?>" class="btn btn-primary action-btn btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-warning action-btn btn-sm text-white" title="Edit" data-bs-toggle="modal" data-bs-target="#editModal" onclick="loadProductData(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-pen"></i></button>
                                        <button class="btn btn-danger action-btn btn-sm" title="Delete" onclick="deleteProduct(<?= $row['productid'] ?>, <?= htmlspecialchars(json_encode($row['pname']), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        You haven't uploaded any products yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 編輯產品彈出表單 Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel"><i class="fas fa-edit me-2"></i>Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editProductForm" enctype="multipart/form-data">
                        <input type="hidden" id="productId" name="productid">
                        
                        <!-- 图片上传字段 -->
                        <div class="row g-3 mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-image"></i> Product Image (JPG)</label>
                                    
                                    <!-- 当前图片显示 -->
                                    <div id="current-image-container" class="mb-3" style="display:none;">
                                        <div class="alert alert-info d-flex align-items-center justify-content-between">
                                            <div>
                                                <strong>Current Image:</strong>
                                                <img id="current-image" src="" alt="Current Image" style="max-width: 100px; max-height: 100px; border-radius: 4px; margin-top: 0.5rem;">
                                            </div>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeCurrentImage()">
                                                <i class="fas fa-trash me-1"></i>Remove
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- 新图片上传 -->
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <input type="file" id="productImage" name="image" class="form-control" accept=".jpg,.jpeg" onchange="previewImage(event)">
                                    </div>
                                    <div class="form-text">Upload a JPG image for your product. Maximum size: 5MB.</div>
                                    
                                    <!-- 新图片预览 -->
                                    <div id="image-preview-container" class="mt-2" style="display:none;">
                                        <div class="alert alert-success">
                                            <strong>New Image Preview:</strong>
                                            <img id="image-preview" src="" alt="Image Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; display: block; margin-top: 0.5rem;">
                                        </div>
                                    </div>
                                    
                                    <!-- 隐藏字段标记是否删除图片 -->
                                    <input type="hidden" id="removeImage" name="remove_image" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-box"></i> Product Name *</label>
                                    <input type="text" id="productName" name="pname" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-dollar-sign"></i> Price (HK$) *</label>
                                    <input type="number" id="productPrice" name="price" class="form-control" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-list"></i> Category *</label>
                                    <select id="productCategory" name="category" class="form-select" required>
                                        <option value="">Select</option>
                                        <option value="Furniture">Furniture</option>
                                        <option value="Material">Material</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6" id="material-field" style="display:none;">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-cube"></i> Material</label>
                                    <input type="text" id="productMaterial" name="material" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                                    <textarea id="productDescription" name="description" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-ruler-combined"></i> Size</label>
                                    <input type="text" id="productSize" name="size" class="form-control" placeholder="e.g., 200cm*80cm">
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-12">
                                <div class="form-section">
                                    <label class="form-label"><i class="fas fa-palette"></i> Color</label>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <input type="color" id="main-color-picker" class="form-control form-control-color" value="#C0392B" style="width:48px; height:48px;">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-main-color-btn">
                                            <i class="fas fa-plus"></i> Add Color
                                        </button>
                                    </div>
                                    <div class="form-text">Click "+" to add a color. You can pick multiple colors for each product. Selected colors will appear below.</div>
                                    <div id="selected-colors-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
                                    <input type="hidden" id="color-hidden-input" name="color_str">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveProductChanges()">
                        <i class="fas fa-check me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        let selectedColors = [];
        const selectedColorsPreview = document.getElementById('selected-colors-preview');
        const colorHiddenInput = document.getElementById('color-hidden-input');
        const mainColorPicker = document.getElementById('main-color-picker');
        const addMainColorBtn = document.getElementById('add-main-color-btn');
        const categorySelect = document.getElementById('productCategory');
        const materialField = document.getElementById('material-field');

        /**
         * 更新顏色預覽
         */
        function updateColorPreview() {
            selectedColorsPreview.innerHTML = '';
            selectedColors.forEach((color, idx) => {
                const colorDiv = document.createElement('div');
                colorDiv.className = 'd-flex align-items-center gap-1';
                colorDiv.innerHTML = `
                    <span style="display:inline-block;width:28px;height:28px;border-radius:50%;background:${color};border:2px solid #ccc;"></span>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" title="Remove" onclick="removeColor(${idx})"><i class="fas fa-times"></i></button>
                `;
                selectedColorsPreview.appendChild(colorDiv);
            });
            colorHiddenInput.value = selectedColors.join(", ");
        }

        /**
         * 移除顏色
         */
        window.removeColor = function(idx) {
            selectedColors.splice(idx, 1);
            updateColorPreview();
        }

        /**
         * 添加顏色
         */
        addMainColorBtn.addEventListener('click', function() {
            const color = mainColorPicker.value;
            if (!selectedColors.includes(color)) {
                selectedColors.push(color);
                updateColorPreview();
            }
        });

        /**
         * 切換材料欄位顯示
         */
        function toggleMaterialField() {
            if (categorySelect.value === 'Furniture') {
                materialField.style.display = '';
            } else {
                materialField.style.display = 'none';
                document.getElementById('productMaterial').value = '';
            }
        }
        categorySelect.addEventListener('change', toggleMaterialField);

        /**
         * 图片预览
         */
        function previewImage(event) {
            const file = event.target.files[0];
            const previewContainer = document.getElementById('image-preview-container');
            const previewImg = document.getElementById('image-preview');
            
            if (file) {
                // 验证文件大小（5MB）
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size exceeds 5MB limit');
                    event.target.value = '';
                    previewContainer.style.display = 'none';
                    return;
                }
                
                // 验证文件格式
                if (!file.type.match('image/jpeg')) {
                    alert('Only JPG/JPEG files are allowed');
                    event.target.value = '';
                    previewContainer.style.display = 'none';
                    return;
                }
                
                // 显示预览
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.style.display = 'none';
            }
        }

        /**
         * 删除当前图片
         */
        function removeCurrentImage() {
            document.getElementById('removeImage').value = '1';
            document.getElementById('current-image-container').style.display = 'none';
            alert('Current image will be removed when you save the product.');
        }

        /**
         * 加載產品數據到編輯表單
         */
        function loadProductData(productData) {
            document.getElementById('productId').value = productData.productid;
            document.getElementById('productName').value = productData.pname;
            document.getElementById('productCategory').value = productData.category;
            document.getElementById('productPrice').value = productData.price;
            document.getElementById('productDescription').value = productData.description || '';
            document.getElementById('productSize').value = productData.size || '';
            document.getElementById('productMaterial').value = productData.material || '';
            document.getElementById('productImage').value = ''; // 清空文件输入
            document.getElementById('image-preview-container').style.display = 'none'; // 隐藏新图片预览
            document.getElementById('removeImage').value = '0'; // 重置删除标记
            
            // 显示当前图片
            const currentImageContainer = document.getElementById('current-image-container');
            if (productData.image) {
                const currentImageElement = document.getElementById('current-image');
                currentImageElement.src = 'product_image.php?id=' + productData.productid; // 使用 product_image.php 来显示图片
                currentImageContainer.style.display = 'block';
            } else {
                currentImageContainer.style.display = 'none';
            }
            
            selectedColors = [];
            if (productData.color) {
                const colors = productData.color.split(',').map(c => c.trim()).filter(c => c);
                selectedColors = colors;
            }
            updateColorPreview();
            toggleMaterialField();
        }

        /**
         * 削除產品
         */
        function deleteProduct(productId, productName) {
            // 要求用戶确认
            const confirmDelete = confirm(`Are you sure you want to delete "${productName}"?\n\nThis action cannot be undone.`);
            
            if (!confirmDelete) {
                return; // 用戶取消删除
            }
            
            // 执行删除
            const deleteBtn = event.target.closest('button');
            const originalHTML = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            fetch('delete_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ productid: productId })
            })
            .then(response => response.json().then(data => ({ status: response.status, ok: response.ok, data: data })))
            .then(result => {
                if (result.ok && result.data.success) {
                    alert('Product deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.data.message || 'Failed to delete product'));
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
         * 保存產品更改
         */
        function saveProductChanges() {
            const form = document.getElementById('editProductForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            
            // 顯示加載狀態
            const saveBtn = event.target;
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            fetch('update_product.php', {
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
                    alert('Product updated successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update product'));
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
</body>
</html>
