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
	$colors = $_POST['color'] ?? [];
	$colorStr = is_array($colors) ? implode(", ", $colors) : $colors;

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
</head>
<body>
	<div class="container mt-5">
		<h2 class="mb-4">Add New Product</h2>
		<?php if ($success): ?>
			<div class="alert alert-success">Product added successfully! <a href="dashboard.php">Back to Dashboard</a></div>
		<?php elseif ($error): ?>
			<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data" class="row g-3">
			<div class="col-md-6">
				<label class="form-label">Product Name *</label>
				<input type="text" name="pname" class="form-control" required>
			</div>
			<div class="col-md-6">
				<label class="form-label">Image *</label>
				<input type="file" name="image" class="form-control" accept="image/*" required>
			</div>
			<div class="col-md-4">
				<label class="form-label">Price (HK$) *</label>
				<input type="number" name="price" class="form-control" min="1" required>
			<div class="col-md-4">
				<label class="form-label">Category *</label>
				<select name="category" class="form-select" required>
					<option value="">Select</option>
					<option value="Furniture">Furniture</option>
					<option value="Material">Material</option>
				</select>
			</div>
			<div class="col-md-4">
				<label class="form-label">Material</label>
				<input type="text" name="material" class="form-control">
			</div>
			<div class="col-md-6">
				<label class="form-label">Description</label>
				<textarea name="description" class="form-control"></textarea>
			</div>
			<div class="col-md-6">
				<label class="form-label">Size</label>
				<input type="text" name="size" class="form-control">
			</div>
			<div class="col-md-12">
				<label class="form-label">Colors (hold Ctrl to select multiple)</label>
				<select name="color[]" class="form-select" multiple required>
					<option value="Red">Red</option>
					<option value="Blue">Blue</option>
					<option value="Yellow">Yellow</option>
					<option value="Green">Green</option>
					<option value="Black">Black</option>
					<option value="White">White</option>
					<option value="Brown">Brown</option>
					<option value="Grey">Grey</option>
					<option value="Other">Other</option>
				</select>
				<div class="form-text">You can select multiple colors for each product.</div>
			</div>
			<div class="col-12">
				<button type="submit" class="btn btn-primary">Add Product</button>
				<a href="dashboard.php" class="btn btn-secondary">Cancel</a>
			</div>
		</form>
	</div>
</body>
</html>
