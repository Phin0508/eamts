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
    <title>Dashboard - E-Asset Management</title>
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

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #7c3aed;
        }

        .header p {
            color: #718096;
            font-size: 15px;
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
            border-left: 4px solid #7c3aed;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-icon {
            font-size: 32px;
            color: #7c3aed;
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
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Section */
        .section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .section-header {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h2 i {
            color: #7c3aed;
        }

        .section-header p {
            color: #718096;
            font-size: 14px;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .chart-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
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

        .chart-container {
            position: relative;
            height: 300px;
        }

        .chart-container canvas {
            max-height: 300px;
        }

        /* Details Grid */
        .details-grid {
            display: grid;
            gap: 16px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: #f7fafc;
            border-radius: 10px;
            transition: all 0.2s;
        }

        .detail-item:hover {
            background: #edf2f7;
        }

        .detail-item .label {
            font-weight: 600;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-item .label i {
            color: #7c3aed;
            font-size: 14px;
        }

        .detail-item .value {
            color: #1a202c;
            font-weight: 500;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
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

        /* Asset Info */
        .asset-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .asset-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .asset-details h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
        }

        .asset-details p {
            font-size: 13px;
            color: #718096;
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge.status-available {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .badge.status-in-use {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge.status-maintenance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge.status-retired {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: #fafbfc;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .activity-info {
            flex: 1;
        }

        .activity-type {
            font-weight: 600;
            color: #1a202c;
            text-transform: capitalize;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .activity-details {
            color: #718096;
            font-size: 13px;
        }

        .activity-time {
            color: #a0aec0;
            font-size: 13px;
            white-space: nowrap;
            margin-left: 12px;
        }

        /* Online Status */
        .online-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 20px;
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

        /* Buttons */
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        .btn {
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
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
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

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .container.sidebar-collapsed {
                margin-left: 80px;
            }

            .charts-grid {
                grid-template-columns: 1fr;
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

            .header h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 20px;
            }

            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table {
                min-width: 800px;
            }

            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .activity-time {
                margin-left: 0;
            }

            .quick-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <h1><i class="fas fa-home"></i> Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>! Here's what's happening with your assets and tickets today.</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                <i class="fas fa-boxes stat-icon"></i>
                <div class="stat-number"><?php echo $stats['my_assets']; ?></div>
                <div class="stat-label">My Assets</div>
            </div>

            <?php if ($role === 'admin'): ?>
                <div class="stat-card" onclick="window.location.href='../public/userV.php'">
                    <i class="fas fa-user-check stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['pending_requests']; ?></div>
                    <div class="stat-label">Pending Verifications</div>
                </div>
            <?php else: ?>
                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <i class="fas fa-ticket-alt stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                </div>
            <?php endif; ?>

            <div class="stat-card" onclick="window.location.href='../public/assetDetails.php'">
                <i class="fas fa-tools stat-icon"></i>
                <div class="stat-number"><?php echo $stats['maintenance_due']; ?></div>
                <div class="stat-label">Maintenance Status</div>
            </div>

            <?php if ($role === 'admin' || $role === 'manager'): ?>
                <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                    <i class="fas fa-chart-line stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['total_assets']; ?></div>
                    <div class="stat-label">Total Assets</div>
                </div>
            <?php else: ?>
                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <i class="fas fa-exclamation-circle stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                    <div class="stat-label">Urgent Tickets</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Charts Section -->
        <?php if ($role === 'admin' || $role === 'manager'): ?>
            <div class="charts-grid">
                <!-- Asset Status Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-pie"></i> Asset Status Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="assetStatusChart"></canvas>
                    </div>
                </div>

                <!-- Asset Category Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-bar"></i> Assets by Category</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="assetCategoryChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Status Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-ticket-alt"></i> Ticket Status Overview</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Priority Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> Tickets by Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="charts-grid">
                <!-- Ticket Status Chart for Employees -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-ticket-alt"></i> My Ticket Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>

                <!-- Ticket Priority Chart for Employees -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> My Tickets by Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Account Information Section -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-user-circle"></i> Your Account Information</h2>
                <p>Your profile details and login information</p>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="label"><i class="fas fa-user"></i> Full Name</span>
                    <span class="value"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-at"></i> Username</span>
                    <span class="value"><?php echo htmlspecialchars($username); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="value"><?php echo htmlspecialchars($email); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-building"></i> Department</span>
                    <span class="value"><?php echo htmlspecialchars($department); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-user-tag"></i> Role</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-clock"></i> Login Time</span>
                    <span class="value"><?php echo htmlspecialchars($login_time); ?></span>
                </div>
            </div>
        </div>

        <!-- Recent Assets Section -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-boxes"></i> My Recent Assets</h2>
                <p>Assets recently assigned to you</p>
            </div>

            <?php if (count($recent_assets) > 0): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Added On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_assets as $asset): ?>
                        <tr>
                            <td>
                                <div class="asset-info">
                                    <div class="asset-icon">
                                        <i class="fas fa-box"></i>
                                    </div>
                                    <div class="asset-details">
                                        <h4><?php echo htmlspecialchars($asset['asset_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($asset['asset_code']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($asset['category']); ?></td>
                            <td>
                                <span class="badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                    <?php echo htmlspecialchars($asset['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($asset['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="quick-actions">
                <a href="../public/asset.php" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i> View All Assets
                </a>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3>No Assets Assigned</h3>
                <p>No assets have been assigned to you yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activity Section -->
        <?php if (count($recent_activity) > 0): ?>
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Recent Activity</h2>
                <p>Your recent asset-related activities</p>
            </div>
            <ul class="activity-list">
                <?php foreach ($recent_activity as $activity): ?>
                <li class="activity-item">
                    <div class="activity-content">
                        <div class="activity-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="activity-info">
                            <div class="activity-type"><?php echo ucfirst(htmlspecialchars($activity['action_type'])); ?></div>
                            <div class="activity-details">
                                <?php echo htmlspecialchars($activity['asset_name']); ?>
                                (<?php echo htmlspecialchars($activity['asset_code']); ?>)
                            </div>
                        </div>
                    </div>
                    <div class="activity-time"><?php echo date('M j, Y H:i', strtotime($activity['created_at'])); ?></div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Quick Actions Section -->
        <?php if ($role === 'admin' || $role === 'manager'): ?>
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <p>Frequently used administrative actions</p>
            </div>
            <div class="quick-actions" style="border-top: none; padding-top: 0; margin-top: 0;">
                <a href="../public/asset.php" class="btn btn-primary">
                    <i class="fas fa-boxes"></i> Manage Assets
                </a>
                <?php if ($role === 'admin'): ?>
                    <a href="../public/userV.php" class="btn btn-primary">
                        <i class="fas fa-user-check"></i> Verify Users
                    </a>
                    <a href="../public/ticket.php" class="btn btn-primary">
                        <i class="fas fa-ticket-alt"></i> View Approved Tickets
                    </a>
                <?php else: ?>
                    <a href="../public/tickets.php" class="btn btn-primary">
                        <i class="fas fa-ticket-alt"></i> View Tickets
                    </a>
                <?php endif; ?>
                <a href="../public/assetHistory.php" class="btn btn-secondary">
                    <i class="fas fa-history"></i> View Asset History
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Currently Online Users Section (Admin Only) -->
        <?php if ($role === 'admin'): ?>
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Currently Online Users</h2>
                <p>Users active in the last 5 minutes</p>
            </div>
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
                            <div class="activity-content">
                                <div class="activity-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="activity-info">
                                    <div class="activity-type">
                                        <span class="online-indicator"></span>
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    </div>
                                    <div class="activity-details">
                                        IP: <?php echo htmlspecialchars($user['ip_address']); ?> | 
                                        Device: <?php echo htmlspecialchars(substr($user['device_serial'], 0, 12)); ?>...
                                    </div>
                                </div>
                            </div>
                            <div class="activity-time">
                                <?php echo date('H:i', strtotime($user['last_activity'])); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="quick-actions">
                        <a href="../public/Uastatus.php" class="btn btn-primary">
                            <i class="fas fa-users"></i> View All Active Users
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <h3>No Users Online</h3>
                        <p>No users are currently active in the system.</p>
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
        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        // Check on load
        document.addEventListener('DOMContentLoaded', updateMainContainer);

        // Listen for sidebar changes
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContainer);
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