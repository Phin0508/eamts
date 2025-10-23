<?php
// api/chat_send_message.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['conversation_id']) || !isset($_POST['message'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized or missing data']);
    exit();
}

include("../auth/config/database.php");

$current_user_id = $_SESSION['user_id'];
$conversation_id = $_POST['conversation_id'];
$message = trim($_POST['message']);

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty']);
    exit();
}

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
    
    // Insert message
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, message, message_type) 
        VALUES (?, ?, ?, 'text')
    ");
    $stmt->execute([$conversation_id, $current_user_id, $message]);
    $message_id = $pdo->lastInsertId();
    
    // Update conversation timestamp
    $stmt = $pdo->prepare("
        UPDATE chat_conversations 
        SET updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$conversation_id]);
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>