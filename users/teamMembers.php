<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get manager's department
$dept_query = "SELECT department, first_name, last_name FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$user_id]);
$manager_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
$manager_dept = $manager_info['department'];

$success_message = '';
$error_message = '';

// Check for session messages
if (isset($_SESSION['team_success'])) {
    $success_message = $_SESSION['team_success'];
    unset($_SESSION['team_success']);
}

if (isset($_SESSION['team_error'])) {
    $error_message = $_SESSION['team_error'];
    unset($_SESSION['team_error']);
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query for team members
$where_conditions = ["u.department = ?"];
$params = [$manager_dept];

if (!empty($search)) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($status_filter !== '') {
    $where_conditions[] = "u.is_active = ?";
    $params[] = $status_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get team members
$team_query = "
    SELECT 
        u.*,
        COUNT(DISTINCT a.id) as asset_count,
        COUNT(DISTINCT t.ticket_id) as ticket_count,
        COUNT(DISTINCT CASE WHEN t.status IN ('open', 'in_progress', 'pending') THEN t.ticket_id END) as active_tickets
    FROM users u
    LEFT JOIN assets a ON u.user_id = a.assigned_to AND a.status != 'retired'
    LEFT JOIN tickets t ON u.user_id = t.requester_id
    WHERE $where_clause
    GROUP BY u.user_id
    ORDER BY u.first_name ASC, u.last_name ASC
";

$stmt = $pdo->prepare($team_query);
$stmt->execute($params);
$team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department statistics - Fixed to avoid counting duplicates
$dept_stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE department = ?) as total_members,
        (SELECT COUNT(*) FROM users WHERE department = ? AND is_active = 1) as active_members,
        (SELECT COUNT(*) FROM users WHERE department = ? AND is_active = 0) as inactive_members,
        (SELECT COUNT(*) FROM assets WHERE assigned_to IN (SELECT user_id FROM users WHERE department = ?) AND status != 'retired') as total_assets,
        (SELECT COUNT(*) FROM tickets WHERE requester_department = ?) as total_tickets,
        (SELECT COUNT(*) FROM tickets WHERE requester_department = ? AND status IN ('open', 'in_progress', 'pending')) as open_tickets
";

$dept_stats_stmt = $pdo->prepare($dept_stats_query);
$dept_stats_stmt->execute([$manager_dept, $manager_dept, $manager_dept, $manager_dept, $manager_dept, $manager_dept]);
$dept_stats = $dept_stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Team - E-Asset Management System</title>
    
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
        }

        .main-content {
            margin-left: 260px;
            padding: 2rem;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header p {
            color: #6b7280;
            font-size: 14px;
        }

        .department-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.primary::before { background: #667eea; }
        .stat-card.success::before { background: #10b981; }
        .stat-card.warning::before { background: #f59e0b; }
        .stat-card.danger::before { background: #ef4444; }
        .stat-card.info::before { background: #3b82f6; }
        .stat-card.purple::before { background: #8b5cf6; }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .stat-card.primary .stat-icon { background: #e0e7ff; color: #667eea; }
        .stat-card.success .stat-icon { background: #d1fae5; color: #10b981; }
        .stat-card.warning .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.danger .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.info .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.purple .stat-icon { background: #ede9fe; color: #8b5cf6; }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #1a202c;
        }

        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 15px;
            align-items: end;
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-box input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        select {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .member-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .member-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-4px);
        }

        .member-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
        }

        .member-header {
            display: flex;
            align-items: start;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .member-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .member-info {
            flex: 1;
        }

        .member-name {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 5px;
        }

        .member-role {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .member-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .member-details {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #4b5563;
        }

        .detail-item i {
            width: 20px;
            color: #667eea;
        }

        .member-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .member-stat {
            text-align: center;
        }

        .member-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }

        .member-stat-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .member-actions {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #1a202c;
        }

        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }

            .team-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .team-grid {
                grid-template-columns: 1fr;
            }

            .member-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="fas fa-users"></i>
                    My Team Members
                </h1>
                <p>Manage and monitor your department team</p>
                <span class="department-badge">
                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($manager_dept); ?> Department
                </span>
            </div>

            <!-- Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Department Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-label">Total Team Members</div>
                    <div class="stat-value"><?php echo $dept_stats['total_members']; ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-label">Active Members</div>
                    <div class="stat-value"><?php echo $dept_stats['active_members']; ?></div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <div class="stat-label">Total Assets</div>
                    <div class="stat-value"><?php echo $dept_stats['total_assets']; ?></div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-label">Open Tickets</div>
                    <div class="stat-value"><?php echo $dept_stats['open_tickets']; ?></div>
                </div>

                <div class="stat-card purple">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="stat-value"><?php echo $dept_stats['total_tickets']; ?></div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-label">Inactive Members</div>
                    <div class="stat-value"><?php echo $dept_stats['inactive_members']; ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name, email, or employee ID..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                    </select>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    
                    <a href="teamMembers.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Team Members Grid -->
            <?php if (count($team_members) > 0): ?>
            <div class="team-grid">
                <?php foreach ($team_members as $member): ?>
                <div class="member-card <?php echo $member['is_active'] ? '' : 'inactive'; ?>">
                    <div class="member-header">
                        <div class="member-avatar">
                            <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                        </div>
                        <div class="member-info">
                            <div class="member-name">
                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                            </div>
                            <div class="member-role">
                                <?php echo ucfirst($member['role']); ?>
                                <?php if ($member['employee_id']): ?>
                                    • ID: <?php echo htmlspecialchars($member['employee_id']); ?>
                                <?php endif; ?>
                            </div>
                            <span class="member-status status-<?php echo $member['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="member-details">
                        <div class="detail-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($member['email']); ?></span>
                        </div>
                        <?php if ($member['phone']): ?>
                        <div class="detail-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($member['phone']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <i class="fas fa-calendar"></i>
                            <span>Joined: <?php echo date('M d, Y', strtotime($member['created_at'])); ?></span>
                        </div>
                        <?php if ($member['last_login']): ?>
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Last login: <?php echo date('M d, Y h:i A', strtotime($member['last_login'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="member-stats">
                        <div class="member-stat">
                            <div class="member-stat-value"><?php echo $member['asset_count']; ?></div>
                            <div class="member-stat-label">Assets</div>
                        </div>
                        <div class="member-stat">
                            <div class="member-stat-value"><?php echo $member['active_tickets']; ?></div>
                            <div class="member-stat-label">Active Tickets</div>
                        </div>
                        <div class="member-stat">
                            <div class="member-stat-value"><?php echo $member['ticket_count']; ?></div>
                            <div class="member-stat-label">Total Tickets</div>
                        </div>
                    </div>

                    <div class="member-actions">
                        <a href="../users/employeeOwned.php?user_id=<?php echo $member['user_id']; ?>" 
                           class="btn btn-sm btn-outline" title="View Assets">
                            <i class="fas fa-laptop"></i> Assets
                        </a>
                        <a href="../users/employeeTicket.php?user_id=<?php echo $member['user_id']; ?>" 
                           class="btn btn-sm btn-outline" title="View Tickets">
                            <i class="fas fa-ticket-alt"></i> Tickets
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <h3>No Team Members Found</h3>
                <p>There are no team members in your department matching the current filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>