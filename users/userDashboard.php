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
    <title>My Dashboard - E-Asset Management System</title>
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

        .chart-container canvas {
            max-height: 300px;
        }

        .ticket-list {
            list-style: none;
            padding: 0;
            margin: 0;
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

        .cta-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            text-align: center;
            margin: 2rem 0;
        }

        .cta-section h2 {
            margin: 0 0 1rem 0;
            font-size: 1.5rem;
        }

        .cta-section p {
            margin: 0 0 1.5rem 0;
            opacity: 0.95;
        }

        .btn-create-ticket {
            background: white;
            color: #667eea;
            padding: 0.875rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-create-ticket:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.95rem;
        }

        @media (max-width: 768px) {
            .charts-section {
                grid-template-columns: 1fr;
            }

            .ticket-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .ticket-badges {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Usidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>! 👋</h1>
                <p class="welcome-message">Here's your asset and ticket overview</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='../public/asset.php'">
                    <span class="stat-icon">📦</span>
                    <div class="stat-number"><?php echo $stats['my_assets']; ?></div>
                    <div class="stat-label">My Assets</div>
                </div>
                
                <div class="stat-card" onclick="window.location.href='../public/tickets.php'">
                    <span class="stat-icon">🎫</span>
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                </div>

                <div class="stat-card" onclick="window.location.href='../public/tickets.php?filter=open'">
                    <span class="stat-icon">📋</span>
                    <div class="stat-number"><?php echo $stats['open_tickets']; ?></div>
                    <div class="stat-label">Open Tickets</div>
                </div>

                <div class="stat-card" onclick="window.location.href='../public/tickets.php?filter=urgent'">
                    <span class="stat-icon">⚡</span>
                    <div class="stat-number"><?php echo $stats['urgent_tickets']; ?></div>
                    <div class="stat-label">Urgent Tickets</div>
                </div>
            </div>

            <!-- Call to Action -->
            <div class="cta-section">
                <h2>Need Help or Support?</h2>
                <p>Create a ticket for repairs, maintenance, new requests, or any inquiries</p>
                <a href="../public/createTicket.php" class="btn-create-ticket">
                    <i class="fas fa-plus-circle"></i> Create New Ticket
                </a>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <!-- My Asset Status Chart -->
                <?php if (count($my_asset_status_data) > 0): ?>
                <div class="chart-card">
                    <h3>📊 My Assets Status</h3>
                    <div class="chart-container">
                        <canvas id="myAssetStatusChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Ticket Status Chart -->
                <?php if (count($my_ticket_status_data) > 0): ?>
                <div class="chart-card">
                    <h3>🎫 My Ticket Status</h3>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Ticket Priority Chart -->
                <?php if (count($my_ticket_priority_data) > 0): ?>
                <div class="chart-card">
                    <h3>⚠️ Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-grid">
                <!-- My Recent Assets -->
                <div class="card">
                    <h2>📦 My Recent Assets</h2>
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
                            <a href="../public/asset.php" class="action-btn btn-primary">View All My Assets</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No assets assigned to you yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- My Recent Tickets -->
                <div class="card">
                    <h2>🎫 My Recent Tickets</h2>
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
                            <a href="../public/tickets.php" class="action-btn btn-primary">View All My Tickets</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-ticket-alt"></i>
                            <p>You haven't created any tickets yet.</p>
                            <br>
                            <a href="../public/createTicket.php" class="action-btn btn-primary">Create Your First Ticket</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card">
                <h2>📈 Quick Statistics</h2>
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="label">Open Tickets:</span>
                        <span class="value"><?php echo $stats['open_tickets']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">In Progress:</span>
                        <span class="value"><?php echo $stats['in_progress_tickets']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Pending:</span>
                        <span class="value"><?php echo $stats['pending_tickets']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Resolved:</span>
                        <span class="value"><?php echo $stats['resolved_tickets']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Urgent Tickets:</span>
                        <span class="value"><?php echo $stats['urgent_tickets']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Total Assets:</span>
                        <span class="value"><?php echo $stats['my_assets']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Account Info -->
            <div class="card">
                <h2>👤 Your Account Information</h2>
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

        <?php if (count($my_asset_status_data) > 0): ?>
        // My Asset Status Chart
        const myAssetStatusCtx = document.getElementById('myAssetStatusChart').getContext('2d');
        const myAssetStatusLabels = <?php echo $asset_status_labels; ?>;
        const myAssetStatusData = <?php echo $asset_status_values; ?>;
        
        new Chart(myAssetStatusCtx, {
            type: 'doughnut',
            data: {
                labels: myAssetStatusLabels,
                datasets: [{
                    data: myAssetStatusData,
                    backgroundColor: myAssetStatusLabels.map(label => statusColors[label] || '#6b7280'),
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

        <?php if (count($my_ticket_status_data) > 0): ?>
        // Ticket Status Chart
        const ticketStatusCtx = document.getElementById('ticketStatusChart').getContext('2d');
        const ticketStatusLabels = <?php echo $ticket_status_labels; ?>;
        const ticketStatusData = <?php echo $ticket_status_values; ?>;
        
        new Chart(ticketStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ticketStatusLabels.map(label => label.replace('_', ' ').toUpperCase()),
                datasets: [{
                    data: ticketStatusData,
                    backgroundColor: ticketStatusLabels.map(label => ticketStatusColors[label] || '#6b7280'),
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

        <?php if (count($my_ticket_priority_data) > 0): ?>
        // Ticket Priority Chart
        const ticketPriorityCtx = document.getElementById('ticketPriorityChart').getContext('2d');
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