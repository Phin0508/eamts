<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// SECURITY: Only allow employees to access this page
// Managers and admins should use the main tickets.php
if ($_SESSION['role'] !== 'employee') {
    header("Location: ../public/ticket.php");
    exit();
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query - ONLY show tickets created by this user
$where_clauses = ["t.requester_id = ?"];
$params = [$user_id];

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

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Fetch user's tickets
$query = "
    SELECT 
        t.*,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        a.asset_name,
        a.asset_code
    FROM tickets t
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

// Get statistics for user's tickets only
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent
    FROM tickets t
    WHERE t.requester_id = ?
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$user_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tickets - E-Asset System</title>
    
    <!-- Include navigation styles -->
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style/ticket.css">
</head>
<body>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/Usidebar.php"); ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>My Tickets</h1>
                    <p>View and manage your support tickets</p>
                </div>
                <div class="header-right">
                    <a href="../public/createTicket.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create New Ticket
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
                        <p>Urgent Priority</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search your tickets..." value="<?php echo htmlspecialchars($search); ?>">
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
                    <a href="userTickets.php" class="btn btn-outline">
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
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-inbox"></i>
                                    <p>No tickets found</p>
                                    <a href="../public/createTicket.php" class="btn btn-primary">Create Your First Ticket</a>
                                </td>
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
                                <td>
                                    <?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : '<span class="text-muted">Unassigned</span>'; ?>
                                </td>
                                <td>
                                    <span class="date-time"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                                    <br>
                                    <small class="text-muted"><?php echo date('h:i A', strtotime($ticket['created_at'])); ?></small>
                                </td>
                                <td>
                                    <span class="date-time"><?php echo date('M d, Y', strtotime($ticket['updated_at'])); ?></span>
                                    <br>
                                    <small class="text-muted"><?php echo date('h:i A', strtotime($ticket['updated_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="../tickets/ticketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($ticket['status'] !== 'closed' && $ticket['status'] !== 'resolved'): ?>
                                        <a href="../tickets/ticketEdit.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="Edit">
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

            <!-- Help Section -->
            <div class="content-card" style="margin-top: 2rem;">
                <h3><i class="fas fa-question-circle"></i> Need Help?</h3>
                <p>If you have any questions about your tickets or need assistance, please contact your department manager or IT support.</p>
                <div style="margin-top: 1rem;">
                    <a href="../public/createTicket.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create New Ticket
                    </a>
                    <a href="../users/userDashboard.php" class="btn btn-outline">
                        <i class="fas fa-home"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>