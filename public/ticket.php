<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';

// Fetch user's tickets or all tickets based on role
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query based on role
$where_clauses = [];
$params = [];

if ($user_role === 'employee') {
    $where_clauses[] = "t.requester_id = ?";
    $params[] = $user_id;
} elseif ($user_role === 'manager') {
    // Managers can see tickets from their department or assigned to them
    $where_clauses[] = "(t.requester_department = (SELECT department FROM users WHERE user_id = ?) OR t.assigned_to = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
}
// Admins see all tickets (no filter)

if ($filter_status !== 'all') {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
}

if ($filter_type !== 'all') {
    $where_clauses[] = "t.ticket_type = ?";
    $params[] = $filter_type;
}

if ($filter_priority !== 'all') {
    $where_clauses[] = "t.priority = ?";
    $params[] = $filter_priority;
}

if (!empty($search)) {
    $where_clauses[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        a.asset_name,
        a.asset_code
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    $where_sql
    ORDER BY 
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        t.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent
    FROM tickets t
";

if ($user_role === 'employee') {
    $stats_query .= " WHERE t.requester_id = ?";
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute([$user_id]);
} elseif ($user_role === 'manager') {
    $stats_query .= " WHERE t.requester_department = (SELECT department FROM users WHERE user_id = ?) OR t.assigned_to = ?";
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute([$user_id, $user_id]);
} else {
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute();
}

$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management - E-Asset System</title>
    
    <!-- Include navigation styles -->
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Dashboard Content Styles */
        .dashboard-content {
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 60px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-left h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .header-left p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Buttons */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
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

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            color: #667eea;
            border-radius: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-icon:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .stat-icon.open {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .stat-icon.progress {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .stat-icon.urgent {
            background: linear-gradient(135deg, #fa709a, #fee140);
        }

        .stat-info h3 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-info p {
            margin: 0.25rem 0 0 0;
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .filters-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .search-box input {
            width: 100%;
            padding: 0.625rem 1rem 0.625rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filters-form select {
            padding: 0.625rem 2.5rem 0.625rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e") no-repeat right 0.75rem center/12px 12px;
            appearance: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filters-form select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        .tickets-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tickets-table thead th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e9ecef;
        }

        .tickets-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .tickets-table tbody tr {
            transition: all 0.2s ease;
        }

        .tickets-table tbody tr:hover {
            background: #f8f9fa;
        }

        .ticket-subject {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .asset-tag {
            color: #6c757d;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .text-muted {
            color: #6c757d;
            font-style: italic;
        }

        .date-time {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
            font-style: italic;
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .badge-status {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-priority {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-type {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        /* Status badges */
        .badge-open { background: #e3f2fd; color: #1976d2; }
        .badge-in_progress { background: #fff3e0; color: #f57c00; }
        .badge-pending { background: #fff9c4; color: #f57f17; }
        .badge-resolved { background: #e8f5e9; color: #388e3c; }
        .badge-closed { background: #f5f5f5; color: #616161; }

        /* Priority badges */
        .badge-low { background: #e8f5e9; color: #388e3c; }
        .badge-medium { background: #fff3e0; color: #f57c00; }
        .badge-high { background: #ffebee; color: #d32f2f; }
        .badge-urgent { background: #f3e5f5; color: #7b1fa2; }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .filters-form {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
                min-width: auto;
            }

            .filters-form select,
            .filters-form .btn {
                width: 100%;
            }

            .table-responsive {
                font-size: 0.85rem;
            }

            .tickets-table thead th,
            .tickets-table tbody td {
                padding: 0.75rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }

            .stat-info h3 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Ticket Management</h1>
                    <p>Manage and track all support tickets</p>
                </div>
                <div class="header-right">
                    <a href="create_ticket.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Ticket
                    </a>
                </div>
            </header>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total']; ?></h3>
                        <p>Total Tickets</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon open">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['open']; ?></h3>
                        <p>Open</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon progress">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['in_progress']; ?></h3>
                        <p>In Progress</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon urgent">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['urgent']; ?></h3>
                        <p>Urgent</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="open" <?php echo $filter_status === 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="closed" <?php echo $filter_status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>

                    <select name="type" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="repair" <?php echo $filter_type === 'repair' ? 'selected' : ''; ?>>Repair</option>
                        <option value="maintenance" <?php echo $filter_type === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="request_item" <?php echo $filter_type === 'request_item' ? 'selected' : ''; ?>>Request Item</option>
                        <option value="request_replacement" <?php echo $filter_type === 'request_replacement' ? 'selected' : ''; ?>>Request Replacement</option>
                        <option value="inquiry" <?php echo $filter_type === 'inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                        <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>

                    <select name="priority" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                        <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>

                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="tickets.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="content-card">
                <div class="table-responsive">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Requester</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="9" class="no-data">No tickets found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                </td>
                                <td>
                                    <div class="ticket-subject">
                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                        <?php if ($ticket['asset_code']): ?>
                                        <small class="asset-tag">
                                            <i class="fas fa-laptop"></i> <?php echo htmlspecialchars($ticket['asset_code']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-type"><?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-priority badge-<?php echo $ticket['priority']; ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-status badge-<?php echo $ticket['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($ticket['requester_name']); ?></td>
                                <td>
                                    <?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : '<span class="text-muted">Unassigned</span>'; ?>
                                </td>
                                <td>
                                    <span class="date-time"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="ticketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($user_role !== 'employee' || $ticket['requester_id'] == $user_id): ?>
                                        <a href="ticketEdit.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>