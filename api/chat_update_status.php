<?php
// api/chat_update_status.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['status'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include("../auth/config/database.php");

$current_user_id = $_SESSION['user_id'];
$status = $_POST['status'];

// Validate status
$valid_statuses = ['online', 'away', 'busy', 'offline'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO chat_users (user_id, status, last_activity) 
        VALUES (?, ?, NOW()) 
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status), 
            last_activity = NOW()
    ");
    
    $stmt->execute([$current_user_id, $status]);
    
    echo json_encode([
        'success' => true,
        'status' => $status,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>