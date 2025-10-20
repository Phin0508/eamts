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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE (requester_department = ? OR assigned_to = ?) AND status IN ('open', 'pending')");
    $stmt->execute([$department, $user_id]);
    $stats['pending_tickets'] = $stmt->fetchColumn();

    // Get urgent tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE (requester_department = ? OR assigned_to = ?) AND priority = 'urgent' AND status NOT IN ('resolved', 'closed')");
    $stmt->execute([$department, $user_id]);
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
        LIMIT 10
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
        LIMIT 10
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

$employee_names = json_encode(array_map(function($emp) {
    return $emp['first_name'] . ' ' . substr($emp['last_name'], 0, 1) . '.';
}, $employee_asset_distribution));
$employee_assets = json_encode(array_column($employee_asset_distribution, 'asset_count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - E-Asset Management System</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="../style/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    
    <style>
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }

        .chart-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-card h3 {
            margin: 0 0 1.5rem 0;
            color: #2c3e50;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .employees-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .employees-table th {
            background: #f8f9fa;
            padding: 0.875rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.875rem;
        }

        .employees-table td {
            padding: 0.875rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.875rem;
        }

        .employees-table tr:hover {
            background: #f8f9fa;
        }

        .employee-name {
            font-weight: 500;
            color: #2c3e50;
        }

        .employee-email {
            color: #718096;
            font-size: 0.8rem;
        }

        .badge {
            padding: 0.25rem 0.625rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-verified {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-unverified {
            background: #fef3c7;
            color: #92400e;
        }

        .stat-number-small {
            font-size: 0.875rem;
            color: #718096;
        }

        .ticket-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0 0 0;
        }

        .ticket-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
            cursor: pointer;
        }

        .ticket-item:hover {
            background: #f8f9fa;
        }

        .ticket-item:last-child {
            border-bottom: none;
        }

        .ticket-info {
            flex: 1;
        }

        .ticket-info h4 {
            margin: 0 0 0.25rem 0;
            color: #2c3e50;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .ticket-info p {
            margin: 0;
            color: #718096;
            font-size: 0.85rem;
        }

        .ticket-badges {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-low { background: #d1fae5; color: #065f46; }
        .priority-medium { background: #fed7aa; color: #92400e; }
        .priority-high { background: #fecaca; color: #991b1b; }
        .priority-urgent { background: #fee2e2; color: #7f1d1d; }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-open { background: #dbeafe; color: #1e40af; }
        .status-in-progress, .status-in_progress { background: #fef3c7; color: #92400e; }
        .status-pending { background: #fef9c3; color: #854d0e; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .status-closed { background: #e5e7eb; color: #374151; }

        .status-available { background: #d1fae5; color: #065f46; }
        .status-in-use { background: #dbeafe; color: #1e40af; }
        .status-maintenance { background: #fed7aa; color: #92400e; }
        .status-retired { background: #e5e7eb; color: #374151; }
        .status-damaged { background: #fecaca; color: #991b1b; }

        .asset-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0 0 0;
        }

        .asset-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
        }

        .asset-item:hover {
            background: #f8f9fa;
        }

        .asset-item:last-child {
            border-bottom: none;
        }

        .asset-info {
            flex: 1;
        }

        .asset-info h4 {
            margin: 0 0 0.25rem 0;
            color: #2c3e50;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .asset-info p {
            margin: 0;
            color: #718096;
            font-size: 0.85rem;
        }

        .manager-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .manager-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
        }

        .manager-header p {
            margin: 0;
            opacity: 0.95;
            font-size: 1.1rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #718096;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            border: none;
            cursor: pointer;
        }

        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-view:hover {
            background: #bfdbfe;
        }

        @media (max-width: 768px) {
            .charts-section {
                grid-template-columns: 1fr;
            }

            .employees-table {
                font-size: 0.8rem;
            }

            .employees-table th,
            .employees-table td {
                padding: 0.5rem;
            }

            .ticket-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .ticket-badges {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <div class="manager-header">
                <h1>👨‍💼 Manager Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>! Managing <?php echo htmlspecialchars($department); ?> Department</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='#employees-section'">
                    <span class="stat-icon">👥</span>
                    <div class="stat-number"><?php echo $stats['active_employees']; ?></div>
                    <div class="stat-label">Active Employees</div>
                    <div class="stat-number-small">Total: <?php echo $stats['department_employees']; ?></div>
                </div>
                
                <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                    <span class="stat-icon">📦</span>
                    <div class="stat-number"><?php echo $stats['department_assets']; ?></div>
                    <div class="stat-label">Department Assets</div>
                    <div class="stat-number-small">My Assets: <?php echo $stats['my_assets']; ?></div>
                </div>

                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <span class="stat-icon">🎫</span>
                    <div class="stat-number"><?php echo $stats['department_tickets']; ?></div>
                    <div class="stat-label">Department Tickets</div>
                    <div class="stat-number-small">Pending: <?php echo $stats['pending_tickets']; ?></div>
                </div>

                <div class="stat-card" onclick="window.location.href='../public/tickets.php?filter=urgent'">
                    <span class="stat-icon">⚡</span>
                    <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                    <div class="stat-label">Urgent Tickets</div>
                    <div class="stat-number-small">Maintenance: <?php echo $stats['maintenance_assets']; ?></div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <!-- Department Asset Status Chart -->
                <?php if (count($dept_asset_status_data) > 0): ?>
                <div class="chart-card">
                    <h3>📊 Department Asset Status</h3>
                    <div class="chart-container">
                        <canvas id="deptAssetStatusChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Employee Asset Distribution -->
                <?php if (count($employee_asset_distribution) > 0): ?>
                <div class="chart-card">
                    <h3>👥 Assets per Employee</h3>
                    <div class="chart-container">
                        <canvas id="employeeAssetChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Department Ticket Status Chart -->
                <?php if (count($dept_ticket_status_data) > 0): ?>
                <div class="chart-card">
                    <h3>🎫 Department Ticket Status</h3>
                    <div class="chart-container">
                        <canvas id="deptTicketStatusChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Department Ticket Priority Chart -->
                <?php if (count($dept_ticket_priority_data) > 0): ?>
                <div class="chart-card">
                    <h3>⚠️ Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="deptTicketPriorityChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Department Employees Section -->
            <div class="card" id="employees-section">
                <h2>👥 Department Employees (<?php echo $stats['department_employees']; ?>)</h2>
                <?php if (count($department_employees) > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="employees-table">
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
                                            <a href="../public/asset.php?user=<?php echo $employee['user_id']; ?>" class="btn-sm btn-view" title="View Assets">
                                                <i class="fas fa-box"></i>
                                            </a>
                                            <a href="../public/tickets.php?user=<?php echo $employee['user_id']; ?>" class="btn-sm btn-view" title="View Tickets">
                                                <i class="fas fa-ticket-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="quick-actions" style="margin-top: 1rem;">
                        <a href="../public/users.php?department=<?php echo urlencode($department); ?>" class="action-btn btn-primary">
                            View All Employees
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No employees found in your department.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-grid">
                <!-- Recent Department Tickets -->
                <div class="card">
                    <h2>🎫 Recent Department Tickets</h2>
                    <?php if (count($recent_tickets) > 0): ?>
                        <ul class="ticket-list">
                            <?php foreach ($recent_tickets as $ticket): ?>
                                <li class="ticket-item" onclick="window.location.href='../public/ticketDetails.php?id=<?php echo $ticket['id']; ?>'">
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
                            <a href="../public/tickets.php" class="action-btn btn-primary">View All Tickets</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-ticket-alt"></i>
                            <p>No recent tickets in your department.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Assets Needing Attention -->
                <div class="card">
                    <h2>⚠️ Assets Needing Attention</h2>
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
                            <a href="../public/asset.php?status=maintenance,damaged" class="action-btn btn-primary">View All</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>All assets are in good condition! 🎉</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <h2>⚡ Quick Actions</h2>
                <div class="quick-actions">
                    <a href="../public/asset.php?department=<?php echo urlencode($department); ?>" class="action-btn btn-primary">
                        <i class="fas fa-box"></i> Manage Department Assets
                    </a>
                    <a href="../public/tickets.php?department=<?php echo urlencode($department); ?>" class="action-btn btn-primary">
                        <i class="fas fa-ticket-alt"></i> Manage Department Tickets
                    </a>
                    <a href="../public/createTicket.php" class="action-btn btn-primary">
                        <i class="fas fa-plus"></i> Create New Ticket
                    </a>
                    <a href="../public/assetHistory.php?department=<?php echo urlencode($department); ?>" class="action-btn btn-secondary">
                        <i class="fas fa-history"></i> View Department History
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script>
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

        <?php if (count($dept_asset_status_data) > 0): ?>
        // Department Asset Status Chart
        const deptAssetStatusCtx = document.getElementById('deptAssetStatusChart').getContext('2d');
        const deptAssetStatusLabels = <?php echo $asset_status_labels; ?>;
        const deptAssetStatusData = <?php echo $asset_status_values; ?>;
        
        new Chart(deptAssetStatusCtx, {
            type: 'doughnut',
            data: {
                labels: deptAssetStatusLabels,
                datasets: [{
                    data: deptAssetStatusData,
                    backgroundColor: deptAssetStatusLabels.map(label => statusColors[label] || '#6b7280'),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if (count($employee_asset_distribution) > 0): ?>
        // Employee Asset Distribution Chart
        const employeeAssetCtx = document.getElementById('employeeAssetChart').getContext('2d');
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
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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
        <?php endif; ?>

        <?php if (count($dept_ticket_status_data) > 0): ?>
        // Department Ticket Status Chart
        const deptTicketStatusCtx = document.getElementById('deptTicketStatusChart').getContext('2d');
        const deptTicketStatusLabels = <?php echo $ticket_status_labels; ?>;
        const deptTicketStatusData = <?php echo $ticket_status_values; ?>;
        
        new Chart(deptTicketStatusCtx, {
            type: 'doughnut',
            data: {
                labels: deptTicketStatusLabels.map(label => label.replace('_', ' ').toUpperCase()),
                datasets: [{
                    data: deptTicketStatusData,
                    backgroundColor: deptTicketStatusLabels.map(label => ticketStatusColors[label] || '#6b7280'),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if (count($dept_ticket_priority_data) > 0): ?>
        // Department Ticket Priority Chart
        const deptTicketPriorityCtx = document.getElementById('deptTicketPriorityChart').getContext('2d');
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
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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
        <?php endif; ?>
    </script>
</body>
</html>