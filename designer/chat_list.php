<?php
// Server-rendered chat room list for current logged-in user
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user'])) {
  // Not logged in — show placeholder (shouldn't normally happen when included from chat.php)
  echo '<div class="p-3 text-center text-muted">Please <a href="../login.php">log in</a> to view chats.</div>';
  return;
}

$role = $_SESSION['user']['role'] ?? 'client';
$memberId = (int) ($_SESSION['user'][$role . 'id'] ?? $_SESSION['user']['id'] ?? 0);

// Normalize role names used in ChatRoomMember (contractors table name is 'Contractors')
if ($role === 'contractors') $member_type = 'Contractors';
else $member_type = $role;

$conn = $mysqli;

// fetch chatroom memberships (primary source)
$rooms = [];
$stmt = $conn->prepare("SELECT ChatRoomMemberid, ChatRoomid FROM ChatRoomMember WHERE memberid = ? AND member_type LIKE ? ORDER BY ChatRoomMemberid DESC");
if ($stmt) {
  $stmt->bind_param('is', $memberId, $member_type);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $rooms[] = $r;
  $stmt->close();
}

// if no membership rows found, also include rooms where the user has sent messages
if (empty($rooms)) {
  $mstmt = $conn->prepare("SELECT DISTINCT ChatRoomid FROM Message WHERE sender_id = ? AND sender_type LIKE ? ORDER BY ChatRoomid DESC");
  if ($mstmt) {
    $mstmt->bind_param('is', $memberId, $member_type);
    $mstmt->execute();
    $mres = $mstmt->get_result();
    while ($mr = $mres->fetch_assoc()) {
      $rooms[] = ['ChatRoomMemberid' => null, 'ChatRoomid' => $mr['ChatRoomid']];
    }
    $mstmt->close();
  }
}

function get_last_message($conn, $chatroomid) {
  $s = $conn->prepare("SELECT content, sender_type, sender_id, timestamp FROM Message WHERE ChatRoomid = ? ORDER BY timestamp DESC LIMIT 1");
  if (!$s) return null;
  $s->bind_param('i', $chatroomid);
  $s->execute();
  $res = $s->get_result()->fetch_assoc();
  $s->close();
  return $res ?: null;
}

function get_unread_count($conn, $chatroomid, $chatRoomMemberid, $role, $memberId) {
  // Count messages in room that are not read by this ChatRoomMember and not sent by this user
  $s = $conn->prepare(
    "SELECT COUNT(*) AS total FROM Message m
     LEFT JOIN MessageRead mr ON mr.messageid = m.messageid AND mr.ChatRoomMemberid = ?
     WHERE m.ChatRoomid = ? AND (mr.is_read = 0 OR mr.is_read IS NULL) AND NOT (m.sender_type = ? AND m.sender_id = ?)");
  if (!$s) return 0;
  $s->bind_param('iisi', $chatRoomMemberid, $chatroomid, $role, $memberId);
  $s->execute();
  $total = (int) ($s->get_result()->fetch_assoc()['total'] ?? 0);
  $s->close();
  return $total;
}

// Render list (styled)
echo '<style>
.chat-list { padding:0; margin:0; }
.chat-list-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;border:1px solid transparent}
.chat-list-item:hover{background:#f6f8fb;border-color:#e9eef6}
.chat-avatar{width:44px;height:44px;border-radius:50%;background:#e9eef8;display:flex;align-items:center;justify-content:center;font-weight:600;color:#41536b}
.chat-main{flex:1;min-width:0}
.chat-name{font-weight:600;color:#0b2b4a}
.chat-preview{display:block;color:#6c7887;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-meta{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:78px}
.chat-time{color:#98a0ab;font-size:12px}
.chat-badge{min-width:28px}
</style>';

echo '<div class="list-group mb-3 chat-list">';

if (empty($rooms)) {
  // helpful empty state so UI is visible
  echo '<div class="p-3 text-center">';
  echo '<div class="mb-2" style="font-weight:600;color:#0b2b4a">No conversations yet</div>';
  echo '<div class="small text-muted mb-3">Your recent chats will appear here.</div>';
  echo '<a class="btn btn-sm btn-outline-primary" href="#" data-bs-toggle="offcanvas" data-bs-target="#catalogOffcanvas">Start a conversation</a>';
  echo '</div>';
}

foreach ($rooms as $rm) {
  $chatMemberId = (int) $rm['ChatRoomMemberid'];
  $chatRoomId = (int) $rm['ChatRoomid'];

  // get room info
  $rstmt = $conn->prepare('SELECT roomname, room_type FROM ChatRoom WHERE ChatRoomid = ?');
  $roomname = 'Chat';
  if ($rstmt) {
    $rstmt->bind_param('i', $chatRoomId);
    $rstmt->execute();
    $rinfo = $rstmt->get_result()->fetch_assoc();
    if ($rinfo) $roomname = $rinfo['roomname'] ?? $roomname;
    $rstmt->close();
  }

  $last = get_last_message($conn, $chatRoomId);
  $unread = get_unread_count($conn, $chatRoomId, $chatMemberId, $role, $memberId);

  $previewText = $last ? mb_strimwidth($last['content'], 0, 80, '...') : 'No messages';
  $preview = htmlspecialchars($previewText);
  $time = $last ? date('Y-m-d H:i', strtotime($last['timestamp'])) : '';

  $initial = htmlspecialchars(mb_strtoupper(mb_substr($roomname, 0, 1)));

  echo '<a href="?conversation=' . $chatRoomId . '" class="list-group-item list-group-item-action chat-list-item" data-room="' . $chatRoomId . '">';
  echo '<div class="chat-avatar">' . $initial . '</div>';
  echo '<div class="chat-main">';
  echo '<div class="chat-name">' . htmlspecialchars($roomname) . '</div>';
  echo '<div class="chat-preview">' . $preview . '</div>';
  echo '</div>';
  echo '<div class="chat-meta">';
  echo '<div class="chat-time">' . ($time ? htmlspecialchars($time) : '') . '</div>';
  if ($unread > 0) echo '<span class="badge bg-danger rounded-pill chat-badge">' . $unread . '</span>';
  echo '</div>';
  echo '</a>';
}
echo '</div>';

?>
