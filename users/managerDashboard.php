<?php
// Start session
session_start();

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
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
    'department_employees' => 0,
    'department_assets' => 0,
    'department_tickets' => 0,
    'pending_tickets' => 0,
    'maintenance_assets' => 0,
    'my_assets' => 0,
    'urgent_tickets' => 0,
    'active_employees' => 0
];

// Department statistics
$dept_asset_status_data = [];
$dept_ticket_status_data = [];
$dept_ticket_priority_data = [];
$employee_asset_distribution = [];

try {
    // Get department employees count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department = ? AND is_active = 1");
    $stmt->execute([$department]);
    $stats['department_employees'] = $stmt->fetchColumn();

    // Get active employees count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department = ? AND is_active = 1 AND is_verified = 1");
    $stmt->execute([$department]);
    $stats['active_employees'] = $stmt->fetchColumn();

    // Get department assets count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department = ?");
    $stmt->execute([$department]);
    $stats['department_assets'] = $stmt->fetchColumn();

    // Get manager's own assets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $stats['my_assets'] = $stmt->fetchColumn();

    // Get department assets in maintenance
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department = ? AND status = 'Maintenance'");
    $stmt->execute([$department]);
    $stats['maintenance_assets'] = $stmt->fetchColumn();

    // Get department asset status distribution
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM assets WHERE department = ? GROUP BY status");
    $stmt->execute([$department]);
    $dept_asset_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get department tickets (tickets from department or assigned to manager)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_department = ? OR assigned_to = ?");
    $stmt->execute([$department, $user_id]);
    $stats['department_tickets'] = $stmt->fetchColumn();

    // Get pending tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_department = ? AND approval_status = 'pending'");
    $stmt->execute([$department]);
    $stats['pending_tickets'] = $stmt->fetchColumn();

    // Get urgent tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_department = ? AND priority = 'urgent' AND approval_status = 'pending'");
    $stmt->execute([$department]);
    $stats['urgent_tickets'] = $stmt->fetchColumn();

    // Get department ticket status distribution
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE requester_department = ? OR assigned_to = ? GROUP BY status");
    $stmt->execute([$department, $user_id]);
    $dept_ticket_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get department ticket priority distribution
    $stmt = $pdo->prepare("SELECT priority, COUNT(*) as count FROM tickets WHERE requester_department = ? OR assigned_to = ? GROUP BY priority");
    $stmt->execute([$department, $user_id]);
    $dept_ticket_priority_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get employee asset distribution (top 10)
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, COUNT(a.id) as asset_count
        FROM users u
        LEFT JOIN assets a ON u.user_id = a.assigned_to
        WHERE u.department = ? AND u.is_active = 1
        GROUP BY u.user_id, u.first_name, u.last_name
        ORDER BY asset_count DESC
        LIMIT 10
    ");
    $stmt->execute([$department]);
    $employee_asset_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Manager dashboard stats error: " . $e->getMessage());
}

// Fetch department employees
$department_employees = [];
try {
    $stmt = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, username, is_verified, is_active, created_at,
               (SELECT COUNT(*) FROM assets WHERE assigned_to = users.user_id) as asset_count,
               (SELECT COUNT(*) FROM tickets WHERE requester_id = users.user_id) as ticket_count
        FROM users 
        WHERE department = ?
        ORDER BY is_active DESC, first_name ASC
        LIMIT 20
    ");
    $stmt->execute([$department]);
    $department_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Department employees error: " . $e->getMessage());
}

// Fetch recent department tickets
$recent_tickets = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.first_name, u.last_name, a.asset_name, a.asset_code
        FROM tickets t
        JOIN users u ON t.requester_id = u.user_id
        LEFT JOIN assets a ON t.asset_id = a.id
        WHERE t.requester_department = ? OR t.assigned_to = ?
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$department, $user_id]);
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent tickets error: " . $e->getMessage());
}

// Fetch department assets needing attention
$attention_assets = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.first_name, u.last_name
        FROM assets a
        LEFT JOIN users u ON a.assigned_to = u.user_id
        WHERE a.department = ? AND a.status IN ('Maintenance', 'Damaged')
        ORDER BY a.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$department]);
    $attention_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Attention assets error: " . $e->getMessage());
}

// Prepare data for JavaScript charts
$asset_status_labels = json_encode(array_column($dept_asset_status_data, 'status'));
$asset_status_values = json_encode(array_column($dept_asset_status_data, 'count'));

$ticket_status_labels = json_encode(array_column($dept_ticket_status_data, 'status'));
$ticket_status_values = json_encode(array_column($dept_ticket_status_data, 'count'));

$ticket_priority_labels = json_encode(array_column($dept_ticket_priority_data, 'priority'));
$ticket_priority_values = json_encode(array_column($dept_ticket_priority_data, 'count'));

$employee_names = json_encode(array_map(function ($emp) {
    return $emp['first_name'] . ' ' . substr($emp['last_name'], 0, 1) . '.';
}, $employee_asset_distribution));
$employee_assets = json_encode(array_column($employee_asset_distribution, 'asset_count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - E-Asset Management</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header p {
            opacity: 0.95;
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
            border-left: 4px solid #667eea;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
        }

        .stat-icon {
            font-size: 32px;
            color: #667eea;
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

        .stat-number-small {
            font-size: 13px;
            color: #a0aec0;
            margin-top: 8px;
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
            color: #667eea;
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
            color: #667eea;
        }

        .section-header p {
            color: #718096;
            font-size: 14px;
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
            background: linear-gradient(135deg, #f3f4ff 0%, #e9ebff 100%);
        }

        .table thead th {
            padding: 16px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            color: #5a67d8;
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

        /* Employee Info */
        .employee-name {
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .employee-email {
            color: #718096;
            font-size: 13px;
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

        .badge-active {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .badge-verified {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-unverified {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
        }

        .btn-view {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .btn-view:hover {
            background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
            transform: translateY(-2px);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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
    <?php include("../auth/inc/Msidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <h1><i class="fas fa-user-tie"></i> Manager Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>! Managing <?php echo htmlspecialchars($department); ?> Department</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='../users/teamMembers.php?department=<?php echo urlencode($department); ?>'">
                <i class="fas fa-users stat-icon"></i>
                <div class="stat-number"><?php echo $stats['active_employees']; ?></div>
                <div class="stat-label">Active Employees</div>
                <div class="stat-number-small">Total: <?php echo $stats['department_employees']; ?></div>
            </div>

            <div class="stat-card" onclick="window.location.href='../users/departmentAsset.php'">
                <i class="fas fa-boxes stat-icon"></i>
                <div class="stat-number"><?php echo $stats['department_assets']; ?></div>
                <div class="stat-label">Department Assets</div>
                <div class="stat-number-small">My Assets: <?php echo $stats['my_assets']; ?></div>
            </div>

            <div class="stat-card" onclick="window.location.href='../users/departmentTicket.php'">
                <i class="fas fa-ticket-alt stat-icon"></i>
                <div class="stat-number"><?php echo $stats['department_tickets']; ?></div>
                <div class="stat-label">Department Tickets</div>
                <div class="stat-number-small">Pending: <?php echo $stats['pending_tickets']; ?></div>
            </div>

            <div class="stat-card" onclick="window.location.href='../users/departmentTicket.php?filter=urgent'">
                <i class="fas fa-exclamation-circle stat-icon"></i>
                <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                <div class="stat-label">Urgent Tickets</div>
                <div class="stat-number-small">Maintenance: <?php echo $stats['maintenance_assets']; ?></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Department Asset Status Chart -->
            <?php if (count($dept_asset_status_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-pie"></i> Department Asset Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="deptAssetStatusChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Employee Asset Distribution -->
            <?php if (count($employee_asset_distribution) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-bar"></i> Assets per Employee</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="employeeAssetChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Department Ticket Status Chart -->
            <?php if (count($dept_ticket_status_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-ticket-alt"></i> Department Ticket Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="deptTicketStatusChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Department Ticket Priority Chart -->
            <?php if (count($dept_ticket_priority_data) > 0): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> Tickets by Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="deptTicketPriorityChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Department Employees Section -->
        <div class="section" id="employees-section">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Department Employees</h2>
                <p>Team members in your department (<?php echo $stats['department_employees']; ?> total)</p>
            </div>

            <?php if (count($department_employees) > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Verification</th>
                                <th>Assets</th>
                                <th>Tickets</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($department_employees as $employee): ?>
                                <tr>
                                    <td>
                                        <div class="employee-name">
                                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                                        </div>
                                        <div class="employee-email">
                                            <?php echo htmlspecialchars($employee['email']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['username']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $employee['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $employee['is_verified'] ? 'verified' : 'unverified'; ?>">
                                            <?php echo $employee['is_verified'] ? 'Verified' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $employee['asset_count']; ?></td>
                                    <td><?php echo $employee['ticket_count']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($employee['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="../users/departmentAsset.php?user=<?php echo $employee['user_id']; ?>" class="btn-sm btn-view" title="View Assets">
                                                <i class="fas fa-box"></i>
                                            </a>
                                            <a href="../users/departmentUserTicket.php?user=<?php echo $employee['user_id']; ?>" class="btn-sm btn-view" title="View Tickets">
                                                <i class="fas fa-ticket-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="quick-actions">
                    <a href="../users/teamMembers.php?department=<?php echo urlencode($department); ?>" class="btn btn-primary">
                        <i class="fas fa-users"></i> View All Employees
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>No Employees Found</h3>
                    <p>No employees found in your department.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Department Tickets -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-ticket-alt"></i> Recent Department Tickets</h2>
                <p>Latest ticket submissions from your team</p>
            </div>

            <?php if (count($recent_tickets) > 0): ?>
                <ul class="ticket-list">
                    <?php foreach ($recent_tickets as $ticket): ?>
                        <li class="ticket-item" onclick="window.location.href='../users/departmentTicketDetails.php?id=<?php echo $ticket['ticket_id']; ?>'">
                            <div class="ticket-info">
                                <h4><?php echo htmlspecialchars($ticket['ticket_number']); ?></h4>
                                <p>
                                    <?php echo htmlspecialchars($ticket['subject']); ?>
                                    <br>
                                    <small>By: <?php echo htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']); ?></small>
                                </p>
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
                    <a href="../users/departmentTicket.php" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> View All Tickets
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3>No Recent Tickets</h3>
                    <p>No recent tickets in your department.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assets Needing Attention -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Assets Needing Attention</h2>
                <p>Department assets requiring maintenance or repair</p>
            </div>

            <?php if (count($attention_assets) > 0): ?>
                <ul class="asset-list">
                    <?php foreach ($attention_assets as $asset): ?>
                        <li class="asset-item">
                            <div class="asset-info">
                                <h4><?php echo htmlspecialchars($asset['asset_name']); ?></h4>
                                <p>
                                    <?php echo htmlspecialchars($asset['asset_code']); ?> • <?php echo htmlspecialchars($asset['category']); ?>
                                    <?php if ($asset['assigned_to']): ?>
                                        <br><small>Assigned to: <?php echo htmlspecialchars($asset['first_name'] . ' ' . $asset['last_name']); ?></small>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                <?php echo htmlspecialchars($asset['status']); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="quick-actions">
                    <a href="../users/departmentAsset.php?status=maintenance" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> View All Maintenance Assets
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>All Assets in Good Condition</h3>
                    <p>All department assets are in good condition! 🎉</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <p>Frequently used management actions</p>
            </div>
            <div class="quick-actions" style="border-top: none; padding-top: 0; margin-top: 0;">
                <a href="../users/departmentAsset.php?department=<?php echo urlencode($department); ?>" class="btn btn-primary">
                    <i class="fas fa-boxes"></i> Manage Department Assets
                </a>
                <a href="../users/departmentTicket.php?department=<?php echo urlencode($department); ?>" class="btn btn-primary">
                    <i class="fas fa-ticket-alt"></i> Manage Department Tickets
                </a>
                <a href="../users/managerCreateticket.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Ticket
                </a>
                <a href="../users/departmentReport.php?department=<?php echo urlencode($department); ?>" class="btn btn-secondary">
                    <i class="fas fa-chart-line"></i> View Department Report
                </a>
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

        <?php if (count($dept_asset_status_data) > 0): ?>
            // Department Asset Status Chart
            const deptAssetStatusCtx = document.getElementById('deptAssetStatusChart');
            if (deptAssetStatusCtx) {
                const deptAssetStatusLabels = <?php echo $asset_status_labels; ?>;
                const deptAssetStatusData = <?php echo $asset_status_values; ?>;

                new Chart(deptAssetStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: deptAssetStatusLabels,
                        datasets: [{
                            data: deptAssetStatusData,
                            backgroundColor: deptAssetStatusLabels.map(label => statusColors[label] || '#6b7280'),
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

        <?php if (count($employee_asset_distribution) > 0): ?>
            // Employee Asset Distribution Chart
            const employeeAssetCtx = document.getElementById('employeeAssetChart');
            if (employeeAssetCtx) {
                const employeeNames = <?php echo $employee_names; ?>;
                const employeeAssets = <?php echo $employee_assets; ?>;

                new Chart(employeeAssetCtx, {
                    type: 'bar',
                    data: {
                        labels: employeeNames,
                        datasets: [{
                            label: 'Number of Assets',
                            data: employeeAssets,
                            backgroundColor: 'rgba(102, 126, 234, 0.8)',
                            borderColor: 'rgba(102, 126, 234, 1)',
                            borderWidth: 0,
                            borderRadius: 8,
                            hoverBackgroundColor: 'rgba(118, 75, 162, 0.9)'
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

        <?php if (count($dept_ticket_status_data) > 0): ?>
            // Department Ticket Status Chart
            const deptTicketStatusCtx = document.getElementById('deptTicketStatusChart');
            if (deptTicketStatusCtx) {
                const deptTicketStatusLabels = <?php echo $ticket_status_labels; ?>;
                const deptTicketStatusData = <?php echo $ticket_status_values; ?>;

                new Chart(deptTicketStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: deptTicketStatusLabels.map(label => label.replace('_', ' ').toUpperCase()),
                        datasets: [{
                            data: deptTicketStatusData,
                            backgroundColor: deptTicketStatusLabels.map(label => ticketStatusColors[label] || '#6b7280'),
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

        <?php if (count($dept_ticket_priority_data) > 0): ?>
            // Department Ticket Priority Chart
            const deptTicketPriorityCtx = document.getElementById('deptTicketPriorityChart');
            if (deptTicketPriorityCtx) {
                const deptTicketPriorityLabels = <?php echo $ticket_priority_labels; ?>;
                const deptTicketPriorityData = <?php echo $ticket_priority_values; ?>;

                new Chart(deptTicketPriorityCtx, {
                    type: 'bar',
                    data: {
                        labels: deptTicketPriorityLabels.map(label => label.toUpperCase()),
                        datasets: [{
                            label: 'Number of Tickets',
                            data: deptTicketPriorityData,
                            backgroundColor: deptTicketPriorityLabels.map(label => priorityColors[label] || '#6b7280'),
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