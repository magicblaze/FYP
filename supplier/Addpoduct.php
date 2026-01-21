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
	$long = trim($_POST['long'] ?? '');
	$wide = trim($_POST['wide'] ?? '');
	$tall = trim($_POST['tall'] ?? '');
	$material = trim($_POST['material'] ?? '');
	
	// Handle color picker (array of hex values)
	$colors = $_POST['color'] ?? [];
	if (!is_array($colors)) {
		$colors = [$colors];
	}
	// Remove empty colors
	$colors = array_filter($colors);
	
	// Handle color images (array of files)
	$colorImages = $_FILES['color_image'] ?? null;
	$colorImageNames = [];
	$uploadDir = __DIR__ . '/../uploads/products/';
	if (!is_dir($uploadDir)) {
		mkdir($uploadDir, 0777, true);
	}
	
	// Process each color and its associated image
	// Each color MUST have an image
	foreach ($colors as $idx => $color) {
		if ($colorImages && isset($colorImages['name'][$idx]) && $colorImages['error'][$idx] === UPLOAD_ERR_OK) {
			$ext = pathinfo($colorImages['name'][$idx], PATHINFO_EXTENSION);
			$imgName = uniqid('colorimg_', true) . '.' . $ext;
			if (move_uploaded_file($colorImages['tmp_name'][$idx], $uploadDir . $imgName)) {
				$colorImageNames[$color] = $imgName;
			} else {
				$colorImageNames[$color] = null;
			}
		} else {
			$colorImageNames[$color] = null;
		}
	}
	$colorStr = implode(", ", $colors);

		// Check if all colors have images
		$allColorsHaveImages = true;
		foreach ($colors as $color) {
			if (empty($colorImageNames[$color])) {
				$allColorsHaveImages = false;
				break;
			}
		}

		// Use the first color's image as the main product image
		$imageName = null;
		if (!empty($colors) && !empty($colorImageNames[$colors[0]])) {
			$imageName = $colorImageNames[$colors[0]];
		}

			if ($pname && $price > 0 && $category && $imageName && !empty($colors) && $allColorsHaveImages) {
				$stmt = $mysqli->prepare("INSERT INTO Product (pname, price, likes, category, description, `long`, `wide`, `tall`, color, material, supplierid) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
			if (!$stmt) {
				$error = 'Database error: ' . $mysqli->error;
			} else {
				$stmt->bind_param("sissssssss", $pname, $price, $category, $description, $long, $wide, $tall, $colorStr, $material, $supplierId);
				if ($stmt->execute()) {
					$productId = $mysqli->insert_id;
					// Insert color-image mapping into ProductColorImage table
					$colorInsertSuccess = true;
					foreach ($colors as $color) {
						if (!empty($colorImageNames[$color])) {
							$pciStmt = $mysqli->prepare("INSERT INTO ProductColorImage (productid, color, image) VALUES (?, ?, ?)");
							if ($pciStmt) {
								$pciStmt->bind_param("iss", $productId, $color, $colorImageNames[$color]);
								if (!$pciStmt->execute()) {
									$colorInsertSuccess = false;
								}
								$pciStmt->close();
							} else {
								$colorInsertSuccess = false;
							}
						}
					}
					if ($colorInsertSuccess) {
						$success = true;
					} else {
						$error = 'Product added but some color images failed to save.';
					}
			} else {
				$error = 'Database error: ' . $stmt->error;
			}
			$stmt->close();
		}
		} else {
			if (!$pname) {
				$error = 'Please enter a product name.';
			} elseif ($price <= 0) {
				$error = 'Please enter a valid price.';
			} elseif (!$category) {
				$error = 'Please select a category.';
			} elseif (empty($colors)) {
				$error = 'Please add at least one color.';
			} elseif (!$allColorsHaveImages) {
				$error = 'Each color must have an image. Please upload an image for all colors.';
			} elseif (!$imageName) {
				$error = 'Please upload an image for the first color.';
			} else {
				$error = 'Please fill in all required fields.';
			}
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
		
		/* Color item styles */
		.color-item {
			display: flex;
			align-items: center;
			gap: 1rem;
			padding: 1rem;
			background: #fff;
			border: 2px solid #e0e0e0;
			border-radius: 8px;
			margin-bottom: 0.75rem;
			transition: all 0.3s ease;
		}
		
		.color-item:hover {
			border-color: #3498db;
			box-shadow: 0 2px 8px rgba(52, 152, 219, 0.1);
		}
		
		.color-swatch {
			width: 50px;
			height: 50px;
			border-radius: 8px;
			border: 2px solid #ccc;
			flex-shrink: 0;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}
		
		.color-info {
			flex: 1;
			display: flex;
			flex-direction: column;
			gap: 0.5rem;
		}
		
		.color-code {
			font-family: monospace;
			font-weight: 600;
			color: #2c3e50;
			font-size: 0.9rem;
		}
		
		.color-image-preview {
			width: 60px;
			height: 60px;
			border-radius: 6px;
			object-fit: cover;
			border: 2px solid #ddd;
			flex-shrink: 0;
		}
		
		.color-actions {
			display: flex;
			gap: 0.5rem;
			flex-shrink: 0;
		}
		
		.btn-remove-color {
			padding: 0.5rem 0.75rem;
			font-size: 0.85rem;
		}
		
		.color-list {
			max-height: 400px;
			overflow-y: auto;
			padding-right: 0.5rem;
		}
		
		.color-list::-webkit-scrollbar {
			width: 8px;
		}
		
		.color-list::-webkit-scrollbar-track {
			background: #f1f1f1;
			border-radius: 4px;
		}
		
		.color-list::-webkit-scrollbar-thumb {
			background: #bbb;
			border-radius: 4px;
		}
		
		.color-list::-webkit-scrollbar-thumb:hover {
			background: #888;
		}
		
		.add-color-section {
			background: #f0f7ff;
			border: 2px dashed #3498db;
			border-radius: 8px;
			padding: 1rem;
			margin-bottom: 1rem;
		}
		
		.color-picker-group {
			display: flex;
			align-items: flex-end;
			gap: 1rem;
			flex-wrap: wrap;
		}
		
		.color-picker-item {
			display: flex;
			flex-direction: column;
			gap: 0.5rem;
		}
		
		.color-picker-item label {
			font-size: 0.9rem;
			font-weight: 600;
			margin-bottom: 0;
		}
		
		.color-picker-item input[type="color"] {
			width: 80px;
			height: 50px;
			border: 2px solid #ddd;
			border-radius: 6px;
			cursor: pointer;
		}
		
		.color-picker-item input[type="file"] {
			flex: 1;
			min-width: 200px;
		}
		
		.empty-state {
			text-align: center;
			padding: 2rem;
			color: #7f8c8d;
			background: #fafafa;
			border-radius: 8px;
			border: 2px dashed #bdc3c7;
		}
		
		.empty-state i {
			font-size: 2rem;
			margin-bottom: 0.5rem;
			color: #bdc3c7;
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
							<div class="alert alert-success alert-dismissible fade show" role="alert">
								<i class="fas fa-check-circle me-2"></i>
								<strong>Success!</strong> Product added successfully with all colors and images.
								<a href="dashboard.php" class="alert-link">Back to Dashboard</a>
								<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
							</div>
						<?php elseif ($error): ?>
							<div class="alert alert-danger alert-dismissible fade show" role="alert">
								<i class="fas fa-exclamation-circle me-2"></i>
								<strong>Error!</strong> <?= htmlspecialchars($error) ?>
								<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
							</div>
						<?php endif; ?>
						<form method="post" enctype="multipart/form-data" id="addProductForm">
							<div class="row g-3 mb-2">
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-box"></i> Product Name *</label>
										<input type="text" name="pname" class="form-control" required>
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
											<option value="">Select Category</option>
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
										<textarea name="description" class="form-control" rows="3"></textarea>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-ruler-horizontal"></i> Long (cm)</label>
										<input type="text" name="long" class="form-control" placeholder="e.g. 200cm">
									</div>
									<div class="form-section mt-2">
										<label class="form-label"><i class="fas fa-ruler-vertical"></i> Wide (cm)</label>
										<input type="text" name="wide" class="form-control" placeholder="e.g. 80cm">
									</div>
									<div class="form-section mt-2">
										<label class="form-label"><i class="fas fa-ruler"></i> Tall (cm)</label>
										<input type="text" name="tall" class="form-control" placeholder="e.g. 300cm">
									</div>
								</div>
							</div>
							
							<!-- Colors Section -->
							<div class="row mb-2">
								<div class="col-md-12">
									<div class="form-section">
										<label class="form-label"><i class="fas fa-palette"></i> Product Colors *</label>
										<p class="form-text text-muted mb-2">Add colors for your product. Each color MUST have an image.</p>
										
										<!-- Add Color Input Section -->
										<div class="add-color-section">
											<div class="color-picker-group">
												<div class="color-picker-item">
													<label for="main-color-picker">Pick Color:</label>
													<input type="color" id="main-color-picker" class="form-control form-control-color" value="#C0392B">
												</div>
												<div class="color-picker-item" style="flex: 1; min-width: 200px;">
													<label for="main-color-image">Select Image (Required) *:</label>
													<input type="file" id="main-color-image" class="form-control" accept="image/*">
												</div>
												<button type="button" class="btn btn-primary" id="add-main-color-btn">
													<i class="fas fa-plus me-1"></i> Add Color
												</button>
											</div>
										</div>
										
										<!-- Selected Colors List -->
										<div id="color-list-container">
											<div id="empty-colors-state" class="empty-state">
												<i class="fas fa-palette"></i>
												<p>No colors added yet. Pick a color and image above, then click "Add Color".</p>
											</div>
											<div id="color-list" class="color-list" style="display: none;"></div>
										</div>
										
										<!-- Hidden inputs for form submission -->
										<div id="hidden-color-inputs"></div>
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
// Category selection - show/hide material field
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

// ===== Color Management System =====
let selectedColors = []; // Array of {color, imageFile}

const mainColorPicker = document.getElementById('main-color-picker');
const mainColorImage = document.getElementById('main-color-image');
const addMainColorBtn = document.getElementById('add-main-color-btn');
const colorListContainer = document.getElementById('color-list-container');
const emptyColorsState = document.getElementById('empty-colors-state');
const colorList = document.getElementById('color-list');
const hiddenColorInputs = document.getElementById('hidden-color-inputs');
const form = document.getElementById('addProductForm');

function updateColorDisplay() {
	if (selectedColors.length === 0) {
		emptyColorsState.style.display = 'block';
		colorList.style.display = 'none';
	} else {
		emptyColorsState.style.display = 'none';
		colorList.style.display = 'block';
	}
	
	// Rebuild color list
	colorList.innerHTML = '';
	selectedColors.forEach((item, idx) => {
		const colorDiv = document.createElement('div');
		colorDiv.className = 'color-item';
		
		let imagePreviewHtml = '';
		let imageStatusClass = 'text-danger';
		let imageStatusText = 'No image (Required)';
		
		if (item.imageFile) {
			const imageUrl = URL.createObjectURL(item.imageFile);
			imagePreviewHtml = '<img src="' + imageUrl + '" alt="Color image" class="color-image-preview">';
			imageStatusClass = 'text-success';
			imageStatusText = 'Image: ' + item.imageFile.name;
		}
		
		colorDiv.innerHTML = '<div class="color-swatch" style="background-color: ' + item.color + ';"></div><div class="color-info"><div class="color-code">' + item.color.toUpperCase() + '</div><small class="' + imageStatusClass + '">' + imageStatusText + '</small></div>' + imagePreviewHtml + '<div class="color-actions"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-color" onclick="removeColor(' + idx + ')"><i class="fas fa-trash"></i> Remove</button></div>';
		colorList.appendChild(colorDiv);
	});
	
	// Rebuild hidden inputs for form submission
	hiddenColorInputs.innerHTML = '';
	selectedColors.forEach((item, idx) => {
		// Hidden color input
		const colorInput = document.createElement('input');
		colorInput.type = 'hidden';
		colorInput.name = 'color[]';
		colorInput.value = item.color;
		hiddenColorInputs.appendChild(colorInput);
		
		// Hidden file input for color image
		if (item.imageFile) {
			const fileInput = document.createElement('input');
			fileInput.type = 'file';
			fileInput.name = 'color_image[]';
			fileInput.style.display = 'none';
			
			// Create a DataTransfer object to set the file
			const dataTransfer = new DataTransfer();
			dataTransfer.items.add(item.imageFile);
			fileInput.files = dataTransfer.files;
			
			hiddenColorInputs.appendChild(fileInput);
		}
	});
}

function removeColor(idx) {
	selectedColors.splice(idx, 1);
	updateColorDisplay();
}

addMainColorBtn.addEventListener('click', function() {
	const color = mainColorPicker.value;
	const imageFile = mainColorImage.files[0] || null;
	
	// Check if color already exists
	if (selectedColors.some(item => item.color.toLowerCase() === color.toLowerCase())) {
		alert('This color has already been added!');
		return;
	}
	
	// Check if image is provided (required)
	if (!imageFile) {
		alert('Please select an image for this color. Each color must have an image.');
		return;
	}
	
	selectedColors.push({
		color: color,
		imageFile: imageFile
	});
	
	// Reset inputs
	mainColorPicker.value = '#C0392B';
	mainColorImage.value = '';
	
	updateColorDisplay();
});

// Form submission validation
form.addEventListener('submit', function(e) {
	if (selectedColors.length === 0) {
		e.preventDefault();
		alert('Please add at least one color!');
		return false;
	}
	
	// Check if all colors have images
	const allColorsHaveImages = selectedColors.every(item => item.imageFile !== null);
	if (!allColorsHaveImages) {
		e.preventDefault();
		alert('Each color must have an image. Please upload an image for all colors.');
		return false;
	}
});

// Initialize
updateColorDisplay();
</script>
</body>
</html>
