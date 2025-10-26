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

// ============================================
// DEBUG CODE - REMOVE THIS IN PRODUCTION
// ============================================
$show_debug = false; // Set to true only when debugging
// $show_debug = isset($_GET['debug']) && $_GET['debug'] === 'true';

if ($show_debug) {
    echo "<pre style='background: #f0f0f0; padding: 20px; margin: 20px; border: 2px solid #333; font-size: 12px;'>";
    echo "<h3 style='color: #e74c3c;'>🔍 DEBUG INFORMATION - TICKET ISSUES</h3>";
    echo "<p style='color: #555;'>URL: Add ?debug=true to see this | Current Role: <strong>{$role}</strong> | User ID: <strong>{$user_id}</strong></p>";
    echo "<hr>";

    // Check tickets table structure
    echo "\n<strong style='color: #2980b9;'>1. ALL TICKETS IN DATABASE:</strong>\n";
    try {
        $debug_stmt = $pdo->query("SELECT ticket_id, ticket_number, approval_status, status, requester_id, created_at FROM tickets ORDER BY created_at DESC");
        $all_tickets = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "📊 Total tickets in database: <strong>" . count($all_tickets) . "</strong>\n\n";
        
        if (count($all_tickets) > 0) {
            foreach ($all_tickets as $t) {
                echo "Ticket #{$t['ticket_number']}: ";
                echo "approval_status=<strong style='color: #e74c3c;'>'{$t['approval_status']}'</strong>, ";
                echo "status='{$t['status']}', ";
                echo "requester_id={$t['requester_id']}, ";
                echo "created=" . date('M d, Y H:i', strtotime($t['created_at'])) . "\n";
            }
        } else {
            echo "⚠️ No tickets found in database!\n";
        }
    } catch (PDOException $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }

    // Check approval status values
    echo "\n<strong style='color: #2980b9;'>2. APPROVAL STATUS BREAKDOWN:</strong>\n";
    try {
        $status_stmt = $pdo->query("SELECT approval_status, COUNT(*) as count FROM tickets GROUP BY approval_status");
        $statuses = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Different approval_status values found:\n";
        foreach ($statuses as $s) {
            $approval = $s['approval_status'] === null ? 'NULL' : "'{$s['approval_status']}'";
            echo "  • {$approval}: <strong>{$s['count']}</strong> tickets\n";
        }
    } catch (PDOException $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }

    // Check what admin query returns
    if ($role === 'admin') {
        echo "\n<strong style='color: #2980b9;'>3. ADMIN TICKET QUERY TEST:</strong>\n";
        try {
            // Test current query
            $test_stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE approval_status = 'approved'");
            $approved_count = $test_stmt->fetchColumn();
            echo "Tickets WHERE approval_status='approved': <strong>{$approved_count}</strong>\n";
            
            // Test alternative query
            $test_stmt2 = $pdo->query("SELECT COUNT(*) FROM tickets WHERE (approval_status = 'approved' OR approval_status IS NULL)");
            $alt_count = $test_stmt2->fetchColumn();
            echo "Tickets WHERE approval_status='approved' OR NULL: <strong>{$alt_count}</strong>\n";
            
            // Test show all
            $test_stmt3 = $pdo->query("SELECT COUNT(*) FROM tickets");
            $all_count = $test_stmt3->fetchColumn();
            echo "All tickets (no filter): <strong>{$all_count}</strong>\n";
        } catch (PDOException $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }

    // Check tickets table structure
    echo "\n<strong style='color: #2980b9;'>4. TICKETS TABLE STRUCTURE:</strong>\n";
    try {
        $struct_stmt = $pdo->query("DESCRIBE tickets");
        $columns = $struct_stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Columns in tickets table:\n";
        foreach ($columns as $col) {
            if (stripos($col['Field'], 'approval') !== false || stripos($col['Field'], 'status') !== false) {
                echo "  • {$col['Field']}: {$col['Type']} (Null: {$col['Null']}, Default: {$col['Default']})\n";
            }
        }
    } catch (PDOException $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }

    echo "\n<hr>";
    echo "<p style='color: #27ae60;'><strong>✅ Debug complete!</strong> Remove ?debug=true from URL to hide this.</p>";
    echo "</pre>";
}
// ============================================
// END DEBUG CODE
// ============================================

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
    // Keep default values on error
    error_log("Dashboard stats error: " . $e->getMessage());
    if ($show_debug) {
        echo "<div style='background: #f8d7da; padding: 20px; margin: 20px; border: 2px solid #dc3545;'>";
        echo "<strong>❌ ASSET STATISTICS ERROR:</strong><br>";
        echo $e->getMessage();
        echo "</div>";
    }
}

// ============================================
// TICKET STATISTICS (SEPARATE TRY-CATCH BLOCK)
// ============================================
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
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where");
        $stmt->execute($ticket_params);
        $stats['total_tickets'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Total tickets error: " . $e->getMessage());
    }

    // Get open tickets
    $where_and = $ticket_base_where ? "AND" : "WHERE";
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.status = 'open'");
        $stmt->execute($ticket_params);
        $stats['open_tickets'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Open tickets error: " . $e->getMessage());
    }

    // Get resolved tickets
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.status = 'resolved'");
        $stmt->execute($ticket_params);
        $stats['resolved_tickets'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Resolved tickets error: " . $e->getMessage());
    }

    // Get urgent tickets
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $ticket_base_where $where_and t.priority = 'urgent'");
        $stmt->execute($ticket_params);
        $stats['urgent_tickets'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Urgent tickets error: " . $e->getMessage());
    }

    // Get ticket status distribution
    try {
        $stmt = $pdo->prepare("SELECT t.status, COUNT(*) as count FROM tickets t $ticket_base_where GROUP BY t.status");
        $stmt->execute($ticket_params);
        $ticket_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ticket status data error: " . $e->getMessage());
    }

    // Get ticket priority distribution
    try {
        $stmt = $pdo->prepare("SELECT t.priority, COUNT(*) as count FROM tickets t $ticket_base_where GROUP BY t.priority");
        $stmt->execute($ticket_params);
        $ticket_priority_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ticket priority data error: " . $e->getMessage());
    }
} catch (PDOException $e) {
    // Keep default values on error
    error_log("Ticket statistics error: " . $e->getMessage());
    if ($show_debug) {
        echo "<div style='background: #f8d7da; padding: 20px; margin: 20px; border: 2px solid #dc3545;'>";
        echo "<strong>❌ TICKET STATISTICS ERROR:</strong><br>";
        echo $e->getMessage();
        echo "</div>";
    }
}
// ============================================
// END TICKET STATISTICS
// ============================================

// ============================================
// CHART DATA DEBUG (After statistics are calculated)
// ============================================
if ($show_debug) {
    echo "<pre style='background: #e8f5e9; padding: 20px; margin: 20px; border: 2px solid #4caf50;'>";
    echo "<h3 style='color: #2e7d32;'>📊 CHART DATA DEBUG (After Stats Calculation)</h3>";
    echo "\n<strong>Stats Array:</strong>\n";
    print_r($stats);
    echo "\n<strong>Ticket Status Data (for charts):</strong>\n";
    print_r($ticket_status_data);
    echo "\n<strong>Ticket Priority Data (for charts):</strong>\n";
    print_r($ticket_priority_data);
    
    if (!empty($ticket_status_data)) {
        echo "\n<strong>JSON for JavaScript (Status):</strong>\n";
        echo "ticket_status_labels = " . json_encode(array_column($ticket_status_data, 'status')) . "\n";
        echo "ticket_status_values = " . json_encode(array_column($ticket_status_data, 'count')) . "\n";
    } else {
        echo "\n<strong style='color: #d32f2f;'>⚠️ ticket_status_data is EMPTY!</strong>\n";
    }
    
    if (!empty($ticket_priority_data)) {
        echo "\n<strong>JSON for JavaScript (Priority):</strong>\n";
        echo "ticket_priority_labels = " . json_encode(array_column($ticket_priority_data, 'priority')) . "\n";
        echo "ticket_priority_values = " . json_encode(array_column($ticket_priority_data, 'count')) . "\n";
    } else {
        echo "\n<strong style='color: #d32f2f;'>⚠️ ticket_priority_data is EMPTY!</strong>\n";
    }
    echo "</pre>";
}
// ============================================
// END CHART DATA DEBUG
// ============================================

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
    <title>E-Asset Management System - Dashboard</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="../style/dashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script src="../js/deviceTracker.js" defer></script>

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

        @media (max-width: 768px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
        }
    </style>
    
</head>

<body>

    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</h1>
                <p class="welcome-message">You have successfully logged into the E-Asset Management System.</p>
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

                <div class="stat-card">
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

            <!-- REST OF YOUR DASHBOARD HTML CONTINUES HERE... -->
            <!-- (I'm keeping the rest of the file the same as your original) -->

            <!-- Charts Section -->
            <?php if ($role === 'admin' || $role === 'manager'): ?>
                <div class="charts-section">
                    <!-- Asset Status Chart -->
                    <div class="chart-card">
                        <h3>📊 Asset Status Distribution</h3>
                        <div class="chart-container">
                            <canvas id="assetStatusChart"></canvas>
                        </div>
                    </div>

                    <!-- Asset Category Chart -->
                    <div class="chart-card">
                        <h3>📈 Assets by Category</h3>
                        <div class="chart-container">
                            <canvas id="assetCategoryChart"></canvas>
                        </div>
                    </div>

                    <!-- Ticket Status Chart -->
                    <div class="chart-card">
                        <h3>🎫 Ticket Status Overview</h3>
                        <div class="chart-container">
                            <canvas id="ticketStatusChart"></canvas>
                        </div>
                    </div>

                    <!-- Ticket Priority Chart -->
                    <div class="chart-card">
                        <h3>⚠️ Tickets by Priority</h3>
                        <div class="chart-container">
                            <canvas id="ticketPriorityChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="charts-section">
                    <!-- Ticket Status Chart for Employees -->
                    <div class="chart-card">
                        <h3>🎫 My Ticket Status</h3>
                        <div class="chart-container">
                            <canvas id="ticketStatusChart"></canvas>
                        </div>
                    </div>

                    <!-- Ticket Priority Chart for Employees -->
                    <div class="chart-card">
                        <h3>⚠️ My Tickets by Priority</h3>
                        <div class="chart-container">
                            <canvas id="ticketPriorityChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dashboard-grid">
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
                            <a href="../public/asset.php" class="action-btn btn-primary">View All Assets</a>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No assets assigned to you yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (count($recent_activity) > 0): ?>
                <div class="card">
                    <h2>📊 Recent Activity</h2>
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
                    <h2>⚡ Quick Actions</h2>
                    <div class="quick-actions">
                        <a href="../public/asset.php" class="action-btn btn-primary">Manage Assets</a>
                        <?php if ($role === 'admin'): ?>
                            <a href="../public/userV.php" class="action-btn btn-primary">Verify Users</a>
                            <a href="../public/tickets.php" class="action-btn btn-primary">View Approved Tickets</a>
                        <?php else: ?>
                            <a href="../public/tickets.php" class="action-btn btn-primary">View Tickets</a>
                        <?php endif; ?>
                        <a href="../public/assetHistory.php" class="action-btn btn-secondary">View History</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($role === 'admin'): ?>
                <div class="card">
                    <h2>🟢 Currently Online Users</h2>
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
                                            🟢 <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
                                <a href="../public/Uastatus.php" class="action-btn btn-primary">View All Active Users</a>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <p>No users currently online</p>
                            </div>
                        <?php endif; ?>
                    <?php } catch (PDOException $e) {
                        error_log("Online users error: " . $e->getMessage());
                    } ?>
                </div>
            <?php endif; ?>
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

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            // Asset Status Pie Chart
            const assetStatusCtx = document.getElementById('assetStatusChart').getContext('2d');
            const assetStatusLabels = <?php echo $asset_status_labels; ?>;
            const assetStatusData = <?php echo $asset_status_values; ?>;

            new Chart(assetStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: assetStatusLabels,
                    datasets: [{
                        data: assetStatusData,
                        backgroundColor: assetStatusLabels.map(label => statusColors[label] || '#6b7280'),
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

            // Asset Category Bar Chart
            const assetCategoryCtx = document.getElementById('assetCategoryChart').getContext('2d');
            const assetCategoryLabels = <?php echo $asset_category_labels; ?>;
            const assetCategoryData = <?php echo $asset_category_values; ?>;

            new Chart(assetCategoryCtx, {
                type: 'bar',
                data: {
                    labels: assetCategoryLabels,
                    datasets: [{
                        label: 'Number of Assets',
                        data: assetCategoryData,
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

        // Ticket Status Pie Chart
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

        // Ticket Priority Bar Chart
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
    </script>
</body>

</html>