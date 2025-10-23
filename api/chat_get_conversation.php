<?php
// api/chat_get_conversation.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include("../auth/config/database.php");

$current_user_id = $_SESSION['user_id'];
$other_user_id = $_POST['user_id'];

try {
    // Check if conversation already exists
    $stmt = $pdo->prepare("
        SELECT cc.id as conversation_id
        FROM chat_conversations cc
        JOIN chat_participants cp1 ON cc.id = cp1.conversation_id
        JOIN chat_participants cp2 ON cc.id = cp2.conversation_id
        WHERE cc.type = 'direct'
        AND cp1.user_id = ?
        AND cp2.user_id = ?
        AND cp1.is_active = 1
        AND cp2.is_active = 1
        LIMIT 1
    ");
    
    $stmt->execute([$current_user_id, $other_user_id]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conversation) {
        echo json_encode($conversation);
    } else {
        // Create new conversation
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO chat_conversations (type, created_by) 
            VALUES ('direct', ?)
        ");
        $stmt->execute([$current_user_id]);
        $conversation_id = $pdo->lastInsertId();
        
        // Add participants
        $stmt = $pdo->prepare("
            INSERT INTO chat_participants (conversation_id, user_id) 
            VALUES (?, ?), (?, ?)
        ");
        $stmt->execute([$conversation_id, $current_user_id, $conversation_id, $other_user_id]);
        
        $pdo->commit();
        
        echo json_encode(['conversation_id' => $conversation_id]);
    }
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>