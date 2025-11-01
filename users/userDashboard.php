<?php
// Start output buffering to prevent header errors
ob_start();

// Start session
session_start();

// Check if user is logged in - MUST BE BEFORE ANY OUTPUT
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

// Initialize statistics
$stats = [
    'my_assets' => 0,
    'total_tickets' => 0,
    'open_tickets' => 0,
    'resolved_tickets' => 0,
    'in_progress_tickets' => 0,
    'urgent_tickets' => 0,
    'pending_tickets' => 0
];

// Asset statistics by status
$my_asset_status_data = [];
// Ticket statistics
$my_ticket_status_data = [];
$my_ticket_priority_data = [];

try {
    // Get user's assets count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $stats['my_assets'] = $stmt->fetchColumn();

    // Get user's asset status distribution
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM assets WHERE assigned_to = ? GROUP BY status");
    $stmt->execute([$user_id]);
    $my_asset_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total tickets created by user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ?");
    $stmt->execute([$user_id]);
    $stats['total_tickets'] = $stmt->fetchColumn();

    // Get open tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ? AND status = 'open'");
    $stmt->execute([$user_id]);
    $stats['open_tickets'] = $stmt->fetchColumn();

    // Get in progress tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ? AND status = 'in_progress'");
    $stmt->execute([$user_id]);
    $stats['in_progress_tickets'] = $stmt->fetchColumn();

    // Get pending tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $stats['pending_tickets'] = $stmt->fetchColumn();

    // Get resolved tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ? AND status = 'resolved'");
    $stmt->execute([$user_id]);
    $stats['resolved_tickets'] = $stmt->fetchColumn();

    // Get urgent tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id = ? AND priority = 'urgent'");
    $stmt->execute([$user_id]);
    $stats['urgent_tickets'] = $stmt->fetchColumn();

    // Get ticket status distribution
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE requester_id = ? GROUP BY status");
    $stmt->execute([$user_id]);
    $my_ticket_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get ticket priority distribution
    $stmt = $pdo->prepare("SELECT priority, COUNT(*) as count FROM tickets WHERE requester_id = ? GROUP BY priority");
    $stmt->execute([$user_id]);
    $my_ticket_priority_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
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

// Fetch user's recent tickets
$recent_tickets = [];
try {
    $stmt = $pdo->prepare("
        SELECT ticket_number, subject, status, priority, created_at, id
        FROM tickets 
        WHERE requester_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent tickets error: " . $e->getMessage());
}

// Prepare data for JavaScript charts
$asset_status_labels = json_encode(array_column($my_asset_status_data, 'status'));
$asset_status_values = json_encode(array_column($my_asset_status_data, 'count'));

$ticket_status_labels = json_encode(array_column($my_ticket_status_data, 'status'));
$ticket_status_values = json_encode(array_column($my_ticket_status_data, 'count'));

$ticket_priority_labels = json_encode(array_column($my_ticket_priority_data, 'priority'));
$ticket_priority_values = json_encode(array_column($my_ticket_priority_data, 'count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - E-Asset Management</title>
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

        /* CTA Section */
        .cta-section {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .cta-section h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .cta-section p {
            opacity: 0.95;
            margin-bottom: 24px;
            font-size: 15px;
        }

        .btn-create-ticket {
            background: white;
            color: #7c3aed;
            padding: 14px 32px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-create-ticket:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
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

        /* Asset List */
        .asset-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .asset-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .asset-item:hover {
            background: #fafbfc;
        }

        .asset-item:last-child {
            border-bottom: none;
        }

        .asset-info {
            flex: 1;
        }

        .asset-info h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 6px;
        }

        .asset-info p {
            color: #718096;
            font-size: 13px;
            margin: 0;
        }

        /* Ticket List */
        .ticket-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .ticket-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
        }

        .ticket-item:hover {
            background: #fafbfc;
        }

        .ticket-item:last-child {
            border-bottom: none;
        }

        .ticket-info {
            flex: 1;
        }

        .ticket-info h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 6px;
        }

        .ticket-info p {
            color: #718096;
            font-size: 13px;
            margin: 0;
        }

        .ticket-badges {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
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

        .priority-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .priority-low {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .priority-medium {
            background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
            color: #92400e;
        }

        .priority-high {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
        }

        .priority-urgent {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            letter-spacing: 0.3px;
        }

        .status-open {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .status-in-progress,
        .status-in_progress {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .status-pending {
            background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%);
            color: #854d0e;
        }

        .status-resolved {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .status-closed {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
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
            background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
            color: #92400e;
        }

        .status-retired {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }

        .status-damaged {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
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

        /* Quick Actions */
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
            margin-bottom: 20px;
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

            .cta-section {
                padding: 30px 20px;
            }

            .ticket-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .ticket-badges {
                width: 100%;
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
    <?php include("../auth/inc/Usidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <h1><i class="fas fa-home"></i> My Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>! Here's your asset and ticket overview</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                <i class="fas fa-boxes stat-icon"></i>
                <div class="stat-number"><?php echo $stats['my_assets']; ?></div>
                <div class="stat-label">My Assets</div>
            </div>

            <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                <i class="fas fa-ticket-alt stat-icon"></i>
                <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>

            <div class="stat-card" onclick="window.location.href='../public/tickets.php?filter=open'">
                <i class="fas fa-folder-open stat-icon"></i>
                <div class="stat-number"><?php echo $stats['open_tickets']; ?></div>
                <div class="stat-label">Open Tickets</div>
            </div>

            <div class="stat-card" onclick="window.location.href='../public/tickets.php?filter=urgent'">
                <i class="fas fa-exclamation-circle stat-icon"></i>
                <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                <div class="stat-label">Urgent Tickets</div>
            </div>
        </div>

        <!-- Call to Action -->
        <div class="cta-section">
            <h2>Need Help or Support?</h2>
            <p>Create a ticket for repairs, maintenance, new requests, or any inquiries</p>
            <a href="../users/userCreateticket.php" class="btn-create-ticket">
                <i class="fas fa-plus-circle"></i> Create New Ticket
            </a>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- My Asset Status Chart -->
            <?php if (count($my_asset_status_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-pie"></i> My Assets Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="myAssetStatusChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Ticket Status Chart -->
            <?php if (count($my_ticket_status_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-ticket-alt"></i> My Ticket Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Ticket Priority Chart -->
            <?php if (count($my_ticket_priority_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> Tickets by Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- My Recent Assets -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-boxes"></i> My Recent Assets</h2>
                <p>Assets recently assigned to you</p>
            </div>

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
                    <a href="../public/asset.php" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> View All My Assets
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

        <!-- My Recent Tickets -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-ticket-alt"></i> My Recent Tickets</h2>
                <p>Your latest ticket submissions</p>
            </div>

            <?php if (count($recent_tickets) > 0): ?>
                <ul class="ticket-list">
                    <?php foreach ($recent_tickets as $ticket): ?>
                        <li class="ticket-item" onclick="window.location.href='../public/ticketDetails.php?id=<?php echo $ticket['id']; ?>'">
                            <div class="ticket-info">
                                <h4><?php echo htmlspecialchars($ticket['ticket_number']); ?></h4>
                                <p><?php echo htmlspecialchars($ticket['subject']); ?></p>
                            </div>
                            <div class="ticket-badges">
                                <span class="priority-badge priority-<?php echo strtolower($ticket['priority']); ?>">
                                    <?php echo htmlspecialchars($ticket['priority']); ?>
                                </span>
                                <span class="status-badge status-<?php echo strtolower($ticket['status']); ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="quick-actions">
                    <a href="../public/tickets.php" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> View All My Tickets
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3>No Tickets Created</h3>
                    <p>You haven't created any tickets yet.</p>
                    <a href="../users/userCreateticket.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Create Your First Ticket
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Statistics -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-chart-line"></i> Quick Statistics</h2>
                <p>Overview of your tickets and assets</p>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="label"><i class="fas fa-folder-open"></i> Open Tickets</span>
                    <span class="value"><?php echo $stats['open_tickets']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-spinner"></i> In Progress</span>
                    <span class="value"><?php echo $stats['in_progress_tickets']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-clock"></i> Pending</span>
                    <span class="value"><?php echo $stats['pending_tickets']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-check-circle"></i> Resolved</span>
                    <span class="value"><?php echo $stats['resolved_tickets']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-exclamation-circle"></i> Urgent Tickets</span>
                    <span class="value"><?php echo $stats['urgent_tickets']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="label"><i class="fas fa-boxes"></i> Total Assets</span>
                    <span class="value"><?php echo $stats['my_assets']; ?></span>
                </div>
            </div>
        </div>

        <!-- Account Information -->
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

        <?php if (count($my_asset_status_data) > 0): ?>
            // My Asset Status Chart
            const myAssetStatusCtx = document.getElementById('myAssetStatusChart');
            if (myAssetStatusCtx) {
                const myAssetStatusLabels = <?php echo $asset_status_labels; ?>;
                const myAssetStatusData = <?php echo $asset_status_values; ?>;

                new Chart(myAssetStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: myAssetStatusLabels,
                        datasets: [{
                            data: myAssetStatusData,
                            backgroundColor: myAssetStatusLabels.map(label => statusColors[label] || '#6b7280'),
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
        <?php endif; ?>

        <?php if (count($my_ticket_status_data) > 0): ?>
            // Ticket Status Chart
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
        <?php endif; ?>

        <?php if (count($my_ticket_priority_data) > 0): ?>
            // Ticket Priority Chart
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
        <?php endif; ?>
    </script>
</body>
</html>
<?php
// Flush output buffer at the end
ob_end_flush();
?>