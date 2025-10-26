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

// Check if user has permission to view history (admin, manager, or superadmin)
if (!in_array($user_role, ['admin', 'manager', 'superadmin'])) {
    header("Location: ticket.php");
    exit();
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$filter_approval = $_GET['approval'] ?? 'all'; // NEW: Approval status filter
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = [];
$params = [];

// REMOVED: Role-based filtering for approval status (admin can now see all)
// Only keep department-based filtering for managers
if ($user_role === 'manager') {
    $where_clauses[] = "(t.requester_department = (SELECT department FROM users WHERE user_id = ?) OR t.assigned_to = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
}

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

// NEW: Approval status filter
if ($filter_approval !== 'all') {
    $where_clauses[] = "t.approval_status = ?";
    $params[] = $filter_approval;
}

if (!empty($filter_date_from)) {
    $where_clauses[] = "DATE(t.created_at) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where_clauses[] = "DATE(t.created_at) <= ?";
    $params[] = $filter_date_to;
}

if (!empty($search)) {
    $where_clauses[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR CONCAT(requester.first_name, ' ', requester.last_name) LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Count total records
$count_query = "
    SELECT COUNT(*) as total
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    $where_sql
";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch tickets with pagination (same as before)
$query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.department as requester_department,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        CONCAT(resolver.first_name, ' ', resolver.last_name) as resolved_by_name,
        CONCAT(closer.first_name, ' ', closer.last_name) as closed_by_name,
        a.asset_name,
        a.asset_code,
        a.category as asset_category,
        (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = t.ticket_id) as comment_count,
        (SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = t.ticket_id) as attachment_count
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN users resolver ON t.resolved_by = resolver.user_id
    LEFT JOIN users closer ON t.closed_by = closer.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    $where_sql
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics - UPDATED to include approval status counts
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
        SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_approval,
        SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        AVG(CASE 
            WHEN status IN ('resolved', 'closed') AND resolved_at IS NOT NULL 
            THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) 
        END) as avg_resolution_time
    FROM tickets t
";

$stats_where = $where_sql;
$stats_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params

// REMOVED: Approval status filtering for stats
// Only keep department-based filtering for managers
if ($user_role === 'manager') {
    if (empty($stats_where)) {
        $stats_where = "WHERE (t.requester_department = (SELECT department FROM users WHERE user_id = ?) OR t.assigned_to = ?)";
        $stats_params = [$user_id, $user_id];
    }
}

$stats_stmt = $pdo->prepare($stats_query . $stats_where);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket History - E-Asset System</title>
    
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
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

        .page-header h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .page-header p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.total { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.open { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-icon.progress { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.resolved { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        .stat-icon.closed { background: linear-gradient(135deg, #a8a8a8, #6c757d); }
        .stat-icon.urgent { background: linear-gradient(135deg, #fa709a, #fee140); }

        .stat-info h3 {
            margin: 0;
            font-size: 1.75rem;
            color: #2c3e50;
            font-weight: 700;
        }

        .stat-info p {
            margin: 0.25rem 0 0 0;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group.full-width {
            grid-column: 1 / -1;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #2d3748;
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            color: #718096;
        }

        .search-box input {
            width: 100%;
            padding: 0.625rem 0.75rem 0.625rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .filters-form select,
        .filters-form input[type="date"] {
            padding: 0.625rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
        }

        .filters-form select:focus,
        .filters-form input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #2d3748;
            font-weight: 600;
        }

        .export-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .tickets-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tickets-table thead {
            background: #f7fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .tickets-table th {
            padding: 1rem;
            text-align: left;
            font-size: 0.875rem;
            font-weight: 600;
            color: #2d3748;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tickets-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            color: #2d3748;
        }

        .tickets-table tbody tr {
            transition: background 0.3s ease;
        }

        .tickets-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge-open { background: #e3f2fd; color: #1976d2; }
        .badge-in_progress { background: #fff3e0; color: #f57c00; }
        .badge-pending { background: #fff9c4; color: #f57f17; }
        .badge-resolved { background: #e8f5e9; color: #388e3c; }
        .badge-closed { background: #f5f5f5; color: #616161; }
        .badge-low { background: #e8f5e9; color: #388e3c; }
        .badge-medium { background: #fff3e0; color: #f57c00; }
        .badge-high { background: #ffebee; color: #d32f2f; }
        .badge-urgent { background: #f3e5f5; color: #7b1fa2; }

        .ticket-subject {
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 0.25rem;
        }

        .ticket-meta {
            display: flex;
            gap: 0.75rem;
            font-size: 0.75rem;
            color: #718096;
        }

        .ticket-meta i {
            margin-right: 0.25rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
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
            transform: translateY(-1px);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            color: #2d3748;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: #f7fafc;
        }

        .pagination .active {
            background: #667eea;
            color: white;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e0;
        }

        .quick-actions {
            display: flex;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    
    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1><i class="fas fa-history"></i> Ticket History</h1>
                    <p>View and analyze all past tickets including rejected ones</p> <!-- Updated text -->
                </div>
                <div class="header-right">
                    <a href="ticket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                    <button onclick="exportToCSV()" class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </header>

            <!-- Statistics - UPDATED with approval stats -->
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
                    <div class="stat-icon resolved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['resolved']; ?></h3>
                        <p>Resolved</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon closed">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['closed']; ?></h3>
                        <p>Closed</p>
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
                <!-- NEW: Approval status statistics -->
                <div class="stat-card">
                    <div class="stat-icon pending_approval">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['pending_approval'] ?? 0; ?></h3>
                        <p>Pending Approval</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon rejected">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['rejected'] ?? 0; ?></h3>
                        <p>Rejected</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <!-- Filters - UPDATED with approval status filter -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <div class="filter-group full-width">
                        <label>Search</label>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search by ticket #, subject, or requester..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="open" <?php echo $filter_status === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $filter_status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Type</label>
                        <select name="type">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="repair" <?php echo $filter_type === 'repair' ? 'selected' : ''; ?>>Repair</option>
                            <option value="maintenance" <?php echo $filter_type === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="request_item" <?php echo $filter_type === 'request_item' ? 'selected' : ''; ?>>Request Item</option>
                            <option value="request_replacement" <?php echo $filter_type === 'request_replacement' ? 'selected' : ''; ?>>Request Replacement</option>
                            <option value="inquiry" <?php echo $filter_type === 'inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                            <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                            <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>

                    <!-- NEW: Approval Status Filter -->
                    <div class="filter-group">
                        <label>Approval Status</label>
                        <select name="approval">
                            <option value="all" <?php echo $filter_approval === 'all' ? 'selected' : ''; ?>>All Approval</option>
                            <option value="pending" <?php echo $filter_approval === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                            <option value="approved" <?php echo $filter_approval === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filter_approval === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>

                    <div class="filter-group" style="display: flex; flex-direction: row; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="ticketHistory.php" class="btn btn-outline" style="flex: 1; justify-content: center;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="content-card">
                <div class="table-header">
                    <h3>
                        Ticket Records 
                        <span style="color: #718096; font-size: 0.875rem; font-weight: normal;">
                            (Showing <?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?>)
                        </span>
                    </h3>
                </div>

                <div class="table-responsive">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Requester</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Approval</th> <!-- NEW COLUMN -->
                                <th>Created</th>
                                <th>Activity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="10"> <!-- Updated colspan to 10 -->
                                    <div class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No Tickets Found</h3>
                                        <p>Try adjusting your filters or search criteria</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td>
                                    <strong style="color: #667eea;"><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                </td>
                                <td>
                                    <div class="ticket-subject">
                                        <?php echo htmlspecialchars(substr($ticket['subject'], 0, 50)) . (strlen($ticket['subject']) > 50 ? '...' : ''); ?>
                                    </div>
                                    <?php if ($ticket['asset_code']): ?>
                                    <div class="ticket-meta">
                                        <span><i class="fas fa-laptop"></i> <?php echo htmlspecialchars($ticket['asset_code']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($ticket['requester_name']); ?></div>
                                    <div style="font-size: 0.75rem; color: #718096;">
                                        <?php echo htmlspecialchars($ticket['requester_department']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background: #f3e5f5; color: #7b1fa2;">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $ticket['priority']; ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $ticket['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                </td>
                                <!-- NEW: Approval Status Column -->
                                <td>
                                    <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['approval_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.875rem;">
                                        <?php echo date('M d, Y', strtotime($ticket['created_at'])); ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: #718096;">
                                        <?php echo date('h:i A', strtotime($ticket['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="ticket-meta">
                                        <span title="Comments"><i class="fas fa-comment"></i> <?php echo $ticket['comment_count']; ?></span>
                                        <span title="Attachments"><i class="fas fa-paperclip"></i> <?php echo $ticket['attachment_count']; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="ticketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="ticketDetailHistory.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View History">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search); ?>" 
                           class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?page=<?php echo $total_pages; ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Export to CSV - UPDATED to include approval status
        function exportToCSV() {
            const table = document.querySelector('.tickets-table');
            let csv = [];
            
            // Headers - added Approval Status
            const headers = ['Ticket #', 'Subject', 'Requester', 'Department', 'Type', 'Priority', 'Status', 'Approval Status', 'Created Date', 'Comments', 'Attachments'];
            csv.push(headers.join(','));
            
            // Data rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.querySelector('.no-data')) return;
                
                const cols = row.querySelectorAll('td');
                const rowData = [];
                
                // Ticket Number
                rowData.push('"' + cols[0].textContent.trim() + '"');
                
                // Subject (clean up)
                const subject = cols[1].querySelector('.ticket-subject').textContent.trim();
                rowData.push('"' + subject.replace(/"/g, '""') + '"');
                
                // Requester
                const requester = cols[2].querySelector('div:first-child').textContent.trim();
                rowData.push('"' + requester + '"');
                
                // Department
                const department = cols[2].querySelector('div:last-child').textContent.trim();
                rowData.push('"' + department + '"');
                
                // Type
                rowData.push('"' + cols[3].textContent.trim() + '"');
                
                // Priority
                rowData.push('"' + cols[4].textContent.trim() + '"');
                
                // Status
                rowData.push('"' + cols[5].textContent.trim() + '"');
                
                // Approval Status (NEW)
                rowData.push('"' + cols[6].textContent.trim() + '"');
                
                // Created Date
                const date = cols[7].querySelector('div:first-child').textContent.trim();
                const time = cols[7].querySelector('div:last-child').textContent.trim();
                rowData.push('"' + date + ' ' + time + '"');
                
                // Comments and Attachments
                const activity = cols[8].textContent.trim().split(/\s+/);
                rowData.push(activity[0] || '0');
                rowData.push(activity[2] || '0');
                
                csv.push(rowData.join(','));
            });
            
            // Create and download CSV
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'ticket_history_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

    </script>
</body>
</html>