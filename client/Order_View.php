<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Only allow logged-in clients
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$client_id = $user['clientid'];

$orderid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Verify order exists and belongs to this client
$check_order_sql = "SELECT o.orderid, o.odate, o.Requirements, o.ostatus, o.cost, o.deposit,
               c.clientid, c.cname as client_name, c.ctel, c.cemail, c.budget,
               d.designid, d.designName, d.expect_price as design_price, d.tag as design_tag,
               s.scheduleid, s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid
        WHERE o.orderid = ? AND o.clientid = ?";

$stmt = mysqli_prepare($mysqli, $check_order_sql);
mysqli_stmt_bind_param($stmt, "ii", $orderid, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    die("Order not found or you don't have permission to view this order.");
}

// Fetch order references
$ref_sql = "SELECT 
                orr.id, 
                orr.productid,
                orr.status,
                orr.price,
                orr.note,
                p.pname, 
                p.price as product_price, 
                p.category,
                p.description as product_description
            FROM `OrderReference` orr
            LEFT JOIN `Product` p ON orr.productid = p.productid
            WHERE orr.orderid = ?";

$ref_stmt = mysqli_prepare($mysqli, $ref_sql);
mysqli_stmt_bind_param($ref_stmt, "i", $orderid);
mysqli_stmt_execute($ref_stmt);
$ref_result = mysqli_stmt_get_result($ref_stmt);
$references = array();
while ($ref_row = mysqli_fetch_assoc($ref_result)) {
    $references[] = $ref_row;
}

// Fetch additional fees
$fees_sql = "SELECT fee_id, fee_name, amount, description, created_at FROM `AdditionalFee` WHERE orderid = ? ORDER BY created_at ASC";
$fees_stmt = mysqli_prepare($mysqli, $fees_sql);
mysqli_stmt_bind_param($fees_stmt, "i", $orderid);
mysqli_stmt_execute($fees_stmt);
$fees_result = mysqli_stmt_get_result($fees_stmt);
$fees = array();
$total_fees = 0;
while ($fee_row = mysqli_fetch_assoc($fees_result)) {
    $fees[] = $fee_row;
    $total_fees += floatval($fee_row['amount']);
}

// Fetch latest designed picture
$pic_sql = "SELECT filename, pictureid FROM `DesignedPicture` WHERE orderid = ? ORDER BY upload_date DESC LIMIT 1";
$pic_stmt = mysqli_prepare($mysqli, $pic_sql);
mysqli_stmt_bind_param($pic_stmt, "i", $orderid);
mysqli_stmt_execute($pic_stmt);
$pic_result = mysqli_stmt_get_result($pic_stmt);
$latest_picture = mysqli_fetch_assoc($pic_result);

$design_price = isset($order["design_price"]) ? floatval($order["design_price"]) : 0;
$deposit = isset($order['deposit']) ? floatval($order['deposit']) : 2000.0;
$references_total = 0.0;
if (!empty($references)) {
    foreach ($references as $r) {
        $rprice = isset($r['price']) && $r['price'] !== null ? (float)$r['price'] : (float)($r['product_price'] ?? 0);
        $references_total += $rprice;
    }
}

$final_total_cost = $design_price + $total_fees + $references_total;

$status = strtolower($order['ostatus'] ?? 'waiting confirm');
// Handle client actions: confirm proposal or request revision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['client_confirm_proposal'])) {
        // Set to waiting client payment and redirect to order detail/payment page
        $next_status = 'waiting client payment';
        $u_sql = "UPDATE `Order` SET ostatus = ? WHERE orderid = ? AND clientid = ?";
        $u_stmt = mysqli_prepare($mysqli, $u_sql);
        mysqli_stmt_bind_param($u_stmt, "sii", $next_status, $orderid, $client_id);
        mysqli_stmt_execute($u_stmt);
        mysqli_stmt_close($u_stmt);
        header('Location: ../client/payment.php?orderid=' . $orderid);
        exit;
    }

    if (isset($_POST['client_request_revision'])) {
        $next_status = 'drafting 2nd proposal';
        $note = mysqli_real_escape_string($mysqli, $_POST['revision_note'] ?? 'Client requested revision');
        $u_sql = "UPDATE `Order` SET ostatus = ?, Requirements = CONCAT(Requirements, '\n\nCLIENT REQUEST: ', ?) WHERE orderid = ? AND clientid = ?";
        $u_stmt = mysqli_prepare($mysqli, $u_sql);
        mysqli_stmt_bind_param($u_stmt, "ssii", $next_status, $note, $orderid, $client_id);
        mysqli_stmt_execute($u_stmt);
        mysqli_stmt_close($u_stmt);
        header('Location: Order_View.php?id=' . $orderid);
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order #<?php echo $orderid; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <div class="container mb-5">
        <div class="page-title"><i class="fas fa-info-circle me-2"></i>Proposal Detail</div>

        <div class="alert <?php echo in_array($status, ['waiting confirm', 'waiting client review']) ? 'alert-warning' : 'alert-info'; ?> mb-4"
            role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Status:</strong> <?php echo htmlspecialchars($status); ?>
        </div>

        <div class="row mb-4">
            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-user me-2"></i>Customer</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><label class="fw-bold text-muted small">Client Name</label>
                            <p class="mb-0">
                                <strong><?php echo htmlspecialchars($order["client_name"] ?? 'N/A'); ?></strong></p>
                        </div>
                        <?php if (!empty($order["cemail"])): ?>
                            <div class="mb-3"><label class="fw-bold text-muted small">Email</label>
                                <p class="mb-0"><small><?php echo htmlspecialchars($order["cemail"]); ?></small></p>
                            </div><?php endif; ?>
                        <?php if (!empty($order["ctel"])): ?>
                            <div class="mb-3"><label class="fw-bold text-muted small">Phone</label>
                                <p class="mb-0"><small><?php echo htmlspecialchars($order["ctel"]); ?></small></p>
                            </div><?php endif; ?>
                        <hr>
                        <div class="mb-0"><label class="fw-bold text-muted small">Budget</label>
                            <p class="mb-0"><strong
                                    class="text-success fs-5">HK$<?php echo number_format($order["budget"], 2); ?></strong>
                            </p>
                        </div>
                        <div class="mb-0"><label class="fw-bold text-muted small">Deposit</label>
                            <p class="mb-0"><strong class="text-warning">HK$<?php echo number_format($deposit, 2); ?></strong></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-pencil-alt me-2"></i>Design</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><label class="fw-bold text-muted small">Design ID</label>
                            <p class="mb-0"><small>#<?php echo htmlspecialchars($order["designid"] ?? 'N/A'); ?></small>
                            </p>
                        </div>
                        <div class="mb-3"><label class="fw-bold text-muted small">Design Name</label>
                            <p class="mb-0">
                                <small><?php echo htmlspecialchars($order["designName"] ?? 'N/A'); ?></small></p>
                        </div>
                        <div class="mb-3"><label class="fw-bold text-muted small">Expected Price</label>
                            <p class="mb-0"><strong
                                    class="text-info">HK$<?php echo number_format($order["design_price"] ?? 0, 0); ?></strong>
                            </p>
                        </div>
                        <hr>
                        <div class="mb-0"><label class="fw-bold text-muted small">Design Tag</label>
                            <p class="mb-0"><small
                                    class="text-muted"><?php echo htmlspecialchars($order["design_tag"] ?? 'N/A'); ?></small>
                            </p>
                        </div>

                        <?php if (!empty($references)): ?>
                            <hr>
                            <div class="fw-bold text-muted small mb-2">Product References</div>
                            <?php
                            $grouped_refs = [];
                            foreach ($references as $ref) {
                                $grouped_refs[$ref['category']][] = $ref;
                            }
                            foreach ($grouped_refs as $category => $items): ?>
                                <div class="mb-3">
                                    <div class="mb-1"><span
                                            class="badge bg-secondary"><?php echo htmlspecialchars($category); ?></span></div>
                                    <ul class="list-unstyled ps-2 mb-0 border-start border-2 border-light">
                                        <?php foreach ($items as $ref): ?>
                                            <li class="d-flex justify-content-between align-items-center mb-1 ps-2">
                                                <small class="text-truncate" style="max-width: 60%;"
                                                    title="<?php echo htmlspecialchars($ref['pname']); ?>"><?php echo htmlspecialchars($ref['pname']); ?></small>
                                                <small
                                                    class="text-success fw-bold">HK$<?php echo number_format($ref['product_price'], 0); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="fw-bold text-muted small mb-2">Design proposal</div>
                        <?php if ($latest_picture): ?>
                            <button type="button" class="btn btn-outline-primary"
                                onclick="openProposalPreview('../uploads/designed_Picture/<?php echo htmlspecialchars($latest_picture['filename']); ?>')">
                                <i class="fas fa-file-image me-1"></i>Preview
                            </button>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-clipboard me-2"></i>Order</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><label class="fw-bold text-muted small">Order ID</label>
                            <p class="mb-0"><small>#<?php echo htmlspecialchars($order["orderid"]); ?></small></p>
                        </div>
                        <div class="mb-3"><label class="fw-bold text-muted small">Order Date</label>
                            <p class="mb-0"><small><?php echo date('M d, Y H:i', strtotime($order["odate"])); ?></small>
                            </p>
                        </div>
                        <div class="mb-3"><label class="fw-bold text-muted small">Status</label>
                            <p class="mb-0"><span
                                    class="badge <?php echo ($status === 'complete') ? 'bg-success' : (($status === 'reject') ? 'bg-danger' : 'bg-info'); ?>"><?php echo htmlspecialchars($status); ?></span>
                            </p>
                        </div>
                        <div class="text-muted mb-0 small">Requirements</div>
                        <?php echo nl2br(htmlspecialchars($order["Requirements"] ?? 'No requirements specified')); ?>
                        <hr>
                        <div class="mb-0">
                            <label class="fw-bold text-muted small">Cost Breakdown</label>
                            <div class="mb-2">
                                <ul class="list-unstyled mb-0">
                                    <li class="d-flex justify-content-between">
                                        <small class="text-muted">Design Price</small>
                                        <strong>HK$<?php echo number_format($design_price, 2); ?></strong>
                                    </li>
                                    <?php if (!empty($references)): ?>
                                        <li class="mt-2"><small class="text-muted">Product References</small></li>
                                        <?php foreach ($references as $r):
                                            $rprice = isset($r['price']) && $r['price'] !== null ? (float)$r['price'] : (float)($r['product_price'] ?? 0);
                                        ?>
                                            <li class="d-flex justify-content-between ps-3">
                                                <small><?php echo htmlspecialchars($r['pname'] ?? ('Product #' . $r['productid'])); ?></small>
                                                <small class="text-success">HK$<?php echo number_format($rprice, 2); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($fees)): ?>
                                        <li class="mt-2"><small class="text-muted">Additional Fees</small></li>
                                        <?php foreach ($fees as $f): ?>
                                            <li class="d-flex justify-content-between ps-3">
                                                <small><?php echo htmlspecialchars($f['fee_name']); ?></small>
                                                <small class="text-success">HK$<?php echo number_format((float)$f['amount'], 2); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <label class="fw-bold text-muted small">Total Cost</label>
                            <p class="mb-0"><strong class="text-danger fs-5">HK$<?php echo number_format($final_total_cost, 2); ?></strong></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($references)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-tags me-2"></i>Product Reference Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead class="table">
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                            <th>Requested Price</th>
                                            <th>Note</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($references as $ref):
                                            $refStatus = strtolower(trim($ref['status'] ?? 'pending'));
                                            $displayPrice = isset($ref['price']) && $ref['price'] !== null ? (float) $ref['price'] : (float) ($ref['product_price'] ?? 0);
                                            $badgeClass = 'bg-secondary';
                                            if (in_array($refStatus, ['waiting confirm', 'pending']))
                                                $badgeClass = 'bg-warning';
                                            if (in_array($refStatus, ['confirmed', 'approved']))
                                                $badgeClass = 'bg-success';
                                            if (in_array($refStatus, ['rejected', 'reject']))
                                                $badgeClass = 'bg-danger';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ref['pname'] ?? ('Product #' . $ref['productid'])); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($ref['category'] ?? '—'); ?></td>
                                                <td><span
                                                        class="badge <?php echo $badgeClass; ?>"><?php echo $refStatus === 'waiting confirm' ? 'Request Confirm' : htmlspecialchars($refStatus); ?></span>
                                                </td>
                                                <td>HK$<?php echo number_format($displayPrice, 2); ?></td>
                                                <td><?php echo htmlspecialchars($ref['note'] ?? '—'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Proposal Preview Modal (view-only) -->
        <div class="modal fade" id="proposalPreviewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Design Proposal Preview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="proposalPreviewImageWrap" class="text-center" style="display:none;">
                            <img id="proposalPreviewImage" src="" alt="Design Proposal"
                                style="max-width:100%;max-height:70vh;border-radius:8px;" />
                        </div>
                        <div id="proposalPreviewPdfWrap" style="display:none;">
                            <iframe id="proposalPreviewPdf" src="" style="width:100%;height:70vh;border:0;"></iframe>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($status === 'waiting client review'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="mb-2">Proposal Ready for Your Review</h5>
                            <p class="text-muted">Please preview the proposal. Confirm to proceed to payment or request a
                                revision.</p>
                            <div class="mt-3 d-flex justify-content-center gap-2">
                                <form method="post"
                                    onsubmit="return confirm('Confirm this proposal and proceed to payment?');"
                                    style="display:inline;">
                                    <input type="hidden" name="client_confirm_proposal" value="1">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check me-1"></i>Confirm Proposal
                                    </button>
                                </form>

                                <button class="btn btn-warning" data-bs-toggle="collapse" data-bs-target="#revisionBox">
                                    <i class="fas fa-edit me-1"></i>Request Revision
                                </button>
                            </div>

                            <div class="collapse mt-3" id="revisionBox">
                                <form method="post">
                                    <input type="hidden" name="client_request_revision" value="1">
                                    <div class="mb-2">
                                        <textarea name="revision_note" class="form-control"
                                            placeholder="Describe requested changes" required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Send Revision Request</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'waiting client payment'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="mb-2">Proceed to Payment</h5>
                            <p class="text-muted">Your proposal has been confirmed. Please proceed to complete the payment to start the work.</p>
                            <div class="mt-3">
                                <a href="payment.php?orderid=<?php echo $orderid; ?>" class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>Proceed to Payment
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="../client/order_history.php" class="btn btn-secondary"><i
                    class="fas fa-arrow-left me-2"></i>Back</a>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openProposalPreview(fileUrl) {
            const imgWrap = document.getElementById('proposalPreviewImageWrap');
            const pdfWrap = document.getElementById('proposalPreviewPdfWrap');
            const img = document.getElementById('proposalPreviewImage');
            const pdf = document.getElementById('proposalPreviewPdf');
            if (!imgWrap || !pdfWrap || !img || !pdf) return;

            const isPdf = fileUrl.toLowerCase().endsWith('.pdf');
            if (isPdf) {
                imgWrap.style.display = 'none';
                pdfWrap.style.display = 'block';
                pdf.src = fileUrl;
                img.src = '';
            } else {
                pdfWrap.style.display = 'none';
                imgWrap.style.display = 'block';
                img.src = fileUrl;
                pdf.src = '';
            }

            const modalEl = document.getElementById('proposalPreviewModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }
    </script>

    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if (isset($result))
    mysqli_free_result($result);
if (isset($ref_result))
    mysqli_free_result($ref_result);
mysqli_close($mysqli);
?>