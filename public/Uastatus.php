<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../public/Udashboard.php");
    exit();
}

include("../auth/config/database.php");

// Fetch enhanced session data with better online detection
$query = "
    SELECT 
        us.*,
        u.username,
        u.first_name,
        u.last_name,
        u.email,
        u.role,
        u.department,
        CASE 
            WHEN us.last_activity >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 'Online'
            ELSE 'Offline'
        END as status
    FROM user_sessions us
    INNER JOIN users u ON us.user_id = u.user_id
    WHERE us.is_active = 1
    ORDER BY us.last_activity DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate analytics - count unique users but all sessions
$unique_users = array_unique(array_column($sessions, 'user_id'));
$total_users = count($unique_users);
$online_users = count(array_filter($sessions, fn($s) => $s['status'] === 'Online'));
$unique_devices = count(array_unique(array_filter(array_column($sessions, 'device_serial'))));

// Device type breakdown
$device_types = array_count_values(array_filter(array_column($sessions, 'device_type')));

// Browser breakdown
$browsers = array_count_values(array_filter(array_column($sessions, 'browser_name')));

// OS breakdown
$os_list = array_count_values(array_filter(array_column($sessions, 'os_name')));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity Dashboard - E-Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="../js/deviceTracker.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc;
            color: #2d3748;
            min-height: 100vh;
        }

        /* Main Container */
        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title h1 i {
            color: #7c3aed;
        }

        .header-title p {
            color: #718096;
            font-size: 15px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Auto Refresh Indicator */
        .auto-refresh-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            background: linear-gradient(135deg, #ede9fe 0%, #f7f4fe 100%);
            border-radius: 10px;
            border: 2px solid #e9d5ff;
            font-size: 14px;
            font-weight: 600;
            color: #6d28d9;
        }

        .refresh-spinner {
            width: 18px;
            height: 18px;
            border: 2px solid #e9d5ff;
            border-top-color: #7c3aed;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Buttons */
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #7c3aed;
            border: 2px solid #e9d5ff;
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #7c3aed;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.05) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.users {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .stat-icon.online {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .stat-icon.devices {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .stat-icon.sessions {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .stat-content h3 {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .stat-content p {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .chart-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .chart-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-header h3 i {
            color: #7c3aed;
        }

        /* Section */
        .section {
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .section-header {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            padding: 28px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-actions {
            display: flex;
            gap: 10px;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            padding: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
        }

        .table thead th {
            padding: 16px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            color: #6d28d9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        .table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .table tbody tr:hover {
            background: #fafbfc;
        }

        .table tbody td {
            padding: 20px 16px;
            font-size: 14px;
            color: #2d3748;
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .user-details h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
        }

        .user-details p {
            font-size: 13px;
            color: #718096;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-badge.online {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .status-badge.offline {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .status-badge.online .status-dot {
            background: #10b981;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.5);
        }

        .status-badge.offline .status-dot {
            background: #ef4444;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(0.9); }
        }

        /* Device Badge */
        .device-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .device-badge.desktop {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .device-badge.mobile {
            background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%);
            color: #9f1239;
        }

        .device-badge.tablet {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .device-serial {
            margin-top: 6px;
            font-size: 11px;
            color: #9ca3af;
            font-family: 'Courier New', monospace;
        }

        /* Tech Badge */
        .tech-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: #f3f4f6;
            color: #4b5563;
            margin: 2px;
        }

        /* IP Badge */
        .ip-badge {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
            color: #374151;
        }

        .network-type {
            display: inline-block;
            font-size: 10px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 6px;
            letter-spacing: 0.5px;
        }

        .network-type.public {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .network-type.private {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .network-type.localhost {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .location-info {
            font-size: 13px;
            color: #4b5563;
        }

        .location-info i {
            color: #9ca3af;
            margin-right: 4px;
        }

        .language-info {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #718096;
            font-size: 15px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .container.sidebar-collapsed {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .container.sidebar-collapsed {
                margin-left: 0;
            }

            .header {
                padding: 20px;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-title h1 {
                font-size: 22px;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .header-actions .btn,
            .auto-refresh-indicator {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            .section-header {
                padding: 20px;
                flex-direction: column;
                align-items: flex-start;
            }

            .section-actions {
                width: 100%;
                flex-direction: column;
            }

            .section-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .table-container {
                padding: 20px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table {
                min-width: 1000px;
            }

            .user-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="fas fa-chart-line"></i> User Activity Dashboard</h1>
                    <p>Real-time monitoring of user sessions, devices, and system activity</p>
                </div>
                <div class="header-actions">
                    <div class="auto-refresh-indicator">
                        <div class="refresh-spinner"></div>
                        <span>Auto-refresh: 30s</span>
                    </div>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh Now
                    </button>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $total_users; ?></h3>
                        <p>Active Users</p>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon online">
                        <i class="fas fa-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $online_users; ?></h3>
                        <p>Online Now</p>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon devices">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $unique_devices; ?></h3>
                        <p>Unique Devices</p>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon sessions">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo count($sessions); ?></h3>
                        <p>Total Sessions</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-container">
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-laptop"></i> Device Types</h3>
                </div>
                <canvas id="deviceChart"></canvas>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-browser"></i> Browsers</h3>
                </div>
                <canvas id="browserChart"></canvas>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-desktop"></i> Operating Systems</h3>
                </div>
                <canvas id="osChart"></canvas>
            </div>
        </div>

        <!-- Active Sessions Table -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-users-cog"></i> Active Sessions</h2>
                <div class="section-actions">
                    <button class="btn btn-secondary" onclick="exportToCSV()">
                        <i class="fas fa-download"></i> Export CSV
                    </button>
                </div>
            </div>

            <div class="table-container">
                <?php if (count($sessions) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>User</th>
                                <th>Device</th>
                                <th>Browser & OS</th>
                                <th>IP Address</th>
                                <th>Location</th>
                                <th>Last Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): ?>
                                <tr>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($session['status']); ?>">
                                            <span class="status-dot"></span>
                                            <?php echo $session['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($session['first_name'], 0, 1) . substr($session['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="user-details">
                                                <h4><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></h4>
                                                <p>@<?php echo htmlspecialchars($session['username']); ?> • <?php echo htmlspecialchars($session['role']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($session['device_type']): ?>
                                            <span class="device-badge <?php echo strtolower($session['device_type']); ?>">
                                                <i class="fas fa-<?php 
                                                    echo $session['device_type'] == 'mobile' ? 'mobile-alt' : 
                                                        ($session['device_type'] == 'tablet' ? 'tablet-alt' : 'laptop'); 
                                                ?>"></i>
                                                <?php echo ucfirst($session['device_type']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($session['device_serial']): ?>
                                            <div class="device-serial">
                                                <?php echo htmlspecialchars(substr($session['device_serial'], 0, 20)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($session['browser_name']): ?>
                                            <span class="tech-badge">
                                                <i class="fab fa-<?php echo strtolower($session['browser_name']); ?>"></i>
                                                <?php echo htmlspecialchars($session['browser_name']); ?>
                                                <?php if ($session['browser_version']): ?>
                                                    <?php echo htmlspecialchars(explode('.', $session['browser_version'])[0]); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($session['os_name']): ?>
                                            <span class="tech-badge">
                                                <i class="fas fa-desktop"></i>
                                                <?php echo htmlspecialchars(explode(' ', $session['os_name'])[0]); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="ip-badge">
                                            <?php echo htmlspecialchars($session['ip_address']); ?>
                                        </div>
                                        <?php if ($session['network_type']): ?>
                                            <span class="network-type <?php echo strtolower($session['network_type']); ?>">
                                                <?php echo htmlspecialchars($session['network_type']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($session['timezone']): ?>
                                            <div class="location-info">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($session['timezone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['language']): ?>
                                            <div class="language-info">
                                                <i class="fas fa-language"></i>
                                                <?php echo htmlspecialchars($session['language']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($session['status'] === 'Online') {
                                            echo '<strong style="color: #10b981;">Now</strong>';
                                        } else {
                                            $last_activity = strtotime($session['last_activity']);
                                            $now = time();
                                            $diff = $now - $last_activity;
                                            
                                            if ($diff < 60) {
                                                echo 'Just now';
                                            } elseif ($diff < 3600) {
                                                echo floor($diff / 60) . ' min ago';
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . ' hrs ago';
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
                        <h3>No Active Sessions</h3>
                        <p>No users are currently logged in</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Device Types Chart
        const deviceCtx = document.getElementById('deviceChart');
        if (deviceCtx) {
            new Chart(deviceCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_keys($device_types)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($device_types)); ?>,
                        backgroundColor: [
                            'rgba(102, 126, 234, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(16, 185, 129, 0.8)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12,
                                    family: "'Segoe UI', sans-serif"
                                }
                            }
                        }
                    }
                }
            });
        }

        // Browsers Chart
        const browserCtx = document.getElementById('browserChart');
        if (browserCtx) {
            new Chart(browserCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($browsers)); ?>,
                    datasets: [{
                        label: 'Users',
                        data: <?php echo json_encode(array_values($browsers)); ?>,
                        backgroundColor: 'rgba(102, 126, 234, 0.8)',
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        // OS Chart
        const osCtx = document.getElementById('osChart');
        if (osCtx) {
            new Chart(osCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_keys($os_list)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($os_list)); ?>,
                        backgroundColor: [
                            'rgba(139, 92, 246, 0.8)',
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(59, 130, 246, 0.8)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12,
                                    family: "'Segoe UI', sans-serif"
                                }
                            }
                        }
                    }
                }
            });
        }

        // Export to CSV
        function exportToCSV() {
            const table = document.querySelector('table');
            if (!table) return;

            let csv = [];
            const rows = table.querySelectorAll('tr');

            for (let row of rows) {
                let cols = row.querySelectorAll('td, th');
                let csvRow = [];
                for (let col of cols) {
                    csvRow.push('"' + col.innerText.replace(/"/g, '""') + '"');
                }
                csv.push(csvRow.join(','));
            }

            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'user_sessions_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);

        // Update refresh indicator
        let countdown = 30;
        setInterval(() => {
            countdown--;
            if (countdown <= 0) countdown = 30;
            const indicator = document.querySelector('.auto-refresh-indicator span');
            if (indicator) {
                indicator.textContent = `Auto-refresh: ${countdown}s`;
            }
        }, 1000);
    </script>
</body>
</html>