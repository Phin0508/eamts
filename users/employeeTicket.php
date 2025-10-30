<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$employee_user_id = $_GET['user_id'] ?? 0;

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$user_id]);
$manager_dept = $dept_stmt->fetchColumn();

// Verify employee is in manager's department
$employee_query = "SELECT user_id, first_name, last_name, email, phone, employee_id, department, role, is_active 
                   FROM users 
                   WHERE user_id = ? AND department = ?";
$employee_stmt = $pdo->prepare($employee_query);
$employee_stmt->execute([$employee_user_id, $manager_dept]);
$employee = $employee_stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['team_error'] = "Employee not found or not in your department.";
    header("Location: teamMembers.php");
    exit();
}

// Get filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query for employee's tickets
$where_conditions = ["t.requester_id = ?"];
$params = [$employee_user_id];

if (!empty($search)) {
    $where_conditions[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($status_filter)) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
}

if (!empty($priority_filter)) {
    $where_conditions[] = "t.priority = ?";
    $params[] = $priority_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "t.ticket_type = ?";
    $params[] = $type_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch employee's tickets
$tickets_query = "
    SELECT 
        t.*,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        a.asset_name,
        a.asset_code
    FROM tickets t
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    WHERE $where_clause
    ORDER BY t.created_at DESC
";

$stmt = $pdo->prepare($tickets_query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ticket statistics for this employee
$stats_query = "
    SELECT 
        COUNT(*) as total_tickets,
        COUNT(CASE WHEN status = 'open' THEN 1 END) as open_count,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_count,
        COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_count,
        COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_count,
        COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending_approval_count
    FROM tickets
    WHERE requester_id = ?
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$employee_user_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>'s Tickets</title>
    
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

        .breadcrumb {
            margin-bottom: 1.5rem;
            font-size: 14px;
            color: #6b7280;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s;
        }

        .breadcrumb a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .employee-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .employee-details h1 {
            font-size: 28px;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .employee-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            font-size: 14px;
            color: #6b7280;
        }

        .employee-meta i {
            color: #667eea;
        }

        .status-badge {
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .stat-card.info::before { background: #3b82f6; }
        .stat-card.warning::before { background: #f59e0b; }
        .stat-card.success::before { background: #10b981; }
        .stat-card.danger::before { background: #ef4444; }
        .stat-card.purple::before { background: #8b5cf6; }
        .stat-card.gray::before { background: #6b7280; }

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
        .stat-card.info .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.warning .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.success .stat-icon { background: #d1fae5; color: #10b981; }
        .stat-card.danger .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.purple .stat-icon { background: #ede9fe; color: #8b5cf6; }
        .stat-card.gray .stat-icon { background: #f3f4f6; color: #6b7280; }

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
            grid-template-columns: 1fr auto auto auto auto auto;
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

        .tickets-grid {
            display: grid;
            gap: 20px;
        }

        .ticket-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }

        .ticket-card:hover {
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .ticket-card.priority-urgent {
            border-left-color: #dc2626;
        }

        .ticket-card.priority-high {
            border-left-color: #f59e0b;
        }

        .ticket-card.priority-medium {
            border-left-color: #3b82f6;
        }

        .ticket-card.priority-low {
            border-left-color: #10b981;
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }

        .ticket-number {
            font-size: 14px;
            color: #667eea;
            font-weight: 600;
        }

        .ticket-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a202c;
            margin: 8px 0;
        }

        .ticket-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .ticket-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-open {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-in-progress {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-resolved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-closed {
            background: #e5e7eb;
            color: #4b5563;
        }

        .badge-urgent {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-high {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-medium {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-low {
            background: #d1fae5;
            color: #065f46;
        }

        .ticket-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin: 12px 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .ticket-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            margin-top: 16px;
        }

        .detail-item {
            font-size: 12px;
        }

        .detail-label {
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .detail-value {
            color: #1a202c;
            font-weight: 500;
        }

        .ticket-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }

        .action-btn {
            padding: 8px 16px;
            font-size: 13px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            font-weight: 500;
        }

        .action-btn.view {
            background: #e0e7ff;
            color: #667eea;
        }

        .action-btn.view:hover {
            background: #667eea;
            color: white;
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

            .ticket-header {
                flex-direction: column;
            }

            .ticket-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="teamMembers.php"><i class="fas fa-users"></i> My Team</a> 
                <span> / </span>
                <span><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>'s Tickets</span>
            </div>

            <!-- Employee Info Header -->
            <div class="page-header">
                <div class="employee-info">
                    <div class="employee-avatar">
                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                    </div>
                    <div class="employee-details">
                        <h1><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h1>
                        <div class="employee-meta">
                            <span><i class="fas fa-id-badge"></i> <?php echo ucfirst($employee['role']); ?></span>
                            <?php if ($employee['employee_id']): ?>
                                <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($employee['employee_id']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($employee['department']); ?></span>
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($employee['email']); ?></span>
                            <?php if ($employee['phone']): ?>
                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($employee['phone']); ?></span>
                            <?php endif; ?>
                            <span class="status-badge status-<?php echo $employee['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ticket Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="stat-value"><?php echo $stats['total_tickets']; ?></div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div class="stat-label">Open</div>
                    <div class="stat-value"><?php echo $stats['open_count']; ?></div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?php echo $stats['in_progress_count']; ?></div>
                </div>

                <div class="stat-card purple">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Resolved</div>
                    <div class="stat-value"><?php echo $stats['resolved_count']; ?></div>
                </div>

                <div class="stat-card gray">
                    <div class="stat-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-label">Closed</div>
                    <div class="stat-value"><?php echo $stats['closed_count']; ?></div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-label">Urgent</div>
                    <div class="stat-value"><?php echo $stats['urgent_count']; ?></div>
                </div>

                <div class="stat-card warning" style="border-left-color: #f59e0b;">
                    <div class="stat-icon" style="background: #fef3c7; color: #f59e0b;">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value"><?php echo $stats['pending_approval_count']; ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <input type="hidden" name="user_id" value="<?php echo $employee_user_id; ?>">
                    
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search tickets..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>

                    <select name="priority">
                        <option value="">All Priority</option>
                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    </select>

                    <select name="type">
                        <option value="">All Types</option>
                        <option value="incident" <?php echo $type_filter === 'incident' ? 'selected' : ''; ?>>Incident</option>
                        <option value="service_request" <?php echo $type_filter === 'service_request' ? 'selected' : ''; ?>>Service Request</option>
                        <option value="maintenance" <?php echo $type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    
                    <a href="employeeTicket.php?user_id=<?php echo $employee_user_id; ?>" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Tickets Grid -->
            <?php if (count($tickets) > 0): ?>
            <div class="tickets-grid">
                <?php foreach ($tickets as $ticket): ?>
                <div class="ticket-card priority-<?php echo $ticket['priority']; ?>">
                    <div class="ticket-header">
                        <div>
                            <div class="ticket-number"><?php echo htmlspecialchars($ticket['ticket_number']); ?></div>
                            <div class="ticket-title"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                            <div class="ticket-meta">
                                <span class="ticket-badge badge-<?php echo str_replace('_', '-', $ticket['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                                <span class="ticket-badge badge-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?> Priority
                                </span>
                                <span class="ticket-badge" style="background: #f3e5f5; color: #7b1fa2;">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-description">
                        <?php echo htmlspecialchars($ticket['description']); ?>
                    </div>

                    <div class="ticket-details">
                        <div class="detail-item">
                            <div class="detail-label">Created</div>
                            <div class="detail-value"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Assigned To</div>
                            <div class="detail-value">
                                <?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : 'Unassigned'; ?>
                            </div>
                        </div>
                        <?php if ($ticket['asset_name']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Related Asset</div>
                            <div class="detail-value"><?php echo htmlspecialchars($ticket['asset_code']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <div class="detail-label">Approval</div>
                            <div class="detail-value">
                                <span class="ticket-badge" style="
                                    background: <?php echo $ticket['approval_status'] === 'approved' ? '#d1fae5' : ($ticket['approval_status'] === 'rejected' ? '#fee2e2' : '#fef3c7'); ?>;
                                    color: <?php echo $ticket['approval_status'] === 'approved' ? '#065f46' : ($ticket['approval_status'] === 'rejected' ? '#991b1b' : '#92400e'); ?>;
                                ">
                                    <?php echo ucfirst($ticket['approval_status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-actions">
                        <a href="departmentTicketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" 
                           class="action-btn view">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Tickets Found</h3>
                <p>This employee currently has no tickets matching the current filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
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