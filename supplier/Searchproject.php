<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
	header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
	exit;
}

$supplier_id = (int) ($_SESSION['user']['supplierid'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_project'])) {
	$order_id = (int) ($_POST['order_id'] ?? 0);
	if ($supplier_id > 0 && $order_id > 0) {
		$accept_sql = "UPDATE `Order`
		              SET supplierid = ?, supplier_status = 'Pending'
		              WHERE orderid = ?
		                AND supplierid IS NULL
		                AND supplier_status = 'Pending'
		                AND LOWER(ostatus) = 'coordinating contractors'";
		$accept_stmt = mysqli_prepare($mysqli, $accept_sql);
		if ($accept_stmt) {
			mysqli_stmt_bind_param($accept_stmt, "ii", $supplier_id, $order_id);
			mysqli_stmt_execute($accept_stmt);
			$affected = mysqli_stmt_affected_rows($accept_stmt);
			mysqli_stmt_close($accept_stmt);
			header('Location: Searchproject.php?msg=' . ($affected > 0 ? 'accepted' : 'unavailable'));
			exit;
		}
	}
	header('Location: Searchproject.php?msg=error');
	exit;
}

$search = trim($_GET['search'] ?? '');

$sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.budget,
			   c.cname AS client_name,
			   d.designName,
			   d.tag,
			   (SELECT dp.filename FROM DesignedPicture dp WHERE dp.orderid = o.orderid ORDER BY dp.upload_date DESC LIMIT 1) AS latest_picture
		FROM `Order` o
		JOIN `Client` c ON o.clientid = c.clientid
		LEFT JOIN `Design` d ON o.designid = d.designid
		WHERE o.supplierid IS NULL
		  AND o.supplier_status = 'Pending'
		  AND LOWER(o.ostatus) = 'coordinating contractors'";

$params = [];
$types = '';
if ($search !== '') {
	$sql .= " AND (
				CAST(o.orderid AS CHAR) LIKE ?
				OR c.cname LIKE ?
				OR IFNULL(o.Requirements, '') LIKE ?
				OR IFNULL(d.designName, '') LIKE ?
				OR IFNULL(d.tag, '') LIKE ?
			 )";
	$keyword = '%' . $search . '%';
	$params = [$keyword, $keyword, $keyword, $keyword, $keyword];
	$types = 'sssss';
}

$sql .= " ORDER BY o.odate DESC";

$stmt = mysqli_prepare($mysqli, $sql);
if ($stmt) {
	if (!empty($params)) {
		mysqli_stmt_bind_param($stmt, $types, ...$params);
	}
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$projects = mysqli_fetch_all($result, MYSQLI_ASSOC);
	mysqli_stmt_close($stmt);
} else {
	$projects = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Search Projects - Supplier</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<link rel="stylesheet" href="../css/styles.css">
</head>

<body>
	<?php include_once __DIR__ . '/../includes/header.php'; ?>

	<main class="container-lg mt-4 mb-5">
		<div class="d-flex justify-content-between align-items-center mb-3">
			<h3 class="mb-0"><i class="fas fa-search me-2"></i>Available Projects</h3>
			<a href="dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
		</div>

		<?php if (isset($_GET['msg']) && $_GET['msg'] === 'accepted'): ?>
			<div class="alert alert-success">
				<i class="fas fa-check-circle me-2"></i>Project accepted successfully. Manager will review your assignment request.
			</div>
		<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'unavailable'): ?>
			<div class="alert alert-warning">
				<i class="fas fa-exclamation-triangle me-2"></i>This project is no longer available.
			</div>
		<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
			<div class="alert alert-danger">
				<i class="fas fa-times-circle me-2"></i>Unable to accept this project. Please try again.
			</div>
		<?php endif; ?>

		<div class="card mb-3">
			<div class="card-body">
				<form method="get" class="d-flex gap-2">
					<input type="text" name="search" class="form-control" placeholder="Search by project id, client, requirement, design..."
						value="<?php echo htmlspecialchars($search); ?>">
					<button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Search</button>
				</form>
				<small class="text-muted">Only projects approved by client for re-sharing are shown here.</small>
			</div>
		</div>

		<?php if (!empty($projects)): ?>
			<div class="table-container">
				<table class="table">
					<thead>
						<tr>
							<th>Project ID</th>
							<th>Date</th>
							<th>Client</th>
							<th>Design</th>
							<th>Designed Picture</th>
							<th>Budget</th>
							<th>Status</th>
							<th>Requirements</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($projects as $project): ?>
							<tr>
								<td><strong>#<?php echo (int) $project['orderid']; ?></strong></td>
								<td><?php echo htmlspecialchars(date('Y-m-d', strtotime($project['odate']))); ?></td>
								<td><?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></td>
								<td>
									<div><?php echo htmlspecialchars($project['designName'] ?? 'N/A'); ?></div>
									<small class="text-muted"><?php echo htmlspecialchars($project['tag'] ?? ''); ?></small>
								</td>
								<td>
									<?php if (!empty($project['latest_picture'])): ?>
										<button type="button" class="btn btn-sm btn-outline-primary"
											onclick="openPictureModal('<?php echo htmlspecialchars($project['latest_picture'], ENT_QUOTES); ?>')">
											<i class="fas fa-image me-1"></i>View
										</button>
									<?php else: ?>
										<span class="text-muted small">No picture</span>
									<?php endif; ?>
								</td>
								<td>HK$<?php echo number_format((float) ($project['budget'] ?? 0), 2); ?></td>
								<td><span class="badge bg-info text-white"><?php echo htmlspecialchars($project['ostatus']); ?></span></td>
								<td><?php echo htmlspecialchars($project['Requirements'] ?? '—'); ?></td>
								<td>
									<form method="post" onsubmit="return confirm('Accept this project assignment request?');">
										<input type="hidden" name="accept_project" value="1">
										<input type="hidden" name="order_id" value="<?php echo (int) $project['orderid']; ?>">
										<button type="submit" class="btn btn-sm btn-success">
											<i class="fas fa-check me-1"></i>Accept Project
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else: ?>
			<div class="alert alert-warning mb-0">
				<i class="fas fa-info-circle me-2"></i>No available projects for supplier reassignment right now.
			</div>
		<?php endif; ?>
	</main>

	<div class="modal fade" id="pictureModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="fas fa-image me-2"></i>Designed Picture</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body text-center">
					<img id="pictureModalImage" src="" alt="Designed Picture" style="max-width:100%;max-height:70vh;border-radius:8px;" />
				</div>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		function openPictureModal(filename) {
			const img = document.getElementById('pictureModalImage');
			if (!img) return;
			img.src = '../uploads/designed_Picture/' + filename;
			const modalEl = document.getElementById('pictureModal');
			if (modalEl) {
				const modal = new bootstrap.Modal(modalEl);
				modal.show();
			}
		}
	</script>
	<?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

