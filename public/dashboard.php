<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
include("../auth/config/database.php");

// Get user information from session
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$username = $_SESSION['username'];
$email = $_SESSION['email'];
$role = $_SESSION['role'];
$department = $_SESSION['department'];
$user_id = $_SESSION['user_id'];
$login_time = isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'Unknown';

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    header("Location: login.php?message=logged_out");
    exit();
}

// Fetch real statistics from database
$stats = [
    'my_assets' => 0,
    'pending_requests' => 0,
    'maintenance_due' => 0,
    'total_assets' => 0,
    'total_tickets' => 0,
    'open_tickets' => 0,
    'resolved_tickets' => 0,
    'urgent_tickets' => 0
];

// Asset statistics by status for pie chart
$asset_status_data = [];
// Asset statistics by category for bar chart
$asset_category_data = [];
// Ticket statistics by status for pie chart
$ticket_status_data = [];
// Ticket statistics by priority for bar chart
$ticket_priority_data = [];

try {
    // Get assets assigned to current user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $stats['my_assets'] = $stmt->fetchColumn();

    // Get pending verification requests (only for admin)
    if ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_verified = 0 AND is_active = 1");
        $stmt->execute();
        $stats['pending_requests'] = $stmt->fetchColumn();
    }

    // Get assets in maintenance status
    if ($role === 'admin' || $role === 'manager') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE status = 'Maintenance'");
        $stmt->execute();
        $stats['maintenance_due'] = $stmt->fetchColumn();

        // Get total assets count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets");
        $stmt->execute();
        $stats['total_assets'] = $stmt->fetchColumn();

        // Get asset status distribution
        $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM assets GROUP BY status");
        $stmt->execute();
        $asset_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get asset category distribution
        $stmt = $pdo->prepare("SELECT category, COUNT(*) as count FROM assets GROUP BY category ORDER BY count DESC LIMIT 10");
        $stmt->execute();
        $asset_category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // For regular users, show their department's maintenance assets
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE status = 'Maintenance' AND department = ?");
        $stmt->execute([$department]);
        $stats['maintenance_due'] = $stmt->fetchColumn();
    }

    // Get recent asset history count (as reports)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM asset_history WHERE performed_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$user_id]);
    $stats['reports'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// TICKET STATISTICS
try {
    // Build ticket query based on role
    $ticket_base_where = "";
    $ticket_params = [];

    if ($role === 'employee') {
        $ticket_base_where = "WHERE t.requester_id = ?";
        $ticket_params = [$user_id];
    } elseif ($role === 'manager') {
        $ticket_base_where = "WHERE (t.requester_department = (SELECT department FROM users WHERE user_id = ?) OR t.assigned_to = ?)";
        $ticket_params = [$user_id, $user_id];
    } elseif ($role === 'admin') {
        // Admins see only approved tickets
        $ticket_base_where = "WHERE t.approval_status = 'approved'";
        $ticket_params = [];
    }

    // Get total tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where");
    $stmt->execute($ticket_params);
    $stats['total_tickets'] = $stmt->fetchColumn();

    // Get open tickets
    $where_and = $ticket_base_where ? "AND" : "WHERE";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.status = 'open'");
    $stmt->execute($ticket_params);
    $stats['open_tickets'] = $stmt->fetchColumn();

    // Get resolved tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.status = 'resolved'");
    $stmt->execute($ticket_params);
    $stats['resolved_tickets'] = $stmt->fetchColumn();

    // Get urgent tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.priority = 'urgent'");
    $stmt->execute($ticket_params);
    $stats['urgent_tickets'] = $stmt->fetchColumn();

    // Get ticket status distribution
    $stmt = $pdo->prepare("SELECT t.status, COUNT(*) as count FROM tickets t $ticket_base_where GROUP BY t.status");
    $stmt->execute($ticket_params);
    $ticket_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get ticket priority distribution
    $stmt = $pdo->prepare("SELECT t.priority, COUNT(*) as count FROM tickets t $ticket_base_where GROUP BY t.priority");
    $stmt->execute($ticket_params);
    $ticket_priority_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ticket statistics error: " . $e->getMessage());
}

// Fetch user's recent assets
$recent_assets = [];
try {
    $stmt = $pdo->prepare("
        SELECT asset_name, asset_code, category, status, created_at 
        FROM assets 
        WHERE assigned_to = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent assets error: " . $e->getMessage());
}

// Fetch recent activity for the user
$recent_activity = [];
try {
    $stmt = $pdo->prepare("
        SELECT ah.*, a.asset_name, a.asset_code 
        FROM asset_history ah
        JOIN assets a ON ah.asset_id = a.id
        WHERE ah.performed_by = ? 
        ORDER BY ah.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent activity error: " . $e->getMessage());
}

// Prepare data for JavaScript charts
$asset_status_labels = json_encode(array_column($asset_status_data, 'status'));
$asset_status_values = json_encode(array_column($asset_status_data, 'count'));

$asset_category_labels = json_encode(array_column($asset_category_data, 'category'));
$asset_category_values = json_encode(array_column($asset_category_data, 'count'));

$ticket_status_labels = json_encode(array_column($ticket_status_data, 'status'));
$ticket_status_values = json_encode(array_column($ticket_status_data, 'count'));

$ticket_priority_labels = json_encode(array_column($ticket_priority_data, 'priority'));
$ticket_priority_values = json_encode(array_column($ticket_priority_data, 'count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - E-Asset</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
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
        .main-content {
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            padding: 30px;
        }

        .main-content.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .welcome-section h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-message {
            font-size: 16px;
            opacity: 0.95;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-icon {
            font-size: 36px;
            margin-bottom: 16px;
            display: block;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .chart-card h3 {
            margin: 0 0 24px 0;
            color: #1a202c;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .chart-container canvas {
            max-height: 300px;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .card h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Details Grid */
        .details-grid {
            display: grid;
            gap: 20px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 16px;
            background: #f7fafc;
            border-radius: 10px;
            transition: background 0.2s;
        }

        .detail-item:hover {
            background: #edf2f7;
        }

        .detail-item .label {
            font-weight: 600;
            color: #4a5568;
        }

        .detail-item .value {
            color: #1a202c;
            font-weight: 500;
        }

        /* Asset List */
        .asset-list {
            list-style: none;
            margin-bottom: 20px;
        }

        .asset-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
        }

        .asset-item:hover {
            background: #fafbfc;
        }

        .asset-item:last-child {
            border-bottom: none;
        }

        .asset-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .asset-info p {
            font-size: 13px;
            color: #718096;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-available {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .status-in-use {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .status-maintenance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .status-retired {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
            align-items: center;
        }

        .activity-item:hover {
            background: #fafbfc;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-type {
            font-weight: 600;
            color: #7c3aed;
            text-transform: capitalize;
            font-size: 14px;
        }

        .activity-details {
            color: #4a5568;
            font-size: 14px;
        }

        .activity-time {
            color: #a0aec0;
            font-size: 13px;
            white-space: nowrap;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        .action-btn {
            padding: 12px 24px;
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
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .no-data i {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 16px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 80px;
            }

            .charts-section {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .welcome-section {
                padding: 30px 24px;
            }

            .welcome-section h1 {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-section {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .activity-item {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .quick-actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-section">
            <h1>👋 Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</h1>
            <p class="welcome-message">Here's what's happening with your assets and tickets today.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                <span class="stat-icon">📦</span>
                <div class="stat-number"><?php echo $stats['my_assets']; ?></div>
                <div class="stat-label">My Assets</div>
            </div>

            <?php if ($role === 'admin'): ?>
                <div class="stat-card" onclick="window.location.href='../public/userV.php'">
                    <span class="stat-icon">👥</span>
                    <div class="stat-number"><?php echo $stats['pending_requests']; ?></div>
                    <div class="stat-label">Pending Verifications</div>
                </div>
            <?php else: ?>
                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <span class="stat-icon">🎫</span>
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                </div>
            <?php endif; ?>

            <div class="stat-card" onclick="window.location.href='../public/assetDetails.php'">
                <span class="stat-icon">🔧</span>
                <div class="stat-number"><?php echo $stats['maintenance_due']; ?></div>
                <div class="stat-label">Maintenance Status</div>
            </div>

            <?php if ($role === 'admin' || $role === 'manager'): ?>
                <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                    <span class="stat-icon">📊</span>
                    <div class="stat-number"><?php echo $stats['total_assets']; ?></div>
                    <div class="stat-label">Total Assets</div>
                </div>
            <?php else: ?>
                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <span class="stat-icon">⚡</span>
                    <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                    <div class="stat-label">Urgent Tickets</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Charts Section -->
        <?php if ($role === 'admin' || $role === 'manager'): ?>
            <div class="charts-section">
                <!-- Asset Status Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Asset Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="assetStatusChart"></canvas>
                    </div>
                </div>

                <!-- Asset Category Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Assets by Category</h3>
                    <div class="chart-container">
                        <canvas id="assetCategoryChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Status Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-ticket-alt"></i> Ticket Status Overview</h3>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Priority Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-exclamation-triangle"></i> Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="charts-section">
                <!-- Ticket Status Chart for Employees -->
                <div class="chart-card">
                    <h3><i class="fas fa-ticket-alt"></i> My Ticket Status</h3>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Priority Chart for Employees -->
                <div class="chart-card">
                    <h3><i class="fas fa-exclamation-triangle"></i> My Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <div class="card">
                <h2><i class="fas fa-user-circle"></i> Your Account Information</h2>
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="label">Full Name:</span>
                        <span class="value"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Username:</span>
                        <span class="value"><?php echo htmlspecialchars($username); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Email:</span>
                        <span class="value"><?php echo htmlspecialchars($email); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Department:</span>
                        <span class="value"><?php echo htmlspecialchars($department); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Role:</span>
                        <span class="value"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Login Time:</span>
                        <span class="value"><?php echo htmlspecialchars($login_time); ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-boxes"></i> My Recent Assets</h2>
                <?php if (count($recent_assets) > 0): ?>
                    <ul class="asset-list">
                        <?php foreach ($recent_assets as $asset): ?>
                            <li class="asset-item">
                                <div class="asset-info">
                                    <h4><?php echo htmlspecialchars($asset['asset_name']); ?></h4>
                                    <p><?php echo htmlspecialchars($asset['asset_code']); ?> • <?php echo htmlspecialchars($asset['category']); ?></p>
                                </div>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                    <?php echo htmlspecialchars($asset['status']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="quick-actions">
                        <a href="../public/asset.php" class="action-btn btn-primary">
                            <i class="fas fa-arrow-right"></i> View All Assets
                        </a>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-box-open"></i>
                        <p>No assets assigned to you yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($recent_activity) > 0): ?>
            <div class="card">
                <h2><i class="fas fa-history"></i> Recent Activity</h2>
                <ul class="activity-list">
                    <?php foreach ($recent_activity as $activity): ?>
                        <li class="activity-item">
                            <div class="activity-type"><?php echo ucfirst(htmlspecialchars($activity['action_type'])); ?></div>
                            <div class="activity-details">
                                <?php echo htmlspecialchars($activity['asset_name']); ?>
                                (<?php echo htmlspecialchars($activity['asset_code']); ?>)
                            </div>
                            <div class="activity-time"><?php echo date('M j, Y H:i', strtotime($activity['created_at'])); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            <div class="card">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <div class="quick-actions" style="border-top: none; padding-top: 0;">
                    <a href="../public/asset.php" class="action-btn btn-primary">
                        <i class="fas fa-boxes"></i> Manage Assets
                    </a>
                    <?php if ($role === 'admin'): ?>
                        <a href="../public/userV.php" class="action-btn btn-primary">
                            <i class="fas fa-user-check"></i> Verify Users
                        </a>
                        <a href="../public/ticket.php" class="action-btn btn-primary">
                            <i class="fas fa-ticket-alt"></i> View Approved Tickets
                        </a>
                    <?php else: ?>
                        <a href="../public/tickets.php" class="action-btn btn-primary">
                            <i class="fas fa-ticket-alt"></i> View Tickets
                        </a>
                    <?php endif; ?>
                    <a href="../public/assetHistory.php" class="action-btn btn-secondary">
                        <i class="fas fa-history"></i> View Asset History
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <div class="card">
                <h2><i class="fas fa-circle" style="color: #10b981;"></i> Currently Online Users</h2>
                <?php
                try {
                    $stmt = $pdo->query("
                        SELECT 
                            u.first_name, u.last_name, u.department,
                            us.ip_address, us.device_serial, us.last_activity
                        FROM user_sessions us
                        JOIN users u ON us.user_id = u.user_id
                        WHERE us.is_active = 1 
                        AND us.last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                        ORDER BY us.last_activity DESC
                        LIMIT 5
                    ");
                    $online_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($online_users) > 0):
                ?>
                        <ul class="activity-list">
                            <?php foreach ($online_users as $user): ?>
                                <li class="activity-item">
                                    <div class="activity-type">
                                        <i class="fas fa-circle" style="color: #10b981; font-size: 8px;"></i>
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    </div>
                                    <div class="activity-details">
                                        IP: <?php echo htmlspecialchars($user['ip_address']); ?> |
                                        Device: <?php echo htmlspecialchars(substr($user['device_serial'], 0, 12)); ?>...
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('H:i', strtotime($user['last_activity'])); ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="quick-actions">
                            <a href="../public/Uastatus.php" class="action-btn btn-primary">
                                <i class="fas fa-users"></i> View All Active Users
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-user-slash"></i>
                            <p>No users currently online</p>
                        </div>
                    <?php endif; ?>
                <?php } catch (PDOException $e) {
                    error_log("Online users error: " . $e->getMessage());
                } ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Handle sidebar toggle
        function updateMainContent() {
            const mainContent = document.getElementById('mainContent');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContent.classList.add('sidebar-collapsed');
            } else {
                mainContent.classList.remove('sidebar-collapsed');
            }
        }

        // Check on load
        document.addEventListener('DOMContentLoaded', updateMainContent);

        // Listen for sidebar changes
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContent, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContent);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }

        // Color palettes
        const statusColors = {
            'Available': '#10b981',
            'In Use': '#3b82f6',
            'Maintenance': '#f59e0b',
            'Retired': '#6b7280',
            'Damaged': '#ef4444'
        };

        const ticketStatusColors = {
            'open': '#3b82f6',
            'in_progress': '#f59e0b',
            'pending': '#eab308',
            'resolved': '#10b981',
            'closed': '#6b7280'
        };

        const priorityColors = {
            'low': '#10b981',
            'medium': '#f59e0b',
            'high': '#ef4444',
            'urgent': '#dc2626'
        };

        // Chart.js default settings
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#718096';

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            // Asset Status Pie Chart
            const assetStatusCtx = document.getElementById('assetStatusChart');
            if (assetStatusCtx) {
                const assetStatusLabels = <?php echo $asset_status_labels; ?>;
                const assetStatusData = <?php echo $asset_status_values; ?>;

                new Chart(assetStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: assetStatusLabels,
                        datasets: [{
                            data: assetStatusData,
                            backgroundColor: assetStatusLabels.map(label => statusColors[label] || '#6b7280'),
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    font: {
                                        size: 13,
                                        weight: 500
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8
                            }
                        }
                    }
                });
            }

            // Asset Category Bar Chart
            const assetCategoryCtx = document.getElementById('assetCategoryChart');
            if (assetCategoryCtx) {
                const assetCategoryLabels = <?php echo $asset_category_labels; ?>;
                const assetCategoryData = <?php echo $asset_category_values; ?>;

                new Chart(assetCategoryCtx, {
                    type: 'bar',
                    data: {
                        labels: assetCategoryLabels,
                        datasets: [{
                            label: 'Number of Assets',
                            data: assetCategoryData,
                            backgroundColor: 'rgba(124, 58, 237, 0.8)',
                            borderColor: 'rgba(124, 58, 237, 1)',
                            borderWidth: 0,
                            borderRadius: 8,
                            hoverBackgroundColor: 'rgba(109, 40, 217, 0.9)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8
                            }
                        }
                    }
                });
            }
        <?php endif; ?>

        // Ticket Status Pie Chart
        const ticketStatusCtx = document.getElementById('ticketStatusChart');
        if (ticketStatusCtx) {
            const ticketStatusLabels = <?php echo $ticket_status_labels; ?>;
            const ticketStatusData = <?php echo $ticket_status_values; ?>;

            new Chart(ticketStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ticketStatusLabels.map(label => label.replace('_', ' ').toUpperCase()),
                    datasets: [{
                        data: ticketStatusData,
                        backgroundColor: ticketStatusLabels.map(label => ticketStatusColors[label] || '#6b7280'),
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: {
                                    size: 13,
                                    weight: 500
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 600
                            },
                            bodyFont: {
                                size: 13
                            },
                            cornerRadius: 8
                        }
                    }
                }
            });
        }

        // Ticket Priority Bar Chart
        const ticketPriorityCtx = document.getElementById('ticketPriorityChart');
        if (ticketPriorityCtx) {
            const ticketPriorityLabels = <?php echo $ticket_priority_labels; ?>;
            const ticketPriorityData = <?php echo $ticket_priority_values; ?>;

            new Chart(ticketPriorityCtx, {
                type: 'bar',
                data: {
                    labels: ticketPriorityLabels.map(label => label.toUpperCase()),
                    datasets: [{
                        label: 'Number of Tickets',
                        data: ticketPriorityData,
                        backgroundColor: ticketPriorityLabels.map(label => priorityColors[label] || '#6b7280'),
                        borderWidth: 0,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 600
                            },
                            bodyFont: {
                                size: 13
                            },
                            cornerRadius: 8
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>