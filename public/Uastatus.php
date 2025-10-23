<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if user is admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../public/Udashboard.php");
    exit();
}

include("../auth/config/database.php");

// Helper function to determine IP type
function getIPType($ip) {
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

// Fetch active user sessions
$query = "
    SELECT 
        us.id,
        us.user_id,
        us.ip_address,
        us.device_serial,
        us.user_agent,
        us.login_time,
        us.last_activity,
        us.is_active,
        u.username,
        u.first_name,
        u.last_name,
        u.email,
        u.role,
        u.department,
        CASE 
            WHEN us.last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'Online'
            ELSE 'Offline'
        END as status
    FROM user_sessions us
    INNER JOIN users u ON us.user_id = u.user_id
    WHERE us.is_active = 1
    ORDER BY us.last_activity DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$user_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_users = count(array_unique(array_column($user_sessions, 'user_id')));
$online_users = count(array_filter($user_sessions, function($session) {
    return $session['status'] === 'Online';
}));
$unique_devices = count(array_unique(array_filter(array_column($user_sessions, 'device_serial'), function($serial) {
    return $serial && $serial !== 'UNKNOWN';
})));

// Count only non-localhost IPs
$unique_ips = count(array_unique(array_filter(array_column($user_sessions, 'ip_address'), function($ip) {
    $type = getIPType($ip);
    return $type !== 'localhost' && $type !== 'unknown';
})));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity Tracking - E-Asset Management</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../js/deviceTracker.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.95;
            font-size: 1rem;
        }

        .info-banner {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-banner i {
            color: #856404;
            font-size: 1.5rem;
        }

        .info-banner p {
            color: #856404;
            margin: 0;
            font-size: 0.9rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.users { background: #dbeafe; color: #1e40af; }
        .stat-icon.online { background: #d1fae5; color: #065f46; }
        .stat-icon.devices { background: #fef3c7; color: #92400e; }
        .stat-icon.ips { background: #e0e7ff; color: #3730a3; }

        .stat-content h3 {
            font-size: 2rem;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .sessions-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .refresh-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.3s;
        }

        .refresh-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f9fafb;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }

        tr:hover {
            background: #f9fafb;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.online {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.offline {
            background: #e5e7eb;
            color: #374151;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .status-badge.online .status-indicator {
            background: #10b981;
        }

        .status-badge.offline .status-indicator {
            background: #6b7280;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-badge.admin {
            background: #fecaca;
            color: #991b1b;
        }

        .role-badge.manager {
            background: #fed7aa;
            color: #92400e;
        }

        .role-badge.employee {
            background: #dbeafe;
            color: #1e40af;
        }

        .device-info {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: #4b5563;
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }

        .ip-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .ip-type-badge {
            font-size: 0.65rem;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            width: fit-content;
        }

        .ip-type-badge.localhost {
            background: #fee2e2;
            color: #991b1b;
        }

        .ip-type-badge.private {
            background: #dbeafe;
            color: #1e40af;
        }

        .ip-type-badge.public {
            background: #d1fae5;
            color: #065f46;
        }

        .ip-type-badge.unknown {
            background: #e5e7eb;
            color: #6b7280;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: scroll;
            }

            table {
                min-width: 800px;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Active Users & Devices</h1>
            <p>Monitor user activity, IP addresses, and device information</p>
        </div>

        <?php if (getIPType($_SERVER['REMOTE_ADDR'] ?? '') === 'localhost'): ?>
        <div class="info-banner">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Testing on localhost</strong>
                <p>You're testing locally, so IP addresses will show as ::1 or 127.0.0.1. To see real WiFi IP addresses, access this page from another device on your network using your computer's IP address (e.g., http://192.168.1.x/eamts/...)</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $total_users; ?></h3>
                    <p>Total Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon online">
                    <i class="fas fa-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $online_users; ?></h3>
                    <p>Online Now</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon devices">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $unique_devices; ?></h3>
                    <p>Unique Devices</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon ips">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $unique_ips; ?></h3>
                    <p>Unique IP Addresses</p>
                </div>
            </div>
        </div>

        <div class="sessions-card">
            <div class="card-header">
                <h2>
                    <i class="fas fa-chart-line"></i>
                    User Sessions
                </h2>
                <button class="refresh-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                    Refresh
                </button>
            </div>

            <div class="table-container">
                <?php if (count($user_sessions) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>STATUS</th>
                                <th>USER</th>
                                <th>ROLE</th>
                                <th>DEPARTMENT</th>
                                <th>IP ADDRESS</th>
                                <th>DEVICE SERIAL</th>
                                <th>LAST ACTIVITY</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_sessions as $session): ?>
                                <?php 
                                $ip = $session['ip_address'];
                                $ip_type = getIPType($ip);
                                $display_ip = $ip ?: 'N/A';
                                ?>
                                <tr>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($session['status']); ?>">
                                            <span class="status-indicator"></span>
                                            <?php echo $session['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></strong>
                                        <br>
                                        <small style="color: #6b7280;">@<?php echo htmlspecialchars($session['username']); ?></small>
                                    </td>
                                    <td>
                                        <span class="role-badge <?php echo strtolower($session['role']); ?>">
                                            <?php echo htmlspecialchars(strtoupper($session['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($session['department']); ?></td>
                                    <td>
                                        <div class="ip-wrapper">
                                            <span class="device-info">
                                                <?php echo htmlspecialchars($display_ip); ?>
                                            </span>
                                            <span class="ip-type-badge <?php echo $ip_type; ?>">
                                                <?php 
                                                switch($ip_type) {
                                                    case 'localhost':
                                                        echo '<i class="fas fa-laptop"></i> Localhost';
                                                        break;
                                                    case 'private':
                                                        echo '<i class="fas fa-wifi"></i> LAN';
                                                        break;
                                                    case 'public':
                                                        echo '<i class="fas fa-globe"></i> Public';
                                                        break;
                                                    default:
                                                        echo '<i class="fas fa-question"></i> Unknown';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="device-info">
                                            <?php echo htmlspecialchars($session['device_serial'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($session['status'] === 'Online') {
                                            echo 'Now';
                                        } else {
                                            $last_activity = strtotime($session['last_activity']);
                                            $now = time();
                                            $diff = $now - $last_activity;
                                            
                                            if ($diff < 60) {
                                                echo 'Just now';
                                            } elseif ($diff < 3600) {
                                                echo floor($diff / 60) . ' min ago';
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . ' hours ago';
                                            } else {
                                                echo date('M d, Y', $last_activity);
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <p>No active user sessions found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>