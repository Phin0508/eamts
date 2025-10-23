<?php
session_start();
header('Content-Type: application/json');

try {
    // Test 1: Check session testing purpose file i got lost with my file path
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("No session - user_id not set");
    }
    
    echo json_encode([
        'session_check' => 'PASS',
        'user_id' => $_SESSION['user_id'],
        'session_role' => $_SESSION['role'] ?? 'not set'
    ]);
    echo "\n\n";
    
    // Test 2: Check database file - try multiple paths
    $possible_paths = [
        __DIR__ . "/../config/database.php",
        __DIR__ . "/config/database.php",
        "../config/database.php",
        "./config/database.php",
        "config/database.php"
    ];
    
    $found_path = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $found_path = $path;
            break;
        }
    }
    
    if (!$found_path) {
        echo json_encode([
            'db_file_check' => 'FAIL',
            'tried_paths' => $possible_paths,
            'current_dir' => __DIR__
        ]) . "\n\n";
        throw new Exception("database.php not found in any expected location");
    }
    
    echo json_encode([
        'db_file_check' => 'PASS',
        'path_used' => $found_path
    ]) . "\n\n";
    
    // Test 3: Include database
    include($found_path);
    
    if (!isset($pdo)) {
        throw new Exception("PDO object not created in database.php");
    }
    echo json_encode(['pdo_check' => 'PASS']) . "\n\n";
    
    // Test 4: Check table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_sessions'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        echo json_encode([
            'table_check' => 'FAIL',
            'error' => 'user_sessions table does not exist',
            'solution' => 'Run the CREATE TABLE SQL provided'
        ]) . "\n\n";
        
        // Provide CREATE TABLE statement
        echo "\n--- RUN THIS SQL ---\n";
        echo "CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    device_serial VARCHAR(100),
    user_agent TEXT,
    login_time DATETIME,
    last_activity DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_user_id (user_id),
    INDEX idx_device (device_serial),
    INDEX idx_active (is_active),
    INDEX idx_activity (last_activity)
);\n";
        exit;
    }
    
    echo json_encode(['table_check' => 'PASS']) . "\n\n";
    
    // Test 5: Check table structure
    $stmt = $pdo->query("DESCRIBE user_sessions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'structure_check' => 'PASS',
        'columns' => array_column($columns, 'Field')
    ]) . "\n\n";
    
    // Test 6: Try to insert test data
    $test_insert = $pdo->prepare("
        INSERT INTO user_sessions 
        (user_id, ip_address, device_serial, user_agent, login_time, last_activity, is_active) 
        VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
    ");
    
    $test_result = $test_insert->execute([
        $_SESSION['user_id'],
        '127.0.0.1',
        'TEST-' . time(),
        'Test Agent'
    ]);
    
    if ($test_result) {
        $test_id = $pdo->lastInsertId();
        echo json_encode([
            'insert_check' => 'PASS',
            'test_session_id' => $test_id
        ]) . "\n\n";
        
        // Clean up test data
        $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$test_id]);
        echo json_encode(['cleanup' => 'PASS']) . "\n\n";
    }
    
    echo "\n=== ALL TESTS PASSED ===\n";
    echo "trackDevice.php should work now!\n";
    
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database Error',
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error',
        'message' => $e->getMessage()
    ]);
}
?>