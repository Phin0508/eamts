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

// Calculate analytics
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

        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

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

        .table tbody tr.main-row {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
        }

        .table tbody tr.main-row:hover {
            background: #fafbfc;
        }

        .table tbody td {
            padding: 20px 16px;
            font-size: 14px;
            color: #2d3748;
        }

        /* Expandable Row Styles */
        .expand-btn {
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            color: #7c3aed;
            font-size: 16px;
        }

        .expand-btn:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            transform: scale(1.1);
        }

        .expand-btn i {
            transition: transform 0.3s;
        }

        .expand-btn.expanded i {
            transform: rotate(180deg);
        }

        .details-row {
            display: none;
            background: #f9fafb;
        }

        .details-row.show {
            display: table-row;
        }

        .details-content {
            padding: 30px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .detail-section h4 {
            font-size: 16px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .detail-section h4 i {
            color: #7c3aed;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 13px;
            color: #718096;
            font-weight: 600;
        }

        .detail-value {
            font-size: 13px;
            color: #2d3748;
            font-weight: 500;
            text-align: right;
        }

        /* Battery indicator */
        .battery-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
        }

        .battery-indicator.charging {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .battery-indicator.high {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .battery-indicator.medium {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .battery-indicator.low {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .battery-bar {
            width: 60px;
            height: 8px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .battery-fill {
            height: 100%;
            transition: width 0.3s;
        }

        .battery-fill.high {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .battery-fill.medium {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .battery-fill.low {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        /* Network speed indicator */
        .speed-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }

        .speed-indicator.fast {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .speed-indicator.medium {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .speed-indicator.slow {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        /* Progress bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #7c3aed, #6d28d9);
            transition: width 0.3s;
        }

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

        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="fas fa-chart-line"></i> User Activity Dashboard</h1>
                    <p>Real-time monitoring of user sessions, devices, and system activity</p>
                </div>
                <div class="header-actions">
                    <div class="auto-refresh-indicator">
                        <div class="refresh-spinner"></div>
                        <span>Auto-refresh: 60s</span>
                    </div>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh Now
                    </button>
                </div>
            </div>
        </div>

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
                                <th style="width: 60px;"></th>
                                <th>Status</th>
                                <th>User</th>
                                <th>Device</th>
                                <th>Browser & OS</th>
                                <th>IP Address</th>
                                <th>Last Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $index => $session): ?>
                                <tr class="main-row" onclick="toggleDetails(<?php echo $index; ?>)">
                                    <td>
                                        <button class="expand-btn" id="expand-btn-<?php echo $index; ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </td>
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
                                
                                <!-- Expandable Details Row -->
                                <tr class="details-row" id="details-<?php echo $index; ?>">
                                    <td colspan="7">
                                        <div class="details-content">
                                            <div class="details-grid">
                                                <!-- Battery Information -->
                                                <?php 
                                                $battery = $session['battery_info'] ? json_decode($session['battery_info'], true) : null;
                                                if ($battery): 
                                                ?>
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-battery-three-quarters"></i> Battery Status</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Battery Level</span>
                                                        <span class="detail-value">
                                                            <?php 
                                                            $level = $battery['level'];
                                                            $levelClass = $level > 60 ? 'high' : ($level > 20 ? 'medium' : 'low');
                                                            ?>
                                                            <span class="battery-indicator <?php echo $battery['charging'] ? 'charging' : $levelClass; ?>">
                                                                <i class="fas fa-<?php echo $battery['charging'] ? 'bolt' : 'battery-' . $levelClass; ?>"></i>
                                                                <?php echo $level; ?>%
                                                            </span>
                                                            <div class="battery-bar">
                                                                <div class="battery-fill <?php echo $levelClass; ?>" style="width: <?php echo $level; ?>%"></div>
                                                            </div>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Charging Status</span>
                                                        <span class="detail-value">
                                                            <?php echo $battery['charging'] ? '⚡ Charging' : '🔋 On Battery'; ?>
                                                        </span>
                                                    </div>
                                                    <?php if (!$battery['charging'] && $battery['dischargingTime'] && $battery['dischargingTime'] != 'Infinity'): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Time Remaining</span>
                                                        <span class="detail-value">
                                                            <?php echo round($battery['dischargingTime'] / 60); ?> minutes
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>

                                                <!-- Network Information -->
                                                <?php 
                                                $connection = $session['connection_info'] ? json_decode($session['connection_info'], true) : null;
                                                if ($connection): 
                                                ?>
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-wifi"></i> Network Connection</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Connection Type</span>
                                                        <span class="detail-value">
                                                            <?php 
                                                            $effectiveType = strtoupper($connection['effectiveType']);
                                                            $speedClass = in_array($effectiveType, ['4G', '5G']) ? 'fast' : 
                                                                         ($effectiveType == '3G' ? 'medium' : 'slow');
                                                            ?>
                                                            <span class="speed-indicator <?php echo $speedClass; ?>">
                                                                <i class="fas fa-signal"></i>
                                                                <?php echo $effectiveType; ?>
                                                            </span>
                                                        </span>
                                                    </div>
                                                    <?php if ($connection['downlink'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Download Speed</span>
                                                        <span class="detail-value">
                                                            <?php echo number_format($connection['downlink'], 1); ?> Mbps
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($connection['rtt'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Latency (RTT)</span>
                                                        <span class="detail-value">
                                                            <?php echo $connection['rtt']; ?> ms
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Data Saver</span>
                                                        <span class="detail-value">
                                                            <?php echo $connection['saveData'] ? '✅ Enabled' : '❌ Disabled'; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <!-- Screen & Display Information -->
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-desktop"></i> Screen & Display</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Screen Resolution</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['screen_resolution'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Color Depth</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['color_depth'] ?? 'N/A'); ?> bit
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Pixel Ratio</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['pixel_ratio'] ?? '1'); ?>x
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- System Information -->
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-microchip"></i> System Hardware</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">CPU Cores</span>
                                                        <span class="detail-value">
                                                            <?php echo $session['cpu_cores'] ? $session['cpu_cores'] . ' cores' : 'N/A'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Platform</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['platform'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Timezone</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['timezone'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Language</span>
                                                        <span class="detail-value">
                                                            <?php echo htmlspecialchars($session['language'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Session Information -->
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-clock"></i> Session Details</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Login Time</span>
                                                        <span class="detail-value">
                                                            <?php echo date('M d, Y H:i:s', strtotime($session['login_time'])); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Last Activity</span>
                                                        <span class="detail-value">
                                                            <?php echo date('M d, Y H:i:s', strtotime($session['last_activity'])); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Session Duration</span>
                                                        <span class="detail-value">
                                                            <?php 
                                                            $login = strtotime($session['login_time']);
                                                            $last = strtotime($session['last_activity']);
                                                            $duration = $last - $login;
                                                            
                                                            $hours = floor($duration / 3600);
                                                            $minutes = floor(($duration % 3600) / 60);
                                                            echo $hours . 'h ' . $minutes . 'm';
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($session['current_page']): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Current Page</span>
                                                        <span class="detail-value" style="font-size: 11px; max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                            <?php echo htmlspecialchars(basename($session['current_page'])); ?>
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($session['referrer'] && $session['referrer'] != 'direct'): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Referrer</span>
                                                        <span class="detail-value" style="font-size: 11px;">
                                                            <?php echo htmlspecialchars(parse_url($session['referrer'], PHP_URL_HOST) ?? 'Direct'); ?>
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Device Fingerprint -->
                                                <div class="detail-section">
                                                    <h4><i class="fas fa-fingerprint"></i> Device Fingerprint</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Device Serial</span>
                                                        <span class="detail-value" style="font-family: 'Courier New', monospace; font-size: 11px;">
                                                            <?php echo htmlspecialchars($session['device_serial'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">User Agent</span>
                                                        <span class="detail-value" style="font-size: 10px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($session['user_agent']); ?>">
                                                            <?php echo htmlspecialchars(substr($session['user_agent'], 0, 30)) . '...'; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <h3>No Active Sessions</h3>
                        <p>No users are currently logged in</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Toggle expandable row
        function toggleDetails(index) {
            const detailsRow = document.getElementById('details-' + index);
            const expandBtn = document.getElementById('expand-btn-' + index);
            
            if (detailsRow.classList.contains('show')) {
                detailsRow.classList.remove('show');
                expandBtn.classList.remove('expanded');
            } else {
                detailsRow.classList.add('show');
                expandBtn.classList.add('expanded');
            }
        }

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
                            'rgba(124, 58, 237, 0.8)',
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
                                    family: "'Inter', sans-serif"
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
                        backgroundColor: 'rgba(124, 58, 237, 0.8)',
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
                                    family: "'Inter', sans-serif"
                                }
                            }
                        }
                    }
                }
            });
        }

        // Enhanced Export to CSV with all data
function exportToCSV() {
    // Get the PHP sessions data
    const sessions = <?php echo json_encode($sessions); ?>;
    
    if (!sessions || sessions.length === 0) {
        alert('No data to export');
        return;
    }

    let csv = [];
    
    // Comprehensive Headers
    const headers = [
        'Status',
        'Username',
        'First Name',
        'Last Name',
        'Email',
        'Role',
        'Department',
        'Device Type',
        'Device Serial',
        'Browser',
        'Browser Version',
        'Operating System',
        'OS Version',
        'IP Address',
        'Network Type',
        'Screen Resolution',
        'Color Depth',
        'Pixel Ratio',
        'CPU Cores',
        'Platform',
        'Timezone',
        'Language',
        'Battery Level (%)',
        'Battery Charging',
        'Battery Time Remaining (min)',
        'Connection Type',
        'Download Speed (Mbps)',
        'Latency (ms)',
        'Data Saver',
        'Login Time',
        'Last Activity',
        'Session Duration',
        'Current Page',
        'Referrer',
        'User Agent'
    ];
    
    csv.push(headers.join(','));

    // Process each session
    sessions.forEach(session => {
        // Parse JSON fields
        const battery = session.battery_info ? JSON.parse(session.battery_info) : null;
        const connection = session.connection_info ? JSON.parse(session.connection_info) : null;
        
        // Calculate session duration
        const loginTime = new Date(session.login_time).getTime();
        const lastActivity = new Date(session.last_activity).getTime();
        const durationMs = lastActivity - loginTime;
        const hours = Math.floor(durationMs / 3600000);
        const minutes = Math.floor((durationMs % 3600000) / 60000);
        const durationStr = `${hours}h ${minutes}m`;
        
        // Battery time remaining
        let batteryTimeRemaining = 'N/A';
        if (battery && !battery.charging && battery.dischargingTime && battery.dischargingTime !== 'Infinity') {
            batteryTimeRemaining = Math.round(battery.dischargingTime / 60);
        }
        
        // Build row data
        const row = [
            session.status || '',
            session.username || '',
            session.first_name || '',
            session.last_name || '',
            session.email || '',
            session.role || '',
            session.department || '',
            session.device_type || '',
            session.device_serial || '',
            session.browser_name || '',
            session.browser_version || '',
            session.os_name || '',
            session.os_version || '',
            session.ip_address || '',
            session.network_type || '',
            session.screen_resolution || '',
            session.color_depth ? session.color_depth + ' bit' : '',
            session.pixel_ratio || '',
            session.cpu_cores || '',
            session.platform || '',
            session.timezone || '',
            session.language || '',
            battery ? battery.level : '',
            battery ? (battery.charging ? 'Yes' : 'No') : '',
            batteryTimeRemaining,
            connection ? connection.effectiveType : '',
            connection ? connection.downlink : '',
            connection ? connection.rtt : '',
            connection ? (connection.saveData ? 'Yes' : 'No') : '',
            session.login_time || '',
            session.last_activity || '',
            durationStr,
            session.current_page || '',
            session.referrer || '',
            session.user_agent || ''
        ];
        
        // Escape and format for CSV
        const escapedRow = row.map(cell => {
            const cellStr = String(cell);
            // Escape quotes and wrap in quotes if contains comma, quote, or newline
            if (cellStr.includes(',') || cellStr.includes('"') || cellStr.includes('\n')) {
                return '"' + cellStr.replace(/"/g, '""') + '"';
            }
            return cellStr;
        });
        
        csv.push(escapedRow.join(','));
    });

    // Create and download CSV file
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `user_sessions_detailed_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    
    // Show success message
    alert('CSV exported successfully with all data including battery and network information!');
}

        // Auto-refresh every 60 seconds
        setTimeout(() => {
            location.reload();
        }, 60000);

        // Update refresh indicator
        let countdown = 60;
        setInterval(() => {
            countdown--;
            if (countdown <= 0) countdown = 60;
            const indicator = document.querySelector('.auto-refresh-indicator span');
            if (indicator) {
                indicator.textContent = `Auto-refresh: ${countdown}s`;
            }
        }, 1000);
    </script>
</body>
</html>