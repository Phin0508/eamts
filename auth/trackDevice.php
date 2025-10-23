<?php
// IMPORTANT: No whitespace before this line!
session_start();

// Disable display errors completely
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set JSON header immediately
header('Content-Type: application/json');

// Function to send JSON response and exit
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    sendResponse(['error' => 'Unauthorized - No session'], 401);
}

// Include database with error handling
try {
    $possible_paths = [
        __DIR__ . "/../config/database.php",
        __DIR__ . "/config/database.php",
        "../config/database.php",
        "./config/database.php",
        "config/database.php"
    ];
    
    $db_found = false;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            include($path);
            $db_found = true;
            error_log("Database loaded from: $path");
            break;
        }
    }
    
    if (!$db_found) {
        throw new Exception("Database config file not found. Tried: " . implode(", ", $possible_paths));
    }
    
    if (!isset($pdo)) {
        throw new Exception("Database connection not established");
    }
} catch (Exception $e) {
    error_log("Database include error: " . $e->getMessage());
    sendResponse(['error' => 'Database configuration error', 'details' => $e->getMessage()], 500);
}

// Enhanced IP address detection
function getUserIP() {
    $ip_keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip_list = explode(',', $_SERVER[$key]);
            foreach ($ip_list as $ip) {
                $ip = trim($ip);
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    // Fallback to REMOTE_ADDR (will be localhost if local)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Mark localhost IPs
    if ($ip === '::1' || $ip === '127.0.0.1') {
        $ip .= ' (localhost)';
    }
    
    return trim($ip);
}

// Get network type hint from headers
function getNetworkInfo() {
    $info = [
        'ip' => getUserIP(),
        'type' => 'unknown'
    ];
    
    // Check if behind proxy/load balancer
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $info['type'] = 'proxy/forwarded';
    } elseif (strpos($info['ip'], '192.168.') === 0) {
        $info['type'] = 'private-network';
    } elseif (strpos($info['ip'], '10.') === 0) {
        $info['type'] = 'private-network';
    } elseif (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $info['ip'])) {
        $info['type'] = 'private-network';
    } elseif ($info['ip'] === '::1' || $info['ip'] === '127.0.0.1' || strpos($info['ip'], 'localhost') !== false) {
        $info['type'] = 'localhost';
    } else {
        $info['type'] = 'public';
    }
    
    return $info;
}

try {
    // Get JSON data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    if (!$data || !isset($data['serial'])) {
        throw new Exception('Missing required data: serial');
    }
    
    $user_id = $_SESSION['user_id'];
    $network_info = getNetworkInfo();
    $ip_address = $network_info['ip'];
    $device_serial = $data['serial'];
    $user_agent = $data['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Enhanced logging
    error_log("Device tracking - User: $user_id, IP: $ip_address ({$network_info['type']}), Device: $device_serial");
    
    // Clean up old sessions first
    try {
        $cleanup = $pdo->prepare("
            UPDATE user_sessions 
            SET is_active = 0 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR) 
            AND is_active = 1
        ");
        $cleanup->execute();
    } catch (PDOException $e) {
        error_log("Cleanup error: " . $e->getMessage());
    }
    
    // Check if active session exists
    $check = $pdo->prepare("
        SELECT id FROM user_sessions 
        WHERE user_id = ? AND device_serial = ? AND is_active = 1
        LIMIT 1
    ");
    $check->execute([$user_id, $device_serial]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing session
        $update = $pdo->prepare("
            UPDATE user_sessions 
            SET last_activity = NOW(), 
                ip_address = ?,
                user_agent = ?
            WHERE id = ?
        ");
        $result = $update->execute([$ip_address, $user_agent, $existing['id']]);
        
        if (!$result) {
            throw new Exception("Failed to update session");
        }
        
        error_log("Session updated - ID: {$existing['id']}");
        
        sendResponse([
            'success' => true,
            'action' => 'updated',
            'session_id' => $existing['id'],
            'user_id' => $user_id,
            'device_serial' => $device_serial,
            'ip_address' => $ip_address,
            'network_type' => $network_info['type']
        ]);
        
    } else {
        // Create new session
        $insert = $pdo->prepare("
            INSERT INTO user_sessions 
            (user_id, ip_address, device_serial, user_agent, login_time, last_activity, is_active) 
            VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
        ");
        $result = $insert->execute([$user_id, $ip_address, $device_serial, $user_agent]);
        
        if (!$result) {
            throw new Exception("Failed to insert session");
        }
        
        $new_id = $pdo->lastInsertId();
        error_log("Session created - ID: $new_id, User: $user_id");
        
        sendResponse([
            'success' => true,
            'action' => 'created',
            'session_id' => $new_id,
            'user_id' => $user_id,
            'device_serial' => $device_serial,
            'ip_address' => $ip_address,
            'network_type' => $network_info['type']
        ]);
    }
    
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    sendResponse([
        'error' => 'Database error',
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ], 500);
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    sendResponse([
        'error' => 'Processing error',
        'message' => $e->getMessage()
    ], 400);
}
?>