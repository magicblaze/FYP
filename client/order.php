<?php
// ==============================
// File: order.php (layout updated to match Order.html design)
// ==============================
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user'])) {
    $redirect = 'order.php' . (isset($_GET['designid']) ? ('?designid=' . urlencode((string) $_GET['designid'])) : '');
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$designid = isset($_GET['designid']) ? (int) $_GET['designid'] : 0;
if ($designid <= 0) {
    http_response_code(404);
    die('Invalid design.');
}

$ds = $mysqli->prepare("SELECT d.designid, d.expect_price, d.designName, d.designerid, dz.dname, d.tag FROM Design d JOIN Designer dz ON d.designerid = dz.designerid WHERE d.designid=?");
$ds->bind_param("i", $designid);
$ds->execute();
$design = $ds->get_result()->fetch_assoc();
if (!$design) {
    http_response_code(404);
    die('Design not found.');
}

$clientId = (int) ($_SESSION['user']['clientid'] ?? 0);
if ($clientId <= 0) {
    http_response_code(403);
    die('Invalid session.');
}

// Fetch client details (phone, address, floor plan, budget, and payment method) from the Client table
$clientStmt = $mysqli->prepare("SELECT cname, ctel, cemail, address, floor_plan, budget, payment_method FROM Client WHERE clientid = ?");
$clientStmt->bind_param("i", $clientId);
$clientStmt->execute();
$clientData = $clientStmt->get_result()->fetch_assoc();

$success = '';
$error = '';
$references_total = 0.0;
$total_amount = (float) $design['expect_price'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support AJAX floor plan uploads: return JSON and exit
    if (!empty($_POST['ajax']) && $_POST['ajax'] === 'floor_upload') {
        $resp = ['success' => false, 'message' => 'No file uploaded.'];
        if (isset($_FILES['floor_plan_file'])) {
            $fp = $_FILES['floor_plan_file'];
            if ($fp['error'] === UPLOAD_ERR_OK && is_uploaded_file($fp['tmp_name'])) {
                $ext = strtolower(pathinfo($fp['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'png', 'jpg', 'jpeg'];
                if (!in_array($ext, $allowed)) {
                    $resp['message'] = 'Invalid file type.';
                } else {
                    $destDir = __DIR__ . '/../uploads/floor_plan/';
                    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                        $resp['message'] = 'Unable to create upload directory.';
                    } else {
                        $fname = 'floor_' . $clientId . '_' . time() . '.' . $ext;
                        $dst = $destDir . $fname;
                        if (move_uploaded_file($fp['tmp_name'], $dst)) {
                            $pathRel = 'uploads/floor_plan/' . $fname;
                            $upd = $mysqli->prepare('UPDATE Client SET floor_plan = ? WHERE clientid = ?');
                            if ($upd) {
                                $upd->bind_param('si', $pathRel, $clientId);
                                $upd->execute();
                                $upd->close();
                            }
                            $resp['success'] = true;
                            $resp['message'] = 'Uploaded';
                            $resp['path'] = $pathRel;
                            $clientData['floor_plan'] = $pathRel;
                        } else {
                            $resp['message'] = 'Failed to move uploaded file.';
                        }
                    }
                }
            } else {
                $resp['message'] = 'Upload error code: ' . ($fp['error'] ?? 'unknown');
            }
        }
        header('Content-Type: application/json');
        echo json_encode($resp);
        exit;
    }
    // Allow client to edit budget at order placement; fall back to profile or design expected price
    $budget = isset($_POST['budget']) ? (float) $_POST['budget'] : (float) ($clientData['budget'] ?? $design['expect_price']);
    // Gross Floor Area (GFA) for this order (m2) — optional
    $gfa = isset($_POST['gross_floor_area']) ? (float) $_POST['gross_floor_area'] : 0.0;
    $requirements = trim($_POST['requirements'] ?? '');

    // Parse payment method data from profile
    $paymentMethodData = [];
    if (!empty($clientData['payment_method'])) {
        $paymentMethodData = json_decode($clientData['payment_method'], true) ?? [];
    }

    // Handle updates to floor plan upload and payment method submitted on this order page.
    // If provided, save to Client profile so subsequent validation uses updated values.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // -- Floor plan upload --
        if (isset($_FILES['floor_plan_file'])) {
            $fp = $_FILES['floor_plan_file'];
            if ($fp['error'] === UPLOAD_ERR_NO_FILE) {
                // no file uploaded — ignore
            } elseif ($fp['error'] !== UPLOAD_ERR_OK) {
                // surface a helpful upload error
                $phpErr = $fp['error'];
                $msg = 'Floor plan upload failed.';
                if ($phpErr === UPLOAD_ERR_INI_SIZE || $phpErr === UPLOAD_ERR_FORM_SIZE)
                    $msg = 'Uploaded file is too large.';
                elseif ($phpErr === UPLOAD_ERR_PARTIAL)
                    $msg = 'File upload was interrupted.';
                elseif ($phpErr === UPLOAD_ERR_NO_TMP_DIR)
                    $msg = 'Server misconfiguration: missing temp folder.';
                elseif ($phpErr === UPLOAD_ERR_CANT_WRITE)
                    $msg = 'Server error writing uploaded file.';
                $error = $msg;
            } elseif (empty($error) && is_uploaded_file($fp['tmp_name'])) {
                $ext = strtolower(pathinfo($fp['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'png', 'jpg', 'jpeg'];
                if (!in_array($ext, $allowed)) {
                    $error = 'Invalid file type for floor plan. Allowed: PDF, PNG, JPG.';
                } else {
                    $destDir = __DIR__ . '/../uploads/floor_plan/';
                    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                        $error = 'Unable to create upload directory on server.';
                    } else {
                        $fname = 'floor_' . $clientId . '_' . time() . '.' . $ext;
                        $dst = $destDir . $fname;
                        if (move_uploaded_file($fp['tmp_name'], $dst)) {
                            $pathRel = 'uploads/floor_plan/' . $fname;
                            $upd = $mysqli->prepare('UPDATE Client SET floor_plan = ? WHERE clientid = ?');
                            if ($upd) {
                                $upd->bind_param('si', $pathRel, $clientId);
                                $upd->execute();
                                $upd->close();
                            }
                            $clientData['floor_plan'] = $pathRel;
                        } else {
                            $error = 'Failed to move uploaded floor plan.';
                        }
                    }
                }
            }
        }

        // -- Payment method update --
        if (!empty($_POST['payment_method'])) {
            $pm = trim((string) $_POST['payment_method']);
            $pmData = ['method' => $pm];
            if ($pm === 'alipay_hk') {
                $pmData['alipay_hk_email'] = trim((string) ($_POST['alipay_hk_email'] ?? ''));
                $pmData['alipay_hk_phone'] = trim((string) ($_POST['alipay_hk_phone'] ?? ''));
            } elseif ($pm === 'paypal') {
                $pmData['paypal_email'] = trim((string) ($_POST['paypal_email'] ?? ''));
            } elseif ($pm === 'fps') {
                $pmData['fps_id'] = trim((string) ($_POST['fps_id'] ?? ''));
                $pmData['fps_name'] = trim((string) ($_POST['fps_name'] ?? ''));
            }
            // Determine whether to persist payment details to profile. Default to true for backward compat.
            $savePayment = array_key_exists('save_payment_to_profile', $_POST) ? (bool) $_POST['save_payment_to_profile'] : true;
            if ($savePayment) {
                $pmJson = json_encode($pmData);
                $upd2 = $mysqli->prepare('UPDATE Client SET payment_method = ? WHERE clientid = ?');
                if ($upd2) {
                    $upd2->bind_param('si', $pmJson, $clientId);
                    $upd2->execute();
                    $upd2->close();
                }
            }
            // Use updated data for validation below regardless of persistence
            $paymentMethodData = $pmData;
        }
    }

    // Validate required fields (check updated values, not just profile)
    if (empty($clientData['floor_plan'])) {
        $error = 'Please upload a floor plan before placing an order.';
    } elseif ($budget <= 0) {
        $error = 'Budget must be greater than 0.';
    } elseif ($budget < (float) $design['expect_price']) {
        $error = 'Budget cannot be lower than the design cost (HK$' . number_format((float) $design['expect_price'], 0) . '). Please adjust your budget.';
    } elseif ($gfa <= 0) {
        $error = 'Please provide Gross Floor Area (m²) for this order.';
    } elseif (empty($paymentMethodData) || empty($paymentMethodData['method'])) {
        $error = 'Please select a payment method before placing an order.';
    } else {
        // Validate payment method details based on selected method
        $pm = $paymentMethodData['method'] ?? '';
        if ($pm === 'alipay_hk') {
            if (empty($paymentMethodData['alipay_hk_email']) && empty($paymentMethodData['alipay_hk_phone'])) {
                $error = 'Please provide AlipayHK email or phone number.';
            }
        } elseif ($pm === 'paypal') {
            if (empty($paymentMethodData['paypal_email'])) {
                $error = 'Please provide PayPal email address.';
            }
        } elseif ($pm === 'fps') {
            if (empty($paymentMethodData['fps_id'])) {
                $error = 'Please provide FPS ID.';
            }
        }
    }

    // Parse references (comma-separated design ids) and compute references total
    $references = [];
    if (empty($error) && !empty($_POST['references'])) {
        $raw = trim((string) $_POST['references']);
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($parts as $p) {
            if (ctype_digit($p))
                $references[] = (int) $p;
        }
        if (!empty($references)) {
            // Build placeholders and query sum
            $placeholders = implode(',', array_fill(0, count($references), '?'));
            $types = str_repeat('i', count($references));
            $sql = "SELECT SUM(expect_price) AS refs_sum FROM Design WHERE designid IN ($placeholders)";
            $stmtRefs = $mysqli->prepare($sql);
            if ($stmtRefs) {
                $stmtRefs->bind_param($types, ...$references);
                $stmtRefs->execute();
                $rrow = $stmtRefs->get_result()->fetch_assoc();
                $references_total = (float) ($rrow['refs_sum'] ?? 0.0);
                $stmtRefs->close();
            }
        }
    }

    // Parse product references (id[:qty] comma-separated) and compute total
    $product_refs = []; // map id => qty
    $products_total = 0.0;
    if (empty($error) && !empty($_POST['product_references'])) {
        $rawp = trim((string) $_POST['product_references']);
        $parts = array_filter(array_map('trim', explode(',', $rawp)));
        foreach ($parts as $entry) {
            if ($entry === '')
                continue;
            $bits = explode(':', $entry);
            if (!ctype_digit($bits[0]))
                continue;
            $id = (int) $bits[0];
            $qty = isset($bits[1]) && ctype_digit($bits[1]) ? (int) $bits[1] : 1;
            $product_refs[$id] = ($product_refs[$id] ?? 0) + $qty;
        }
        if (!empty($product_refs)) {
            $pids = array_keys($product_refs);
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $types = str_repeat('i', count($pids));
            $sql = "SELECT productid, IFNULL(price,0) AS price FROM Product WHERE productid IN ($placeholders)";
            $stmtP = $mysqli->prepare($sql);
            if ($stmtP) {
                $stmtP->bind_param($types, ...$pids);
                $stmtP->execute();
                $res = $stmtP->get_result();
                while ($r = $res->fetch_assoc()) {
                    $pid = (int) $r['productid'];
                    $price = (float) ($r['price'] ?? 0);
                    $qty = $product_refs[$pid] ?? 0;
                    $products_total += $price * $qty;
                }
                $stmtP->close();
            }
        }
    }

    // Calculate total (design + references + products)
    $total_amount = (float) $design['expect_price'] + $references_total + $products_total;

    if (!$error) {
        // Persist per-order budget; cost and gross_floor_area left NULL until later updates
        $stmt = $mysqli->prepare("INSERT INTO `Order` (odate, clientid, budget, cost, gross_floor_area, Requirements, designid, ostatus) VALUES (NOW(), ?, ?, NULL, ?, ?, ?, 'waiting confirm')");
        $stmt->bind_param("iddsi", $clientId, $budget, $gfa, $requirements, $designid);
        if ($stmt && $stmt->execute()) {
            $orderId = $stmt->insert_id;
            // If product references exist, insert them into OrderReference table so they persist with the order
            if (!empty($product_refs) && $orderId) {
                $insRef = $mysqli->prepare('INSERT INTO OrderReference (orderid, productid, added_by_type, added_by_id) VALUES (?,?,?,?)');
                if ($insRef) {
                    $addedByType = 'client';
                    $addedById = $clientId;
                    foreach ($product_refs as $pid => $qty) {
                        $insRef->bind_param('iisi', $orderId, $pid, $addedByType, $addedById);
                        $insRef->execute();
                    }
                    $insRef->close();
                }
            }
            $success = 'Order created successfully. Order ID: ' . $orderId;
            // Send order confirmation message to designer via chat (create/find private room)
            try {
                $designerId = isset($design['designerid']) ? (int) $design['designerid'] : 0;
                if ($designerId > 0) {
                    // For each order, create or reuse an order-specific group room (client + designer + manager)
                    $roomId = 0;
                    $roomname = sprintf('order-%d', $orderId);
                    $chkRoom = $mysqli->prepare("SELECT ChatRoomid FROM ChatRoom WHERE roomname = ? LIMIT 1");
                    if ($chkRoom) {
                        $chkRoom->bind_param('s', $roomname);
                        $chkRoom->execute();
                        $rr = $chkRoom->get_result();
                        $existing = $rr ? $rr->fetch_assoc() : null;
                        $chkRoom->close();
                        if ($existing && !empty($existing['ChatRoomid'])) {
                            $roomId = (int) $existing['ChatRoomid'];
                        }
                    }
                    if (!$roomId) {
                        $insRoom = $mysqli->prepare("INSERT INTO ChatRoom (roomname,description,room_type,created_by_type,created_by_id) VALUES (?,?,?,?,?)");
                        if ($insRoom) {
                            $desc = 'Order room for order #' . $orderId;
                            // room_type must match DB enum (private|group). Use 'group' for order rooms.
                            $room_type = 'group';
                            $created_by_type = 'client';
                            $insRoom->bind_param('ssssi', $roomname, $desc, $room_type, $created_by_type, $clientId);
                            $insRoom->execute();
                            $roomId = $insRoom->insert_id;
                            $insRoom->close();
                            // insert client and designer members (safe INSERT-IF-NOT-EXISTS)
                            $chkM = $mysqli->prepare("SELECT COUNT(*) FROM ChatRoomMember WHERE ChatRoomid=? AND member_type=? AND memberid=?");
                            $insM = $mysqli->prepare("INSERT INTO ChatRoomMember (ChatRoomid, member_type, memberid) VALUES (?,?,?)");
                            if ($chkM && $insM) {
                                $mt1 = 'client';
                                $mid1 = $clientId;
                                $chkM->bind_param('isi', $roomId, $mt1, $mid1);
                                $chkM->execute();
                                $cnt = $chkM->get_result()->fetch_row()[0] ?? 0;
                                if ((int)$cnt === 0) {
                                    $insM->bind_param('isi', $roomId, $mt1, $mid1);
                                    $insM->execute();
                                }
                                $mt2 = 'designer';
                                $mid2 = $designerId;
                                $chkM->bind_param('isi', $roomId, $mt2, $mid2);
                                $chkM->execute();
                                $cnt = $chkM->get_result()->fetch_row()[0] ?? 0;
                                if ((int)$cnt === 0) {
                                    $insM->bind_param('isi', $roomId, $mt2, $mid2);
                                    $insM->execute();
                                }
                                $chkM->close();
                                $insM->close();
                            }
                            // attempt to add the designer's manager as a member. If designer has no manager, fallback to first Manager.
                            $mgrId = 0;
                            $mgrStmt = $mysqli->prepare('SELECT managerid FROM Designer WHERE designerid = ? LIMIT 1');
                            if ($mgrStmt) {
                                $mgrStmt->bind_param('i', $designerId);
                                $mgrStmt->execute();
                                $mgrRow = $mgrStmt->get_result()->fetch_assoc();
                                $mgrStmt->close();
                                if ($mgrRow && !empty($mgrRow['managerid'])) {
                                    $mgrId = (int) $mgrRow['managerid'];
                                }
                            }
                            if (empty($mgrId)) {
                                $mgrQ = $mysqli->prepare('SELECT managerid FROM Manager ORDER BY managerid ASC LIMIT 1');
                                if ($mgrQ) {
                                    $mgrQ->execute();
                                    $mgrR = $mgrQ->get_result();
                                    $mgr = $mgrR ? $mgrR->fetch_assoc() : null;
                                    $mgrQ->close();
                                    if ($mgr && !empty($mgr['managerid'])) {
                                        $mgrId = (int) $mgr['managerid'];
                                    }
                                }
                            }
                            if (!empty($mgrId)) {
                                $chkMgr = $mysqli->prepare("SELECT COUNT(*) FROM ChatRoomMember WHERE ChatRoomid=? AND member_type=? AND memberid=?");
                                $insMgr = $mysqli->prepare("INSERT INTO ChatRoomMember (ChatRoomid, member_type, memberid) VALUES (?,?,?)");
                                if ($chkMgr && $insMgr) {
                                    $mtm = 'manager';
                                    $chkMgr->bind_param('isi', $roomId, $mtm, $mgrId);
                                    $chkMgr->execute();
                                    $cnt = $chkMgr->get_result()->fetch_row()[0] ?? 0;
                                    if ((int)$cnt === 0) {
                                        $insMgr->bind_param('isi', $roomId, $mtm, $mgrId);
                                        $insMgr->execute();
                                    }
                                    $chkMgr->close();
                                    $insMgr->close();
                                }
                            }
                        }
                    }

                    // Insert message announcing the order
                    if (!empty($roomId)) {
                        // Persist only the order id in `content` and set message_type = 'order'.
                        $orderContent = (string) $orderId;
                        $insMsg = $mysqli->prepare("INSERT INTO Message (sender_type, sender_id, content, message_type, ChatRoomid) VALUES (?,?,?,?,?)");
                        if ($insMsg) {
                            $stype = 'client';
                            $sId = $clientId;
                            $mtype = 'order';
                            // types: sender_type (s), sender_id (i), content (s), message_type (s), ChatRoomid (i)
                            $insMsg->bind_param('sissi', $stype, $sId, $orderContent, $mtype, $roomId);
                            $insMsg->execute();
                            $msgId = $insMsg->insert_id;
                            $insMsg->close();
                            if (!empty($msgId)) {
                                // create MessageRead rows: mark sender as read, others unread
                                $membersQ = $mysqli->prepare('SELECT ChatRoomMemberid, member_type, memberid FROM ChatRoomMember WHERE ChatRoomid = ?');
                                if ($membersQ) {
                                    $membersQ->bind_param('i', $roomId);
                                    $membersQ->execute();
                                    $mres = $membersQ->get_result();
                                    $insRead = $mysqli->prepare('INSERT INTO MessageRead (messageid, ChatRoomMemberid, is_read, read_at) VALUES (?,?,?,?)');
                                    while ($mr = $mres->fetch_assoc()) {
                                        $crmId = (int) $mr['ChatRoomMemberid'];
                                        if ($mr['member_type'] === 'client' && (int) $mr['memberid'] === $clientId) {
                                            $isr = 1;
                                            $rtime = date('Y-m-d H:i:s');
                                        } else {
                                            $isr = 0;
                                            $rtime = null;
                                        }
                                        if ($insRead) {
                                            $insRead->bind_param('iiis', $msgId, $crmId, $isr, $rtime);
                                            $insRead->execute();
                                        }
                                    }
                                    if ($insRead)
                                        $insRead->close();
                                    $membersQ->close();
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // non-fatal: order succeeded but chat notification failed
                error_log('[order.php] failed to send chat notification: ' . $e->getMessage());
            }
            // Redirect immediately to the order detail page to avoid timing issues
            header('Location: order_detail.php?orderid=' . $orderId);
            exit;
        } else {
            $error = 'Failed to create order: ' . $stmt->error;
        }
    }
}

$rawTags = (string) ($design['tag'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $rawTags)));
$designImgSrc = '../design_image.php?id=' . (int) $design['designid'];
// Try to use the primary DesignImage (if present) and map to uploads path
try {
    $imgStmt = $mysqli->prepare("SELECT image_filename FROM DesignImage WHERE designid = ? ORDER BY image_order ASC LIMIT 1");
    if ($imgStmt) {
        $imgStmt->bind_param('i', $designid);
        $imgStmt->execute();
        $imgRow = $imgStmt->get_result()->fetch_assoc();
        if ($imgRow && !empty($imgRow['image_filename'])) {
            $designImgSrc = '../uploads/designs/' . ltrim($imgRow['image_filename'], '/');
        }
        $imgStmt->close();
    }
} catch (Throwable $e) {
    // keep fallback to design_image.php if any error occurs
}

// Format phone number for display
$phoneDisplay = '—';
if (!empty($clientData['ctel'])) {
    $phoneDisplay = (string) $clientData['ctel'];
}

// Format budget display
$budgetDisplay = $clientData['budget'] ?? 0;

// Parse payment method data
$paymentMethodData = [];
if (!empty($clientData['payment_method'])) {
    $paymentMethodData = json_decode($clientData['payment_method'], true) ?? [];
}
$selectedPaymentMethod = $paymentMethodData['method'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyDesign - Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Floor plan preview styles */
        .floorplan-preview-container {
            margin-top: 1rem;
            display: none;
        }

        .floorplan-preview-container.show {
            display: block;
        }

        .floorplan-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            object-fit: contain;
        }

        .floorplan-file-info {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .floorplan-file-info .file-icon {
            font-size: 2rem;
            color: #e74c3c;
            margin-right: 1rem;
        }

        .floorplan-file-info .file-details {
            flex: 1;
        }

        .floorplan-file-info .file-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .floorplan-file-info .file-size {
            font-size: 0.85rem;
            color: #7f8c8d;
        }

        .remove-file-btn {
            background: none;
            border: none;
            color: #e74c3c;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.5rem;
        }

        .remove-file-btn:hover {
            color: #c0392b;
        }

        .floorplan-upload-area.has-file {
            border-color: #27ae60;
            background-color: #e8f8f0;
        }

        .file-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }

        /* Payment method display styles */
        .payment-method-display {
            background: #e8f5e9;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .payment-method-display .payment-method-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .payment-method-display .payment-method-value {
            color: #666;
            font-size: 0.95rem;
        }

        .payment-method-display .edit-link {
            display: inline-block;
            margin-top: 0.5rem;
        }

        .payment-method-display .edit-link a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .payment-method-display .edit-link a:hover {
            text-decoration: underline;
        }

        /* Liked product card selection */
        .liked-product-card {
            position: relative;
            cursor: pointer;
        }

        .liked-product-card.selected {
            outline: 2px solid rgba(13, 110, 253, 0.25);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.08);
        }
        /* Reference list in Order Summary */
        #referenceList { margin-top: 0.5rem; color: #263238; }
        #referenceList .ref-item { padding: 0.35rem 0; color: #546e7a; font-size: 0.95rem; display:flex; align-items:center; justify-content:space-between; }
        #referenceList .ref-item .ref-left { display:flex; align-items:center; }
        #referenceList .ref-item .ref-title { margin-right: .5rem; }
        #referenceList .ref-item .ref-qty { color: #607d8b; margin-right: .5rem; }
        #referenceList .ref-item .ref-price { color: #37474f; font-weight:600; }
        /* Place a single divider above the total */
        .summary-item.summary-total { padding-top: .75rem; border-top: 1px solid rgba(0,0,0,0.12); margin-top: .5rem; }
    </style>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <main class="container mt-4">
        <div class="order-container">
            <div class="mb-3">
                <button type="button" class="btn btn-light" onclick="history.back()" aria-label="Back">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </button>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form id="orderForm" method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-8">
                        <!-- Customer Information Section -->
                        <div class="order-section">
                            <h3 class="section-title">Customer Information</h3>
                            <div class="customer-info-card">
                                <div class="info-row">
                                    <div class="info-label">Name:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['cname'] ?? '—') ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Email:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['cemail'] ?? '—') ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Phone:</div>
                                    <div class="info-value"><?= htmlspecialchars($phoneDisplay) ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Address:</div>
                                    <div class="info-value"><?= htmlspecialchars($clientData['address'] ?? '—') ?></div>
                                </div>
                            </div>
                            <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> Please ensure your
                                detail is correct before proceeding payment. Visit your account settings to update your
                                information if there are any changes.</p>
                        </div>

                        <!-- Design Requirements Section -->
                        <div class="order-section">
                            <h3 class="section-title">Design Details</h3>
                            <!-- Design Information Section -->
                            <label class="form-label fw-bold mb-3">Reference Design:</label>
                            <div class="d-flex mb-4">
                                <div class="design-preview me-3" style="max-width: 200px;">
                                    <img src="<?= htmlspecialchars($designImgSrc) ?>" class="img-fluid"
                                        alt="Selected Design">
                                </div>
                                <i></i>
                                <div>
                                    <p class="text-muted mb-1">Designer:
                                        <?= htmlspecialchars($design['dname']) ?>
                                    </p>
                                    <div class="tags mb-2">
                                        <?php if (!empty($tags)): ?>
                                            <?php foreach ($tags as $tg): ?>
                                                <span class="badge bg-secondary me-1">
                                                    <?= htmlspecialchars($tg) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 mb-3">
                                <label class="form-label fw-bold">Order budget</label>
                                <?php $initialBudget = $budgetDisplay > 0 ? $budgetDisplay : (float) $design['expect_price']; ?>
                                <div id="budgetView" class="d-flex align-items-center">
                                    <div id="budgetDisplayText" class="me-2">
                                        HK$
                                        <?= number_format((float) $initialBudget, 0) ?>
                                    </div>
                                    <button type="button" id="budgetEditBtn" class="btn btn-sm btn-outline-secondary"
                                        title="Edit budget"><i class="fas fa-pencil-alt"></i></button>
                                </div>

                                <div id="budgetEdit" style="display:none; margin-top:.5rem;">
                                    <input type="number" step="1000" min="0" id="budgetInput" class="form-control"
                                        value="<?= htmlspecialchars($initialBudget) ?>">
                                    <div class="mt-2">
                                        <button type="button" id="budgetSaveBtn"
                                            class="btn btn-sm btn-primary">Save</button>
                                        <button type="button" id="budgetCancelBtn"
                                            class="btn btn-sm btn-secondary">Cancel</button>
                                    </div>
                                </div>

                                <!-- Hidden field that will be submitted as the budget value -->
                                <input type="hidden" id="budget" name="budget"
                                    value="<?= htmlspecialchars($initialBudget) ?>">

                                <small class="form-text text-muted mt-2"><i class="fas fa-info-circle me-1"></i>This is
                                    the budget that will be used for the order.</small>
                            </div>
                            <!-- Floor Plan Section-->
                            <label class="form-label fw-bold mb-3">Floor Plan</label>
                            <?php if (!empty($clientData['floor_plan'])): ?>
                                <div id="floorPlanView"
                                    style="background: #e8f8f0; border: 1px solid #27ae60; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-pdf"
                                                style="font-size: 1.5rem; color: #27ae60; margin-right: 0.5rem;"></i>
                                            <div>
                                                <strong
                                                    id="floorPlanFileName"><?= htmlspecialchars(basename($clientData['floor_plan'])) ?></strong>
                                                <br>
                                                <small class="text-muted">Floor plan on file</small>
                                            </div>
                                        </div>
                                        <div>
                                            <a href="../<?= htmlspecialchars($clientData['floor_plan']) ?>" target="_blank"
                                                class="btn btn-sm btn-outline-success me-2">
                                                <i class="fas fa-download me-1"></i>View
                                            </a>
                                            <button type="button" id="floorPlanEditBtn"
                                                class="btn btn-sm btn-outline-secondary"><i class="fas fa-pencil-alt"></i>
                                                Change</button>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div id="floorPlanView"
                                    class="alert alert-warning d-flex justify-content-between align-items-center"
                                    role="alert">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Please upload a floor plan</strong>
                                    </div>
                                    <div>
                                        <button type="button" id="floorPlanEditBtn"
                                            class="btn btn-sm btn-outline-secondary"><i
                                                class="fas fa-pencil-alt"></i></button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3" id="floorPlanEdit" style="display:none;">
                                <label for="floor_plan_file" class="form-label">Upload / Replace Floor Plan</label>
                                <input class="form-control" type="file" id="floor_plan_file" name="floor_plan_file"
                                    accept="application/pdf,image/*">
                                <div class="form-text">Accepted: PDF, PNG, JPG. Upload will update your profile floor
                                    plan.</div>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" id="floorPlanUploadBtn"
                                        class="btn btn-sm btn-primary">Upload</button>
                                    <button type="button" id="floorPlanCancelBtn"
                                        class="btn btn-sm btn-secondary">Cancel</button>
                                </div>
                            </div>
                            <small class="form-text text-muted mt-2 mb-3"><i class="fas fa-info-circle me-1"></i>Please
                                upload a floor plan which is required changes for the order.</small>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Gross Floor Area (m²)</label>
                                <?php $initialGfa = isset($clientData['gross_floor_area']) ? (float) $clientData['gross_floor_area'] : 0.0; ?>
                                <div id="gfaView" class="d-flex align-items-center">
                                    <div id="gfaDisplayText" class="me-2">
                                        <?= $initialGfa > 0 ? htmlspecialchars(number_format($initialGfa, 2)) . ' m²' : '<span class="text-muted">Not provided</span>' ?>
                                    </div>
                                    <button type="button" id="gfaEditBtn" class="btn btn-sm btn-outline-secondary"
                                        title="Edit GFA"><i class="fas fa-pencil-alt"></i></button>
                                </div>
                                <div id="gfaEdit" style="display:none; margin-top:.5rem;">
                                    <input type="number" step="0.01" min="0" id="gfaInput" class="form-control"
                                        value="<?= htmlspecialchars($initialGfa) ?>">
                                    <div class="mt-2">
                                        <button type="button" id="gfaSaveBtn"
                                            class="btn btn-sm btn-primary">Save</button>
                                        <button type="button" id="gfaCancelBtn"
                                            class="btn btn-sm btn-secondary">Cancel</button>
                                    </div>
                                </div>
                                <input type="hidden" id="gross_floor_area" name="gross_floor_area"
                                    value="<?= htmlspecialchars($initialGfa) ?>">
                                <small class="form-text text-muted mt-2">Please provide the gross floor area (m²) for
                                    the project.</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="requirements" class="form-label fw-bold">Request</label>
                            <textarea class="form-control" id="requirements" name="requirements" rows="4"
                                placeholder="Any specific requirements, preferences, or notes for the designer..."
                                maxlength="255"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-label fw-bold">References</div>
                            <div class="d-flex justify-content-between align-items-start mt-2">
                                <div id="selectedProducts" class="d-flex flex-wrap gap-2"></div>
                                <div class="ms-2">
                                    <button type="button" id="openLikedProducts"
                                        class="btn btn-sm btn-outline-secondary">+ reference</button>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">Reference(s) would help to calculate the total
                                cost for your order.</small>
                            <input type="hidden" id="product_references" name="product_references" value="">
                            <input type="hidden" id="references" name="references" value="">
                        </div>
                    </div>
                    <!-- Right Column - Order Summary -->
                    <div class="col-md-4">
                        <div class="order-summary">
                            <h3 class="section-title">Order Summary</h3>
                            <div class="summary-item">
                                <span>Designer cost:</span>
                                <span>HK$<?= number_format((float) $design['expect_price'], 0) ?></span>
                            </div>
                            <div id="referenceList" class="mt-3"></div>
                            <div class="mt-4">
                                <div class="summary-item summary-total">
                                    <span>Total:</span>
                                    <span id="orderTotal">HK$<?= number_format($total_amount, 0) ?></span>
                                </div>
                                <!-- Payment Method Section-->
                                <div class="order-section">
                                    <label class="form-label">Payment Method</label>
                                    <?php
                                    $pmCurrent = $paymentMethodData['method'] ?? ($selectedPaymentMethod ?? null);
                                    $paymentSummary = 'Not set';
                                    if ($pmCurrent === 'alipay_hk') {
                                        $paymentSummary = 'AlipayHK - ' . htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '');
                                    } elseif ($pmCurrent === 'paypal') {
                                        $paymentSummary = 'PayPal - ' . htmlspecialchars($paymentMethodData['paypal_email'] ?? '');
                                    } elseif ($pmCurrent === 'fps') {
                                        $paymentSummary = 'FPS - ' . htmlspecialchars($paymentMethodData['fps_id'] ?? '');
                                    }
                                    ?>

                                    <div id="paymentDisplay"
                                        class="payment-method-display d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="payment-method-label">Current: <?= $paymentSummary ?></div>
                                            <!-- Hidden fields to submit existing payment values when not editing -->
                                            <input type="hidden" name="payment_method" id="payment_method_hidden"
                                                value="<?= htmlspecialchars($pmCurrent ?? '') ?>">
                                            <input type="hidden" name="alipay_hk_email" id="alipay_hk_email_hidden"
                                                value="<?= htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '') ?>">
                                            <input type="hidden" name="alipay_hk_phone" id="alipay_hk_phone_hidden"
                                                value="<?= htmlspecialchars($paymentMethodData['alipay_hk_phone'] ?? '') ?>">
                                            <input type="hidden" name="paypal_email" id="paypal_email_hidden"
                                                value="<?= htmlspecialchars($paymentMethodData['paypal_email'] ?? '') ?>">
                                            <input type="hidden" name="fps_id" id="fps_id_hidden"
                                                value="<?= htmlspecialchars($paymentMethodData['fps_id'] ?? '') ?>">
                                            <input type="hidden" name="fps_name" id="fps_name_hidden"
                                                value="<?= htmlspecialchars($paymentMethodData['fps_name'] ?? '') ?>">

                                            <div id="paymentEdit" style="display:none;">
                                                <div class="mb-2">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio"
                                                            name="payment_method_edit" id="pm_alipay" value="alipay_hk"
                                                            <?= ($pmCurrent === 'alipay_hk') ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="pm_alipay">AlipayHK</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio"
                                                            name="payment_method_edit" id="pm_paypal" value="paypal"
                                                            <?= ($pmCurrent === 'paypal') ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="pm_paypal">PayPal</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio"
                                                            name="payment_method_edit" id="pm_fps" value="fps"
                                                            <?= ($pmCurrent === 'fps') ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="pm_fps">FPS</label>
                                                    </div>
                                                </div>

                                                <div id="alipayHKForm_edit"
                                                    style="display: <?= ($pmCurrent === 'alipay_hk') ? 'block' : 'none' ?>;">
                                                    <div class="mb-2">
                                                        <label class="form-label">AlipayHK Email</label>
                                                        <input id="alipayHKEmail" class="form-control"
                                                            value="<?= htmlspecialchars($paymentMethodData['alipay_hk_email'] ?? '') ?>">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">Phone</label>
                                                        <input id="alipayHKPhone" class="form-control"
                                                            value="<?= htmlspecialchars($paymentMethodData['alipay_hk_phone'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div id="paypalForm_edit"
                                                    style="display: <?= ($pmCurrent === 'paypal') ? 'block' : 'none' ?>;">
                                                    <div class="mb-2">
                                                        <label class="form-label">PayPal Email</label>
                                                        <input id="paypalEmail" class="form-control"
                                                            value="<?= htmlspecialchars($paymentMethodData['paypal_email'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div id="fpsForm_edit"
                                                    style="display: <?= ($pmCurrent === 'fps') ? 'block' : 'none' ?>;">
                                                    <div class="mb-2">
                                                        <label class="form-label">FPS ID</label>
                                                        <input id="fpsId" class="form-control"
                                                            value="<?= htmlspecialchars($paymentMethodData['fps_id'] ?? '') ?>">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label">FPS Name</label>
                                                        <input id="fpsName" class="form-control"
                                                            value="<?= htmlspecialchars($paymentMethodData['fps_name'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="mt-2 d-flex flex-column align-items-start gap-3">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" value="1"
                                                            id="savePaymentToProfile" name="save_payment_to_profile"
                                                            checked>
                                                        <label class="form-check-label" for="savePaymentToProfile">Set
                                                            as default payment method</label>
                                                    </div>
                                                    <div>
                                                        <button type="button" id="paymentSaveBtn"
                                                            class="btn btn-sm btn-primary">Save</button>
                                                        <button type="button" id="paymentCancelBtn"
                                                            class="btn btn-sm btn-secondary">Cancel</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <button type="button" id="paymentEditBtn"
                                                class="btn btn-sm btn-outline-secondary"><i
                                                    class="fas fa-pencil-alt"></i> Edit</button>
                                        </div>
                                    </div>


                                    <div class="form-text mt-2">Your information is securely stored and used for order
                                        only.</div>
                                </div>
                                <button type="submit" class="btn btn-success w-100 py-2">
                                    <i class="fas fa-check-circle me-2"></i>Place Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit toggles for budget, floor plan and payment method
        document.addEventListener('DOMContentLoaded', function () {
            // Budget toggle
            const budgetView = document.getElementById('budgetView');
            const budgetEdit = document.getElementById('budgetEdit');
            const budgetEditBtn = document.getElementById('budgetEditBtn');
            const budgetSaveBtn = document.getElementById('budgetSaveBtn');
            const budgetCancelBtn = document.getElementById('budgetCancelBtn');
            const budgetInput = document.getElementById('budgetInput');
            const budgetHidden = document.getElementById('budget');
            const budgetDisplayText = document.getElementById('budgetDisplayText');

            if (budgetEditBtn) {
                budgetEditBtn.addEventListener('click', function () {
                    budgetView.style.display = 'none';
                    budgetEdit.style.display = 'block';
                    budgetInput.focus();
                });
            }
            if (budgetSaveBtn) {
                budgetSaveBtn.addEventListener('click', function () {
                    const val = parseFloat(budgetInput.value) || 0;
                    const designCost = <?= json_encode((float) $design['expect_price']) ?>;
                    if (val <= 0) {
                        alert('Budget must be greater than 0.');
                        return;
                    }
                    if (val < designCost) {
                        alert('Budget cannot be lower than the design cost (HK$' + designCost.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ').');
                        return;
                    }
                    budgetHidden.value = val;
                    budgetDisplayText.textContent = 'HK$' + val.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                    budgetEdit.style.display = 'none';
                    budgetView.style.display = 'flex';
                });
            }
            if (budgetCancelBtn) {
                budgetCancelBtn.addEventListener('click', function () {
                    budgetInput.value = budgetHidden.value;
                    budgetEdit.style.display = 'none';
                    budgetView.style.display = 'flex';
                });
            }

            // Floor plan toggle
            const floorPlanEditBtn = document.getElementById('floorPlanEditBtn');
            const floorPlanEdit = document.getElementById('floorPlanEdit');
            const floorPlanView = document.getElementById('floorPlanView');
            const floorPlanCancelBtn = document.getElementById('floorPlanCancelBtn');
            if (floorPlanEditBtn) {
                floorPlanEditBtn.addEventListener('click', function () {
                    if (floorPlanEdit) floorPlanEdit.style.display = 'block';
                    if (floorPlanView) floorPlanView.style.display = 'none';
                });
            }
            if (floorPlanCancelBtn) {
                floorPlanCancelBtn.addEventListener('click', function () {
                    if (floorPlanEdit) floorPlanEdit.style.display = 'none';
                    if (floorPlanView) floorPlanView.style.display = '';
                });
            }

            // reflect chosen file name in UI when user selects a file
            const floorFileInput = document.getElementById('floor_plan_file');
            const floorFileNameEl = document.getElementById('floorPlanFileName');
            if (floorFileInput && floorFileNameEl) {
                floorFileInput.addEventListener('change', function () {
                    const f = (this.files && this.files[0]) ? this.files[0] : null;
                    if (f) floorFileNameEl.textContent = f.name;
                });
            }
            // AJAX upload button
            const floorPlanUploadBtn = document.getElementById('floorPlanUploadBtn');
            if (floorPlanUploadBtn && floorFileInput) {
                floorPlanUploadBtn.addEventListener('click', async function () {
                    const f = (floorFileInput.files && floorFileInput.files[0]) ? floorFileInput.files[0] : null;
                    if (!f) return alert('Please select a file to upload.');
                    const fd = new FormData();
                    fd.append('ajax', 'floor_upload');
                    fd.append('floor_plan_file', f);
                    try {
                        // include the current query string so server-side code receives required GET params (e.g. designid)
                        const res = await fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        const j = await res.json();
                        if (j.success) {
                            alert('Floor plan uploaded successfully.');
                            const fname = (j.path || '').split('/').pop();
                            const relPath = (j.path && j.path.charAt(0) === '/') ? j.path : ('../' + (j.path || ''));

                            // Rebuild the floorPlanView element with the uploaded file info
                            if (floorPlanView) {
                                // Remove all alert classes and rebuild as success state
                                floorPlanView.className = '';
                                floorPlanView.style.cssText = 'background: #e8f8f0; border: 1px solid #27ae60; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;';
                                
                                // Build the complete structure
                                floorPlanView.innerHTML = `
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-pdf" style="font-size: 1.5rem; color: #27ae60; margin-right: 0.5rem;"></i>
                                            <div>
                                                <strong id="floorPlanFileName">${fname}</strong>
                                                <br>
                                                <small class="text-muted">Floor plan on file</small>
                                            </div>
                                        </div>
                                        <div>
                                            <a href="${relPath}" target="_blank" class="btn btn-sm btn-outline-success me-2">
                                                <i class="fas fa-download me-1"></i>View
                                            </a>
                                            <button type="button" id="floorPlanEditBtn" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-pencil-alt"></i> Change
                                            </button>
                                        </div>
                                    </div>
                                `;

                                // Re-bind the edit button click handler
                                const editBtnLocal = floorPlanView.querySelector('#floorPlanEditBtn');
                                if (editBtnLocal && floorPlanEdit) {
                                    editBtnLocal.addEventListener('click', function () {
                                        floorPlanEdit.style.display = 'block';
                                        floorPlanView.style.display = 'none';
                                    });
                                }

                                // Show the view and hide the edit panel
                                floorPlanView.style.display = '';
                                if (floorPlanEdit) floorPlanEdit.style.display = 'none';
                            }
                        } else {
                            alert('Upload failed: ' + (j.message || 'Unknown'));
                        }
                    } catch (err) { alert('Upload failed (network error).'); }
                });
            }

            // GFA (Gross Floor Area) toggle
            const gfaView = document.getElementById('gfaView');
            const gfaEdit = document.getElementById('gfaEdit');
            const gfaEditBtn = document.getElementById('gfaEditBtn');
            const gfaSaveBtn = document.getElementById('gfaSaveBtn');
            const gfaCancelBtn = document.getElementById('gfaCancelBtn');
            const gfaInput = document.getElementById('gfaInput');
            const gfaHidden = document.getElementById('gross_floor_area');
            const gfaDisplayText = document.getElementById('gfaDisplayText');
            if (gfaEditBtn) {
                gfaEditBtn.addEventListener('click', function () {
                    gfaView.style.display = 'none';
                    gfaEdit.style.display = 'block';
                    gfaInput.focus();
                });
            }
            if (gfaSaveBtn) {
                gfaSaveBtn.addEventListener('click', function () {
                    const val = parseFloat(gfaInput.value) || 0;
                    if (val <= 0) {
                        alert('Gross Floor Area must be greater than 0.');
                        return;
                    }
                    gfaHidden.value = val;
                    gfaDisplayText.innerHTML = val > 0 ? val.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' m²' : '<span class="text-muted">Not provided</span>';
                    gfaEdit.style.display = 'none';
                    gfaView.style.display = 'flex';
                });
            }
            if (gfaCancelBtn) {
                gfaCancelBtn.addEventListener('click', function () {
                    gfaInput.value = gfaHidden.value;
                    gfaEdit.style.display = 'none';
                    gfaView.style.display = 'flex';
                });
            }

            // Payment method edit/display
            const paymentEditBtn = document.getElementById('paymentEditBtn');
            const paymentEdit = document.getElementById('paymentEdit');
            const paymentDisplay = document.getElementById('paymentDisplay');
            const paymentSaveBtn = document.getElementById('paymentSaveBtn');
            const paymentCancelBtn = document.getElementById('paymentCancelBtn');
            const paymentMethodHidden = document.getElementById('payment_method_hidden');

            function showPaymentEdit(show) {
                if (show) {
                    paymentDisplay.style.display = 'none';
                    paymentEdit.style.display = 'block';
                } else {
                    paymentEdit.style.display = 'none';
                    paymentDisplay.style.display = 'flex';
                }
            }

            if (paymentEditBtn) paymentEditBtn.addEventListener('click', function () { showPaymentEdit(true); });
            if (paymentCancelBtn) paymentCancelBtn.addEventListener('click', function () { showPaymentEdit(false); });

            // inside edit: handle radio switching
            const paymentEditRadios = document.querySelectorAll('input[name="payment_method_edit"]');
            function updatePaymentEditForms() {
                const sel = Array.from(paymentEditRadios).find(r => r.checked);
                const method = sel ? sel.value : '';
                const alipay = document.getElementById('alipayHKForm_edit');
                const paypal = document.getElementById('paypalForm_edit');
                const fps = document.getElementById('fpsForm_edit');
                if (alipay) alipay.style.display = method === 'alipay_hk' ? 'block' : 'none';
                if (paypal) paypal.style.display = method === 'paypal' ? 'block' : 'none';
                if (fps) fps.style.display = method === 'fps' ? 'block' : 'none';
            }
            paymentEditRadios.forEach(r => r.addEventListener('change', updatePaymentEditForms));

            if (paymentSaveBtn) {
                paymentSaveBtn.addEventListener('click', function () {
                    const sel = Array.from(paymentEditRadios).find(r => r.checked);
                    const method = sel ? sel.value : '';
                    if (!method) {
                        alert('Please select a payment method.');
                        return;
                    }
                    // Validate method-specific required fields
                    if (method === 'alipay_hk') {
                        const email = document.getElementById('alipayHKEmail') ? document.getElementById('alipayHKEmail').value.trim() : '';
                        const phone = document.getElementById('alipayHKPhone') ? document.getElementById('alipayHKPhone').value.trim() : '';
                        if (!email && !phone) {
                            alert('Please provide AlipayHK email or phone number.');
                            return;
                        }
                    } else if (method === 'paypal') {
                        const email = document.getElementById('paypalEmail') ? document.getElementById('paypalEmail').value.trim() : '';
                        if (!email) {
                            alert('Please provide PayPal email address.');
                            return;
                        }
                    } else if (method === 'fps') {
                        const fpsId = document.getElementById('fpsId') ? document.getElementById('fpsId').value.trim() : '';
                        if (!fpsId) {
                            alert('Please provide FPS ID.');
                            return;
                        }
                    }
                    
                    paymentMethodHidden.value = method;
                    // copy detail fields into hidden inputs
                    const alipayEmail = document.getElementById('alipayHKEmail') ? document.getElementById('alipayHKEmail').value : '';
                    const alipayPhone = document.getElementById('alipayHKPhone') ? document.getElementById('alipayHKPhone').value : '';
                    const paypalEmail = document.getElementById('paypalEmail') ? document.getElementById('paypalEmail').value : '';
                    const fpsId = document.getElementById('fpsId') ? document.getElementById('fpsId').value : '';
                    const fpsName = document.getElementById('fpsName') ? document.getElementById('fpsName').value : '';
                    document.getElementById('alipay_hk_email_hidden').value = alipayEmail;
                    document.getElementById('alipay_hk_phone_hidden').value = alipayPhone;
                    document.getElementById('paypal_email_hidden').value = paypalEmail;
                    document.getElementById('fps_id_hidden').value = fpsId;
                    document.getElementById('fps_name_hidden').value = fpsName;
                    // update display summary
                    const summaryEl = paymentDisplay.querySelector('.payment-method-label');
                    if (summaryEl) {
                        let text = 'Current: Not set';
                        if (method === 'alipay_hk') text = 'Current: AlipayHK' + (alipayEmail ? ' - ' + alipayEmail : (alipayPhone ? ' - ' + alipayPhone : ''));
                        else if (method === 'paypal') text = 'Current: PayPal - ' + (paypalEmail || '');
                        else if (method === 'fps') text = 'Current: FPS - ' + (fpsId || '');
                        summaryEl.innerHTML = text;
                    }
                    showPaymentEdit(false);
                });
            }

            // Ensure hidden fields are updated before any submit (capture phase)
            const orderForm = document.getElementById('orderForm');
            if (orderForm) {
                // clear previous validation flag at submit start
                orderForm.addEventListener('submit', function (e) {
                    try { orderForm.dataset.hasValidationError = '0'; } catch (ex) { /* ignore */ }
                    // budget
                    if (budgetEdit.style.display !== 'none') {
                        budgetHidden.value = parseFloat(budgetInput.value) || 0;
                    }
                    // gfa
                    if (typeof gfaEdit !== 'undefined' && gfaEdit && gfaEdit.style.display !== 'none') {
                        gfaHidden.value = parseFloat(gfaInput.value) || 0;
                    }
                    // payment: if the edit form is visible, copy values
                    if (paymentEdit && paymentEdit.style.display !== 'none') {
                        const sel = Array.from(paymentEditRadios).find(r => r.checked);
                        const method = sel ? sel.value : '';
                        paymentMethodHidden.value = method;
                        if (document.getElementById('alipayHKEmail')) document.getElementById('alipay_hk_email_hidden').value = document.getElementById('alipayHKEmail').value || '';
                        if (document.getElementById('alipayHKPhone')) document.getElementById('alipay_hk_phone_hidden').value = document.getElementById('alipayHKPhone').value || '';
                        if (document.getElementById('paypalEmail')) document.getElementById('paypal_email_hidden').value = document.getElementById('paypalEmail').value || '';
                        if (document.getElementById('fpsId')) document.getElementById('fps_id_hidden').value = document.getElementById('fpsId').value || '';
                        if (document.getElementById('fpsName')) document.getElementById('fps_name_hidden').value = document.getElementById('fpsName').value || '';
                    }

                    // Validate budget
                    const budgetVal = parseFloat(budgetHidden.value) || 0;
                    const designCost = <?= json_encode((float) $design['expect_price']) ?>;
                    if (budgetVal <= 0) {
                        e.preventDefault();
                        try { orderForm.dataset.hasValidationError = '1'; } catch (ex) { /* ignore */ }
                        alert('Budget must be greater than 0. Please set your budget.');
                        if (budgetView) budgetView.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }
                    if (budgetVal < designCost) {
                        e.preventDefault();
                        try { orderForm.dataset.hasValidationError = '1'; } catch (ex) { /* ignore */ }
                        alert('Budget cannot be lower than the design cost (HK$' + designCost.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",") + '). Please adjust your budget.');
                        if (budgetView) budgetView.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }

                    // Validate Gross Floor Area is provided
                    try {
                        const gfaVal = (gfaHidden && gfaHidden.value) ? parseFloat(gfaHidden.value) : 0;
                        if (!gfaVal || gfaVal <= 0) {
                            e.preventDefault();
                            try { orderForm.dataset.hasValidationError = '1'; } catch (ex) { /* ignore */ }
                            alert('Please provide Gross Floor Area (m²) before placing the order.');
                            if (typeof gfaEdit !== 'undefined' && gfaEdit) {
                                if (gfaView) gfaView.style.display = 'none';
                                gfaEdit.style.display = 'block';
                                if (gfaInput) gfaInput.focus();
                            }
                            return false;
                        }
                    } catch (ex) {
                        // fall through — allow server to validate
                    }

                    // Validate payment method
                    const pmVal = paymentMethodHidden ? paymentMethodHidden.value : '';
                    if (!pmVal) {
                        e.preventDefault();
                        try { orderForm.dataset.hasValidationError = '1'; } catch (ex) { /* ignore */ }
                        alert('Please select a payment method before placing the order.');
                        if (paymentDisplay) paymentDisplay.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        showPaymentEdit(true);
                        return false;
                    }
                }, true);
            }
        });
    </script>
    <!-- Terms of Use Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="termsModalLabel">Terms of Use</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <iframe src="../terms.php" title="Terms of Use"
                        style="border:0;width:100%;height:60vh;display:block;" loading="lazy"></iframe>
                    <div class="p-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="" id="termsAgree">
                            <label class="form-check-label" for="termsAgree">
                                I have read and agree to the Terms of Use
                            </label>
                        </div>
                        <p class="small text-muted mt-2">You can also open the full terms in a new tab: <a
                                href="../terms.php" target="_blank">Terms of Use</a>.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="termsAcceptBtn" disabled>Agree & Place
                        Order</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const orderForm = document.getElementById('orderForm');
            if (!orderForm) return;
            const termsModalEl = document.getElementById('termsModal');
            const termsModal = termsModalEl ? new bootstrap.Modal(termsModalEl) : null;
            const agreeCheckbox = document.getElementById('termsAgree');
            const agreeBtn = document.getElementById('termsAcceptBtn');

            orderForm.addEventListener('submit', function (e) {
                // If earlier validation prevented submission, don't open the Terms modal
                if (orderForm.dataset && orderForm.dataset.hasValidationError === '1') {
                    return; // earlier handler already handled the alert/prevent
                }
                if (!window.__termsAccepted) {
                    e.preventDefault();
                    if (termsModal) termsModal.show();
                }
            });

            if (agreeCheckbox && agreeBtn) {
                agreeCheckbox.addEventListener('change', function () {
                    agreeBtn.disabled = !this.checked;
                });
                agreeBtn.addEventListener('click', function () {
                    window.__termsAccepted = true;
                    if (termsModal) termsModal.hide();
                    orderForm.submit();
                });
            }
        });
    </script>
    <!-- Liked Products Modal -->
    <div class="modal fade" id="likedProductsModal" tabindex="-1" aria-labelledby="likedProductsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="likedProductsModalLabel">Select reference</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="likedProductsGrid" class="row gy-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="likedProductsConfirm" class="btn btn-primary">Add Selected</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const openBtn = document.getElementById('openLikedProducts');
            const modalEl = document.getElementById('likedProductsModal');
            const likedGrid = document.getElementById('likedProductsGrid');
            const confirmBtn = document.getElementById('likedProductsConfirm');
            const prodHidden = document.getElementById('product_references');
            const selectedContainer = document.getElementById('selectedProducts');
            let likedProducts = [];
            const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;

            async function loadLiked() {
                try {
                    const res = await fetch('../Public/get_chat_suggestions.php');
                    const j = await res.json();
                    likedProducts = (j && j.liked_products) ? j.liked_products : [];
                    likedGrid.innerHTML = '';
                    if (likedProducts.length === 0) {
                        likedGrid.innerHTML = '<div class="col-12 text-center py-5"><div class="text-muted"><i class="bi bi-heart" style="font-size: 3rem; opacity: 0.3;"></i><p class="mt-3 mb-1">No liked products yet</p><p class="small text-muted">Like some products to add them as references</p></div></div>';
                        return;
                    }
                    likedProducts.forEach(p => {
                        const col = document.createElement('div'); col.className = 'col-6 col-md-4';
                        col.innerHTML = `
                            <div class="card h-100 liked-product-card" data-id="${p.productid}">
                                <div class="card-body p-2 d-flex align-items-center rounded">
                                    <img src="${p.image || '../css/placeholder.png'}" alt="thumb" class="me-2" style="width:56px;height:56px;object-fit:cover;border-radius:6px;">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">${p.title}</div>
                                        <div class="text-muted small">HK$${Number(p.price || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                        likedGrid.appendChild(col);
                    });
                } catch (e) { likedGrid.innerHTML = '<div class="col-12 text-center py-4"><div class="text-muted">Failed to load liked products.</div></div>'; }
            }

            function parseProductHidden() {
                const raw = (prodHidden.value || '').trim();
                const map = {};
                if (!raw) return map;
                raw.split(',').map(s => s.trim()).filter(s => s).forEach(entry => {
                    const bits = entry.split(':');
                    const id = bits[0];
                    const q = (bits[1] && Number(bits[1]) > 0) ? Number(bits[1]) : 1;
                    map[String(id)] = q;
                });
                return map;
            }

            function updateSelectedUI() {
                const map = parseProductHidden();
                const ids = Object.keys(map);
                if (!ids.length) { selectedContainer.innerHTML = '<div class="text-muted">No products selected.</div>'; return; }
                const items = likedProducts.filter(p => ids.includes(String(p.productid)));
                if (!items.length) { selectedContainer.innerHTML = '<div class="text-muted">No products selected.</div>'; return; }
                selectedContainer.innerHTML = items.map(i => {
                    const qty = map[String(i.productid)] || 1;
                    const thumb = i.image ? `<img src="${i.image}" alt="thumb" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" class="me-2">` : '';
                    return `<div class="badge bs-white text-muted p-2 d-flex align-items-center rounded shadow-sm " data-id="${i.productid}">
                                ${thumb}
                                <div class="me-2">${i.title} <small class="ms-1">x${qty}</small></div>
                                <div class="ms-2 small">HK$${(Number(i.price || 0) * qty).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}</div>
                                <button type="button" class="btn btn-sm btn-light ms-2 prod-edit" data-id="${i.productid}" aria-label="Edit">✎</button>
                                <button type="button" class="btn btn-sm btn-danger ms-1 prod-remove" data-id="${i.productid}" aria-label="Remove">×</button>
                            </div>`;
                }).join('');
            }

            if (openBtn) {
                openBtn.addEventListener('click', async function () {
                    if (!likedProducts.length) await loadLiked();
                    // set checkboxes based on hidden input (ids only)
                    const map = parseProductHidden();
                    const ids = Object.keys(map);
                    document.querySelectorAll('.liked-product-card').forEach(card => { card.classList.toggle('selected', ids.includes(card.dataset.id)); });
                    if (bsModal) bsModal.show();
                });
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    const existing = parseProductHidden();
                    const selected = Array.from(document.querySelectorAll('.liked-product-card.selected')).map(card => card.dataset.id);
                    const out = [];
                    selected.forEach(id => { const q = existing[id] || 1; out.push(id + ':' + q); });
                    prodHidden.value = out.join(',');
                    updateSelectedUI();
                    // trigger total update by dispatching input event on references field (used by total updater)
                    const evt = new Event('input', { bubbles: true }); document.getElementById('references').dispatchEvent(evt);
                    if (bsModal) bsModal.hide();
                });
            }

            // allow edit/remove via event delegation
            selectedContainer.addEventListener('click', function (e) {
                const target = e.target;
                if (target.matches('.prod-remove')) {
                    const id = target.dataset.id;
                    const map = parseProductHidden();
                    delete map[id];
                    prodHidden.value = Object.keys(map).map(k => k + ':' + map[k]).join(',');
                    updateSelectedUI();
                    document.getElementById('references').dispatchEvent(new Event('input', { bubbles: true }));
                } else if (target.matches('.prod-edit')) {
                    const id = target.dataset.id;
                    const map = parseProductHidden();
                    const current = map[id] || 1;
                    const entry = prompt('Enter quantity for product ID ' + id + ':', String(current));
                    if (entry === null) return;
                    const nq = parseInt(entry, 10);
                    if (isNaN(nq) || nq <= 0) return alert('Invalid quantity');
                    map[id] = nq;
                    prodHidden.value = Object.keys(map).map(k => k + ':' + map[k]).join(',');
                    updateSelectedUI();
                    document.getElementById('references').dispatchEvent(new Event('input', { bubbles: true }));
                }
            });

            // initialize UI from any existing hidden value
            updateSelectedUI();

            // Allow clicking the product card to toggle selection (delegated)
            if (likedGrid) {
                likedGrid.addEventListener('click', function (e) {
                    const card = e.target.closest('.liked-product-card');
                    if (!card) return;
                    // if the click originated on an interactive control, ignore (the control handles it)
                    if (e.target.closest('input, button, a')) return;
                    // toggle selection class on the card
                    card.classList.toggle('selected');
                });
            }
        })();
    </script>
    <script>
        (function () {
            const designPrice = <?= json_encode((float) $design['expect_price']) ?>;
            const refsInput = document.getElementById('references');
            const totalEl = document.getElementById('orderTotal');
            let debounceTimer = null;

            function setTotal(refsSum) {
                const total = designPrice + (Number(refsSum) || 0);
                if (totalEl) totalEl.textContent = 'HK$' + total.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }

            async function fetchRefsSum(designIds, productQtys) {
                try {
                    const qs = [];
                    if (designIds) qs.push('designs=' + encodeURIComponent(designIds));
                    if (productQtys) qs.push('products_qty=' + encodeURIComponent(productQtys));
                    // request detail for itemized breakdown
                    qs.push('detail=1');
                    const resp = await fetch('get_design_prices.php?' + qs.join('&'));
                    if (!resp.ok) return 0;
                    const j = await resp.json();
                    // update reference list UI if available
                    try {
                        const refListEl = document.getElementById('referenceList');
                        if (refListEl) {
                            const lines = [];
                            if (j.designs && j.designs.length) {
                                j.designs.forEach(d => {
                                    const title = d.title ? d.title : ('#' + d.designid);
                                    lines.push(`<div class="ref-item"><div class="ref-left"><span class="ref-title">${title}</span></div><span class="ref-price">HK$${Number(d.price || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}</span></div>`);
                                });
                            }
                            if (j.products && j.products.length) {
                                j.products.forEach(p => {
                                    const title = p.title ? p.title : ('#' + p.productid);
                                    const qty = p.qty || 1;
                                    lines.push(`<div class="ref-item"><div class="ref-left"><span class="ref-title">${title}</span><span class="ref-qty">x${qty}</span></div><span class="ref-price">HK$${Number(p.price || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}</span></div>`);
                                });
                            }
                            refListEl.innerHTML = lines.length ? lines.join('') : '<div class="text-muted"></div>';
                        }
                    } catch (ex) { /* ignore UI failures */ }
                    return Number(j.refs_sum || 0);
                } catch (e) { return 0; }
            }

            if (refsInput) {
                refsInput.addEventListener('input', function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(async () => {
                        const dids = refsInput.value.trim();
                        const pqty = (document.getElementById('product_references') || { value: '' }).value.trim();
                        const sum = await fetchRefsSum(dids, pqty);
                        setTotal(sum);
                    }, 300);
                });
            }
            // initialize: fetch itemized refs and set total
            (async function initRefs() {
                const dids = refsInput ? refsInput.value.trim() : '';
                const pqty = (document.getElementById('product_references') || { value: '' }).value.trim();
                const sum = await fetchRefsSum(dids, pqty);
                setTotal(sum);
            })();
        })();
    </script>
</body>

</html>

<?php include __DIR__ . '/../Public/chat_widget.php'; ?>