<?php
// api/chat_get_messages.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['conversation_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include("../auth/config/database.php");

$current_user_id = $_SESSION['user_id'];
$conversation_id = $_GET['conversation_id'];
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

try {
    // Verify user is part of conversation
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM chat_participants 
        WHERE conversation_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$conversation_id, $current_user_id]);
    
    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit();
    }
    
    // Get messages - either all or only new ones after a specific ID
    if ($after_id > 0) {
        // Get only new messages after the specified ID
        $stmt = $pdo->prepare("
            SELECT 
                cm.id as message_id,
                cm.conversation_id,
                cm.sender_id,
                cm.message,
                cm.message_type,
                cm.file_path,
                cm.is_read,
                cm.is_edited,
                cm.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as sender_name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.user_id
            WHERE cm.conversation_id = ? AND cm.id > ?
            ORDER BY cm.created_at ASC
        ");
        $stmt->execute([$conversation_id, $after_id]);
    } else {
        // Get all messages (initial load)
        $stmt = $pdo->prepare("
            SELECT 
                cm.id as message_id,
                cm.conversation_id,
                cm.sender_id,
                cm.message,
                cm.message_type,
                cm.file_path,
                cm.is_read,
                cm.is_edited,
                cm.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as sender_name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.user_id
            WHERE cm.conversation_id = ?
            ORDER BY cm.created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$conversation_id]);
    }
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Only mark messages as read if there are new messages
    if (count($messages) > 0) {
        // Mark messages as read
        $stmt = $pdo->prepare("
            UPDATE chat_messages 
            SET is_read = 1 
            WHERE conversation_id = ? 
            AND sender_id != ? 
            AND is_read = 0
        ");
        $stmt->execute([$conversation_id, $current_user_id]);
        
        // Update last read time
        $stmt = $pdo->prepare("
            UPDATE chat_participants 
            SET last_read_at = NOW() 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversation_id, $current_user_id]);
    }
    
    echo json_encode($messages);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>