<?php
// --- DB connection (singleton)
function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // In production return a clean error; here we throw for visibility
        throw new RuntimeException('Database connection failed: ' . $e->getMessage());
    }
}

// --- Fetch agents
function get_agents(): array {
    $pdo = get_db();
    $stmt = $pdo->query('SELECT id, name, title, avatar, is_online FROM agents ORDER BY is_online DESC, name ASC');
    return $stmt->fetchAll();
}

// --- Fetch messages for a conversation
function get_messages(string $conversation, int $limit = 500): array {
    if ($conversation === '') return [];
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, sender, body, campaign, created_at FROM messages WHERE conversation_id = :conv ORDER BY created_at ASC LIMIT :limit');
    $stmt->bindValue(':conv', $conversation, PDO::PARAM_STR);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// --- Insert message
function send_message(string $conversation, string $sender, string $body, ?string $campaign = null): array {
    if ($conversation === '' || trim($body) === '') {
        throw new InvalidArgumentException('Missing conversation or body');
    }
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO messages (conversation_id, sender, body, campaign) VALUES (:conv, :sender, :body, :campaign)');
    $stmt->execute([
        ':conv' => $conversation,
        ':sender' => $sender,
        ':body' => $body,
        ':campaign' => $campaign ?: null,
    ]);
    return [
        'ok' => true,
        'id' => $pdo->lastInsertId(),
        'created_at' => date('c'),
    ];
}

// --- Helper: JSON response
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Parse a sender string like "user:123" or "designer_5" into type and id.
 * Returns ['type' => string, 'id' => int|null].
 */
function parse_sender(string $sender): array {
    $sender = trim(strtolower($sender));
    // Match formats like "type:123", "type_123", or "type123"
    if (preg_match('/^([a-z]+)[_:]?([0-9]+)$/i', $sender, $m)) {
        return ['type' => strtolower($m[1]), 'id' => (int)$m[2]];
    }
    // If there's a colon with non-numeric id (e.g., "user:abc"), keep type only
    if (preg_match('/^([a-z]+)[:_].+$/i', $sender, $m)) {
        return ['type' => strtolower($m[1]), 'id' => null];
    }
    // Otherwise treat the whole string as the type
    return ['type' => $sender, 'id' => null];
}

function role_applies_to_sender(string $role, string $sender): bool {
    $role = strtolower($role);
    $info = parse_sender($sender);
    $sender_type = $info['type'];
    return ($role === 'user' && $sender_type === 'user') || ($role === 'agent' && $sender_type !== 'user');
}