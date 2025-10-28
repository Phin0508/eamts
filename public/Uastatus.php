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

// Debug: Log browser detection
error_log("Browsers detected: " . print_r($browsers, true));
error_log("Sessions count: " . count($sessions));
error_log("Online sessions: " . $online_users);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced User Activity - E-Asset Management</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="../js/deviceTracker.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }

        .dashboard-header {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header h1 {
            font-size: 2.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .dashboard-header p {
            color: #6b7280;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 0 16px 0 100%;
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .stat-icon.users { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-icon.online { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        .stat-icon.devices { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .stat-icon.sessions { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white; }

        .stat-content h3 {
            font-size: 2.5rem;
            color: #1f2937;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .stat-content p {
            color: #6b7280;
            font-size: 1rem;
            font-weight: 500;
        }

        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .chart-card h3 {
            color: #1f2937;
            margin-bottom: 1.5rem;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-card h3 i {
            color: #667eea;
        }

        .sessions-table {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .table-header h2 {
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-refresh {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-refresh:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .btn-export {
            background: white;
            color: #667eea;
        }

        .btn-export:hover {
            background: #f3f4f6;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            background: #f9fafb;
            padding: 1.25rem;
            text-align: left;
            font-weight: 700;
            color: #374151;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
        }

        tbody td {
            padding: 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .user-details h4 {
            font-size: 0.95rem;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .user-details span {
            font-size: 0.8rem;
            color: #6b7280;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .status-badge.online {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.offline {
            background: #fee2e2;
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
        }

        .status-badge.offline .status-dot {
            background: #ef4444;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(0.95); }
        }

        .device-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .device-badge.desktop {
            background: #dbeafe;
            color: #1e40af;
        }

        .device-badge.mobile {
            background: #fce7f3;
            color: #9f1239;
        }

        .device-badge.tablet {
            background: #fef3c7;
            color: #92400e;
        }

        .tech-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f3f4f6;
            color: #4b5563;
            margin: 0.2rem;
        }

        .ip-badge {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            background: #f3f4f6;
            border-radius: 6px;
            display: inline-block;
        }

        .network-type {
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
        }

        .network-type.public {
            background: #d1fae5;
            color: #065f46;
        }

        .network-type.private {
            background: #dbeafe;
            color: #1e40af;
        }

        .network-type.localhost {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.85rem;
            }

            .user-info {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        .auto-refresh-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: white;
            font-size: 0.85rem;
        }

        .refresh-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-header">
            <h1><i class="fas fa-chart-line"></i> User Activity Dashboard</h1>
            <p>Real-time monitoring of user sessions, devices, and system activity</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $total_users; ?></h3>
                    <p>Active Users</p>
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
                <div class="stat-icon sessions">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($sessions); ?></h3>
                    <p>Total Sessions</p>
                </div>
            </div>
        </div>

        <div class="charts-container">
            <div class="chart-card">
                <h3><i class="fas fa-laptop"></i> Device Types</h3>
                <canvas id="deviceChart"></canvas>
            </div>

            <div class="chart-card">
                <h3><i class="fas fa-browser"></i> Browsers</h3>
                <canvas id="browserChart"></canvas>
            </div>

            <div class="chart-card">
                <h3><i class="fas fa-desktop"></i> Operating Systems</h3>
                <canvas id="osChart"></canvas>
            </div>
        </div>

        <div class="sessions-table">
            <div class="table-header">
                <h2>
                    <i class="fas fa-users-cog"></i>
                    Active Sessions
                </h2>
                <div class="header-actions">
                    <div class="auto-refresh-indicator">
                        <div class="refresh-spinner"></div>
                        <span>Auto-refresh: 30s</span>
                    </div>
                    <button class="btn btn-refresh" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i>
                        Refresh Now
                    </button>
                    <button class="btn btn-export" onclick="exportToCSV()">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <?php if (count($sessions) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>STATUS</th>
                                <th>USER</th>
                                <th>DEVICE</th>
                                <th>BROWSER & OS</th>
                                <th>IP ADDRESS</th>
                                <th>LOCATION</th>
                                <th>LAST ACTIVE</th>
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
                                                <span>@<?php echo htmlspecialchars($session['username']); ?> • <?php echo htmlspecialchars($session['role']); ?></span>
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
                                        <div style="margin-top: 0.5rem; font-size: 0.75rem; color: #6b7280;">
                                            <?php echo htmlspecialchars(substr($session['device_serial'], 0, 20)); ?>
                                        </div>
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
                                            <div style="font-size: 0.85rem;">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($session['timezone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['language']): ?>
                                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
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