<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

// Check if user is logged in as manager
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['managerid'];
$user_name = $user['name'];

// Get status filter parameter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($mysqli, $_GET['search']) : '';

// Build query conditions
$where_conditions = array();

if ($status_filter != 'all') {
    $status_filter = mysqli_real_escape_string($mysqli, $status_filter);
    $where_conditions[] = "o.ostatus = '$status_filter'";
}

if (!empty($search)) {
    $search_conditions = array();
    $search_conditions[] = "o.orderid LIKE '%$search%'";
    $search_conditions[] = "c.cname LIKE '%$search%'";
    $search_conditions[] = "d.tag LIKE '%$search%'";
    $search_conditions[] = "o.Requirements LIKE '%$search%'";
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build SQL query - FIXED: Added Designer filter to show only designs linked to this manager
$sql = "SELECT DISTINCT o.orderid, o.odate, o.Requirements, o.ostatus, o.deposit,
           c.clientid, c.cname as client_name, c.budget,
           d.designid, d.expect_price as design_price, d.tag as design_tag,
           des.dname AS designer_name,
           s.OrderFinishDate, s.DesignFinishDate
        FROM `Order` o
        LEFT JOIN `Client` c ON o.clientid = c.clientid
        LEFT JOIN `Design` d ON o.designid = d.designid
        INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id
        LEFT JOIN `Schedule` s ON o.orderid = s.orderid       
        $where_clause
        ORDER BY o.odate DESC";

$result = mysqli_query($mysqli, $sql);

// Get total count - FIXED: Added Designer filter
$count_sql = "SELECT COUNT(DISTINCT o.orderid) as total 
              FROM `Order` o
              LEFT JOIN `Design` d ON o.designid = d.designid
              INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id";
$count_result = mysqli_query($mysqli, $count_sql);
$count_row = mysqli_fetch_assoc($count_result);
$total_orders = $count_row['total'];

// Get statistics - FIXED: Added Designer filter
$stats_sql = "SELECT 
                COUNT(DISTINCT o.orderid) as total_orders,
                SUM(c.budget) as total_budget,
                AVG(c.budget) as avg_budget,
                SUM(CASE WHEN o.ostatus = 'waiting confirm' THEN 1 ELSE 0 END) as pending_count,
                 SUM(CASE WHEN o.ostatus IN ('designing', 'drafting 2nd proposal', 'reviewing design proposal') THEN 1 ELSE 0 END) as designing_count,
                SUM(CASE WHEN o.ostatus = 'complete' THEN 1 ELSE 0 END) as completed_count
               FROM `Order` o
              LEFT JOIN `Client` c ON o.clientid = c.clientid
              LEFT JOIN `Design` d ON o.designid = d.designid
              INNER JOIN `Designer` des ON d.designerid = des.designerid AND des.managerid = $user_id";
$stats_result = mysqli_query($mysqli, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css">
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container-lg mt-4">

        <!-- Search Card -->
        <div class="bg-white rounded d-flex justify-content-between align-items-center flex-column">
            <!-- Page Title -->
            <div class="page-title">
                Order Manager
            </div>
            <div class="card-body">
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search orders by ID, client name, or tags..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    <?php endif; ?>
                </form>
                <div class="d-flex justify-content-start mt-3 flex-wrap gap-2">
                    <a href="?status=all"
                        class="btn btn-sm <?php echo $status_filter == 'all' ? 'btn-active' : 'btn'; ?>">
                        All
                    </a>
                    <a href="?status=waiting confirm"
                        class="btn btn-sm <?php echo $status_filter == 'waiting confirm' ? 'btn-active' : 'btn'; ?>">
                        Pending
                    </a>
                    <a href="?status=designing"
                        class="btn btn-sm <?php echo $status_filter == 'designing' ? 'btn-active' : 'btn'; ?>">
                        Designing
                    </a>
                    <a href="?status=drafting 2nd proposal"
                        class="btn btn-sm <?php echo $status_filter == 'drafting 2nd proposal' ? 'btn-active ' : 'btn'; ?>">
                        Drafting
                    </a>
                    <a href="?status=reviewing design proposal"
                        class="btn btn-sm <?php echo $status_filter == 'reviewing design proposal' ? 'btn-active' : 'btn'; ?>">
                        Reviewing
                    </a>
                    <a href="?status=waiting client review"
                        class="btn btn-sm <?php echo $status_filter == 'waiting client review' ? 'btn-active' : 'btn'; ?>">
                        Wait Review
                    </a>
                    <a href="?status=waiting payment"
                        class="btn btn-sm <?php echo $status_filter == 'waiting payment' ? 'btn-active' : 'btn'; ?>">
                        Wait Pay
                    </a>
                    <a href="?status=complete"
                        class="btn btn-sm <?php echo $status_filter == 'complete' ? 'btn-active' : 'btn'; ?>">
                        Completed
                    </a>
                    <a href="?status=reject"
                        class="btn btn-sm <?php echo $status_filter == 'reject' ? 'btn-active' : 'btn'; ?>">
                        Rejected
                    </a>
                    <div class="ms-auto"> Total: <?php echo $total_orders; ?>
                        <button onclick="printPage()" class="btn btn-sm btn-outline-dark ms-2">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Client</th>
                            <th>Designer</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($result)):
                            // Determine status class
                            $status_class = '';
                            switch ($row["ostatus"]) {
                                case 'complete':
                                    $status_class = 'status-completed';
                                    break;
                                case 'designing':
                                case 'drafting 2nd proposal':
                                case 'reviewing design proposal':
                                    $status_class = 'status-designing';
                                    break;
                                case 'waiting confirm':
                                case 'waiting client review':
                                case 'waiting payment':
                                    $status_class = 'status-pending';
                                    break;
                                case 'reject':
                                    $status_class = 'bg-danger text-white';
                                    break;
                                default:
                                    $status_class = 'status-pending';
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo htmlspecialchars($row["orderid"]); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row["client_name"] ?? 'N/A'); ?></strong>
                                    <br>
                                    <small class="text-muted">ID:
                                        <?php echo htmlspecialchars($row["clientid"] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row["designer_name"] ?? 'N/A'); ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($row["ostatus"]); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="Order_Edit.php?id=<?php echo $row["orderid"]; ?>"
                                        class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit me-1"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewOrder(orderId) {
            window.location.href = 'Order_Edit.php?id=' + encodeURIComponent(orderId);
        }

        function exportToCSV() {
            const status = "<?php echo $status_filter; ?>";
            const search = "<?php echo urlencode($search); ?>";
            window.location.href = 'export_orders.php?status=' + status + '&search=' + search;
        }

        function printPage() {
            window.print();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function (e) {
                // Ctrl+F focus search box
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }

                // Ctrl+E export
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    exportToCSV();
                }

                // Ctrl+P print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printPage();
                }

                // Esc key to go back
                if (e.key === 'Escape') {
                    window.location.href = 'Manager_MyOrder.php';
                }
            });

            // Table row click effect
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function () {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                });

                row.addEventListener('mouseleave', function () {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });

            // Highlight search results
            const urlParams = new URLSearchParams(window.location.search);
            const searchTerm = urlParams.get('search');
            if (searchTerm) {
                highlightSearchTerm(searchTerm);
            }
        });

        function highlightSearchTerm(term) {
            const table = document.querySelector('.table');
            if (!table || !term) return;

            const regex = new RegExp(`(${term})`, 'gi');
            const walker = document.createTreeWalker(
                table,
                NodeFilter.SHOW_TEXT,
                null,
                false
            );

            let node;
            const textNodes = [];
            while (node = walker.nextNode()) {
                textNodes.push(node);
            }

            textNodes.forEach(node => {
                if (node.parentNode.nodeName !== 'SCRIPT' && node.parentNode.nodeName !== 'STYLE' &&
                    node.parentNode.className !== 'btn-group') {
                    const newHTML = node.textContent.replace(regex, '<mark class="highlight">$1</mark>');
                    if (newHTML !== node.textContent) {
                        const span = document.createElement('span');
                        span.innerHTML = newHTML;
                        node.parentNode.replaceChild(span, node);
                    }
                }
            });
        }
    </script>

    <!-- Include chat widget -->
    <?php include __DIR__ . '/../Public/chat_widget.php'; ?>
</body>

</html>

<?php
if (isset($result))
    mysqli_free_result($result);
if (isset($count_result))
    mysqli_free_result($count_result);
if (isset($stats_result))
    mysqli_free_result($stats_result);
mysqli_close($mysqli);
?>