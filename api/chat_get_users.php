<?php
// api/chat_get_users.php
session_start();
header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'debug' => 'Session not set']);
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
    echo json_encode(['error' => 'Database configuration not found', 'tried_paths' => $db_paths]);
    exit();
}

$current_user_id = $_SESSION['user_id'];

try {
    // Get all users with their status and unread count
    $stmt = $pdo->prepare("
        SELECT 
            u.user_id,
            CONCAT(u.first_name, ' ', u.last_name) as name,
            COALESCE(u.department, 'N/A') as department,
            COALESCE(u.role, 'employee') as role,
            COALESCE(cu.status, 'offline') as status,
            cu.last_activity,
            CONCAT(UPPER(LEFT(u.first_name, 1)), UPPER(LEFT(u.last_name, 1))) as initials,
            (
                SELECT COUNT(*) 
                FROM chat_messages cm
                JOIN chat_conversations cc ON cm.conversation_id = cc.id
                JOIN chat_participants cp1 ON cc.id = cp1.conversation_id
                JOIN chat_participants cp2 ON cc.id = cp2.conversation_id
                WHERE cp1.user_id = u.user_id 
                AND cp2.user_id = ?
                AND cm.sender_id = u.user_id
                AND cm.is_read = 0
            ) as unread
        FROM users u
        LEFT JOIN chat_users cu ON u.user_id = cu.user_id
        WHERE u.user_id != ? 
        AND u.is_active = 1
        ORDER BY 
            CASE cu.status
                WHEN 'online' THEN 1
                WHEN 'away' THEN 2
                WHEN 'busy' THEN 3
                ELSE 4
            END,
            u.first_name ASC
    ");
    
    $stmt->execute([$current_user_id, $current_user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update status for users who haven't been active in 5 minutes
    $pdo->exec("
        UPDATE chat_users 
        SET status = 'away' 
        WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
        AND status = 'online'
    ");
    
    // Convert to proper types
    foreach ($users as &$user) {
        $user['unread'] = (int)$user['unread'];
        $user['user_id'] = (int)$user['user_id'];
    }
    
    echo json_encode($users);
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("chat_get_users.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>