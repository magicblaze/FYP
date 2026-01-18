<?php
require_once __DIR__ . '/../config.php';
session_start();
// Check if supplier is logged in

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
	header('Location: ../login.php');
	exit;
}
$supplierId = $_SESSION['user']['supplierid'];

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$pname = trim($_POST['pname'] ?? '');
	$price = intval($_POST['price'] ?? 0);
	$category = $_POST['category'] ?? '';
	$description = trim($_POST['description'] ?? '');
	$size = trim($_POST['size'] ?? '');
	$material = trim($_POST['material'] ?? '');
	// Handle color picker (array of hex values)
	$colors = $_POST['color'] ?? [];
	if (!is_array($colors)) {
		$colors = [$colors];
	}
	$colorStr = implode(", ", $colors);

	// Handle image upload
	$imageName = null;
	if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
		$ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
	$imageName = uniqid('prod_', true) . '.' . $ext;
	$uploadDir = __DIR__ . '/../uploads/products/';
	if (!is_dir($uploadDir)) {
			mkdir($uploadDir, 0777, true);
		}
		move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName);
	}

	if ($pname && $price > 0 && $category && $imageName) {
	$stmt = $mysqli->prepare("INSERT INTO Product (pname, image, price, likes, category, description, size, color, material, supplierid) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
	$stmt->bind_param("ssissssss", $pname, $imageName, $price, $category, $description, $size, $colorStr, $material, $supplierId);
	if ($stmt->execute()) {
			$success = true;
		} else {
			$error = 'Database error: ' . $stmt->error;
		}
	} else {
		$error = 'Please fill in all required fields and upload an image.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Add New Product</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<link rel="stylesheet" href="../css/supplier_style.css">
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
	</style>
</head>
<body>
	<!-- Navbar -->
	<header class="bg-white shadow p-3 d-flex justify-content-between align-items-center">
		<div class="d-flex align-items-center gap-3">
			<div class="h4 mb-0"><a href="dashboard.php" style="text-decoration: none; color: inherit;">HappyDesign</a></div>
			<nav>
				<ul class="nav align-items-center gap-2">
					<li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
					<li class="nav-item"><a class="nav-link" href="schedule.php">Schedule</a></li>
				</ul>
			</nav>
		</div>
		<nav>
			<ul class="nav align-items-center">
				<li class="nav-item me-2">
					<a class="nav-link text-muted" href="#">
						<i class="fas fa-user me-1"></i>Hello <?= htmlspecialchars($_SESSION['user']['name'] ?? 'Supplier') ?>
					</a>
				</li>
				<li class="nav-item"><a class="nav-link" href="../logout.php">Logout</a></li>
			</ul>
		</nav>
	</header>

	<div class="container mb-5">
		<div class="dashboard-header text-center mb-4" style="background: linear-gradient(135deg, #2c3e50, #3498db); color: white; padding: 2rem 0; border-radius: 0 0 15px 15px;">
			<h2 class="mb-1"><i class="fas fa-plus-circle me-2"></i>Add New Product</h2>
			<p class="mb-0">Fill in the details to add a new product to your inventory</p>
		</div>
		<div class="row justify-content-center">
			<div class="col-lg-10">
				<div class="card shadow-sm border-0">
					<div class="card-body p-4">
						<?php if ($success): ?>
							<div class="alert alert-success">Product added successfully! <a href="dashboard.php">Back to Dashboard</a></div>
						<?php elseif ($error): ?>
							<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
						<?php endif; ?>
						<form method="post" enctype="multipart/form-data">
							<div class="row g-3 mb-2">
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-box"></i> Product Name *</label>
										<input type="text" name="pname" class="form-control" required>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-image"></i> Image *</label>
										<input type="file" name="image" class="form-control" accept="image/*" required>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-dollar-sign"></i> Price (HK$) *</label>
										<input type="number" name="price" class="form-control" min="1" required>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-list"></i> Category *</label>
										<select name="category" class="form-select" required>
											<option value="">Select</option>
											<option value="Furniture">Furniture</option>
											<option value="Material">Material</option>
										</select>
									</div>
								</div>
							</div>
							<div class="row mb-2">
								<div class="col-md-4" id="material-field" style="display:none;">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-cube"></i> Material</label>
										<input type="text" name="material" class="form-control">
									</div>
								</div>
							</div>
							<div class="row g-3 mb-2">
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-align-left"></i> Description</label>
										<textarea name="description" class="form-control"></textarea>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-ruler-combined"></i> Size</label>
										<input type="text" name="size" class="form-control">
									</div>
								</div>
							</div>
							<div class="row mb-2">
								<div class="col-md-12">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-palette"></i> Colors <span class="text-muted">(Pick one or more)</span></label>
										<div class="d-flex align-items-center gap-2 mb-2">
											<input type="color" id="main-color-picker" class="form-control form-control-color" value="#C0392B" style="width:48px; height:48px;">
											<button type="button" class="btn btn-outline-primary btn-sm" id="add-main-color-btn">
												<i class="fas fa-plus"></i> Add Color
											</button>
										</div>
										<input type="hidden" name="color[]" id="color-hidden-input">
										<div class="form-text">Click "+" to add a color. You can pick multiple colors for each product. Selected colors will appear below.</div>
										<div id="selected-colors-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-12 d-flex gap-2 justify-content-end">
									<button type="submit" class="btn btn-success px-4"><i class="fas fa-check"></i> Add Product</button>
									<a href="dashboard.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left"></i> Cancel</a>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
// 類別選擇時顯示/隱藏材料欄位
const categorySelect = document.querySelector('select[name="category"]');
const materialField = document.getElementById('material-field');
function toggleMaterialField() {
	if (categorySelect.value === 'Furniture') {
		materialField.style.display = '';
	} else {
		materialField.style.display = 'none';
		materialField.querySelector('input').value = '';
	}
}
categorySelect.addEventListener('change', toggleMaterialField);
window.addEventListener('DOMContentLoaded', toggleMaterialField);
// --- 固定主色選擇器多選邏輯 ---
let selectedColors = [];
const selectedColorsPreview = document.getElementById('selected-colors-preview');
const colorHiddenInput = document.getElementById('color-hidden-input');
const mainColorPicker = document.getElementById('main-color-picker');
const addMainColorBtn = document.getElementById('add-main-color-btn');

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
	// Update hidden input for form submission
	colorHiddenInput.name = 'color[]';
	colorHiddenInput.value = selectedColors.join(',');
}

addMainColorBtn.addEventListener('click', function() {
	const color = mainColorPicker.value;
	if (!selectedColors.includes(color)) {
		selectedColors.push(color);
		updateColorPreview();
	}
});

window.removeColor = function(idx) {
	selectedColors.splice(idx, 1);
	updateColorPreview();
}

// 初始化
updateColorPreview();
</script>
</body>
