<?php
// api/chat_get_unread_count.php
// Get total unread message count for current user
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'unread' => 0]);
    exit();
}

// Try different paths for database connection
$db_paths = [
    __DIR__ . "/../auth/config/database.php",
    "../auth/config/database.php",
    dirname(__DIR__) . "/auth/config/database.php"
];

$db_loaded = false;
foreach ($db_paths as $path) {
    if (file_exists($path)) {
        include($path);
        $db_loaded = true;
        break;
    }
}

if (!$db_loaded) {
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration not found', 'unread' => 0]);
    exit();
}

$current_user_id = $_SESSION['user_id'];

try {
    // Get total unread messages for current user
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread
        FROM chat_messages cm
        JOIN chat_conversations cc ON cm.conversation_id = cc.id
        JOIN chat_participants cp1 ON cc.id = cp1.conversation_id
        JOIN chat_participants cp2 ON cc.id = cp2.conversation_id
        WHERE cp1.user_id != ?
        AND cp2.user_id = ?
        AND cm.sender_id = cp1.user_id
        AND cm.is_read = 0
        AND cp1.is_active = 1
        AND cp2.is_active = 1
    ");
    
    $stmt->execute([$current_user_id, $current_user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'unread' => (int)$result['unread']
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("chat_get_unread_count.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error', 'unread' => 0]);
}
?>