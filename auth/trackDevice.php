<?php
// Enhanced Device Tracking Endpoint
// Location: /auth/trackDevice.php

session_start();

// Disable display errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    sendResponse(['error' => 'Unauthorized - No session'], 401);
}

// Include database
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
            break;
        }
    }
    
    if (!$db_found || !isset($pdo)) {
        throw new Exception("Database configuration error");
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    sendResponse(['error' => 'Database configuration error'], 500);
}

// Enhanced IP detection
function getUserIP() {
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER)) {
            $ip_list = explode(',', $_SERVER[$key]);
            foreach ($ip_list as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Detect device type from user agent
function detectDeviceType($userAgent) {
    if (preg_match('/mobile|android|iphone|ipod|phone/i', $userAgent)) {
        return 'mobile';
    } elseif (preg_match('/tablet|ipad/i', $userAgent)) {
        return 'tablet';
    }
    return 'desktop';
}

// Extract browser info - FIXED ORDER
function getBrowserInfo($userAgent) {
    $browser = 'Unknown';
    $version = '';
    
    // Check Edge first (before Chrome, as Edge contains Chrome in UA)
    if (preg_match('/Edg\/([0-9.]+)/', $userAgent, $matches)) {
        $browser = 'Edge';
        $version = $matches[1];
    }
    // Check Chrome (before Safari, as Chrome contains Safari in UA)
    elseif (preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches)) {
        $browser = 'Chrome';
        $version = $matches[1];
    }
    // Check Firefox
    elseif (preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches)) {
        $browser = 'Firefox';
        $version = $matches[1];
    }
    // Check Safari last
    elseif (preg_match('/Safari\/([0-9.]+)/', $userAgent, $matches)) {
        if (!preg_match('/Chrome|Chromium/', $userAgent)) {
            $browser = 'Safari';
            $version = $matches[1];
        }
    }
    // Check Opera
    elseif (preg_match('/OPR\/([0-9.]+)/', $userAgent, $matches)) {
        $browser = 'Opera';
        $version = $matches[1];
    }
    
    return ['name' => $browser, 'version' => $version];
}

// Extract OS info
function getOSInfo($userAgent) {
    $os = 'Unknown';
    
    if (preg_match('/Windows NT ([0-9.]+)/', $userAgent, $matches)) {
        $os = 'Windows ' . $matches[1];
    } elseif (preg_match('/Mac OS X ([0-9_]+)/', $userAgent, $matches)) {
        $os = 'macOS ' . str_replace('_', '.', $matches[1]);
    } elseif (preg_match('/Android ([0-9.]+)/', $userAgent, $matches)) {
        $os = 'Android ' . $matches[1];
    } elseif (preg_match('/iPhone OS ([0-9_]+)/', $userAgent, $matches)) {
        $os = 'iOS ' . str_replace('_', '.', $matches[1]);
    } elseif (preg_match('/Linux/', $userAgent)) {
        $os = 'Linux';
    }
    
    return $os;
}

// Get network type
function getNetworkType($ip) {
    if (empty($ip) || $ip === 'N/A') {
        return 'unknown';
    }
    if ($ip === '::1' || $ip === '127.0.0.1' || strpos($ip, 'localhost') !== false) {
        return 'localhost';
    }
    if (strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || 
        preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
        return 'private';
    }
    return 'public';
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
    
    // Extract data
    $user_id = $_SESSION['user_id'];
    $ip_address = getUserIP();
    $device_serial = $data['serial'];
    $user_agent = $data['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Enhanced device information
    $browser_info = getBrowserInfo($user_agent);
    $os_info = getOSInfo($user_agent);
    $device_type = detectDeviceType($user_agent);
    $network_type = getNetworkType($ip_address);
    
    // Additional data
    $screen_resolution = $data['screenResolution'] ?? null;
    $timezone = $data['timezone'] ?? null;
    $language = $data['language'] ?? null;
    $platform = $data['platform'] ?? null;
    $color_depth = $data['colorDepth'] ?? null;
    $pixel_ratio = $data['pixelRatio'] ?? null;
    $cores = $data['cores'] ?? null;
    $connection_type = isset($data['connectionType']) ? json_encode($data['connectionType']) : null;
    $battery_info = isset($data['battery']) ? json_encode($data['battery']) : null;
    $page_url = $data['pageUrl'] ?? null;
    $referrer = $data['referrer'] ?? null;
    
    // Log comprehensive device info
    error_log("Device Tracking - User: $user_id, IP: $ip_address, Device: $device_serial, Type: $device_type, Browser: {$browser_info['name']} {$browser_info['version']}, OS: $os_info");
    
    // Clean up old inactive sessions (older than 24 hours)
    $cleanup = $pdo->prepare("
        UPDATE user_sessions 
        SET is_active = 0 
        WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR) 
        AND is_active = 1
    ");
    $cleanup->execute();
    
    // REMOVED: Don't deactivate other sessions - allow multiple active sessions per user
    // Users can be logged in on multiple browsers/devices simultaneously
    
    // Check for existing active session for this specific device
    $check = $pdo->prepare("
        SELECT id FROM user_sessions 
        WHERE user_id = ? AND device_serial = ? AND is_active = 1
        LIMIT 1
    ");
    $check->execute([$user_id, $device_serial]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing session with enhanced data
        $update = $pdo->prepare("
            UPDATE user_sessions 
            SET last_activity = NOW(), 
                ip_address = ?,
                user_agent = ?,
                device_type = ?,
                browser_name = ?,
                browser_version = ?,
                os_name = ?,
                network_type = ?,
                screen_resolution = ?,
                timezone = ?,
                language = ?,
                platform = ?,
                color_depth = ?,
                pixel_ratio = ?,
                cpu_cores = ?,
                connection_info = ?,
                battery_info = ?,
                current_page = ?,
                referrer = ?
            WHERE id = ?
        ");
        
        $result = $update->execute([
            $ip_address,
            $user_agent,
            $device_type,
            $browser_info['name'],
            $browser_info['version'],
            $os_info,
            $network_type,
            $screen_resolution,
            $timezone,
            $language,
            $platform,
            $color_depth,
            $pixel_ratio,
            $cores,
            $connection_type,
            $battery_info,
            $page_url,
            $referrer,
            $existing['id']
        ]);
        
        if (!$result) {
            throw new Exception("Failed to update session");
        }
        
        sendResponse([
            'success' => true,
            'action' => 'updated',
            'session_id' => $existing['id'],
            'user_id' => $user_id,
            'device_serial' => $device_serial,
            'ip_address' => $ip_address,
            'network_type' => $network_type,
            'device_type' => $device_type,
            'browser' => $browser_info['name'] . ' ' . $browser_info['version'],
            'os' => $os_info
        ]);
        
    } else {
        // Create new session with enhanced data
        $insert = $pdo->prepare("
            INSERT INTO user_sessions 
            (user_id, ip_address, device_serial, user_agent, device_type, 
             browser_name, browser_version, os_name, network_type, 
             screen_resolution, timezone, language, platform, 
             color_depth, pixel_ratio, cpu_cores, connection_info, 
             battery_info, current_page, referrer, 
             login_time, last_activity, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ");
        
        $result = $insert->execute([
            $user_id,
            $ip_address,
            $device_serial,
            $user_agent,
            $device_type,
            $browser_info['name'],
            $browser_info['version'],
            $os_info,
            $network_type,
            $screen_resolution,
            $timezone,
            $language,
            $platform,
            $color_depth,
            $pixel_ratio,
            $cores,
            $connection_type,
            $battery_info,
            $page_url,
            $referrer
        ]);
        
        if (!$result) {
            throw new Exception("Failed to insert session");
        }
        
        $new_id = $pdo->lastInsertId();
        
        sendResponse([
            'success' => true,
            'action' => 'created',
            'session_id' => $new_id,
            'user_id' => $user_id,
            'device_serial' => $device_serial,
            'ip_address' => $ip_address,
            'network_type' => $network_type,
            'device_type' => $device_type,
            'browser' => $browser_info['name'] . ' ' . $browser_info['version'],
            'os' => $os_info
        ]);
    }
    
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    sendResponse([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ], 500);
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    sendResponse([
        'error' => 'Processing error',
        'message' => $e->getMessage()
    ], 400);
}
?>