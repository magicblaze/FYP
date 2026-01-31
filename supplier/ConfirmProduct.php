<?php
// ==============================
// File: supplier/ConfirmProduct.php
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

// 1. 权限检查
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'supplier') {
    header('Location: ../login.php');
    exit;
}

$supplierId = (int)$_SESSION['user']['supplierid'];
$success = ''; $error = '';

// 2. 处理确认 (Confirm) 或 拒绝 (Reject) 逻辑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $refId = (int)$_POST['ref_id'];
    $action = $_POST['action']; // 'confirm' 或 'reject'
    $price = (float)$_POST['price'];

    // 安全校验：确保该引用属于当前供应商
    $check = $mysqli->query("SELECT r.id FROM OrderReference r 
                             JOIN Product p ON r.productid = p.productid 
                             WHERE r.id = $refId AND p.supplierid = $supplierId");
    
    if ($check->num_rows > 0) {
        $newStatus = ($action === 'confirm') ? 'confirmed' : 'rejected';
        
        $stmt = $mysqli->prepare("UPDATE OrderReference SET status = ?, price = ? WHERE id = ?");
        $stmt->bind_param("sdi", $newStatus, $price, $refId);
        
        if ($stmt->execute()) {
            $success = "Order item has been " . ($action === 'confirm' ? "CONFIRMED" : "REJECTED") . " successfully.";
        } else {
            $error = "Database error: " . $mysqli->error;
        }
    } else {
        $error = "Access denied.";
    }
}

// 3. 获取数据 (包含 waiting confirm, confirmed, rejected 状态)
$sql = "SELECT r.id as ref_id, r.status, r.price as ref_price, 
               o.orderid, c.cname as client_name,
               p.pname, p.price as original_price, p.category
        FROM OrderReference r
        JOIN `Order` o ON r.orderid = o.orderid
        JOIN Client c ON o.clientid = c.clientid
        JOIN Product p ON r.productid = p.productid
        WHERE p.supplierid = ?
        ORDER BY FIELD(r.status, 'waiting confirm', 'confirmed', 'waiting delivery', 'completed', 'rejected'), r.created_at DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $supplierId);
$stmt->execute();
$orderItems = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Confirmation - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body { background: #f6f6f7; color: #444; }
        .banner { height: 180px; background: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80') center/cover; }
        .profile-header-card { background: #fff; border-radius: 12px; padding: 25px; margin-top: -60px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); position: relative; z-index: 10; }
        .content-section { background: #fff; border-radius: 12px; padding: 25px; margin-top: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        .status-badge { font-size: 0.75rem; padding: 5px 12px; border-radius: 20px; font-weight: 600; }
        .st-waiting-confirm { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .st-confirmed { background: #d4edda; color: #155724; }
        .st-rejected { background: #f8d7da; color: #721c24; }
        .st-waiting-delivery { background: #cce5ff; color: #004085; }
        .st-completed { background: #e2e3e5; color: #383d41; }

        .item-row { border-bottom: 1px solid #f0f0f0; padding: 15px 0; transition: 0.2s; }
        .item-row:hover { background: #fafafa; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="banner"></div>

<div class="container mb-5">
    <div class="profile-header-card">
        <h2 class="fw-bold mb-0 text-primary">Quote list</h2>
    </div>

    <div class="content-section">
        <?php if($success): ?> <div class="alert alert-success py-2"><?= $success ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-danger py-2"><?= $error ?></div> <?php endif; ?>

        <div class="row fw-bold text-muted small mb-3 border-bottom pb-2">
            <div class="col-md-2">Order / Client</div>
            <div class="col-md-3">Product Name</div>
            <div class="col-md-2">Your Price</div>
            <div class="col-md-2 text-center">Status</div>
            <div class="col-md-3 text-end">Action</div>
        </div>

        <?php while($row = $orderItems->fetch_assoc()): 
            $stClass = "st-" . str_replace(' ', '-', $row['status']);
        ?>
            <div class="item-row row align-items-center">
                <div class="col-md-2">
                    <span class="d-block fw-bold">#<?= $row['orderid'] ?></span>
                    <small class="text-muted"><?= htmlspecialchars($row['client_name']) ?></small>
                </div>
                <div class="col-md-3">
                    <strong class="text-dark"><?= htmlspecialchars($row['pname']) ?></strong>
                    <div class="small text-muted"><?= $row['category'] ?></div>
                </div>
                <div class="col-md-2">
                    <span class="text-success fw-bold">HK$<?= number_format($row['ref_price'] ?: $row['original_price'], 2) ?></span>
                </div>
                <div class="col-md-2 text-center">
                    <span class="status-badge <?= $stClass ?>"><?= ucwords($row['status']) ?></span>
                </div>
                <div class="col-md-3 text-end">
                    <?php if($row['status'] === 'waiting confirm'): ?>
                        <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#actionModal" 
                                onclick='fillModal(<?= json_encode($row) ?>)'>Review</button>
                    <?php else: ?>
                        <button class="btn btn-light btn-sm disabled">Submitted</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quote Review</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ref_id" id="m_ref_id">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Review Product</label>
                    <input type="text" id="m_pname" class="form-control bg-light" readonly>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-primary">Price (HK$)</label>
                    <input type="number" step="0.01" name="price" id="m_price" class="form-control form-control-lg border-primary" required>
                </div>

                <div class="alert alert-warning small">
                    <i class="fas fa-exclamation-triangle me-1"></i> Once you confirm or reject, this action cannot be undone.
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <!-- 拒绝按钮 -->
                <button type="submit" name="action" value="reject" class="btn btn-outline-danger px-4" onclick="return confirm('Reject this product for this order?')">
                    <i class="fas fa-times me-1"></i> Reject
                </button>
                <!-- 确认按钮 -->
                <button type="submit" name="action" value="confirm" class="btn btn-success px-5">
                    <i class="fas fa-check me-1"></i> Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function fillModal(data) {
    document.getElementById('m_ref_id').value = data.ref_id;
    document.getElementById('m_pname').value = data.pname;
    document.getElementById('m_price').value = data.ref_price || data.original_price;
}
</script>
    <!-- ==================== Chat Widget Integration ==================== -->
    <?php
    if (isset($_SESSION['user'])) {
        include __DIR__ . '/../Public/chat_widget.php';
    }
    ?>

    <!-- Chatfunction and initialization moved into Public/chat_widget.php -->
</body>
</html>